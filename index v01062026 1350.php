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

// --- CONFIGURACIÓN DE USUARIO Y ROLES ---
$rol            = $_SESSION['rol'] ?? 'vendedor';
$talento_gs     = $_SESSION['numero_talento_gs'] ?? '';
$id_posicion    = $_SESSION['id_posicion'] ?? '';
$nombre_usuario = $_SESSION['usuario'] ?? '';

$puestos_comerciales = "'PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'";

// --- FUNCIONES DE JERARQUÍA ---
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

// --- TIEMPO Y FECHAS ---
$semana_actual = null; $anio_actual = null; $semana_base = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_base   = (int)$row_sem['semana'];
    $anio_actual   = (int)$row_sem['anio'];
    $semana_actual = $semana_base;
}

$niveles = ['admin'=>6,'director_regional'=>5,'director_distrital'=>4,'lider'=>3,'coach'=>2,'vendedor'=>1];
$nivel   = $niveles[$rol] ?? 1;

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

// --- FILTRADO POR JERARQUÍA ---
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

$mes_actual   = (int)date('n', strtotime('-2 day'));
$anio_query   = (int)date('Y', strtotime('-2 day'));
$distrito_esc = mysqli_real_escape_string($conexion, $distrito_usuario);

// Equivalencias de distrito
$distritos_equivalentes = [$distrito_usuario];
if ($distrito_usuario === 'COATZA MINA') {
    $distritos_equivalentes[] = 'COATZA / MINA';
}
$distritos_equivalentes = array_unique($distritos_equivalentes);
$distritos_sql = "'" . implode("','", array_map(function($d) use ($conexion) {
    return mysqli_real_escape_string($conexion, $d);
}, $distritos_equivalentes)) . "'";

$por_distrito = in_array($rol, ['admin', 'director_regional', 'director_distrital']);
$mostrar_meta = $por_distrito;

// ── INSTALACIONES ────────────────────────────────────────────────────────────
if ($rol === 'admin') {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-'");
} elseif ($por_distrito) {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-' AND distrito IN ($distritos_sql)");
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
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query");
} elseif ($por_distrito) {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query AND distrito IN ($distritos_sql)");
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
$kpi_conv = ($kpi_vent > 0) ? round(($kpi_inst / $kpi_vent) * 100, 1) : 0;

// ── HC ───────────────────────────────────────────────────────────────────────
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

// ── META ─────────────────────────────────────────────────────────────────────
$ayer_timestamp = strtotime('-1 day');
$dia_ayer           = (int)date('j', $ayer_timestamp);    
$mes_actual         = (int)date('n', $ayer_timestamp); 
$anio_query         = (int)date('Y', $ayer_timestamp); 
$dias_transcurridos = $dia_ayer; 

$kpi_meta_acum      = 0;
$kpi_meta_pct       = 0;

if ($mostrar_meta) {
    if ($rol === 'admin') {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1");
    } else {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1 AND distrito IN ($distritos_sql)");
    }

    if ($r_meta && $row_meta = mysqli_fetch_assoc($r_meta)) {
        $meta_diaria_total = (float)($row_meta['meta_diaria_total'] ?? 0);
        $kpi_meta_acum     = round($meta_diaria_total * $dias_transcurridos);
        $kpi_meta_pct      = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
    }
}

// ── MIX INSTALACIONES ────────────────────────────────────────────────────────
if ($rol === 'admin') {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-'");
} elseif ($por_distrito) {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-' AND distrito IN ($distritos_sql)");
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
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query");
} elseif ($por_distrito) {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query AND distrito IN ($distritos_sql)");
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

// ── EVOLUCIÓN 6 MESES APILADA ────────────────────────────────────────────────
$meses_labels = [];
$datos_inst_stacked = []; 
$datos_vent_stacked = [];

// Fecha inicial alineada a los 6 meses mostrados en el dashboard.
// Evita que el primer mes del periodo quede incompleto o en cero
// cuando CURDATE() ya cambió de mes.
$fecha_inicio_evolucion = date(
    'Y-m-01',
    mktime(0, 0, 0, $mes_actual - 5, 1, $anio_query)
);

// Generar etiquetas de meses (eje X)
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, $mes_actual - $i, 1, $anio_query);
    $meses_labels[] = date('M Y', $ts);
}

// 1. Datos Instalaciones por Origen
$query_inst = "SELECT MONTH(fecha) as mes, YEAR(fecha) as anio, origen_prospecto, COUNT(*) as total 
    FROM instalaciones 
    WHERE fecha >= '$fecha_inicio_evolucion' AND origen_prospecto <> '-' ";
if ($rol !== 'admin' && $por_distrito) {
    $query_inst .= " AND distrito IN ($distritos_sql)";
} elseif ($rol !== 'admin' && !empty($folio_ids)) {
    $ph = implode("','", array_values($folio_ids));
    $query_inst .= " AND folio_empleado IN ('$ph')";
}
$query_inst .= " GROUP BY anio, mes, origen_prospecto";

$res_i = mysqli_query($conexion, $query_inst);
while($row = mysqli_fetch_assoc($res_i)) {
    $label_mes = date('M Y', mktime(0,0,0, $row['mes'], 1, $row['anio']));
    $idx = array_search($label_mes, $meses_labels);
    if($idx !== false) {
        $orig = $row['origen_prospecto'] ?: 'OTROS';
        if(!isset($datos_inst_stacked[$orig])) $datos_inst_stacked[$orig] = array_fill(0, 6, 0);
        $datos_inst_stacked[$orig][$idx] = (int)$row['total'];
    }
}

// 2. Datos Ventas por Canal
$query_vent = "SELECT MONTH(fecha_cierre) as mes, YEAR(fecha_cierre) as anio, canal_venta, COUNT(*) as total 
    FROM ventas 
    WHERE fecha_cierre >= '$fecha_inicio_evolucion' ";
if ($rol !== 'admin' && $por_distrito) {
    $query_vent .= " AND distrito IN ($distritos_sql)";
} elseif ($rol !== 'admin' && !empty($folio_ids)) {
    $ph = implode("','", array_values($folio_ids));
    $query_vent .= " AND folio_empleado IN ('$ph')";
}
$query_vent .= " GROUP BY anio, mes, canal_venta";

$res_v = mysqli_query($conexion, $query_vent);
while($row = mysqli_fetch_assoc($res_v)) {
    $label_mes = date('M Y', mktime(0,0,0, $row['mes'], 1, $row['anio']));
    $idx = array_search($label_mes, $meses_labels);
    if($idx !== false) {
        $canal = $row['canal_venta'] ?: 'OTROS';
        if(!isset($datos_vent_stacked[$canal])) $datos_vent_stacked[$canal] = array_fill(0, 6, 0);
        $datos_vent_stacked[$canal][$idx] = (int)$row['total'];
    }
}

$colores_palette = ['#FF006C', '#7A2BFF', '#00A6FF', '#00E5FF', '#FF6500', '#7CFF00', '#2C2F3A'];

// ── TABLA DE PARTICIPACIÓN POR CANAL ─────────────────────────────────────────
// Calcular totales por mes para instalaciones
$totales_inst_mes = array_fill(0, 6, 0);
foreach ($datos_inst_stacked as $canal => $vals) {
    foreach ($vals as $i => $v) $totales_inst_mes[$i] += $v;
}
// Calcular totales por mes para ventas
$totales_vent_mes = array_fill(0, 6, 0);
foreach ($datos_vent_stacked as $canal => $vals) {
    foreach ($vals as $i => $v) $totales_vent_mes[$i] += $v;
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
    <link rel="stylesheet" href="assets/css/xpedient-v2.css?v=160">
</head>
<body class="page-dashboard">
<?php
$current_page = 'dashboard';
include __DIR__ . '/includes/sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div>
            <h2><?= htmlspecialchars($roles_labels[$rol] ?? $rol) ?> <?= htmlspecialchars($distrito_usuario) ?></h2>
            <p><?= date('d \d\e F Y', strtotime('-1 day')) ?></p>
        </div>
        <div class="user-badge" id="userBadge" onclick="toggleUserMenu(event)">
            <div class="user-avatar"><?= strtoupper(substr($nombre_completo, 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($nombre_completo) ?></div>
                <div class="user-role"><?= htmlspecialchars($roles_labels[$rol] ?? $rol) ?></div>
            </div>
            <span class="user-caret">▾</span>
            <div class="user-dropdown" id="userDropdown">
                <button class="user-dropdown-item" onclick="openChangePassword(event)">
                    <span>🔑</span> Cambiar contraseña
                </button>
                <div class="user-dropdown-divider"></div>
                <a class="user-dropdown-item user-dropdown-logout" href="logout.php" onclick="event.stopPropagation()">
                    <span>⎋</span> Cerrar sesión
                </a>
            </div>
        </div>
    </div>

    <div class="kpi-grid">

        <?php if ($mostrar_meta): 
            $porcentaje_visual_aguja_meta = min((float)$kpi_meta_pct, 100); 
            $angulo_aguja_meta = ($porcentaje_visual_aguja_meta / 100 * 180) - 90;
            $color_porcentaje_meta = ($kpi_meta_pct >= 100) ? 'var(--green)' : (($kpi_meta_pct >= 80) ? '#f59e0b' : 'var(--red)');
        ?>
        <div class="kpi-card" style="padding-right: 15px;">
            <div class="kpi-header" style="margin-bottom: 5px;">
                <div class="kpi-icon kpi-orange" style="width: 32px; height: 32px;">🎯</div>
                <div class="kpi-label">Avance vs Meta</div>
            </div>
            
            <div class="kpi-speed-layout">
                <div class="speedometer-container">
                    <div class="speedometer-arco-mascara">
                        <div class="speedometer-gradiente"></div>
                        <div class="speedometer-centro-blanco"></div>
                    </div>
                    <div class="needle-pivote"></div>
                    <div class="needle" style="transform: rotate(<?= $angulo_aguja_meta ?>deg);"></div>
                    <div class="porcentaje-sobre-arco" style="color: <?= $color_porcentaje_meta ?>;">
                        <?= $kpi_meta_pct ?>%
                    </div>
                </div>

                <div class="speed-numbers">
                    <span class="speed-val"><?= number_format($kpi_inst) ?></span>
                    <span class="kpi-sub" style="margin-bottom: 10px;">Instalaciones</span>
                    
                    <span class="kpi-val" style="color:#f59e0b; font-size: 1.3rem;"><?= number_format($kpi_meta_acum) ?></span>
                    <span class="kpi-sub">Meta (Día <?= $dias_transcurridos ?>)</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
        
        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-icon kpi-green">🔄</div>
                <div class="kpi-label">Conversión</div>
            </div>
            <div class="kpi-numbers">
                <div class="kpi-num">
                    <span class="kpi-val green"><?= number_format($kpi_conv, 1) ?>%</span>
                    <span class="kpi-sub">del mes</span>
                </div>
            </div>
        </div>

    </div>

    <div class="kpi-grid-3cols">
        
        <?php 
            $porcentaje_visual_aguja_hc = min((float)$kpi_hc_pct, 100); 
            $angulo_aguja_hc = ($porcentaje_visual_aguja_hc / 100 * 180) - 90;
            $color_porcentaje_hc = ($kpi_hc_pct >= 100) ? 'var(--green)' : (($kpi_hc_pct >= 80) ? '#f59e0b' : 'var(--red)');
        ?>
        <div class="kpi-card" style="padding-right: 15px;">
             <div class="kpi-header" style="margin-bottom: 5px;">
                <div class="kpi-icon kpi-purple" style="width: 32px; height: 32px;">👥</div>
                <div class="kpi-label">Headcount — Semana <?= $semana_base ?> · <?= $anio_actual ?></div>
            </div>

             <div class="kpi-speed-layout">
                <div class="speedometer-container">
                    <div class="speedometer-arco-mascara">
                        <div class="speedometer-gradiente"></div>
                        <div class="speedometer-centro-blanco"></div>
                    </div>
                    <div class="needle-pivote"></div>
                    <div class="needle" style="transform: rotate(<?= $angulo_aguja_hc ?>deg);"></div>
                    <div class="porcentaje-sobre-arco" style="color: <?= $color_porcentaje_hc ?>;">
                        <?= $kpi_hc_pct ?>%
                    </div>
                </div>

                <div class="speed-numbers" style="align-items: flex-start; margin-left: 20px;">
                    <div style="display:flex; gap: 20px;">
                        <div class="kpi-num">
                            <span class="kpi-val purple" style="font-size: 1.8rem;"><?= number_format($kpi_hc_act) ?></span>
                            <span class="kpi-sub">Activo</span>
                        </div>
                        <div class="kpi-num">
                            <span class="kpi-val red" style="font-size: 1.8rem;"><?= number_format($kpi_hc_vac) ?></span>
                            <span class="kpi-sub">Vacante</span>
                        </div>
                    </div>
                    <div style="margin-top: 10px;">
                        <span class="kpi-val" style="color:var(--text); font-size: 1.2rem;"><?= number_format($kpi_hc_total) ?></span>
                        <span class="kpi-sub">Total Plantilla</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-title">Mix 2P y 3P — Ventas</div>
            <div class="chart-wrap"><canvas id="cVentMix"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-title">Mix 2P y 3P — Instalaciones</div>
            <div class="chart-wrap"><canvas id="cInstMix"></canvas></div>
        </div>

    </div>

    <div class="evo-card">
        <div class="chart-title">Evolución — Últimos 6 meses por canal</div>
        <div class="evo-grid">
            <div>
                <div class="evo-sub">Ventas por canal</div>
                <div class="evo-wrap"><canvas id="cVentEvo"></canvas></div>
            </div>
            <div>
                <div class="evo-sub">Instalaciones por origen</div>
                <div class="evo-wrap"><canvas id="cInstEvo"></canvas></div>
            </div>
        </div>
    </div>

    <!-- TABLA DE PARTICIPACIÓN POR CANAL -->
    <?php if (!empty($datos_inst_stacked) || !empty($datos_vent_stacked)): ?>
    <div class="evo-card" style="margin-top:20px;">
        <div class="chart-title">Participación por canal — Últimos 6 meses (%)</div>
        <div class="evo-grid" style="margin-top:16px;">
            <!-- VENTAS -->
            <div>
                <div class="evo-sub">Ventas por canal</div>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:7px 10px;background:#2b57a7;color:white;border-radius:6px 0 0 0;font-size:0.7rem;">Canal</th>
                            <?php foreach ($meses_labels as $ml): ?>
                            <th style="text-align:center;padding:7px 8px;background:#2b57a7;color:white;font-size:0.7rem;"><?= $ml ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($datos_vent_stacked as $canal => $vals):
                        if (array_sum($vals) == 0) continue;
                    ?>
                        <tr style="border-bottom:1px solid #e2e8f4;">
                            <td style="padding:6px 10px;font-weight:600;color:#1a2540;"><?= htmlspecialchars($canal) ?></td>
                            <?php foreach ($vals as $i => $v):
                                $pct = $totales_vent_mes[$i] > 0 ? round(($v / $totales_vent_mes[$i]) * 100, 1) : 0;
                            ?>
                            <td style="text-align:center;padding:6px 8px;color:<?= $pct > 0 ? '#1a2540' : '#9ca3af' ?>;">
                                <?= $pct > 0 ? $pct . '%' : '—' ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                        <tr style="background:#e8f0fe;font-weight:700;">
                            <td style="padding:6px 10px;color:#2b57a7;">Total</td>
                            <?php foreach ($totales_vent_mes as $t): ?>
                            <td style="text-align:center;padding:6px 8px;color:#2b57a7;"><?= number_format($t) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            <!-- INSTALACIONES -->
            <div>
                <div class="evo-sub">Instalaciones por origen</div>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:7px 10px;background:#2b57a7;color:white;border-radius:6px 0 0 0;font-size:0.7rem;">Origen</th>
                            <?php foreach ($meses_labels as $ml): ?>
                            <th style="text-align:center;padding:7px 8px;background:#2b57a7;color:white;font-size:0.7rem;"><?= $ml ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($datos_inst_stacked as $canal => $vals):
                        if (array_sum($vals) == 0) continue;
                    ?>
                        <tr style="border-bottom:1px solid #e2e8f4;">
                            <td style="padding:6px 10px;font-weight:600;color:#1a2540;"><?= htmlspecialchars($canal) ?></td>
                            <?php foreach ($vals as $i => $v):
                                $pct = $totales_inst_mes[$i] > 0 ? round(($v / $totales_inst_mes[$i]) * 100, 1) : 0;
                            ?>
                            <td style="text-align:center;padding:6px 8px;color:<?= $pct > 0 ? '#1a2540' : '#9ca3af' ?>;">
                                <?= $pct > 0 ? $pct . '%' : '—' ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                        <tr style="background:#e8f0fe;font-weight:700;">
                            <td style="padding:6px 10px;color:#2b57a7;">Total</td>
                            <?php foreach ($totales_inst_mes as $t): ?>
                            <td style="text-align:center;padding:6px 8px;color:#2b57a7;"><?= number_format($t) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<script>
// --- PALETA TOTAL STORE / TOTALXPEDIENT ---
// Manual de identidad: primarios Magenta, Purpura, Azul Electrico;
// secundarios Cian, Lima y Naranja; neutros para apoyo visual.
const txBrandColors = {
    magenta: '#FF006C',
    purpura: '#7A2BFF',
    azulElectrico: '#00A6FF',
    cian: '#00E5FF',
    lima: '#7CFF00',
    naranja: '#FF6500',
    carbon: '#030916',
    grisOscuro: '#2C2F3A',
    grisClaro: '#E0E3EA'
};

// --- DONUTS (MIX) ---
const inst2p = <?= $inst_2p ?>; const inst3p = <?= $inst_3p ?>;
const vent2p = <?= $vent_2p ?>; const vent3p = <?= $vent_3p ?>; // mostrar % en el mix
const donutOpts = () => ({
    responsive: true, maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom', labels: { font: { size: 10 }, padding: 10 } },
        datalabels: {
            color: '#fff', font: { size: 11, weight: 'bold' },
            formatter: (value, ctx) => {
                const t = ctx.dataset.data.reduce((a,b)=>a+b,0);
                return (t === 0 || value === 0) ? '' : ((value/t)*100).toFixed(1) + '%';
            }
        },
        tooltip: { callbacks: { label: ctx => {
            const t = ctx.dataset.data.reduce((a,b)=>a+b,0);
            const p = t > 0 ? ((ctx.parsed/t)*100).toFixed(1) : 0;
            return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${p}%)`;
        }}}
    }
});

new Chart(document.getElementById('cInstMix'), {
    type: 'doughnut',
    data: { labels: ['2P','3P'], datasets: [{ data: [inst2p, inst3p], backgroundColor: [txBrandColors.azulElectrico, txBrandColors.cian], borderWidth: 0 }] },
    options: donutOpts(),
    plugins: [ChartDataLabels]
});
new Chart(document.getElementById('cVentMix'), {
    type: 'doughnut',
    data: { labels: ['2P','3P'], datasets: [{ data: [vent2p, vent3p], backgroundColor: [txBrandColors.magenta, txBrandColors.purpura], borderWidth: 0 }] },
    options: donutOpts(),
    plugins: [ChartDataLabels]
});

// --- EVOLUCIÓN APILADA (6 MESES) ---
const labels6 = <?= json_encode($meses_labels) ?>;

const canalColores = {
    // Paleta alineada al manual de identidad Total Store
    'Cambaceo':                    txBrandColors.azulElectrico,
    'Punto de Venta':              txBrandColors.magenta,
    'Call Center':                 txBrandColors.naranja,
    'eCommerce':                   txBrandColors.purpura,
    'Venta Digital':               txBrandColors.cian,
    'Winback':                     '#FF00B8',
    'Desarrollos':                 txBrandColors.lima,
    'Distribuidor':                '#FF8A00',
    'Autoempresarios Autorizados': '#3B5BFF',
    'Otro':                        txBrandColors.grisClaro,
};

// ... (Obtención de datasets igual que antes) ...
const instCanales = <?= json_encode(empty($datos_inst_stacked) ? [] : array_keys($datos_inst_stacked)) ?>;
const instData    = <?= json_encode(empty($datos_inst_stacked) ? [] : array_values($datos_inst_stacked)) ?>;
const ventCanales = <?= json_encode(empty($datos_vent_stacked) ? [] : array_keys($datos_vent_stacked)) ?>;
const ventData    = <?= json_encode(empty($datos_vent_stacked) ? [] : array_values($datos_vent_stacked)) ?>;

// 1. PLUGIN PARA LOS TOTALES (Con ajuste de margen superior)
const pluginTotalesArriba = {
    id: 'pluginTotalesArriba',
    afterDatasetsDraw: (chart) => {
        const ctx = chart.ctx;
        chart.data.datasets[0].data.forEach((_, index) => {
            let total = 0;
            let topY = chart.scales.y.bottom;
            let metaX = 0;
            let hasData = false;

            for (let k = 0; k < chart.data.datasets.length; k++) {
                const meta = chart.getDatasetMeta(k);
                const val = chart.data.datasets[k].data[index];
                if (chart.isDatasetVisible(k) && val > 0) {
                    total += val;
                    metaX = meta.data[index].x;
                    if (meta.data[index].y < topY) topY = meta.data[index].y;
                    hasData = true;
                }
            }

            if (hasData && total > 0) {
                ctx.save();
                ctx.fillStyle = '#1a2540'; 
                ctx.font = 'bold 13px "Segoe UI", sans-serif'; 
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(total, metaX, topY - 8); 
                ctx.restore();
            }
        });
    }
};

// 2. OPCIONES DE LAS BARRAS
const stackOpts = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { 
        legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } },
        datalabels: { 
            display: (context) => context.dataset.data[context.dataIndex] > 0, 
            color: '#ffffff',
            font: { weight: 'bold', size: 11 },
            textShadowColor: 'rgba(0, 0, 0, 0.5)',
            textShadowBlur: 4,
            formatter: Math.round
        }
    },
    scales: {
        y: { 
            stacked: true, 
            beginAtZero: true, 
            grid: { color: '#e2e8f4' },
            ticks: { font: { size: 11 } },
            suggestedMax: (ctx) => {
                const max = ctx.chart.scales.y?.max;
                return max ? max * 1.25 : null;
            }
        },
        x: { 
            stacked: true, 
            grid: { display: false },
            ticks: { font: { size: 11, weight: 'bold' } } 
        }
    }
};

new Chart(document.getElementById('cInstEvo'), {
    type: 'bar',
    data: {
        labels: labels6,
        datasets: instCanales.map((c, i) => ({
            label: c,
            data: instData[i],
            backgroundColor: canalColores[c] || txBrandColors.grisClaro,
            borderRadius: i === instCanales.length - 1 ? 4 : 0,
        }))
    },
    options: stackOpts,
    plugins: [pluginTotalesArriba]
});

new Chart(document.getElementById('cVentEvo'), {
    type: 'bar',
    data: {
        labels: labels6,
        datasets: ventCanales.map((c, i) => ({
            label: c,
            data: ventData[i],
            backgroundColor: canalColores[c] || txBrandColors.grisClaro,
            borderRadius: i === ventCanales.length - 1 ? 4 : 0,
        }))
    },
    options: stackOpts,
    plugins: [pluginTotalesArriba]
});
</script>

<!-- ── MODAL CAMBIAR CONTRASEÑA ── -->
<div class="modal-overlay" id="modalPassword">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-title">🔑 Cambiar contraseña</div>
        <div class="modal-subtitle">La nueva contraseña debe tener al menos 8 caracteres</div>

        <div class="modal-field">
            <label for="pwdActual">Contraseña actual</label>
            <input type="password" id="pwdActual" placeholder="••••••••" autocomplete="current-password">
            <div class="modal-msg" id="msgActual"></div>
        </div>

        <div class="modal-field">
            <label for="pwdNueva">Nueva contraseña</label>
            <input type="password" id="pwdNueva" placeholder="••••••••" autocomplete="new-password" oninput="checkStrength()">
            <div class="pwd-strength"><div class="pwd-strength-bar" id="strengthBar"></div></div>
            <div class="modal-msg" id="msgNueva"></div>
        </div>

        <div class="modal-field">
            <label for="pwdConfirm">Confirmar nueva contraseña</label>
            <input type="password" id="pwdConfirm" placeholder="••••••••" autocomplete="new-password">
            <div class="modal-msg" id="msgConfirm"></div>
        </div>

        <div class="modal-msg" id="msgGeneral" style="font-size:0.85rem;margin-top:4px;"></div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeChangePassword()">Cancelar</button>
            <button class="btn-save" id="btnGuardar" onclick="submitChangePassword()">Guardar cambios</button>
        </div>
    </div>
</div>

<script>
/* ── DROPDOWN USER BADGE ── */
function toggleUserMenu(e) {
    e.stopPropagation();
    const badge = document.getElementById('userBadge');
    badge.classList.toggle('open');
}
document.addEventListener('click', () => {
    document.getElementById('userBadge').classList.remove('open');
});

/* ── MODAL CONTRASEÑA ── */
function openChangePassword(e) {
    e.stopPropagation();
    document.getElementById('userBadge').classList.remove('open');
    resetPasswordModal();
    document.getElementById('modalPassword').classList.add('active');
    setTimeout(() => document.getElementById('pwdActual').focus(), 150);
}
function closeChangePassword() {
    document.getElementById('modalPassword').classList.remove('active');
}
document.getElementById('modalPassword').addEventListener('click', closeChangePassword);

function resetPasswordModal() {
    ['pwdActual','pwdNueva','pwdConfirm'].forEach(id => {
        const el = document.getElementById(id);
        el.value = '';
        el.classList.remove('error');
    });
    ['msgActual','msgNueva','msgConfirm','msgGeneral'].forEach(id => {
        const el = document.getElementById(id);
        el.textContent = '';
        el.className = 'modal-msg';
    });
    document.getElementById('strengthBar').style.width = '0';
    document.getElementById('strengthBar').style.background = '';
    document.getElementById('btnGuardar').disabled = false;
}

function checkStrength() {
    const pwd = document.getElementById('pwdNueva').value;
    const bar = document.getElementById('strengthBar');
    let score = 0;
    if (pwd.length >= 8)  score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    const colors = ['#ef4444','#f59e0b','#10b981','#2b57a7'];
    const widths = ['25%','50%','75%','100%'];
    bar.style.width  = pwd.length ? widths[score - 1] || '10%' : '0';
    bar.style.background = pwd.length ? colors[score - 1] || '#ef4444' : '';
}

async function submitChangePassword() {
    const actual  = document.getElementById('pwdActual').value.trim();
    const nueva   = document.getElementById('pwdNueva').value.trim();
    const confirm = document.getElementById('pwdConfirm').value.trim();
    let ok = true;

    // Reset
    ['pwdActual','pwdNueva','pwdConfirm'].forEach(id => document.getElementById(id).classList.remove('error'));
    ['msgActual','msgNueva','msgConfirm','msgGeneral'].forEach(id => { document.getElementById(id).textContent=''; document.getElementById(id).className='modal-msg'; });

    if (!actual) {
        document.getElementById('pwdActual').classList.add('error');
        document.getElementById('msgActual').textContent = 'Ingresa tu contraseña actual';
        document.getElementById('msgActual').className = 'modal-msg error';
        ok = false;
    }
    if (nueva.length < 8) {
        document.getElementById('pwdNueva').classList.add('error');
        document.getElementById('msgNueva').textContent = 'Mínimo 8 caracteres';
        document.getElementById('msgNueva').className = 'modal-msg error';
        ok = false;
    }
    if (nueva !== confirm) {
        document.getElementById('pwdConfirm').classList.add('error');
        document.getElementById('msgConfirm').textContent = 'Las contraseñas no coinciden';
        document.getElementById('msgConfirm').className = 'modal-msg error';
        ok = false;
    }
    if (!ok) return;

    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    try {
        const res = await fetch('cambiar_password.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ actual, nueva })
        });

        // Leer siempre texto crudo primero para detectar errores PHP/HTML
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch(parseErr) {
            // El servidor no devolvio JSON puro — mostramos la respuesta real
            const msg = document.getElementById('msgGeneral');
            msg.innerHTML = '<b>Error del servidor (HTTP ' + res.status + '):</b><br><code style="font-size:0.72rem;word-break:break-all;white-space:pre-wrap;">' + rawText.substring(0, 500).replace(/</g,'&lt;') + '</code>';
            msg.className = 'modal-msg error';
            btn.disabled = false;
            btn.textContent = 'Guardar cambios';
            return;
        }

        if (data.ok) {
            const msg = document.getElementById('msgGeneral');
            msg.textContent = '✅ Contraseña actualizada correctamente';
            msg.className = 'modal-msg success';
            setTimeout(closeChangePassword, 2000);
        } else {
            document.getElementById('pwdActual').classList.add('error');
            const msg = document.getElementById('msgActual');
            msg.textContent = data.error || 'Contraseña actual incorrecta';
            msg.className = 'modal-msg error';
            btn.disabled = false;
            btn.textContent = 'Guardar cambios';
        }
    } catch(err) {
        const msg = document.getElementById('msgGeneral');
        msg.textContent = 'Error de red: ' + err.message;
        msg.className = 'modal-msg error';
        btn.disabled = false;
        btn.textContent = 'Guardar cambios';
    }
}
</script>
</body>
</html>