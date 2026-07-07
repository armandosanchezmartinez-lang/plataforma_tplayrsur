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

$rol     = $_SESSION['rol'] ?? 'vendedor';
$tgs     = mysqli_real_escape_string($conexion, $_GET['tgs'] ?? '');
$periodo = $_GET['periodo'] ?? 'semanal';
if (!in_array($periodo, ['semanal','mensual'], true)) $periodo = 'semanal';

if (!$tgs) {
    header("Location: reai.php");
    exit();
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function normaliza_detalle_reai($v) {
    $v = strtoupper(trim((string)$v));
    $v = str_replace(['Á','É','Í','Ó','Ú','Ñ'], ['A','E','I','O','U','N'], $v);
    $v = preg_replace('/[^A-Z0-9]+/', ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}

function distrito_equivalentes_detalle_reai($distrito) {
    $d = trim((string)$distrito);
    $arr = [$d];
    if ($d === 'COATZA MINA') $arr[] = 'COATZA / MINA';
    if ($d === 'COATZA / MINA') $arr[] = 'COATZA MINA';
    return array_values(array_unique(array_filter($arr, fn($x) => trim((string)$x) !== '')));
}

function sql_in_escaped_detalle_reai($conexion, $vals) {
    $vals = array_values(array_unique(array_filter($vals, fn($x) => trim((string)$x) !== '')));
    if (empty($vals)) return "''";
    return "'" . implode("','", array_map(fn($v) => mysqli_real_escape_string($conexion, (string)$v), $vals)) . "'";
}

function es_director_distrital_detalle_reai($posicion) {
    return strpos(normaliza_detalle_reai($posicion), 'DIRECTOR DISTRITAL') !== false;
}

function es_lider_detalle_reai($posicion) {
    $p = normaliza_detalle_reai($posicion);
    return strpos($p, 'LIDER') !== false || strpos($p, 'GERENTE') !== false;
}

function es_coach_detalle_reai($posicion) {
    return strpos(normaliza_detalle_reai($posicion), 'COACH') !== false;
}

function es_vendedor_detalle_reai($posicion) {
    $p = trim((string)$posicion);
    return in_array($p, ['PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'], true);
}

function obtener_folios_vendedores_descendientes_detalle_reai($conexion, $id_posicion_root, $semana, $anio) {
    $out = [];
    if (trim((string)$id_posicion_root) === '' || !$semana || !$anio) return $out;

    $rows = [];
    $children = [];
    $sql = "SELECT id_posicion, posicion_lr, numero_talento_gs, posicion, nombre_colaborador
            FROM hc
            WHERE semana = ? AND anio = ?
              AND numero_talento_gs NOT LIKE '%VACANTE%'
              AND nombre_colaborador NOT LIKE '%VACANTE%'";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return $out;
    mysqli_stmt_bind_param($stmt, "ii", $semana, $anio);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $r['id_posicion'] = (string)($r['id_posicion'] ?? '');
        $r['posicion_lr'] = (string)($r['posicion_lr'] ?? '');
        $rows[] = $r;
        $children[$r['posicion_lr']][] = $r;
    }
    mysqli_stmt_close($stmt);

    $walk = function($id_pos) use (&$walk, &$children, &$out) {
        foreach ($children[(string)$id_pos] ?? [] as $child) {
            if (es_vendedor_detalle_reai($child['posicion'] ?? '')) {
                if (!empty($child['numero_talento_gs'])) $out[] = (string)$child['numero_talento_gs'];
            } else {
                $walk($child['id_posicion'] ?? '');
            }
        }
    };
    $walk((string)$id_posicion_root);
    return array_values(array_unique(array_filter($out)));
}

// ── DATOS DEL COLABORADOR / DIRECTOR ─────────────────────────────────────────
$info = null;
$res_info = mysqli_query($conexion,
    "SELECT nombre_colaborador, numero_talento_gs, distrito, fecha_alta,
            nombre_linea_reporte, posicion, id_posicion, semana, anio,
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

$nombre      = $info['nombre_colaborador'] ?? '';
$distrito    = $info['distrito'] ?? '';
$fecha_alta  = $info['fecha_alta'] ?? null;
$coach       = $info['nombre_linea_reporte'] ?? '';
$posicion    = $info['posicion'] ?? '';
$id_posicion_info = (string)($info['id_posicion'] ?? '');
$semana_info = (int)($info['semana'] ?? 0);
$anio_info = (int)($info['anio'] ?? 0);
$es_director = es_director_distrital_detalle_reai($posicion);
$es_lider = es_lider_detalle_reai($posicion);
$es_coach = es_coach_detalle_reai($posicion);
$es_equipo = (!$es_director && ($es_lider || $es_coach));
$folios_equipo = $es_equipo ? obtener_folios_vendedores_descendientes_detalle_reai($conexion, $id_posicion_info, $semana_info, $anio_info) : [];
$folios_equipo_sql = $es_equipo ? sql_in_escaped_detalle_reai($conexion, $folios_equipo) : "''";
$ant_meses   = (int)($info['antiguedad_meses'] ?? 0);
$ant_anios   = (int)($info['antiguedad_anios'] ?? 0);
$ant_txt     = $ant_anios > 0 ? "{$ant_anios} año(s) " . ($ant_meses % 12) . " mes(es)" : "{$ant_meses} mes(es)";

$labels            = [];
$data_instalado    = [];
$data_no_instalado = [];

if ($periodo === 'mensual') {
    $fecha_inicio = '2026-01-01';
    $fecha_fin    = date('Y-m-d');
    $meses_es = [1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];

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

    if ($es_director) {
        $dsql = sql_in_escaped_detalle_reai($conexion, distrito_equivalentes_detalle_reai($distrito));

        $ventas_mes = [];
        $rv = mysqli_query($conexion, "
            SELECT DATE_FORMAT(fecha_cierre, '%Y-%m') AS mes_key, COUNT(*) AS total
            FROM ventas
            WHERE fecha_cierre BETWEEN '$fecha_inicio' AND '$fecha_fin'
              AND distrito IN ($dsql)
            GROUP BY DATE_FORMAT(fecha_cierre, '%Y-%m')
        ");
        while ($rv && $row = mysqli_fetch_assoc($rv)) $ventas_mes[$row['mes_key']] = (int)$row['total'];

        $inst_mes = [];
        $ri = mysqli_query($conexion, "
            SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes_key, COUNT(cuenta) AS total
            FROM instalaciones
            WHERE fecha BETWEEN '$fecha_inicio' AND '$fecha_fin'
              AND origen_prospecto IS NOT NULL
              AND origen_prospecto <> '-'
              AND distrito IN ($dsql)
            GROUP BY DATE_FORMAT(fecha, '%Y-%m')
        ");
        while ($ri && $row = mysqli_fetch_assoc($ri)) $inst_mes[$row['mes_key']] = (int)$row['total'];

        foreach ($meses as $m) {
            $labels[] = $m['label'];
            $ventas = (int)($ventas_mes[$m['key']] ?? 0);
            $inst   = (int)($inst_mes[$m['key']] ?? 0);
            $data_instalado[]    = $inst;
            $data_no_instalado[] = max(0, $ventas - $inst);
        }

        $chart_title = 'Evolución mensual del distrito — Ventas vs Instalaciones';
        $chart_sub   = 'Enero 2026 a la fecha · Cálculo por distrito · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
        $kpi_period_label = 'Mensual 2026';
    } elseif ($es_equipo) {
        $ventas_mes = [];
        if (!empty($folios_equipo)) {
            $rv = mysqli_query($conexion, "
                SELECT DATE_FORMAT(fecha_cierre, '%Y-%m') AS mes_key, COUNT(*) AS total
                FROM ventas
                WHERE fecha_cierre BETWEEN '$fecha_inicio' AND '$fecha_fin'
                  AND folio_empleado IN ($folios_equipo_sql)
                GROUP BY DATE_FORMAT(fecha_cierre, '%Y-%m')
            ");
            while ($rv && $row = mysqli_fetch_assoc($rv)) $ventas_mes[$row['mes_key']] = (int)$row['total'];
        }

        $inst_mes = [];
        if (!empty($folios_equipo)) {
            $ri = mysqli_query($conexion, "
                SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes_key, COUNT(cuenta) AS total
                FROM instalaciones
                WHERE fecha BETWEEN '$fecha_inicio' AND '$fecha_fin'
                  AND origen_prospecto IS NOT NULL
                  AND origen_prospecto <> '-'
                  AND folio_empleado IN ($folios_equipo_sql)
                GROUP BY DATE_FORMAT(fecha, '%Y-%m')
            ");
            while ($ri && $row = mysqli_fetch_assoc($ri)) $inst_mes[$row['mes_key']] = (int)$row['total'];
        }

        foreach ($meses as $m) {
            $labels[] = $m['label'];
            $ventas = (int)($ventas_mes[$m['key']] ?? 0);
            $inst   = (int)($inst_mes[$m['key']] ?? 0);
            $data_instalado[]    = $inst;
            $data_no_instalado[] = max(0, $ventas - $inst);
        }

        $nivel_txt = $es_lider ? 'línea del líder' : 'línea del coach';
        $chart_title = 'Evolución mensual de la ' . $nivel_txt . ' — Ventas vs Instalaciones';
        $chart_sub   = 'Enero 2026 a la fecha · Cálculo por vendedores activos a cargo · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
        $kpi_period_label = 'Mensual 2026';
    } else {
        $res_ventas = mysqli_query($conexion,
            "SELECT id_cuenta_brm, fecha_cierre,
                    DATE_FORMAT(fecha_cierre, '%Y-%m') as mes_key
             FROM ventas
             WHERE folio_empleado = '$tgs'
               AND fecha_cierre BETWEEN '$fecha_inicio' AND '$fecha_fin'
             ORDER BY fecha_cierre");

        $ventas_raw = [];
        while ($res_ventas && $row = mysqli_fetch_assoc($res_ventas)) $ventas_raw[] = $row;

        $cuentas = array_unique(array_column($ventas_raw, 'id_cuenta_brm'));
        $instaladas = [];
        if (!empty($cuentas)) {
            $ph = implode(',', array_fill(0, count($cuentas), '?'));
            $stmt = mysqli_prepare($conexion, "SELECT DISTINCT cuenta FROM instalaciones WHERE cuenta IN ($ph)");
            $tipos = str_repeat('s', count($cuentas));
            mysqli_stmt_bind_param($stmt, $tipos, ...array_values($cuentas));
            mysqli_stmt_execute($stmt);
            $res_inst = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res_inst)) $instaladas[$row['cuenta']] = true;
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
    }
} else {
    $semanas = [];
    for ($i = 19; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} weeks");
        $anio = (int)date('o', $ts);
        $sem  = (int)date('W', $ts);
        $key  = sprintf('%04d%02d', $anio, $sem);
        $semanas[] = ['anio' => $anio, 'sem' => $sem, 'key'=>$key, 'label' => "S{$sem}/{$anio}"];
    }
    $fecha_inicio = date('Y-m-d', strtotime('-20 weeks'));

    if ($es_director) {
        $dsql = sql_in_escaped_detalle_reai($conexion, distrito_equivalentes_detalle_reai($distrito));

        $ventas_sem = [];
        $rv = mysqli_query($conexion, "
            SELECT YEARWEEK(fecha_cierre, 3) AS yw, COUNT(*) AS total
            FROM ventas
            WHERE fecha_cierre >= '$fecha_inicio'
              AND distrito IN ($dsql)
            GROUP BY YEARWEEK(fecha_cierre, 3)
        ");
        while ($rv && $row = mysqli_fetch_assoc($rv)) $ventas_sem[(string)$row['yw']] = (int)$row['total'];

        $inst_sem = [];
        $ri = mysqli_query($conexion, "
            SELECT YEARWEEK(fecha, 3) AS yw, COUNT(cuenta) AS total
            FROM instalaciones
            WHERE fecha >= '$fecha_inicio'
              AND origen_prospecto IS NOT NULL
              AND origen_prospecto <> '-'
              AND distrito IN ($dsql)
            GROUP BY YEARWEEK(fecha, 3)
        ");
        while ($ri && $row = mysqli_fetch_assoc($ri)) $inst_sem[(string)$row['yw']] = (int)$row['total'];

        foreach ($semanas as $s) {
            $labels[] = $s['label'];
            $ventas = (int)($ventas_sem[$s['key']] ?? 0);
            $inst   = (int)($inst_sem[$s['key']] ?? 0);
            $data_instalado[]    = $inst;
            $data_no_instalado[] = max(0, $ventas - $inst);
        }

        $chart_title = 'Evolución semanal del distrito — Ventas vs Instalaciones';
        $chart_sub   = 'Últimas 20 semanas · Cálculo por distrito · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
        $kpi_period_label = '20 sem';
    } elseif ($es_equipo) {
        $ventas_sem = [];
        if (!empty($folios_equipo)) {
            $rv = mysqli_query($conexion, "
                SELECT YEARWEEK(fecha_cierre, 3) AS yw, COUNT(*) AS total
                FROM ventas
                WHERE fecha_cierre >= '$fecha_inicio'
                  AND folio_empleado IN ($folios_equipo_sql)
                GROUP BY YEARWEEK(fecha_cierre, 3)
            ");
            while ($rv && $row = mysqli_fetch_assoc($rv)) $ventas_sem[(string)$row['yw']] = (int)$row['total'];
        }

        $inst_sem = [];
        if (!empty($folios_equipo)) {
            $ri = mysqli_query($conexion, "
                SELECT YEARWEEK(fecha, 3) AS yw, COUNT(cuenta) AS total
                FROM instalaciones
                WHERE fecha >= '$fecha_inicio'
                  AND origen_prospecto IS NOT NULL
                  AND origen_prospecto <> '-'
                  AND folio_empleado IN ($folios_equipo_sql)
                GROUP BY YEARWEEK(fecha, 3)
            ");
            while ($ri && $row = mysqli_fetch_assoc($ri)) $inst_sem[(string)$row['yw']] = (int)$row['total'];
        }

        foreach ($semanas as $s) {
            $labels[] = $s['label'];
            $ventas = (int)($ventas_sem[$s['key']] ?? 0);
            $inst   = (int)($inst_sem[$s['key']] ?? 0);
            $data_instalado[]    = $inst;
            $data_no_instalado[] = max(0, $ventas - $inst);
        }

        $nivel_txt = $es_lider ? 'línea del líder' : 'línea del coach';
        $chart_title = 'Evolución semanal de la ' . $nivel_txt . ' — Ventas vs Instalaciones';
        $chart_sub   = 'Últimas 20 semanas · Cálculo por vendedores activos a cargo · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
        $kpi_period_label = '20 sem';
    } else {
        $res_ventas = mysqli_query($conexion,
            "SELECT id_cuenta_brm, fecha_cierre,
                    YEARWEEK(fecha_cierre, 3) as yw
             FROM ventas
             WHERE folio_empleado = '$tgs'
               AND fecha_cierre >= '$fecha_inicio'
             ORDER BY fecha_cierre");

        $ventas_raw = [];
        while ($res_ventas && $row = mysqli_fetch_assoc($res_ventas)) $ventas_raw[] = $row;

        $cuentas = array_unique(array_column($ventas_raw, 'id_cuenta_brm'));
        $instaladas = [];
        if (!empty($cuentas)) {
            $ph = implode(',', array_fill(0, count($cuentas), '?'));
            $stmt = mysqli_prepare($conexion, "SELECT DISTINCT cuenta FROM instalaciones WHERE cuenta IN ($ph)");
            $tipos = str_repeat('s', count($cuentas));
            mysqli_stmt_bind_param($stmt, $tipos, ...array_values($cuentas));
            mysqli_stmt_execute($stmt);
            $res_inst = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res_inst)) $instaladas[$row['cuenta']] = true;
            mysqli_stmt_close($stmt);
        }

        foreach ($semanas as $s) {
            $labels[] = $s['label'];
            $inst = 0; $no_inst = 0;
            foreach ($ventas_raw as $v) {
                if ((string)$v['yw'] === (string)$s['key']) {
                    if (isset($instaladas[$v['id_cuenta_brm']])) $inst++;
                    else $no_inst++;
                }
            }
            $data_instalado[]    = $inst;
            $data_no_instalado[] = $no_inst;
        }

        $chart_title = 'Evolución semanal — Ventas vs Instalaciones';
        $chart_sub   = 'Últimas 20 semanas · Barras apiladas: Instalado / No instalado · Línea: Total ventas';
        $kpi_period_label = '20 sem';
    }
}

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
    <title><?= h($nombre) ?> — Seguimiento</title>
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
    <?php $nivel_back = $es_director ? 'DIRECTOR DISTRITAL' : ($es_lider ? 'LÍDER' : ($es_coach ? 'COACH' : 'VENDEDOR')); ?>
    <a href="reai.php?periodo=<?= urlencode($periodo) ?>&nivel=<?= urlencode($nivel_back) ?>" class="back-btn">← Volver al seguimiento</a>

    <div class="vendedor-card">
        <div class="vendedor-avatar"><?= strtoupper(substr($nombre, 0, 1)) ?></div>
        <div class="vendedor-info">
            <div class="vendedor-nombre"><?= h($nombre) ?></div>
            <div class="vendedor-pos"><?= h($posicion) ?></div>
            <div class="vendedor-meta">
                <div class="meta-item">
                    <span class="meta-label"># Empleado</span>
                    <span class="meta-val"><?= h($tgs) ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Distrito</span>
                    <span class="meta-val"><?= h($distrito) ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Fecha Ingreso</span>
                    <span class="meta-val"><?= $fecha_alta ? date('d/m/Y', strtotime($fecha_alta)) : '—' ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Antigüedad</span>
                    <span class="meta-val"><?= h($ant_txt) ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label"><?= ($es_director || $es_lider || $es_coach) ? 'Línea reporte' : 'Coach' ?></span>
                    <span class="meta-val"><?= h($coach ?: '—') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-chip">
            <div class="kpi-chip-label">Ventas (<?= h($kpi_period_label) ?>)</div>
            <div class="kpi-chip-val c-blue"><?= number_format($total_ventas) ?></div>
        </div>
        <div class="kpi-chip">
            <div class="kpi-chip-label">Instaladas</div>
            <div class="kpi-chip-val c-green"><?= number_format($total_instalado) ?></div>
        </div>
        <div class="kpi-chip">
            <div class="kpi-chip-label">No instaladas</div>
            <div class="kpi-chip-val c-orange"><?= number_format(max(0, $total_ventas - $total_instalado)) ?></div>
        </div>
        <div class="kpi-chip">
            <div class="kpi-chip-label">% Instalación</div>
            <div class="kpi-chip-val" style="color:<?= $pct_inst >= 80 ? 'var(--green)' : ($pct_inst >= 60 ? 'var(--orange)' : 'var(--red)') ?>"><?= $pct_inst ?>%</div>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-title"><?= h($chart_title) ?></div>
        <div class="chart-sub"><?= h($chart_sub) ?></div>
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
