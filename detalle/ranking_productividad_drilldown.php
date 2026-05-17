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

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_num($v, $d=0) { return number_format((float)($v ?? 0), $d); }
function fmt_prod($v) { return ($v === null || $v === '') ? '-' : number_format((float)$v, 2); }
function pct_class($pct) {
    if ($pct === null || $pct === '') return 'flat';
    $n = (float)$pct;
    if ($n >= 5) return 'up';
    if ($n <= -10) return 'down-hard';
    if ($n < 0) return 'down';
    return 'flat';
}
function prod_class($prod) {
    if ($prod === null || $prod === '') return 'muted';
    $p = (float)$prod;
    if ($p >= 4.0) return 'tier-1';
    if ($p >= 3.0) return 'tier-2';
    if ($p >= 2.5) return 'tier-3';
    return 'tier-4';
}
function hc_sin_venta_class($v) {
    $n = (float)$v;
    if ($n <= 2) return 'hc-good';
    if ($n <= 5) return 'hc-mid';
    return 'hc-bad';
}
function qs($arr) { return http_build_query($arr); }
function esc($conexion, $v) { return mysqli_real_escape_string($conexion, (string)$v); }

function table_has_column($conexion, $table, $column) {
    $table = mysqli_real_escape_string($conexion, $table);
    $column = mysqli_real_escape_string($conexion, $column);
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}
function first_existing_column($conexion, $table, $candidates) {
    foreach ($candidates as $c) {
        if (table_has_column($conexion, $table, $c)) return $c;
    }
    return null;
}

function base_metrics_totals($rows) {
    $keys = [
        'ins_sem_base','ins_sem_actual','dif','hc_activo_base','hc_activo_actual',
        'hc_con_ins_base','hc_con_ins_actual','hc_sin_venta_base','hc_sin_venta_actual',
        'activo_base','vacante_base','hc_total_base','activo_actual','vacante_actual','hc_total_actual'
    ];
    $tot = array_fill_keys($keys, 0);
    foreach ($rows as $r) {
        foreach ($keys as $k) $tot[$k] += (float)($r[$k] ?? 0);
    }
    $tot['pct_dif'] = $tot['ins_sem_base'] > 0 ? round((($tot['ins_sem_actual'] - $tot['ins_sem_base']) / $tot['ins_sem_base']) * 100, 0) : null;
    $tot['prod_base'] = $tot['hc_activo_base'] > 0 ? round($tot['ins_sem_base'] / $tot['hc_activo_base'], 2) : null;
    $tot['prod_actual'] = $tot['hc_activo_actual'] > 0 ? round($tot['ins_sem_actual'] / $tot['hc_activo_actual'], 2) : null;
    return $tot;
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

$prev_semana = $semana_actual - 1; $prev_anio = $anio_actual;
if ($prev_semana < 1) { $prev_semana = 52; $prev_anio--; }

$next_semana = $semana_actual + 1; $next_anio = $anio_actual;
if ($next_semana > 53) { $next_semana = 1; $next_anio++; }

$has_prev = isset($semanas_key[$prev_anio.'-'.$prev_semana]);
$has_next = isset($semanas_key[$next_anio.'-'.$next_semana]);

$view = $_GET['view'] ?? 'lideres';
if (!in_array($view, ['lideres','coaches','vendedores','ventas'], true)) $view = 'lideres';

$distrito_param  = $_GET['distrito'] ?? '';
$lider_param     = $_GET['lider'] ?? '';
$coach_param     = $_GET['coach'] ?? '';
$coach_pos_param = $_GET['coach_pos'] ?? '';
$vendedor_param  = $_GET['vendedor'] ?? '';
$folio_param     = $_GET['folio'] ?? '';

$distrito_sql  = esc($conexion, $distrito_param);
$lider_sql     = esc($conexion, $lider_param);
$coach_sql     = esc($conexion, $coach_param);
$coach_pos_sql = esc($conexion, $coach_pos_param);
$folio_sql     = esc($conexion, $folio_param);

$antiguedad_expr = "
    CASE
        WHEN h.fecha_alta IS NULL OR h.fecha_alta = '' OR h.fecha_alta = '0000-00-00' THEN '-'
        WHEN TIMESTAMPDIFF(MONTH, h.fecha_alta, CURDATE()) < 12
            THEN CONCAT(TIMESTAMPDIFF(MONTH, h.fecha_alta, CURDATE()), ' meses')
        ELSE CONCAT(
            FLOOR(TIMESTAMPDIFF(MONTH, h.fecha_alta, CURDATE()) / 12),
            ' años ',
            MOD(TIMESTAMPDIFF(MONTH, h.fecha_alta, CURDATE()), 12),
            ' meses'
        )
    END
";

$segmento_col = first_existing_column($conexion, 'instalaciones', [
    'segmento',
    'tipo_cliente',
    'tipo_venta',
    'mercado',
    'unidad_negocio',
    'negocio',
    'linea_negocio',
    'canal_segmento'
]);

$segmento_select_expr = $segmento_col ? "i.`$segmento_col`" : "''";

$lideres_cte = "
lideres_activos AS (
    SELECT 'CANCUN' AS distrito_reporte, 'CANCUN' AS distrito_hc, 'COTO FELIX ERICK DANIEL' AS lider_hc, 'COTO FELIX ERICK DANIEL' AS lider_instalaciones
    UNION ALL SELECT 'CANCUN', 'CANCUN', 'GAMBOA LARA LUIS ANTONIO', 'GAMBOA LARA LUIS ANTONIO'
    UNION ALL SELECT 'COATZA-MINA', 'COATZA MINA', 'HECTOR ANDRES PALMA HERNANDEZ', 'HECTOR ANDRES PALMA HERNANDEZ'
    UNION ALL SELECT 'MERIDA', 'MERIDA', 'PARAMO AVILA JOVANY DAMIAN', 'JOVANY DAMIAN PARAMO AVILA'
    UNION ALL SELECT 'MERIDA', 'MERIDA', 'PAREDES ROCHEL MARIA JOSE', 'PAREDES ROCHEL MARIA JOSE'
    UNION ALL SELECT 'TUXTLA', 'TUXTLA', 'LOPEZ MANCILLA JOSE ALBERTO', 'JOSE ALBERTO LOPEZ MANCILLA'
    UNION ALL SELECT 'TUXTLA', 'TUXTLA', 'SANCHEZ SANCHEZ CHRISTIANNE MIGUEL', 'CHRISTIANNE MIGUEL SANCHEZ SANCHEZ'
    UNION ALL SELECT 'VILLAHERMOSA', 'VILLAHERMOSA', 'HERNANDEZ PALMA MIRIAN GABRIELA', 'MIRIAN GABRIELA HERNANDEZ PALMA'
)";

$query_error = '';
$rows = [];
$ventas_hist = [];

if ($view === 'lideres') {
$sql = "
WITH {$lideres_cte},
ventas_lider AS (
    SELECT
        la.distrito_reporte AS distrito,
        la.lider_hc AS entidad,
        la.lider_hc AS lider,
        SUM(CASE WHEN YEAR(i.fecha) = {$anio_base} AND WEEK(i.fecha,1) = {$semana_base} THEN 1 ELSE 0 END) AS ins_sem_base,
        SUM(CASE WHEN YEAR(i.fecha) = {$anio_actual} AND WEEK(i.fecha,1) = {$semana_actual} THEN 1 ELSE 0 END) AS ins_sem_actual
    FROM lideres_activos la
    LEFT JOIN instalaciones i
        ON i.lider = la.lider_instalaciones
       AND (
            (YEAR(i.fecha) = {$anio_base} AND WEEK(i.fecha,1) = {$semana_base})
         OR (YEAR(i.fecha) = {$anio_actual} AND WEEK(i.fecha,1) = {$semana_actual})
       )
    GROUP BY la.distrito_reporte, la.lider_hc
),
coaches_lider AS (
    SELECT DISTINCT
        la.distrito_reporte AS distrito,
        la.distrito_hc,
        la.lider_hc AS lider,
        h.nombre_colaborador AS coach,
        h.id_posicion AS coach_pos,
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
vendedores AS (
    SELECT DISTINCT
        c.distrito,
        c.lider,
        h.numero_talento_gs AS folio_empleado,
        h.nombre_colaborador,
        h.id_posicion,
        h.posicion_lr,
        h.semana,
        h.anio
    FROM coaches_lider c
    INNER JOIN hc h
        ON (
            (c.coach <> 'VACANTE' AND h.nombre_linea_reporte = c.coach)
            OR
            (c.coach = 'VACANTE' AND h.posicion_lr = c.coach_pos)
        )
       AND h.distrito = c.distrito_hc
       AND h.semana = c.semana
       AND h.anio = c.anio
       AND h.puesto_lr LIKE '%COACH%'
),
hc_resumen AS (
    SELECT
        v.distrito,
        v.lider,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_base} AND v.semana={$semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_base,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_actual} AND v.semana={$semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_base} AND v.semana={$semana_base} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.posicion_lr END) AS vacante_base,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_actual} AND v.semana={$semana_actual} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.posicion_lr END) AS vacante_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_base} AND v.semana={$semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND ibase.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_base,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_actual} AND v.semana={$semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND iactual.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_actual
    FROM vendedores v
    LEFT JOIN instalaciones ibase ON v.folio_empleado = ibase.folio_empleado AND YEAR(ibase.fecha)={$anio_base} AND WEEK(ibase.fecha,1)={$semana_base}
    LEFT JOIN instalaciones iactual ON v.folio_empleado = iactual.folio_empleado AND YEAR(iactual.fecha)={$anio_actual} AND WEEK(iactual.fecha,1)={$semana_actual}
    GROUP BY v.distrito, v.lider
)
SELECT
    vl.distrito,
    vl.entidad,
    vl.lider,
    '' AS coach,
    '' AS coach_pos,
    '' AS folio_empleado,
    vl.ins_sem_base,
    vl.ins_sem_actual,
    vl.ins_sem_actual - vl.ins_sem_base AS dif,
    ROUND(((vl.ins_sem_actual - vl.ins_sem_base) / NULLIF(vl.ins_sem_base,0)) * 100,0) AS pct_dif,
    COALESCE(h.hc_activo_base,0) AS hc_activo_base,
    COALESCE(h.hc_activo_actual,0) AS hc_activo_actual,
    COALESCE(h.hc_con_ins_base,0) AS hc_con_ins_base,
    COALESCE(h.hc_con_ins_actual,0) AS hc_con_ins_actual,
    COALESCE(h.hc_activo_base,0) - COALESCE(h.hc_con_ins_base,0) AS hc_sin_venta_base,
    COALESCE(h.hc_activo_actual,0) - COALESCE(h.hc_con_ins_actual,0) AS hc_sin_venta_actual,
    ROUND(vl.ins_sem_base / NULLIF(h.hc_activo_base,0),2) AS prod_base,
    ROUND(vl.ins_sem_actual / NULLIF(h.hc_activo_actual,0),2) AS prod_actual,
    COALESCE(h.hc_activo_base,0) AS activo_base,
    COALESCE(h.vacante_base,0) AS vacante_base,
    COALESCE(h.hc_activo_base,0) + COALESCE(h.vacante_base,0) AS hc_total_base,
    COALESCE(h.hc_activo_actual,0) AS activo_actual,
    COALESCE(h.vacante_actual,0) AS vacante_actual,
    COALESCE(h.hc_activo_actual,0) + COALESCE(h.vacante_actual,0) AS hc_total_actual
FROM ventas_lider vl
LEFT JOIN hc_resumen h ON vl.distrito = h.distrito AND vl.lider = h.lider
ORDER BY prod_actual DESC, ins_sem_actual DESC, entidad ASC
";
} elseif ($view === 'coaches') {
$sql = "
WITH {$lideres_cte},
selected_lider AS (
    SELECT *
    FROM lideres_activos
    WHERE distrito_reporte = '{$distrito_sql}'
      AND lider_hc = '{$lider_sql}'
),
coaches_base AS (
    SELECT DISTINCT
        la.distrito_reporte AS distrito,
        la.distrito_hc,
        la.lider_hc AS lider,
        h.nombre_colaborador AS coach,
        h.id_posicion AS coach_pos,
        CONCAT(h.nombre_colaborador, '|', h.id_posicion) AS coach_key,
        h.semana,
        h.anio
    FROM selected_lider la
    INNER JOIN hc h
        ON h.nombre_linea_reporte = la.lider_hc
       AND h.distrito = la.distrito_hc
       AND (
            (h.anio = {$anio_base} AND h.semana = {$semana_base})
         OR (h.anio = {$anio_actual} AND h.semana = {$semana_actual})
       )
       AND h.puesto_lr LIKE '%LIDER%'
),
vendedores AS (
    SELECT DISTINCT
        c.distrito,
        c.distrito_hc,
        c.lider,
        c.coach,
        c.coach_pos,
        c.coach_key,
        h.numero_talento_gs AS folio_empleado,
        h.nombre_colaborador,
        h.id_posicion,
        h.posicion_lr,
        h.semana,
        h.anio
    FROM coaches_base c
    INNER JOIN hc h
        ON (
            (c.coach <> 'VACANTE' AND h.nombre_linea_reporte = c.coach)
            OR
            (c.coach = 'VACANTE' AND h.posicion_lr = c.coach_pos)
        )
       AND h.distrito = c.distrito_hc
       AND h.semana = c.semana
       AND h.anio = c.anio
       AND h.puesto_lr LIKE '%COACH%'
),
resumen AS (
    SELECT
        c.distrito,
        c.lider,
        c.coach AS entidad,
        c.coach,
        c.coach_pos,
        c.coach_key,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_base} AND v.semana={$semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_base,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_actual} AND v.semana={$semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_base} AND v.semana={$semana_base} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.posicion_lr END) AS vacante_base,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_actual} AND v.semana={$semana_actual} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.posicion_lr END) AS vacante_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_base} AND v.semana={$semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND ibase.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_base,
        COUNT(DISTINCT CASE WHEN v.anio={$anio_actual} AND v.semana={$semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND iactual.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_actual,
        COUNT(ibase.cuenta) AS ins_sem_base,
        COUNT(iactual.cuenta) AS ins_sem_actual
    FROM coaches_base c
    LEFT JOIN vendedores v ON c.coach_key = v.coach_key AND c.anio = v.anio AND c.semana = v.semana
    LEFT JOIN instalaciones ibase
        ON v.folio_empleado = ibase.folio_empleado
       AND YEAR(ibase.fecha)={$anio_base}
       AND WEEK(ibase.fecha,1)={$semana_base}
       AND v.anio={$anio_base}
       AND v.semana={$semana_base}
    LEFT JOIN instalaciones iactual
        ON v.folio_empleado = iactual.folio_empleado
       AND YEAR(iactual.fecha)={$anio_actual}
       AND WEEK(iactual.fecha,1)={$semana_actual}
       AND v.anio={$anio_actual}
       AND v.semana={$semana_actual}
    GROUP BY c.distrito, c.lider, c.coach, c.coach_pos, c.coach_key
)
SELECT
    distrito,
    entidad,
    lider,
    coach,
    coach_pos,
    '' AS folio_empleado,
    ins_sem_base,
    ins_sem_actual,
    ins_sem_actual - ins_sem_base AS dif,
    ROUND(((ins_sem_actual - ins_sem_base) / NULLIF(ins_sem_base,0)) * 100,0) AS pct_dif,
    hc_activo_base,
    hc_activo_actual,
    hc_con_ins_base,
    hc_con_ins_actual,
    hc_activo_base - hc_con_ins_base AS hc_sin_venta_base,
    hc_activo_actual - hc_con_ins_actual AS hc_sin_venta_actual,
    ROUND(ins_sem_base / NULLIF(hc_activo_base,0),2) AS prod_base,
    ROUND(ins_sem_actual / NULLIF(hc_activo_actual,0),2) AS prod_actual,
    hc_activo_base AS activo_base,
    vacante_base,
    hc_activo_base + vacante_base AS hc_total_base,
    hc_activo_actual AS activo_actual,
    vacante_actual,
    hc_activo_actual + vacante_actual AS hc_total_actual
FROM resumen
ORDER BY prod_actual DESC, ins_sem_actual DESC, entidad ASC
";
} elseif ($view === 'vendedores') {
$sql = "
WITH vendedores_base AS (
    SELECT DISTINCT
        h.distrito,
        '{$lider_sql}' AS lider,
        '{$coach_sql}' AS coach,
        '{$coach_pos_sql}' AS coach_pos,
        h.nombre_colaborador AS vendedor,
        h.numero_talento_gs AS folio_empleado,
        {$antiguedad_expr} AS antiguedad
    FROM hc h
    WHERE h.distrito = '{$distrito_sql}'
      AND h.puesto_lr LIKE '%COACH%'
      AND h.numero_talento_gs <> 'VACANTE'
      AND h.nombre_colaborador <> 'VACANTE'
      AND (
          ('{$coach_sql}' <> 'VACANTE' AND h.nombre_linea_reporte = '{$coach_sql}')
          OR
          ('{$coach_sql}' = 'VACANTE' AND h.posicion_lr = '{$coach_pos_sql}')
      )
      AND (
            (h.anio = {$anio_base} AND h.semana = {$semana_base})
         OR (h.anio = {$anio_actual} AND h.semana = {$semana_actual})
      )
),
ventas_vendedor AS (
    SELECT
        vb.distrito,
        vb.lider,
        vb.coach,
        vb.coach_pos,
        vb.vendedor,
        vb.folio_empleado,
        vb.antiguedad,
        WEEK(i.fecha,1) AS semana,
        COUNT(i.cuenta) AS ventas,
        SUM(CASE 
                WHEN WEEK(i.fecha,1) = {$semana_actual}
                 AND UPPER(COALESCE(i.plan,'')) LIKE '%TV%' 
                THEN 1 ELSE 0 END) AS triple_play,
        SUM(CASE 
                WHEN WEEK(i.fecha,1) = {$semana_actual}
                 AND UPPER(COALESCE(i.plan,'')) NOT LIKE '%TV%' 
                THEN 1 ELSE 0 END) AS doble_play,
        SUM(CASE 
                WHEN WEEK(i.fecha,1) = {$semana_actual}
                 AND UPPER(COALESCE({$segmento_select_expr},'')) LIKE '%NEGOC%' 
                THEN 1 ELSE 0 END) AS negocios,
        SUM(CASE 
                WHEN WEEK(i.fecha,1) = {$semana_actual}
                 AND UPPER(COALESCE({$segmento_select_expr},'')) NOT LIKE '%NEGOC%' 
                THEN 1 ELSE 0 END) AS residencial
    FROM vendedores_base vb
    LEFT JOIN instalaciones i
        ON i.folio_empleado = vb.folio_empleado
       AND YEAR(i.fecha) = {$anio_actual}
       AND WEEK(i.fecha,1) BETWEEN 1 AND {$semana_actual}
    GROUP BY
        vb.distrito,
        vb.lider,
        vb.coach,
        vb.coach_pos,
        vb.vendedor,
        vb.folio_empleado,
        vb.antiguedad,
        WEEK(i.fecha,1)
)
SELECT
    distrito,
    lider,
    coach,
    coach_pos,
    vendedor,
    folio_empleado,
    antiguedad,
    semana,
    ventas,
    triple_play,
    doble_play,
    negocios,
    residencial
FROM ventas_vendedor
ORDER BY vendedor ASC, semana ASC
";
} else {
$sql = "
SELECT
    WEEK(fecha,1) AS semana,
    COUNT(*) AS ventas
FROM instalaciones
WHERE folio_empleado = '{$folio_sql}'
  AND YEAR(fecha) = {$anio_actual}
  AND WEEK(fecha,1) BETWEEN 1 AND {$semana_actual}
GROUP BY WEEK(fecha,1)
ORDER BY semana ASC
";
}

$res = mysqli_query($conexion, $sql);
$coach_matrix = [];
if (!$res) {
    $query_error = mysqli_error($conexion);
} else {
    if ($view === 'ventas') {
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) $map[(int)$r['semana']] = (int)$r['ventas'];
        for ($w = 1; $w <= $semana_actual; $w++) {
            $ventas_hist[] = ['semana' => $w, 'ventas' => $map[$w] ?? 0];
        }
    } elseif ($view === 'vendedores') {
        while ($r = mysqli_fetch_assoc($res)) {
            $folio = $r['folio_empleado'] ?? '';
            if (!isset($coach_matrix[$folio])) {
                $coach_matrix[$folio] = [
                    'vendedor' => $r['vendedor'] ?? '',
                    'folio_empleado' => $folio,
                    'antiguedad' => $r['antiguedad'] ?? '-',
                    'semanas' => array_fill(1, $semana_actual, 0),
                    'total' => 0,
                    'triple_play' => 0,
                    'doble_play' => 0,
                    'negocios' => 0,
                    'residencial' => 0
                ];
            }
            $sem = (int)($r['semana'] ?? 0);
            $ventas = (int)($r['ventas'] ?? 0);
            if ($sem >= 1 && $sem <= $semana_actual) {
                $coach_matrix[$folio]['semanas'][$sem] = $ventas;
                $coach_matrix[$folio]['total'] += $ventas;
                $coach_matrix[$folio]['triple_play'] += (int)($r['triple_play'] ?? 0);
                $coach_matrix[$folio]['doble_play'] += (int)($r['doble_play'] ?? 0);
                $coach_matrix[$folio]['negocios'] += (int)($r['negocios'] ?? 0);
                $coach_matrix[$folio]['residencial'] += (int)($r['residencial'] ?? 0);
            }
        }
        uasort($coach_matrix, function($a, $b) {
            if ($a['total'] == $b['total']) return strcmp($a['vendedor'], $b['vendedor']);
            return $b['total'] <=> $a['total'];
        });
    } else {
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
}

$tot = base_metrics_totals($rows);
$districts = [];
if ($view === 'lideres') {
    foreach ($rows as $r) if (!in_array($r['distrito'], $districts, true)) $districts[] = $r['distrito'];
    sort($districts);
}

$fecha_label = date('d/m/Y');
$entity_label = $view === 'lideres' ? 'Líder' : ($view === 'coaches' ? 'Coach' : 'Semana');
$title_label = [
    'lideres'    => 'Ranking de Productividad',
    'coaches'    => 'Ranking por Coach',
    'vendedores' => 'Ventas Semanales del Coach',
    'ventas'     => 'Ventas Semanales del Vendedor',
][$view];

$base_link = '?' . qs(['anio'=>$anio_actual, 'semana'=>$semana_actual]);
$lider_link = '?' . qs(['anio'=>$anio_actual, 'semana'=>$semana_actual, 'view'=>'coaches', 'distrito'=>$distrito_param, 'lider'=>$lider_param]);
$coach_link = '?' . qs(['anio'=>$anio_actual, 'semana'=>$semana_actual, 'view'=>'vendedores', 'distrito'=>$distrito_param, 'lider'=>$lider_param, 'coach'=>$coach_param, 'coach_pos'=>$coach_pos_param]);

$subtitle = "Comparativo Semana {$semana_base} vs Semana {$semana_actual} · {$fecha_label} · " . ($roles_labels[$rol] ?? $rol);
if ($view !== 'lideres') $subtitle .= " · Líder: {$lider_param}";
if ($view === 'vendedores' || $view === 'ventas') $subtitle .= " · Coach: {$coach_param}";
if ($view === 'ventas') $subtitle .= " · Vendedor: {$vendedor_param}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title_label) ?> — TOTALXPEDIENT</title>
<style>
:root{--blue:#2b57a7;--blue-dark:#153b82;--bg:#f4f6fb;--white:#fff;--text:#111827;--text2:#64748b;--border:#e2e8f0;--sidebar:200px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh}
.sidebar{width:var(--sidebar);background:linear-gradient(180deg,var(--blue),var(--blue-dark));min-height:100vh;position:fixed;inset:0 auto 0 0;display:flex;flex-direction:column;align-items:center;padding:28px 0;z-index:100}
.sidebar-logo{color:white;font-size:2rem;margin-bottom:6px}.sidebar-brand{color:rgba(255,255,255,.92);font-size:.72rem;font-weight:900;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:32px;text-align:center;padding:0 12px}
.nav-item{width:100%;display:flex;flex-direction:column;align-items:center;gap:4px;padding:14px 0;color:rgba(255,255,255,.68);text-decoration:none;font-size:.78rem;font-weight:700;transition:.2s}.nav-item:hover,.nav-item.active{color:white;background:rgba(255,255,255,.14)}.nav-icon{font-size:1.25rem}.sidebar-bottom{margin-top:auto;width:100%;padding:0 12px}.logout-btn{display:block;text-align:center;padding:10px;border-radius:8px;color:rgba(255,255,255,.65);text-decoration:none;font-size:.78rem;font-weight:700}.logout-btn:hover{background:rgba(255,255,255,.12);color:white}
.main{margin-left:var(--sidebar);flex:1;padding:30px 32px 40px;min-width:0}.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:16px}.page-title h1{font-size:1.55rem;color:#0f1f3d}.page-title p{margin-top:5px;color:var(--text2);font-size:.86rem}.week-pill{display:inline-flex;margin-left:10px;background:#e8f0fe;color:var(--blue);border-radius:999px;padding:5px 12px;font-size:.82rem;font-weight:800}.week-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.week-btn,.week-current,.back-btn{border:1px solid var(--border);background:var(--white);color:var(--blue);text-decoration:none;border-radius:12px;padding:9px 12px;font-size:.82rem;font-weight:800;box-shadow:0 1px 3px rgba(15,23,42,.05)}.week-btn.disabled{opacity:.45;pointer-events:none;color:#94a3b8}.week-current{background:var(--blue);color:white;border-color:var(--blue)}.back-btn{color:#334155}
.breadcrumb-card{background:linear-gradient(135deg,#fff,#f8fbff);border:1px solid var(--border);border-left:5px solid var(--blue);border-radius:16px;padding:14px 16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(15,23,42,.04)}.breadcrumb-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.breadcrumb-title{font-size:.78rem;font-weight:900;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}.breadcrumb-path{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:.92rem;font-weight:900;color:#0f1f3d}.breadcrumb-link,.breadcrumb-current{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:7px 11px;text-decoration:none;border:1px solid #dbe3f0}.breadcrumb-link{background:#f8fafc;color:var(--blue)}.breadcrumb-link:hover{background:#e8f0fe;border-color:#bcd0f5}.breadcrumb-current{background:var(--blue);color:white;border-color:var(--blue)}.breadcrumb-sep{color:#94a3b8;font-weight:900}.level-actions{display:flex;gap:8px;flex-wrap:wrap}.level-action{border:1px solid var(--border);background:white;color:#334155;text-decoration:none;border-radius:12px;padding:8px 11px;font-size:.78rem;font-weight:900;box-shadow:0 1px 3px rgba(15,23,42,.05)}.level-action.primary{background:#e8f0fe;color:var(--blue);border-color:#bcd0f5}.context-chip{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#1e3a8a;font-size:.78rem;font-weight:800}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.card{background:var(--white);border:1px solid var(--border);border-radius:16px;padding:14px 16px;box-shadow:0 2px 8px rgba(15,23,42,.04)}.card .label{color:var(--text2);font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.4px}.card .value{margin-top:4px;font-size:1.45rem;font-weight:900;color:#0f1f3d}.card .hint{margin-top:2px;color:var(--text2);font-size:.78rem}
.filters{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap;background:var(--white);border:1px solid var(--border);border-radius:16px;padding:10px 12px;box-shadow:0 2px 8px rgba(15,23,42,.04)}.filter-label{font-size:.8rem;font-weight:900;color:#334155;margin-right:4px}.filter-btn{border:1px solid #dbe3f0;background:#f8fafc;color:#334155;border-radius:999px;padding:7px 12px;font-size:.78rem;font-weight:900;cursor:pointer}.filter-btn.active,.filter-btn:hover{background:#e8f0fe;color:var(--blue);border-color:#bcd0f5}.counter{margin-left:auto;color:var(--text2);font-size:.78rem;font-weight:800}
.table-card{background:var(--white);border-radius:18px;border:1px solid var(--border);box-shadow:0 2px 10px rgba(15,23,42,.05);overflow:hidden}.table-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--border)}.table-head strong{font-size:.95rem;color:#0f1f3d}.table-head span{color:var(--text2);font-size:.8rem}.table-wrap{overflow:auto}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:.78rem;min-width:1360px}th{position:sticky;top:0;z-index:2;background:var(--blue);color:white;padding:10px 8px;text-align:left;font-weight:900;text-transform:uppercase;letter-spacing:.35px;border-right:1px solid rgba(255,255,255,.25);vertical-align:bottom}th.num,td.num{text-align:right}th.center,td.center{text-align:center}th.group{background:#cfcfd2;color:#111827;text-align:center;border-right:2px solid #0f172a}th.sub-gray{background:#d9d9dc;color:#111827}th.sortable{cursor:pointer;user-select:none}th.sortable .sort-icon{opacity:.65;margin-left:4px;font-size:.72rem}
td{padding:9px 8px;border-bottom:1px solid var(--border);border-right:1px solid #eef2f7;white-space:nowrap}tbody tr:hover td{background:#f8fbff}.clickable{cursor:pointer}.clickable:hover td{background:#eef6ff!important}.rank{display:inline-flex;justify-content:center;align-items:center;min-width:24px;height:24px;border-radius:999px;background:#e8f0fe;color:var(--blue);font-weight:900;padding:0 8px}.entity{font-weight:800;color:#0f1f3d}.district{font-weight:800;color:#334155}.badge{display:inline-block;min-width:36px;padding:3px 8px;border-radius:999px;text-align:center;font-weight:900}.badge.up{background:#bbf7d0;color:#166534}.badge.flat{background:#dbeafe;color:#1d4ed8}.badge.down{background:#fed7aa;color:#9a3412}.badge.down-hard{background:#fecaca;color:#991b1b}
.prod{font-weight:900;border-radius:8px;padding:4px 8px;display:inline-block;min-width:48px;text-align:center}.prod.tier-1{background:#bbf7d0;color:#065f46}.prod.tier-2{background:#fde68a;color:#78350f}.prod.tier-3{background:#fed7aa;color:#9a3412}.prod.tier-4{background:#fecaca;color:#991b1b}.prod.muted{background:#f1f5f9;color:#94a3b8}.hc-indicator{display:inline-block;min-width:36px;padding:3px 8px;border-radius:999px;text-align:center;font-weight:900}.hc-good{background:#bbf7d0;color:#166534}.hc-mid{background:#fde68a;color:#78350f}.hc-bad{background:#fecaca;color:#991b1b}.gray-cell{background:#f1f1f3}.total-row td{background:#f3f4f6!important;color:#111827!important;font-weight:900!important;border-top:2px solid #9ca3af!important}.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:14px;padding:14px 16px;margin-bottom:16px;font-weight:700}
.sales-table{min-width:640px}.sales-table .sales-zero{color:#94a3b8}.sales-table .sales-hit{font-weight:900;color:#065f46}
@media(max-width:1100px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.topbar{flex-direction:column}.week-nav{justify-content:flex-start}.counter{margin-left:0;width:100%}}@media(max-width:760px){:root{--sidebar:0}.sidebar{display:none}.main{margin-left:0;padding:20px}.cards{grid-template-columns:1fr}}

.matrix-sortable{cursor:pointer;user-select:none}
.matrix-sortable .sort-icon{opacity:.65;margin-left:4px;font-size:.72rem}

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
    <div class="sidebar-bottom"><a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a></div>
</aside>

<main class="main">
<section class="topbar">
    <div class="page-title">
        <h1><?= h($title_label) ?> <span class="week-pill">Semana <?= h($semana_actual) ?> · <?= h($anio_actual) ?></span></h1>
        <p><?= h($subtitle) ?></p>
    </div>
    <div class="week-nav">
        <a class="week-btn <?= $has_prev ? '' : 'disabled' ?>" href="?<?= qs(array_merge($_GET, ['anio'=>$prev_anio,'semana'=>$prev_semana])) ?>">← Semana <?= h($prev_semana) ?></a>
        <span class="week-current">Semana <?= h($semana_actual) ?></span>
        <a class="week-btn <?= $has_next ? '' : 'disabled' ?>" href="?<?= qs(array_merge($_GET, ['anio'=>$next_anio,'semana'=>$next_semana])) ?>">Semana <?= h($next_semana) ?> →</a>
    </div>
</section>

<section class="breadcrumb-card">
    <div class="breadcrumb-top">
        <div>
            <div class="breadcrumb-title">Nivel de análisis</div>
            <div class="breadcrumb-path">
                <?php if ($view === 'lideres'): ?>
                    <span class="breadcrumb-current">🏆 Ranking General</span>
                <?php else: ?>
                    <a class="breadcrumb-link" href="<?= h($base_link) ?>">🏆 Ranking General</a>
                    <span class="breadcrumb-sep">›</span>
                    <?php if ($view === 'coaches'): ?>
                        <span class="breadcrumb-current">👤 <?= h($lider_param) ?></span>
                    <?php elseif ($view === 'vendedores'): ?>
                        <a class="breadcrumb-link" href="<?= h($lider_link) ?>">👤 <?= h($lider_param) ?></a>
                        <span class="breadcrumb-sep">›</span>
                        <span class="breadcrumb-current">🧭 <?= h($coach_param) ?></span>
                    <?php else: ?>
                        <a class="breadcrumb-link" href="<?= h($lider_link) ?>">👤 <?= h($lider_param) ?></a>
                        <span class="breadcrumb-sep">›</span>
                        <a class="breadcrumb-link" href="<?= h($coach_link) ?>">🧭 <?= h($coach_param) ?></a>
                        <span class="breadcrumb-sep">›</span>
                        <span class="breadcrumb-current">🧑‍💼 <?= h($vendedor_param) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($view !== 'lideres'): ?><div class="context-chip">Distrito: <?= h($distrito_param) ?></div><?php endif; ?>
        </div>
        <div class="level-actions">
            <?php if ($view !== 'lideres'): ?><a class="level-action" href="<?= h($base_link) ?>">← Ver líderes</a><?php endif; ?>
            <?php if ($view === 'vendedores' || $view === 'ventas'): ?><a class="level-action primary" href="<?= h($lider_link) ?>">← Ver coaches</a><?php endif; ?>
            <?php if ($view === 'ventas'): ?><a class="level-action primary" href="<?= h($coach_link) ?>">← Ver vendedores</a><?php endif; ?>
        </div>
    </div>
</section>

<?php if ($query_error): ?><div class="error">Error al generar ranking: <?= h($query_error) ?></div><?php endif; ?>

<?php if (!in_array($view, ['ventas','vendedores'], true)): ?>
<section class="cards">
    <div class="card"><div class="label">Instalaciones Semana <?= h($semana_actual) ?></div><div class="value" id="kpi-ins-actual"><?= fmt_num($tot['ins_sem_actual']) ?></div><div class="hint">Semana <?= h($semana_base) ?>: <span id="kpi-ins-base"><?= fmt_num($tot['ins_sem_base']) ?></span></div></div>
    <div class="card"><div class="label">Diferencia</div><div class="value" id="kpi-dif"><?= fmt_num($tot['dif']) ?></div><div class="hint"><span id="kpi-pct"><?= $tot['pct_dif'] === null ? '-' : fmt_num($tot['pct_dif']).'%' ?></span> vs semana anterior</div></div>
    <div class="card"><div class="label">Productividad Semana <?= h($semana_actual) ?></div><div class="value" id="kpi-prod-actual"><?= fmt_prod($tot['prod_actual']) ?></div><div class="hint">Semana <?= h($semana_base) ?>: <span id="kpi-prod-base"><?= fmt_prod($tot['prod_base']) ?></span></div></div>
    <div class="card"><div class="label">Headcount Semana <?= h($semana_actual) ?></div><div class="value" id="kpi-hc-total"><?= fmt_num($tot['hc_total_actual']) ?></div><div class="hint">Activos <span id="kpi-activo"><?= fmt_num($tot['activo_actual']) ?></span> · Vacantes <span id="kpi-vacante"><?= fmt_num($tot['vacante_actual']) ?></span></div></div>
</section>

<section class="filters">
    <?php if ($view === 'lideres' && count($districts) > 1): ?>
        <span class="filter-label">Distrito:</span>
        <button class="filter-btn active" data-district="ALL">Todos</button>
        <?php foreach ($districts as $d): ?><button class="filter-btn" data-district="<?= h($d) ?>"><?= h($d) ?></button><?php endforeach; ?>
    <?php else: ?>
        <span class="filter-label">Vista:</span>
        <span class="context-chip"><?= h($title_label) ?></span>
    <?php endif; ?>
    <span class="counter" id="visibleCounter">Mostrando <?= count($rows) ?> registros</span>
</section>

<section class="table-card">
    <div class="table-head">
        <strong><?= h($title_label) ?></strong>
        <span><?= $view === 'lideres' ? 'Click en líder para ver coaches' : ($view === 'coaches' ? 'Click en coach para ver vendedores' : 'Click en vendedor para ver historial semanal') ?></span>
    </div>
    <div class="table-wrap">
        <table id="rankingTable">
            <thead>
                <tr>
                    <th rowspan="2" class="center">#</th>
                    <th rowspan="2">Distrito</th>
                    <th rowspan="2"><?= h($entity_label) ?></th>
                    <th rowspan="2" class="num sortable" data-key="ins_sem_base">INS<br>SEM<?= h($semana_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="ins_sem_actual">INS<br>SEM<?= h($semana_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="dif">Dif. <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="pct_dif">% Dif. <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_activo_base">HC Activo<br>SEM<?= h($semana_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_activo_actual">HC Activo<br>SEM<?= h($semana_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_con_ins_base">HC con INS<br>SEM<?= h($semana_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_con_ins_actual">HC con INS<br>SEM<?= h($semana_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_sin_venta_base">HC sin Venta<br>SEM<?= h($semana_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_sin_venta_actual">HC sin Venta<br>SEM<?= h($semana_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="prod_base">Prod.<br>SEM<?= h($semana_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="prod_actual">Prod.<br>SEM<?= h($semana_actual) ?> <span class="sort-icon">↕</span></th>
                    <th colspan="3" class="group">Head Count SEM <?= h($semana_base) ?></th>
                    <th colspan="3" class="group">Head Count SEM <?= h($semana_actual) ?></th>
                </tr>
                <tr>
                    <th class="num sub-gray">Activo</th><th class="num sub-gray">Vacante</th><th class="num sub-gray">HC</th>
                    <th class="num sub-gray">Activo</th><th class="num sub-gray">Vacante</th><th class="num sub-gray">HC</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank=1; foreach($rows as $r):
                    $href = '';
                    if ($view === 'lideres') {
                        $href = '?' . qs(['anio'=>$anio_actual,'semana'=>$semana_actual,'view'=>'coaches','distrito'=>$r['distrito'],'lider'=>$r['lider']]);
                    } elseif ($view === 'coaches') {
                        $href = '?' . qs(['anio'=>$anio_actual,'semana'=>$semana_actual,'view'=>'vendedores','distrito'=>$r['distrito'],'lider'=>$r['lider'],'coach'=>$r['coach'],'coach_pos'=>$r['coach_pos']]);
                    } elseif ($view === 'vendedores' && !empty($r['folio_empleado'])) {
                        $href = '?' . qs(['anio'=>$anio_actual,'semana'=>$semana_actual,'view'=>'ventas','distrito'=>$r['distrito'],'lider'=>$r['lider'],'coach'=>$r['coach'],'coach_pos'=>$r['coach_pos'],'vendedor'=>$r['entidad'],'folio'=>$r['folio_empleado']]);
                    }
                ?>
                <tr class="data-row <?= $href ? 'clickable' : '' ?>" data-href="<?= h($href) ?>" data-district="<?= h($r['distrito']) ?>"
                    <?php foreach(['ins_sem_base','ins_sem_actual','dif','pct_dif','hc_activo_base','hc_activo_actual','hc_con_ins_base','hc_con_ins_actual','hc_sin_venta_base','hc_sin_venta_actual','prod_base','prod_actual','activo_base','vacante_base','hc_total_base','activo_actual','vacante_actual','hc_total_actual'] as $k): ?>
                    data-<?= h($k) ?>="<?= h($r[$k] ?? 0) ?>"
                    <?php endforeach; ?>>
                    <td class="center"><span class="rank"><?= $rank++ ?></span></td>
                    <td class="district"><?= h($r['distrito']) ?></td>
                    <td class="entity"><?= h($r['entidad']) ?></td>
                    <td class="num"><?= fmt_num($r['ins_sem_base']) ?></td>
                    <td class="num"><?= fmt_num($r['ins_sem_actual']) ?></td>
                    <td class="num"><?= fmt_num($r['dif']) ?></td>
                    <td class="center"><span class="badge <?= pct_class($r['pct_dif']) ?>"><?= $r['pct_dif'] === null ? '-' : fmt_num($r['pct_dif']).'%' ?></span></td>
                    <td class="num"><?= fmt_num($r['hc_activo_base']) ?></td>
                    <td class="num"><?= fmt_num($r['hc_activo_actual']) ?></td>
                    <td class="num"><?= fmt_num($r['hc_con_ins_base']) ?></td>
                    <td class="num"><?= fmt_num($r['hc_con_ins_actual']) ?></td>
                    <td class="num"><span class="hc-indicator <?= hc_sin_venta_class($r['hc_sin_venta_base']) ?>"><?= fmt_num($r['hc_sin_venta_base']) ?></span></td>
                    <td class="num"><span class="hc-indicator <?= hc_sin_venta_class($r['hc_sin_venta_actual']) ?>"><?= fmt_num($r['hc_sin_venta_actual']) ?></span></td>
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
                <tr class="total-row" id="totalRow">
                    <td></td><td></td><td>TOTAL</td>
                    <td class="num" data-total-key="ins_sem_base"><?= fmt_num($tot['ins_sem_base']) ?></td>
                    <td class="num" data-total-key="ins_sem_actual"><?= fmt_num($tot['ins_sem_actual']) ?></td>
                    <td class="num" data-total-key="dif"><?= fmt_num($tot['dif']) ?></td>
                    <td class="center"><span id="total-pct" class="badge <?= pct_class($tot['pct_dif']) ?>"><?= $tot['pct_dif'] === null ? '-' : fmt_num($tot['pct_dif']).'%' ?></span></td>
                    <td class="num" data-total-key="hc_activo_base"><?= fmt_num($tot['hc_activo_base']) ?></td>
                    <td class="num" data-total-key="hc_activo_actual"><?= fmt_num($tot['hc_activo_actual']) ?></td>
                    <td class="num" data-total-key="hc_con_ins_base"><?= fmt_num($tot['hc_con_ins_base']) ?></td>
                    <td class="num" data-total-key="hc_con_ins_actual"><?= fmt_num($tot['hc_con_ins_actual']) ?></td>
                    <td class="num"><span id="total-hc-sin-base" class="hc-indicator <?= hc_sin_venta_class($tot['hc_sin_venta_base']) ?>"><?= fmt_num($tot['hc_sin_venta_base']) ?></span></td>
                    <td class="num"><span id="total-hc-sin-actual" class="hc-indicator <?= hc_sin_venta_class($tot['hc_sin_venta_actual']) ?>"><?= fmt_num($tot['hc_sin_venta_actual']) ?></span></td>
                    <td class="center"><span id="total-prod-base" class="prod <?= prod_class($tot['prod_base']) ?>"><?= fmt_prod($tot['prod_base']) ?></span></td>
                    <td class="center"><span id="total-prod-actual" class="prod <?= prod_class($tot['prod_actual']) ?>"><?= fmt_prod($tot['prod_actual']) ?></span></td>
                    <td class="num gray-cell" data-total-key="activo_base"><?= fmt_num($tot['activo_base']) ?></td>
                    <td class="num gray-cell" data-total-key="vacante_base"><?= fmt_num($tot['vacante_base']) ?></td>
                    <td class="num gray-cell" data-total-key="hc_total_base"><?= fmt_num($tot['hc_total_base']) ?></td>
                    <td class="num gray-cell" data-total-key="activo_actual"><?= fmt_num($tot['activo_actual']) ?></td>
                    <td class="num gray-cell" data-total-key="vacante_actual"><?= fmt_num($tot['vacante_actual']) ?></td>
                    <td class="num gray-cell" data-total-key="hc_total_actual"><?= fmt_num($tot['hc_total_actual']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
<?php elseif ($view === 'vendedores'): ?>
<?php
$total_coach = 0;
$mejor_vendedor = '';
$mejor_total = -1;
foreach ($coach_matrix as $v) {
    $total_coach += (int)$v['total'];
    if ((int)$v['total'] > $mejor_total) {
        $mejor_total = (int)$v['total'];
        $mejor_vendedor = $v['vendedor'];
    }
}
?>
<section class="cards">
    <div class="card"><div class="label">Ventas acumuladas del coach</div><div class="value"><?= fmt_num($total_coach) ?></div><div class="hint">Semana 1 a Semana <?= h($semana_actual) ?></div></div>
    <div class="card"><div class="label">Vendedores considerados</div><div class="value"><?= fmt_num(count($coach_matrix)) ?></div><div class="hint">Estructura SEM<?= h($semana_base) ?> o SEM<?= h($semana_actual) ?></div></div>
    <div class="card"><div class="label">Mejor vendedor</div><div class="value" style="font-size:1.05rem"><?= h($mejor_vendedor ?: '-') ?></div><div class="hint"><?= fmt_num(max(0,$mejor_total)) ?> ventas</div></div>
    <div class="card"><div class="label">Coach</div><div class="value" style="font-size:1.05rem"><?= h($coach_param) ?></div><div class="hint">Líder: <?= h($lider_param) ?></div></div>
</section>

<section class="table-card">
    <div class="table-head">
        <strong>Resumen por vendedor del coach</strong>
        <span>Comparativo SEM<?= h($semana_base) ?> vs SEM<?= h($semana_actual) ?> · Mix comercial SEM<?= h($semana_actual) ?></span>
    </div>
    <div class="table-wrap">
        <table class="sales-table" style="min-width:1280px">
            <thead>
                <tr>
                    <th>Nombre vendedor</th>
                    <th class="center matrix-sortable" data-sort="antiguedad">Antigüedad <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="ins_base">INS<br>SEM<?= h($semana_base) ?> <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="ins_actual">INS<br>SEM<?= h($semana_actual) ?> <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="dif">Dif. <span class="sort-icon">↕</span></th>
                    <th class="center matrix-sortable" data-sort="pct_dif">% Dif. <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="doble">2P <span class="sort-icon">↕</span></th>
                    <th class="center matrix-sortable" data-sort="pct_doble">% 2P <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="triple">3P <span class="sort-icon">↕</span></th>
                    <th class="center matrix-sortable" data-sort="pct_triple">% 3P <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="resid">Resid. <span class="sort-icon">↕</span></th>
                    <th class="center matrix-sortable" data-sort="pct_resid">% Resid. <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="neg">Neg. <span class="sort-icon">↕</span></th>
                    <th class="center matrix-sortable" data-sort="pct_neg">% Neg. <span class="sort-icon">↕</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coach_matrix as $v): ?>
                <?php
                        $ins_base_v = (int)($v['semanas'][$semana_base] ?? 0);
                        $ins_actual_v = (int)($v['semanas'][$semana_actual] ?? 0);
                        $dif_v = $ins_actual_v - $ins_base_v;
                        $pct_v = $ins_base_v > 0 ? round(($dif_v / $ins_base_v) * 100, 0) : null;
                        $total_v = (int)$v['total'];
                        $doble_v = (int)$v['doble_play'];
                        $triple_v = (int)$v['triple_play'];
                        $resid_v = (int)$v['residencial'];
                        $neg_v = (int)$v['negocios'];
                        $pct_doble = $total_v > 0 ? round(($doble_v / $total_v) * 100, 0) : null;
                        $pct_triple = $total_v > 0 ? round(($triple_v / $total_v) * 100, 0) : null;
                        $pct_resid = $total_v > 0 ? round(($resid_v / $total_v) * 100, 0) : null;
                        $pct_neg = $total_v > 0 ? round(($neg_v / $total_v) * 100, 0) : null;
                        $antig_meses = 0;
                        if (is_numeric($v['antiguedad'])) {
                            $antig_meses = (float)$v['antiguedad'];
                        } elseif (preg_match('/(\d+) años (\d+) meses/', (string)$v['antiguedad'], $m)) {
                            $antig_meses = ((int)$m[1] * 12) + (int)$m[2];
                        } elseif (preg_match('/(\d+) meses/', (string)$v['antiguedad'], $m)) {
                            $antig_meses = (int)$m[1];
                        }
                    ?>
                <tr class="matrix-row"
                    data-antiguedad="<?= h($antig_meses) ?>"
                    data-ins_base="<?= h($ins_base_v) ?>"
                    data-ins_actual="<?= h($ins_actual_v) ?>"
                    data-dif="<?= h($dif_v) ?>"
                    data-pct_dif="<?= h($pct_v ?? 0) ?>"
                    data-doble="<?= h($doble_v) ?>"
                    data-pct_doble="<?= h($pct_doble ?? 0) ?>"
                    data-triple="<?= h($triple_v) ?>"
                    data-pct_triple="<?= h($pct_triple ?? 0) ?>"
                    data-resid="<?= h($resid_v) ?>"
                    data-pct_resid="<?= h($pct_resid ?? 0) ?>"
                    data-neg="<?= h($neg_v) ?>"
                    data-pct_neg="<?= h($pct_neg ?? 0) ?>">
                    <td class="entity"><?= h($v['vendedor']) ?></td>
                    <td class="center"><?= h($v['antiguedad']) ?></td>
                    <td class="num"><?= fmt_num($ins_base_v) ?></td>
                    <td class="num"><?= fmt_num($ins_actual_v) ?></td>
                    <td class="num"><?= fmt_num($dif_v) ?></td>
                    <td class="center"><span class="badge <?= pct_class($pct_v) ?>"><?= $pct_v === null ? '-' : fmt_num($pct_v).'%' ?></span></td>
                    <td class="num"><?= fmt_num($doble_v) ?></td>
                    <td class="center"><?= $pct_doble === null ? '-' : fmt_num($pct_doble).'%' ?></td>
                    <td class="num"><?= fmt_num($triple_v) ?></td>
                    <td class="center"><?= $pct_triple === null ? '-' : fmt_num($pct_triple).'%' ?></td>
                    <td class="num"><?= fmt_num($resid_v) ?></td>
                    <td class="center"><?= $pct_resid === null ? '-' : fmt_num($pct_resid).'%' ?></td>
                    <td class="num"><?= fmt_num($neg_v) ?></td>
                    <td class="center"><?= $pct_neg === null ? '-' : fmt_num($pct_neg).'%' ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <?php
                        $t_base = 0; $t_actual = 0; $t_doble = 0; $t_triple = 0; $t_resid = 0; $t_neg = 0;
                        foreach ($coach_matrix as $v) {
                            $t_base += (int)($v['semanas'][$semana_base] ?? 0);
                            $t_actual += (int)($v['semanas'][$semana_actual] ?? 0);
                            $t_doble += (int)($v['doble_play'] ?? 0);
                            $t_triple += (int)($v['triple_play'] ?? 0);
                            $t_resid += (int)($v['residencial'] ?? 0);
                            $t_neg += (int)($v['negocios'] ?? 0);
                        }
                        $t_dif = $t_actual - $t_base;
                        $t_pct = $t_base > 0 ? round(($t_dif / $t_base) * 100, 0) : null;
                    ?>
                    <td>TOTAL</td>
                    <td></td>
                    <td class="num"><?= fmt_num($t_base) ?></td>
                    <td class="num"><?= fmt_num($t_actual) ?></td>
                    <td class="num"><?= fmt_num($t_dif) ?></td>
                    <td class="center"><span class="badge <?= pct_class($t_pct) ?>"><?= $t_pct === null ? '-' : fmt_num($t_pct).'%' ?></span></td>
                    <?php
                        $t_mix = $t_doble + $t_triple;
                        $t_segmento = $t_resid + $t_neg;

                        $pct_t_doble = $t_mix > 0 ? round(($t_doble / $t_mix) * 100, 0) : null;
                        $pct_t_triple = $t_mix > 0 ? round(($t_triple / $t_mix) * 100, 0) : null;

                        $pct_t_resid = $t_segmento > 0 ? round(($t_resid / $t_segmento) * 100, 0) : null;
                        $pct_t_neg = $t_segmento > 0 ? round(($t_neg / $t_segmento) * 100, 0) : null;
                    ?>
                    <td class="num"><?= fmt_num($t_doble) ?></td>
                    <td class="center"><?= $pct_t_doble === null ? '-' : fmt_num($pct_t_doble).'%' ?></td>
                    <td class="num"><?= fmt_num($t_triple) ?></td>
                    <td class="center"><?= $pct_t_triple === null ? '-' : fmt_num($pct_t_triple).'%' ?></td>
                    <td class="num"><?= fmt_num($t_resid) ?></td>
                    <td class="center"><?= $pct_t_resid === null ? '-' : fmt_num($pct_t_resid).'%' ?></td>
                    <td class="num"><?= fmt_num($t_neg) ?></td>
                    <td class="center"><?= $pct_t_neg === null ? '-' : fmt_num($pct_t_neg).'%' ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<?php else: ?>
<?php
$total_ventas_hist = 0;
$best_week = 0;
$best_sales = 0;
foreach ($ventas_hist as $vh) {
    $total_ventas_hist += (int)$vh['ventas'];
    if ((int)$vh['ventas'] > $best_sales) { $best_sales = (int)$vh['ventas']; $best_week = (int)$vh['semana']; }
}
?>
<section class="cards">
    <div class="card"><div class="label">Ventas acumuladas</div><div class="value"><?= fmt_num($total_ventas_hist) ?></div><div class="hint">Semana 1 a Semana <?= h($semana_actual) ?></div></div>
    <div class="card"><div class="label">Mejor semana</div><div class="value">SEM <?= h($best_week) ?></div><div class="hint"><?= fmt_num($best_sales) ?> ventas</div></div>
    <div class="card"><div class="label">Promedio semanal</div><div class="value"><?= $semana_actual > 0 ? fmt_prod($total_ventas_hist / $semana_actual) : '-' ?></div><div class="hint">Año <?= h($anio_actual) ?></div></div>
    <div class="card"><div class="label">Folio empleado</div><div class="value" style="font-size:1.05rem"><?= h($folio_param) ?></div><div class="hint"><?= h($vendedor_param) ?></div></div>
</section>
<section class="table-card">
    <div class="table-head">
        <strong>Ventas semanales del vendedor</strong>
        <span>Semana 1 de <?= h($anio_actual) ?> a Semana <?= h($semana_actual) ?></span>
    </div>
    <div class="table-wrap">
        <table class="sales-table">
            <thead><tr><th class="center">Semana</th><th class="num">Ventas / Instalaciones</th></tr></thead>
            <tbody>
                <?php foreach ($ventas_hist as $vh): ?>
                <tr>
                    <td class="center"><span class="rank">SEM <?= h($vh['semana']) ?></span></td>
                    <td class="num <?= ((int)$vh['ventas'] > 0) ? 'sales-hit' : 'sales-zero' ?>"><?= fmt_num($vh['ventas']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row"><td>TOTAL</td><td class="num"><?= fmt_num($total_ventas_hist) ?></td></tr>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
</main>

<?php if (!in_array($view, ['ventas','vendedores'], true)): ?>
<script>
const table=document.getElementById('rankingTable');
const tbody=table.querySelector('tbody');
const totalRow=document.getElementById('totalRow');
const dataRows=()=>[...tbody.querySelectorAll('tr.data-row')];
let activeDistrict='ALL';
let sortState={key:'prod_actual',dir:'desc'};
function num(v){const n=parseFloat(v);return isNaN(n)?0:n}
function fmt0(n){return Math.round(n).toLocaleString('en-US')}
function fmt2(n){return (Math.round(n*100)/100).toFixed(2)}
function pctClass(n){if(n>=5)return'badge up';if(n<=-10)return'badge down-hard';if(n<0)return'badge down';return'badge flat'}
function prodClass(n){if(n>=4)return'prod tier-1';if(n>=3)return'prod tier-2';if(n>=2.5)return'prod tier-3';return'prod tier-4'}
function hcClass(n){if(n<=2)return'hc-indicator hc-good';if(n<=5)return'hc-indicator hc-mid';return'hc-indicator hc-bad'}
function visibleRows(){return dataRows().filter(r=>r.style.display!=='none')}
function applyFilter(){
 dataRows().forEach(r=>{r.style.display=(activeDistrict==='ALL'||r.dataset.district===activeDistrict)?'':'none'});
 recalc();
}
function recalc(){
 const rows=visibleRows();
 rows.forEach((r,i)=>{const rk=r.querySelector('.rank');if(rk)rk.textContent=i+1});
 const keys=['ins_sem_base','ins_sem_actual','dif','hc_activo_base','hc_activo_actual','hc_con_ins_base','hc_con_ins_actual','hc_sin_venta_base','hc_sin_venta_actual','activo_base','vacante_base','hc_total_base','activo_actual','vacante_actual','hc_total_actual'];
 const t={};keys.forEach(k=>t[k]=0);
 rows.forEach(r=>keys.forEach(k=>t[k]+=num(r.dataset[k])));
 const pct=t.ins_sem_base>0?Math.round(((t.ins_sem_actual-t.ins_sem_base)/t.ins_sem_base)*100):null;
 const pb=t.hc_activo_base>0?t.ins_sem_base/t.hc_activo_base:null;
 const pa=t.hc_activo_actual>0?t.ins_sem_actual/t.hc_activo_actual:null;
 document.querySelectorAll('[data-total-key]').forEach(td=>td.textContent=fmt0(t[td.dataset.totalKey]||0));
 const p=document.getElementById('total-pct');p.textContent=pct===null?'-':fmt0(pct)+'%';p.className=pct===null?'badge flat':pctClass(pct);
 const hb=document.getElementById('total-hc-sin-base');hb.textContent=fmt0(t.hc_sin_venta_base);hb.className=hcClass(t.hc_sin_venta_base);
 const ha=document.getElementById('total-hc-sin-actual');ha.textContent=fmt0(t.hc_sin_venta_actual);ha.className=hcClass(t.hc_sin_venta_actual);
 const tb=document.getElementById('total-prod-base');tb.textContent=pb===null?'-':fmt2(pb);tb.className=pb===null?'prod muted':prodClass(pb);
 const ta=document.getElementById('total-prod-actual');ta.textContent=pa===null?'-':fmt2(pa);ta.className=pa===null?'prod muted':prodClass(pa);
 document.getElementById('kpi-ins-actual').textContent=fmt0(t.ins_sem_actual);
 document.getElementById('kpi-ins-base').textContent=fmt0(t.ins_sem_base);
 document.getElementById('kpi-dif').textContent=fmt0(t.dif);
 document.getElementById('kpi-pct').textContent=pct===null?'-':fmt0(pct)+'%';
 document.getElementById('kpi-prod-actual').textContent=pa===null?'-':fmt2(pa);
 document.getElementById('kpi-prod-base').textContent=pb===null?'-':fmt2(pb);
 document.getElementById('kpi-hc-total').textContent=fmt0(t.hc_total_actual);
 document.getElementById('kpi-activo').textContent=fmt0(t.activo_actual);
 document.getElementById('kpi-vacante').textContent=fmt0(t.vacante_actual);
 const c=document.getElementById('visibleCounter');
 if(c){const label=activeDistrict==='ALL'?'':' · '+activeDistrict;c.textContent='Mostrando '+rows.length+' de '+dataRows().length+' registros'+label}
}
document.querySelectorAll('.filter-btn').forEach(btn=>btn.addEventListener('click',()=>{
 document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
 btn.classList.add('active');activeDistrict=btn.dataset.district||'ALL';applyFilter();
}));
document.querySelectorAll('th.sortable').forEach(th=>th.addEventListener('click',()=>{
 const key=th.dataset.key;const dir=(sortState.key===key&&sortState.dir==='desc')?'asc':'desc';sortState={key,dir};
 const rows=dataRows();rows.sort((a,b)=>dir==='desc'?num(b.dataset[key])-num(a.dataset[key]):num(a.dataset[key])-num(b.dataset[key]));
 rows.forEach(r=>tbody.insertBefore(r,totalRow));
 document.querySelectorAll('.sort-icon').forEach(i=>i.textContent='↕');
 const icon=th.querySelector('.sort-icon');if(icon)icon.textContent=dir==='desc'?'↓':'↑';
 applyFilter();
}));
dataRows().forEach(r=>r.addEventListener('click',()=>{const href=r.dataset.href;if(href)window.location.href=href}));
recalc();
</script>
<?php endif; ?>

<?php if ($view === 'vendedores'): ?>
<script>
(function(){
  const tbody = document.querySelector('.sales-table tbody');
  if(!tbody) return;
  const totalRow = tbody.querySelector('.total-row');
  let state = {key:null, dir:'desc'};
  function n(v){ const x=parseFloat(v); return isNaN(x)?0:x; }
  function rows(){ return [...tbody.querySelectorAll('tr.matrix-row')]; }
  document.querySelectorAll('.matrix-sortable').forEach(th=>{
    th.addEventListener('click',()=>{
      const key = th.dataset.sort;
      const dir = (state.key === key && state.dir === 'desc') ? 'asc' : 'desc';
      state = {key,dir};
      const rs = rows();
      rs.sort((a,b)=> dir === 'desc' ? n(b.dataset[key])-n(a.dataset[key]) : n(a.dataset[key])-n(b.dataset[key]));
      rs.forEach(r=>tbody.insertBefore(r,totalRow));
      document.querySelectorAll('.matrix-sortable .sort-icon').forEach(i=>i.textContent='↕');
      const icon = th.querySelector('.sort-icon');
      if(icon) icon.textContent = dir === 'desc' ? '↓' : '↑';
    });
  });
})();
</script>
<?php endif; ?>

</body>
</html>
