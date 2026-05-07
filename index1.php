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
$por_distrito = in_array($rol, ['admin', 'director_regional', 'director_distrital']);
$mostrar_meta = $por_distrito;

// ── INSTALACIONES ──
if ($rol === 'admin') {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-'");
} elseif ($por_distrito) {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query AND origen_prospecto <> '-' AND distrito='$distrito_esc'");
} else {
    if (empty($folio_ids)) { $r_inst = mysqli_query($conexion, "SELECT 0 as total"); } 
    else {
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

// ── VENTAS ──
if ($rol === 'admin') {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query");
} elseif ($por_distrito) {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query AND distrito='$distrito_esc'");
} else {
    if (empty($folio_ids)) { $r_vent = mysqli_query($conexion, "SELECT 0 as total"); } 
    else {
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

// ── HC ──
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

<<<<<<< HEAD
// ── META ACUMULADA ───────────────────────────────────────────────────────────
$dias_transcurridos = (int)date('j') - 1;
$kpi_meta_acum      = 0;
$kpi_meta_pct       = 0;

=======
// ── META ──
$ayer_timestamp = strtotime('-1 day');
$dia_ayer = (int)date('j', $ayer_timestamp);    
$dias_transcurridos = $dia_ayer; 
$kpi_meta_acum = 0; $kpi_meta_pct = 0;
>>>>>>> da9819da629ba29dad74d0b456977aaf06260c91
if ($mostrar_meta) {
    if ($rol === 'admin') {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1");
    } else {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1 AND distrito='$distrito_esc'");
    }
    if ($r_meta && $row_meta = mysqli_fetch_assoc($r_meta)) {
        $meta_diaria_total = (float)($row_meta['meta_diaria_total'] ?? 0);
        $kpi_meta_acum     = round($meta_diaria_total * $dias_transcurridos);
        $kpi_meta_pct      = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
    }
}

// ── MIX Y EVOLUCIÓN (DATOS SIMPLIFICADOS PARA EL EJEMPLO) ──
$inst_3p = 65; $inst_2p = 35; // Datos ejemplo
$vent_3p = 70; $vent_2p = 30; // Datos ejemplo
$meses_labels = ["Nov", "Dic", "Ene", "Feb", "Mar", "Abr"];
$roles_labels = ['admin'=>'Administrador','director_regional'=>'Director Regional','director_distrital'=>'Director Distrital','lider'=>'Líder','coach'=>'Coach','vendedor'=>'Vendedor'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — TOTALXPEDIENT</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
    <style>
        :root { 
            /* PALETA EXTRAÍDA DE Background_2.jpg */
            --bg-deep: #06002E;         /* Azul Noche Profundo */
            --bg-card: #0F0A3D;         /* Azul Tarjeta */
            --magenta: #F90093;         /* Rosa Neón */
            --purple: #6B00D7;          /* Púrpura Vibrante */
            --blue-electric: #0038E0;   /* Azul Eléctrico */
            --cyan: #00C2FF;            /* Cian Brillante */
            --text-main: #FFFFFF;
            --text-dim: #A5B4FC;
            --border: rgba(107, 0, 215, 0.3);
            --sidebar-w: 200px;
        }
        
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Segoe UI',sans-serif; background:var(--bg-deep); color:var(--text-main); display:flex; min-height:100vh; }
        
        /* BARRA LATERAL CON DEGRADADO Background_2.jpg */
        .sidebar { 
            width:var(--sidebar-w); 
            background: linear-gradient(180deg, #06002E 0%, #6B00D7 50%, #F90093 100%);
            min-height:100vh; position:fixed; top:0; left:0; display:flex; flex-direction:column; align-items:center; padding:32px 0; z-index:100;
        }
        .sidebar-logo-text { font-size:3.5rem; margin-bottom:12px; }
        .sidebar-brand { font-size:0.75rem; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; margin-bottom:36px; text-align:center; }
        
        .nav-item { width:100%; display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px 0; color:rgba(255,255,255,0.6); text-decoration:none; font-size:0.78rem; font-weight:600; transition:0.3s; }
        .nav-item:hover, .nav-item.active { color:white; background:rgba(255,255,255,0.1); border-left: 4px solid var(--cyan); }
        .nav-icon { font-size:1.4rem; }
        
        .main { margin-left:var(--sidebar-w); flex:1; padding:32px; }
        .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; }
        .page-header p { color:var(--text-dim); font-size:0.85rem; }

        .kpi-grid { display:grid; grid-template-columns: 1.8fr 1fr 1fr 1fr; gap:20px; margin-bottom:24px; }
        .kpi-card { background:var(--bg-card); border-radius:16px; padding:22px 24px; border:1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        
        .kpi-label { font-size:0.85rem; font-weight:700; color:var(--text-dim); margin-bottom:10px; text-transform:uppercase; }
        .kpi-val { font-size:2.2rem; font-weight:800; letter-spacing:-1px; line-height:1; }
        .kpi-sub { font-size:0.7rem; color:var(--text-dim); margin-top:4px; }
        
        /* COLORES DE VALORES KPI */
        .val-magenta { color: var(--magenta); text-shadow: 0 0 15px rgba(249, 0, 147, 0.4); }
        .val-cyan { color: var(--cyan); text-shadow: 0 0 15px rgba(0, 194, 255, 0.4); }
        .val-purple { color: #A78BFA; }

        /* VELOCÍMETRO NEÓN */
        .speedometer-container { position: relative; width: 220px; height: 110px; overflow: hidden; transform: scale(0.9); transform-origin: left bottom; }
        .speedometer-arco { 
            width: 220px; height: 220px; border-radius: 50%; 
            background: conic-gradient(from -90deg, var(--magenta) 0deg, var(--purple) 90deg, var(--cyan) 180deg, transparent 180deg);
        }
        .speedometer-mask { position: absolute; top: 15px; left: 15px; width: 190px; height: 190px; border-radius: 50%; background: var(--bg-card); }
        .needle { 
            position: absolute; bottom: 0; left: 50%; width: 4px; height: 90px; background: white; 
            transform-origin: bottom center; transition: 1.5s cubic-bezier(0.17, 0.67, 0.83, 0.67); box-shadow: 0 0 10px white;
        }
        .speed-pct { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); font-size: 1.5rem; font-weight: 900; }

        .charts-row { display:grid; grid-template-columns: 2fr 1fr 1fr; gap:20px; }
        .chart-card { background:var(--bg-card); border-radius:16px; padding:20px; border:1px solid var(--border); }
        .chart-title { font-size:0.8rem; font-weight:700; color:var(--text-dim); margin-bottom:15px; text-transform:uppercase; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo-text">🚀</div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="#" class="nav-item active"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="#" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="#" class="nav-item"><span class="nav-icon">📋</span> REAI</a>
</aside>

<main class="main">
    <div class="page-header">
        <div>
            <h2>Dashboard Operativo</h2>
            <p><?= date('d \d\e F Y') ?> — <?= htmlspecialchars($distrito_usuario) ?></p>
        </div>
        <div style="text-align:right">
            <div style="font-weight:bold"><?= htmlspecialchars($nombre_completo) ?></div>
            <div style="font-size:0.7rem; color:var(--cyan)"><?= htmlspecialchars($roles_labels[$rol]) ?></div>
        </div>
    </div>

    <div class="kpi-grid">
        <!-- AVANCE VS META (VELOCÍMETRO) -->
        <div class="kpi-card">
            <div class="kpi-label">Avance vs Meta Mensual</div>
            <div style="display:flex; align-items:flex-end; gap:20px;">
                <div class="speedometer-container">
                    <div class="speedometer-arco"></div>
                    <div class="speedometer-mask"></div>
                    <div class="needle" style="transform: rotate(<?= ($kpi_meta_pct / 100 * 180) - 90 ?>deg)"></div>
                    <div class="speed-pct val-cyan"><?= $kpi_meta_pct ?>%</div>
                </div>
                <div>
                    <div class="kpi-val val-cyan"><?= number_format($kpi_inst) ?></div>
                    <div class="kpi-sub">Instalaciones Reales</div>
                    <div class="kpi-val" style="font-size:1.2rem; margin-top:10px;"><?= number_format($kpi_meta_acum) ?></div>
                    <div class="kpi-sub">Meta Acumulada</div>
                </div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label">Ventas Totales</div>
            <div class="kpi-val val-magenta"><?= number_format($kpi_vent) ?></div>
            <div class="kpi-sub">Cierres de mes</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label">Conversión</div>
            <div class="kpi-val val-purple"><?= $kpi_conv ?>%</div>
            <div class="kpi-sub">Venta a Instalación</div>
        </div>

        <div class="kpi-card">
            <div class="kpi-label">Headcount</div>
            <div class="kpi-val" style="color:var(--text-main)"><?= $kpi_hc_pct ?>%</div>
            <div class="kpi-sub"><?= $kpi_hc_act ?> Activos / <?= $kpi_hc_total ?> Plazas</div>
        </div>
    </div>

    <div class="charts-row">
        <!-- EVOLUCIÓN -->
        <div class="chart-card">
            <div class="chart-title">Evolución Semestral</div>
            <div style="height:250px;"><canvas id="chartEvo"></canvas></div>
        </div>
        <!-- MIX VENTAS -->
        <div class="chart-card">
            <div class="chart-title">Mix Ventas</div>
            <div style="height:200px;"><canvas id="chartMixV"></canvas></div>
        </div>
        <!-- MIX INSTALACIONES -->
        <div class="chart-card">
            <div class="chart-title">Mix Inst.</div>
            <div style="height:200px;"><canvas id="chartMixI"></canvas></div>
        </div>
    </div>
</main>

<script>
    // Configuración Global de Gráficos para modo Oscuro
    Chart.defaults.color = '#A5B4FC';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.1)';

    // Gráfico de Evolución con Colores de la Imagen
    new Chart(document.getElementById('chartEvo'), {
        type: 'line',
        data: {
            labels: <?= json_encode($meses_labels) ?>,
            datasets: [{
                label: 'Instalaciones',
                data: [45, 52, 48, 70, 65, 82],
                borderColor: '#00C2FF',
                backgroundColor: 'rgba(0, 194, 255, 0.1)',
                fill: true,
                tension: 0.4,
                borderWidth: 3
            }, {
                label: 'Ventas',
                data: [60, 58, 62, 85, 80, 95],
                borderColor: '#F90093',
                backgroundColor: 'rgba(249, 0, 147, 0.1)',
                fill: true,
                tension: 0.4,
                borderWidth: 3
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Gráficos de Mix (Donas)
    const donutConfig = (data, colors) => ({
        type: 'doughnut',
        data: {
            labels: ['2P', '3P'],
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('chartMixV'), donutConfig([30, 70], ['#6B00D7', '#F90093']));
    new Chart(document.getElementById('chartMixI'), donutConfig([35, 65], ['#0038E0', '#00C2FF']));
</script>

</body>
</html>