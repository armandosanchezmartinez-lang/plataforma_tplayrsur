<?php
ini_set('display_errors', 0);
error_reporting(0);
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}

include 'conexion.php';

$rol            = $_SESSION['rol'] ?? 'vendedor';
$talento_gs     = $_SESSION['numero_talento_gs'] ?? '';
$id_posicion    = $_SESSION['id_posicion'] ?? '';
$nombre_usuario = $_SESSION['usuario'] ?? '';

$puestos_comerciales = "'PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'";

// ── FUNCIONES DE JERARQUÍA ───────────────────────────────────────────────────
function getSubordinados($conexion, $id_pos, $semana = null, $anio = null) {
    $ids = [];
    if ($semana && $anio) {
        $stmt = mysqli_prepare($conexion, "SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr = ? AND numero_talento_gs NOT LIKE '%VACANTE%' AND semana = ? AND anio = ?");
        mysqli_stmt_bind_param($stmt, "sii", $id_pos, $semana, $anio);
    } else {
        $stmt = mysqli_prepare($conexion, "SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr = ? AND numero_talento_gs NOT LIKE '%VACANTE%'");
        mysqli_stmt_bind_param($stmt, "s", $id_pos);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $ids[] = $row['id_posicion'];
    mysqli_stmt_close($stmt);
    return $ids;
}

function getTodosSubordinados($conexion, $id_pos, $niveles_restantes, $semana = null, $anio = null) {
    if ($niveles_restantes <= 0) return [];
    $directos = getSubordinados($conexion, $id_pos, $semana, $anio);
    $todos = $directos;
    foreach ($directos as $id) {
        $sub = getTodosSubordinados($conexion, $id, $niveles_restantes - 1, $semana, $anio);
        $todos = array_merge($todos, $sub);
    }
    return array_unique($todos);
}

// ── SEMANA MÁS RECIENTE ──────────────────────────────────────────────────────
$semana_actual = null; $anio_actual = null; $semana_base = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_base   = (int)$row_sem['semana'];
    $anio_actual   = (int)$row_sem['anio'];
    $semana_actual = $semana_base;
}

// ── NIVELES POR ROL ──────────────────────────────────────────────────────────
$niveles = ['admin'=>6,'director_regional'=>5,'director_distrital'=>4,'lider'=>3,'coach'=>2,'vendedor'=>1];
$nivel   = $niveles[$rol] ?? 1;

// ── DATOS DEL USUARIO ────────────────────────────────────────────────────────
$nombre_completo  = $nombre_usuario;
$posicion_usuario = '';
$distrito_usuario = '';

$stmt_nombre = mysqli_prepare($conexion, "SELECT nombre_colaborador, posicion, distrito FROM hc WHERE id_posicion = ? LIMIT 1");
if ($stmt_nombre) {
    mysqli_stmt_bind_param($stmt_nombre, "s", $id_posicion);
    mysqli_stmt_execute($stmt_nombre);
    $res_nombre = mysqli_stmt_get_result($stmt_nombre);
    if ($row_nombre = mysqli_fetch_assoc($res_nombre)) {
        $nombre_completo  = $row_nombre['nombre_colaborador'] ?? $nombre_usuario;
        $posicion_usuario = $row_nombre['posicion'] ?? '';
        $distrito_usuario = $row_nombre['distrito'] ?? '';
    }
    mysqli_stmt_close($stmt_nombre);
}

// ── SUBORDINADOS ─────────────────────────────────────────────────────────────
$subordinados_ids = [];
$folio_ids        = [];

if ($rol !== 'admin') {
    $subordinados_ids = getTodosSubordinados($conexion, $id_posicion, $nivel, $semana_actual, $anio_actual);
    $subordinados_ids[] = $id_posicion;
    $subordinados_ids = array_unique(array_values($subordinados_ids));

    if (!empty($subordinados_ids)) {
        $ph_sub = implode(',', array_fill(0, count($subordinados_ids), '?'));
        $stmt_folios = mysqli_prepare($conexion, "SELECT DISTINCT numero_talento_gs FROM hc WHERE id_posicion IN ($ph_sub) AND numero_talento_gs NOT LIKE '%VACANTE%'");
        $tipos_sub = str_repeat('s', count($subordinados_ids));
        mysqli_stmt_bind_param($stmt_folios, $tipos_sub, ...array_values($subordinados_ids));
        mysqli_stmt_execute($stmt_folios);
        $res_folios = mysqli_stmt_get_result($stmt_folios);
        while ($row_f = mysqli_fetch_assoc($res_folios)) $folio_ids[] = $row_f['numero_talento_gs'];
        mysqli_stmt_close($stmt_folios);
    }
}

$mes_actual   = (int)date('n');
$anio_query   = (int)date('Y');
$distrito_esc = mysqli_real_escape_string($conexion, $distrito_usuario);

// Roles que filtran por distrito (no por vendedor)
$por_distrito = in_array($rol, ['admin', 'director_regional', 'director_distrital']);
$mostrar_meta = $por_distrito;

// ── INSTALACIONES ────────────────────────────────────────────────────────────
if ($rol === 'admin') {
    $r_inst = mysqli_query($conexion,
        "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-'");
} elseif ($por_distrito) {
    $r_inst = mysqli_query($conexion,
        "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-' AND distrito='$distrito_esc'");
} else {
    if (empty($folio_ids)) {
        $r_inst = mysqli_query($conexion, "SELECT 0 as total");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_inst = mysqli_prepare($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
        $tipos = 'ii' . str_repeat('s', count($folio_ids));
        $bind  = array_merge([$mes_actual, $anio_query], array_values($folio_ids));
        mysqli_stmt_bind_param($stmt_inst, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_inst);
        $r_inst = mysqli_stmt_get_result($stmt_inst);
    }
}
$kpi_inst = $r_inst ? (mysqli_fetch_assoc($r_inst)['total'] ?? 0) : 0;

// ── VENTAS ───────────────────────────────────────────────────────────────────
if ($rol === 'admin') {
    $r_vent = mysqli_query($conexion,
        "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query");
} elseif ($por_distrito) {
    $r_vent = mysqli_query($conexion,
        "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query AND distrito='$distrito_esc'");
} else {
    if (empty($folio_ids)) {
        $r_vent = mysqli_query($conexion, "SELECT 0 as total");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_vent = mysqli_prepare($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? AND folio_empleado IN ($ph)");
        $tipos = 'ii' . str_repeat('s', count($folio_ids));
        $bind  = array_merge([$mes_actual, $anio_query], array_values($folio_ids));
        mysqli_stmt_bind_param($stmt_vent, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_vent);
        $r_vent = mysqli_stmt_get_result($stmt_vent);
    }
}
$kpi_vent = $r_vent ? (mysqli_fetch_assoc($r_vent)['total'] ?? 0) : 0;

// ── HC ACTIVO Y VACANTE ──────────────────────────────────────────────────────
$kpi_hc_act = 0; $kpi_hc_vac = 0;
if ($semana_actual && $anio_actual) {
    if ($rol === 'admin') {
        $r_hc_act = mysqli_query($conexion, "SELECT COUNT(*) as total FROM hc WHERE numero_talento_gs NOT LIKE '%VACANTE%' AND semana=$semana_actual AND anio=$anio_actual AND posicion IN ($puestos_comerciales)");
        $r_hc_vac = mysqli_query($conexion, "SELECT COUNT(*) as total FROM hc WHERE numero_talento_gs LIKE '%VACANTE%' AND semana=$semana_actual AND anio=$anio_actual AND posicion IN ($puestos_comerciales)");
    } else {
        if (!empty($subordinados_ids)) {
            $ph = implode(',', array_fill(0, count($subordinados_ids), '?'));

            $stmt_act = mysqli_prepare($conexion, "SELECT COUNT(*) as total FROM hc WHERE numero_talento_gs NOT LIKE '%VACANTE%' AND semana=? AND anio=? AND posicion IN ($puestos_comerciales) AND id_posicion IN ($ph)");
            $tipos = 'ii' . str_repeat('s', count($subordinados_ids));
            $bind  = array_merge([$semana_actual, $anio_actual], array_values($subordinados_ids));
            mysqli_stmt_bind_param($stmt_act, $tipos, ...$bind);
            mysqli_stmt_execute($stmt_act);
            $r_hc_act = mysqli_stmt_get_result($stmt_act);

            $stmt_vac = mysqli_prepare($conexion, "SELECT COUNT(*) as total FROM hc WHERE numero_talento_gs LIKE '%VACANTE%' AND semana=? AND anio=? AND posicion IN ($puestos_comerciales) AND posicion_lr IN ($ph)");
            mysqli_stmt_bind_param($stmt_vac, $tipos, ...$bind);
            mysqli_stmt_execute($stmt_vac);
            $r_hc_vac = mysqli_stmt_get_result($stmt_vac);
        } else {
            $r_hc_act = mysqli_query($conexion, "SELECT 0 as total");
            $r_hc_vac = mysqli_query($conexion, "SELECT 0 as total");
        }
    }
    $kpi_hc_act = $r_hc_act ? (mysqli_fetch_assoc($r_hc_act)['total'] ?? 0) : 0;
    $kpi_hc_vac = $r_hc_vac ? (mysqli_fetch_assoc($r_hc_vac)['total'] ?? 0) : 0;
}
$kpi_hc_total = $kpi_hc_act + $kpi_hc_vac;
$kpi_hc_pct   = $kpi_hc_total > 0 ? round(($kpi_hc_act / $kpi_hc_total) * 100) : 0;

// ── META ACUMULADA ───────────────────────────────────────────────────────────
$dias_transcurridos = (int)date('j') - 2;
$kpi_meta_acum      = 0;
$kpi_meta_pct       = 0;

if ($mostrar_meta) {
    if ($rol === 'admin') {
        $r_meta = mysqli_query($conexion,
            "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1");
    } else {
        $r_meta = mysqli_query($conexion,
            "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1 AND distrito='$distrito_esc'");
    }
    if ($r_meta && $row_meta = mysqli_fetch_assoc($r_meta)) {
        $meta_diaria_total = (float)($row_meta['meta_diaria_total'] ?? 0);
        $kpi_meta_acum     = round($meta_diaria_total * $dias_transcurridos);
        $kpi_meta_pct      = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
    }
}

// ── MIX INSTALACIONES ────────────────────────────────────────────────────────
if ($rol === 'admin') {
    $r_mix_inst = mysqli_query($conexion,
        "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-'");
} elseif ($por_distrito) {
    $r_mix_inst = mysqli_query($conexion,
        "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-' AND distrito='$distrito_esc'");
} else {
    if (empty($folio_ids)) {
        $r_mix_inst = mysqli_query($conexion, "SELECT 0 as p3, 0 as p2");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_mix = mysqli_prepare($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
        $tipos = 'ii' . str_repeat('s', count($folio_ids));
        $bind  = array_merge([$mes_actual, $anio_query], array_values($folio_ids));
        mysqli_stmt_bind_param($stmt_mix, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_mix);
        $r_mix_inst = mysqli_stmt_get_result($stmt_mix);
    }
}
$mix_inst = $r_mix_inst ? mysqli_fetch_assoc($r_mix_inst) : ['p3'=>0,'p2'=>0];
$inst_3p = (int)($mix_inst['p3'] ?? 0);
$inst_2p = (int)($mix_inst['p2'] ?? 0);

// ── MIX VENTAS ───────────────────────────────────────────────────────────────
if ($rol === 'admin') {
    $r_mix_vent = mysqli_query($conexion,
        "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query");
} elseif ($por_distrito) {
    $r_mix_vent = mysqli_query($conexion,
        "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query AND distrito='$distrito_esc'");
} else {
    if (empty($folio_ids)) {
        $r_mix_vent = mysqli_query($conexion, "SELECT 0 as p3, 0 as p2");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_mix_v = mysqli_prepare($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? AND folio_empleado IN ($ph)");
        $tipos = 'ii' . str_repeat('s', count($folio_ids));
        $bind  = array_merge([$mes_actual, $anio_query], array_values($folio_ids));
        mysqli_stmt_bind_param($stmt_mix_v, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_mix_v);
        $r_mix_vent = mysqli_stmt_get_result($stmt_mix_v);
    }
}
$mix_vent = $r_mix_vent ? mysqli_fetch_assoc($r_mix_vent) : ['p3'=>0,'p2'=>0];
$vent_3p = (int)($mix_vent['p3'] ?? 0);
$vent_2p = (int)($mix_vent['p2'] ?? 0);

// ── EVOLUCIÓN 6 MESES ────────────────────────────────────────────────────────
$evolucion_inst = [];
$evolucion_vent = [];
$meses_labels   = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, $mes_actual - $i, 1, $anio_query);
    $m  = (int)date('n', $ts);
    $a  = (int)date('Y', $ts);
    $meses_labels[] = date('M Y', $ts);

    if ($rol === 'admin') {
        $ri = mysqli_query($conexion, "SELECT COUNT(cuenta) as t FROM instalaciones WHERE MONTH(fecha)=$m AND YEAR(fecha)=$a AND origen_prospecto <> '-'");
        $rv = mysqli_query($conexion, "SELECT COUNT(*) as t FROM ventas WHERE MONTH(fecha_cierre)=$m AND YEAR(fecha_cierre)=$a");
    } elseif ($por_distrito) {
        $ri = mysqli_query($conexion, "SELECT COUNT(cuenta) as t FROM instalaciones WHERE MONTH(fecha)=$m AND YEAR(fecha)=$a AND origen_prospecto <> '-' AND distrito='$distrito_esc'");
        $rv = mysqli_query($conexion, "SELECT COUNT(*) as t FROM ventas WHERE MONTH(fecha_cierre)=$m AND YEAR(fecha_cierre)=$a AND distrito='$distrito_esc'");
    } else {
        if (empty($folio_ids)) {
            $ri = mysqli_query($conexion, "SELECT 0 as t");
            $rv = mysqli_query($conexion, "SELECT 0 as t");
        } else {
            $ph = implode(',', array_fill(0, count($folio_ids), '?'));
            $stmt_ri = mysqli_prepare($conexion, "SELECT COUNT(cuenta) as t FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
            $tipos = 'ii' . str_repeat('s', count($folio_ids));
            $bind  = array_merge([$m, $a], array_values($folio_ids));
            mysqli_stmt_bind_param($stmt_ri, $tipos, ...$bind);
            mysqli_stmt_execute($stmt_ri);
            $ri = mysqli_stmt_get_result($stmt_ri);

            $stmt_rv = mysqli_prepare($conexion, "SELECT COUNT(*) as t FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? AND folio_empleado IN ($ph)");
            mysqli_stmt_bind_param($stmt_rv, $tipos, ...$bind);
            mysqli_stmt_execute($stmt_rv);
            $rv = mysqli_stmt_get_result($stmt_rv);
        }
    }
    $evolucion_inst[] = (int)(($ri ? mysqli_fetch_assoc($ri)['t'] : 0) ?? 0);
    $evolucion_vent[] = (int)(($rv ? mysqli_fetch_assoc($rv)['t'] : 0) ?? 0);
}

$roles_labels = [
    'admin'              => 'Administrador',
    'director_regional'  => 'Director Regional',
    'director_distrital' => 'Director Distrital',
    'lider'              => 'Líder',
    'coach'              => 'Coach',
    'vendedor'           => 'Vendedor',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — TOTALXPEDIENT</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        :root {
            --blue:#2b57a7; --blue2:#3b66b8; --bg:#f4f6fb; --white:#ffffff;
            --text:#1a2540; --text2:#6b7a99; --border:#e2e8f4;
            --green:#10b981; --purple:#7c3aed; --red:#ef4444; --sidebar:200px;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Segoe UI',sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; }
        .sidebar { width:var(--sidebar); background:var(--blue); min-height:100vh; position:fixed; top:0; left:0; display:flex; flex-direction:column; align-items:center; padding:28px 0; z-index:100; }
        .sidebar-logo { color:white; font-size:2rem; margin-bottom:6px; }
        .sidebar-brand { color:rgba(255,255,255,0.9); font-size:0.72rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:32px; text-align:center; padding:0 12px; }
        .nav-item { width:100%; display:flex; flex-direction:column; align-items:center; gap:4px; padding:14px 0; color:rgba(255,255,255,0.65); text-decoration:none; font-size:0.78rem; font-weight:600; transition:all 0.2s; }
        .nav-item:hover,.nav-item.active { color:white; background:rgba(255,255,255,0.12); }
        .nav-icon { font-size:1.3rem; }
        .sidebar-bottom { margin-top:auto; width:100%; padding:0 12px; }
        .logout-btn { display:block; text-align:center; padding:10px; border-radius:8px; color:rgba(255,255,255,0.6); text-decoration:none; font-size:0.78rem; font-weight:600; transition:all 0.2s; }
        .logout-btn:hover { background:rgba(255,255,255,0.1); color:white; }
        .main { margin-left:var(--sidebar); flex:1; padding:32px; }
        .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; }
        .page-header h2 { font-size:1.5rem; font-weight:700; letter-spacing:-0.5px; }
        .page-header p { font-size:0.82rem; color:var(--text2); margin-top:2px; }
        .user-badge { display:flex; align-items:center; gap:10px; background:var(--white); border:1px solid var(--border); border-radius:50px; padding:8px 16px 8px 8px; }
        .user-avatar { width:34px; height:34px; border-radius:50%; background:var(--blue); color:white; display:flex; align-items:center; justify-content:center; font-size:0.8rem; font-weight:700; }
        .user-name { font-size:0.82rem; font-weight:700; }
        .user-role { font-size:0.7rem; color:var(--text2); }
        .kpi-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
        .kpi-card { background:var(--white); border-radius:16px; padding:22px 24px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.04); }
        .kpi-card.full { grid-column:span 2; }
        .kpi-header { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
        .kpi-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
        .kpi-blue   { background:#e8f0fe; }
        .kpi-green  { background:#e6faf3; }
        .kpi-purple { background:#f0ebff; }
        .kpi-orange { background:#fff7ed; }
        .kpi-label { font-size:0.88rem; font-weight:700; }
        .kpi-numbers { display:flex; gap:28px; }
        .kpi-num { display:flex; flex-direction:column; }
        .kpi-val { font-size:1.9rem; font-weight:800; letter-spacing:-1px; line-height:1; }
        .kpi-val.blue   { color:var(--blue2); }
        .kpi-val.green  { color:var(--green); }
        .kpi-val.purple { color:var(--purple); }
        .kpi-val.red    { color:var(--red); }
        .kpi-sub { font-size:0.7rem; color:var(--text2); margin-top:4px; font-weight:600; }
        .progress-bar-wrap { margin-top:14px; }
        .progress-bar-bg { background:#e2e8f4; border-radius:99px; height:10px; overflow:hidden; }
        .progress-bar-fill { height:100%; border-radius:99px; transition:width 0.6s ease; }
        .progress-bar-fill.good    { background:#10b981; }
        .progress-bar-fill.warning { background:#f59e0b; }
        .progress-bar-fill.danger  { background:#ef4444; }
        .progress-labels { display:flex; justify-content:space-between; margin-top:6px; font-size:0.7rem; color:var(--text2); font-weight:600; }
        .charts-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
        .chart-card { background:var(--white); border-radius:16px; padding:22px 24px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.04); }
        .chart-title { font-size:0.88rem; font-weight:700; margin-bottom:16px; color:var(--text); }
        .chart-wrap { position:relative; height:200px; }
        .evo-card { background:var(--white); border-radius:16px; padding:22px 24px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.04); }
        .evo-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:16px; }
        .evo-wrap { position:relative; height:220px; }
        .evo-sub { font-size:0.72rem; color:var(--text2); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-logo">📊</div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="index.php" class="nav-item active"><span class="nav-icon">⊞</span> Dashboard</a>
    <!-- <a href="import/import_instalaciones.php" class="nav-item"><span class="nav-icon">🔧</span> Instalaciones</a>
    <a href="import/import_ventas.php" class="nav-item"><span class="nav-icon">📈</span> Ventas</a> -->
    <a href="detalle/hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="detalle/reai.php" class="nav-item"><span class="nav-icon">📋</span> REAI</a>
    <div class="sidebar-bottom">
        <a href="logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <div class="page-header">
        <div>
            <h2><?= htmlspecialchars($roles_labels[$rol] ?? $rol) ?> <?= htmlspecialchars($distrito_usuario) ?></h2>
            <p><?= date('d \d\e F Y') ?></p>
        </div>
        <div class="user-badge">
            <div class="user-avatar"><?= strtoupper(substr($nombre_completo, 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($nombre_completo) ?></div>
                <div class="user-role"><?= htmlspecialchars($roles_labels[$rol] ?? $rol) ?></div>
            </div>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-icon kpi-blue">🔧</div>
                <div class="kpi-label">Instalaciones</div>
            </div>
            <div class="kpi-numbers">
                <div class="kpi-num">
                    <span class="kpi-val blue"><?= number_format($kpi_inst) ?></span>
                    <span class="kpi-sub">del mes</span>
                </div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-icon kpi-green">📈</div>
                <div class="kpi-label">Ventas</div>
            </div>
            <div class="kpi-numbers">
                <div class="kpi-num">
                    <span class="kpi-val green"><?= number_format($kpi_vent) ?></span>
                    <span class="kpi-sub">del mes</span>
                </div>
            </div>
        </div>

        <div class="kpi-card full">
            <div class="kpi-header">
                <div class="kpi-icon kpi-purple">👥</div>
                <div class="kpi-label">Headcount — Semana <?= $semana_base ?> · <?= $anio_actual ?></div>
            </div>
            <div class="kpi-numbers">
                <div class="kpi-num">
                    <span class="kpi-val purple"><?= number_format($kpi_hc_act) ?></span>
                    <span class="kpi-sub">activo</span>
                </div>
                <div class="kpi-num">
                    <span class="kpi-val red"><?= number_format($kpi_hc_vac) ?></span>
                    <span class="kpi-sub">vacante</span>
                </div>
                <div class="kpi-num">
                    <span class="kpi-val purple"><?= $kpi_hc_pct ?>%</span>
                    <span class="kpi-sub">ocupación</span>
                </div>
            </div>
        </div>

        <?php if ($mostrar_meta): ?>
        <div class="kpi-card full">
            <div class="kpi-header">
                <div class="kpi-icon kpi-orange">🎯</div>
                <div class="kpi-label">Avance vs Meta — Día <?= $dias_transcurridos ?> de <?= date('t') ?></div>
            </div>
            <div class="kpi-numbers" style="margin-bottom:0;">
                <div class="kpi-num">
                    <span class="kpi-val blue"><?= number_format($kpi_inst) ?></span>
                    <span class="kpi-sub">instalaciones</span>
                </div>
                <div class="kpi-num">
                    <span class="kpi-val" style="color:#f59e0b;"><?= number_format($kpi_meta_acum) ?></span>
                    <span class="kpi-sub">meta acumulada</span>
                </div>
                <div class="kpi-num">
                    <span class="kpi-val <?= $kpi_meta_pct >= 100 ? 'green' : 'red' ?>"
                          style="<?= $kpi_meta_pct >= 80 && $kpi_meta_pct < 100 ? 'color:#f59e0b;' : '' ?>">
                        <?= $kpi_meta_pct ?>%
                    </span>
                    <span class="kpi-sub">avance</span>
                </div>
            </div>
            <div class="progress-bar-wrap">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill <?= $kpi_meta_pct >= 100 ? 'good' : ($kpi_meta_pct >= 80 ? 'warning' : 'danger') ?>"
                         style="width:<?= min($kpi_meta_pct, 100) ?>%"></div>
                </div>
                <div class="progress-labels">
                    <span>0</span>
                    <span>Meta: <?= number_format($kpi_meta_acum) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-title">Mix 2P y 3P — Instalaciones</div>
            <div class="chart-wrap"><canvas id="cInstMix"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Mix 2P y 3P — Ventas</div>
            <div class="chart-wrap"><canvas id="cVentMix"></canvas></div>
        </div>
    </div>

    <div class="evo-card">
        <div class="chart-title">Instalaciones y Ventas — Últimos 6 meses</div>
        <div class="evo-grid">
            <div>
                <div class="evo-sub">Instalaciones</div>
                <div class="evo-wrap"><canvas id="cInstEvo"></canvas></div>
            </div>
            <div>
                <div class="evo-sub">Ventas</div>
                <div class="evo-wrap"><canvas id="cVentEvo"></canvas></div>
            </div>
        </div>
    </div>
</main>

<script>
const labels6 = <?= json_encode($meses_labels) ?>;
const instEvo = <?= json_encode($evolucion_inst) ?>;
const ventEvo = <?= json_encode($evolucion_vent) ?>;
const inst2p  = <?= $inst_2p ?>;
const inst3p  = <?= $inst_3p ?>;
const vent2p  = <?= $vent_2p ?>;
const vent3p  = <?= $vent_3p ?>;

const donutOpts = () => ({
    responsive: true, maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 16 } },
        tooltip: { callbacks: { label: ctx => {
            const t = ctx.dataset.data.reduce((a,b)=>a+b,0);
            const p = t > 0 ? ((ctx.parsed/t)*100).toFixed(1) : 0;
            return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${p}%)`;
        }}}
    }
});

new Chart(document.getElementById('cInstMix'), {
    type: 'doughnut',
    data: { labels: ['2P','3P'], datasets: [{ data: [inst2p, inst3p], backgroundColor: ['#2b57a7','#a8c4f0'], borderWidth: 0 }] },
    options: donutOpts()
});
new Chart(document.getElementById('cVentMix'), {
    type: 'doughnut',
    data: { labels: ['2P','3P'], datasets: [{ data: [vent2p, vent3p], backgroundColor: ['#10b981','#a7f3d0'], borderWidth: 0 }] },
    options: donutOpts()
});

const barOpts = () => ({
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        y: { beginAtZero: true, grid: { color: '#e2e8f4' }, ticks: { font: { size: 11 } } },
        x: { grid: { display: false }, ticks: { font: { size: 10 } } }
    }
});
new Chart(document.getElementById('cInstEvo'), {
    type: 'bar',
    data: { labels: labels6, datasets: [{ data: instEvo, backgroundColor: '#3b66b8', borderRadius: 6 }] },
    options: barOpts()
});
new Chart(document.getElementById('cVentEvo'), {
    type: 'bar',
    data: { labels: labels6, datasets: [{ data: ventEvo, backgroundColor: '#10b981', borderRadius: 6 }] },
    options: barOpts()
});
</script>
</body>
</html>