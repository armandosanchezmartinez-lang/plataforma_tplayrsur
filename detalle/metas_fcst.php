<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

/*
    METAS-FCST
    Primer apartado: Captura semanal de Forecast + Compromiso cualitativo.

    Reglas:
    - SEMANAL activo por default.
    - MENSUAL deshabilitado por ahora.
    - Si estatus = CERRADO, ya no se permite modificar.
    - El responsable se identifica por id_posicion de sesión y se enriquece desde HC.
    - Se respeta línea de reporte con posicion_lr.
*/

$conexion_path_1 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';
$conexion_path_2 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/conexion.php';

if (file_exists($conexion_path_1)) {
    include $conexion_path_1;
} elseif (file_exists($conexion_path_2)) {
    include $conexion_path_2;
} else {
    die("No se encontró archivo de conexión.");
}

$usuario = $_SESSION['usuario'] ?? 'sistema';
$rol = $_SESSION['rol'] ?? 'vendedor';
$id_posicion_sesion = $_SESSION['id_posicion'] ?? '';
$numero_talento_sesion = $_SESSION['numero_talento_gs'] ?? '';

$mensaje = "";
$tipo_mensaje = "";

function h($txt) {
    return htmlspecialchars((string)$txt, ENT_QUOTES, 'UTF-8');
}

function normalizar_texto($txt) {
    $txt = trim((string)$txt);
    $txt = mb_strtoupper($txt, 'UTF-8');
    $buscar  = ['Á','É','Í','Ó','Ú','Ü'];
    $cambiar = ['A','E','I','O','U','U'];
    $txt = str_replace($buscar, $cambiar, $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    return $txt;
}

function nivel_forecast_desde_posicion($posicion) {
    $p = normalizar_texto($posicion);

    if ($p === 'DIRECTOR DISTRITAL') {
        return 'DIRECTOR_DISTRITAL';
    }

    if ($p === 'LIDER VENTAS' || $p === 'LIDER PROMOVENDEDOR/PROMOTOR') {
        return 'LIDER_VENTAS';
    }

    if (
        $p === 'COACH VENTAS' ||
        $p === 'COACH DE VENTAS' ||
        $p === 'COACH PROMOVENDEDOR PUNTO DE VENTA' ||
        $p === 'COACH PROMOTOR PDV'
    ) {
        return 'COACH_VENTAS';
    }

    return 'OTRO';
}

function semana_anterior_calc($semana, $anio) {
    $sem_ant = (int)$semana - 1;
    $anio_ant = (int)$anio;

    if ($sem_ant <= 0) {
        $sem_ant = 52;
        $anio_ant--;
    }

    return [$sem_ant, $anio_ant];
}

$anio_actual = (int)date('Y');
$semana_actual = (int)date('W');

list($semana_anterior, $anio_semana_anterior) = semana_anterior_calc($semana_actual, $anio_actual);

$responsable = null;

if ($id_posicion_sesion !== '') {
    $sql_resp = "SELECT
                    id_posicion,
                    posicion_lr,
                    numero_talento_gs,
                    nombre_colaborador,
                    posicion AS puesto_responsable,
                    distrito
                 FROM hc
                 WHERE id_posicion = ?
                   AND anio = ?
                   AND semana = ?
                   AND nombre_colaborador NOT LIKE '%VACANTE%'
                 LIMIT 1";

    $stmt_resp = mysqli_prepare($conexion, $sql_resp);
    mysqli_stmt_bind_param($stmt_resp, "sii", $id_posicion_sesion, $anio_actual, $semana_actual);
    mysqli_stmt_execute($stmt_resp);
    $res = mysqli_stmt_get_result($stmt_resp);
    $responsable = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt_resp);

    if (!$responsable) {
        $sql_resp_fb = "SELECT
                            id_posicion,
                            posicion_lr,
                            numero_talento_gs,
                            nombre_colaborador,
                            posicion AS puesto_responsable,
                            distrito
                        FROM hc
                        WHERE id_posicion = ?
                          AND nombre_colaborador NOT LIKE '%VACANTE%'
                        ORDER BY anio DESC, semana DESC
                        LIMIT 1";

        $stmt_fb = mysqli_prepare($conexion, $sql_resp_fb);
        mysqli_stmt_bind_param($stmt_fb, "s", $id_posicion_sesion);
        mysqli_stmt_execute($stmt_fb);
        $res_fb = mysqli_stmt_get_result($stmt_fb);
        $responsable = mysqli_fetch_assoc($res_fb);
        mysqli_stmt_close($stmt_fb);
    }
}

if (!$responsable && $numero_talento_sesion !== '') {
    $sql_resp_tal = "SELECT
                        id_posicion,
                        posicion_lr,
                        numero_talento_gs,
                        nombre_colaborador,
                        posicion AS puesto_responsable,
                        distrito
                    FROM hc
                    WHERE numero_talento_gs = ?
                      AND nombre_colaborador NOT LIKE '%VACANTE%'
                    ORDER BY anio DESC, semana DESC
                    LIMIT 1";

    $stmt_tal = mysqli_prepare($conexion, $sql_resp_tal);
    mysqli_stmt_bind_param($stmt_tal, "s", $numero_talento_sesion);
    mysqli_stmt_execute($stmt_tal);
    $res_tal = mysqli_stmt_get_result($stmt_tal);
    $responsable = mysqli_fetch_assoc($res_tal);
    mysqli_stmt_close($stmt_tal);
}

if (!$responsable) {
    $responsable = [
        'id_posicion' => $id_posicion_sesion ?: 'SIN_POSICION',
        'posicion_lr' => null,
        'numero_talento_gs' => $numero_talento_sesion,
        'nombre_colaborador' => $usuario,
        'puesto_responsable' => strtoupper($rol),
        'distrito' => ''
    ];
}

$id_posicion = (string)$responsable['id_posicion'];
$posicion_lr = $responsable['posicion_lr'];
$numero_talento_gs = $responsable['numero_talento_gs'];
$nombre_responsable = $responsable['nombre_colaborador'];
$puesto_responsable = $responsable['puesto_responsable'];
$distrito = $responsable['distrito'];
$nivel_forecast = nivel_forecast_desde_posicion($puesto_responsable);

function cargar_forecast_actual($conexion, $anio, $semana, $id_posicion) {
    $sql = "SELECT * FROM metas_forecast_semanal WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
}

function cargar_compromiso_actual($conexion, $anio, $semana, $id_posicion) {
    $sql = "SELECT * FROM metas_fcst_compromiso_semanal WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
}

$forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$esta_cerrado = ($compromiso_row && $compromiso_row['estatus'] === 'CERRADO');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';

    $forecast = isset($_POST['forecast']) ? (int)$_POST['forecast'] : 0;
    $impulso_semana_anterior = trim($_POST['impulso_semana_anterior'] ?? '');
    $resto_semana_anterior = trim($_POST['resto_semana_anterior'] ?? '');
    $competencia = trim($_POST['competencia'] ?? '');
    $acciones_clave = trim($_POST['acciones_clave'] ?? '');
    $necesidades_apoyo = trim($_POST['necesidades_apoyo'] ?? '');

    $compromiso_existente = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);

    if ($compromiso_existente && $compromiso_existente['estatus'] === 'CERRADO') {
        $mensaje = "Este compromiso ya está CERRADO y no puede modificarse.";
        $tipo_mensaje = "error";
    } else {
        mysqli_begin_transaction($conexion);

        try {
            $sql_fcst = "INSERT INTO metas_forecast_semanal (
                            anio, semana, id_posicion, posicion_lr, numero_talento_gs,
                            nombre_responsable, puesto_responsable, distrito, nivel_forecast,
                            forecast, observacion, archivo_origen, usuario_carga
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            posicion_lr = VALUES(posicion_lr),
                            numero_talento_gs = VALUES(numero_talento_gs),
                            nombre_responsable = VALUES(nombre_responsable),
                            puesto_responsable = VALUES(puesto_responsable),
                            distrito = VALUES(distrito),
                            nivel_forecast = VALUES(nivel_forecast),
                            forecast = VALUES(forecast),
                            observacion = VALUES(observacion),
                            usuario_carga = VALUES(usuario_carga),
                            fecha_carga = CURRENT_TIMESTAMP";

            $observacion_fcst = "Captura METAS-FCST";
            $archivo_origen = "captura_web";

            $stmt_fcst = mysqli_prepare($conexion, $sql_fcst);
            mysqli_stmt_bind_param(
                $stmt_fcst,
                "iisssssssisss",
                $anio_actual,
                $semana_actual,
                $id_posicion,
                $posicion_lr,
                $numero_talento_gs,
                $nombre_responsable,
                $puesto_responsable,
                $distrito,
                $nivel_forecast,
                $forecast,
                $observacion_fcst,
                $archivo_origen,
                $usuario
            );

            if (!mysqli_stmt_execute($stmt_fcst)) {
                throw new Exception(mysqli_error($conexion));
            }
            mysqli_stmt_close($stmt_fcst);

            $forecast_row_nuevo = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
            $forecast_id = $forecast_row_nuevo ? (int)$forecast_row_nuevo['id'] : null;

            $estatus = ($accion === 'cerrar') ? 'CERRADO' : 'BORRADOR';
            $cerrado_en = ($accion === 'cerrar') ? date('Y-m-d H:i:s') : null;
            $cerrado_por = ($accion === 'cerrar') ? $usuario : null;

            $sql_comp = "INSERT INTO metas_fcst_compromiso_semanal (
                            forecast_id, anio, semana, id_posicion,
                            impulso_semana_anterior, resto_semana_anterior, competencia,
                            acciones_clave, necesidades_apoyo, estatus,
                            cerrado_en, cerrado_por, usuario_captura
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            forecast_id = VALUES(forecast_id),
                            impulso_semana_anterior = VALUES(impulso_semana_anterior),
                            resto_semana_anterior = VALUES(resto_semana_anterior),
                            competencia = VALUES(competencia),
                            acciones_clave = VALUES(acciones_clave),
                            necesidades_apoyo = VALUES(necesidades_apoyo),
                            estatus = VALUES(estatus),
                            cerrado_en = VALUES(cerrado_en),
                            cerrado_por = VALUES(cerrado_por),
                            usuario_captura = VALUES(usuario_captura)";

            $stmt_comp = mysqli_prepare($conexion, $sql_comp);
            mysqli_stmt_bind_param(
                $stmt_comp,
                "iiissssssssss",
                $forecast_id,
                $anio_actual,
                $semana_actual,
                $id_posicion,
                $impulso_semana_anterior,
                $resto_semana_anterior,
                $competencia,
                $acciones_clave,
                $necesidades_apoyo,
                $estatus,
                $cerrado_en,
                $cerrado_por,
                $usuario
            );

            if (!mysqli_stmt_execute($stmt_comp)) {
                throw new Exception(mysqli_error($conexion));
            }
            mysqli_stmt_close($stmt_comp);

            mysqli_commit($conexion);

            $mensaje = ($accion === 'cerrar')
                ? "✅ Compromiso cerrado correctamente. Ya no podrá modificarse."
                : "✅ Borrador guardado correctamente.";
            $tipo_mensaje = "exito";

        } catch (Exception $e) {
            mysqli_rollback($conexion);
            $mensaje = "Error al guardar: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }

    $forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
    $compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
    $esta_cerrado = ($compromiso_row && $compromiso_row['estatus'] === 'CERRADO');
}

$forecast_valor = $forecast_row ? (int)$forecast_row['forecast'] : 0;
$impulso = $compromiso_row['impulso_semana_anterior'] ?? '';
$resto = $compromiso_row['resto_semana_anterior'] ?? '';
$competencia_txt = $compromiso_row['competencia'] ?? '';
$acciones = $compromiso_row['acciones_clave'] ?? '';
$necesidades = $compromiso_row['necesidades_apoyo'] ?? '';
$estatus = $compromiso_row['estatus'] ?? 'BORRADOR';
$disabled = $esta_cerrado ? 'disabled' : '';
$readonly_class = $esta_cerrado ? 'readonly' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>METAS-FCST — TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=162">
    <style>
        :root {
            --tx-purple: #7A2BFF;
            --tx-pink: #FF00B8;
            --tx-bg: #f4f7fb;
            --tx-card: rgba(255,255,255,.88);
            --tx-border: rgba(226,232,240,.95);
            --tx-text: #1a2540;
            --tx-muted: #6b7a99;
            --sidebar: 200px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            background: radial-gradient(circle at 8% 8%, rgba(255,10,200,.10), transparent 28%), radial-gradient(circle at 92% 14%, rgba(0,216,255,.09), transparent 30%), linear-gradient(180deg,#f7f8ff 0%,#eef5ff 100%);
            color: var(--tx-text);
            min-height: 100vh;
            display: flex;
        }
        .sidebar {
            width: var(--sidebar);
            background: linear-gradient(180deg, #1b2d5a 0%, #102046 100%);
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 24px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .sidebar-logo { width: 74px; height: 74px; border-radius: 22px; background: rgba(255,255,255,.10); display: flex; align-items: center; justify-content: center; margin-bottom: 8px; font-size: 2rem; }
        .sidebar-brand { color: white; font-weight: 800; letter-spacing: .8px; font-size: .78rem; margin-bottom: 28px; }
        .nav-item { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 14px 0; color: rgba(255,255,255,.70); text-decoration: none; font-weight: 700; font-size: .78rem; transition: .18s ease; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,.11); color: white; }
        .nav-icon { font-size: 1.25rem; }
        .main { margin-left: var(--sidebar); width: calc(100% - var(--sidebar)); padding: 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 22px; }
        .page-title h1 { margin: 0; font-size: 1.65rem; letter-spacing: -.4px; }
        .page-title p { margin: 6px 0 0; color: var(--tx-muted); font-size: .9rem; }
        .pill-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .pill { border: 1px solid var(--tx-border); background: var(--tx-card); padding: 8px 12px; border-radius: 999px; font-size: .78rem; font-weight: 800; color: var(--tx-muted); }
        .pill.active { color: white; background: linear-gradient(135deg, var(--tx-purple) 0%, var(--tx-pink) 100%); border: none; }
        .pill.disabled { opacity: .45; cursor: not-allowed; }
        .status-card { background: var(--tx-card); border: 1px solid var(--tx-border); border-radius: 22px; padding: 18px 20px; min-width: 280px; box-shadow: 0 14px 32px rgba(22,28,60,.08); }
        .status-label { font-size: .72rem; text-transform: uppercase; color: var(--tx-muted); font-weight: 900; letter-spacing: .7px; }
        .status-main { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 8px; }
        .badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 7px 12px; font-size: .78rem; font-weight: 900; }
        .badge.borrador { color: #92400e; background: #fef3c7; }
        .badge.cerrado { color: #166534; background: #dcfce7; }
        .grid { display: grid; grid-template-columns: 1.05fr 1fr; gap: 20px; }
        .card { background: var(--tx-card); border: 1px solid var(--tx-border); border-radius: 24px; padding: 22px; box-shadow: 0 14px 32px rgba(22,28,60,.08); }
        .card.full { grid-column: 1 / -1; }
        .card-title { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 16px; }
        .card-title h2 { margin: 0; font-size: 1.05rem; }
        .card-title span { color: var(--tx-muted); font-size: .78rem; font-weight: 800; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: .78rem; text-transform: uppercase; letter-spacing: .5px; font-weight: 900; color: var(--tx-muted); margin-bottom: 8px; }
        .field input, .field textarea { width: 100%; border: 1.5px solid #dbe4f0; border-radius: 16px; padding: 13px 14px; font-family: inherit; font-size: .92rem; outline: none; background: rgba(255,255,255,.82); color: var(--tx-text); transition: .15s ease; }
        .field textarea { min-height: 130px; resize: vertical; line-height: 1.45; }
        .field input:focus, .field textarea:focus { border-color: var(--tx-purple); box-shadow: 0 0 0 4px rgba(122,43,255,.10); background: white; }
        .field input:disabled, .field textarea:disabled { background: #f8fafc; color: #64748b; cursor: not-allowed; }
        .kpi { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
        .kpi-box { border-radius: 18px; padding: 16px; background: rgba(255,255,255,.70); border: 1px solid #e2e8f0; }
        .kpi-box .label { color: var(--tx-muted); font-weight: 900; font-size: .72rem; text-transform: uppercase; letter-spacing: .6px; }
        .kpi-box .value { margin-top: 8px; font-size: 1.55rem; font-weight: 900; color: var(--tx-purple); }
        .actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 18px; flex-wrap: wrap; }
        .btn { border: none; border-radius: 14px; padding: 12px 18px; font-weight: 900; cursor: pointer; font-size: .9rem; font-family: inherit; transition: .15s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-secondary { background: #e8eef7; color: #1a2540; }
        .btn-primary { color: white; background: linear-gradient(135deg, var(--tx-purple) 0%, var(--tx-pink) 100%); box-shadow: 0 12px 28px rgba(122,43,255,.20); }
        .btn-danger { color: white; background: linear-gradient(135deg, #f97316 0%, #dc2626 100%); box-shadow: 0 12px 28px rgba(220,38,38,.18); }
        .btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
        .alert { border-radius: 16px; padding: 14px 16px; margin-bottom: 18px; line-height: 1.45; font-weight: 700; }
        .alert.exito { background: #dcfce7; color: #166534; }
        .alert.error { background: #fee2e2; color: #991b1b; }
        .helper { font-size: .78rem; color: var(--tx-muted); line-height: 1.45; margin-top: 8px; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } .main { padding: 22px; } .page-header { flex-direction: column; } .status-card { width: 100%; } }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-logo">🎯</div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span>Dashboard</a>
    <a href="ranking_productividad.php?anio=<?= h($anio_actual) ?>&semana=<?= h($semana_actual) ?>" class="nav-item"><span class="nav-icon">🏆</span>Ranking</a>
    <a href="hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span>Headcount</a>
    <a href="reai.php" class="nav-item"><span class="nav-icon">📋</span>REAI</a>
    <a href="metas_fcst.php" class="nav-item active"><span class="nav-icon">🎯</span>METAS-FCST</a>
</aside>

<main class="main">
    <div class="page-header">
        <div class="page-title">
            <h1>🎯 METAS-FCST</h1>
            <p>Captura semanal de forecast, hallazgos y compromisos de ejecución.</p>
            <div class="pill-row">
                <span class="pill active">SEMANAL</span>
                <span class="pill disabled">MENSUAL · Próximamente</span>
            </div>
        </div>
        <div class="status-card">
            <div class="status-label">Semana actual</div>
            <div class="status-main">
                <div>
                    <strong>SEM <?= h($semana_actual) ?> · <?= h($anio_actual) ?></strong><br>
                    <span style="font-size:.78rem;color:var(--tx-muted);font-weight:800;">Semana anterior: SEM <?= h($semana_anterior) ?></span>
                </div>
                <span class="badge <?= strtolower($estatus) ?>"><?= $estatus === 'CERRADO' ? '🔒 CERRADO' : '✏️ BORRADOR' ?></span>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert <?= h($tipo_mensaje) ?>"><?= h($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="grid">
            <div class="card">
                <div class="card-title"><h2>Responsable</h2><span>Línea de reporte</span></div>
                <div class="kpi">
                    <div class="kpi-box"><div class="label">Responsable</div><div class="value" style="font-size:1rem;line-height:1.25;"><?= h($nombre_responsable) ?></div></div>
                    <div class="kpi-box"><div class="label">Distrito</div><div class="value"><?= h($distrito ?: 'N/D') ?></div></div>
                </div>
                <div class="kpi">
                    <div class="kpi-box"><div class="label">Puesto</div><div class="value" style="font-size:1rem;line-height:1.25;"><?= h($puesto_responsable) ?></div></div>
                    <div class="kpi-box"><div class="label">Nivel Forecast</div><div class="value" style="font-size:1rem;line-height:1.25;"><?= h($nivel_forecast) ?></div></div>
                </div>
                <div class="field">
                    <label>Forecast compromiso de la semana actual</label>
                    <input type="number" name="forecast" min="0" step="1" value="<?= h($forecast_valor) ?>" <?= $disabled ?>>
                    <div class="helper">Este número se guarda en <strong>metas_forecast_semanal</strong>.</div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><h2>Semana anterior</h2><span>Hallazgos</span></div>
                <div class="field"><label>Lo que me impulsó en la semana anterior</label><textarea name="impulso_semana_anterior" <?= $disabled ?>><?= h($impulso) ?></textarea></div>
                <div class="field"><label>Lo que me restó en la semana anterior</label><textarea name="resto_semana_anterior" <?= $disabled ?>><?= h($resto) ?></textarea></div>
            </div>

            <div class="card">
                <div class="card-title"><h2>Competencia</h2><span>Información clave</span></div>
                <div class="field"><label>Información clave de la competencia</label><textarea name="competencia" <?= $disabled ?>><?= h($competencia_txt) ?></textarea></div>
            </div>

            <div class="card">
                <div class="card-title"><h2>Semana actual</h2><span>Ejecución</span></div>
                <div class="field"><label>Acciones clave a ejecutar</label><textarea name="acciones_clave" <?= $disabled ?>><?= h($acciones) ?></textarea></div>
                <div class="field"><label>Necesidades y apoyos requeridos</label><textarea name="necesidades_apoyo" <?= $disabled ?>><?= h($necesidades) ?></textarea></div>
            </div>

            <div class="card full">
                <div class="card-title"><h2>Control de compromiso</h2><span><?= $esta_cerrado ? 'Solo lectura' : 'Editable hasta cerrar' ?></span></div>
                <?php if ($esta_cerrado): ?>
                    <div class="helper">Este compromiso fue cerrado por <strong><?= h($compromiso_row['cerrado_por'] ?? '') ?></strong> el <strong><?= h($compromiso_row['cerrado_en'] ?? '') ?></strong>. Ya no puede modificarse.</div>
                <?php else: ?>
                    <div class="helper">Puedes guardar como borrador y regresar a editar. Al seleccionar <strong>Cerrar compromiso</strong>, la información quedará bloqueada.</div>
                <?php endif; ?>
                <div class="actions">
                    <a href="../index.php" class="btn btn-secondary" style="text-decoration:none;">Volver</a>
                    <button type="submit" name="accion" value="guardar" class="btn btn-primary" <?= $disabled ?>>Guardar borrador</button>
                    <button type="submit" name="accion" value="cerrar" class="btn btn-danger" <?= $disabled ?> onclick="return confirm('¿Confirmas cerrar el compromiso? Una vez cerrado ya no podrá modificarse.');">Cerrar compromiso</button>
                </div>
            </div>
        </div>
    </form>
</main>
</body>
</html>
