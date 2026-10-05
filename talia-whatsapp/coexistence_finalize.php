<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Finalizador server-side de Embedded Signup Coexistence.
 * Nunca expone App Secret ni business token al navegador.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_start();
date_default_timezone_set('America/Merida');

function out(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['usuario'])) {
    out(401, ['ok'=>false,'error'=>'Sesión no válida.']);
}

$rol = (string)($_SESSION['rol'] ?? '');
if (!in_array($rol, ['admin','director_regional'], true)) {
    out(403, ['ok'=>false,'error'=>'No tienes permisos para realizar el onboarding.']);
}

$rutaConexion = dirname(__DIR__) . '/conexion.php';
if (!is_file($rutaConexion)) {
    out(500, ['ok'=>false,'error'=>'No se encontró la conexión a base de datos.']);
}
$conexion = null;
require $rutaConexion;
if (!($conexion instanceof mysqli)) {
    out(500, ['ok'=>false,'error'=>'No fue posible conectar con MySQL.']);
}
mysqli_set_charset($conexion, 'utf8mb4');

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) {
    out(400, ['ok'=>false,'error'=>'Payload JSON inválido.']);
}

$csrf = (string)($in['csrf'] ?? '');
$csrfSesion = (string)($_SESSION['talia_wa_csrf'] ?? '');
if ($csrf === '' || $csrfSesion === '' || !hash_equals($csrfSesion, $csrf)) {
    out(403, ['ok'=>false,'error'=>'La sesión de seguridad expiró. Recarga la página.']);
}

$code = trim((string)($in['code'] ?? ''));
$expectedPhone = preg_replace('/\D+/', '', (string)($in['expected_phone'] ?? '')) ?? '';
if ($code === '' || $expectedPhone === '') {
    out(422, ['ok'=>false,'error'=>'Falta el code de Meta o el número esperado.']);
}

$rutaSecreto = dirname(__DIR__) . '/talia_whatsapp_secrets.php';
if (!is_file($rutaSecreto)) {
    out(500, ['ok'=>false,'error'=>'No existe talia_whatsapp_secrets.php.']);
}
$cfg = require $rutaSecreto;
if (!is_array($cfg)) {
    out(500, ['ok'=>false,'error'=>'El archivo de secretos no devolvió una configuración válida.']);
}

$appId = trim((string)($cfg['app_id'] ?? ''));
$appSecret = trim((string)($cfg['app_secret'] ?? ''));
$graphVersion = trim((string)($cfg['graph_version'] ?? 'v26.0'));
$encryptionKey = trim((string)($cfg['token_encryption_key'] ?? ''));
$redirectUri = trim((string)($cfg['embedded_signup_redirect_uri'] ?? ''));

if ($appId === '' || $appSecret === '' || $encryptionKey === '') {
    out(500, [
        'ok'=>false,
        'error'=>'Faltan app_id, app_secret o token_encryption_key en talia_whatsapp_secrets.php.'
    ]);
}
if (!preg_match('/^v\d+\.\d+$/', $graphVersion)) {
    $graphVersion = 'v26.0';
}

function graphRequest(
    string $method,
    string $version,
    string $path,
    string $token = '',
    array $query = [],
    ?array $json = null
): array {
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'TalIA-Connect-WA-Coexistence/1.0',
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = [];
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) $decoded = $tmp;
    }

    $error = $curlError;
    if (isset($decoded['error']['message'])) {
        $error = (string)$decoded['error']['message'];
    }

    return [
        'ok' => $curlError === '' && $http >= 200 && $http < 300 && !isset($decoded['error']),
        'http' => $http,
        'data' => $decoded,
        'error' => $error,
    ];
}

function oauthExchange(
    string $version,
    string $appId,
    string $appSecret,
    string $code,
    string $redirectUri
): array {
    $query = [
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'code' => $code,
    ];
    if ($redirectUri !== '') {
        $query['redirect_uri'] = $redirectUri;
        $query['grant_type'] = 'authorization_code';
    }
    return graphRequest('GET', $version, 'oauth/access_token', '', $query);
}

function encryptToken(string $plain, string $master): string {
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL no está disponible para cifrar el token.');
    }

    $key = hash('sha256', $master, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($cipher === false) {
        throw new RuntimeException('No fue posible cifrar el token.');
    }

    return base64_encode(json_encode([
        'v'=>1,
        'iv'=>base64_encode($iv),
        'tag'=>base64_encode($tag),
        'ct'=>base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES));
}

function digits(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}

function findWabaFromSession(mixed $session): string {
    if (!is_array($session)) return '';
    $candidates = [
        $session['data']['waba_id'] ?? '',
        $session['data']['whatsapp_business_account_id'] ?? '',
        $session['waba_id'] ?? '',
    ];
    foreach ($candidates as $v) {
        $v = trim((string)$v);
        if ($v !== '' && ctype_digit($v)) return $v;
    }
    return '';
}

function findWabaFromDebug(array $debug): string {
    $granular = $debug['data']['data']['granular_scopes'] ?? $debug['data']['granular_scopes'] ?? [];
    if (!is_array($granular)) return '';

    foreach ($granular as $scope) {
        if (!is_array($scope)) continue;
        $name = (string)($scope['scope'] ?? '');
        if (!in_array($name, ['whatsapp_business_management','business_management'], true)) continue;
        $targets = $scope['target_ids'] ?? [];
        if (is_array($targets) && !empty($targets)) {
            $id = trim((string)$targets[0]);
            if ($id !== '') return $id;
        }
    }
    return '';
}

$warnings = [];

// 1) Intercambio inmediato del code por business token.
$exchange = oauthExchange($graphVersion, $appId, $appSecret, $code, $redirectUri);
if (!$exchange['ok']) {
    out(502, [
        'ok'=>false,
        'error'=>'Meta rechazó el intercambio del código de autorización.',
        'details'=>[
            'HTTP ' . $exchange['http'],
            $exchange['error'] ?: 'Sin detalle de Meta.'
        ]
    ]);
}

$businessToken = trim((string)($exchange['data']['access_token'] ?? $exchange['data']['business_token'] ?? ''));
$tokenType = trim((string)($exchange['data']['token_type'] ?? 'bearer'));
if ($businessToken === '') {
    out(502, ['ok'=>false,'error'=>'Meta respondió sin business access token.']);
}

// 2) WABA desde session logging; fallback por debug_token.
$wabaId = findWabaFromSession($in['session'] ?? null);

$appAccessToken = $appId . '|' . $appSecret;
$debug = graphRequest(
    'GET',
    $graphVersion,
    'debug_token',
    $appAccessToken,
    ['input_token'=>$businessToken]
);

if ($wabaId === '' && $debug['ok']) {
    $wabaId = findWabaFromDebug($debug);
}

if ($wabaId === '') {
    out(422, [
        'ok'=>false,
        'error'=>'No fue posible determinar el WABA ID del onboarding.',
        'details'=>['Meta no devolvió WABA en session logging y debug_token no aportó target_ids utilizables.']
    ]);
}

// 3) Validar acceso real al WABA y obtener sus números.
$phones = graphRequest(
    'GET',
    $graphVersion,
    $wabaId . '/phone_numbers',
    $businessToken,
    ['fields'=>'id,display_phone_number,verified_name,quality_rating,code_verification_status']
);
if (!$phones['ok']) {
    out(502, [
        'ok'=>false,
        'error'=>'El token se obtuvo, pero no fue posible consultar phone_numbers del WABA.',
        'details'=>['HTTP ' . $phones['http'], $phones['error']]
    ]);
}

$dataPhones = $phones['data']['data'] ?? [];
if (!is_array($dataPhones) || empty($dataPhones)) {
    out(422, ['ok'=>false,'error'=>'El WABA no devolvió números de WhatsApp.']);
}

$selected = null;
foreach ($dataPhones as $p) {
    if (!is_array($p)) continue;
    if (digits((string)($p['display_phone_number'] ?? '')) === $expectedPhone) {
        $selected = $p;
        break;
    }
}
if (!$selected) {
    $devueltos = [];
    foreach ($dataPhones as $p) {
        if (is_array($p)) $devueltos[] = (string)($p['display_phone_number'] ?? '');
    }
    out(422, [
        'ok'=>false,
        'error'=>'El WABA conectado no contiene el número capturado en TalIA.',
        'details'=>['Esperado: ' . $expectedPhone, 'Meta devolvió: ' . implode(', ', $devueltos)]
    ]);
}

$phoneNumberId = trim((string)($selected['id'] ?? ''));
$displayPhone = trim((string)($selected['display_phone_number'] ?? ''));
$verifiedName = trim((string)($selected['verified_name'] ?? ''));
if ($phoneNumberId === '') {
    out(422, ['ok'=>false,'error'=>'Meta no devolvió Phone Number ID.']);
}

// 4) Suscribir la app al WABA.
$subscribe = graphRequest('POST', $graphVersion, $wabaId . '/subscribed_apps', $businessToken);
$webhookSubscribed = $subscribe['ok'];
if (!$webhookSubscribed) {
    $warnings[] = 'El número quedó identificado, pero la suscripción del WABA al webhook respondió HTTP '
        . $subscribe['http'] . ': ' . ($subscribe['error'] ?: 'sin detalle');
}

// 5) Persistir número y conexión.
$nombreVendedor = trim((string)($in['nombre_vendedor'] ?? ''));
$numeroTalento = trim((string)($in['numero_talento'] ?? ''));
$idPosicion = trim((string)($in['id_posicion'] ?? ''));
$distrito = trim((string)($in['distrito'] ?? ''));

if ($nombreVendedor === '') {
    $nombreVendedor = $verifiedName !== '' ? $verifiedName : 'WHATSAPP COEXISTENCE';
}

$tokenCipher = encryptToken($businessToken, $encryptionKey);

mysqli_begin_transaction($conexion);
try {
    $sqlNumero = "INSERT INTO wa_numeros (
            numero_talento,id_posicion,nombre_vendedor,distrito,
            telefono,display_phone_number,phone_number_id,waba_id,
            bot_activo,modo_respuesta,espera_segundos,timezone,estado
        ) VALUES (
            NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?,?,?,?,0,'MANUAL',60,'America/Merida','ACTIVO'
        )
        ON DUPLICATE KEY UPDATE
            id=LAST_INSERT_ID(id),
            numero_talento=COALESCE(NULLIF(VALUES(numero_talento),''),numero_talento),
            id_posicion=COALESCE(NULLIF(VALUES(id_posicion),''),id_posicion),
            nombre_vendedor=COALESCE(NULLIF(VALUES(nombre_vendedor),''),nombre_vendedor),
            distrito=COALESCE(NULLIF(VALUES(distrito),''),distrito),
            telefono=VALUES(telefono),
            display_phone_number=VALUES(display_phone_number),
            waba_id=VALUES(waba_id),
            estado='ACTIVO'";

    $stmt = mysqli_prepare($conexion, $sqlNumero);
    if (!$stmt) throw new RuntimeException(mysqli_error($conexion));
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssss',
        $numeroTalento,
        $idPosicion,
        $nombreVendedor,
        $distrito,
        $expectedPhone,
        $displayPhone,
        $phoneNumberId,
        $wabaId
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $idNumero = (int)mysqli_insert_id($conexion);
    if ($idNumero <= 0) {
        $stmt = mysqli_prepare($conexion, "SELECT id FROM wa_numeros WHERE phone_number_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $phoneNumberId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $idNumeroDb);
        if (mysqli_stmt_fetch($stmt)) $idNumero = (int)$idNumeroDb;
        mysqli_stmt_close($stmt);
    }
    if ($idNumero <= 0) throw new RuntimeException('No se pudo recuperar id_numero.');

    $estadoConexion = $webhookSubscribed ? 'ACTIVA' : 'PENDIENTE';

    $sqlConn = "INSERT INTO wa_meta_conexiones (
            id_numero,waba_id,phone_number_id,modo,estado,
            token_cipher,token_type,webhook_suscrito,
            fecha_onboarding,fecha_actualizacion
        ) VALUES (?,?,?,'COEXISTENCE',?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            id_numero=VALUES(id_numero),
            waba_id=VALUES(waba_id),
            modo='COEXISTENCE',
            estado=VALUES(estado),
            token_cipher=VALUES(token_cipher),
            token_type=VALUES(token_type),
            webhook_suscrito=VALUES(webhook_suscrito),
            ultimo_error=NULL,
            fecha_actualizacion=CURRENT_TIMESTAMP";

    $stmt = mysqli_prepare($conexion, $sqlConn);
    if (!$stmt) throw new RuntimeException(mysqli_error($conexion));
    $subInt = $webhookSubscribed ? 1 : 0;
    mysqli_stmt_bind_param(
        $stmt,
        'isssssi',
        $idNumero,
        $wabaId,
        $phoneNumberId,
        $estadoConexion,
        $tokenCipher,
        $tokenType,
        $subInt
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    mysqli_commit($conexion);
} catch (Throwable $e) {
    mysqli_rollback($conexion);
    out(500, ['ok'=>false,'error'=>'Error guardando la conexión en TalIA: ' . $e->getMessage()]);
}

// No devolvemos el token ni datos sensibles.
out(200, [
    'ok'=>true,
    'id_numero'=>$idNumero,
    'waba_id'=>$wabaId,
    'phone_number_id'=>$phoneNumberId,
    'display_phone_number'=>$displayPhone,
    'verified_name'=>$verifiedName,
    'webhook_subscribed'=>$webhookSubscribed,
    'warnings'=>$warnings,
]);
