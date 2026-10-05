<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Onboarding Coexistence
 * Versión 1.0
 *
 * Permite conectar un número que ya usa WhatsApp Business App
 * a Cloud API mediante Meta Embedded Signup, conservando la app móvil.
 *
 * IMPORTANTE:
 * - App Secret y tokens NUNCA se envían al navegador.
 * - El flujo utiliza featureType=whatsapp_business_app_onboarding.
 * - La finalización y el intercambio del code ocurren en coexistence_finalize.php.
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

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tablaExisteCoex(mysqli $conexion, string $tabla): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 's', $tabla);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $n);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int)$n > 0;
}

function configCoexPublica(): array {
    $out = [
        'app_id' => '',
        'config_id' => '',
        'graph_version' => 'v26.0',
    ];

    $ruta = dirname(__DIR__) . '/talia_whatsapp_secrets.php';
    if (!is_file($ruta)) {
        return $out;
    }

    $cfg = require $ruta;
    if (!is_array($cfg)) {
        return $out;
    }

    foreach (['app_id', 'embedded_signup_config_id', 'graph_version'] as $k) {
        if (isset($cfg[$k]) && is_string($cfg[$k])) {
            $valor = trim($cfg[$k]);
            if ($k === 'embedded_signup_config_id') {
                $out['config_id'] = $valor;
            } else {
                $out[$k] = $valor;
            }
        }
    }

    if (!preg_match('/^v\d+\.\d+$/', $out['graph_version'])) {
        $out['graph_version'] = 'v26.0';
    }

    return $out;
}

$rol = (string)($_SESSION['rol'] ?? 'vendedor');
$usuario = (string)($_SESSION['usuario'] ?? '');

if (!in_array($rol, ['admin', 'director_regional'], true)) {
    http_response_code(403);
    exit('No tienes permisos para administrar onboarding de WhatsApp.');
}

if (empty($_SESSION['talia_wa_csrf'])) {
    $_SESSION['talia_wa_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['talia_wa_csrf'];

$cfg = configCoexPublica();
$configLista = $cfg['app_id'] !== '' && $cfg['config_id'] !== '';
$tablasListas = tablaExisteCoex($conexion, 'wa_meta_conexiones')
    && tablaExisteCoex($conexion, 'wa_coexistence_eventos')
    && tablaExisteCoex($conexion, 'wa_coexistence_contactos');

$conexiones = [];
if ($tablasListas) {
    $sql = "SELECT
                c.id, c.id_numero, c.waba_id, c.phone_number_id,
                c.modo, c.estado, c.webhook_suscrito,
                c.contactos_sync_estado, c.historial_sync_estado,
                c.fecha_onboarding, c.fecha_actualizacion,
                n.nombre_vendedor, n.display_phone_number, n.telefono, n.estado AS estado_numero
            FROM wa_meta_conexiones c
            LEFT JOIN wa_numeros n ON n.id = c.id_numero
            ORDER BY c.id DESC
            LIMIT 20";
    $rs = mysqli_query($conexion, $sql);
    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $conexiones[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Coexistence | TalIA Connect WA</title>
<style>
:root{
    --bg:#f4f7fb;--card:#fff;--ink:#142033;--muted:#6d7888;
    --brand:#6c3df0;--brand2:#875df5;--green:#0f8f73;
    --line:#e5eaf1;--danger:#b42318;--warning:#b54708;
    --shadow:0 14px 36px rgba(20,32,51,.08);
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink)}
body{overflow-x:hidden}
.shell{min-height:100vh;display:grid;grid-template-columns:250px 1fr}
.sidebar{position:sticky;top:0;height:100vh;background:#111827;color:#fff;padding:24px 18px;display:flex;flex-direction:column;gap:24px}
.brand{display:flex;gap:12px;align-items:center}
.logo{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--brand),#9b74ff);display:grid;place-items:center;font-weight:800;font-size:20px}
.brand h1{font-size:17px;margin:0}.brand small{color:#aeb8c8}
.nav{display:grid;gap:8px}.nav a{padding:12px 14px;border-radius:12px;text-decoration:none;color:#cbd5e1}
.nav a.active,.nav a:hover{background:#202a3a;color:#fff}
.sidebar-foot{margin-top:auto;color:#94a3b8;font-size:12px;line-height:1.5}
.main{min-width:0;padding:26px 30px 40px}
.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:18px}
.topbar h2{margin:0 0 6px;font-size:28px}.topbar p{margin:0;color:var(--muted)}
.user-chip{background:#fff;border:1px solid var(--line);padding:10px 14px;border-radius:14px;font-size:13px;text-align:right}
.alert{padding:13px 15px;border-radius:12px;margin-bottom:16px;font-size:14px}
.alert.ok{background:#ecfdf3;color:#067647}.alert.warning{background:#fffaeb;color:#b54708}.alert.error{background:#fef3f2;color:#b42318}
.hero,.panel,.stat{background:#fff;border:1px solid var(--line);box-shadow:var(--shadow)}
.hero{display:flex;justify-content:space-between;gap:18px;align-items:center;background:linear-gradient(135deg,#fff 0%,#f4efff 100%);border-color:#e6ddff;border-radius:20px;padding:20px 22px;margin-bottom:18px}
.hero-left{display:flex;align-items:center;gap:16px}.hero-icon{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,var(--brand),#9b74ff);color:#fff;font-weight:900;font-size:18px}
.hero h3{margin:0 0 4px;font-size:20px}.hero p{margin:0;color:var(--muted);font-size:13px}
.panel{border-radius:20px;overflow:hidden;margin-bottom:18px}.panel-head{padding:17px 19px;border-bottom:1px solid var(--line)}
.panel-head h3{margin:0 0 4px;font-size:17px}.sub{color:var(--muted);font-size:12px}
.panel-body{padding:20px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.field{margin-bottom:15px}.field label{display:block;font-size:13px;font-weight:800;margin-bottom:7px}
input,select{width:100%;border:1px solid #d7deea;border-radius:12px;padding:11px 12px;font:inherit;background:#fff;color:var(--ink)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border-radius:11px;padding:0 15px;text-decoration:none;font-size:13px;font-weight:800;border:1px solid transparent;cursor:pointer}
.button.primary{background:var(--brand);color:#fff}.button.secondary{background:#fff;color:var(--ink);border-color:var(--line)}
.button:disabled{opacity:.45;cursor:not-allowed}
.steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px;min-height:112px;box-shadow:var(--shadow)}
.step .n{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#ede9fe;color:#5b21b6;font-weight:900;margin-bottom:8px}
.step strong{display:block;margin-bottom:5px}.step span{font-size:11px;color:var(--muted);line-height:1.4}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:left;padding:11px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
.badge{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:10px;font-weight:900;background:#eef2ff;color:#4f46e5}
.badge.ok{background:#ecfdf3;color:#067647}.badge.warn{background:#fffaeb;color:#b54708}.badge.err{background:#fef3f2;color:#b42318}
.logbox{background:#0f172a;color:#dbeafe;border-radius:14px;padding:14px;font:12px/1.55 Consolas,monospace;white-space:pre-wrap;min-height:110px}
.small-note{font-size:11px;color:var(--muted);line-height:1.5;margin-top:10px}
@media(max-width:1050px){.shell{grid-template-columns:1fr}.sidebar{display:none}.main{padding:18px}.steps{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.grid,.steps{grid-template-columns:1fr}.topbar{display:block}.user-chip{display:none}.hero{display:block}}
</style>
</head>
<body>
<div id="fb-root"></div>
<div class="shell">
<aside class="sidebar">
    <div class="brand">
        <div class="logo">T</div>
        <div><h1>TalIA Connect WA</h1><small>Centro de mensajería</small></div>
    </div>
    <nav class="nav">
        <a href="index.php">Configuración</a>
        <a href="conversaciones.php">Conversaciones</a>
        <a href="management.php">Administración WA</a>
        <a class="active" href="coexistence.php">Conectar WhatsApp</a>
        <a href="privacidad.php" target="_blank" rel="noopener">Privacidad</a>
    </nav>
    <div class="sidebar-foot">Región SUR<br>Plataforma TalIA</div>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h2>Conectar WhatsApp Business</h2>
            <p>Onboarding Coexistence: conserva la app móvil y agrega Cloud API a TalIA Connect WA.</p>
        </div>
        <div class="user-chip"><?= h($usuario) ?><br><strong><?= h($rol) ?></strong></div>
    </div>

    <?php if (!$tablasListas): ?>
        <div class="alert error">Faltan tablas de Coexistence. Ejecuta primero <strong>install_coexistence.sql</strong>.</div>
    <?php elseif (!$configLista): ?>
        <div class="alert warning">Falta configurar <strong>app_id</strong> y <strong>embedded_signup_config_id</strong> en talia_whatsapp_secrets.php.</div>
    <?php else: ?>
        <div class="alert ok">Módulo listo para iniciar Meta Embedded Signup en modo Coexistence.</div>
    <?php endif; ?>

    <section class="hero">
        <div class="hero-left">
            <div class="hero-icon">WA</div>
            <div>
                <h3>Primer piloto: número que ya usa WhatsApp Business</h3>
                <p>El flujo no hace una migración tradicional; solicita a Meta la ruta de WhatsApp Business App Onboarding.</p>
            </div>
        </div>
        <span class="badge">Coexistence v1.0</span>
    </section>

    <div class="steps">
        <div class="step"><div class="n">1</div><strong>Preparar</strong><span>El número debe seguir activo en WhatsApp Business y el responsable debe poder autorizarlo.</span></div>
        <div class="step"><div class="n">2</div><strong>Conectar con Meta</strong><span>Embedded Signup abre el flujo con featureType whatsapp_business_app_onboarding.</span></div>
        <div class="step"><div class="n">3</div><strong>Vincular TalIA</strong><span>El servidor intercambia el code, valida el WABA y registra el Phone Number ID.</span></div>
        <div class="step"><div class="n">4</div><strong>Sincronizar</strong><span>Después del onboarding podrás solicitar contactos e historial dentro de la ventana permitida por Meta.</span></div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <h3>Nuevo onboarding Coexistence</h3>
            <div class="sub">Los secretos permanecen únicamente en el servidor.</div>
        </div>
        <div class="panel-body">
            <div class="grid">
                <div class="field">
                    <label for="telefono">Número WhatsApp Business</label>
                    <input id="telefono" value="529602334572" inputmode="numeric" autocomplete="off">
                    <div class="small-note">Formato internacional, solo dígitos. Para México: 52 + 10 dígitos.</div>
                </div>
                <div class="field">
                    <label for="nombre_vendedor">Vendedor / propietario</label>
                    <input id="nombre_vendedor" placeholder="Ej. PILOTO COEXISTENCE">
                </div>
                <div class="field">
                    <label for="numero_talento">Número de talento (opcional)</label>
                    <input id="numero_talento" autocomplete="off">
                </div>
                <div class="field">
                    <label for="id_posicion">ID posición (opcional)</label>
                    <input id="id_posicion" autocomplete="off">
                </div>
                <div class="field">
                    <label for="distrito">Distrito (opcional)</label>
                    <select id="distrito">
                        <option value="">Sin distrito</option>
                        <option>MÉRIDA</option>
                        <option>CANCÚN</option>
                        <option>COATZA MINA</option>
                        <option>TUXTLA GUTIÉRREZ</option>
                        <option>VILLAHERMOSA</option>
                    </select>
                </div>
            </div>

            <div class="actions">
                <button id="btnConnect" class="button primary" <?= (!$tablasListas || !$configLista) ? 'disabled' : '' ?>>Conectar WhatsApp Business</button>
                <button id="btnClear" class="button secondary" type="button">Limpiar diagnóstico</button>
            </div>
            <div class="small-note">
                No cierres el popup de Meta hasta finalizar. El código de autorización es temporal y se intercambia inmediatamente en el servidor.
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h3>Diagnóstico del onboarding</h3>
            <div class="sub">No se muestran access tokens, App Secret ni credenciales.</div>
        </div>
        <div class="panel-body">
            <div id="status" class="logbox">Listo para iniciar.</div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h3>Conexiones registradas</h3>
            <div class="sub">Últimas 20 conexiones Meta guardadas por TalIA.</div>
        </div>
        <div class="panel-body table-wrap">
            <?php if (empty($conexiones)): ?>
                <div class="sub">Aún no hay conexiones Coexistence registradas.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr><th>Número</th><th>WABA</th><th>Phone ID</th><th>Webhook</th><th>Contactos</th><th>Historial</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                <?php foreach ($conexiones as $c): ?>
                    <tr>
                        <td><strong><?= h($c['display_phone_number'] ?: $c['telefono']) ?></strong><br><?= h($c['nombre_vendedor'] ?: 'Sin asignar') ?></td>
                        <td><?= h($c['waba_id']) ?></td>
                        <td><?= h($c['phone_number_id']) ?></td>
                        <td><span class="badge <?= ((int)$c['webhook_suscrito'] === 1) ? 'ok' : 'warn' ?>"><?= ((int)$c['webhook_suscrito'] === 1) ? 'SUSCRITO' : 'PENDIENTE' ?></span></td>
                        <td><span class="badge"><?= h($c['contactos_sync_estado']) ?></span></td>
                        <td><span class="badge"><?= h($c['historial_sync_estado']) ?></span></td>
                        <td>
                            <div class="actions" style="margin:0">
                                <button class="button secondary js-sync" data-id="<?= (int)$c['id_numero'] ?>" data-type="smb_app_state_sync">Contactos</button>
                                <button class="button secondary js-sync" data-id="<?= (int)$c['id_numero'] ?>" data-type="history">Historial</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </section>
</main>
</div>

<script>
const TALIA = <?= json_encode([
    'appId' => $cfg['app_id'],
    'configId' => $cfg['config_id'],
    'graphVersion' => $cfg['graph_version'],
    'csrf' => $csrf,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let signupSession = null;
let pendingCode = '';
let finalizeTimer = null;

const statusBox = document.getElementById('status');

function logStatus(msg) {
    const hora = new Date().toLocaleTimeString();
    statusBox.textContent += `\n[${hora}] ${msg}`;
    statusBox.scrollTop = statusBox.scrollHeight;
}

function setStatus(msg) {
    statusBox.textContent = msg;
}

window.fbAsyncInit = function() {
    FB.init({
        appId: TALIA.appId,
        autoLogAppEvents: true,
        xfbml: false,
        version: TALIA.graphVersion
    });
    logStatus('Facebook JavaScript SDK listo.');
};

(function(d, s, id) {
    const fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) return;
    const js = d.createElement(s);
    js.id = id;
    js.src = 'https://connect.facebook.net/es_LA/sdk.js';
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));

window.addEventListener('message', function(event) {
    if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') {
        return;
    }

    let data = event.data;
    if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch (_) { return; }
    }

    if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;

    signupSession = data;
    const evento = data.event || 'EVENTO';
    const waba = data?.data?.waba_id || '';
    const phone = data?.data?.phone_number_id || '';

    logStatus(`Meta session: ${evento}${waba ? ' | WABA=' + waba : ''}${phone ? ' | PHONE_ID=' + phone : ''}`);

    if (pendingCode) {
        scheduleFinalize();
    }
});

function scheduleFinalize() {
    if (finalizeTimer) clearTimeout(finalizeTimer);
    finalizeTimer = setTimeout(() => finalizeSignup(), 700);
}

document.getElementById('btnConnect')?.addEventListener('click', function() {
    const telefono = document.getElementById('telefono').value.replace(/\D+/g, '');
    if (!/^52\d{10}$/.test(telefono)) {
        setStatus('ERROR: captura el número en formato internacional. México = 52 + 10 dígitos.');
        return;
    }

    setStatus('Abriendo Meta Embedded Signup en modo Coexistence...');

    if (typeof FB === 'undefined') {
        logStatus('ERROR: el SDK de Meta todavía no está listo.');
        return;
    }

    // IMPORTANTE: FB.login se ejecuta directamente dentro del click para evitar bloqueo del popup.
    FB.login(function(response) {
        if (response && response.authResponse && response.authResponse.code) {
            pendingCode = response.authResponse.code;
            logStatus('Código temporal recibido. Finalizando en servidor...');
            scheduleFinalize();
        } else {
            logStatus('El flujo fue cancelado o Meta no devolvió un código de autorización.');
        }
    }, {
        config_id: TALIA.configId,
        response_type: 'code',
        override_default_response_type: true,
        extras: {
            setup: {},
            featureType: 'whatsapp_business_app_onboarding',
            sessionInfoVersion: '3'
        }
    });
});

async function finalizeSignup() {
    if (!pendingCode) return;

    const payload = {
        csrf: TALIA.csrf,
        code: pendingCode,
        session: signupSession,
        expected_phone: document.getElementById('telefono').value.replace(/\D+/g, ''),
        nombre_vendedor: document.getElementById('nombre_vendedor').value.trim(),
        numero_talento: document.getElementById('numero_talento').value.trim(),
        id_posicion: document.getElementById('id_posicion').value.trim(),
        distrito: document.getElementById('distrito').value.trim()
    };

    // Consumimos el code una sola vez.
    pendingCode = '';

    try {
        const r = await fetch('coexistence_finalize.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await r.json();

        if (!r.ok || !data.ok) {
            logStatus('ERROR: ' + (data.error || 'No fue posible finalizar el onboarding.'));
            if (Array.isArray(data.details)) {
                data.details.forEach(x => logStatus('Detalle: ' + x));
            }
            return;
        }

        logStatus('ONBOARDING COMPLETADO.');
        logStatus('Número: ' + data.display_phone_number);
        logStatus('WABA ID: ' + data.waba_id);
        logStatus('Phone Number ID: ' + data.phone_number_id);
        logStatus('Webhook WABA: ' + (data.webhook_subscribed ? 'SUSCRITO' : 'PENDIENTE'));

        if (Array.isArray(data.warnings)) {
            data.warnings.forEach(x => logStatus('Aviso: ' + x));
        }

        setTimeout(() => window.location.reload(), 1800);
    } catch (e) {
        logStatus('ERROR de comunicación con TalIA: ' + e.message);
    }
}

document.getElementById('btnClear')?.addEventListener('click', () => setStatus('Listo para iniciar.'));

document.querySelectorAll('.js-sync').forEach(btn => {
    btn.addEventListener('click', async function() {
        const tipo = this.dataset.type;
        const idNumero = parseInt(this.dataset.id || '0', 10);

        const texto = tipo === 'history' ? 'historial' : 'contactos';
        if (!confirm(`Solicitar sincronización de ${texto}? Esta operación de Coexistence puede ser de una sola ejecución.`)) {
            return;
        }

        this.disabled = true;
        logStatus(`Solicitando sync de ${texto}...`);

        try {
            const r = await fetch('coexistence_sync.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({csrf:TALIA.csrf,id_numero:idNumero,sync_type:tipo})
            });
            const data = await r.json();

            if (!r.ok || !data.ok) {
                logStatus('ERROR sync: ' + (data.error || 'respuesta no válida'));
            } else {
                logStatus(`Sync ${texto} solicitado. Request ID: ${data.request_id || 'sin ID'}`);
                setTimeout(() => window.location.reload(), 1200);
            }
        } catch (e) {
            logStatus('ERROR sync: ' + e.message);
        } finally {
            this.disabled = false;
        }
    });
});
</script>
</body>
</html>
