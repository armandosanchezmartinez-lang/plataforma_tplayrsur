<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Administración WA
 * Management v1.0 (solo lectura)
 *
 * Ruta:
 * /public_html/plataforma/talia-whatsapp/management.php
 *
 * Objetivo:
 * - Consultar activos reales de WhatsApp Business mediante Graph API.
 * - Mostrar WABA / números / suscripciones webhook / plantillas.
 * - Ejecutar llamadas reales de whatsapp_business_management sin exponer secretos.
 * - Mantener una primera versión segura, sin acciones destructivas.
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
 * Conexión
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
 * Helpers
 * ========================================================= */

function h(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function guardarLogManagement(string $contenido): void
{
    $dir = __DIR__ . '/logs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $bloque  = "============================================================\n";
    $bloque .= "Fecha servidor: " . date('Y-m-d H:i:s') . "\n";
    $bloque .= $contenido . "\n\n";

    @file_put_contents(
        $dir . '/management_api.log',
        $bloque,
        FILE_APPEND | LOCK_EX
    );
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

function obtenerConfigWhatsApp(): array
{
    $config = [
        'access_token' => '',
        'graph_version' => 'v26.0',
    ];

    $envToken = getenv('TALIA_WA_ACCESS_TOKEN');
    if (is_string($envToken) && trim($envToken) !== '') {
        $config['access_token'] = trim($envToken);
    }

    $envVersion = getenv('TALIA_WA_GRAPH_VERSION');
    if (
        is_string($envVersion)
        && preg_match('/^v\d+\.\d+$/', trim($envVersion))
    ) {
        $config['graph_version'] = trim($envVersion);
    }

    $rutaSecreto = dirname(__DIR__) . '/talia_whatsapp_secrets.php';

    if (is_file($rutaSecreto)) {
        $cfg = require $rutaSecreto;

        if (is_array($cfg)) {
            if (
                $config['access_token'] === ''
                && isset($cfg['access_token'])
                && is_string($cfg['access_token'])
                && trim($cfg['access_token']) !== ''
            ) {
                $config['access_token'] = trim($cfg['access_token']);
            }

            if (
                isset($cfg['graph_version'])
                && is_string($cfg['graph_version'])
                && preg_match('/^v\d+\.\d+$/', trim($cfg['graph_version']))
            ) {
                $config['graph_version'] = trim($cfg['graph_version']);
            }
        }
    }

    return $config;
}

function graphGet(
    string $graphVersion,
    string $accessToken,
    string $path,
    array $query = []
): array {
    $graphVersion = trim($graphVersion);
    $path = ltrim(trim($path), '/');

    $url = 'https://graph.facebook.com/'
        . rawurlencode($graphVersion)
        . '/'
        . $path;

    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $inicio = microtime(true);
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'TalIA-Connect-WA-Management/1.0',
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $duracionMs = (int)round((microtime(true) - $inicio) * 1000);

    $decoded = [];
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    $graphError = '';
    $graphErrorCode = '';

    if (isset($decoded['error']) && is_array($decoded['error'])) {
        $graphError = (string)($decoded['error']['message'] ?? 'Error de Meta.');
        $graphErrorCode = (string)($decoded['error']['code'] ?? '');
    }

    $ok = $curlError === ''
        && $httpCode >= 200
        && $httpCode < 300
        && $graphError === '';

    $safePath = preg_replace('/\?.*$/', '', $path) ?? $path;

    guardarLogManagement(
        'GET /' . $safePath
        . ' | HTTP=' . $httpCode
        . ' | OK=' . ($ok ? 'SI' : 'NO')
        . ' | DURACION_MS=' . $duracionMs
        . ($graphErrorCode !== '' ? ' | META_CODE=' . $graphErrorCode : '')
        . ($curlError !== '' ? ' | CURL=' . $curlError : '')
    );

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'duration_ms' => $duracionMs,
        'data' => $decoded,
        'error' => $graphError !== '' ? $graphError : $curlError,
        'error_code' => $graphErrorCode,
        'endpoint' => '/' . $safePath,
    ];
}

function estadoBadge(string $estado): string
{
    $valor = strtoupper(trim($estado));

    return match ($valor) {
        'GREEN', 'APPROVED', 'ACTIVE', 'ACTIVO', 'CONNECTED', 'VERIFIED' => 'ok',
        'YELLOW', 'PENDING', 'PRUEBA', 'IN_REVIEW' => 'warning',
        'RED', 'REJECTED', 'DISABLED', 'INACTIVO', 'FAILED' => 'danger',
        default => 'neutral',
    };
}

function textoSeguro(mixed $valor, string $default = '—'): string
{
    $texto = trim((string)$valor);
    return $texto !== '' ? $texto : $default;
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
 * Números disponibles
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
 * Configuración / Meta
 * ========================================================= */

$configWA = obtenerConfigWhatsApp();
$accessToken = (string)$configWA['access_token'];
$graphVersion = (string)$configWA['graph_version'];

$wabaId = trim((string)($numero['waba_id'] ?? ''));
$phoneNumberIdLocal = trim((string)($numero['phone_number_id'] ?? ''));

$metaDisponible = $accessToken !== '' && $wabaId !== '';

$resPhoneNumbers = null;
$resSubscribedApps = null;
$resTemplates = null;

$phoneNumbersMeta = [];
$appsSuscritas = [];
$templates = [];

if ($metaDisponible) {
    // Esta llamada es específicamente de administración y sirve como prueba real
    // de whatsapp_business_management.
    $resPhoneNumbers = graphGet(
        $graphVersion,
        $accessToken,
        $wabaId . '/phone_numbers'
    );

    $resSubscribedApps = graphGet(
        $graphVersion,
        $accessToken,
        $wabaId . '/subscribed_apps'
    );

    $resTemplates = graphGet(
        $graphVersion,
        $accessToken,
        $wabaId . '/message_templates',
        ['limit' => 100]
    );

    if (
        $resPhoneNumbers['ok']
        && isset($resPhoneNumbers['data']['data'])
        && is_array($resPhoneNumbers['data']['data'])
    ) {
        $phoneNumbersMeta = $resPhoneNumbers['data']['data'];
    }

    if (
        $resSubscribedApps['ok']
        && isset($resSubscribedApps['data']['data'])
        && is_array($resSubscribedApps['data']['data'])
    ) {
        $appsSuscritas = $resSubscribedApps['data']['data'];
    }

    if (
        $resTemplates['ok']
        && isset($resTemplates['data']['data'])
        && is_array($resTemplates['data']['data'])
    ) {
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

$displayNumero = $numero['display_phone_number']
    ?? ($numero['telefono'] ?? 'Sin número');

$nombreLocal = textoSeguro($numero['nombre_vendedor'] ?? '', 'Sin nombre');
$estadoLocal = strtoupper(textoSeguro($numero['estado'] ?? '', 'SIN ESTADO'));
$distritoLocal = textoSeguro($numero['distrito'] ?? '', 'Sin distrito');

$consultas = array_values(array_filter([
    $resPhoneNumbers,
    $resSubscribedApps,
    $resTemplates,
], static fn($v) => is_array($v)));

$consultasOk = 0;
foreach ($consultas as $c) {
    if (!empty($c['ok'])) {
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

<style>
:root{
    --bg:#f4f7fb;
    --card:#ffffff;
    --ink:#142033;
    --muted:#6d7888;
    --brand:#6c3df0;
    --brand2:#875df5;
    --green:#0f8f73;
    --green-dark:#08695a;
    --green-soft:#eaf8f2;
    --line:#e5eaf1;
    --danger:#b42318;
    --warning:#b54708;
    --shadow:0 14px 36px rgba(20,32,51,.08);
}

*{box-sizing:border-box}

html,body{
    margin:0;
    min-height:100%;
    font-family:Inter,Segoe UI,Arial,sans-serif;
    background:var(--bg);
    color:var(--ink);
}

body{overflow-x:hidden}
a{color:inherit}

.shell{
    min-height:100vh;
    display:grid;
    grid-template-columns:250px 1fr;
}

.sidebar{
    position:sticky;
    top:0;
    height:100vh;
    background:#111827;
    color:#fff;
    padding:24px 18px;
    display:flex;
    flex-direction:column;
    gap:24px;
}

.brand{
    display:flex;
    gap:12px;
    align-items:center;
}

.logo{
    width:44px;
    height:44px;
    border-radius:14px;
    background:linear-gradient(135deg,var(--brand),#9b74ff);
    display:grid;
    place-items:center;
    font-weight:800;
    font-size:20px;
}

.brand h1{
    font-size:17px;
    margin:0;
}

.brand small{color:#aeb8c8}

.nav{
    display:grid;
    gap:8px;
}

.nav a{
    padding:12px 14px;
    border-radius:12px;
    text-decoration:none;
    color:#cbd5e1;
}

.nav a.active,
.nav a:hover{
    background:#202a3a;
    color:#fff;
}

.sidebar-foot{
    margin-top:auto;
    color:#94a3b8;
    font-size:12px;
    line-height:1.5;
}

.main{
    min-width:0;
    padding:26px 30px 40px;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    margin-bottom:18px;
}

.topbar h2{
    margin:0 0 6px;
    font-size:28px;
}

.topbar p{
    margin:0;
    color:var(--muted);
}

.user-chip{
    background:#fff;
    border:1px solid var(--line);
    padding:10px 14px;
    border-radius:14px;
    font-size:13px;
    text-align:right;
}

.admin-select,
.panel,
.stat{
    background:#fff;
    border:1px solid var(--line);
    box-shadow:var(--shadow);
}

.admin-select{
    border-radius:18px;
    padding:16px 18px;
    margin-bottom:18px;
}

.field label{
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:7px;
}

select{
    width:100%;
    border:1px solid #d7deea;
    border-radius:12px;
    padding:11px 12px;
    font:inherit;
    background:#fff;
    color:var(--ink);
}

.alert{
    padding:13px 15px;
    border-radius:12px;
    margin-bottom:16px;
    font-size:14px;
}

.alert.ok{
    background:#ecfdf3;
    color:#067647;
}

.alert.error{
    background:#fef3f2;
    color:var(--danger);
}

.alert.warning{
    background:#fffaeb;
    color:var(--warning);
}

.hero{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:center;
    background:linear-gradient(135deg,#ffffff 0%,#f4efff 100%);
    border:1px solid #e6ddff;
    border-radius:20px;
    padding:20px 22px;
    box-shadow:var(--shadow);
    margin-bottom:18px;
}

.hero-left{
    display:flex;
    align-items:center;
    gap:16px;
}

.hero-icon{
    width:54px;
    height:54px;
    border-radius:16px;
    display:grid;
    place-items:center;
    background:linear-gradient(135deg,var(--brand),#9b74ff);
    color:#fff;
    font-weight:900;
    font-size:20px;
}

.hero h3{
    margin:0 0 4px;
    font-size:20px;
}

.hero p{
    margin:0;
    color:var(--muted);
    font-size:13px;
}

.hero-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    border-radius:11px;
    padding:0 14px;
    text-decoration:none;
    font-size:13px;
    font-weight:800;
    border:1px solid transparent;
}

.button.primary{
    background:var(--brand);
    color:#fff;
}

.button.primary:hover{background:#5c30da}

.button.secondary{
    background:#fff;
    color:var(--ink);
    border-color:var(--line);
}

.mode-pill{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 10px;
    border-radius:999px;
    background:#eef2ff;
    color:#4f46e5;
    font-size:12px;
    font-weight:800;
}

.dot{
    width:8px;
    height:8px;
    border-radius:50%;
    background:currentColor;
}

.stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.stat{
    border-radius:17px;
    padding:16px 17px;
}

.stat .label{
    font-size:11px;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.05em;
    font-weight:800;
}

.stat .value{
    margin-top:7px;
    font-size:18px;
    font-weight:900;
    overflow-wrap:anywhere;
}

.stat .sub{
    margin-top:5px;
    font-size:11px;
    color:var(--muted);
}

.grid-2{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:18px;
    margin-bottom:18px;
}

.panel{
    border-radius:20px;
    overflow:hidden;
    margin-bottom:18px;
}

.grid-2 .panel{margin-bottom:0}

.panel-head{
    padding:17px 19px;
    border-bottom:1px solid var(--line);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
}

.panel-head h3{
    margin:0 0 4px;
    font-size:17px;
}

.panel-head p{
    margin:0;
    font-size:12px;
    color:var(--muted);
}

.panel-body{
    padding:18px 19px;
}

.detail-list{
    display:grid;
    gap:12px;
}

.detail-row{
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    gap:12px;
    align-items:center;
}

.detail-row .k{
    color:var(--muted);
    font-size:12px;
}

.detail-row .v{
    font-size:13px;
    font-weight:700;
    overflow-wrap:anywhere;
}

.badge{
    display:inline-flex;
    align-items:center;
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    line-height:1;
}

.badge.ok{
    background:#ecfdf3;
    color:#067647;
}

.badge.warning{
    background:#fffaeb;
    color:#b54708;
}

.badge.danger{
    background:#fef3f2;
    color:#b42318;
}

.badge.neutral{
    background:#f2f4f7;
    color:#475467;
}

.table-wrap{overflow-x:auto}

table{
    width:100%;
    border-collapse:collapse;
    min-width:720px;
}

th,td{
    padding:12px 14px;
    border-bottom:1px solid #eef1f5;
    text-align:left;
    vertical-align:middle;
    font-size:12px;
}

th{
    color:#667085;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.04em;
    background:#fafbfc;
}

td strong{
    display:block;
    font-size:13px;
    color:var(--ink);
}

td .muted{
    margin-top:3px;
    color:var(--muted);
    font-size:11px;
}

.api-row{
    display:grid;
    grid-template-columns:minmax(220px,1fr) 90px 100px 110px;
    gap:12px;
    align-items:center;
    padding:12px 0;
    border-bottom:1px solid #eef1f5;
}

.api-row:last-child{border-bottom:0}

.api-endpoint{
    font-family:ui-monospace,SFMono-Regular,Consolas,monospace;
    font-size:12px;
    overflow-wrap:anywhere;
}

.empty-state{
    padding:24px;
    text-align:center;
    color:var(--muted);
    font-size:13px;
}

.note{
    margin-top:12px;
    padding:11px 12px;
    border-radius:12px;
    background:#f8fafc;
    border:1px solid var(--line);
    color:var(--muted);
    font-size:12px;
    line-height:1.45;
}

@media(max-width:1100px){
    .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
    .grid-2{grid-template-columns:1fr}
}

@media(max-width:1050px){
    .shell{grid-template-columns:1fr}
    .sidebar{display:none}
    .main{padding:18px}
}

@media(max-width:700px){
    .topbar{display:block}
    .user-chip{display:none}
    .hero{display:block}
    .hero-actions{justify-content:flex-start;margin-top:16px}
    .stats{grid-template-columns:1fr}
    .detail-row{grid-template-columns:1fr}
    .api-row{grid-template-columns:1fr}
}
</style>
</head>

<body>
<div class="shell">

<aside class="sidebar">
    <div class="brand">
        <div class="logo">T</div>
        <div>
            <h1>TalIA Connect WA</h1>
            <small>Centro de mensajería</small>
        </div>
    </div>

    <nav class="nav">
        <a href="index.php">Configuración</a>
        <a href="conversaciones.php">Conversaciones</a>
        <a class="active" href="management.php">Administración WA</a>
        <a href="privacidad.php" target="_blank" rel="noopener">Privacidad</a>
    </nav>

    <div class="sidebar-foot">
        Región SUR<br>
        Plataforma TalIA
    </div>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h2>Administración WA</h2>
            <p>Consulta activos de WhatsApp Business conectados a TalIA Connect WA.</p>
        </div>

        <div class="user-chip">
            <?= h($usuario) ?><br>
            <strong><?= h($rol) ?></strong>
        </div>
    </div>

    <?php if (!empty($numerosDisponibles)): ?>
    <form method="get" class="admin-select">
        <div class="field">
            <label for="id_numero">Número administrado</label>
            <select
                id="id_numero"
                name="id_numero"
                onchange="this.form.submit()"
            >
                <?php foreach ($numerosDisponibles as $n): ?>
                <option
                    value="<?= (int)$n['id'] ?>"
                    <?= (int)$n['id'] === $idNumeroSeleccionado ? 'selected' : '' ?>
                >
                    <?= h(
                        ($n['nombre_vendedor'] ?: 'Sin nombre')
                        . ' · '
                        . ($n['display_phone_number'] ?: $n['telefono'])
                        . ' · '
                        . ($n['estado'] ?: 'SIN ESTADO')
                    ) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <?php if (empty($numerosDisponibles)): ?>
        <div class="alert warning">
            No existen números registrados en wa_numeros.
        </div>
    <?php elseif ($accessToken === ''): ?>
        <div class="alert warning">
            No hay access token configurado. Revisa talia_whatsapp_secrets.php o la variable TALIA_WA_ACCESS_TOKEN.
        </div>
    <?php elseif ($wabaId === ''): ?>
        <div class="alert warning">
            El número seleccionado no tiene WABA ID registrado en wa_numeros.
        </div>
    <?php elseif ($totalConsultas > 0 && $consultasOk === $totalConsultas): ?>
        <div class="alert ok">
            Conexión con Meta correcta. Se consultaron activos de WhatsApp Business en modo lectura.
        </div>
    <?php elseif ($totalConsultas > 0): ?>
        <div class="alert warning">
            Meta respondió parcialmente. Revisa el diagnóstico de API al final de la página.
        </div>
    <?php endif; ?>

    <?php if ($numero): ?>
    <section class="hero">
        <div class="hero-left">
            <div class="hero-icon">WA</div>
            <div>
                <h3><?= h($nombreLocal) ?></h3>
                <p>
                    <?= h($displayNumero) ?>
                    · WABA <?= h(textoSeguro($wabaId)) ?>
                    · <?= h($estadoLocal) ?>
                </p>
            </div>
        </div>

        <div class="hero-actions">
            <span class="mode-pill">
                <span class="dot"></span>
                Management v1.0 · solo lectura
            </span>

            <a
                class="button primary"
                href="management.php?id_numero=<?= (int)$idNumeroSeleccionado ?>&refresh=1"
            >
                Actualizar desde Meta
            </a>
        </div>
    </section>

    <section class="stats">
        <div class="stat">
            <div class="label">WABA</div>
            <div class="value"><?= h(textoSeguro($wabaId)) ?></div>
            <div class="sub">WhatsApp Business Account</div>
        </div>

        <div class="stat">
            <div class="label">Graph API</div>
            <div class="value"><?= h($graphVersion) ?></div>
            <div class="sub">Versión configurada</div>
        </div>

        <div class="stat">
            <div class="label">Números WABA</div>
            <div class="value"><?= count($phoneNumbersMeta) ?></div>
            <div class="sub">Devueltos por Meta</div>
        </div>

        <div class="stat">
            <div class="label">Plantillas</div>
            <div class="value"><?= count($templates) ?></div>
            <div class="sub">Primeros 100 registros</div>
        </div>
    </section>

    <section class="grid-2">

        <article class="panel">
            <div class="panel-head">
                <div>
                    <h3>Cuenta conectada</h3>
                    <p>Identificadores locales vinculados con Meta.</p>
                </div>
            </div>

            <div class="panel-body">
                <div class="detail-list">
                    <div class="detail-row">
                        <div class="k">WABA ID</div>
                        <div class="v"><?= h(textoSeguro($wabaId)) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Phone Number ID</div>
                        <div class="v"><?= h(textoSeguro($phoneNumberIdLocal)) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Número</div>
                        <div class="v"><?= h(textoSeguro($displayNumero)) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Vendedor / propietario</div>
                        <div class="v"><?= h($nombreLocal) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Distrito</div>
                        <div class="v"><?= h($distritoLocal) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Estado local</div>
                        <div class="v">
                            <span class="badge <?= h(estadoBadge($estadoLocal)) ?>">
                                <?= h($estadoLocal) ?>
                            </span>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Zona horaria</div>
                        <div class="v"><?= h(textoSeguro($numero['timezone'] ?? '')) ?></div>
                    </div>
                </div>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <h3>Estado reportado por Meta</h3>
                    <p>Datos del número seleccionado.</p>
                </div>
            </div>

            <div class="panel-body">
                <?php if (is_array($telefonoMetaSeleccionado)): ?>
                <div class="detail-list">
                    <div class="detail-row">
                        <div class="k">Nombre verificado</div>
                        <div class="v">
                            <?= h(textoSeguro($telefonoMetaSeleccionado['verified_name'] ?? '')) ?>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Número</div>
                        <div class="v">
                            <?= h(textoSeguro($telefonoMetaSeleccionado['display_phone_number'] ?? '')) ?>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="k">ID Meta</div>
                        <div class="v">
                            <?= h(textoSeguro($telefonoMetaSeleccionado['id'] ?? '')) ?>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="k">Calidad</div>
                        <div class="v">
                            <?php $quality = strtoupper(textoSeguro($telefonoMetaSeleccionado['quality_rating'] ?? '', 'NA')); ?>
                            <span class="badge <?= h(estadoBadge($quality)) ?>">
                                <?= h($quality) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <div class="empty-state">
                        El Phone Number ID local no fue encontrado en la respuesta de Meta.
                    </div>
                <?php endif; ?>
            </div>
        </article>

    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Números conectados a la WABA</h3>
                <p>Consulta en vivo: /<?= h($wabaId) ?>/phone_numbers</p>
            </div>

            <?php if (is_array($resPhoneNumbers)): ?>
            <span class="badge <?= $resPhoneNumbers['ok'] ? 'ok' : 'danger' ?>">
                HTTP <?= (int)$resPhoneNumbers['http_code'] ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($phoneNumbersMeta)): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nombre verificado</th>
                        <th>Número</th>
                        <th>Phone Number ID</th>
                        <th>Calidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($phoneNumbersMeta as $pn): ?>
                    <?php $quality = strtoupper(textoSeguro($pn['quality_rating'] ?? '', 'NA')); ?>
                    <tr>
                        <td>
                            <strong><?= h(textoSeguro($pn['verified_name'] ?? '')) ?></strong>
                        </td>
                        <td><?= h(textoSeguro($pn['display_phone_number'] ?? '')) ?></td>
                        <td><?= h(textoSeguro($pn['id'] ?? '')) ?></td>
                        <td>
                            <span class="badge <?= h(estadoBadge($quality)) ?>">
                                <?= h($quality) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state">
                No se recibieron números desde Meta.
                <?php if (is_array($resPhoneNumbers) && !$resPhoneNumbers['ok']): ?>
                    <br><?= h(textoSeguro($resPhoneNumbers['error'] ?? '')) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Apps / webhook suscritos</h3>
                <p>Consulta en vivo: /<?= h($wabaId) ?>/subscribed_apps</p>
            </div>

            <?php if (is_array($resSubscribedApps)): ?>
            <span class="badge <?= $resSubscribedApps['ok'] ? 'ok' : 'danger' ?>">
                HTTP <?= (int)$resSubscribedApps['http_code'] ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($appsSuscritas)): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Aplicación</th>
                        <th>App ID</th>
                        <th>Categoría</th>
                        <th>Suscripción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appsSuscritas as $appRow): ?>
                    <?php
                        $app = isset($appRow['whatsapp_business_api_data'])
                            && is_array($appRow['whatsapp_business_api_data'])
                                ? $appRow['whatsapp_business_api_data']
                                : $appRow;
                    ?>
                    <tr>
                        <td>
                            <strong><?= h(textoSeguro($app['name'] ?? '', 'App suscrita')) ?></strong>
                        </td>
                        <td><?= h(textoSeguro($app['id'] ?? '')) ?></td>
                        <td><?= h(textoSeguro($app['category'] ?? '')) ?></td>
                        <td>
                            <span class="badge ok">ACTIVA</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state">
                No se recibieron suscripciones desde Meta.
                <?php if (is_array($resSubscribedApps) && !$resSubscribedApps['ok']): ?>
                    <br><?= h(textoSeguro($resSubscribedApps['error'] ?? '')) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Plantillas de mensajes</h3>
                <p>Consulta en vivo: /<?= h($wabaId) ?>/message_templates</p>
            </div>

            <?php if (is_array($resTemplates)): ?>
            <span class="badge <?= $resTemplates['ok'] ? 'ok' : 'danger' ?>">
                HTTP <?= (int)$resTemplates['http_code'] ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($templates)): ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Estado</th>
                        <th>Categoría</th>
                        <th>Idioma</th>
                        <th>Template ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                    <?php $statusTpl = strtoupper(textoSeguro($tpl['status'] ?? '', 'N/A')); ?>
                    <tr>
                        <td>
                            <strong><?= h(textoSeguro($tpl['name'] ?? '')) ?></strong>
                        </td>
                        <td>
                            <span class="badge <?= h(estadoBadge($statusTpl)) ?>">
                                <?= h($statusTpl) ?>
                            </span>
                        </td>
                        <td><?= h(textoSeguro($tpl['category'] ?? '')) ?></td>
                        <td><?= h(textoSeguro($tpl['language'] ?? '')) ?></td>
                        <td><?= h(textoSeguro($tpl['id'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state">
                No se recibieron plantillas desde Meta.
                <?php if (is_array($resTemplates) && !$resTemplates['ok']): ?>
                    <br><?= h(textoSeguro($resTemplates['error'] ?? '')) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Diagnóstico de API Management</h3>
                <p>
                    Última consulta <?= h($actualizado) ?>.
                    El token nunca se imprime ni se guarda en este log.
                </p>
            </div>

            <span class="badge <?= $totalConsultas > 0 && $consultasOk === $totalConsultas ? 'ok' : 'warning' ?>">
                <?= (int)$consultasOk ?>/<?= (int)$totalConsultas ?> OK
            </span>
        </div>

        <div class="panel-body">
            <?php if (!empty($consultas)): ?>
                <?php foreach ($consultas as $consulta): ?>
                <div class="api-row">
                    <div class="api-endpoint">
                        GET <?= h(textoSeguro($consulta['endpoint'] ?? '')) ?>
                    </div>

                    <div>
                        <span class="badge <?= !empty($consulta['ok']) ? 'ok' : 'danger' ?>">
                            <?= !empty($consulta['ok']) ? 'OK' : 'ERROR' ?>
                        </span>
                    </div>

                    <div>HTTP <?= (int)($consulta['http_code'] ?? 0) ?></div>
                    <div><?= (int)($consulta['duration_ms'] ?? 0) ?> ms</div>
                </div>

                <?php if (empty($consulta['ok']) && !empty($consulta['error'])): ?>
                <div class="note">
                    <?= h((string)$consulta['error']) ?>
                </div>
                <?php endif; ?>

                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    No se ejecutaron llamadas de Management.
                </div>
            <?php endif; ?>

            <div class="note">
                Esta versión es deliberadamente de solo lectura. No elimina números,
                no modifica WABA, no crea ni borra plantillas y no altera suscripciones.
                Las llamadas se realizan con Authorization Bearer desde el servidor;
                el access token no se muestra en la interfaz.
            </div>
        </div>
    </section>

    <?php endif; ?>

</main>
</div>
</body>
</html>
