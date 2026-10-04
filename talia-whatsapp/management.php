<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Administración WA
 * Management v1.2 SAFE / Solo lectura
 *
 * Objetivo:
 * - Consultar activos propios de WhatsApp Business en Meta Graph API.
 * - Mostrar WABA, números, suscripciones webhook y plantillas.
 * - Mantener endpoints cerrados, GET únicamente y sin escritura de archivos.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('America/Merida');

/* =========================================================
 * Conexión local
 * ========================================================= */
$rutaConexion = dirname(__DIR__) . '/conexion.php';

if (!is_file($rutaConexion)) {
    http_response_code(500);
    exit('No se encontró el archivo de conexión.');
}

$conexion = null;
require $rutaConexion;

if (!($conexion instanceof mysqli)) {
    http_response_code(500);
    exit('No fue posible iniciar la conexión con la base de datos.');
}

mysqli_set_charset($conexion, 'utf8mb4');

/* =========================================================
 * Helpers UI / seguridad
 * ========================================================= */
function h(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function textoSeguro(mixed $valor, string $default = '—'): string
{
    $texto = trim((string)$valor);
    return $texto !== '' ? $texto : $default;
}

function tablaExiste(mysqli $conexion, string $tabla): bool
{
    $sql = "SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?";

    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 's', $tabla);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total > 0;
}

function estadoBadge(string $estado): string
{
    return match (strtoupper(trim($estado))) {
        'GREEN', 'APPROVED', 'ACTIVE', 'ACTIVO', 'CONNECTED', 'VERIFIED' => 'ok',
        'YELLOW', 'PENDING', 'PRUEBA', 'IN_REVIEW' => 'warning',
        'RED', 'REJECTED', 'DISABLED', 'INACTIVO', 'FAILED' => 'danger',
        default => 'neutral',
    };
}

/* =========================================================
 * Configuración Meta
 * No se imprime ni registra el token.
 * ========================================================= */
function obtenerConfigWhatsApp(): array
{
    $config = [
        'access_token' => '',
        'graph_version' => 'v26.0',
    ];

    // Preferimos el archivo privado que ya usa TalIA Connect WA.
    $rutaSecreto = dirname(__DIR__) . '/talia_whatsapp_secrets.php';

    if (is_file($rutaSecreto)) {
        $cfg = require $rutaSecreto;

        if (is_array($cfg)) {
            $token = $cfg['access_token'] ?? '';
            $version = $cfg['graph_version'] ?? '';

            if (is_string($token) && trim($token) !== '') {
                $config['access_token'] = trim($token);
            }

            if (
                is_string($version)
                && preg_match('/^v\d+\.\d+$/', trim($version))
            ) {
                $config['graph_version'] = trim($version);
            }
        }
    }

    // Fallback opcional si en el futuro se migra a variable de entorno.
    if ($config['access_token'] === '') {
        $envToken = getenv('TALIA_WA_ACCESS_TOKEN');
        if (is_string($envToken) && trim($envToken) !== '') {
            $config['access_token'] = trim($envToken);
        }
    }

    $envVersion = getenv('TALIA_WA_GRAPH_VERSION');
    if (
        is_string($envVersion)
        && preg_match('/^v\d+\.\d+$/', trim($envVersion))
    ) {
        $config['graph_version'] = trim($envVersion);
    }

    return $config;
}

/**
 * Cliente GET restringido exclusivamente a graph.facebook.com.
 * Recibe una URL ya construida por funciones internas; no acepta URL del usuario.
 */
function metaGet(string $url, string $accessToken): array
{
    if (!str_starts_with($url, 'https://graph.facebook.com/')) {
        return [
            'ok' => false,
            'http_code' => 0,
            'data' => [],
            'error' => 'Endpoint no permitido.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'http_code' => 0,
            'data' => [],
            'error' => 'cURL no está disponible en PHP.',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = [];
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    $metaError = '';
    if (isset($decoded['error']) && is_array($decoded['error'])) {
        $metaError = (string)($decoded['error']['message'] ?? 'Meta devolvió un error.');
    }

    $ok = $curlError === ''
        && $httpCode >= 200
        && $httpCode < 300
        && $metaError === '';

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'data' => $decoded,
        'error' => $metaError !== '' ? $metaError : $curlError,
    ];
}

function metaPhoneNumbers(string $version, string $wabaId, string $token): array
{
    $url = 'https://graph.facebook.com/'
        . rawurlencode($version)
        . '/'
        . rawurlencode($wabaId)
        . '/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating,code_verification_status';

    return metaGet($url, $token);
}

function metaSubscribedApps(string $version, string $wabaId, string $token): array
{
    $url = 'https://graph.facebook.com/'
        . rawurlencode($version)
        . '/'
        . rawurlencode($wabaId)
        . '/subscribed_apps';

    return metaGet($url, $token);
}

function metaMessageTemplates(string $version, string $wabaId, string $token): array
{
    $url = 'https://graph.facebook.com/'
        . rawurlencode($version)
        . '/'
        . rawurlencode($wabaId)
        . '/message_templates?fields=id,name,status,language,category&limit=50';

    return metaGet($url, $token);
}

/* =========================================================
 * Sesión / alcance
 * ========================================================= */
$rol = (string)($_SESSION['rol'] ?? 'vendedor');
$usuario = (string)($_SESSION['usuario'] ?? '');

$rolesManagement = ['admin', 'director_regional'];

if (!in_array($rol, $rolesManagement, true)) {
    http_response_code(403);
    exit('No tienes permisos para acceder a Administración WA.');
}

if (!tablaExiste($conexion, 'wa_numeros')) {
    http_response_code(500);
    exit('No existe la tabla wa_numeros.');
}

/* =========================================================
 * Números locales disponibles
 * ========================================================= */
$numerosDisponibles = [];

$sqlNumeros = "SELECT id, numero_talento, id_posicion, nombre_vendedor, distrito,
                      telefono, display_phone_number, phone_number_id, waba_id,
                      bot_activo, modo_respuesta, timezone, estado
               FROM wa_numeros
               ORDER BY estado = 'ACTIVO' DESC,
                        estado = 'PRUEBA' DESC,
                        nombre_vendedor,
                        id";

$rsNumeros = mysqli_query($conexion, $sqlNumeros);
if ($rsNumeros) {
    while ($row = mysqli_fetch_assoc($rsNumeros)) {
        $numerosDisponibles[] = $row;
    }
}

$idNumeroSeleccionado = (int)($_GET['id_numero'] ?? 0);
if ($idNumeroSeleccionado <= 0 && !empty($numerosDisponibles)) {
    $idNumeroSeleccionado = (int)$numerosDisponibles[0]['id'];
}

$numero = null;
foreach ($numerosDisponibles as $filaNumero) {
    if ((int)$filaNumero['id'] === $idNumeroSeleccionado) {
        $numero = $filaNumero;
        break;
    }
}

if ($numero === null && !empty($numerosDisponibles)) {
    $numero = $numerosDisponibles[0];
    $idNumeroSeleccionado = (int)$numero['id'];
}

/* =========================================================
 * Consultas Meta - GET solamente
 * ========================================================= */
$configWA = obtenerConfigWhatsApp();
$accessToken = (string)$configWA['access_token'];
$graphVersion = (string)$configWA['graph_version'];

$wabaId = trim((string)($numero['waba_id'] ?? ''));
$phoneNumberIdLocal = trim((string)($numero['phone_number_id'] ?? ''));

$wabaValido = $wabaId !== '' && preg_match('/^\d+$/', $wabaId) === 1;
$metaDisponible = $accessToken !== '' && $wabaValido;

$resPhoneNumbers = null;
$resSubscribedApps = null;
$resTemplates = null;
$phoneNumbersMeta = [];
$appsSuscritas = [];
$templates = [];

if ($metaDisponible) {
    $resPhoneNumbers = metaPhoneNumbers($graphVersion, $wabaId, $accessToken);
    $resSubscribedApps = metaSubscribedApps($graphVersion, $wabaId, $accessToken);
    $resTemplates = metaMessageTemplates($graphVersion, $wabaId, $accessToken);

    if (!empty($resPhoneNumbers['ok']) && isset($resPhoneNumbers['data']['data']) && is_array($resPhoneNumbers['data']['data'])) {
        $phoneNumbersMeta = $resPhoneNumbers['data']['data'];
    }

    if (!empty($resSubscribedApps['ok']) && isset($resSubscribedApps['data']['data']) && is_array($resSubscribedApps['data']['data'])) {
        $appsSuscritas = $resSubscribedApps['data']['data'];
    }

    if (!empty($resTemplates['ok']) && isset($resTemplates['data']['data']) && is_array($resTemplates['data']['data'])) {
        $templates = $resTemplates['data']['data'];
    }
}

$telefonoMetaSeleccionado = null;
foreach ($phoneNumbersMeta as $pn) {
    if ((string)($pn['id'] ?? '') === $phoneNumberIdLocal) {
        $telefonoMetaSeleccionado = $pn;
        break;
    }
}

$displayNumero = $numero['display_phone_number'] ?? ($numero['telefono'] ?? 'Sin número');
$nombreLocal = textoSeguro($numero['nombre_vendedor'] ?? '', 'Sin nombre');
$estadoLocal = strtoupper(textoSeguro($numero['estado'] ?? '', 'SIN ESTADO'));
$distritoLocal = textoSeguro($numero['distrito'] ?? '', 'Sin distrito');

$consultas = array_values(array_filter([
    $resPhoneNumbers,
    $resSubscribedApps,
    $resTemplates,
], static fn($v) => is_array($v)));

$consultasOk = 0;
foreach ($consultas as $consulta) {
    if (!empty($consulta['ok'])) {
        $consultasOk++;
    }
}
$totalConsultas = count($consultas);
$actualizado = date('d/m/Y H:i:s');

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administración WA | TalIA Connect WA</title>
<!-- TALIA-WA-MANAGEMENT-V1.2-SAFE -->
<style>
:root{
    --bg:#f4f7fb;
    --card:#fff;
    --ink:#142033;
    --muted:#6d7888;
    --brand:#6c3df0;
    --brand2:#875df5;
    --green:#0f8f73;
    --green-soft:#eaf8f2;
    --line:#e5eaf1;
    --danger:#b42318;
    --danger-soft:#fff1f0;
    --warning:#b54708;
    --warning-soft:#fff7ed;
    --shadow:0 14px 36px rgba(20,32,51,.08);
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink)}
body{overflow-x:hidden}
a{color:inherit}
.shell{min-height:100vh;display:grid;grid-template-columns:250px 1fr}
.sidebar{position:sticky;top:0;height:100vh;background:#111827;color:#fff;padding:24px 18px;display:flex;flex-direction:column;gap:24px}
.brand{display:flex;gap:12px;align-items:center}
.logo{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--brand),#9b74ff);display:grid;place-items:center;font-weight:800;font-size:20px}
.brand h1{font-size:17px;margin:0}.brand small{color:#aeb8c8}
.nav{display:grid;gap:8px}.nav a{padding:12px 14px;border-radius:12px;text-decoration:none;color:#cbd5e1}.nav a.active,.nav a:hover{background:#202a3a;color:#fff}
.sidebar-foot{margin-top:auto;color:#94a3b8;font-size:12px;line-height:1.5}
.main{min-width:0;padding:26px 30px 40px}
.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:18px}.topbar h2{margin:0;font-size:30px}.topbar p{margin:7px 0 0;color:var(--muted)}
.user-chip{background:#fff;border:1px solid var(--line);border-radius:16px;padding:10px 14px;text-align:right;font-size:12px;box-shadow:var(--shadow)}
.admin-select,.hero,.panel,.stat{background:var(--card);border:1px solid var(--line);box-shadow:var(--shadow)}
.admin-select{border-radius:18px;padding:18px;margin-bottom:18px}.field{display:grid;gap:8px}.field label{font-weight:700;font-size:13px}.field select{width:100%;padding:13px 14px;border:1px solid #d5dce7;border-radius:12px;background:#fff;font-size:15px}
.alert{border-radius:14px;padding:13px 15px;margin-bottom:18px;font-size:14px}.alert.ok{background:var(--green-soft);color:#08735f}.alert.warning{background:var(--warning-soft);color:#9a3412}.alert.danger{background:var(--danger-soft);color:var(--danger)}
.hero{border-radius:20px;padding:20px 22px;display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:18px}.hero-left{display:flex;gap:14px;align-items:center}.hero-icon{width:54px;height:54px;border-radius:16px;background:var(--green-soft);color:var(--green);display:grid;place-items:center;font-weight:900}.hero h3{margin:0 0 5px}.hero p{margin:0;color:var(--muted);font-size:13px}.hero-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}.mode-pill{display:inline-flex;align-items:center;gap:7px;padding:9px 11px;border-radius:999px;background:#eef2ff;color:#4f46e5;font-size:12px;font-weight:700}.dot{width:8px;height:8px;border-radius:50%;background:var(--green)}
.button{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:10px;padding:10px 13px;font-weight:700;font-size:13px}.button.primary{background:var(--brand);color:#fff}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat{border-radius:18px;padding:18px}.stat .label{font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em}.stat .value{margin-top:8px;font-size:22px;font-weight:800;overflow-wrap:anywhere}.stat .sub{margin-top:4px;color:var(--muted);font-size:12px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}.panel{border-radius:20px;overflow:hidden}.panel-head{padding:18px 20px;border-bottom:1px solid var(--line)}.panel-head h3{margin:0;font-size:18px}.panel-head p{margin:5px 0 0;color:var(--muted);font-size:13px}.panel-body{padding:18px 20px}.detail-list{display:grid;gap:10px}.detail-row{display:grid;grid-template-columns:170px 1fr;gap:12px;padding:10px 0;border-bottom:1px solid #f0f2f6}.detail-row:last-child{border-bottom:0}.detail-row .k{color:var(--muted);font-size:13px}.detail-row .v{font-weight:700;overflow-wrap:anywhere}
.badge{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:11px;font-weight:800}.badge.ok{background:#e8f7f1;color:#08735f}.badge.warning{background:#fff3e8;color:#9a3412}.badge.danger{background:#feeceb;color:#b42318}.badge.neutral{background:#eef2f6;color:#475569}
.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;font-size:13px}.table th,.table td{text-align:left;padding:11px 10px;border-bottom:1px solid var(--line);vertical-align:top}.table th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em;background:#fafbfc}.empty{padding:16px;border-radius:12px;background:#f8fafc;color:var(--muted);font-size:13px}.api-grid{display:grid;gap:10px}.api-row{display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid var(--line)}.api-row:last-child{border-bottom:0}.api-endpoint{font-weight:700;overflow-wrap:anywhere}.api-error{margin-top:5px;color:var(--danger);font-size:12px;font-weight:500}.small{color:var(--muted);font-size:12px}.footer-note{margin-top:18px;color:var(--muted);font-size:12px;text-align:center}
@media(max-width:1050px){.stats{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
@media(max-width:760px){.shell{grid-template-columns:1fr}.sidebar{position:relative;height:auto}.main{padding:20px 14px}.topbar,.hero{display:block}.user-chip{display:none}.hero-actions{justify-content:flex-start;margin-top:16px}.stats{grid-template-columns:1fr}.detail-row,.api-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
<aside class="sidebar">
    <div class="brand">
        <div class="logo">T</div>
        <div><h1>TalIA Connect WA</h1><small>Centro de mensajería</small></div>
    </div>
    <nav class="nav">
        <a href="index.php">Configuración</a>
        <a href="conversaciones.php">Conversaciones</a>
        <a class="active" href="management.php">Administración WA</a>
        <a href="privacidad.php" target="_blank" rel="noopener">Privacidad</a>
    </nav>
    <div class="sidebar-foot">Región SUR<br>Plataforma TalIA</div>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h2>Administración WA</h2>
            <p>Consulta segura y de solo lectura de activos WhatsApp Business conectados a TalIA.</p>
        </div>
        <div class="user-chip"><?= h($usuario) ?><br><strong><?= h($rol) ?></strong></div>
    </div>

    <?php if (!empty($numerosDisponibles)): ?>
    <form method="get" class="admin-select">
        <div class="field">
            <label for="id_numero">Número administrado</label>
            <select id="id_numero" name="id_numero" onchange="this.form.submit()">
                <?php foreach ($numerosDisponibles as $n): ?>
                <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $idNumeroSeleccionado ? 'selected' : '' ?>>
                    <?= h(($n['nombre_vendedor'] ?: 'Sin nombre') . ' · ' . ($n['display_phone_number'] ?: $n['telefono']) . ' · ' . ($n['estado'] ?: 'SIN ESTADO')) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <?php if (empty($numerosDisponibles)): ?>
        <div class="alert warning">No existen números registrados en wa_numeros.</div>
    <?php elseif ($accessToken === ''): ?>
        <div class="alert warning">No hay access token configurado para WhatsApp Business.</div>
    <?php elseif (!$wabaValido): ?>
        <div class="alert warning">El número seleccionado no tiene un WABA ID válido.</div>
    <?php elseif ($totalConsultas > 0 && $consultasOk === $totalConsultas): ?>
        <div class="alert ok">Conexión con Meta correcta. Las tres consultas de administración respondieron satisfactoriamente.</div>
    <?php elseif ($totalConsultas > 0): ?>
        <div class="alert warning">Meta respondió parcialmente. Revisa el diagnóstico API al final de la página.</div>
    <?php endif; ?>

    <?php if ($numero): ?>
    <section class="hero">
        <div class="hero-left">
            <div class="hero-icon">WA</div>
            <div>
                <h3><?= h($nombreLocal) ?></h3>
                <p><?= h($displayNumero) ?> · WABA <?= h(textoSeguro($wabaId)) ?> · <?= h($estadoLocal) ?></p>
            </div>
        </div>
        <div class="hero-actions">
            <span class="mode-pill"><span class="dot"></span>Management v1.2 SAFE · solo lectura</span>
            <a class="button primary" href="management.php?id_numero=<?= (int)$idNumeroSeleccionado ?>&refresh=1">Actualizar desde Meta</a>
        </div>
    </section>

    <section class="stats">
        <div class="stat"><div class="label">WABA</div><div class="value"><?= h(textoSeguro($wabaId)) ?></div><div class="sub">WhatsApp Business Account</div></div>
        <div class="stat"><div class="label">Graph API</div><div class="value"><?= h($graphVersion) ?></div><div class="sub">Versión configurada</div></div>
        <div class="stat"><div class="label">Números WABA</div><div class="value"><?= count($phoneNumbersMeta) ?></div><div class="sub">Devueltos por Meta</div></div>
        <div class="stat"><div class="label">Plantillas</div><div class="value"><?= count($templates) ?></div><div class="sub">Primeros 50 registros</div></div>
    </section>

    <section class="grid-2">
        <article class="panel">
            <div class="panel-head"><h3>Cuenta conectada</h3><p>Identificadores locales vinculados con Meta.</p></div>
            <div class="panel-body"><div class="detail-list">
                <div class="detail-row"><div class="k">WABA ID</div><div class="v"><?= h(textoSeguro($wabaId)) ?></div></div>
                <div class="detail-row"><div class="k">Phone Number ID</div><div class="v"><?= h(textoSeguro($phoneNumberIdLocal)) ?></div></div>
                <div class="detail-row"><div class="k">Número</div><div class="v"><?= h(textoSeguro($displayNumero)) ?></div></div>
                <div class="detail-row"><div class="k">Vendedor / propietario</div><div class="v"><?= h($nombreLocal) ?></div></div>
                <div class="detail-row"><div class="k">Distrito</div><div class="v"><?= h($distritoLocal) ?></div></div>
                <div class="detail-row"><div class="k">Estado local</div><div class="v"><span class="badge <?= h(estadoBadge($estadoLocal)) ?>"><?= h($estadoLocal) ?></span></div></div>
                <div class="detail-row"><div class="k">Zona horaria</div><div class="v"><?= h(textoSeguro($numero['timezone'] ?? '')) ?></div></div>
            </div></div>
        </article>

        <article class="panel">
            <div class="panel-head"><h3>Número confirmado por Meta</h3><p>Datos de phone_numbers para el Phone Number ID seleccionado.</p></div>
            <div class="panel-body">
                <?php if (is_array($telefonoMetaSeleccionado)): ?>
                <div class="detail-list">
                    <div class="detail-row"><div class="k">Nombre verificado</div><div class="v"><?= h(textoSeguro($telefonoMetaSeleccionado['verified_name'] ?? '')) ?></div></div>
                    <div class="detail-row"><div class="k">Número visible</div><div class="v"><?= h(textoSeguro($telefonoMetaSeleccionado['display_phone_number'] ?? '')) ?></div></div>
                    <div class="detail-row"><div class="k">Phone Number ID</div><div class="v"><?= h(textoSeguro($telefonoMetaSeleccionado['id'] ?? '')) ?></div></div>
                    <div class="detail-row"><div class="k">Quality rating</div><div class="v"><?= h(textoSeguro($telefonoMetaSeleccionado['quality_rating'] ?? '')) ?></div></div>
                    <div class="detail-row"><div class="k">Verificación</div><div class="v"><?= h(textoSeguro($telefonoMetaSeleccionado['code_verification_status'] ?? '')) ?></div></div>
                </div>
                <?php else: ?><div class="empty">Meta no devolvió un registro coincidente para este Phone Number ID.</div><?php endif; ?>
            </div>
        </article>
    </section>

    <section class="grid-2">
        <article class="panel">
            <div class="panel-head"><h3>Números de WhatsApp Business</h3><p>GET de solo lectura sobre phone_numbers.</p></div>
            <div class="panel-body table-wrap">
                <?php if ($phoneNumbersMeta): ?>
                <table class="table"><thead><tr><th>ID</th><th>Número</th><th>Nombre</th><th>Calidad</th></tr></thead><tbody>
                <?php foreach ($phoneNumbersMeta as $pn): ?>
                    <tr><td><?= h(textoSeguro($pn['id'] ?? '')) ?></td><td><?= h(textoSeguro($pn['display_phone_number'] ?? '')) ?></td><td><?= h(textoSeguro($pn['verified_name'] ?? '')) ?></td><td><?= h(textoSeguro($pn['quality_rating'] ?? '')) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><div class="empty">Sin datos disponibles.</div><?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head"><h3>Suscripciones webhook</h3><p>GET de solo lectura sobre subscribed_apps.</p></div>
            <div class="panel-body table-wrap">
                <?php if ($appsSuscritas): ?>
                <table class="table"><thead><tr><th>ID</th><th>Nombre</th></tr></thead><tbody>
                <?php foreach ($appsSuscritas as $app): ?>
                    <tr><td><?= h(textoSeguro($app['id'] ?? '')) ?></td><td><?= h(textoSeguro($app['name'] ?? 'Aplicación suscrita')) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><div class="empty">Meta no devolvió aplicaciones suscritas o el permiso aún no está disponible.</div><?php endif; ?>
            </div>
        </article>
    </section>

    <article class="panel" style="margin-bottom:18px">
        <div class="panel-head"><h3>Plantillas de mensajes</h3><p>GET de solo lectura sobre message_templates.</p></div>
        <div class="panel-body table-wrap">
            <?php if ($templates): ?>
            <table class="table"><thead><tr><th>Nombre</th><th>Estado</th><th>Idioma</th><th>Categoría</th><th>ID</th></tr></thead><tbody>
            <?php foreach ($templates as $tpl): ?>
                <tr><td><?= h(textoSeguro($tpl['name'] ?? '')) ?></td><td><span class="badge <?= h(estadoBadge((string)($tpl['status'] ?? ''))) ?>"><?= h(textoSeguro($tpl['status'] ?? '')) ?></span></td><td><?= h(textoSeguro($tpl['language'] ?? '')) ?></td><td><?= h(textoSeguro($tpl['category'] ?? '')) ?></td><td><?= h(textoSeguro($tpl['id'] ?? '')) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php else: ?><div class="empty">Sin plantillas disponibles o el permiso todavía no las devuelve.</div><?php endif; ?>
        </div>
    </article>

    <article class="panel">
        <div class="panel-head"><h3>Diagnóstico API</h3><p>Resultado de las llamadas reales de administración realizadas al cargar esta pantalla.</p></div>
        <div class="panel-body"><div class="api-grid">
            <?php
            $diag = [
                'phone_numbers' => $resPhoneNumbers,
                'subscribed_apps' => $resSubscribedApps,
                'message_templates' => $resTemplates,
            ];
            foreach ($diag as $nombre => $resultado):
                $ok = is_array($resultado) && !empty($resultado['ok']);
                $http = is_array($resultado) ? (int)($resultado['http_code'] ?? 0) : 0;
                $error = is_array($resultado) ? trim((string)($resultado['error'] ?? '')) : '';
            ?>
            <div class="api-row">
                <div><div class="api-endpoint">/<?= h(textoSeguro($wabaId)) ?>/<?= h($nombre) ?></div><?php if ($error !== ''): ?><div class="api-error"><?= h($error) ?></div><?php endif; ?></div>
                <div class="small">HTTP <?= $http ?></div>
                <div><span class="badge <?= $ok ? 'ok' : 'danger' ?>"><?= $ok ? 'OK' : 'ERROR' ?></span></div>
            </div>
            <?php endforeach; ?>
        </div></div>
    </article>

    <div class="footer-note">Actualizado <?= h($actualizado) ?> · TalIA Connect WA · Administración WA v1.2 SAFE</div>
    <?php endif; ?>
</main>
</div>
</body>
</html>
