<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Landing / Panel de configuración
 * Versión 1.0
 * Ruta: /public_html/plataforma/talia-whatsapp/index.php
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

function h(mixed $valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function tablaExiste(mysqli $conexion, string $tabla): bool {
    $sql = "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 's', $tabla);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int)$total > 0;
}

function obtenerNumero(mysqli $conexion, int $idNumero): ?array {
    $sql = "SELECT id, numero_talento, id_posicion, nombre_vendedor, distrito,
                   telefono, display_phone_number, phone_number_id, waba_id,
                   bot_activo, modo_respuesta, espera_segundos,
                   horario_inicio, horario_fin, timezone, estado
            FROM wa_numeros
            WHERE id = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $idNumero);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
    return $fila;
}

function obtenerNumeroUsuario(mysqli $conexion, string $talento, string $idPosicion): ?array {
    $sql = "SELECT id, numero_talento, id_posicion, nombre_vendedor, distrito,
                   telefono, display_phone_number, phone_number_id, waba_id,
                   bot_activo, modo_respuesta, espera_segundos,
                   horario_inicio, horario_fin, timezone, estado
            FROM wa_numeros
            WHERE
                (numero_talento IS NOT NULL AND numero_talento <> '' AND numero_talento = ?)
                OR
                (id_posicion IS NOT NULL AND id_posicion <> '' AND id_posicion = ?)
            ORDER BY estado = 'ACTIVO' DESC, id DESC
            LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $talento, $idPosicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
    return $fila;
}

function obtenerConfiguracion(mysqli $conexion, int $idNumero): array {
    $default = [
        'bienvenida_activa' => 1,
        'mensaje_bienvenida' => 'Hola, gracias por contactarnos. En un momento te atendemos.',
        'fuera_horario_activo' => 1,
        'mensaje_fuera_horario' => 'Gracias por escribirnos. En este momento estamos fuera de horario; retomaremos tu mensaje en cuanto iniciemos atención.'
    ];

    if (!tablaExiste($conexion, 'wa_configuracion_mensajes') || $idNumero <= 0) {
        return $default;
    }

    $sql = "SELECT bienvenida_activa, mensaje_bienvenida,
                   fuera_horario_activo, mensaje_fuera_horario
            FROM wa_configuracion_mensajes
            WHERE id_numero = ?
            LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $idNumero);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    return $fila ? array_merge($default, $fila) : $default;
}

$rol = (string)($_SESSION['rol'] ?? 'vendedor');
$talento = (string)($_SESSION['numero_talento_gs'] ?? '');
$idPosicion = (string)($_SESSION['id_posicion'] ?? '');
$usuario = (string)($_SESSION['usuario'] ?? '');

$rolesAdministrativos = ['admin', 'director_regional'];
$esAdmin = in_array($rol, $rolesAdministrativos, true);

if (empty($_SESSION['talia_wa_csrf'])) {
    $_SESSION['talia_wa_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['talia_wa_csrf'];

$numerosDisponibles = [];
$idNumeroSeleccionado = 0;

if ($esAdmin) {
    $rs = mysqli_query(
        $conexion,
        "SELECT id, nombre_vendedor, display_phone_number, telefono, estado
         FROM wa_numeros
         ORDER BY estado = 'ACTIVO' DESC, nombre_vendedor, id"
    );
    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $numerosDisponibles[] = $row;
        }
    }

    $idNumeroSeleccionado = (int)($_POST['id_numero'] ?? $_GET['id_numero'] ?? 0);
    if ($idNumeroSeleccionado <= 0 && !empty($numerosDisponibles)) {
        $idNumeroSeleccionado = (int)$numerosDisponibles[0]['id'];
    }
    $numero = $idNumeroSeleccionado > 0 ? obtenerNumero($conexion, $idNumeroSeleccionado) : null;
} else {
    $numero = obtenerNumeroUsuario($conexion, $talento, $idPosicion);
    if ($numero) {
        $idNumeroSeleccionado = (int)$numero['id'];
    }
}

$mensajeUi = '';
$tipoMensajeUi = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_configuracion'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $mensajeUi = 'La sesión de seguridad expiró. Recarga la página e inténtalo nuevamente.';
        $tipoMensajeUi = 'error';
    } elseif (!$numero || $idNumeroSeleccionado <= 0) {
        $mensajeUi = 'No hay un número de WhatsApp asociado para guardar la configuración.';
        $tipoMensajeUi = 'error';
    } elseif (!tablaExiste($conexion, 'wa_configuracion_mensajes')) {
        $mensajeUi = 'Falta instalar la tabla wa_configuracion_mensajes. Ejecuta primero el SQL incluido con esta versión.';
        $tipoMensajeUi = 'error';
    } else {
        $botActivo = isset($_POST['bot_activo']) ? 1 : 0;
        $modoRespuesta = strtoupper(trim((string)($_POST['modo_respuesta'] ?? 'MANUAL')));
        if (!in_array($modoRespuesta, ['INMEDIATO', 'ESPERA', 'MANUAL'], true)) {
            $modoRespuesta = 'MANUAL';
        }

        $esperaSegundos = max(0, min(3600, (int)($_POST['espera_segundos'] ?? 60)));
        $horarioInicio = trim((string)($_POST['horario_inicio'] ?? '09:00'));
        $horarioFin = trim((string)($_POST['horario_fin'] ?? '19:00'));
        $timezone = 'America/Merida';

        $bienvenidaActiva = isset($_POST['bienvenida_activa']) ? 1 : 0;
        $fueraHorarioActiva = isset($_POST['fuera_horario_activo']) ? 1 : 0;
        $mensajeBienvenida = trim((string)($_POST['mensaje_bienvenida'] ?? ''));
        $mensajeFueraHorario = trim((string)($_POST['mensaje_fuera_horario'] ?? ''));

        if (mb_strlen($mensajeBienvenida) > 2000 || mb_strlen($mensajeFueraHorario) > 2000) {
            $mensajeUi = 'Los mensajes no pueden exceder 2,000 caracteres.';
            $tipoMensajeUi = 'error';
        } elseif (!preg_match('/^\d{2}:\d{2}$/', $horarioInicio) || !preg_match('/^\d{2}:\d{2}$/', $horarioFin)) {
            $mensajeUi = 'Revisa el formato del horario.';
            $tipoMensajeUi = 'error';
        } else {
            mysqli_begin_transaction($conexion);
            try {
                $sqlNumero = "UPDATE wa_numeros
                              SET bot_activo = ?,
                                  modo_respuesta = ?,
                                  espera_segundos = ?,
                                  horario_inicio = ?,
                                  horario_fin = ?,
                                  timezone = ?
                              WHERE id = ?";
                $stmt = mysqli_prepare($conexion, $sqlNumero);
                mysqli_stmt_bind_param(
                    $stmt, 'isisssi',
                    $botActivo, $modoRespuesta, $esperaSegundos,
                    $horarioInicio, $horarioFin, $timezone,
                    $idNumeroSeleccionado
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $sqlCfg = "INSERT INTO wa_configuracion_mensajes (
                                id_numero, bienvenida_activa, mensaje_bienvenida,
                                fuera_horario_activo, mensaje_fuera_horario
                           ) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                                bienvenida_activa = VALUES(bienvenida_activa),
                                mensaje_bienvenida = VALUES(mensaje_bienvenida),
                                fuera_horario_activo = VALUES(fuera_horario_activo),
                                mensaje_fuera_horario = VALUES(mensaje_fuera_horario),
                                fecha_actualizacion = CURRENT_TIMESTAMP";
                $stmt = mysqli_prepare($conexion, $sqlCfg);
                mysqli_stmt_bind_param(
                    $stmt, 'iisis',
                    $idNumeroSeleccionado, $bienvenidaActiva, $mensajeBienvenida,
                    $fueraHorarioActiva, $mensajeFueraHorario
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conexion);
                $mensajeUi = 'Configuración guardada correctamente.';
                $tipoMensajeUi = 'ok';
                $numero = obtenerNumero($conexion, $idNumeroSeleccionado);
            } catch (Throwable $e) {
                mysqli_rollback($conexion);
                $mensajeUi = 'No fue posible guardar la configuración.';
                $tipoMensajeUi = 'error';
                error_log('TalIA Connect WA landing: ' . $e->getMessage());
            }
        }
    }
}

$config = obtenerConfiguracion($conexion, $numero ? (int)$numero['id'] : 0);

$displayNumero = $numero['display_phone_number'] ?? ($numero['telefono'] ?? 'Sin número vinculado');
$estadoNumero = strtoupper((string)($numero['estado'] ?? 'SIN VINCULAR'));
$botActivoActual = (int)($numero['bot_activo'] ?? 0) === 1;
$modoActual = (string)($numero['modo_respuesta'] ?? 'MANUAL');
$esperaActual = (int)($numero['espera_segundos'] ?? 60);
$inicioActual = substr((string)($numero['horario_inicio'] ?? '09:00'), 0, 5);
$finActual = substr((string)($numero['horario_fin'] ?? '19:00'), 0, 5);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TalIA Connect WA</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--ink:#142033;--muted:#6d7888;--brand:#6c3df0;--green:#15a87b;--line:#e5eaf1;--danger:#b42318;--shadow:0 14px 36px rgba(20,32,51,.08)}
*{box-sizing:border-box}body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink)}a{color:inherit}
.shell{min-height:100vh;display:grid;grid-template-columns:250px 1fr}.sidebar{background:#111827;color:#fff;padding:24px 18px;display:flex;flex-direction:column;gap:24px}
.brand{display:flex;gap:12px;align-items:center}.logo{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--brand),#9b74ff);display:grid;place-items:center;font-weight:800;font-size:20px}.brand h1{font-size:17px;margin:0}.brand small{color:#aeb8c8}
.nav{display:grid;gap:8px}.nav a{padding:12px 14px;border-radius:12px;text-decoration:none;color:#cbd5e1}.nav a.active,.nav a:hover{background:#202a3a;color:#fff}.sidebar-foot{margin-top:auto;color:#94a3b8;font-size:12px;line-height:1.5}
.main{padding:28px 34px 44px}.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:22px}.topbar h2{margin:0 0 6px;font-size:28px}.topbar p{margin:0;color:var(--muted)}.user-chip{background:#fff;border:1px solid var(--line);padding:10px 14px;border-radius:14px;font-size:13px}
.grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,.8fr);gap:20px}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;box-shadow:var(--shadow);padding:22px}.card h3{margin:0 0 5px;font-size:18px}.sub{color:var(--muted);font-size:13px;margin-bottom:18px}
.status{display:flex;align-items:center;gap:10px}.dot{width:10px;height:10px;border-radius:50%;background:#aab3c0}.dot.ok{background:#10b981}.dot.warn{background:#f59e0b}.badge{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#4f46e5;font-size:12px;font-weight:700}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{margin-bottom:16px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}input[type=text],input[type=number],input[type=time],textarea,select{width:100%;border:1px solid #d7deea;border-radius:12px;padding:11px 12px;font:inherit;background:#fff;color:var(--ink)}textarea{min-height:116px;resize:vertical}
.toggle{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid var(--line)}.switch{position:relative;width:48px;height:28px}.switch input{opacity:0;width:0;height:0}.slider{position:absolute;inset:0;background:#cbd5e1;border-radius:30px;transition:.2s}.slider:before{content:"";position:absolute;width:22px;height:22px;left:3px;top:3px;background:white;border-radius:50%;transition:.2s;box-shadow:0 2px 8px rgba(0,0,0,.18)}.switch input:checked+.slider{background:var(--green)}.switch input:checked+.slider:before{transform:translateX(20px)}
.modes{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.mode{border:1px solid var(--line);padding:12px;border-radius:12px}.mode input{margin-right:6px}.btn{border:0;border-radius:12px;padding:12px 18px;font-weight:800;cursor:pointer}.btn-primary{background:var(--brand);color:white}.btn:disabled{opacity:.5;cursor:not-allowed}
.alert{padding:13px 15px;border-radius:12px;margin-bottom:18px;font-size:14px}.alert.ok{background:#ecfdf3;color:#067647}.alert.error{background:#fef3f2;color:var(--danger)}
.preview{background:#e9f7ef;border-radius:22px;padding:20px;min-height:360px;overflow:hidden}.phone-head{background:#075e54;color:#fff;padding:12px;border-radius:16px 16px 5px 5px;font-weight:700}.bubble{background:#fff;border-radius:7px;padding:11px 12px;margin:18px 0 0;box-shadow:0 1px 2px rgba(0,0,0,.12);font-size:13px;line-height:1.45}.bubble.out{background:#d9fdd3;margin-left:28px}.meta{font-size:11px;color:#7b8794;margin-top:6px;text-align:right}
.admin-select{margin:0 0 18px}.notice{padding:16px;background:#fff8e7;border:1px solid #f8d58a;border-radius:14px;margin-bottom:18px;color:#7a4b00}.footer{margin-top:22px;color:var(--muted);font-size:12px;display:flex;gap:18px;flex-wrap:wrap}.footer a{color:var(--muted)}
@media(max-width:980px){.shell{grid-template-columns:1fr}.sidebar{display:none}.main{padding:20px}.grid{grid-template-columns:1fr}.row{grid-template-columns:1fr}.modes{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
<aside class="sidebar">
    <div class="brand"><div class="logo">T</div><div><h1>TalIA Connect WA</h1><small>Centro de mensajería</small></div></div>
    <nav class="nav">
        <a class="active" href="index.php">Configuración</a>
        <a href="conversaciones.php">Conversaciones</a>
        <a href="management.php">Administración WA</a>
        <a href="privacidad.php" target="_blank" rel="noopener">Privacidad</a>
    </nav>
    <div class="sidebar-foot">Región SUR<br>Plataforma TalIA</div>
</aside>

<main class="main">
    <div class="topbar">
        <div><h2>Configura tu atención por WhatsApp</h2><p>Define cómo debe responder TalIA y cuándo debe transferir la conversación a una persona.</p></div>
        <div class="user-chip"><?= h($usuario) ?><br><strong><?= h($rol) ?></strong></div>
    </div>

    <?php if ($esAdmin && !empty($numerosDisponibles)): ?>
    <form method="get" class="card admin-select">
        <div class="field" style="margin:0">
            <label for="id_numero">Número administrado</label>
            <select id="id_numero" name="id_numero" onchange="this.form.submit()">
                <?php foreach ($numerosDisponibles as $n): ?>
                <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $idNumeroSeleccionado ? 'selected' : '' ?>>
                    <?= h(($n['nombre_vendedor'] ?: 'Sin nombre').' · '.($n['display_phone_number'] ?: $n['telefono']).' · '.$n['estado']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($mensajeUi !== ''): ?><div class="alert <?= h($tipoMensajeUi) ?>"><?= h($mensajeUi) ?></div><?php endif; ?>
    <?php if (!$numero): ?><div class="notice">No encontramos un número de WhatsApp vinculado a tu usuario. Cuando quede registrado en Meta, asígnalo en <strong>wa_numeros</strong> con número de talento o id de posición.</div><?php endif; ?>

    <div class="grid">
    <form method="post" class="card">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id_numero" value="<?= (int)$idNumeroSeleccionado ?>">
        <input type="hidden" name="guardar_configuracion" value="1">

        <h3>Canal conectado</h3><div class="sub">Configuración operativa del número asociado.</div>
        <div class="row">
            <div class="status"><span class="dot <?= $numero && $estadoNumero === 'ACTIVO' ? 'ok' : 'warn' ?>"></span><div><strong><?= h($displayNumero) ?></strong><br><span class="badge"><?= h($estadoNumero) ?></span></div></div>
            <div><small style="color:var(--muted)">Vendedor / propietario</small><br><strong><?= h($numero['nombre_vendedor'] ?? 'Pendiente de asignar') ?></strong><br><small><?= h($numero['distrito'] ?? '') ?></small></div>
        </div>

        <div class="toggle"><div><strong>Asistente automático</strong><div class="sub" style="margin:3px 0 0">Si se desactiva, la conversación inicia en modo HUMANO.</div></div><label class="switch"><input type="checkbox" name="bot_activo" value="1" <?= $botActivoActual ? 'checked' : '' ?> <?= !$numero ? 'disabled' : '' ?>><span class="slider"></span></label></div>

        <div class="field" style="margin-top:18px">
            <label>Modo de respuesta</label>
            <div class="modes">
            <?php foreach (['INMEDIATO'=>'Inmediato','ESPERA'=>'Espera','MANUAL'=>'Manual'] as $valor=>$etiqueta): ?>
                <label class="mode"><input type="radio" name="modo_respuesta" value="<?= h($valor) ?>" <?= $modoActual === $valor ? 'checked' : '' ?> <?= !$numero ? 'disabled' : '' ?>><?= h($etiqueta) ?></label>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="row">
            <div class="field"><label for="espera_segundos">Espera antes de responder (segundos)</label><input type="number" id="espera_segundos" name="espera_segundos" min="0" max="3600" value="<?= (int)$esperaActual ?>" <?= !$numero ? 'disabled' : '' ?>></div>
            <div class="field"><label>Zona horaria</label><input type="text" value="America/Merida" disabled></div>
        </div>

        <div class="row">
            <div class="field"><label for="horario_inicio">Inicio de atención</label><input type="time" id="horario_inicio" name="horario_inicio" value="<?= h($inicioActual) ?>" <?= !$numero ? 'disabled' : '' ?>></div>
            <div class="field"><label for="horario_fin">Fin de atención</label><input type="time" id="horario_fin" name="horario_fin" value="<?= h($finActual) ?>" <?= !$numero ? 'disabled' : '' ?>></div>
        </div>

        <div class="toggle"><div><strong>Mensaje de bienvenida</strong><div class="sub" style="margin:3px 0 0">Texto inicial de la conversación.</div></div><label class="switch"><input type="checkbox" name="bienvenida_activa" value="1" <?= (int)$config['bienvenida_activa'] === 1 ? 'checked' : '' ?> <?= !$numero ? 'disabled' : '' ?>><span class="slider"></span></label></div>
        <div class="field"><textarea id="mensaje_bienvenida" name="mensaje_bienvenida" maxlength="2000" <?= !$numero ? 'disabled' : '' ?>><?= h($config['mensaje_bienvenida']) ?></textarea></div>

        <div class="toggle"><div><strong>Mensaje fuera de horario</strong><div class="sub" style="margin:3px 0 0">Respuesta cuando el mensaje llega fuera de la ventana configurada.</div></div><label class="switch"><input type="checkbox" name="fuera_horario_activo" value="1" <?= (int)$config['fuera_horario_activo'] === 1 ? 'checked' : '' ?> <?= !$numero ? 'disabled' : '' ?>><span class="slider"></span></label></div>
        <div class="field"><textarea id="mensaje_fuera_horario" name="mensaje_fuera_horario" maxlength="2000" <?= !$numero ? 'disabled' : '' ?>><?= h($config['mensaje_fuera_horario']) ?></textarea></div>

        <button class="btn btn-primary" type="submit" <?= !$numero ? 'disabled' : '' ?>>Guardar configuración</button>
    </form>

    <section class="card">
        <h3>Vista previa</h3><div class="sub">Así se verá el mensaje configurado.</div>
        <div class="preview">
            <div class="phone-head">TalIA Connect WA<br><small style="font-weight:400;opacity:.85"><?= h($displayNumero) ?></small></div>
            <div class="bubble">Hola, quiero información.<div class="meta">12:01</div></div>
            <div class="bubble out" id="previewBienvenida"><?= nl2br(h($config['mensaje_bienvenida'])) ?><div class="meta">12:01 ✓✓</div></div>
        </div>
        <div style="margin-top:18px"><span class="badge"><?= $botActivoActual ? 'BOT ACTIVO' : 'ATENCIÓN HUMANA' ?></span></div>
    </section>
    </div>

    <div class="footer"><span>TalIA Connect WA · Región SUR</span><a href="privacidad.php" target="_blank" rel="noopener">Política de privacidad</a></div>
</main>
</div>

<script>
const bienvenida=document.getElementById('mensaje_bienvenida');
const preview=document.getElementById('previewBienvenida');
if(bienvenida&&preview){
    bienvenida.addEventListener('input',()=>{
        const safe=bienvenida.value.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;").replace(/\n/g,'<br>');
        preview.innerHTML=safe+'<div class="meta">12:01 ✓✓</div>';
    });
}
</script>
</body>
</html>
