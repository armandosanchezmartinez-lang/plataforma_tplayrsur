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

$rol = $_SESSION['rol'] ?? 'vendedor';
$roles_labels = [
    'admin'              => 'Administrador',
    'director_regional'  => 'Director Regional',
    'director_distrital' => 'Director Distrital',
    'lider'              => 'Líder',
    'coach'              => 'Coach',
    'vendedor'           => 'Vendedor',
];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_num($value, $decimals = 0) {
    if ($value === null || $value === '') return '0';
    return number_format((float)$value, $decimals);
}

function fmt_prod($value) {
    return $value === null ? '-' : number_format((float)$value, 2);
}

function pct_class($pct) {
    $n = (float)str_replace('%', '', (string)$pct);
    if ($n >= 5) return 'up';
    if ($n <= -10) return 'down-hard';
    if ($n < 0) return 'down';
    return 'flat';
}

function prod_class($prod) {
    if ($prod === null) return 'muted';
    $p = (float)$prod;
    if ($p >= 4.0) return 'tier-1';
    if ($p >= 3.0) return 'tier-2';
    if ($p >= 2.5) return 'tier-3';
    return 'tier-4';
}

/* Semanas disponibles */
$semanas = [];
$res_sem = mysqli_query($conexion, "
    SELECT DISTINCT anio, semana
    FROM hc
    WHERE anio IS NOT NULL AND semana IS NOT NULL
    ORDER BY anio DESC, semana DESC
");
while ($res_sem && $row = mysqli_fetch_assoc($res_sem)) {
    $semanas[] = ['anio' => (int)$row['anio'], 'semana' => (int)$row['semana']];
}

$anio_actual = $semanas[0]['anio'] ?? (int)date('Y');
$semana_actual = $semanas[0]['semana'] ?? (int)date('W');

if (isset($_GET['anio'])) $anio_actual = max(2020, min(2100, (int)$_GET['anio']));
if (isset($_GET['semana'])) $semana_actual = max(1, min(53, (int)$_GET['semana']));

$semana_base = $semana_actual - 1;
$anio_base = $anio_actual;
if ($semana_base < 1) {
    $semana_base = 52;
    $anio_base = $anio_actual - 1;
}

$semanas_key = [];
foreach ($semanas as $s) $semanas_key[$s['anio'].'-'.$s['semana']] = true;

$prev_semana = $semana_actual - 1;
$prev_anio = $anio_actual;
if ($prev_semana < 1) { $prev_semana = 52; $prev_anio--; }

$next_semana = $semana_actual + 1;
$next_anio = $anio_actual;
if ($next_semana > 53) { $next_semana = 1; $next_anio++; }

$has_prev = isset($semanas_key[$prev_anio.'-'.$prev_semana]);
$has_next = isset($semanas_key[$next_anio.'-'.$next_semana]);

/* Query Ranking Productividad: compara semana N-1 vs N y ordena por productividad actual desc */
$sql = "
WITH lideres_activos AS (
    SELECT 'CANCUN' AS distrito_reporte, 'CANCUN' AS distrito_hc, 'COTO FELIX ERICK DANIEL' AS lider_hc, 'COTO FELIX ERICK DANIEL' AS lider_instalaciones
    UNION ALL SELECT 'CANCUN', 'CANCUN', 'GAMBOA LARA LUIS ANTONIO', 'GAMBOA LARA LUIS ANTONIO'
    UNION ALL SELECT 'COATZA-MINA', 'COATZA MINA', 'HECTOR ANDRES PALMA HERNANDEZ', 'HECTOR ANDRES PALMA HERNANDEZ'
    UNION ALL SELECT 'MERIDA', 'MERIDA', 'PARAMO AVILA JOVANY DAMIAN', 'JOVANY DAMIAN PARAMO AVILA'
    UNION ALL SELECT 'MERIDA', 'MERIDA', 'PAREDES ROCHEL MARIA JOSE', 'PAREDES ROCHEL MARIA JOSE'
    UNION ALL SELECT 'TUXTLA', 'TUXTLA', 'LOPEZ MANCILLA JOSE ALBERTO', 'JOSE ALBERTO LOPEZ MANCILLA'
    UNION ALL SELECT 'TUXTLA', 'TUXTLA', 'SANCHEZ SANCHEZ CHRISTIANNE MIGUEL', 'CHRISTIANNE MIGUEL SANCHEZ SANCHEZ'
    UNION ALL SELECT 'VILLAHERMOSA', 'VILLAHERMOSA', 'HERNANDEZ PALMA MIRIAN GABRIELA', 'MIRIAN GABRIELA HERNANDEZ PALMA'
),
ventas_lider AS (
    SELECT
        la.distrito_reporte AS distrito,
        la.lider_hc AS lider,
        SUM(CASE WHEN YEAR(i.fecha) = {$anio_base} AND WEEK(i.fecha, 1) = {$semana_base} THEN 1 ELSE 0 END) AS ins_sem_base,
        SUM(CASE WHEN YEAR(i.fecha) = {$anio_actual} AND WEEK(i.fecha, 1) = {$semana_actual} THEN 1 ELSE 0 END) AS ins_sem_actual
    FROM lideres_activos la
    LEFT JOIN instalaciones i
        ON i.lider = la.lider_instalaciones
       AND (
            (YEAR(i.fecha) = {$anio_base} AND WEEK(i.fecha, 1) = {$semana_base})
         OR (YEAR(i.fecha) = {$anio_actual} AND WEEK(i.fecha, 1) = {$semana_actual})
       )
    GROUP BY la.distrito_reporte, la.lider_hc
),
coaches AS (
    SELECT DISTINCT
        la.distrito_reporte AS distrito,
        la.distrito_hc,
        la.lider_hc AS lider,
        h.nombre_colaborador AS coach,
        h.id_posicion,
        h.semana,
        h.anio
    FROM lideres_activos la
    INNER JOIN hc h
        ON h.nombre_linea_reporte = la.lider_hc
       AND h.distrito = la.distrito_hc
       AND (
            (h.anio = {$anio_base} AND h.semana = {$semana_base})
         OR (h.anio = {$anio_actual} AND h.semana = {$semana_actual})
       )
       AND h.puesto_lr LIKE '%LIDER%'
),
vendedores_hc AS (
    SELECT DISTINCT
        c.distrito,
        c.lider,
        h.numero_talento_gs AS folio_empleado,
        h.nombre_colaborador,
        h.id_posicion,
        h.posicion_lr,
        h.semana,
        h.anio
    FROM coaches c
    INNER JOIN hc h
        ON (
            (c.coach <> 'VACANTE' AND h.nombre_linea_reporte = c.coach)
            OR
            (c.coach = 'VACANTE' AND h.posicion_lr = c.id_posicion)
        )
       AND h.distrito = c.distrito_hc
       AND h.semana = c.semana
       AND h.anio = c.anio
       AND h.puesto_lr LIKE '%COACH%'
),
hc_resumen AS (
    SELECT
        vhc.distrito,
        vhc.lider,

        COUNT(DISTINCT CASE
            WHEN vhc.anio = {$anio_base} AND vhc.semana = {$semana_base}
             AND vhc.folio_empleado <> 'VACANTE'
             AND vhc.nombre_colaborador <> 'VACANTE'
            THEN vhc.folio_empleado
        END) AS hc_activo_base,

        COUNT(DISTINCT CASE
            WHEN vhc.anio = {$anio_actual} AND vhc.semana = {$semana_actual}
             AND vhc.folio_empleado <> 'VACANTE'
             AND vhc.nombre_colaborador <> 'VACANTE'
            THEN vhc.folio_empleado
        END) AS hc_activo_actual,

        COUNT(DISTINCT CASE
            WHEN vhc.anio = {$anio_base} AND vhc.semana = {$semana_base}
             AND (vhc.folio_empleado = 'VACANTE' OR vhc.nombre_colaborador = 'VACANTE')
            THEN vhc.posicion_lr
        END) AS vacante_base,

        COUNT(DISTINCT CASE
            WHEN vhc.anio = {$anio_actual} AND vhc.semana = {$semana_actual}
             AND (vhc.folio_empleado = 'VACANTE' OR vhc.nombre_colaborador = 'VACANTE')
            THEN vhc.posicion_lr
        END) AS vacante_actual,

        COUNT(DISTINCT CASE
            WHEN vhc.anio = {$anio_base} AND vhc.semana = {$semana_base}
             AND vhc.folio_empleado <> 'VACANTE'
             AND vhc.nombre_colaborador <> 'VACANTE'
             AND ibase.folio_empleado IS NOT NULL
            THEN vhc.folio_empleado
        END) AS hc_con_ins_base,

        COUNT(DISTINCT CASE
            WHEN vhc.anio = {$anio_actual} AND vhc.semana = {$semana_actual}
             AND vhc.folio_empleado <> 'VACANTE'
             AND vhc.nombre_colaborador <> 'VACANTE'
             AND iactual.folio_empleado IS NOT NULL
            THEN vhc.folio_empleado
        END) AS hc_con_ins_actual

    FROM vendedores_hc vhc

    LEFT JOIN instalaciones ibase
        ON vhc.folio_empleado = ibase.folio_empleado
       AND YEAR(ibase.fecha) = {$anio_base}
       AND WEEK(ibase.fecha, 1) = {$semana_base}

    LEFT JOIN instalaciones iactual
        ON vhc.folio_empleado = iactual.folio_empleado
       AND YEAR(iactual.fecha) = {$anio_actual}
       AND WEEK(iactual.fecha, 1) = {$semana_actual}

    GROUP BY vhc.distrito, vhc.lider
)
SELECT
    v.distrito,
    v.lider,
    v.ins_sem_base,
    v.ins_sem_actual,
    v.ins_sem_actual - v.ins_sem_base AS dif,
    ROUND(((v.ins_sem_actual - v.ins_sem_base) / NULLIF(v.ins_sem_base, 0)) * 100, 0) AS pct_dif,
    COALESCE(h.hc_activo_base, 0) AS hc_activo_base,
    COALESCE(h.hc_activo_actual, 0) AS hc_activo_actual,
    COALESCE(h.hc_con_ins_base, 0) AS hc_con_ins_base,
    COALESCE(h.hc_con_ins_actual, 0) AS hc_con_ins_actual,
    COALESCE(h.hc_activo_base, 0) - COALESCE(h.hc_con_ins_base, 0) AS hc_sin_venta_base,
    COALESCE(h.hc_activo_actual, 0) - COALESCE(h.hc_con_ins_actual, 0) AS hc_sin_venta_actual,
    ROUND(v.ins_sem_base / NULLIF(h.hc_activo_base, 0), 2) AS prod_base,
    ROUND(v.ins_sem_actual / NULLIF(h.hc_activo_actual, 0), 2) AS prod_actual,
    COALESCE(h.hc_activo_base, 0) AS activo_base,
    COALESCE(h.vacante_base, 0) AS vacante_base,
    COALESCE(h.hc_activo_base, 0) + COALESCE(h.vacante_base, 0) AS hc_total_base,
    COALESCE(h.hc_activo_actual, 0) AS activo_actual,
    COALESCE(h.vacante_actual, 0) AS vacante_actual,
    COALESCE(h.hc_activo_actual, 0) + COALESCE(h.vacante_actual, 0) AS hc_total_actual
FROM ventas_lider v
LEFT JOIN hc_resumen h
    ON v.distrito = h.distrito
   AND v.lider = h.lider
ORDER BY prod_actual DESC, ins_sem_actual DESC, lider ASC
";

$res = mysqli_query($conexion, $sql);
$rows = [];
$query_error = '';
if (!$res) {
    $query_error = mysqli_error($conexion);
} else {
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
}

$tot = [
    'ins_sem_base'=>0,'ins_sem_actual'=>0,'dif'=>0,
    'hc_activo_base'=>0,'hc_activo_actual'=>0,
    'hc_con_ins_base'=>0,'hc_con_ins_actual'=>0,
    'hc_sin_venta_base'=>0,'hc_sin_venta_actual'=>0,
    'activo_base'=>0,'vacante_base'=>0,'hc_total_base'=>0,
    'activo_actual'=>0,'vacante_actual'=>0,'hc_total_actual'=>0,
];
foreach ($rows as $r) {
    foreach ($tot as $k => $v) $tot[$k] += (float)($r[$k] ?? 0);
}
$tot['pct_dif'] = $tot['ins_sem_base'] > 0 ? round((($tot['ins_sem_actual'] - $tot['ins_sem_base']) / $tot['ins_sem_base']) * 100, 0) : null;
$tot['prod_base'] = $tot['hc_activo_base'] > 0 ? round($tot['ins_sem_base'] / $tot['hc_activo_base'], 2) : null;
$tot['prod_actual'] = $tot['hc_activo_actual'] > 0 ? round($tot['ins_sem_actual'] / $tot['hc_activo_actual'], 2) : null;

$fecha_label = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking Productividad — TOTALXPEDIENT</title>
    <style>
        :root {
            --blue:#2b57a7;
            --blue-dark:#153b82;
            --bg:#f4f6fb;
            --white:#ffffff;
            --text:#111827;
            --text2:#64748b;
            --border:#e2e8f0;
            --green:#10b981;
            --green-bg:#d1fae5;
            --red:#ef4444;
            --red-bg:#fee2e2;
            --yellow-bg:#fef3c7;
            --orange-bg:#fed7aa;
            --gray:#e5e7eb;
            --sidebar:200px;
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Segoe UI', Arial, sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; }
        .sidebar { width:var(--sidebar); background:linear-gradient(180deg,var(--blue),var(--blue-dark)); min-height:100vh; position:fixed; inset:0 auto 0 0; display:flex; flex-direction:column; align-items:center; padding:28px 0; z-index:100; }
        .sidebar-logo { color:white; font-size:2rem; margin-bottom:6px; }
        .sidebar-brand { color:rgba(255,255,255,0.92); font-size:0.72rem; font-weight:900; letter-spacing:1.2px; text-transform:uppercase; margin-bottom:32px; text-align:center; padding:0 12px; }
        .nav-item { width:100%; display:flex; flex-direction:column; align-items:center; gap:4px; padding:14px 0; color:rgba(255,255,255,0.68); text-decoration:none; font-size:0.78rem; font-weight:700; transition:all 0.2s; }
        .nav-item:hover,.nav-item.active { color:white; background:rgba(255,255,255,0.14); }
        .nav-icon { font-size:1.25rem; }
        .sidebar-bottom { margin-top:auto; width:100%; padding:0 12px; }
        .logout-btn { display:block; text-align:center; padding:10px; border-radius:8px; color:rgba(255,255,255,0.65); text-decoration:none; font-size:0.78rem; font-weight:700; }
        .logout-btn:hover { background:rgba(255,255,255,0.12); color:white; }
        .main { margin-left:var(--sidebar); flex:1; padding:30px 32px 40px; min-width:0; }
        .topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:18px; }
        .page-title h1 { font-size:1.55rem; line-height:1.15; color:#0f1f3d; }
        .page-title p { margin-top:5px; color:var(--text2); font-size:0.86rem; }
        .week-pill { display:inline-flex; align-items:center; gap:6px; margin-left:10px; background:#e8f0fe; color:var(--blue); border-radius:999px; padding:5px 12px; font-size:0.82rem; font-weight:800; }
        .week-nav { display:flex; align-items:center; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
        .week-btn, .week-current { border:1px solid var(--border); background:var(--white); color:var(--blue); text-decoration:none; border-radius:12px; padding:9px 12px; font-size:0.82rem; font-weight:800; box-shadow:0 1px 3px rgba(15,23,42,.05); }
        .week-btn.disabled { opacity:.45; pointer-events:none; color:#94a3b8; }
        .week-current { background:var(--blue); color:white; border-color:var(--blue); }
        .cards { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
        .card { background:var(--white); border:1px solid var(--border); border-radius:16px; padding:14px 16px; box-shadow:0 2px 8px rgba(15,23,42,.04); }
        .card .label { color:var(--text2); font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.4px; }
        .card .value { margin-top:4px; font-size:1.45rem; font-weight:900; color:#0f1f3d; }
        .card .hint { margin-top:2px; color:var(--text2); font-size:.78rem; }
        .table-card { background:var(--white); border-radius:18px; border:1px solid var(--border); box-shadow:0 2px 10px rgba(15,23,42,.05); overflow:hidden; }
        .table-head { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:14px 16px; border-bottom:1px solid var(--border); }
        .table-head strong { font-size:.95rem; color:#0f1f3d; }
        .table-head span { color:var(--text2); font-size:.8rem; }
        .table-wrap { overflow:auto; }
        table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.78rem; min-width:1360px; }
        th { position:sticky; top:0; z-index:2; background:var(--blue); color:white; padding:10px 8px; text-align:left; font-weight:900; text-transform:uppercase; letter-spacing:.35px; border-right:1px solid rgba(255,255,255,.25); vertical-align:bottom; }
        th.num, td.num { text-align:right; }
        th.center, td.center { text-align:center; }
        th.group { background:#cfcfd2; color:#111827; text-align:center; border-right:2px solid #0f172a; }
        th.sub-gray { background:#d9d9dc; color:#111827; }
        td { padding:9px 8px; border-bottom:1px solid var(--border); border-right:1px solid #eef2f7; white-space:nowrap; }
        tbody tr:hover td { background:#f8fbff; }
        .rank { display:inline-flex; justify-content:center; align-items:center; width:24px; height:24px; border-radius:999px; background:#e8f0fe; color:var(--blue); font-weight:900; }
        .leader { font-weight:800; color:#0f1f3d; }
        .district { font-weight:800; color:#334155; }
        .badge { display:inline-block; min-width:36px; padding:3px 8px; border-radius:999px; text-align:center; font-weight:900; }
        .badge.up { background:var(--green-bg); color:#065f46; }
        .badge.down { background:var(--yellow-bg); color:#92400e; }
        .badge.down-hard { background:var(--red-bg); color:#991b1b; }
        .badge.flat { background:#e2e8f0; color:#334155; }
        .prod { font-weight:900; border-radius:8px; padding:4px 8px; display:inline-block; min-width:48px; text-align:center; }
        .prod.tier-1 { background:var(--green-bg); color:#065f46; }
        .prod.tier-2 { background:#fde68a; color:#78350f; }
        .prod.tier-3 { background:var(--orange-bg); color:#9a3412; }
        .prod.tier-4 { background:var(--red-bg); color:#991b1b; }
        .prod.muted { background:#f1f5f9; color:#94a3b8; }
        .gray-cell { background:#f1f1f3; }
        .total-row td { background:#f8fafc; font-weight:900; border-top:2px solid #0f172a; }
        .error { background:var(--red-bg); color:#991b1b; border:1px solid #fecaca; border-radius:14px; padding:14px 16px; margin-bottom:16px; font-weight:700; }
        @media (max-width: 1100px) {
            .cards { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .topbar { flex-direction:column; }
            .week-nav { justify-content:flex-start; }
        }
        @media (max-width: 760px) {
            :root { --sidebar:0px; }
            .sidebar { display:none; }
            .main { margin-left:0; padding:20px; }
            .cards { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-logo">📊</div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="ranking_productividad.php" class="nav-item active"><span class="nav-icon">🏆</span> Ranking</a>
    <a href="hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="reai.php" class="nav-item"><span class="nav-icon">📋</span> REAI</a>
    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <section class="topbar">
        <div class="page-title">
            <h1>Ranking de Productividad <span class="week-pill">Semana <?= h($semana_actual) ?> · <?= h($anio_actual) ?></span></h1>
            <p>Comparativo Semana <?= h($semana_base) ?> vs Semana <?= h($semana_actual) ?> · <?= h($fecha_label) ?> · <?= h($roles_labels[$rol] ?? $rol) ?></p>
        </div>
        <div class="week-nav">
            <a class="week-btn <?= $has_prev ? '' : 'disabled' ?>" href="?anio=<?= $prev_anio ?>&semana=<?= $prev_semana ?>">← Semana <?= h($prev_semana) ?></a>
            <span class="week-current">Semana <?= h($semana_actual) ?></span>
            <a class="week-btn <?= $has_next ? '' : 'disabled' ?>" href="?anio=<?= $next_anio ?>&semana=<?= $next_semana ?>">Semana <?= h($next_semana) ?> →</a>
        </div>
    </section>

    <?php if ($query_error): ?>
        <div class="error">Error al generar ranking: <?= h($query_error) ?></div>
    <?php endif; ?>

    <section class="cards">
        <div class="card">
            <div class="label">Instalaciones Semana <?= h($semana_actual) ?></div>
            <div class="value"><?= fmt_num($tot['ins_sem_actual']) ?></div>
            <div class="hint">Semana <?= h($semana_base) ?>: <?= fmt_num($tot['ins_sem_base']) ?></div>
        </div>
        <div class="card">
            <div class="label">Diferencia</div>
            <div class="value"><?= fmt_num($tot['dif']) ?></div>
            <div class="hint"><?= $tot['pct_dif'] === null ? '-' : fmt_num($tot['pct_dif']).'%' ?> vs semana anterior</div>
        </div>
        <div class="card">
            <div class="label">Productividad Semana <?= h($semana_actual) ?></div>
            <div class="value"><?= fmt_prod($tot['prod_actual']) ?></div>
            <div class="hint">Semana <?= h($semana_base) ?>: <?= fmt_prod($tot['prod_base']) ?></div>
        </div>
        <div class="card">
            <div class="label">Headcount Semana <?= h($semana_actual) ?></div>
            <div class="value"><?= fmt_num($tot['hc_total_actual']) ?></div>
            <div class="hint">Activos <?= fmt_num($tot['activo_actual']) ?> · Vacantes <?= fmt_num($tot['vacante_actual']) ?></div>
        </div>
    </section>

    <section class="table-card">
        <div class="table-head">
            <strong>Ranking por Líder</strong>
            <span>Ordenado de mayor a menor productividad de Semana <?= h($semana_actual) ?></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" class="center">#</th>
                        <th rowspan="2">Distrito</th>
                        <th rowspan="2">Líder</th>
                        <th rowspan="2" class="num">INS<br>SEM<?= h($semana_base) ?></th>
                        <th rowspan="2" class="num">INS<br>SEM<?= h($semana_actual) ?></th>
                        <th rowspan="2" class="num">Dif.</th>
                        <th rowspan="2" class="center">% Dif.</th>
                        <th rowspan="2" class="num">HC Activo<br>SEM<?= h($semana_base) ?></th>
                        <th rowspan="2" class="num">HC Activo<br>SEM<?= h($semana_actual) ?></th>
                        <th rowspan="2" class="num">HC con INS<br>SEM<?= h($semana_base) ?></th>
                        <th rowspan="2" class="num">HC con INS<br>SEM<?= h($semana_actual) ?></th>
                        <th rowspan="2" class="num">HC sin Venta<br>SEM<?= h($semana_base) ?></th>
                        <th rowspan="2" class="num">HC sin Venta<br>SEM<?= h($semana_actual) ?></th>
                        <th rowspan="2" class="center">Prod.<br>SEM<?= h($semana_base) ?></th>
                        <th rowspan="2" class="center">Prod.<br>SEM<?= h($semana_actual) ?></th>
                        <th colspan="3" class="group">Head Count SEM <?= h($semana_base) ?></th>
                        <th colspan="3" class="group">Head Count SEM <?= h($semana_actual) ?></th>
                    </tr>
                    <tr>
                        <th class="num sub-gray">Activo</th>
                        <th class="num sub-gray">Vacante</th>
                        <th class="num sub-gray">HC</th>
                        <th class="num sub-gray">Activo</th>
                        <th class="num sub-gray">Vacante</th>
                        <th class="num sub-gray">HC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td class="center"><span class="rank"><?= $rank++ ?></span></td>
                        <td class="district"><?= h($r['distrito']) ?></td>
                        <td class="leader"><?= h($r['lider']) ?></td>
                        <td class="num"><?= fmt_num($r['ins_sem_base']) ?></td>
                        <td class="num"><?= fmt_num($r['ins_sem_actual']) ?></td>
                        <td class="num"><?= fmt_num($r['dif']) ?></td>
                        <td class="center"><span class="badge <?= pct_class($r['pct_dif']) ?>"><?= $r['pct_dif'] === null ? '-' : fmt_num($r['pct_dif']).'%' ?></span></td>
                        <td class="num"><?= fmt_num($r['hc_activo_base']) ?></td>
                        <td class="num"><?= fmt_num($r['hc_activo_actual']) ?></td>
                        <td class="num"><?= fmt_num($r['hc_con_ins_base']) ?></td>
                        <td class="num"><?= fmt_num($r['hc_con_ins_actual']) ?></td>
                        <td class="num"><?= fmt_num($r['hc_sin_venta_base']) ?></td>
                        <td class="num"><?= fmt_num($r['hc_sin_venta_actual']) ?></td>
                        <td class="center"><span class="prod <?= prod_class($r['prod_base']) ?>"><?= fmt_prod($r['prod_base']) ?></span></td>
                        <td class="center"><span class="prod <?= prod_class($r['prod_actual']) ?>"><?= fmt_prod($r['prod_actual']) ?></span></td>
                        <td class="num gray-cell"><?= fmt_num($r['activo_base']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($r['vacante_base']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($r['hc_total_base']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($r['activo_actual']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($r['vacante_actual']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($r['hc_total_actual']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td></td>
                        <td></td>
                        <td>TOTAL</td>
                        <td class="num"><?= fmt_num($tot['ins_sem_base']) ?></td>
                        <td class="num"><?= fmt_num($tot['ins_sem_actual']) ?></td>
                        <td class="num"><?= fmt_num($tot['dif']) ?></td>
                        <td class="center"><span class="badge <?= pct_class($tot['pct_dif']) ?>"><?= $tot['pct_dif'] === null ? '-' : fmt_num($tot['pct_dif']).'%' ?></span></td>
                        <td class="num"><?= fmt_num($tot['hc_activo_base']) ?></td>
                        <td class="num"><?= fmt_num($tot['hc_activo_actual']) ?></td>
                        <td class="num"><?= fmt_num($tot['hc_con_ins_base']) ?></td>
                        <td class="num"><?= fmt_num($tot['hc_con_ins_actual']) ?></td>
                        <td class="num"><?= fmt_num($tot['hc_sin_venta_base']) ?></td>
                        <td class="num"><?= fmt_num($tot['hc_sin_venta_actual']) ?></td>
                        <td class="center"><span class="prod <?= prod_class($tot['prod_base']) ?>"><?= fmt_prod($tot['prod_base']) ?></span></td>
                        <td class="center"><span class="prod <?= prod_class($tot['prod_actual']) ?>"><?= fmt_prod($tot['prod_actual']) ?></span></td>
                        <td class="num gray-cell"><?= fmt_num($tot['activo_base']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($tot['vacante_base']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($tot['hc_total_base']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($tot['activo_actual']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($tot['vacante_actual']) ?></td>
                        <td class="num gray-cell"><?= fmt_num($tot['hc_total_actual']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
