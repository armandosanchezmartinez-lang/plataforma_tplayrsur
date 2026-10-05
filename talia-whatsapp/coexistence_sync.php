<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Solicitud de sincronización Coexistence.
 * sync_type permitido: smb_app_state_sync | history
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_start();
date_default_timezone_set('America/Merida');

function outSync(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['usuario'])) outSync(401,['ok'=>false,'error'=>'Sesión no válida.']);
$rol = (string)($_SESSION['rol'] ?? '');
if (!in_array($rol,['admin','director_regional'],true)) {
    outSync(403,['ok'=>false,'error'=>'No autorizado.']);
}

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw,true);
if (!is_array($in)) outSync(400,['ok'=>false,'error'=>'JSON inválido.']);

$csrf = (string)($in['csrf'] ?? '');
$csrfSession = (string)($_SESSION['talia_wa_csrf'] ?? '');
if ($csrf === '' || $csrfSession === '' || !hash_equals($csrfSession,$csrf)) {
    outSync(403,['ok'=>false,'error'=>'CSRF inválido.']);
}

$idNumero = (int)($in['id_numero'] ?? 0);
$syncType = trim((string)($in['sync_type'] ?? ''));
if ($idNumero <= 0 || !in_array($syncType,['smb_app_state_sync','history'],true)) {
    outSync(422,['ok'=>false,'error'=>'Solicitud de sincronización inválida.']);
}

$rutaConexion = dirname(__DIR__) . '/conexion.php';
$conexion = null;
require $rutaConexion;
if (!($conexion instanceof mysqli)) outSync(500,['ok'=>false,'error'=>'Sin conexión MySQL.']);
mysqli_set_charset($conexion,'utf8mb4');

$rutaSecreto = dirname(__DIR__) . '/talia_whatsapp_secrets.php';
if (!is_file($rutaSecreto)) outSync(500,['ok'=>false,'error'=>'Falta archivo de secretos.']);
$cfg = require $rutaSecreto;
if (!is_array($cfg)) outSync(500,['ok'=>false,'error'=>'Secretos inválidos.']);

$graphVersion = trim((string)($cfg['graph_version'] ?? 'v26.0'));
$master = trim((string)($cfg['token_encryption_key'] ?? ''));
if ($master === '') outSync(500,['ok'=>false,'error'=>'Falta token_encryption_key.']);

function decryptToken(string $blob, string $master): string {
    $decodedOuter = base64_decode($blob, true);
    if ($decodedOuter === false) throw new RuntimeException('Token cifrado inválido.');
    $j = json_decode($decodedOuter,true);
    if (!is_array($j)) throw new RuntimeException('Token cifrado inválido.');
    $iv = base64_decode((string)($j['iv'] ?? ''),true);
    $tag = base64_decode((string)($j['tag'] ?? ''),true);
    $ct = base64_decode((string)($j['ct'] ?? ''),true);
    if ($iv===false || $tag===false || $ct===false) throw new RuntimeException('Token cifrado inválido.');
    $key = hash('sha256',$master,true);
    $plain = openssl_decrypt($ct,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'');
    if ($plain===false || $plain==='') throw new RuntimeException('No fue posible descifrar el token.');
    return $plain;
}

$sql = "SELECT c.phone_number_id,c.token_cipher,c.contactos_sync_estado,c.historial_sync_estado
        FROM wa_meta_conexiones c
        WHERE c.id_numero=? AND c.modo='COEXISTENCE'
        LIMIT 1";
$stmt = mysqli_prepare($conexion,$sql);
mysqli_stmt_bind_param($stmt,'i',$idNumero);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$conn = mysqli_fetch_assoc($res) ?: null;
mysqli_stmt_close($stmt);

if (!$conn) outSync(404,['ok'=>false,'error'=>'No existe conexión Coexistence para ese número.']);

$estadoActual = $syncType==='history'
    ? (string)$conn['historial_sync_estado']
    : (string)$conn['contactos_sync_estado'];

if (in_array($estadoActual,['SOLICITADO','COMPLETADO'],true)) {
    outSync(409,[
        'ok'=>false,
        'error'=>"La sincronización {$syncType} ya tiene estado {$estadoActual}. No se reintentó para evitar duplicar una operación de una sola ejecución."
    ]);
}

try {
    $token = decryptToken((string)$conn['token_cipher'],$master);
} catch (Throwable $e) {
    outSync(500,['ok'=>false,'error'=>$e->getMessage()]);
}

$phoneNumberId = trim((string)$conn['phone_number_id']);
$url = 'https://graph.facebook.com/' . rawurlencode($graphVersion) . '/'
     . rawurlencode($phoneNumberId) . '/smb_app_data';

$payload = json_encode([
    'messaging_product'=>'whatsapp',
    'sync_type'=>$syncType,
], JSON_UNESCAPED_SLASHES);

$ch = curl_init($url);
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>[
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>30,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
]);
$body = curl_exec($ch);
$curlError = curl_error($ch);
$http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
curl_close($ch);

$data = [];
if (is_string($body) && $body!=='') {
    $tmp = json_decode($body,true);
    if (is_array($tmp)) $data=$tmp;
}

if ($curlError!=='' || $http<200 || $http>=300 || isset($data['error'])) {
    $err = $curlError ?: (string)($data['error']['message'] ?? 'Meta rechazó el sync.');
    $campoEstado = $syncType==='history' ? 'historial_sync_estado' : 'contactos_sync_estado';
    $sql = "UPDATE wa_meta_conexiones SET {$campoEstado}='ERROR',ultimo_error=?,fecha_actualizacion=CURRENT_TIMESTAMP WHERE id_numero=?";
    $stmt = mysqli_prepare($conexion,$sql);
    mysqli_stmt_bind_param($stmt,'si',$err,$idNumero);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    outSync(502,['ok'=>false,'error'=>"HTTP {$http}: {$err}"]);
}

$requestId = trim((string)($data['request_id'] ?? ''));
if ($syncType==='history') {
    $sql = "UPDATE wa_meta_conexiones
            SET historial_sync_estado='SOLICITADO',
                historial_request_id=NULLIF(?, ''),
                historial_sync_fecha=CURRENT_TIMESTAMP,
                ultimo_error=NULL,
                fecha_actualizacion=CURRENT_TIMESTAMP
            WHERE id_numero=?";
} else {
    $sql = "UPDATE wa_meta_conexiones
            SET contactos_sync_estado='SOLICITADO',
                contactos_request_id=NULLIF(?, ''),
                contactos_sync_fecha=CURRENT_TIMESTAMP,
                ultimo_error=NULL,
                fecha_actualizacion=CURRENT_TIMESTAMP
            WHERE id_numero=?";
}
$stmt = mysqli_prepare($conexion,$sql);
mysqli_stmt_bind_param($stmt,'si',$requestId,$idNumero);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

outSync(200,['ok'=>true,'sync_type'=>$syncType,'request_id'=>$requestId]);
