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

$rol         = $_SESSION['rol'] ?? 'vendedor';
$tgs         = mysqli_real_escape_string($conexion, $_GET['tgs'] ?? '');

if (!$tgs) {
    header("Location: reai_v2.php");
    exit();
}

// ── DATOS DEL VENDEDOR (última semana en que aparece) ────────────────────────
$info = null;
$res_info = mysqli_query($conexion,
    "SELECT nombre_colaborador, numero_talento_gs, distrito, fecha_alta,
            nombre_linea_reporte, posicion, semana, anio,
            TIMESTAMPDIFF(MONTH, fecha_alta, CURDATE()) as antiguedad_meses,
            TIMESTAMPDIFF(YEAR,  fecha_alta, CURDATE()) as antiguedad_anios
     FROM hc
     WHERE numero_talento_gs = '$tgs'
     ORDER BY anio DESC, semana DESC
     LIMIT 1");
if ($res_info) $info = mysqli_fetch_assoc($res_info);

if (!$info) {
    header("Location: reai_v2.php");
    exit();
}

$nombre      = $info['nombre_colaborador'];
$distrito    = $info['distrito'];
$fecha_alta  = $info['fecha_alta'];
$coach       = $info['nombre_linea_reporte'];
$ant_meses   = (int)$info['antiguedad_meses'];
$ant_anios   = (int)$info['antiguedad_anios'];
$ant_txt     = $ant_anios > 0 ? "{$ant_anios} año(s) " . ($ant_meses % 12) . " mes(es)" : "{$ant_meses} mes(es)";

// ── 18 SEMANAS ───────────────────────────────────────────────────────────────
// Generar últimas 18 semanas ISO desde hoy hacia atrás
$semanas = [];
for ($i = 19; $i >= 0; $i--) {
    $ts   = strtotime("-{$i} weeks");
    $anio = (int)date('o', $ts); // año ISO
    $sem  = (int)date('W', $ts); // semana ISO
    $semanas[] = ['anio' => $anio, 'sem' => $sem, 'label' => "S{$sem}/{$anio}"];
}

// ── VENTAS DEL VENDEDOR POR SEMANA ───────────────────────────────────────────
// Traer todas las ventas de las últimas 18 semanas con su id_cuenta_brm
$fecha_inicio = date('Y-m-d', strtotime('-18 weeks'));
$res_ventas = mysqli_query($conexion,
    "SELECT id_cuenta_brm, fecha_cierre,
            YEAR(fecha_cierre)  as anio,
            WEEK(fecha_cierre, 3) as semana
     FROM ventas
     WHERE folio_empleado = '$tgs'
     AND fecha_cierre >= '$fecha_inicio'
     ORDER BY fecha_cierre");

$ventas_raw = [];
while ($row = mysqli_fetch_assoc($res_ventas)) {
    $ventas_raw[] = $row;
}

// ── MATCH CON INSTALACIONES ──────────────────────────────────────────────────
$cuentas = array_unique(array_column($ventas_raw, 'id_cuenta_brm'));
$instaladas = [];
if (!empty($cuentas)) {
    $ph = implode(',', array_fill(0, count($cuentas), '?'));
    $stmt = mysqli_prepare($conexion, "SELECT DISTINCT cuenta FROM instalaciones WHERE cuenta IN ($ph)");
    $tipos = str_repeat('s', count($cuentas));
    mysqli_stmt_bind_param($stmt, $tipos, ...array_values($cuentas));
    mysqli_stmt_execute($stmt);
    $res_inst = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res_inst)) {
        $instaladas[$row['cuenta']] = true;
    }
    mysqli_stmt_close($stmt);
}

// ── AGRUPAR POR SEMANA ───────────────────────────────────────────────────────
$data_instalado    = [];
$data_no_instalado = [];
$labels            = [];

foreach ($semanas as $s) {
    $labels[] = $s['label'];
    $inst = 0; $no_inst = 0;
    foreach ($ventas_raw as $v) {
        if ((int)$v['anio'] === $s['anio'] && (int)$v['semana'] === $s['sem']) {
            if (isset($instaladas[$v['id_cuenta_brm']])) {
                $inst++;
            } else {
                $no_inst++;
            }
        }
    }
    $data_instalado[]    = $inst;
    $data_no_instalado[] = $no_inst;
}

// Totales por semana (línea)
$data_total = array_map(fn($a,$b) => $a+$b, $data_instalado, $data_no_instalado);

// KPIs resumen
$total_ventas    = array_sum($data_total);
$total_instalado = array_sum($data_instalado);
$pct_inst        = $total_ventas > 0 ? round(($total_instalado / $total_ventas) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nombre) ?> — Seguimiento</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
    <style>
        :root { --blue:#2b57a7; --bg:#f4f6fb; --white:#fff; --text:#1a2540; --text2:#6b7a99; --border:#e2e8f4; --green:#10b981; --red:#ef4444; --orange:#f59e0b; --sidebar:200px; }
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
        .main { margin-left:var(--sidebar); flex:1; padding:32px; }
        .back-btn { display:inline-flex; align-items:center; gap:6px; color:var(--blue); font-size:0.82rem; font-weight:700; text-decoration:none; margin-bottom:20px; }
        .back-btn:hover { opacity:0.7; }

        /* TARJETA VENDEDOR */
        .vendedor-card { background:var(--white); border-radius:16px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:24px 28px; margin-bottom:24px; display:flex; align-items:center; gap:28px; }
        .vendedor-avatar { width:64px; height:64px; border-radius:50%; background:var(--blue); color:white; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:800; flex-shrink:0; }
        .vendedor-info { flex:1; }
        .vendedor-nombre { font-size:1.3rem; font-weight:800; margin-bottom:4px; }
        .vendedor-pos { font-size:0.82rem; color:var(--text2); margin-bottom:12px; }
        .vendedor-meta { display:flex; flex-wrap:wrap; gap:20px; }
        .meta-item { display:flex; flex-direction:column; }
        .meta-label { font-size:0.68rem; color:var(--text2); text-transform:uppercase; letter-spacing:0.5px; font-weight:700; margin-bottom:3px; }
        .meta-val { font-size:0.88rem; font-weight:700; color:var(--text); }

        /* KPI CHIPS */
        .kpi-row { display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
        .kpi-chip { background:var(--white); border-radius:12px; border:1px solid var(--border); padding:16px 20px; flex:1; min-width:140px; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
        .kpi-chip-label { font-size:0.72rem; color:var(--text2); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
        .kpi-chip-val { font-size:1.8rem; font-weight:800; letter-spacing:-1px; }
        .c-blue   { color:#2b57a7; }
        .c-green  { color:var(--green); }
        .c-orange { color:var(--orange); }

        /* GRÁFICA */
        .chart-card { background:var(--white); border-radius:16px; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:24px 28px; }
        .chart-title { font-size:0.9rem; font-weight:700; margin-bottom:4px; }
        .chart-sub { font-size:0.75rem; color:var(--text2); margin-bottom:20px; }
        .chart-wrap { position:relative; height:320px; }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-logo">📊</div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="../detalle/hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="reai.php" class="nav-item"><span class="nav-icon">📋</span> REAI</a>
    <a href="reai_v2.php" class="nav-item active"><span class="nav-icon">📊</span> Seguimiento</a>
    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <a href="reai1.php" class="back-btn">← Volver al seguimiento</a>

    <!-- TARJETA VENDEDOR -->
    <div class="vendedor-card">
        <div class="vendedor-avatar"><?= strtoupper(substr($nombre, 0, 1)) ?></div>
        <div class="vendedor-info">
            <div class="vendedor-nombre"><?= htmlspecialchars($nombre) ?></div>
            <div class="vendedor-pos"><?= htmlspecialchars($info['posicion'] ?? '') ?></div>
            <div class="vendedor-meta">
                <div class="meta-item">
                    <span class="meta-label"># Empleado</span>
                    <span class="meta-val"><?= htmlspecialchars($tgs) ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Distrito</span>
                    <span class="meta-val"><?= htmlspecialchars($distrito) ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Fecha Ingreso</span>
                    <span class="meta-val"><?= $fecha_alta ? date('d/m/Y', strtotime($fecha_alta)) : '—' ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Antigüedad</span>
                    <span class="meta-val"><?= $ant_txt ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Coach</span>
                    <span class="meta-val"><?= htmlspecialchars($coach ?? '—') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi-chip">
            <div class="kpi-chip-label">Ventas (18 sem)</div>
            <div class="kpi-chip-val c-blue"><?= number_format($total_ventas) ?></div>
        </div>
        <div class="kpi-chip">
            <div class="kpi-chip-label">Instaladas</div>
            <div class="kpi-chip-val c-green"><?= number_format($total_instalado) ?></div>
        </div>
        <div class="kpi-chip">
            <div class="kpi-chip-label">No instaladas</div>
            <div class="kpi-chip-val c-orange"><?= number_format($total_ventas - $total_instalado) ?></div>
        </div>
        <div class="kpi-chip">
            <div class="kpi-chip-label">% Instalación</div>
            <div class="kpi-chip-val" style="color:<?= $pct_inst >= 80 ? 'var(--green)' : ($pct_inst >= 60 ? 'var(--orange)' : 'var(--red)') ?>"><?= $pct_inst ?>%</div>
        </div>
    </div>

    <!-- GRÁFICA -->
    <div class="chart-card">
        <div class="chart-title">Evolución semanal — Ventas vs Instalaciones</div>
        <div class="chart-sub">Últimas 18 semanas · Barras apiladas: Instalado / No instalado · Línea: Total ventas</div>
        <div class="chart-wrap"><canvas id="cVendedor"></canvas></div>
    </div>
</main>

<script>
const labels  = <?= json_encode($labels) ?>;
const instData   = <?= json_encode($data_instalado) ?>;
const noInstData = <?= json_encode($data_no_instalado) ?>;
const totalData  = <?= json_encode($data_total) ?>;


Chart.register(ChartDataLabels);

new Chart(document.getElementById('cVendedor'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Instalado',
                data: instData,
                backgroundColor: '#10b981',
                borderRadius: 0,
                stack: 'ventas',
                order: 2,
            },
            {
                label: 'No instalado',
                data: noInstData,
                backgroundColor: '#f59e0b',
                borderRadius: 4,
                stack: 'ventas',
                order: 2,
            },
            {
                label: 'Total ventas',
                data: totalData,
                type: 'line',
                borderColor: '#2b57a7',
                backgroundColor: 'rgba(43,87,167,0.1)',
                borderWidth: 2.5,
                pointBackgroundColor: '#2b57a7',
                pointRadius: 4,
                tension: 0.3,
                fill: false,
                stack: undefined,
                order: 1,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 14, boxWidth: 14 } },
            datalabels: {
                display: (ctx) => ctx.dataset.type !== 'line' && ctx.dataset.data[ctx.dataIndex] > 0,
                color: '#fff',
                font: { size: 10, weight: 'bold' },
                formatter: Math.round,
                anchor: 'center',
                align: 'center',
            },
            tooltip: {
                callbacks: {
                    afterBody: (items) => {
                        const inst = items.find(i => i.dataset.label === 'Instalado')?.parsed.y ?? 0;
                        const total = items.find(i => i.dataset.label === 'Total ventas')?.parsed.y ?? 0;
                        const pct = total > 0 ? ((inst/total)*100).toFixed(1) : 0;
                        return [`% Instalación: ${pct}%`];
                    }
                }
            }
        },
        scales: {
            x: { stacked: true, grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 45 } },
            y: { stacked: true, beginAtZero: true, grid: { color: '#e2e8f4' }, ticks: { font: { size: 11 } } }
        }
    }
});
</script>
</body>
</html>