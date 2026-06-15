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
    header("Location: reai.php");
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
    header("Location: reai.php");
    exit();
}

$nombre      = $info['nombre_colaborador'];
$distrito    = $info['distrito'];
$fecha_alta  = $info['fecha_alta'];
$coach       = $info['nombre_linea_reporte'];
$ant_meses   = (int)$info['antiguedad_meses'];
$ant_anios   = (int)$info['antiguedad_anios'];
$ant_txt     = $ant_anios > 0 ? "{$ant_anios} año(s) " . ($ant_meses % 12) . " mes(es)" : "{$ant_meses} mes(es)";

// ── MODO DE GRÁFICA: SEMANAL / MENSUAL ───────────────────────────────────────
// Si viene desde REAI mensual, mostrar evolución mensual de instalaciones.
// Si viene desde REAI semanal o no trae parámetro, conservar comportamiento semanal actual.
$periodo = $_GET['periodo'] ?? 'semanal';
if (!in_array($periodo, ['semanal','mensual'], true)) $periodo = 'semanal';

$labels            = [];
$data_instalado    = [];
$data_no_instalado = [];

if ($periodo === 'mensual') {
    // ── VISTA MENSUAL: Enero 2026 a la fecha, agrupada por mes ───────────────
    $fecha_inicio = '2026-01-01';
    $fecha_fin    = date('Y-m-d');

    $meses_es = [1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];

    // Generar meses desde enero 2026 hasta el mes actual
    $cursor = new DateTime($fecha_inicio);
    $limite = new DateTime(date('Y-m-01'));
    $meses = [];
    while ($cursor <= $limite) {
        $anio_m = (int)$cursor->format('Y');
        $mes_m  = (int)$cursor->format('n');
        $key    = $cursor->format('Y-m');
        $meses[] = ['key'=>$key, 'anio'=>$anio_m, 'mes'=>$mes_m, 'label'=>$meses_es[$mes_m].'/'.$anio_m];
        $cursor->modify('+1 month');
    }

    // Ventas del vendedor desde 01/01/2026
    $res_ventas = mysqli_query($conexion,
        "SELECT id_cuenta_brm, fecha_cierre,
                DATE_FORMAT(fecha_cierre, '%Y-%m') as mes_key
         FROM ventas
         WHERE folio_empleado = '$tgs'
           AND fecha_cierre BETWEEN '$fecha_inicio' AND '$fecha_fin'
         ORDER BY fecha_cierre");

    $ventas_raw = [];
    while ($res_ventas && $row = mysqli_fetch_assoc($res_ventas)) {
        $ventas_raw[] = $row;
    }

    // Match contra instalaciones por cuenta
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

    foreach ($meses as $m) {
        $labels[] = $m['label'];
        $inst = 0; $no_inst = 0;
        foreach ($ventas_raw as $v) {
            if ($v['mes_key'] === $m['key']) {
                if (isset($instaladas[$v['id_cuenta_brm']])) $inst++;
                else $no_inst++;
            }
        }
        $data_instalado[]    = $inst;
        $data_no_instalado[] = $no_inst;
    }

    $chart_title = 'Evolución mensual — Ventas vs Instalaciones';
    $chart_sub   = 'Enero 2026 a la fecha · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
    $kpi_period_label = 'Mensual 2026';

} else {
    // ── VISTA SEMANAL: conservar comportamiento actual ───────────────────────
    // Generar últimas 18 semanas ISO desde hoy hacia atrás
    $semanas = [];
    for ($i = 19; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} weeks");
        $anio = (int)date('o', $ts); // año ISO
        $sem  = (int)date('W', $ts); // semana ISO
        $semanas[] = ['anio' => $anio, 'sem' => $sem, 'label' => "S{$sem}/{$anio}"];
    }

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
    while ($res_ventas && $row = mysqli_fetch_assoc($res_ventas)) {
        $ventas_raw[] = $row;
    }

    // Match contra instalaciones por cuenta
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

    foreach ($semanas as $s) {
        $labels[] = $s['label'];
        $inst = 0; $no_inst = 0;
        foreach ($ventas_raw as $v) {
            if ((int)$v['anio'] === $s['anio'] && (int)$v['semana'] === $s['sem']) {
                if (isset($instaladas[$v['id_cuenta_brm']])) $inst++;
                else $no_inst++;
            }
        }
        $data_instalado[]    = $inst;
        $data_no_instalado[] = $no_inst;
    }

    $chart_title = 'Evolución semanal — Ventas vs Instalaciones';
    $chart_sub   = 'Últimas 18 semanas · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
    $kpi_period_label = '18 sem';
}

// Totales del modo activo
$data_total = array_map(fn($a,$b) => $a+$b, $data_instalado, $data_no_instalado);
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
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=161">
</head>
<body class="page-vendedor">
<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/img/logo-xpedient.png?v=3" alt="Xpedient">
    </div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="ranking_productividad.php" class="nav-item"><span class="nav-icon">🏆</span> Ranking</a>
    <a href="hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="reai.php" class="nav-item active"><span class="nav-icon">📋</span> REAI</a>
    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <a href="reai.php?periodo=<?= urlencode($periodo) ?>" class="back-btn">← Volver al seguimiento</a>

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
            <div class="kpi-chip-label">Ventas (<?= htmlspecialchars($kpi_period_label) ?>)</div>
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
        <div class="chart-title"><?= htmlspecialchars($chart_title) ?></div>
        <div class="chart-sub"><?= htmlspecialchars($chart_sub) ?></div>
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