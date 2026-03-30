<?php
ini_set('display_errors', 0);
error_reporting(0);
header("Cache-Control: no-cache, no-store, must-revalidate");
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

include '../conexion.php';

$rol              = $_SESSION['rol'] ?? 'vendedor';
$id_posicion      = $_SESSION['id_posicion'] ?? '';
$talento_gs_coach = $_SESSION['numero_talento_gs'] ?? '';
$puestos_comerciales = ['PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'];
$puestos_in = "'" . implode("','", $puestos_comerciales) . "'";

// Semana y año más recientes
$semana_actual = null; $anio_actual = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_actual = (int)$row_sem['semana'];
    $anio_actual   = (int)$row_sem['anio'];
}

$mes_actual = (int)date('n');
$anio_query = (int)date('Y');

// Día vencido: ayer
$fecha_dia_actual  = date('Y-m-d', strtotime('-1 day'));
// Misma semana anterior: mismo día de la semana pasada
$fecha_dia_anterior = date('Y-m-d', strtotime('-8 days'));

$roles_labels = [
    'admin'              => 'Administrador',
    'director_regional'  => 'Director Regional',
    'director_distrital' => 'Director Distrital',
    'lider'              => 'Líder',
    'coach'              => 'Coach',
    'vendedor'           => 'Vendedor',
];

$puede_capturar = ($rol === 'coach');

// ── HISTORIAL AJAX ────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'historial') {
    $talento = mysqli_real_escape_string($conexion, $_GET['talento_gs'] ?? '');
    $res = mysqli_query($conexion, "SELECT * FROM reai WHERE numero_talento_gs = '$talento' ORDER BY fecha DESC, created_at DESC");
    $registros = [];
    while ($row = mysqli_fetch_assoc($res)) $registros[] = $row;
    header('Content-Type: application/json');
    echo json_encode($registros);
    exit();
}

// ── GUARDAR REAI ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puede_capturar && isset($_POST['action']) && $_POST['action'] === 'guardar') {
    $talento_vendedor = mysqli_real_escape_string($conexion, $_POST['numero_talento_gs'] ?? '');
    $nombre_vendedor  = mysqli_real_escape_string($conexion, $_POST['nombre_colaborador'] ?? '');
    $asunto           = $_POST['asunto'] ?? '';
    $fecha            = $_POST['fecha'] ?? '';
    $descripcion      = mysqli_real_escape_string($conexion, $_POST['descripcion'] ?? '');
    $evidencia_nombre = '';
    $asuntos_validos  = ['Retroalimentación','ECNUs','Acta Administrativa','Incidencia'];

    if (!in_array($asunto, $asuntos_validos)) {
        echo json_encode(['status'=>'error','msg'=>'Asunto no válido']); exit();
    }
    if (!empty($_FILES['evidencia']['name'])) {
        $ext     = pathinfo($_FILES['evidencia']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg','jpeg','png','pdf','doc','docx'];
        if (in_array(strtolower($ext), $allowed)) {
            $upload_dir = '../uploads/reai/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $nombre_archivo = time() . '_' . preg_replace('/[^a-zA-Z0-9._]/', '_', $_FILES['evidencia']['name']);
            if (move_uploaded_file($_FILES['evidencia']['tmp_name'], $upload_dir . $nombre_archivo)) {
                $evidencia_nombre = $nombre_archivo;
            }
        } else {
            echo json_encode(['status'=>'error','msg'=>'Formato no permitido']); exit();
        }
    }
    $asunto_esc = mysqli_real_escape_string($conexion, $asunto);
    $sql = "INSERT INTO reai (numero_talento_gs, nombre_colaborador, asunto, fecha, descripcion, evidencia, capturado_por, talento_gs_coach, id_posicion_coach)
            VALUES ('$talento_vendedor','$nombre_vendedor','$asunto_esc','$fecha','$descripcion','$evidencia_nombre','$talento_gs_coach','$talento_gs_coach','$id_posicion')";
    if (mysqli_query($conexion, $sql)) {
        echo json_encode(['status'=>'ok','msg'=>'Registro guardado correctamente']);
    } else {
        echo json_encode(['status'=>'error','msg'=>'Error: '.mysqli_error($conexion)]);
    }
    exit();
}

// ── OBTENER VENDEDORES SEGÚN JERARQUÍA ────────────────────────────────────────
$vendedores = [];
if ($semana_actual && $anio_actual) {
    if ($rol === 'coach') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE v.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_actual, $anio_actual);
    } elseif ($rol === 'lider') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE c.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_actual, $anio_actual);
    } elseif ($rol === 'director_distrital') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     INNER JOIN hc l ON c.posicion_lr = l.id_posicion AND l.semana = v.semana AND l.anio = v.anio
                     WHERE l.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY l.nombre_colaborador, c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_actual, $anio_actual);
    } else {
        // admin / director_regional
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "ii", $semana_actual, $anio_actual);
    }
    mysqli_stmt_execute($stmt);
    $res_vend = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res_vend)) $vendedores[] = $row;
    mysqli_stmt_close($stmt);
}

// ── VENTAS E INSTALACIONES POR VENDEDOR ──────────────────────────────────────
$stats = [];
if (!empty($vendedores)) {
    $talentos = array_column($vendedores, 'numero_talento_gs');
    $ph = implode(',', array_fill(0, count($talentos), '?'));
    $tipos = str_repeat('s', count($talentos));

    // Ventas del mes
    $stmt_vm = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_vm, 'ii'.$tipos, $mes_actual, $anio_query, ...array_values($talentos));
    mysqli_stmt_execute($stmt_vm);
    $res_vm = mysqli_stmt_get_result($stmt_vm);
    while ($r = mysqli_fetch_assoc($res_vm)) $stats[$r['folio_empleado']]['ventas_mes'] = $r['total'];
    mysqli_stmt_close($stmt_vm);

    // Ventas día vencido (ayer)
    $stmt_vd = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(*) as total FROM ventas WHERE DATE(fecha_cierre)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_vd, 's'.$tipos, $fecha_dia_actual, ...array_values($talentos));
    mysqli_stmt_execute($stmt_vd);
    $res_vd = mysqli_stmt_get_result($stmt_vd);
    while ($r = mysqli_fetch_assoc($res_vd)) $stats[$r['folio_empleado']]['ventas_dia'] = $r['total'];
    mysqli_stmt_close($stmt_vd);

    // Ventas semana anterior (mismo día -7)
    $stmt_vs = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(*) as total FROM ventas WHERE DATE(fecha_cierre)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_vs, 's'.$tipos, $fecha_dia_anterior, ...array_values($talentos));
    mysqli_stmt_execute($stmt_vs);
    $res_vs = mysqli_stmt_get_result($stmt_vs);
    while ($r = mysqli_fetch_assoc($res_vs)) $stats[$r['folio_empleado']]['ventas_sem_ant'] = $r['total'];
    mysqli_stmt_close($stmt_vs);

    // Instalaciones del mes
    $stmt_im = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_im, 'ii'.$tipos, $mes_actual, $anio_query, ...array_values($talentos));
    mysqli_stmt_execute($stmt_im);
    $res_im = mysqli_stmt_get_result($stmt_im);
    while ($r = mysqli_fetch_assoc($res_im)) $stats[$r['folio_empleado']]['inst_mes'] = $r['total'];
    mysqli_stmt_close($stmt_im);

    // Instalaciones día vencido
    $stmt_id = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) as total FROM instalaciones WHERE DATE(fecha)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_id, 's'.$tipos, $fecha_dia_actual, ...array_values($talentos));
    mysqli_stmt_execute($stmt_id);
    $res_id = mysqli_stmt_get_result($stmt_id);
    while ($r = mysqli_fetch_assoc($res_id)) $stats[$r['folio_empleado']]['inst_dia'] = $r['total'];
    mysqli_stmt_close($stmt_id);

    // Instalaciones semana anterior
    $stmt_is = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) as total FROM instalaciones WHERE DATE(fecha)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_is, 's'.$tipos, $fecha_dia_anterior, ...array_values($talentos));
    mysqli_stmt_execute($stmt_is);
    $res_is = mysqli_stmt_get_result($stmt_is);
    while ($r = mysqli_fetch_assoc($res_is)) $stats[$r['folio_empleado']]['inst_sem_ant'] = $r['total'];
    mysqli_stmt_close($stmt_is);

    // REAI counts
    $stmt_rc = mysqli_prepare($conexion, "SELECT numero_talento_gs, asunto, COUNT(*) as total FROM reai WHERE numero_talento_gs IN ($ph) GROUP BY numero_talento_gs, asunto");
    mysqli_stmt_bind_param($stmt_rc, $tipos, ...array_values($talentos));
    mysqli_stmt_execute($stmt_rc);
    $res_rc = mysqli_stmt_get_result($stmt_rc);
    while ($r = mysqli_fetch_assoc($res_rc)) $stats[$r['numero_talento_gs']]['reai'][$r['asunto']] = $r['total'];
    mysqli_stmt_close($stmt_rc);
}

$label_dia_act = date('d/m', strtotime($fecha_dia_actual));
$label_dia_ant = date('d/m', strtotime($fecha_dia_anterior));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REAI v2 — TOTALXPEDIENT</title>
    <style>
        :root { --blue:#2b57a7; --bg:#f4f6fb; --white:#fff; --text:#1a2540; --text2:#6b7a99; --border:#e2e8f4; --green:#10b981; --red:#ef4444; --sidebar:200px; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Segoe UI',sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; }
        .sidebar { width:var(--sidebar); background:var(--blue); min-height:100vh; position:fixed; top:0; left:0; display:flex; flex-direction:column; align-items:center; padding:28px 0; z-index:100; }
        .sidebar-logo { color:white; font-size:2rem; margin-bottom:6px; }
        .sidebar-brand { color:rgba(255,255,255,0.9); font-size:0.72rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:32px; text-align:center; padding:0 12px; }
        .nav-item { width:100%; display:flex; flex-direction:column; align-items:center; gap:4px; padding:14px 0; color:rgba(255,255,255,0.65); text-decoration:none; font-size:0.78rem; font-weight:600; transition:all 0.2s; }
        .nav-item:hover,.nav-item.active { color:white; background:rgba(255,255,255,0.12); }
        .nav-icon { font-size:1.3rem; }
        .sidebar-bottom { margin-top:auto; width:100%; padding:0 12px; }
        .logout-btn { display:block; text-align:center; padding:10px; border-radius:8px; color:rgba(255,255,255,0.6); text-decoration:none; font-size:0.78rem; font-weight:600; }
        .logout-btn:hover { background:rgba(255,255,255,0.1); color:white; }
        .main { margin-left:var(--sidebar); flex:1; padding:32px; overflow-x:auto; }
        .page-header { margin-bottom:20px; }
        .page-header h2 { font-size:1.5rem; font-weight:700; }
        .page-header p { font-size:0.82rem; color:var(--text2); margin-top:2px; }
        .search-bar { margin-bottom:16px; }
        .search-input { width:100%; max-width:380px; padding:10px 16px 10px 40px; border:1px solid var(--border); border-radius:10px; font-size:0.9rem; background:var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236b7a99' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.156a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z'/%3E%3C/svg%3E") no-repeat 12px center; outline:none; }
        .search-input:focus { border-color:var(--blue); }
        .table-card { background:var(--white); border-radius:16px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.04); overflow:hidden; }
        table { width:100%; border-collapse:collapse; font-size:0.78rem; white-space:nowrap; }
        thead tr:first-child th { background:#1d4ed8; color:white; padding:10px 12px; font-weight:700; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; text-align:center; }
        thead tr:last-child th { background:var(--blue); color:white; padding:8px 12px; font-weight:700; font-size:0.7rem; text-transform:uppercase; text-align:center; }
        thead th.left { text-align:left; }
        tbody tr { border-bottom:1px solid var(--border); }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover td { background:#f8faff; }
        td { padding:10px 12px; vertical-align:middle; text-align:center; }
        td.left { text-align:left; }
        .sub-text { font-size:0.68rem; color:var(--text2); margin-top:2px; }
        .num-pos { color:var(--green); font-weight:700; }
        .num-neg { color:var(--red); font-weight:700; }
        .num-neu { color:var(--text2); font-weight:600; }
        .diff-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:0.7rem; font-weight:700; }
        .diff-pos { background:#d1fae5; color:#065f46; }
        .diff-neg { background:#fee2e2; color:#991b1b; }
        .diff-neu { background:#f4f6fb; color:#6b7a99; }
        .reai-badge { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:7px; font-size:0.7rem; font-weight:700; cursor:pointer; border:none; transition:all 0.15s; }
        .reai-badge.has-data { background:#e8f0fe; color:var(--blue); }
        .reai-badge.no-data  { background:#f4f6fb; color:#d1d5db; cursor:default; }
        .reai-badge.can-add  { background:#f0fdf4; color:#059669; }
        .reai-badge:hover:not(.no-data) { transform:scale(1.15); }
        .sep { border-left:2px solid var(--border); }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:white; border-radius:16px; padding:28px; width:100%; max-width:520px; box-shadow:0 20px 60px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto; }
        .modal-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; }
        .modal-title { font-size:1rem; font-weight:700; line-height:1.3; }
        .modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:var(--text2); flex-shrink:0; margin-left:12px; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:0.78rem; font-weight:700; color:var(--text2); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
        .form-group select,.form-group input,.form-group textarea { width:100%; padding:10px 14px; border:1px solid var(--border); border-radius:8px; font-size:0.9rem; font-family:inherit; outline:none; }
        .form-group textarea { resize:vertical; min-height:80px; }
        .btn-primary { width:100%; padding:12px; background:var(--blue); color:white; border:none; border-radius:8px; font-size:0.95rem; font-weight:700; cursor:pointer; }
        .btn-primary:disabled { background:#9ca3af; cursor:not-allowed; }
        .historial-item { border:1px solid var(--border); border-radius:10px; padding:14px; margin-bottom:10px; }
        .historial-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
        .historial-asunto { font-size:0.78rem; font-weight:700; padding:3px 10px; border-radius:20px; }
        .asunto-r { background:#dbeafe; color:#1d4ed8; }
        .asunto-e { background:#fef3c7; color:#92400e; }
        .asunto-a { background:#fee2e2; color:#991b1b; }
        .asunto-i { background:#f3e8ff; color:#6b21a8; }
        .historial-fecha { font-size:0.75rem; color:var(--text2); }
        .historial-desc { font-size:0.82rem; margin-top:6px; }
        .historial-evidencia a { font-size:0.78rem; color:var(--blue); text-decoration:none; font-weight:600; }
        .divider { border:none; border-top:1px solid var(--border); margin:20px 0; }
        .section-label { font-size:0.78rem; color:var(--text2); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:14px; font-weight:700; }
        .toast { position:fixed; bottom:24px; right:24px; padding:12px 20px; border-radius:10px; font-size:0.85rem; font-weight:600; z-index:9999; display:none; color:white; }
        .toast.show { display:block; }
        .toast.success { background:#065f46; }
        .toast.error   { background:#991b1b; }
        .hidden { display:none; }
        .empty-state { text-align:center; padding:48px; color:var(--text2); }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-logo">📊</div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="../import/import_instalaciones.php" class="nav-item"><span class="nav-icon">🔧</span> Instalaciones</a>
    <a href="../import/import_ventas.php" class="nav-item"><span class="nav-icon">📈</span> Ventas</a>
    <a href="hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="reai.php" class="nav-item"><span class="nav-icon">📋</span> REAI</a>
    <a href="reai_v2.php" class="nav-item active"><span class="nav-icon">📊</span> REAI v2</a>
    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <div class="page-header">
        <h2>Seguimiento de Equipo</h2>
        <p><?= date('d \d\e F Y') ?> · Comparativa: <?= $label_dia_act ?> vs <?= $label_dia_ant ?> ·
        <?php if ($puede_capturar): ?>
            <span style="color:#059669;font-weight:700;">✓ Captura habilitada</span>
        <?php else: ?>
            <span style="color:var(--text2);">Solo visualización</span>
        <?php endif; ?>
        </p>
    </div>

    <?php if (empty($vendedores)): ?>
        <div class="table-card"><div class="empty-state">No se encontraron colaboradores.</div></div>
    <?php else: ?>

    <div class="search-bar">
        <input type="text" class="search-input" id="buscador" placeholder="Buscar colaborador o coach..." oninput="filtrarTabla()">
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th colspan="3" class="left">Colaborador</th>
                    <th colspan="3">Ventas</th>
                    <th colspan="3">Instalaciones</th>
                    <th colspan="4" class="sep">REAI</th>
                </tr>
                <tr>
                    <th class="left">Nombre</th>
                    <th class="left">Coach</th>
                    <th>Antigüedad</th>
                    <th>Mes</th>
                    <th><?= $label_dia_act ?></th>
                    <th><?= $label_dia_ant ?> / Dif</th>
                    <th>Mes</th>
                    <th><?= $label_dia_act ?></th>
                    <th><?= $label_dia_ant ?> / Dif</th>
                    <th class="sep">R</th>
                    <th>E</th>
                    <th>A</th>
                    <th>I</th>
                </tr>
            </thead>
            <tbody id="tablaBody">
            <?php foreach ($vendedores as $vend):
                $tgs       = $vend['numero_talento_gs'];
                $nombre    = $vend['nombre_colaborador'];
                $antig     = $vend['antiguedad'] ?? 0;
                $coach_nom = $vend['nombre_coach'] ?? '—';
                $st        = $stats[$tgs] ?? [];

                $ventas_mes     = $st['ventas_mes'] ?? 0;
                $ventas_dia     = $st['ventas_dia'] ?? 0;
                $ventas_sem_ant = $st['ventas_sem_ant'] ?? 0;
                $ventas_diff    = $ventas_dia - $ventas_sem_ant;

                $inst_mes     = $st['inst_mes'] ?? 0;
                $inst_dia     = $st['inst_dia'] ?? 0;
                $inst_sem_ant = $st['inst_sem_ant'] ?? 0;
                $inst_diff    = $inst_dia - $inst_sem_ant;

                $reai  = $st['reai'] ?? [];
                $cnt_r = $reai['Retroalimentación'] ?? 0;
                $cnt_e = $reai['ECNUs'] ?? 0;
                $cnt_a = $reai['Acta Administrativa'] ?? 0;
                $cnt_i = $reai['Incidencia'] ?? 0;

                $vd_class = $ventas_diff > 0 ? 'diff-pos' : ($ventas_diff < 0 ? 'diff-neg' : 'diff-neu');
                $id_class = $inst_diff > 0 ? 'diff-pos' : ($inst_diff < 0 ? 'diff-neg' : 'diff-neu');
            ?>
            <tr data-nombre="<?= strtolower(htmlspecialchars($nombre)) ?>" data-coach="<?= strtolower(htmlspecialchars($coach_nom)) ?>">
                <td class="left">
                    <div style="font-weight:600;"><?= htmlspecialchars($nombre) ?></div>
                    <div class="sub-text"><?= $tgs ?></div>
                </td>
                <td class="left" style="font-size:0.78rem;"><?= htmlspecialchars($coach_nom) ?></td>
                <td><span style="font-weight:700;"><?= $antig ?></span> <span class="sub-text">m</span></td>

                <!-- VENTAS -->
                <td><span style="font-weight:700;"><?= $ventas_mes ?></span></td>
                <td><?= $ventas_dia ?></td>
                <td>
                    <span class="sub-text"><?= $ventas_sem_ant ?></span>
                    <span class="diff-badge <?= $vd_class ?>"><?= $ventas_diff >= 0 ? '+' : '' ?><?= $ventas_diff ?></span>
                </td>

                <!-- INSTALACIONES -->
                <td><span style="font-weight:700;"><?= $inst_mes ?></span></td>
                <td><?= $inst_dia ?></td>
                <td>
                    <span class="sub-text"><?= $inst_sem_ant ?></span>
                    <span class="diff-badge <?= $id_class ?>"><?= $inst_diff >= 0 ? '+' : '' ?><?= $inst_diff ?></span>
                </td>

                <?php
                $asuntos_map = [
                    'R' => ['Retroalimentación',   $cnt_r],
                    'E' => ['ECNUs',               $cnt_e],
                    'A' => ['Acta Administrativa', $cnt_a],
                    'I' => ['Incidencia',           $cnt_i],
                ];
                $first = true;
                foreach ($asuntos_map as $letra => [$asunto_val, $cnt]):
                    $tgs_js    = addslashes($tgs);
                    $nombre_js = addslashes($nombre);
                    $asunto_js = addslashes($asunto_val);
                    $sep_class = $first ? 'sep' : '';
                    $first = false;
                ?>
                <td class="<?= $sep_class ?>">
                    <?php if ($puede_capturar): ?>
                        <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'can-add' ?>"
                            onclick="abrirModal('<?= $tgs_js ?>','<?= $nombre_js ?>','<?= $asunto_js ?>')"
                            title="<?= $cnt > 0 ? "$asunto_val ($cnt)" : "Agregar $asunto_val" ?>">
                            <?= $cnt > 0 ? $cnt : '+' ?>
                        </button>
                    <?php else: ?>
                        <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'no-data' ?>"
                            <?= $cnt > 0 ? "onclick=\"abrirModal('$tgs_js','$nombre_js','$asunto_js')\"" : 'disabled' ?>>
                            <?= $cnt > 0 ? $cnt : '—' ?>
                        </button>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- MODAL -->
<div class="modal-overlay" id="modalOverlay" onclick="cerrarModal(event)">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle"></div>
            <button class="modal-close" onclick="cerrarModalBtn()">×</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
let currentTalento = '', currentNombre = '', currentAsunto = '';
const puedeCapturar = <?= $puede_capturar ? 'true' : 'false' ?>;
const asuntoColors = {
    'Retroalimentación':'asunto-r','ECNUs':'asunto-e',
    'Acta Administrativa':'asunto-a','Incidencia':'asunto-i'
};

function filtrarTabla() {
    const q = document.getElementById('buscador').value.toLowerCase();
    document.querySelectorAll('#tablaBody tr').forEach(tr => {
        const n = tr.dataset.nombre || '', c = tr.dataset.coach || '';
        tr.classList.toggle('hidden', q !== '' && !n.includes(q) && !c.includes(q));
    });
}

function abrirModal(talento, nombre, asunto) {
    currentTalento = talento; currentNombre = nombre; currentAsunto = asunto;
    document.getElementById('modalTitle').textContent = nombre + ' — ' + asunto;
    document.getElementById('modalOverlay').classList.add('open');
    document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text2);">Cargando...</div>';

    fetch('reai_v2.php?action=historial&talento_gs=' + encodeURIComponent(talento))
        .then(r => r.json())
        .then(data => {
            const filtrados = data.filter(r => r.asunto === asunto);
            let html = '';
            if (filtrados.length > 0) {
                html += `<div class="section-label">Historial (${filtrados.length})</div>`;
                filtrados.forEach(r => {
                    html += `<div class="historial-item">
                        <div class="historial-header">
                            <span class="historial-asunto ${asuntoColors[r.asunto]||''}">${r.asunto}</span>
                            <span class="historial-fecha">${r.fecha}</span>
                        </div>
                        <div class="historial-desc">${r.descripcion||'—'}</div>
                        ${r.evidencia?`<div class="historial-evidencia"><a href="../uploads/reai/${r.evidencia}" target="_blank">📎 Ver evidencia</a></div>`:''}
                    </div>`;
                });
            } else {
                html += '<div style="text-align:center;padding:16px 0;color:var(--text2);font-size:0.85rem;">Sin registros previos</div>';
            }
            if (puedeCapturar) {
                const hoy = new Date().toISOString().split('T')[0];
                html += `<hr class="divider">
                <div class="section-label">Nuevo registro</div>
                <div class="form-group"><label>Asunto</label>
                    <select id="f_asunto">
                        <option value="Retroalimentación" ${asunto==='Retroalimentación'?'selected':''}>Retroalimentación</option>
                        <option value="ECNUs" ${asunto==='ECNUs'?'selected':''}>ECNUs</option>
                        <option value="Acta Administrativa" ${asunto==='Acta Administrativa'?'selected':''}>Acta Administrativa</option>
                        <option value="Incidencia" ${asunto==='Incidencia'?'selected':''}>Incidencia</option>
                    </select>
                </div>
                <div class="form-group"><label>Fecha</label><input type="date" id="f_fecha" value="${hoy}"></div>
                <div class="form-group"><label>Descripción</label><textarea id="f_descripcion" placeholder="Escribe los detalles..."></textarea></div>
                <div class="form-group"><label>Evidencia (jpg, png, pdf, doc)</label><input type="file" id="f_evidencia" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"></div>
                <button class="btn-primary" id="btnGuardar" onclick="guardarReai()">Guardar registro</button>`;
            }
            document.getElementById('modalBody').innerHTML = html;
        });
}

function guardarReai() {
    const btn = document.getElementById('btnGuardar');
    btn.disabled = true; btn.textContent = 'Guardando...';
    const fd = new FormData();
    fd.append('action','guardar');
    fd.append('numero_talento_gs', currentTalento);
    fd.append('nombre_colaborador', currentNombre);
    fd.append('asunto', document.getElementById('f_asunto').value);
    fd.append('fecha', document.getElementById('f_fecha').value);
    fd.append('descripcion', document.getElementById('f_descripcion').value);
    const ev = document.getElementById('f_evidencia');
    if (ev.files[0]) fd.append('evidencia', ev.files[0]);

    fetch('reai_v2.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                mostrarToast(data.msg, 'success');
                cerrarModalBtn();
                setTimeout(() => location.reload(), 800);
            } else {
                mostrarToast(data.msg, 'error');
                btn.disabled = false; btn.textContent = 'Guardar registro';
            }
        })
        .catch(() => { mostrarToast('Error de conexión','error'); btn.disabled=false; btn.textContent='Guardar registro'; });
}

function cerrarModal(e) { if (e.target.id === 'modalOverlay') cerrarModalBtn(); }
function cerrarModalBtn() {
    document.getElementById('modalOverlay').classList.remove('open');
    document.getElementById('modalBody').innerHTML = '';
}
function mostrarToast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + tipo;
    setTimeout(() => t.className = 'toast', 3000);
}
</script>
</body>
</html>