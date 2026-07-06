

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

    // Productividad diaria homologada.
    // Ajustable según criterio comercial:
    // Verde >= 0.50, Amarillo >= 0.40, Naranja >= 0.30, Rojo < 0.30
    if ($p >= 0.70) return 'tier-1';
    if ($p >= 0.55) return 'tier-2';
    if ($p >= 0.40) return 'tier-3';
    return 'tier-4';
}
function hc_sin_venta_class($v) {
    $n = (float)$v;
    if ($n <= 2) return 'hc-good';
    if ($n <= 5) return 'hc-mid';
    return 'hc-bad';
}
function pct_hc_sin_ins_class($pct) {
    if ($pct === null || $pct === '') return 'flat';
    $p = (float)$pct;
    if ($p <= 5) return 'up';
    if ($p <= 10) return 'flat';
    return 'down-hard';
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

function table_exists($conexion, $table) {
    $table = mysqli_real_escape_string($conexion, $table);
    $res = mysqli_query($conexion, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}

function fecha_iso_semana_inicio($anio, $semana) {
    $d = new DateTime();
    $d->setISODate((int)$anio, (int)$semana, 1); // lunes ISO
    return $d;
}

function contar_dias_habiles($conexion, $fecha_inicio, $fecha_fin) {
    if (!$fecha_inicio || !$fecha_fin) return 1;

    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);

    if ($inicio > $fin) {
        $tmp = $inicio;
        $inicio = $fin;
        $fin = $tmp;
    }

    $festivos = [];
    if (table_exists($conexion, 'dias_inhabiles')) {
        $fi = mysqli_real_escape_string($conexion, $inicio->format('Y-m-d'));
        $ff = mysqli_real_escape_string($conexion, $fin->format('Y-m-d'));
        $sql = "
            SELECT fecha
            FROM dias_inhabiles
            WHERE activo = 1
              AND fecha BETWEEN '$fi' AND '$ff'
        ";
        $res = mysqli_query($conexion, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $festivos[$row['fecha']] = true;
            }
        }
    }

    $habiles = 0;
    for ($d = clone $inicio; $d <= $fin; $d->modify('+1 day')) {
        $fecha = $d->format('Y-m-d');
        $dia_semana = (int)$d->format('N'); // 7 = domingo

        if ($dia_semana === 7) continue;       // domingo inhábil
        if (isset($festivos[$fecha])) continue; // festivo oficial/interno inhábil

        $habiles++;
    }

    return max(1, $habiles);
}

function contar_dias_habiles_semana_seleccionada($conexion, $anio, $semana, $dias_iso) {
    if (empty($dias_iso)) return 1;

    $lunes = fecha_iso_semana_inicio($anio, $semana);
    $fechas_seleccionadas = [];

    foreach ($dias_iso as $dia_iso) {
        $dia_iso = (int)$dia_iso;
        if ($dia_iso < 1 || $dia_iso > 7) continue; // 1=LUN ... 7=DOM

        // Domingo puede seleccionarse para filtrar ventas, pero NO cuenta como día hábil.
        if ($dia_iso === 7) continue;

        $fecha = clone $lunes;
        $fecha->modify('+'.($dia_iso - 1).' days');
        $fechas_seleccionadas[$fecha->format('Y-m-d')] = true;
    }

    if (empty($fechas_seleccionadas)) return 1;

    $festivos = [];
    if (table_exists($conexion, 'dias_inhabiles')) {
        $fechas_sql = [];
        foreach (array_keys($fechas_seleccionadas) as $fecha_sel) {
            $fechas_sql[] = "'".mysqli_real_escape_string($conexion, $fecha_sel)."'";
        }

        if (!empty($fechas_sql)) {
            $sql = "SELECT fecha FROM dias_inhabiles WHERE fecha IN (".implode(',', $fechas_sql).")";
            $res = mysqli_query($conexion, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    if (!empty($row['fecha'])) $festivos[$row['fecha']] = true;
                }
            }
        }
    }

    $habiles = count($fechas_seleccionadas) - count(array_intersect_key($fechas_seleccionadas, $festivos));
    return max(1, $habiles);
}

function week_has_hc($conexion, $anio, $semana) {
    $sql = "SELECT 1 FROM hc WHERE anio = ".(int)$anio." AND semana = ".(int)$semana." LIMIT 1";
    $res = mysqli_query($conexion, $sql);
    return $res && mysqli_num_rows($res) > 0;
}

function previous_iso_week($anio, $semana) {
    $anio = (int)$anio;
    $semana = (int)$semana - 1;

    if ($semana >= 1) return [$anio, $semana];

    $prev_anio = $anio - 1;
    $last_week = (int)(new DateTime($prev_anio.'-12-28'))->format('W');

    return [$prev_anio, $last_week];
}

function base_metrics_totals($rows, $dias_habiles_base = 1, $dias_habiles_actual = 1) {
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
    $tot['pct_hc_sin_ins_base'] = $tot['hc_activo_base'] > 0 ? round(($tot['hc_sin_venta_base'] / $tot['hc_activo_base']) * 100, 0) : null;
    $tot['pct_hc_sin_ins_actual'] = $tot['hc_activo_actual'] > 0 ? round(($tot['hc_sin_venta_actual'] / $tot['hc_activo_actual']) * 100, 0) : null;
    $tot['prod_base'] = ($tot['hc_activo_base'] > 0 && $dias_habiles_base > 0) ? round($tot['ins_sem_base'] / $tot['hc_activo_base'] / $dias_habiles_base, 2) : null;
    $tot['prod_actual'] = ($tot['hc_activo_actual'] > 0 && $dias_habiles_actual > 0) ? round($tot['ins_sem_actual'] / $tot['hc_activo_actual'] / $dias_habiles_actual, 2) : null;
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

// Última semana con plantilla HC. Sirve para histórico y fallback.
$anio_hc_default = $semanas[0]['anio'] ?? (int)date('Y');
$semana_hc_default = $semanas[0]['semana'] ?? (int)date('W');

// Semana operativa máxima: se obtiene de la última fecha real cargada en instalaciones.
// Esta es la que debe abrir por default, aunque aún no exista plantilla HC propia.
$max_anio_operativo = $anio_hc_default;
$max_semana_operativa = $semana_hc_default;
$ultima_fecha_operativa_global = null;

$res_max_inst = mysqli_query($conexion, "SELECT MAX(fecha) AS ultima_fecha FROM instalaciones WHERE fecha IS NOT NULL AND fecha <= CURDATE()");
if ($res_max_inst && $row_max_inst = mysqli_fetch_assoc($res_max_inst)) {
    if (!empty($row_max_inst['ultima_fecha'])) {
        $ultima_fecha_operativa_global = $row_max_inst['ultima_fecha'];
        $max_anio_operativo = (int)date('o', strtotime($row_max_inst['ultima_fecha']));
        $max_semana_operativa = (int)date('W', strtotime($row_max_inst['ultima_fecha']));
    }
}

// Abrir por default la última semana con información cargada en instalaciones.
// Si es lunes y aún no hay carga de la semana corriente, se queda en la semana vencida completa.
$anio_actual = $max_anio_operativo;
$semana_actual = $max_semana_operativa;

if (isset($_GET['anio'])) $anio_actual = max(2020, min(2100, (int)$_GET['anio']));
if (isset($_GET['semana'])) $semana_actual = max(1, min(53, (int)$_GET['semana']));

// Bloquear semanas sin información operativa.
// Si por URL intentan abrir una semana mayor a la última cargada, regresar a la última semana con datos.
if (
    $anio_actual > $max_anio_operativo
    || ($anio_actual == $max_anio_operativo && $semana_actual > $max_semana_operativa)
) {
    $anio_actual = $max_anio_operativo;
    $semana_actual = $max_semana_operativa;
}

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
// Solo permitir navegar a semanas con datos operativos reales.
// La semana futura queda bloqueada aunque exista por calendario.
$has_next = (
    $next_anio < $max_anio_operativo
    || ($next_anio == $max_anio_operativo && $next_semana <= $max_semana_operativa)
);

$view = $_GET['view'] ?? 'lideres';
if (!in_array($view, ['lideres','ranking_coach','coaches','vendedores','ventas'], true)) $view = 'lideres';

$periodo = $_GET['periodo'] ?? 'semanal';
if (!in_array($periodo, ['semanal','mensual'], true)) $periodo = 'semanal';

$meses_es = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];

$mes_actual = isset($_GET['mes']) ? max(1, min(12, (int)$_GET['mes'])) : (int)date('n');
$anio_mes_actual = isset($_GET['anio_mes']) ? max(2020, min(2100, (int)$_GET['anio_mes'])) : $anio_actual;

// Límite mensual: último mes con instalaciones cargadas.
$ultimo_anio_datos = (int)date('Y');
$ultimo_mes_datos = (int)date('n');
$res_ultima_fecha = mysqli_query($conexion, "SELECT MAX(fecha) AS ultima_fecha FROM instalaciones WHERE fecha IS NOT NULL");
if ($res_ultima_fecha && $row_ultima_fecha = mysqli_fetch_assoc($res_ultima_fecha)) {
    if (!empty($row_ultima_fecha['ultima_fecha'])) {
        $ultimo_anio_datos = (int)date('Y', strtotime($row_ultima_fecha['ultima_fecha']));
        $ultimo_mes_datos = (int)date('n', strtotime($row_ultima_fecha['ultima_fecha']));
    }
}

// Si entran por URL a un mes sin datos futuros, regresar al último mes con datos.
if ($anio_mes_actual > $ultimo_anio_datos || ($anio_mes_actual == $ultimo_anio_datos && $mes_actual > $ultimo_mes_datos)) {
    $anio_mes_actual = $ultimo_anio_datos;
    $mes_actual = $ultimo_mes_datos;
}

$mes_base = $mes_actual - 1;
$anio_mes_base = $anio_mes_actual;
if ($mes_base < 1) {
    $mes_base = 12;
    $anio_mes_base--;
}

function semanas_del_mes_iso($anio, $mes) {
    $inicio = new DateTime(sprintf('%04d-%02d-01', $anio, $mes));
    $fin = clone $inicio;
    $fin->modify('last day of this month');
    $weeks = [];
    for ($d = clone $inicio; $d <= $fin; $d->modify('+1 day')) {
        $weeks[(int)$d->format('W')] = true;
    }
    return array_keys($weeks);
}

function ultima_semana_hc_mes($conexion, $anio, $mes, $fallback_semana) {
    $weeks = semanas_del_mes_iso($anio, $mes);
    if (empty($weeks)) return (int)$fallback_semana;
    $in = implode(',', array_map('intval', $weeks));
    $sql = "SELECT MAX(semana) AS semana FROM hc WHERE anio = ".(int)$anio." AND semana IN ($in)";
    $res = mysqli_query($conexion, $sql);
    if ($res && $row = mysqli_fetch_assoc($res)) {
        if (!empty($row['semana'])) return (int)$row['semana'];
    }
    return (int)$fallback_semana;
}

function ultima_semana_hc_disponible_hasta($conexion, $anio, $semana_limite) {
    $anio = (int)$anio;
    $semana_limite = (int)$semana_limite;

    $sql = "
        SELECT anio, semana
        FROM hc
        WHERE anio IS NOT NULL
          AND semana IS NOT NULL
          AND (
                anio < {$anio}
             OR (anio = {$anio} AND semana <= {$semana_limite})
          )
        ORDER BY anio DESC, semana DESC
        LIMIT 1
    ";

    $res = mysqli_query($conexion, $sql);
    if ($res && $row = mysqli_fetch_assoc($res)) {
        return [(int)$row['anio'], (int)$row['semana']];
    }

    return [$anio, $semana_limite];
}

$hc_anio_base = $anio_base;
$hc_semana_base = $semana_base;
$hc_anio_actual = $anio_actual;
$hc_semana_actual = $semana_actual;

$hc_actual_fallback = false;
if ($periodo === 'semanal' && !week_has_hc($conexion, $hc_anio_actual, $hc_semana_actual)) {
    [$fallback_anio, $fallback_semana] = previous_iso_week($hc_anio_actual, $hc_semana_actual);
    $hc_anio_actual = $fallback_anio;
    $hc_semana_actual = $fallback_semana;
    $hc_actual_fallback = true;
}

if ($periodo === 'mensual') {
    $hc_anio_base = $anio_mes_base;
    $hc_semana_base = ultima_semana_hc_mes($conexion, $anio_mes_base, $mes_base, $semana_base);

    $hc_anio_actual = $anio_mes_actual;
    $hc_semana_actual = ultima_semana_hc_mes($conexion, $anio_mes_actual, $mes_actual, $semana_actual);

    // Si el mes actual todavía no tiene HC propio (ej. Junio inicia en SEM23,
    // pero HC solo está cargado hasta SEM22), usar la última plantilla HC disponible.
    if (!week_has_hc($conexion, $hc_anio_actual, $hc_semana_actual)) {
        [$hc_anio_actual, $hc_semana_actual] = ultima_semana_hc_disponible_hasta(
            $conexion,
            $anio_mes_actual,
            $semana_actual
        );
        $hc_actual_fallback = true;
    }
}

// Corte operativo por default: día vencido del calendario actual.
// Aplica también cuando navegas meses anteriores.
// Ejemplo si hoy es 19: Abril default = 1-18.
// Si el mes seleccionado tiene menos días, se ajusta al último día del mes.
$dia_default_mensual = (int)date('j', strtotime('-1 day'));
$dia_default_mensual = min(
    $dia_default_mensual,
    (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio_mes_actual, $mes_actual)))
);

$ultimo_dia_base = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio_mes_base, $mes_base)));
$ultimo_dia_actual = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio_mes_actual, $mes_actual)));

// El input se limita al mes seleccionado actual.
// El mes base se ajusta automáticamente con MIN() si tiene menos días.
// Ejemplo: Mar 1-31 vs Feb 1-28.
$rango_mode = $_GET['rango_mode'] ?? 'mtd';
if (!in_array($rango_mode, ['mtd','completo','custom'], true)) $rango_mode = 'mtd';

// Mes completo siempre debe ignorar cualquier rango manual heredado en la URL.
if ($rango_mode === 'completo') {
    unset($_GET['dia_inicio'], $_GET['dia_fin'], $_GET['fecha_inicio'], $_GET['fecha_fin']);
}

$fecha_inicio_actual = sprintf('%04d-%02d-01', $anio_mes_actual, $mes_actual);
$fecha_fin_actual = sprintf('%04d-%02d-%02d', $anio_mes_actual, $mes_actual, $dia_default_mensual);

if ($rango_mode === 'completo') {
    // Mes completo real para cada mes histórico:
    // Ejemplo Abril completo => Marzo 1-31 vs Abril 1-30.
    // Si el mes seleccionado es el último mes con datos, se limita al último día cargado para no ir al futuro.
    $dia_inicio_mensual = 1;

    $dia_fin_actual_completo = $ultimo_dia_actual;
    if ($anio_mes_actual == $ultimo_anio_datos && $mes_actual == $ultimo_mes_datos && !empty($row_ultima_fecha['ultima_fecha'])) {
        $dia_fin_actual_completo = min($ultimo_dia_actual, (int)date('j', strtotime($row_ultima_fecha['ultima_fecha'])));
    }

    $dia_inicio_base = 1;
    $dia_fin_base = $ultimo_dia_base;

    $dia_inicio_actual = 1;
    $dia_fin_actual = $dia_fin_actual_completo;
    $dia_fin_mensual = $dia_fin_actual_completo;
} else {
    if ($rango_mode !== 'custom') {
        $_GET['dia_inicio'] = 1;
        $_GET['dia_fin'] = $dia_default_mensual;
    }

    if ($rango_mode === 'custom' && !empty($_GET['fecha_inicio']) && !empty($_GET['fecha_fin'])) {
        $fi = DateTime::createFromFormat('Y-m-d', $_GET['fecha_inicio']);
        $ff = DateTime::createFromFormat('Y-m-d', $_GET['fecha_fin']);

        if ($fi && $ff) {
            // Solo se toma el día elegido; el mes/año activo se controla con el selector mensual.
            $dia_inicio_mensual = (int)$fi->format('j');
            $dia_fin_mensual = (int)$ff->format('j');
        } else {
            $dia_inicio_mensual = isset($_GET['dia_inicio']) ? (int)$_GET['dia_inicio'] : 1;
            $dia_fin_mensual = isset($_GET['dia_fin']) ? (int)$_GET['dia_fin'] : $dia_default_mensual;
        }
    } else {
        $dia_inicio_mensual = isset($_GET['dia_inicio']) ? (int)$_GET['dia_inicio'] : 1;
        $dia_fin_mensual = isset($_GET['dia_fin']) ? (int)$_GET['dia_fin'] : $dia_default_mensual;
    }

    $dia_inicio_mensual = max(1, min($ultimo_dia_actual, $dia_inicio_mensual));
    $dia_fin_mensual = max(1, min($ultimo_dia_actual, $dia_fin_mensual));

    if ($dia_inicio_mensual > $dia_fin_mensual) {
        $tmp_dia = $dia_inicio_mensual;
        $dia_inicio_mensual = $dia_fin_mensual;
        $dia_fin_mensual = $tmp_dia;
    }

    $dia_inicio_base = min($dia_inicio_mensual, $ultimo_dia_base);
    $dia_fin_base = min($dia_fin_mensual, $ultimo_dia_base);

    $dia_inicio_actual = min($dia_inicio_mensual, $ultimo_dia_actual);
    $dia_fin_actual = min($dia_fin_mensual, $ultimo_dia_actual);
}

$fecha_inicio_actual = sprintf('%04d-%02d-%02d', $anio_mes_actual, $mes_actual, $dia_inicio_actual);
$fecha_fin_actual = sprintf('%04d-%02d-%02d', $anio_mes_actual, $mes_actual, $dia_fin_actual);
$fecha_inicio_base = sprintf('%04d-%02d-%02d', $anio_mes_base, $mes_base, $dia_inicio_base);
$fecha_fin_base = sprintf('%04d-%02d-%02d', $anio_mes_base, $mes_base, $dia_fin_base);

$dias_semana_labels = [
    1 => 'LUN',
    2 => 'MAR',
    3 => 'MIÉ',
    4 => 'JUE',
    5 => 'VIE',
    6 => 'SÁB',
    7 => 'DOM'
];

// Selector semanal operativo.
// Por default muestra solo días vencidos con base en la última fecha cargada.
// Ejemplo: si última carga es viernes de SEM21, default = LUN-VIE.
// Semanas históricas: permitir semana completa LUN-DOM.
// Semana corriente: limitar únicamente a días con información cargada/vencida.
$dias_semana_disponibles = [1,2,3,4,5,6,7];
if ($periodo === 'semanal' && $anio_actual == $max_anio_operativo && $semana_actual == $max_semana_operativa) {
    // Última semana cargada: mostrar solo hasta el último día con información.
    // Si la última carga fue domingo, queda semana completa LUN-DOM.
    $ultima_fecha_operativa = $ultima_fecha_operativa_global;

    if ($ultima_fecha_operativa) {
        $dow_ultima = (int)date('N', strtotime($ultima_fecha_operativa)); // 1=LUN ... 7=DOM
        $dow_ultima = min(7, max(1, $dow_ultima));
        $dias_semana_disponibles = range(1, $dow_ultima);
    }
}

$dias_semana_seleccionados = $dias_semana_disponibles;
if ($periodo === 'semanal' && isset($_GET['dias_semana'])) {
    $raw_dias = explode(',', (string)$_GET['dias_semana']);
    $tmp_dias = [];
    foreach ($raw_dias as $d) {
        $n = (int)$d;
        if ($n >= 1 && $n <= 7 && in_array($n, $dias_semana_disponibles, true) && !in_array($n, $tmp_dias, true)) {
            $tmp_dias[] = $n;
        }
    }
    if (!empty($tmp_dias)) {
        sort($tmp_dias);
        $dias_semana_seleccionados = $tmp_dias;
    }
}

// ISO 1=LUN ... 7=DOM -> MySQL DAYOFWEEK 2=LUN ... 1=DOM
$dias_semana_mysql = array_map(function($d) { return ((int)$d === 7) ? 1 : ((int)$d + 1); }, $dias_semana_seleccionados);
$dias_semana_mysql_in = implode(',', $dias_semana_mysql);
$dias_semana_label = implode(', ', array_map(function($d) use ($dias_semana_labels) {
    return $dias_semana_labels[$d] ?? '';
}, $dias_semana_seleccionados));

if ($periodo === 'semanal') {
    $sem_base_inicio = fecha_iso_semana_inicio($anio_base, $semana_base);
    $sem_base_fin = clone $sem_base_inicio;
    $sem_base_fin->modify('+6 days');

    $sem_actual_inicio = fecha_iso_semana_inicio($anio_actual, $semana_actual);
    $sem_actual_fin = clone $sem_actual_inicio;
    $sem_actual_fin->modify('+6 days');

    $fecha_inicio_base_calc = $sem_base_inicio->format('Y-m-d');
    $fecha_fin_base_calc = $sem_base_fin->format('Y-m-d');

    $fecha_inicio_actual_calc = $sem_actual_inicio->format('Y-m-d');
    $fecha_fin_actual_calc = $sem_actual_fin->format('Y-m-d');
} else {
    $fecha_inicio_base_calc = $fecha_inicio_base;
    $fecha_fin_base_calc = $fecha_fin_base;

    $fecha_inicio_actual_calc = $fecha_inicio_actual;
    $fecha_fin_actual_calc = $fecha_fin_actual;
}

if ($periodo === 'semanal') {
    $dias_habiles_base = contar_dias_habiles_semana_seleccionada($conexion, $anio_base, $semana_base, $dias_semana_seleccionados);
    $dias_habiles_actual = contar_dias_habiles_semana_seleccionada($conexion, $anio_actual, $semana_actual, $dias_semana_seleccionados);
} else {
    $dias_habiles_base = contar_dias_habiles($conexion, $fecha_inicio_base_calc, $fecha_fin_base_calc);
    $dias_habiles_actual = contar_dias_habiles($conexion, $fecha_inicio_actual_calc, $fecha_fin_actual_calc);
}

$rango_dias_label = $dia_inicio_mensual === $dia_fin_mensual
    ? 'día '.$dia_fin_mensual
    : 'días '.$dia_inicio_mensual.'-'.$dia_fin_mensual;

$label_periodo_base = $periodo === 'mensual'
    ? $meses_es[$mes_base].' '.$dia_inicio_base.'-'.$dia_fin_base.' '.$anio_mes_base
    : 'Semana '.$semana_base;

$label_periodo_actual = $periodo === 'mensual'
    ? $meses_es[$mes_actual].' '.$dia_inicio_actual.'-'.$dia_fin_actual.' '.$anio_mes_actual
    : 'Semana '.$semana_actual;

$label_col_base = $periodo === 'mensual' ? strtoupper(substr($meses_es[$mes_base],0,3)).' '.$dia_inicio_base.'-'.$dia_fin_base : 'SEM'.$semana_base;
$label_col_actual = $periodo === 'mensual' ? strtoupper(substr($meses_es[$mes_actual],0,3)).' '.$dia_inicio_actual.'-'.$dia_fin_actual : 'SEM'.$semana_actual;

$cond_i_base = $periodo === 'mensual'
    ? "YEAR(i.fecha) = {$anio_mes_base} AND MONTH(i.fecha) = {$mes_base} AND DAY(i.fecha) BETWEEN {$dia_inicio_base} AND {$dia_fin_base}"
    : "YEAR(i.fecha) = {$anio_base} AND WEEK(i.fecha,1) = {$semana_base} AND DAYOFWEEK(i.fecha) IN ({$dias_semana_mysql_in})";

$cond_i_actual = $periodo === 'mensual'
    ? "YEAR(i.fecha) = {$anio_mes_actual} AND MONTH(i.fecha) = {$mes_actual} AND DAY(i.fecha) BETWEEN {$dia_inicio_actual} AND {$dia_fin_actual}"
    : "YEAR(i.fecha) = {$anio_actual} AND WEEK(i.fecha,1) = {$semana_actual} AND DAYOFWEEK(i.fecha) IN ({$dias_semana_mysql_in})";

$cond_ibase = $periodo === 'mensual'
    ? "YEAR(ibase.fecha) = {$anio_mes_base} AND MONTH(ibase.fecha) = {$mes_base} AND DAY(ibase.fecha) BETWEEN {$dia_inicio_base} AND {$dia_fin_base}"
    : "YEAR(ibase.fecha) = {$anio_base} AND WEEK(ibase.fecha,1) = {$semana_base} AND DAYOFWEEK(ibase.fecha) IN ({$dias_semana_mysql_in})";

$cond_iactual = $periodo === 'mensual'
    ? "YEAR(iactual.fecha) = {$anio_mes_actual} AND MONTH(iactual.fecha) = {$mes_actual} AND DAY(iactual.fecha) BETWEEN {$dia_inicio_actual} AND {$dia_fin_actual}"
    : "YEAR(iactual.fecha) = {$anio_actual} AND WEEK(iactual.fecha,1) = {$semana_actual} AND DAYOFWEEK(iactual.fecha) IN ({$dias_semana_mysql_in})";

$cond_mix_actual = $periodo === 'mensual'
    ? "YEAR(i.fecha) = {$anio_mes_actual} AND MONTH(i.fecha) = {$mes_actual} AND DAY(i.fecha) BETWEEN {$dia_inicio_actual} AND {$dia_fin_actual}"
    : "YEAR(i.fecha) = {$anio_actual} AND WEEK(i.fecha,1) = {$semana_actual} AND DAYOFWEEK(i.fecha) IN ({$dias_semana_mysql_in})";

$distrito_param  = $_GET['distrito'] ?? '';
$lider_param     = $_GET['lider'] ?? '';
$coach_param     = $_GET['coach'] ?? '';
$coach_pos_param = $_GET['coach_pos'] ?? '';
$vendedor_param  = $_GET['vendedor'] ?? '';
$folio_param     = $_GET['folio'] ?? '';

$distrito_sql  = esc($conexion, $distrito_param);

// Normalización específica de distrito para cruces HC vs Ranking.
// Ranking muestra COATZA-MINA, pero la tabla HC conserva COATZA MINA.
$distrito_hc_param = $distrito_param;
if (strtoupper(trim($distrito_hc_param)) === 'COATZA-MINA') {
    $distrito_hc_param = 'COATZA MINA';
}
$distrito_hc_sql = esc($conexion, $distrito_hc_param);

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

// Clasificación comercial para el rollout a nivel vendedor.
// Antes solo se tomaba NEGOCIOS si el campo segmento contenía "NEGOC".
// En instalaciones legacy, algunos paquetes de oferta negocios viajan en plan/subcanal
// y el segmento puede venir vacío o como residencial; por eso se arma una señal amplia.
$clasificacion_negocio_expr = "UPPER(CONCAT_WS(' ',
    COALESCE({$segmento_select_expr},''),
    COALESCE(i.plan,''),
    COALESCE(i.subcanal,'')
))";

$cond_negocios_actual = "{$cond_mix_actual} AND (
       {$clasificacion_negocio_expr} LIKE '%NEGOC%'
    OR {$clasificacion_negocio_expr} LIKE '%PYME%'
    OR {$clasificacion_negocio_expr} LIKE '%EMPRES%'
    OR {$clasificacion_negocio_expr} LIKE '%BUSINESS%'
    OR {$clasificacion_negocio_expr} LIKE '%COMERCIAL%'
    OR {$clasificacion_negocio_expr} LIKE '%SOHO%'
)";

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
        SUM(CASE WHEN {$cond_i_base} THEN 1 ELSE 0 END) AS ins_sem_base,
        SUM(CASE WHEN {$cond_i_actual} THEN 1 ELSE 0 END) AS ins_sem_actual
    FROM lideres_activos la
    LEFT JOIN instalaciones i
        ON i.lider = la.lider_instalaciones
       AND (
            ({$cond_i_base})
         OR ({$cond_i_actual})
       )
    GROUP BY la.distrito_reporte, la.lider_hc
),
coaches_lider AS (
    SELECT DISTINCT
        la.distrito_reporte AS distrito,
        la.distrito_hc,
        la.lider_hc AS lider,
        la.lider_instalaciones AS lider_instalaciones,
        h.nombre_colaborador AS coach,
        h.id_posicion AS coach_pos,
        h.semana,
        h.anio
    FROM lideres_activos la
    INNER JOIN hc h
        ON h.nombre_linea_reporte = la.lider_hc
       AND h.distrito = la.distrito_hc
       AND (
            (h.anio = {$hc_anio_base} AND h.semana = {$hc_semana_base})
         OR (h.anio = {$hc_anio_actual} AND h.semana = {$hc_semana_actual})
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
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.id_posicion END) AS vacante_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.id_posicion END) AS vacante_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND ibase.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND iactual.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_actual
    FROM vendedores v
    LEFT JOIN instalaciones ibase ON v.folio_empleado = ibase.folio_empleado AND {$cond_ibase}
    LEFT JOIN instalaciones iactual ON v.folio_empleado = iactual.folio_empleado AND {$cond_iactual}
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
    ROUND(((COALESCE(h.hc_activo_base,0) - COALESCE(h.hc_con_ins_base,0)) / NULLIF(h.hc_activo_base,0)) * 100,0) AS pct_hc_sin_ins_base,
    ROUND(((COALESCE(h.hc_activo_actual,0) - COALESCE(h.hc_con_ins_actual,0)) / NULLIF(h.hc_activo_actual,0)) * 100,0) AS pct_hc_sin_ins_actual,
    ROUND(vl.ins_sem_base / NULLIF(h.hc_activo_base * {$dias_habiles_base},0),2) AS prod_base,
    ROUND(vl.ins_sem_actual / NULLIF(h.hc_activo_actual * {$dias_habiles_actual},0),2) AS prod_actual,
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
} elseif ($view === 'ranking_coach') {
$sql = "
WITH {$lideres_cte},
coaches_raw AS (
    SELECT DISTINCT
        la.distrito_reporte AS distrito,
        la.distrito_hc,
        la.lider_hc AS lider,
        la.lider_instalaciones AS lider_instalaciones,
        h.nombre_colaborador AS coach,
        h.id_posicion AS coach_pos,
        CONCAT(la.distrito_reporte, '|', la.lider_hc, '|', h.nombre_colaborador, '|', h.id_posicion) AS coach_key,
        h.semana,
        h.anio
    FROM lideres_activos la
    INNER JOIN hc h
        ON h.nombre_linea_reporte = la.lider_hc
       AND h.distrito = la.distrito_hc
       AND (
            (h.anio = {$hc_anio_base} AND h.semana = {$hc_semana_base})
         OR (h.anio = {$hc_anio_actual} AND h.semana = {$hc_semana_actual})
       )
       AND h.puesto_lr LIKE '%LIDER%'
),
coaches_base AS (
    SELECT
        distrito,
        distrito_hc,
        lider,
        lider_instalaciones,
        coach,
        coach_pos,
        coach_key
    FROM coaches_raw
    GROUP BY distrito, distrito_hc, lider, lider_instalaciones, coach, coach_pos, coach_key
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
    FROM coaches_raw c
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
ventas_base AS (
    SELECT
        c.coach_key,
        COUNT(DISTINCT i.cuenta) AS ins_sem_base
    FROM coaches_base c
    INNER JOIN instalaciones i
        ON i.lider = c.lider_instalaciones
       AND {$cond_i_base}
       AND (
            UPPER(TRIM(i.coach)) = UPPER(TRIM(c.coach))
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', 2))), '%')
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', -2))), '%')
       )
    GROUP BY c.coach_key
),
ventas_actual AS (
    SELECT
        c.coach_key,
        COUNT(DISTINCT i.cuenta) AS ins_sem_actual
    FROM coaches_base c
    INNER JOIN instalaciones i
        ON i.lider = c.lider_instalaciones
       AND {$cond_i_actual}
       AND (
            UPPER(TRIM(i.coach)) = UPPER(TRIM(c.coach))
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', 2))), '%')
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', -2))), '%')
       )
    GROUP BY c.coach_key
),
hc_resumen AS (
    SELECT
        c.distrito,
        c.lider,
        c.coach AS entidad,
        c.coach,
        c.coach_pos,
        c.coach_key,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.id_posicion END) AS vacante_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.id_posicion END) AS vacante_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND ibase.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND iactual.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_actual
    FROM coaches_base c
    LEFT JOIN vendedores v
        ON c.coach_key = v.coach_key
    LEFT JOIN instalaciones ibase
        ON v.folio_empleado = ibase.folio_empleado
       AND {$cond_ibase}
       AND v.anio={$hc_anio_base}
       AND v.semana={$hc_semana_base}
    LEFT JOIN instalaciones iactual
        ON v.folio_empleado = iactual.folio_empleado
       AND {$cond_iactual}
       AND v.anio={$hc_anio_actual}
       AND v.semana={$hc_semana_actual}
    GROUP BY c.distrito, c.lider, c.coach, c.coach_pos, c.coach_key
)
SELECT
    h.distrito,
    h.entidad,
    h.lider,
    h.coach,
    h.coach_pos,
    '' AS folio_empleado,
    COALESCE(vb.ins_sem_base,0) AS ins_sem_base,
    COALESCE(va.ins_sem_actual,0) AS ins_sem_actual,
    COALESCE(va.ins_sem_actual,0) - COALESCE(vb.ins_sem_base,0) AS dif,
    ROUND(((COALESCE(va.ins_sem_actual,0) - COALESCE(vb.ins_sem_base,0)) / NULLIF(vb.ins_sem_base,0)) * 100,0) AS pct_dif,
    h.hc_activo_base,
    h.hc_activo_actual,
    h.hc_con_ins_base,
    h.hc_con_ins_actual,
    h.hc_activo_base - h.hc_con_ins_base AS hc_sin_venta_base,
    h.hc_activo_actual - h.hc_con_ins_actual AS hc_sin_venta_actual,
    ROUND(((h.hc_activo_base - h.hc_con_ins_base) / NULLIF(h.hc_activo_base,0)) * 100,0) AS pct_hc_sin_ins_base,
    ROUND(((h.hc_activo_actual - h.hc_con_ins_actual) / NULLIF(h.hc_activo_actual,0)) * 100,0) AS pct_hc_sin_ins_actual,
    ROUND(COALESCE(vb.ins_sem_base,0) / NULLIF(h.hc_activo_base * {$dias_habiles_base},0),2) AS prod_base,
    ROUND(COALESCE(va.ins_sem_actual,0) / NULLIF(h.hc_activo_actual * {$dias_habiles_actual},0),2) AS prod_actual,
    h.hc_activo_base AS activo_base,
    h.vacante_base,
    h.hc_activo_base + h.vacante_base AS hc_total_base,
    h.hc_activo_actual AS activo_actual,
    h.vacante_actual,
    h.hc_activo_actual + h.vacante_actual AS hc_total_actual
FROM hc_resumen h
LEFT JOIN ventas_base vb ON h.coach_key = vb.coach_key
LEFT JOIN ventas_actual va ON h.coach_key = va.coach_key
WHERE
       COALESCE(vb.ins_sem_base,0) > 0
    OR COALESCE(va.ins_sem_actual,0) > 0
    OR COALESCE(h.hc_activo_base + h.vacante_base,0) > 0
    OR COALESCE(h.hc_activo_actual + h.vacante_actual,0) > 0
ORDER BY prod_actual DESC, ins_sem_actual DESC, entidad ASC
";
} elseif ($view === 'coaches') {
$sql = "
WITH {$lideres_cte},
selected_lider AS (
    SELECT *
    FROM lideres_activos
    WHERE (distrito_reporte = '{$distrito_sql}' OR distrito_hc = '{$distrito_hc_sql}')
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
            (h.anio = {$hc_anio_base} AND h.semana = {$hc_semana_base})
         OR (h.anio = {$hc_anio_actual} AND h.semana = {$hc_semana_actual})
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
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' THEN v.folio_empleado END) AS hc_activo_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.id_posicion END) AS vacante_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND (v.folio_empleado='VACANTE' OR v.nombre_colaborador='VACANTE') THEN v.id_posicion END) AS vacante_actual,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_base} AND v.semana={$hc_semana_base} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND ibase.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_base,
        COUNT(DISTINCT CASE WHEN v.anio={$hc_anio_actual} AND v.semana={$hc_semana_actual} AND v.folio_empleado <> 'VACANTE' AND v.nombre_colaborador <> 'VACANTE' AND iactual.folio_empleado IS NOT NULL THEN v.folio_empleado END) AS hc_con_ins_actual,
        (
            SELECT COUNT(DISTINCT ibase.cuenta)
            FROM instalaciones ibase
            WHERE {$cond_ibase}
              AND ibase.lider = c.lider_instalaciones
              AND (
                    UPPER(TRIM(ibase.coach)) = UPPER(TRIM(c.coach))
                 OR UPPER(TRIM(ibase.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', 2))), '%')
                 OR UPPER(TRIM(ibase.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', -2))), '%')
              )
        ) AS ins_sem_base,
        (
            SELECT COUNT(DISTINCT iactual.cuenta)
            FROM instalaciones iactual
            WHERE {$cond_iactual}
              AND iactual.lider = c.lider_instalaciones
              AND (
                    UPPER(TRIM(iactual.coach)) = UPPER(TRIM(c.coach))
                 OR UPPER(TRIM(iactual.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', 2))), '%')
                 OR UPPER(TRIM(iactual.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(c.coach, ' ', -2))), '%')
              )
        ) AS ins_sem_actual
    FROM coaches_base c
    LEFT JOIN vendedores v ON c.coach_key = v.coach_key AND c.anio = v.anio AND c.semana = v.semana
    LEFT JOIN instalaciones ibase
        ON v.folio_empleado = ibase.folio_empleado
       AND {$cond_ibase}
       AND v.anio={$hc_anio_base}
       AND v.semana={$hc_semana_base}
    LEFT JOIN instalaciones iactual
        ON v.folio_empleado = iactual.folio_empleado
       AND {$cond_iactual}
       AND v.anio={$hc_anio_actual}
       AND v.semana={$hc_semana_actual}
    GROUP BY c.distrito, c.lider, c.coach, c.coach_pos, c.coach_key
),
ventas_sin_coach_base AS (
    SELECT
        la.distrito_reporte AS distrito,
        la.lider_hc AS lider,
        COUNT(DISTINCT i.cuenta) AS ins_sem_base
    FROM selected_lider la
    INNER JOIN instalaciones i
        ON i.lider = la.lider_instalaciones
       AND {$cond_i_base}
    LEFT JOIN coaches_base cb
        ON cb.lider_instalaciones = i.lider
       AND (
            UPPER(TRIM(i.coach)) = UPPER(TRIM(cb.coach))
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(cb.coach, ' ', 2))), '%')
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(cb.coach, ' ', -2))), '%')
       )
    WHERE cb.coach_key IS NULL
    GROUP BY la.distrito_reporte, la.lider_hc
),
ventas_sin_coach_actual AS (
    SELECT
        la.distrito_reporte AS distrito,
        la.lider_hc AS lider,
        COUNT(DISTINCT i.cuenta) AS ins_sem_actual
    FROM selected_lider la
    INNER JOIN instalaciones i
        ON i.lider = la.lider_instalaciones
       AND {$cond_i_actual}
    LEFT JOIN coaches_base cb
        ON cb.lider_instalaciones = i.lider
       AND (
            UPPER(TRIM(i.coach)) = UPPER(TRIM(cb.coach))
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(cb.coach, ' ', 2))), '%')
         OR UPPER(TRIM(i.coach)) LIKE CONCAT('%', UPPER(TRIM(SUBSTRING_INDEX(cb.coach, ' ', -2))), '%')
       )
    WHERE cb.coach_key IS NULL
    GROUP BY la.distrito_reporte, la.lider_hc
),
sin_coach AS (
    SELECT
        la.distrito_reporte AS distrito,
        la.lider_hc AS lider,
        COALESCE(b.ins_sem_base, 0) AS ins_sem_base,
        COALESCE(a.ins_sem_actual, 0) AS ins_sem_actual
    FROM selected_lider la
    LEFT JOIN ventas_sin_coach_base b
        ON la.distrito_reporte = b.distrito
       AND la.lider_hc = b.lider
    LEFT JOIN ventas_sin_coach_actual a
        ON la.distrito_reporte = a.distrito
       AND la.lider_hc = a.lider
)
SELECT *
FROM (
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
        ROUND(((hc_activo_base - hc_con_ins_base) / NULLIF(hc_activo_base,0)) * 100,0) AS pct_hc_sin_ins_base,
        ROUND(((hc_activo_actual - hc_con_ins_actual) / NULLIF(hc_activo_actual,0)) * 100,0) AS pct_hc_sin_ins_actual,
        ROUND(ins_sem_base / NULLIF(hc_activo_base * {$dias_habiles_base},0),2) AS prod_base,
        ROUND(ins_sem_actual / NULLIF(hc_activo_actual * {$dias_habiles_actual},0),2) AS prod_actual,
        hc_activo_base AS activo_base,
        vacante_base,
        hc_activo_base + vacante_base AS hc_total_base,
        hc_activo_actual AS activo_actual,
        vacante_actual,
        hc_activo_actual + vacante_actual AS hc_total_actual
    FROM resumen

    UNION ALL

    SELECT
        distrito,
        'SIN COACH / NO ENCONTRADO EN HC' AS entidad,
        lider,
        'SIN COACH / NO ENCONTRADO EN HC' AS coach,
        '' AS coach_pos,
        '' AS folio_empleado,
        ins_sem_base,
        ins_sem_actual,
        ins_sem_actual - ins_sem_base AS dif,
        ROUND(((ins_sem_actual - ins_sem_base) / NULLIF(ins_sem_base,0)) * 100,0) AS pct_dif,
        0 AS hc_activo_base,
        0 AS hc_activo_actual,
        0 AS hc_con_ins_base,
        0 AS hc_con_ins_actual,
        0 AS hc_sin_venta_base,
        0 AS hc_sin_venta_actual,
        NULL AS pct_hc_sin_ins_base,
        NULL AS pct_hc_sin_ins_actual,
        NULL AS prod_base,
        NULL AS prod_actual,
        0 AS activo_base,
        0 AS vacante_base,
        0 AS hc_total_base,
        0 AS activo_actual,
        0 AS vacante_actual,
        0 AS hc_total_actual
    FROM sin_coach
    WHERE (ins_sem_base + ins_sem_actual) > 0
) final
WHERE
       COALESCE(ins_sem_base,0) > 0
    OR COALESCE(ins_sem_actual,0) > 0
    OR COALESCE(hc_total_base,0) > 0
    OR COALESCE(hc_total_actual,0) > 0
ORDER BY
    CASE WHEN entidad = 'SIN COACH / NO ENCONTRADO EN HC' THEN 1 ELSE 0 END,
    prod_actual DESC,
    ins_sem_actual DESC,
    entidad ASC
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
    WHERE h.distrito = '{$distrito_hc_sql}'
      AND h.puesto_lr LIKE '%COACH%'
      AND h.numero_talento_gs <> 'VACANTE'
      AND h.nombre_colaborador <> 'VACANTE'
      AND (
          ('{$coach_sql}' <> 'VACANTE' AND h.nombre_linea_reporte = '{$coach_sql}')
          OR
          ('{$coach_sql}' = 'VACANTE' AND h.posicion_lr = '{$coach_pos_sql}')
      )
      AND (
            (h.anio = {$hc_anio_base} AND h.semana = {$hc_semana_base})
         OR (h.anio = {$hc_anio_actual} AND h.semana = {$hc_semana_actual})
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
        CASE WHEN {$cond_i_base} THEN {$semana_base} WHEN {$cond_i_actual} THEN {$semana_actual} ELSE NULL END AS semana,
        COUNT(i.cuenta) AS ventas,
        SUM(CASE
                WHEN {$cond_mix_actual}
                 AND cp.play = 'TRIPLE PLAY'
                THEN 1 ELSE 0 END) AS triple_play,
        SUM(CASE
                WHEN {$cond_mix_actual}
                 AND cp.play = 'DOBLE PLAY'
                THEN 1 ELSE 0 END) AS doble_play,
        SUM(CASE
                WHEN {$cond_mix_actual}
                 AND cp.tipo = 'NEGOCIOS'
                THEN 1 ELSE 0 END) AS negocios,
        SUM(CASE
                WHEN {$cond_mix_actual}
                 AND cp.tipo = 'RESIDENCIAL'
                THEN 1 ELSE 0 END) AS residencial

    FROM vendedores_base vb
    LEFT JOIN instalaciones i
        ON i.folio_empleado = vb.folio_empleado
       AND (
            ({$cond_i_base})
         OR ({$cond_i_actual})
       )
    LEFT JOIN catalogo_paquetes cp
        ON UPPER(TRIM(i.plan)) = UPPER(TRIM(cp.nombre_plan))
    GROUP BY
        vb.distrito,
        vb.lider,
        vb.coach,
        vb.coach_pos,
        vb.vendedor,
        vb.folio_empleado,
        vb.antiguedad,
        CASE WHEN {$cond_i_base} THEN {$semana_base} WHEN {$cond_i_actual} THEN {$semana_actual} ELSE NULL END
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
        uasort($coach_matrix, function($a, $b) use ($semana_actual) {
            $a_actual = (int)($a['semanas'][$semana_actual] ?? 0);
            $b_actual = (int)($b['semanas'][$semana_actual] ?? 0);

            if ($a_actual === $b_actual) {
                return strcmp($a['vendedor'], $b['vendedor']);
            }

            return $b_actual <=> $a_actual;
        });
    } else {
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
}

$tot = base_metrics_totals($rows, $dias_habiles_base, $dias_habiles_actual);
$districts = [];
if (in_array($view, ['lideres','ranking_coach'], true)) {
    foreach ($rows as $r) if (!in_array($r['distrito'], $districts, true)) $districts[] = $r['distrito'];
    sort($districts);
}

$fecha_label = date('d/m/Y');
$entity_label = $view === 'lideres' ? 'Líder' : (in_array($view, ['coaches','ranking_coach'], true) ? 'Coach' : 'Semana');
$title_label = [
    'lideres'        => 'Ranking de Productividad',
    'ranking_coach'  => 'Ranking Coach',
    'coaches'        => 'Ranking por Coach',
    'vendedores' => 'Ventas Semanales del Coach',
    'ventas'     => 'Ventas Semanales del Vendedor',
][$view];

$base_link = '?' . qs(['periodo'=>$periodo, 'anio'=>$anio_actual, 'semana'=>$semana_actual, 'anio_mes'=>$anio_mes_actual, 'mes'=>$mes_actual, 'dias_semana'=>implode(',', $dias_semana_seleccionados), 'view'=>'lideres']);
$ranking_coach_link = '?' . qs(['periodo'=>$periodo, 'anio'=>$anio_actual, 'semana'=>$semana_actual, 'anio_mes'=>$anio_mes_actual, 'mes'=>$mes_actual, 'dias_semana'=>implode(',', $dias_semana_seleccionados), 'view'=>'ranking_coach']);
$lider_link = '?' . qs(['periodo'=>$periodo, 'anio'=>$anio_actual, 'semana'=>$semana_actual, 'anio_mes'=>$anio_mes_actual, 'mes'=>$mes_actual, 'dias_semana'=>implode(',', $dias_semana_seleccionados), 'view'=>'coaches', 'distrito'=>$distrito_param, 'lider'=>$lider_param]);
$coach_link = '?' . qs(['periodo'=>$periodo, 'anio'=>$anio_actual, 'semana'=>$semana_actual, 'anio_mes'=>$anio_mes_actual, 'mes'=>$mes_actual, 'dias_semana'=>implode(',', $dias_semana_seleccionados), 'view'=>'vendedores', 'distrito'=>$distrito_param, 'lider'=>$lider_param, 'coach'=>$coach_param, 'coach_pos'=>$coach_pos_param]);

$subtitle = ($periodo === 'mensual' ? 'MTD día vencido · ' : '') . "Comparativo {$label_periodo_base} vs {$label_periodo_actual} · {$fecha_label} · " . ($roles_labels[$rol] ?? $rol);
if ($periodo === 'semanal') $subtitle .= " · Días: {$dias_semana_label}";
if (!empty($hc_actual_fallback)) $subtitle .= " · HC actual usando SEM{$hc_semana_actual}";
if (in_array($view, ['coaches','vendedores','ventas'], true)) $subtitle .= " · Líder: {$lider_param}";
if ($view === 'vendedores' || $view === 'ventas') $subtitle .= " · Coach: {$coach_param}";
if ($view === 'ventas') $subtitle .= " · Vendedor: {$vendedor_param}";

/* Calendario mensual: lunes = 1, domingo = 7 */
$primer_dia_semana = (int)(new DateTime(sprintf('%04d-%02d-01', $anio_mes_actual, $mes_actual)))->format('N');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title_label) ?> — TOTALXPEDIENT</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=162">
</head>
<body class="page-ranking">
<?php
$current_page = 'ranking';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main">
<section class="topbar">
    <div class="page-title">
        <h1><?= h($title_label) ?> <span class="week-pill"><?= h($periodo === 'mensual' ? 'Mensual · '.$label_periodo_actual : 'Semana '.$semana_actual.' · '.$anio_actual) ?></span></h1>
        <p><?= h($subtitle) ?> · Días hábiles: <?= h($label_col_base) ?> <?= h($dias_habiles_base) ?> / <?= h($label_col_actual) ?> <?= h($dias_habiles_actual) ?></p>
    </div>
    <div class="week-nav">
        <a class="week-btn <?= $periodo === 'semanal' ? 'week-current' : '' ?>" href="?<?= qs(array_merge($_GET, ['periodo'=>'semanal'])) ?>">Semanal</a>
        <a class="week-btn <?= $periodo === 'mensual' ? 'week-current' : '' ?>" href="?<?= qs(array_merge($_GET, ['periodo'=>'mensual'])) ?>">Mensual</a>
        <?php if ($periodo === 'semanal'): ?>
            <a class="week-btn <?= $has_prev ? '' : 'disabled' ?>" href="?<?= qs(array_merge($_GET, ['periodo'=>'semanal','anio'=>$prev_anio,'semana'=>$prev_semana])) ?>">← Semana <?= h($prev_semana) ?></a>
            <div class="range-dropdown week-range-dropdown">
                <button type="button" class="week-current range-trigger" id="weekRangeTrigger"><?= h($label_periodo_actual) ?></button>
                <div class="range-panel week-range-panel" id="weekRangePanel">
                    <form method="get" id="weekRangeForm">
                        <?php foreach ($_GET as $k => $v): ?>
                            <?php if (!in_array($k, ['dias_semana'], true)): ?>
                                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <input type="hidden" name="periodo" value="semanal">
                        <input type="hidden" name="dias_semana" id="diasSemanaInput" value="<?= h(implode(',', $dias_semana_seleccionados)) ?>">

                        <div class="range-panel-title">Selecciona días comparables de Semana <?= h($semana_actual) ?> · <?= h($anio_actual) ?></div>
                        <div class="calendar-grid-real weekday-grid-real" id="weekdayGrid">
                            <div class="calendar-weekday">Lun</div>
                            <div class="calendar-weekday">Mar</div>
                            <div class="calendar-weekday">Mié</div>
                            <div class="calendar-weekday">Jue</div>
                            <div class="calendar-weekday">Vie</div>
                            <div class="calendar-weekday">Sáb</div>
                            <div class="calendar-weekday">Dom</div>

                            <?php for ($dia_num = 1; $dia_num <= 7; $dia_num++): ?>
                                <?php
                                    $disabled_week_day = !in_array($dia_num, $dias_semana_disponibles, true);
                                    $selected_week_day = in_array($dia_num, $dias_semana_seleccionados, true);
                                    $week_day_class = $selected_week_day ? 'selected-start selected-end' : '';
                                    if ($disabled_week_day) $week_day_class .= ' disabled-day';
                                ?>
                                <button type="button"
                                        class="calendar-day weekday-day <?= h(trim($week_day_class)) ?>"
                                        data-day="<?= h($dia_num) ?>"
                                        <?= $disabled_week_day ? 'disabled' : '' ?>>
                                    <?= h($dias_semana_labels[$dia_num]) ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                        <div class="range-actions">
                            <button type="button" class="quick-week" id="weekFullBtn">Semana completa disponible</button>
                            <button type="submit">Aplicar</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php if ($has_next): ?>
                <a class="week-btn" href="?<?= qs(array_merge($_GET, ['periodo'=>'semanal','anio'=>$next_anio,'semana'=>$next_semana])) ?>">Semana <?= h($next_semana) ?> →</a>
            <?php else: ?>
                <span class="week-btn disabled">Semana <?= h($next_semana) ?> →</span>
            <?php endif; ?>
        <?php else:
            $prev_mes_nav = $mes_actual - 1; $prev_anio_mes_nav = $anio_mes_actual;
            if ($prev_mes_nav < 1) { $prev_mes_nav = 12; $prev_anio_mes_nav--; }
            $next_mes_nav = $mes_actual + 1; $next_anio_mes_nav = $anio_mes_actual;
            if ($next_mes_nav > 12) { $next_mes_nav = 1; $next_anio_mes_nav++; }
        ?>
            <?php
                $has_next_month = (
                    $next_anio_mes_nav < $ultimo_anio_datos
                    || ($next_anio_mes_nav == $ultimo_anio_datos && $next_mes_nav <= $ultimo_mes_datos)
                );
            ?>
            <div class="month-nav-row">
                <a class="week-btn" href="?<?= qs(array_merge($_GET, ['periodo'=>'mensual','anio_mes'=>$prev_anio_mes_nav,'mes'=>$prev_mes_nav,'rango_mode'=>'mtd','dia_inicio'=>1,'dia_fin'=>null,'fecha_inicio'=>null,'fecha_fin'=>null])) ?>">← <?= h($meses_es[$prev_mes_nav]) ?></a>
                <div class="range-dropdown">
                    <button type="button" class="week-current range-trigger" id="rangeTrigger"><?= h($label_periodo_actual) ?></button>
                    <div class="range-panel" id="rangePanel">
                        <form method="get" id="rangeForm">
                            <?php foreach ($_GET as $k => $v): ?>
                                <?php if (!in_array($k, ['dia_inicio','dia_fin','fecha_inicio','fecha_fin','rango_mode'], true)): ?>
                                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <input type="hidden" name="rango_mode" value="custom">
                            <input type="hidden" name="dia_inicio" id="diaInicioInput" value="<?= h($dia_inicio_actual) ?>">
                            <input type="hidden" name="dia_fin" id="diaFinInput" value="<?= h($dia_fin_actual) ?>">

                            <div class="range-panel-title">Selecciona rango de <?= h($meses_es[$mes_actual]) ?> <?= h($anio_mes_actual) ?></div>
                            <div class="calendar-grid-real" id="calendarGrid"
     data-days="<?= h($ultimo_dia_actual) ?>"
     data-start="<?= h($dia_inicio_actual) ?>"
     data-end="<?= h($dia_fin_actual) ?>">

    <div class="calendar-weekday">Lun</div>
    <div class="calendar-weekday">Mar</div>
    <div class="calendar-weekday">Mié</div>
    <div class="calendar-weekday">Jue</div>
    <div class="calendar-weekday">Vie</div>
    <div class="calendar-weekday">Sáb</div>
    <div class="calendar-weekday">Dom</div>

    <?php for ($i = 1; $i < $primer_dia_semana; $i++): ?>
        <div class="calendar-empty"></div>
    <?php endfor; ?>

    <?php for ($d = 1; $d <= $ultimo_dia_actual; $d++): ?>
        <?php
            $classes = [];

            if ($d == $dia_inicio_actual) {
                $classes[] = 'selected-start';
            }

            if ($d == $dia_fin_actual) {
                $classes[] = 'selected-end';
            }

            if ($d > $dia_inicio_actual && $d < $dia_fin_actual) {
                $classes[] = 'in-range';
            }
        ?>

        <button
            type="button"
            class="calendar-day <?= h(implode(' ', $classes)) ?>"
            data-day="<?= h($d) ?>"
        >
            <?= h($d) ?>
        </button>
    <?php endfor; ?>

</div>

                            <div class="range-summary">
                                <span id="rangeSummary"><?= h($meses_es[$mes_base]) ?> <?= h($dia_inicio_base) ?>-<?= h($dia_fin_base) ?> vs <?= h($meses_es[$mes_actual]) ?> <?= h($dia_inicio_actual) ?>-<?= h($dia_fin_actual) ?></span>
                            </div>

		<div class="range-actions">
    			<a class="quick-range <?= ($rango_mode === 'completo') ? 'active' : '' ?>"
       				style="<?= ($rango_mode === 'completo') ? 'background:linear-gradient(135deg,#7A2BFF,#FF0AC8)!important;color:#fff!important;box-shadow:0 8px 18px rgba(122,43,255,.18)!important;' : '' ?>"
       					href="?<?= qs(array_merge($_GET, [
            					'periodo'=>'mensual',
            					'rango_mode'=>'completo',
            					'dia_inicio'=>null,
            					'dia_fin'=>null,
            					'fecha_inicio'=>null,
            					'fecha_fin'=>null
        			])) ?>">Mes completo</a>

    			<button type="submit">Aplicar</button>
		</div>

                        </form>
                    </div>
                </div>
                <?php if ($has_next_month): ?>
                    <a class="week-btn" href="?<?= qs(array_merge($_GET, ['periodo'=>'mensual','anio_mes'=>$next_anio_mes_nav,'mes'=>$next_mes_nav,'rango_mode'=>'mtd','dia_inicio'=>1,'dia_fin'=>null,'fecha_inicio'=>null,'fecha_fin'=>null])) ?>"><?= h($meses_es[$next_mes_nav]) ?> →</a>
                <?php else: ?>
                    <span class="week-btn disabled"><?= h($meses_es[$next_mes_nav]) ?> →</span>
                <?php endif; ?>
            </div>


        <?php endif; ?>
    </div>
</section>
<section class="breadcrumb-card">
    <div class="breadcrumb-top">
        <div>
            <div class="breadcrumb-title">Nivel de análisis</div>
            <div class="breadcrumb-path">
                <?php if ($view === 'lideres'): ?>
                    <span class="breadcrumb-current">🏆 Ranking Líder</span>
                    <a class="breadcrumb-link" href="<?= h($ranking_coach_link) ?>">👥 Ranking Coach</a>
                <?php elseif ($view === 'ranking_coach'): ?>
                    <a class="breadcrumb-link" href="<?= h($base_link) ?>">🏆 Ranking Líder</a>
                    <span class="breadcrumb-current">👥 Ranking Coach</span>
                <?php else: ?>
                    <a class="breadcrumb-link" href="<?= h($base_link) ?>">🏆 Ranking Líder</a>
                    <a class="breadcrumb-link" href="<?= h($ranking_coach_link) ?>">👥 Ranking Coach</a>
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
            <?php if (!in_array($view, ['lideres','ranking_coach'], true)): ?><div class="context-chip">Distrito: <?= h($distrito_param) ?></div><?php endif; ?>
        </div>
        <div class="level-actions">
            <?php if (!in_array($view, ['lideres','ranking_coach'], true)): ?><a class="level-action" href="<?= h($base_link) ?>">← Ver líderes</a><?php endif; ?>
            <?php if ($view === 'vendedores' || $view === 'ventas'): ?><a class="level-action primary" href="<?= h($lider_link) ?>">← Ver coaches</a><?php endif; ?>
            <?php if ($view === 'ventas'): ?><a class="level-action primary" href="<?= h($coach_link) ?>">← Ver vendedores</a><?php endif; ?>
        </div>
    </div>
</section>

<?php if ($query_error): ?><div class="error">Error al generar ranking: <?= h($query_error) ?></div><?php endif; ?>

<?php if (!in_array($view, ['ventas','vendedores'], true)): ?>
<section class="cards">
    <div class="card"><div class="label">Instalaciones <?= h($label_periodo_actual) ?></div><div class="value" id="kpi-ins-actual"><?= fmt_num($tot['ins_sem_actual']) ?></div><div class="hint"><?= h($label_periodo_base) ?>: <span id="kpi-ins-base"><?= fmt_num($tot['ins_sem_base']) ?></span></div></div>
    <div class="card"><div class="label">Diferencia</div><div class="value" id="kpi-dif"><?= fmt_num($tot['dif']) ?></div><div class="hint"><span id="kpi-pct"><?= $tot['pct_dif'] === null ? '-' : fmt_num($tot['pct_dif']).'%' ?></span> vs semana anterior</div></div>
    <div class="card"><div class="label">Prod. diaria <?= h($label_periodo_actual) ?></div><div class="value" id="kpi-prod-actual"><?= fmt_prod($tot['prod_actual']) ?></div><div class="hint"><?= h($label_periodo_base) ?>: <span id="kpi-prod-base"><?= fmt_prod($tot['prod_base']) ?></span></div></div>
    <div class="card"><div class="label">Headcount <?= h($label_periodo_actual) ?></div><div class="value" id="kpi-hc-total"><?= fmt_num($tot['hc_total_actual']) ?></div><div class="hint">Activos <span id="kpi-activo"><?= fmt_num($tot['activo_actual']) ?></span> · Vacantes <span id="kpi-vacante"><?= fmt_num($tot['vacante_actual']) ?></span></div></div>
</section>

<section class="filters">
    <?php if (in_array($view, ['lideres','ranking_coach'], true) && count($districts) > 1): ?>
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
        <span><?= $view === 'lideres' ? 'Click en líder para ver coaches' : (in_array($view, ['coaches','ranking_coach'], true) ? 'Click en coach para ver vendedores' : 'Click en vendedor para ver historial semanal') ?></span>
    </div>
    <div class="table-wrap">
        <table id="rankingTable">
            <thead>
                <tr>
                    <th rowspan="2" class="center">#</th>
                    <th rowspan="2">Distrito</th>
                    <th rowspan="2"><?= h($entity_label) ?></th>
                    <th rowspan="2" class="num sortable" data-key="ins_sem_base">INS<br><?= h($label_col_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="ins_sem_actual">INS<br><?= h($label_col_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="dif">Dif. <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="pct_dif">% Dif. <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_activo_base">HC Activo<br><?= h($label_col_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_activo_actual">HC Activo<br><?= h($label_col_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_sin_venta_base">HC sin INS<br><?= h($label_col_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="num sortable" data-key="hc_sin_venta_actual">HC sin INS<br><?= h($label_col_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="pct_hc_sin_ins_base">% HC sin INS<br><?= h($label_col_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="pct_hc_sin_ins_actual">% HC sin INS<br><?= h($label_col_actual) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="prod_base">PROD. DIARIA<br><?= h($label_col_base) ?> <span class="sort-icon">↕</span></th>
                    <th rowspan="2" class="center sortable" data-key="prod_actual">PROD. DIARIA<br><?= h($label_col_actual) ?> <span class="sort-icon">↕</span></th>
                    <th colspan="2" class="group">Head Count <?= h($label_col_base) ?></th>
                    <th colspan="2" class="group">Head Count <?= h($label_col_actual) ?></th>
                </tr>
                <tr>
                    <th class="num sub-gray">HC Vacante<br><?= h($label_col_base) ?></th><th class="num sub-gray">HC Total<br><?= h($label_col_base) ?></th>
                    <th class="num sub-gray">HC Vacante<br><?= h($label_col_actual) ?></th><th class="num sub-gray">HC Total<br><?= h($label_col_actual) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rank=1; foreach($rows as $r):
                    $href = '';
                    if ($view === 'lideres') {
                        $href = '?' . qs(['periodo'=>$periodo,'anio'=>$anio_actual,'semana'=>$semana_actual,'anio_mes'=>$anio_mes_actual,'mes'=>$mes_actual,'dias_semana'=>implode(',', $dias_semana_seleccionados),'view'=>'coaches','distrito'=>$r['distrito'],'lider'=>$r['lider']]);
                    } elseif (in_array($view, ['coaches','ranking_coach'], true)) {
                        $href = '?' . qs(['periodo'=>$periodo,'anio'=>$anio_actual,'semana'=>$semana_actual,'anio_mes'=>$anio_mes_actual,'mes'=>$mes_actual,'dias_semana'=>implode(',', $dias_semana_seleccionados),'view'=>'vendedores','distrito'=>$r['distrito'],'lider'=>$r['lider'],'coach'=>$r['coach'],'coach_pos'=>$r['coach_pos']]);
                    } elseif ($view === 'vendedores' && !empty($r['folio_empleado'])) {
                        $href = '?' . qs(['periodo'=>$periodo,'anio'=>$anio_actual,'semana'=>$semana_actual,'anio_mes'=>$anio_mes_actual,'mes'=>$mes_actual,'dias_semana'=>implode(',', $dias_semana_seleccionados),'view'=>'ventas','distrito'=>$r['distrito'],'lider'=>$r['lider'],'coach'=>$r['coach'],'coach_pos'=>$r['coach_pos'],'vendedor'=>$r['entidad'],'folio'=>$r['folio_empleado']]);
                    }
                ?>
                <tr class="data-row <?= $href ? 'clickable' : '' ?>" data-href="<?= h($href) ?>" data-district="<?= h($r['distrito']) ?>"
                    <?php foreach(['ins_sem_base','ins_sem_actual','dif','pct_dif','hc_activo_base','hc_activo_actual','hc_sin_venta_base','pct_hc_sin_ins_base','hc_sin_venta_actual','pct_hc_sin_ins_actual','prod_base','prod_actual','activo_base','vacante_base','hc_total_base','activo_actual','vacante_actual','hc_total_actual'] as $k): ?>
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
                    <td class="num"><?= fmt_num($r['hc_sin_venta_base']) ?></td>
                    <td class="num"><?= fmt_num($r['hc_sin_venta_actual']) ?></td>
                    <td class="center"><span class="badge <?= pct_hc_sin_ins_class($r['pct_hc_sin_ins_base'] ?? null) ?>"><?= ($r['pct_hc_sin_ins_base'] ?? null) === null ? '-' : fmt_num($r['pct_hc_sin_ins_base']).'%' ?></span></td>
                    <td class="center"><span class="badge <?= pct_hc_sin_ins_class($r['pct_hc_sin_ins_actual'] ?? null) ?>"><?= ($r['pct_hc_sin_ins_actual'] ?? null) === null ? '-' : fmt_num($r['pct_hc_sin_ins_actual']).'%' ?></span></td>
                    <td class="center"><span class="prod <?= prod_class($r['prod_base']) ?>"><?= fmt_prod($r['prod_base']) ?></span></td>
                    <td class="center"><span class="prod <?= prod_class($r['prod_actual']) ?>"><?= fmt_prod($r['prod_actual']) ?></span></td>
                    <td class="num gray-cell"><?= fmt_num($r['vacante_base']) ?></td>
                    <td class="num gray-cell"><?= fmt_num($r['hc_total_base']) ?></td>
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
                    <td class="num" id="total-hc-sin-base"><?= fmt_num($tot['hc_sin_venta_base']) ?></td>
                    <td class="num" id="total-hc-sin-actual"><?= fmt_num($tot['hc_sin_venta_actual']) ?></td>
                    <td class="center"><span id="total-pct-hc-sin-base" class="badge <?= pct_hc_sin_ins_class($tot['pct_hc_sin_ins_base'] ?? null) ?>"><?= ($tot['pct_hc_sin_ins_base'] ?? null) === null ? '-' : fmt_num($tot['pct_hc_sin_ins_base']).'%' ?></span></td>
                    <td class="center"><span id="total-pct-hc-sin-actual" class="badge <?= pct_hc_sin_ins_class($tot['pct_hc_sin_ins_actual'] ?? null) ?>"><?= ($tot['pct_hc_sin_ins_actual'] ?? null) === null ? '-' : fmt_num($tot['pct_hc_sin_ins_actual']).'%' ?></span></td>
                    <td class="center"><span id="total-prod-base" class="prod <?= prod_class($tot['prod_base']) ?>"><?= fmt_prod($tot['prod_base']) ?></span></td>
                    <td class="center"><span id="total-prod-actual" class="prod <?= prod_class($tot['prod_actual']) ?>"><?= fmt_prod($tot['prod_actual']) ?></span></td>
                    <td class="num gray-cell" data-total-key="vacante_base"><?= fmt_num($tot['vacante_base']) ?></td>
                    <td class="num gray-cell" data-total-key="hc_total_base"><?= fmt_num($tot['hc_total_base']) ?></td>
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
    <div class="card"><div class="label">Ventas acumuladas del coach</div><div class="value"><?= fmt_num($total_coach) ?></div><div class="hint">Semana 1 a <?= h($label_periodo_actual) ?></div></div>
    <div class="card"><div class="label">Vendedores considerados</div><div class="value"><?= fmt_num(count($coach_matrix)) ?></div><div class="hint">Estructura <?= h($label_col_base) ?> o <?= h($label_col_actual) ?></div></div>
    <div class="card"><div class="label">Mejor vendedor</div><div class="value" style="font-size:1.05rem"><?= h($mejor_vendedor ?: '-') ?></div><div class="hint"><?= fmt_num(max(0,$mejor_total)) ?> ventas</div></div>
    <div class="card"><div class="label">Coach</div><div class="value" style="font-size:1.05rem"><?= h($coach_param) ?></div><div class="hint">Líder: <?= h($lider_param) ?></div></div>
</section>

<section class="table-card">
    <div class="table-head">
        <strong>Resumen por vendedor del coach</strong>
        <span>Comparativo <?= h($label_col_base) ?> vs <?= h($label_col_actual) ?> · Mix comercial <?= h($label_col_actual) ?></span>
    </div>
    <div class="table-wrap">
        <table class="sales-table" style="min-width:1280px">
            <thead>
                <tr>
                    <th>Nombre vendedor</th>
                    <th class="center matrix-sortable" data-sort="antiguedad">Antigüedad <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="ins_base">INS<br><?= h($label_col_base) ?> <span class="sort-icon">↕</span></th>
                    <th class="num matrix-sortable" data-sort="ins_actual">INS<br><?= h($label_col_actual) ?> <span class="sort-icon">↕</span></th>
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
                        $mix_total_v = $doble_v + $triple_v;
                        $segmento_total_v = $resid_v + $neg_v;
                        $pct_doble = $mix_total_v > 0 ? round(($doble_v / $mix_total_v) * 100, 0) : null;
                        $pct_triple = $mix_total_v > 0 ? round(($triple_v / $mix_total_v) * 100, 0) : null;
                        $pct_resid = $segmento_total_v > 0 ? round(($resid_v / $segmento_total_v) * 100, 0) : null;
                        $pct_neg = $segmento_total_v > 0 ? round(($neg_v / $segmento_total_v) * 100, 0) : null;
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
    <div class="card"><div class="label">Ventas acumuladas</div><div class="value"><?= fmt_num($total_ventas_hist) ?></div><div class="hint">Semana 1 a <?= h($label_periodo_actual) ?></div></div>
    <div class="card"><div class="label">Mejor semana</div><div class="value">SEM <?= h($best_week) ?></div><div class="hint"><?= fmt_num($best_sales) ?> ventas</div></div>
    <div class="card"><div class="label">Promedio semanal</div><div class="value"><?= $semana_actual > 0 ? fmt_prod($total_ventas_hist / $semana_actual) : '-' ?></div><div class="hint">Año <?= h($anio_actual) ?></div></div>
    <div class="card"><div class="label">Folio empleado</div><div class="value" style="font-size:1.05rem"><?= h($folio_param) ?></div><div class="hint"><?= h($vendedor_param) ?></div></div>
</section>
<section class="table-card">
    <div class="table-head">
        <strong>Ventas semanales del vendedor</strong>
        <span>Semana 1 de <?= h($anio_actual) ?> a <?= h($label_periodo_actual) ?></span>
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
const DIAS_HABILES_BASE = <?= (int)$dias_habiles_base ?>;
const DIAS_HABILES_ACTUAL = <?= (int)$dias_habiles_actual ?>;
const tbody=table.querySelector('tbody');
const totalRow=document.getElementById('totalRow');
const dataRows=()=>[...tbody.querySelectorAll('tr.data-row')];
let activeDistrict='ALL';
let sortState={key:'prod_actual',dir:'desc'};
function num(v){const n=parseFloat(v);return isNaN(n)?0:n}
function fmt0(n){return Math.round(n).toLocaleString('en-US')}
function fmt2(n){return (Math.round(n*100)/100).toFixed(2)}
function pctClass(n){if(n>=5)return'badge up';if(n<=-10)return'badge down-hard';if(n<0)return'badge down';return'badge flat'}
function prodClass(n){if(n>=0.70)return'prod tier-1';if(n>=0.55)return'prod tier-2';if(n>=0.40)return'prod tier-3';return'prod tier-4'}
function hcClass(n){if(n<=2)return'hc-indicator hc-good';if(n<=5)return'hc-indicator hc-mid';return'hc-indicator hc-bad'}
function pctHcClass(n){if(n===null)return'badge flat';if(n<=5)return'badge up';if(n<=10)return'badge flat';return'badge down-hard'}
function visibleRows(){return dataRows().filter(r=>r.style.display!=='none')}
function applyFilter(){
 dataRows().forEach(r=>{r.style.display=(activeDistrict==='ALL'||r.dataset.district===activeDistrict)?'':'none'});
 recalc();
}
function recalc(){
 const rows=visibleRows();
 rows.forEach((r,i)=>{const rk=r.querySelector('.rank');if(rk)rk.textContent=i+1});
 const keys=['ins_sem_base','ins_sem_actual','dif','hc_activo_base','hc_activo_actual','hc_sin_venta_base','pct_hc_sin_ins_base','hc_sin_venta_actual','pct_hc_sin_ins_actual','activo_base','vacante_base','hc_total_base','activo_actual','vacante_actual','hc_total_actual'];
 const t={};keys.forEach(k=>t[k]=0);
 rows.forEach(r=>keys.forEach(k=>t[k]+=num(r.dataset[k])));
 const pct=t.ins_sem_base>0?Math.round(((t.ins_sem_actual-t.ins_sem_base)/t.ins_sem_base)*100):null;
 const pctHcSinBase=t.hc_activo_base>0?Math.round((t.hc_sin_venta_base/t.hc_activo_base)*100):null;
 const pctHcSinActual=t.hc_activo_actual>0?Math.round((t.hc_sin_venta_actual/t.hc_activo_actual)*100):null;
 const pb=(t.hc_activo_base>0 && DIAS_HABILES_BASE>0)?t.ins_sem_base/t.hc_activo_base/DIAS_HABILES_BASE:null;
 const pa=(t.hc_activo_actual>0 && DIAS_HABILES_ACTUAL>0)?t.ins_sem_actual/t.hc_activo_actual/DIAS_HABILES_ACTUAL:null;
 document.querySelectorAll('[data-total-key]').forEach(td=>td.textContent=fmt0(t[td.dataset.totalKey]||0));
 const p=document.getElementById('total-pct');p.textContent=pct===null?'-':fmt0(pct)+'%';p.className=pct===null?'badge flat':pctClass(pct);
 const hb=document.getElementById('total-hc-sin-base');hb.textContent=fmt0(t.hc_sin_venta_base);
 const ha=document.getElementById('total-hc-sin-actual');ha.textContent=fmt0(t.hc_sin_venta_actual);
 const phb=document.getElementById('total-pct-hc-sin-base');if(phb){phb.textContent=pctHcSinBase===null?'-':fmt0(pctHcSinBase)+'%';phb.className=pctHcClass(pctHcSinBase);}
 const pha=document.getElementById('total-pct-hc-sin-actual');if(pha){pha.textContent=pctHcSinActual===null?'-':fmt0(pctHcSinActual)+'%';pha.className=pctHcClass(pctHcSinActual);}
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


<script>
(function(){
    const dropdown = document.querySelector('.range-dropdown');
    const trigger = document.getElementById('rangeTrigger');
    const panel = document.getElementById('rangePanel');
    const grid = document.getElementById('calendarGrid');
    const startInput = document.getElementById('diaInicioInput');
    const endInput = document.getElementById('diaFinInput');
    const summary = document.getElementById('rangeSummary');

    if(!dropdown || !trigger || !grid || !startInput || !endInput) return;

    let start = parseInt(grid.dataset.start || startInput.value || '1', 10);
    let end = parseInt(grid.dataset.end || endInput.value || start, 10);
    let clickMode = 'start';

    function paint(){
        if(start > end){ const t = start; start = end; end = t; }
        startInput.value = start;
        endInput.value = end;

        grid.querySelectorAll('.calendar-day').forEach(btn=>{
            const d = parseInt(btn.dataset.day, 10);
            btn.classList.toggle('selected-start', d === start);
            btn.classList.toggle('selected-end', d === end);
            btn.classList.toggle('in-range', d > start && d < end);
        });

        if(summary){
            summary.textContent = 'Rango seleccionado: día ' + start + ' al ' + end;
        }
    }

    trigger.addEventListener('click', (e)=>{
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    panel.addEventListener('click', (e)=>e.stopPropagation());

    document.addEventListener('click', ()=>{
        dropdown.classList.remove('open');
    });

    grid.querySelectorAll('.calendar-day').forEach(btn=>{
        btn.addEventListener('click', ()=>{
            const d = parseInt(btn.dataset.day, 10);
            if(clickMode === 'start'){
                start = d;
                end = d;
                clickMode = 'end';
            }else{
                end = d;
                clickMode = 'start';
            }
            paint();
        });
    });

    paint();
})();
</script>


<script>
(function(){
    const dropdown = document.querySelector('body.page-ranking .week-range-dropdown');
    const trigger = document.getElementById('weekRangeTrigger');
    const panel = document.getElementById('weekRangePanel');
    const grid = document.getElementById('weekdayGrid');
    const input = document.getElementById('diasSemanaInput');
    const fullBtn = document.getElementById('weekFullBtn');

    if(!dropdown || !trigger || !grid || !input) return;

    function buttons(){
        return [...grid.querySelectorAll('.weekday-day:not(:disabled)')];
    }

    function selectedDays(){
        return buttons()
            .filter(btn => btn.classList.contains('selected-start'))
            .map(btn => parseInt(btn.dataset.day, 10))
            .filter(n => n >= 1 && n <= 7)
            .sort((a,b)=>a-b);
    }

    function sync(){
        let days = selectedDays();

        if(days.length === 0){
            const first = buttons()[0];
            if(first) first.classList.add('selected-start','selected-end');
            days = selectedDays();
        }

        input.value = days.join(',');
    }

    function markSelected(btn, selected){
        btn.classList.toggle('selected-start', selected);
        btn.classList.toggle('selected-end', selected);
    }

    trigger.addEventListener('click', (e)=>{
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    panel.addEventListener('click', (e)=>e.stopPropagation());

    document.addEventListener('click', ()=>{
        dropdown.classList.remove('open');
    });

    buttons().forEach(btn=>{
        btn.addEventListener('click', ()=>{
            const selected = !(btn.classList.contains('selected-start') || btn.classList.contains('selected-end'));
            markSelected(btn, selected);
            sync();
        });
    });

    if(fullBtn){
        fullBtn.addEventListener('click', ()=>{
            buttons().forEach(btn=>markSelected(btn, true));
            sync();
        });
    }

    sync();
})();
</script>

</body>
</html>
