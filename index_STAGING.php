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

    /*
    |--------------------------------------------------------------------------
    | HIC-Fallback v1.0 en jerarquía
    |--------------------------------------------------------------------------
    | Cuando un Coach/Líder migra de nómina, algunos vendedores pueden conservar
    | en HC una referencia de posicion_lr anterior/nueva. Por eso la búsqueda de
    | subordinados no debe limitarse únicamente al id_posicion vigente: debe
    | considerar también las posiciones equivalentes en historial_identidad_colaborador.
    |
    | Esto corrige casos como CARRILLO CAMACHO FRANCISCO MAURICIO, donde el
    | Ranking Coach sí trae sus vendedores/instalaciones, pero el Dashboard dejaba
    | el renglón en 0 al reconstruir la jerarquía sólo por posicion_lr exacta.
    |--------------------------------------------------------------------------
    */
    $posiciones_lr = [$id_pos];
    if (function_exists('tx_hic_aliases_posiciones_dashboard')) {
        $aliases_pos = tx_hic_aliases_posiciones_dashboard($conexion, [$id_pos]);
        if (!empty($aliases_pos)) {
            $posiciones_lr = array_values(array_unique(array_merge($posiciones_lr, $aliases_pos)));
        }
    }

    $ph = implode(',', array_fill(0, count($posiciones_lr), '?'));

    if ($semana && $anio) {
        $stmt = mysqli_prepare($conexion, "SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr IN ($ph) AND numero_talento_gs NOT LIKE '%VACANTE%' AND semana = ? AND anio = ?");
        $tipos = str_repeat('s', count($posiciones_lr)) . 'ii';
        $bind = array_merge(array_values($posiciones_lr), [(int)$semana, (int)$anio]);
        mysqli_stmt_bind_param($stmt, $tipos, ...$bind);
    } else {
        $stmt = mysqli_prepare($conexion, "SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr IN ($ph) AND numero_talento_gs NOT LIKE '%VACANTE%'");
        $tipos = str_repeat('s', count($posiciones_lr));
        mysqli_stmt_bind_param($stmt, $tipos, ...array_values($posiciones_lr));
    }
    if (!$stmt) return $ids;
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $ids[] = $row['id_posicion'];
    mysqli_stmt_close($stmt);
    return array_unique(array_values($ids));
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

function getFoliosPorPosiciones($conexion, $posiciones_ids) {
    $folios = [];
    if (empty($posiciones_ids)) return $folios;

    $ph = implode(',', array_fill(0, count($posiciones_ids), '?'));
    $stmt = mysqli_prepare($conexion, "SELECT DISTINCT numero_talento_gs FROM hc WHERE id_posicion IN ($ph) AND numero_talento_gs NOT LIKE '%VACANTE%' AND numero_talento_gs <> ''");
    if (!$stmt) return $folios;

    $tipos = str_repeat('s', count($posiciones_ids));
    mysqli_stmt_bind_param($stmt, $tipos, ...array_values($posiciones_ids));
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        if (!empty($row['numero_talento_gs'])) $folios[] = $row['numero_talento_gs'];
    }
    mysqli_stmt_close($stmt);

    return array_unique(array_values($folios));
}

function nivel_dashboard_hc($posicion, $puesto_lr) {
    $p = strtoupper((string)$posicion);

    if (strpos($p, 'DIRECTOR DISTRITAL') !== false) return 'director_distrital';
    if (strpos($p, 'LIDER VENTAS') !== false) return 'lider';
    if (strpos($p, 'COACH') !== false) return 'coach';
    if (
        $p === 'VENDEDOR'
        || $p === 'VENDEDOR NEGOCIOS'
        || $p === 'VENDEDOR NEGOCIO'
        || strpos($p, 'PROMOVENDEDOR PUNTO DE VENTA') !== false
    ) return 'vendedor';

    return 'otro';
}


function tx_meta_propia_operativa_dashboard($conexion, $anio, $semana, $id_posicion) {
    $sql = "
        SELECT meta_asignada
        FROM ejecucion_operativa_metas
        WHERE anio = ?
          AND semana = ?
          AND id_subordinado = ?
          AND meta_asignada > 0
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['meta_asignada'] ?? 0);
}

function label_nivel_dashboard($nivel) {
    $labels = [
        'director_distrital' => 'Directores Distritales',
        'lider' => 'Líderes',
        'coach' => 'Coaches',
        'vendedor' => 'Vendedores',
        'otro' => 'Otros'
    ];
    return $labels[$nivel] ?? 'Otros';
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

/*
|--------------------------------------------------------------------------
| TOTALXPEDIENT - IDENTIDAD DEL USUARIO EN DASHBOARD
|--------------------------------------------------------------------------
|
| FIX 2026-06:
| Antes se buscaba el nombre únicamente por id_posicion con LIMIT 1.
| Eso podía mostrar al ocupante anterior del puesto cuando una posición
| había cambiado de colaborador.
|
| Nueva regla:
| 1) Buscar primero por numero_talento_gs en la semana/año vigente.
| 2) Si no existe, respaldar por id_posicion en la semana/año vigente.
| 3) Si tampoco existe, tomar el último registro histórico del id_posicion.
|--------------------------------------------------------------------------
*/
$nombre_completo  = $nombre_usuario;
$posicion_usuario = '';
$distrito_usuario = '';

if (!empty($talento_gs) && $semana_actual && $anio_actual) {
    $stmt_nombre = mysqli_prepare($conexion, "
        SELECT nombre_colaborador, posicion, distrito, id_posicion
        FROM hc
        WHERE numero_talento_gs = ?
          AND semana = ?
          AND anio = ?
        ORDER BY anio DESC, semana DESC, id DESC
        LIMIT 1
    ");
    if ($stmt_nombre) {
        mysqli_stmt_bind_param($stmt_nombre, "sii", $talento_gs, $semana_actual, $anio_actual);
        mysqli_stmt_execute($stmt_nombre);
        $res_nombre = mysqli_stmt_get_result($stmt_nombre);
        if ($row_nombre = mysqli_fetch_assoc($res_nombre)) {
            $nombre_completo  = $row_nombre['nombre_colaborador'] ?? $nombre_usuario;
            $posicion_usuario = $row_nombre['posicion'] ?? '';
            $distrito_usuario = $row_nombre['distrito'] ?? '';
            // Refresca id_posicion en memoria si el HC vigente lo trae actualizado.
            if (!empty($row_nombre['id_posicion'])) {
                $id_posicion = $row_nombre['id_posicion'];
            }
        }
        mysqli_stmt_close($stmt_nombre);
    }
}

if (($nombre_completo === $nombre_usuario || $posicion_usuario === '') && !empty($id_posicion) && $semana_actual && $anio_actual) {
    $stmt_nombre = mysqli_prepare($conexion, "
        SELECT nombre_colaborador, posicion, distrito
        FROM hc
        WHERE id_posicion = ?
          AND semana = ?
          AND anio = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($stmt_nombre) {
        mysqli_stmt_bind_param($stmt_nombre, "sii", $id_posicion, $semana_actual, $anio_actual);
        mysqli_stmt_execute($stmt_nombre);
        $res_nombre = mysqli_stmt_get_result($stmt_nombre);
        if ($row_nombre = mysqli_fetch_assoc($res_nombre)) {
            $nombre_completo  = $row_nombre['nombre_colaborador'] ?? $nombre_usuario;
            $posicion_usuario = $row_nombre['posicion'] ?? '';
            $distrito_usuario = $row_nombre['distrito'] ?? '';
        }
        mysqli_stmt_close($stmt_nombre);
    }
}

if (($nombre_completo === $nombre_usuario || $posicion_usuario === '') && !empty($id_posicion)) {
    $stmt_nombre = mysqli_prepare($conexion, "
        SELECT nombre_colaborador, posicion, distrito
        FROM hc
        WHERE id_posicion = ?
        ORDER BY anio DESC, semana DESC, id DESC
        LIMIT 1
    ");
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

    // Parche HIC-Fallback v1.0 global:
    // A partir de aquí, los KPIs y tarjetas del dashboard que consultan por folio
    // deben incluir folios históricos equivalentes. El HC se sigue calculando por
    // id_posicion vigente para no inflar plantilla/productividad.
    if (!empty($subordinados_ids)) {
        // HIC-Fallback v1.0: tomar folios vigentes + históricos desde las posiciones
        // autorizadas para que KPIs y tarjetas cuadren con ranking_productividad.php.
        $folio_ids = tx_hic_folios_por_posiciones_dashboard($conexion, $subordinados_ids);
    } elseif (!empty($folio_ids)) {
        $folio_ids = tx_hic_aliases_folios_dashboard($conexion, $folio_ids);
    }
}

// ── VISTA JERÁRQUICA DEL DASHBOARD ───────────────────────────────────────────
// Por default el dashboard carga la posición del usuario.
// Si se elige una posición subordinada, se recalculan KPIs, mix y evolución para su línea completa.
$scope_pos = $_GET['scope_pos'] ?? '';
$scope_activo = false;
$scope_registro = null;
$scope_nivel = '';
$scope_distritos_sql = '';
$scope_filtrar_por_distrito = false;
$scope_ids = [];
$scope_autorizado_ids = [];
$jerarquia_opciones = [
    'director_distrital' => [],
    'lider' => [],
    'coach' => [],
    'vendedor' => []
];

if ($rol === 'admin') {
    $scope_autorizado_ids = null; // admin puede consultar cualquier posición comercial/jerárquica.
} else {
    $scope_autorizado_ids = getTodosSubordinados($conexion, $id_posicion, $nivel, $semana_actual, $anio_actual);
    $scope_autorizado_ids[] = $id_posicion;
    $scope_autorizado_ids = array_unique(array_values($scope_autorizado_ids));
}

$where_autorizado_opciones = "";
if (is_array($scope_autorizado_ids)) {
    if (!empty($scope_autorizado_ids)) {
        $ids_esc = array_map(function($id) use ($conexion) {
            return "'" . mysqli_real_escape_string($conexion, (string)$id) . "'";
        }, $scope_autorizado_ids);
        $where_autorizado_opciones = " AND id_posicion IN (" . implode(',', $ids_esc) . ")";
    } else {
        $where_autorizado_opciones = " AND 1=0";
    }
}

$sql_opciones = "
    SELECT DISTINCT id_posicion, nombre_colaborador, posicion, puesto_lr, distrito
    FROM hc
    WHERE semana = " . (int)$semana_actual . "
      AND anio = " . (int)$anio_actual . "
      AND nombre_colaborador <> 'VACANTE'
      AND numero_talento_gs NOT LIKE '%VACANTE%'
      $where_autorizado_opciones
    ORDER BY distrito, posicion, nombre_colaborador
";
$res_opciones = mysqli_query($conexion, $sql_opciones);
while ($res_opciones && $row_op = mysqli_fetch_assoc($res_opciones)) {
    $nivel_op = nivel_dashboard_hc($row_op['posicion'] ?? '', $row_op['puesto_lr'] ?? '');
    if (isset($jerarquia_opciones[$nivel_op])) {
        $jerarquia_opciones[$nivel_op][] = $row_op;
    }
}

if ($scope_pos !== '') {
    $scope_pos_esc = mysqli_real_escape_string($conexion, $scope_pos);
    $sql_scope = "
        SELECT id_posicion, nombre_colaborador, posicion, puesto_lr, distrito
        FROM hc
        WHERE id_posicion = '$scope_pos_esc'
          AND semana = " . (int)$semana_actual . "
          AND anio = " . (int)$anio_actual . "
        LIMIT 1
    ";
    $res_scope = mysqli_query($conexion, $sql_scope);
    if ($res_scope && $row_scope = mysqli_fetch_assoc($res_scope)) {
        $permitido = ($rol === 'admin') || (is_array($scope_autorizado_ids) && in_array($row_scope['id_posicion'], $scope_autorizado_ids, true));
        if ($permitido) {
            $scope_activo = true;
            $scope_registro = $row_scope;
            $scope_ids = getTodosSubordinados($conexion, $row_scope['id_posicion'], 6, $semana_actual, $anio_actual);
            $scope_ids[] = $row_scope['id_posicion'];
            $scope_ids = array_unique(array_values($scope_ids));

            $folio_ids = tx_hic_folios_por_posiciones_dashboard($conexion, $scope_ids);
            // Parche HIC-Fallback v1.0 global para scope jerárquico:
            // suma instalaciones/ventas históricas por folios equivalentes sin alterar HC.
            if (!empty($folio_ids)) {
                $folio_ids = tx_hic_aliases_folios_dashboard($conexion, $folio_ids);
            }
            $subordinados_ids = $scope_ids;

            $nombre_completo_scope = $row_scope['nombre_colaborador'] ?? '';
            $posicion_scope = $row_scope['posicion'] ?? '';
            $distrito_scope = $row_scope['distrito'] ?? $distrito_usuario;
            $scope_nivel = nivel_dashboard_hc($posicion_scope, $row_scope['puesto_lr'] ?? '');

            // Para Director Distrital el dashboard debe cuadrar contra el índice base por distrito,
            // no por folios de HC. Esto evita perder instalaciones cargadas con distrito correcto
            // pero sin folio/hc perfectamente amarrado.
            if ($scope_nivel === 'director_distrital') {
                $scope_filtrar_por_distrito = true;
                $scope_distritos_equivalentes = [$distrito_scope];
                if ($distrito_scope === 'COATZA MINA') {
                    $scope_distritos_equivalentes[] = 'COATZA / MINA';
                } elseif ($distrito_scope === 'COATZA / MINA') {
                    $scope_distritos_equivalentes[] = 'COATZA MINA';
                }
                $scope_distritos_equivalentes = array_unique($scope_distritos_equivalentes);
                $scope_distritos_sql = "'" . implode("','", array_map(function($d) use ($conexion) {
                    return mysqli_real_escape_string($conexion, $d);
                }, $scope_distritos_equivalentes)) . "'";
            }
        }
    }
}

$rol_consulta = $scope_activo ? 'scoped' : $rol;

// ── NAVEGACIÓN CANÓNICA — PARIDAD CON RANKING PRODUCTIVIDAD ──────────────
// Semana ISO (lunes-domingo), días comparables y calendario de 42 celdas.
// Los filtros son siempre fechas reales; una selección cruzada no se trunca
// a los días de un único mes. El rol/jerarquía continúa independiente.
$periodo = ($_GET['periodo'] ?? 'mensual') === 'semanal' ? 'semanal' : 'mensual';
$meses_es = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];
$tx_hoy = new DateTimeImmutable('today');
$tx_ayer = $tx_hoy->modify('-1 day');
$res_tx_ultima = mysqli_query($conexion, 'SELECT MAX(fecha) AS ultima_fecha FROM instalaciones WHERE fecha <= CURDATE()');
$row_tx_ultima = $res_tx_ultima ? mysqli_fetch_assoc($res_tx_ultima) : null;
$tx_ultima_fecha_operativa = (string)($row_tx_ultima['ultima_fecha'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tx_ultima_fecha_operativa)) {
    $tx_ultima_fecha_operativa = $tx_ayer->format('Y-m-d');
}
$tx_fecha_limite = new DateTimeImmutable($tx_ultima_fecha_operativa);
$fecha_corte_real_timestamp = strtotime($tx_ultima_fecha_operativa);
$tx_fin_datos_mes = $tx_fecha_limite->format('Y-m');
$tx_nav_semana_max = (int)$tx_fecha_limite->format('W');
$tx_nav_anio_semana_max = (int)$tx_fecha_limite->format('o');
$tx_nav_anio_semana = isset($_GET['anio_semana']) ? (int)$_GET['anio_semana']
    : (($periodo === 'semanal' && isset($_GET['anio'])) ? (int)$_GET['anio'] : $tx_nav_anio_semana_max);
$tx_nav_semana = isset($_GET['semana']) ? (int)$_GET['semana'] : $tx_nav_semana_max;
$tx_nav_anio_semana = max(2020,min(2100,$tx_nav_anio_semana));
$tx_nav_semana = max(1,min(53,$tx_nav_semana));
$tx_semana_lunes = (new DateTimeImmutable('today'))->setISODate($tx_nav_anio_semana,$tx_nav_semana,1);
if ((int)$tx_semana_lunes->format('W') !== $tx_nav_semana || (int)$tx_semana_lunes->format('o') !== $tx_nav_anio_semana) {
    $tx_semana_lunes = $tx_fecha_limite->modify('monday this week');
    $tx_nav_anio_semana = (int)$tx_semana_lunes->format('o');
    $tx_nav_semana = (int)$tx_semana_lunes->format('W');
}
$tx_semana_max_lunes = $tx_fecha_limite->modify('monday this week');
if ($tx_semana_lunes > $tx_semana_max_lunes) {
    $tx_semana_lunes = $tx_semana_max_lunes;
    $tx_nav_anio_semana = (int)$tx_semana_lunes->format('o');
    $tx_nav_semana = (int)$tx_semana_lunes->format('W');
}
$tx_semana_domingo = $tx_semana_lunes->modify('+6 days');
$tx_prev_lunes = $tx_semana_lunes->modify('-7 days');
$tx_next_lunes = $tx_semana_lunes->modify('+7 days');
$tx_semana_has_next = $tx_next_lunes <= $tx_semana_max_lunes;
$tx_dias_semana_labels = [1=>'LUN',2=>'MAR',3=>'MIÉ',4=>'JUE',5=>'VIE',6=>'SÁB',7=>'DOM'];
$tx_dias_semana_disponibles = range(1,7);
if ($tx_semana_lunes == $tx_semana_max_lunes) {
    $tx_dias_semana_disponibles = range(1, (int)$tx_fecha_limite->format('N'));
}
$tx_dias_semana_seleccionados = $tx_dias_semana_disponibles;
if ($periodo === 'semanal' && isset($_GET['dias_semana'])) {
    $tx_dias_solicitados = array_unique(array_map('intval',explode(',',(string)$_GET['dias_semana'])));
    $tx_dias_validos = array_values(array_intersect($tx_dias_semana_disponibles,$tx_dias_solicitados));
    if ($tx_dias_validos) $tx_dias_semana_seleccionados = $tx_dias_validos;
}
$mes_actual = isset($_GET['mes']) ? max(1,min(12,(int)$_GET['mes'])) : (int)$tx_fecha_limite->format('n');
$anio_query = isset($_GET['anio_mes']) ? (int)$_GET['anio_mes'] : (
    isset($_GET['anio']) && $periodo !== 'semanal' ? (int)$_GET['anio'] : (int)$tx_fecha_limite->format('Y'));
$anio_query = max(2020,min((int)$tx_fecha_limite->format('Y'),$anio_query));
if (sprintf('%04d-%02d',$anio_query,$mes_actual) > $tx_fin_datos_mes) {
    $anio_query = (int)$tx_fecha_limite->format('Y');
    $mes_actual = (int)$tx_fecha_limite->format('n');
}
$rango_mode = $_GET['rango_mode'] ?? 'mtd';
if (!in_array($rango_mode,['mtd','completo','custom'],true)) $rango_mode = 'mtd';
$ultimo_dia_mes_actual = (int)date('t',strtotime(sprintf('%04d-%02d-01',$anio_query,$mes_actual)));
$es_mes_actual_real = sprintf('%04d-%02d',$anio_query,$mes_actual) === $tx_fin_datos_mes;
$dia_max_corte = $es_mes_actual_real ? (int)$tx_fecha_limite->format('j') : $ultimo_dia_mes_actual;
// Ranking Productividad: MTD histórico conserva el mismo corte de día del último mes operativo.
$tx_dia_default_mtd = min($ultimo_dia_mes_actual, (int)$tx_fecha_limite->format('j'));
$primer_dia_semana_mes = (int)date('N',strtotime(sprintf('%04d-%02d-01',$anio_query,$mes_actual)));
$tx_dashboard_dias_iso = [];

if ($periodo === 'semanal') {
    $rango_mode = 'semanal';
    $tx_fechas_seleccionadas = [];
    foreach ($tx_dias_semana_seleccionados as $diaISO) {
        $f = $tx_semana_lunes->modify('+'.($diaISO-1).' days')->format('Y-m-d');
        if ($f <= $tx_ultima_fecha_operativa) $tx_fechas_seleccionadas[] = $f;
    }
    sort($tx_fechas_seleccionadas);
    $fecha_inicio_dashboard = $tx_fechas_seleccionadas[0];
    $fecha_fin_dashboard = end($tx_fechas_seleccionadas);
    $tx_dashboard_dias_iso = $tx_dias_semana_seleccionados;
    $dias_rango_dashboard = count($tx_fechas_seleccionadas);
    // Fecha operativa semanal puede cruzar meses. Para las tendencias se usa
    // el mes del último evento del corte, no se fuerza una fecha del mes anterior.
    $mes_actual = (int)date('n',strtotime($fecha_fin_dashboard));
    $anio_query = (int)date('Y',strtotime($fecha_fin_dashboard));
} else {
    if ($rango_mode === 'completo') {
        $fecha_inicio_dashboard = sprintf('%04d-%02d-01',$anio_query,$mes_actual);
        $fecha_fin_dashboard = sprintf('%04d-%02d-%02d',$anio_query,$mes_actual,$dia_max_corte);
    } elseif ($rango_mode === 'custom' && !empty($_GET['fecha_inicio']) && !empty($_GET['fecha_fin'])) {
        $tx_inicio_elegido = DateTimeImmutable::createFromFormat('!Y-m-d',(string)$_GET['fecha_inicio']);
        $tx_fin_elegido = DateTimeImmutable::createFromFormat('!Y-m-d',(string)$_GET['fecha_fin']);
        if ($tx_inicio_elegido && $tx_fin_elegido && $tx_inicio_elegido->format('Y-m-d') === $_GET['fecha_inicio']
            && $tx_fin_elegido->format('Y-m-d') === $_GET['fecha_fin']) {
            if ($tx_inicio_elegido > $tx_fin_elegido) [$tx_inicio_elegido,$tx_fin_elegido] = [$tx_fin_elegido,$tx_inicio_elegido];
            if ($tx_fin_elegido > $tx_fecha_limite) $tx_fin_elegido = $tx_fecha_limite;
            if ($tx_inicio_elegido > $tx_fin_elegido) $tx_inicio_elegido = $tx_fin_elegido;
            $fecha_inicio_dashboard = $tx_inicio_elegido->format('Y-m-d');
            $fecha_fin_dashboard = $tx_fin_elegido->format('Y-m-d');
        } else $rango_mode = 'mtd';
    }
    if (!isset($fecha_inicio_dashboard)) {
        $dia_inicio_dashboard = ($rango_mode === 'custom') ? max(1,min($dia_max_corte,(int)($_GET['dia_inicio']??1))) : 1;
        $dia_fin_dashboard = ($rango_mode === 'custom') ? max(1,min($dia_max_corte,(int)($_GET['dia_fin']??$dia_max_corte))) : $tx_dia_default_mtd;
        if ($dia_inicio_dashboard > $dia_fin_dashboard) [$dia_inicio_dashboard,$dia_fin_dashboard]=[$dia_fin_dashboard,$dia_inicio_dashboard];
        $fecha_inicio_dashboard = sprintf('%04d-%02d-%02d',$anio_query,$mes_actual,$dia_inicio_dashboard);
        $fecha_fin_dashboard = sprintf('%04d-%02d-%02d',$anio_query,$mes_actual,$dia_fin_dashboard);
    }
    $dias_rango_dashboard = (int)(new DateTimeImmutable($fecha_inicio_dashboard))->diff(new DateTimeImmutable($fecha_fin_dashboard))->days + 1;
}
$tx_dashboard_cruza_mes = substr($fecha_inicio_dashboard,0,7) !== substr($fecha_fin_dashboard,0,7);
// Meses históricos completos ANTES del primer mes elegido; primer mes parcial
// y último mes parcial. El Mix circular usa solo las fechas seleccionadas.
$tx_evo_inicio_rango_cruzado = ($periodo === 'mensual' && $tx_dashboard_cruza_mes)
    ? $fecha_inicio_dashboard : null;
$tx_evo_filtro_mes_inicio = $tx_evo_inicio_rango_cruzado !== null
    ? substr($fecha_inicio_dashboard,0,7).'-01' : null;
$dia_inicio_dashboard = (int)date('j',strtotime($fecha_inicio_dashboard));
$dia_fin_dashboard = (int)date('j',strtotime($fecha_fin_dashboard));
$fecha_corte_timestamp = strtotime($fecha_fin_dashboard);
$semana_operativa_dashboard = (int)date('W',$fecha_corte_timestamp);
$anio_operativo_dashboard = (int)date('o',$fecha_corte_timestamp);
$ayer_timestamp = $fecha_corte_timestamp;
$dia_ayer = $dia_fin_dashboard;
$dias_transcurridos = $dias_rango_dashboard;
$tx_esc_ini = mysqli_real_escape_string($conexion,$fecha_inicio_dashboard);
$tx_esc_fin = mysqli_real_escape_string($conexion,$fecha_fin_dashboard);
$tx_dashboard_dow_sql = $periodo === 'semanal'
    ? ' AND DAYOFWEEK(%s) IN ('.implode(',',array_map(static fn($d) => $d === 7 ? 1 : $d+1,$tx_dashboard_dias_iso)).')' : '';
function tx_dashboard_where_fecha(string $campo): string {
    global $tx_esc_ini,$tx_esc_fin,$tx_dashboard_dow_sql;
    if (!in_array($campo,['fecha','fecha_cierre','i.fecha','v.fecha_cierre'],true)) throw new InvalidArgumentException('Columna de fecha no autorizada');
    $f = str_replace('%s',$campo,$tx_dashboard_dow_sql);
    return "$campo >= '$tx_esc_ini' AND $campo <= '$tx_esc_fin'".$f;
}
$tx_dashboard_where_inst = tx_dashboard_where_fecha('fecha');
$tx_dashboard_where_ventas = tx_dashboard_where_fecha('fecha_cierre');
$tx_dashboard_filtro_inst = ' AND '.$tx_dashboard_where_inst;
$tx_dashboard_filtro_ventas = ' AND '.$tx_dashboard_where_ventas;
// Adaptación de funciones históricas que reciben mes/año a períodos cruzados.
$cond_dia_fecha = $periodo === 'mensual' && substr($fecha_inicio_dashboard,0,7) === substr($fecha_fin_dashboard,0,7)
    ? ' AND DAY(fecha) BETWEEN '.$dia_inicio_dashboard.' AND '.$dia_fin_dashboard : $tx_dashboard_filtro_inst;
$cond_dia_fecha_cierre = $periodo === 'mensual' && substr($fecha_inicio_dashboard,0,7) === substr($fecha_fin_dashboard,0,7)
    ? ' AND DAY(fecha_cierre) BETWEEN '.$dia_inicio_dashboard.' AND '.$dia_fin_dashboard : $tx_dashboard_filtro_ventas;
// Evolución siempre representa series MENSUALES; en modo semanal son referencia
// completa hasta el corte, no se confunden con los KPI de la semana elegida.
$cond_dia_evolucion_fecha = ($periodo === 'semanal' || $rango_mode === 'completo' || $tx_dashboard_cruza_mes) ? '' : $cond_dia_fecha;
$cond_dia_evolucion_fecha_cierre = ($periodo === 'semanal' || $rango_mode === 'completo' || $tx_dashboard_cruza_mes) ? '' : $cond_dia_fecha_cierre;
$tx_month_init = new DateTimeImmutable(sprintf('%04d-%02d-01',$anio_query,$mes_actual));
$mes_prev_ts = $tx_month_init->modify('-1 month')->getTimestamp();
$mes_next_ts = $tx_month_init->modify('+1 month')->getTimestamp();
$mes_prev = (int)date('n',$mes_prev_ts);$anio_prev=(int)date('Y',$mes_prev_ts);
$mes_next = (int)date('n',$mes_next_ts);$anio_next=(int)date('Y',$mes_next_ts);
$puede_mes_next = sprintf('%04d-%02d',$anio_next,$mes_next) <= $tx_fin_datos_mes;
$tx_calendar_month_first = new DateTimeImmutable(sprintf('%04d-%02d-01',$anio_query,$mes_actual));
$tx_calendar_grid_start = $tx_calendar_month_first->modify('-'.((int)$tx_calendar_month_first->format('N')-1).' days');
$tx_calendar_max_date = $tx_ultima_fecha_operativa;
if ($periodo === 'semanal') {
    $dashboard_fecha_label = 'Semana '.$tx_nav_semana.' · '.$tx_nav_anio_semana.' · '.date('d/m',strtotime($fecha_inicio_dashboard)).'–'.date('d/m/Y',strtotime($fecha_fin_dashboard));
} elseif (substr($fecha_inicio_dashboard,0,7) !== substr($fecha_fin_dashboard,0,7)) {
    $dashboard_fecha_label = date('d/m/Y',strtotime($fecha_inicio_dashboard)).' – '.date('d/m/Y',strtotime($fecha_fin_dashboard));
} else {
    $dashboard_fecha_label = sprintf('%02d',$dia_inicio_dashboard).'-'.sprintf('%02d',$dia_fin_dashboard).' de '.strtolower($meses_es[$mes_actual]).' '.$anio_query;
}
function tx_dashboard_link(array $changes): string {
    $p = array_merge($_GET,$changes);
    foreach ($p as $key=>$value) if ($value === null || is_array($value)) unset($p[$key]);
    return '?'.htmlspecialchars(http_build_query($p),ENT_QUOTES,'UTF-8');
}
function tx_dashboard_meta_fecha_where(string $alias = ''): string {
    global $periodo,$fecha_inicio_dashboard,$fecha_fin_dashboard,$tx_dashboard_dias_iso;
    $prefix = $alias !== '' ? $alias.'.' : '';
    $d = new DateTimeImmutable($fecha_inicio_dashboard);$end=new DateTimeImmutable($fecha_fin_dashboard);
    $chunks=[];
    while ($d <= $end) {
        $iso=(int)$d->format('N');
        if ($periodo !== 'semanal' || in_array($iso,$tx_dashboard_dias_iso,true)) {
            $chunks[]='('.$prefix.'anio='.(int)$d->format('Y').' AND '.$prefix.'mes_num='.(int)$d->format('n').' AND '.$prefix.'dia='.(int)$d->format('j').')';
        }
        $d=$d->modify('+1 day');
    }
    return $chunks ? '('.implode(' OR ',$chunks).')' : '1=0';
}
// La foto HC del HEADCOUNT es la última semana publicada no posterior al corte.
$tx_iso_hc_year = (int)date('o', $fecha_corte_timestamp);
$tx_iso_hc_week = (int)date('W', $fecha_corte_timestamp);
$tx_hc_res_corte = mysqli_query($conexion, "SELECT anio,semana FROM hc
    WHERE anio < $tx_iso_hc_year OR (anio = $tx_iso_hc_year AND semana <= $tx_iso_hc_week)
    ORDER BY anio DESC,semana DESC LIMIT 1");
if ($tx_hc_res_corte && ($tx_hc_fila_corte = mysqli_fetch_assoc($tx_hc_res_corte))) {
    $anio_actual = (int)$tx_hc_fila_corte['anio'];
    $semana_actual = $semana_base = (int)$tx_hc_fila_corte['semana'];
}
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

$por_distrito = (!$scope_activo && in_array($rol_consulta, ['admin', 'director_regional', 'director_distrital']));

// Contexto jerárquico temprano para activar meta propia en tablero inicial o scope.
// IMPORTANTE: debe calcularse antes de $mostrar_meta.
$nivel_actual_dashboard_pre = $scope_activo
    ? $scope_nivel
    : ($rol === 'admin' ? 'admin' : nivel_dashboard_hc($posicion_usuario ?? '', ''));

$root_dashboard_id_pre = $scope_activo
    ? ($scope_registro['id_posicion'] ?? '')
    : $id_posicion;

$meta_propia_operativa_dashboard = 0;
if (!empty($root_dashboard_id_pre) && in_array($nivel_actual_dashboard_pre, ['lider','coach','vendedor'], true)) {
    // Meta propia de Ejecución Operativa prorrateada contra el rango seleccionado.
    // Evita comparar instalaciones acumuladas de varias semanas contra una sola meta semanal.
    $meta_propia_operativa_dashboard = tx_meta_propia_operativa_periodo_dashboard(
        $conexion,
        $fecha_inicio_dashboard,
        $fecha_fin_dashboard,
        $root_dashboard_id_pre
    );
}

$mostrar_meta = $por_distrito || $scope_filtrar_por_distrito || (($meta_propia_operativa_dashboard ?? 0) > 0);

// ── INSTALACIONES ────────────────────────────────────────────────────────────
if ($rol_consulta === 'admin') {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-'");
} elseif ($scope_filtrar_por_distrito) {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-' AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-' AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_inst = mysqli_query($conexion, "SELECT 0 as total");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_inst = mysqli_prepare($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
        $tipos = str_repeat('s', count($folio_ids));
        $bind  = array_values($folio_ids);
        mysqli_stmt_bind_param($stmt_inst, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_inst);
        $r_inst = mysqli_stmt_get_result($stmt_inst);
    }
}
$kpi_inst = $r_inst ? (mysqli_fetch_assoc($r_inst)['total'] ?? 0) : 0;

// ── VENTAS ───────────────────────────────────────────────────────────────────
if ($rol_consulta === 'admin') {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE $tx_dashboard_where_ventas");
} elseif ($scope_filtrar_por_distrito) {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE $tx_dashboard_where_ventas AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE $tx_dashboard_where_ventas AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_vent = mysqli_query($conexion, "SELECT 0 as total");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_vent = mysqli_prepare($conexion, "SELECT COUNT(*) as total FROM ventas WHERE $tx_dashboard_where_ventas AND folio_empleado IN ($ph)");
        $tipos = str_repeat('s', count($folio_ids));
        $bind  = array_values($folio_ids);
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
    if ($rol_consulta === 'admin') {
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
// Se reutiliza la misma fecha de corte definida arriba.
$ayer_timestamp = $fecha_corte_timestamp;
$dia_ayer           = $dia_fin_dashboard;
$dias_transcurridos = $dias_rango_dashboard; 

$kpi_meta_acum      = 0;
$kpi_meta_pct       = 0;

if ($mostrar_meta) {
    if (($meta_propia_operativa_dashboard ?? 0) > 0 && !$scope_filtrar_por_distrito && !$por_distrito) {
        // Meta asignada al tablero actual desde Ejecución Operativa.
        // Ejemplo: meta del Líder capturada por su Director Distrital.
        $kpi_meta_acum = (int)$meta_propia_operativa_dashboard;
        $kpi_meta_pct  = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
    } else {
        if ($rol_consulta === 'admin') {
            $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE " . tx_dashboard_meta_fecha_where() . "");
        } elseif ($scope_filtrar_por_distrito) {
            $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE " . tx_dashboard_meta_fecha_where() . " AND distrito IN ($scope_distritos_sql)");
        } else {
            $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE " . tx_dashboard_meta_fecha_where() . " AND distrito IN ($distritos_sql)");
        }

        if ($r_meta && $row_meta = mysqli_fetch_assoc($r_meta)) {
            $meta_diaria_total = (float)($row_meta['meta_diaria_total'] ?? 0);
            $kpi_meta_acum     = round($meta_diaria_total);
            $kpi_meta_pct      = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
        }
    }
}


// ── CUMPLIMIENTO NIVEL INFERIOR VS META ──────────────────────────────────────
// Muestra el desempeño del nivel jerárquico inmediato inferior.
// Admin/Regional: Distritos. Director: Líderes. Líder: Coaches. Coach: Vendedores.
function tx_distrito_equivalentes_array($distrito) {
    $d = trim((string)$distrito);
    $arr = [$d];
    if ($d === 'COATZA MINA') $arr[] = 'COATZA / MINA';
    if ($d === 'COATZA / MINA') $arr[] = 'COATZA MINA';
    return array_values(array_unique($arr));
}

function tx_sql_in_escaped($conexion, $vals) {
    $vals = array_values(array_filter(array_unique($vals), function($v) { return trim((string)$v) !== ''; }));
    if (empty($vals)) return "''";
    return "'" . implode("','", array_map(function($v) use ($conexion) {
        return mysqli_real_escape_string($conexion, (string)$v);
    }, $vals)) . "'";
}


function tx_dias_habiles_rango_mes($conexion, $anio, $mes, $dia_inicio, $dia_fin) {
    $inicio = sprintf('%04d-%02d-%02d', (int)$anio, (int)$mes, (int)$dia_inicio);
    $fin    = sprintf('%04d-%02d-%02d', (int)$anio, (int)$mes, (int)$dia_fin);

    // Se excluyen domingos y fechas registradas en dias_inhabiles.
    // Si la tabla dias_inhabiles no existe o no trae datos, al menos excluye domingos.
    $sql = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT DATE_ADD('$inicio', INTERVAL n DAY) AS fecha
            FROM (
                SELECT a.N + b.N * 10 + c.N * 100 AS n
                FROM 
                    (SELECT 0 N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
                    (SELECT 0 N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
                    (SELECT 0 N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c
            ) nums
            WHERE DATE_ADD('$inicio', INTERVAL n DAY) <= '$fin'
        ) calendario
        WHERE DAYOFWEEK(fecha) <> 1
          AND NOT EXISTS (
              SELECT 1
              FROM dias_inhabiles di
              WHERE di.fecha = calendario.fecha
          )
    ";
    $r = mysqli_query($conexion, $sql);
    if ($r && $row = mysqli_fetch_assoc($r)) {
        return (int)($row['total'] ?? 0);
    }

    // Fallback defensivo si algo falla en SQL.
    $count = 0;
    $ts_ini = strtotime($inicio);
    $ts_fin = strtotime($fin);
    for ($ts = $ts_ini; $ts <= $ts_fin; $ts += 86400) {
        if ((int)date('w', $ts) !== 0) $count++;
    }
    return $count;
}



function tx_dias_habiles_rango_fechas($conexion, $fecha_inicio, $fecha_fin) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - DÍAS HÁBILES POR RANGO DE FECHAS
    |--------------------------------------------------------------------------
    |
    | Uso:
    |   Prorratear metas semanales capturadas en ejecucion_operativa_metas
    |   cuando el dashboard analiza un periodo mayor o menor a una semana.
    |
    | Regla:
    |   - Excluye domingos.
    |   - Excluye fechas registradas en dias_inhabiles.
    |--------------------------------------------------------------------------
    */
    $fecha_inicio = date('Y-m-d', strtotime($fecha_inicio));
    $fecha_fin    = date('Y-m-d', strtotime($fecha_fin));
    if (($GLOBALS['periodo'] ?? '') === 'semanal'
        && $fecha_inicio === ($GLOBALS['fecha_inicio_dashboard'] ?? '')
        && $fecha_fin === ($GLOBALS['fecha_fin_dashboard'] ?? '')) {
        $dias=[];
        foreach (($GLOBALS['tx_fechas_seleccionadas'] ?? []) as $f) {
            if ((int)date('N',strtotime($f))!==7) $dias[$f]=true;
        }
        if ($dias) {
            $fechaIniSql=mysqli_real_escape_string($conexion,min(array_keys($dias)));
            $fechaFinSql=mysqli_real_escape_string($conexion,max(array_keys($dias)));
            $festivos=mysqli_query($conexion,"SELECT fecha FROM dias_inhabiles WHERE activo=1 AND fecha BETWEEN '$fechaIniSql' AND '$fechaFinSql'");
            while($festivos && $fila=mysqli_fetch_assoc($festivos)) unset($dias[$fila['fecha']]);
        }
        return count($dias);
    }


    if ($fecha_inicio > $fecha_fin) {
        $tmp = $fecha_inicio;
        $fecha_inicio = $fecha_fin;
        $fecha_fin = $tmp;
    }

    $inicio_sql = mysqli_real_escape_string($conexion, $fecha_inicio);
    $fin_sql    = mysqli_real_escape_string($conexion, $fecha_fin);

    $sql = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT DATE_ADD('$inicio_sql', INTERVAL n DAY) AS fecha
            FROM (
                SELECT a.N + b.N * 10 + c.N * 100 AS n
                FROM
                    (SELECT 0 N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
                    (SELECT 0 N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
                    (SELECT 0 N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c
            ) nums
            WHERE DATE_ADD('$inicio_sql', INTERVAL n DAY) <= '$fin_sql'
        ) calendario
        WHERE DAYOFWEEK(fecha) <> 1
          AND NOT EXISTS (
              SELECT 1
              FROM dias_inhabiles di
              WHERE di.fecha = calendario.fecha
          )
    ";

    $r = mysqli_query($conexion, $sql);
    if ($r && $row = mysqli_fetch_assoc($r)) {
        return (int)($row['total'] ?? 0);
    }

    // Fallback defensivo.
    $count = 0;
    $ts_ini = strtotime($fecha_inicio);
    $ts_fin = strtotime($fecha_fin);
    for ($ts = $ts_ini; $ts <= $ts_fin; $ts += 86400) {
        if ((int)date('w', $ts) !== 0) $count++;
    }
    return $count;
}

function tx_canales_cumplimiento_dashboard() {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - HOMOLOGACIÓN DE CANALES PARA CUMPLIMIENTO
    |--------------------------------------------------------------------------
    |
    | REGLA VIGENTE JUNIO 2026:
    |
    |   CALL CENTER:
    |       Meta = Call Center BTL + Call Center Web
    |       Real = origen_prospecto Call Center, excluyendo subcanal Venta Técnico.
    |
    |   CAMBACEO:
    |       Meta = Cambaceo
    |       Real = Cambaceo
    |
    |   PUNTO DE VENTA:
    |       Meta = Punto de Venta
    |       Real = Punto de Venta
    |
    |   ECOMMERCE:
    |       Meta = Ecommerce
    |       Real = eCommerce
    |
    |   AUTOEMPRESARIOS AUTORIZADOS:
    |       Meta = Autoempresarios
    |       Real = Autoempresarios Autorizados
    |
    |   VENTA TÉCNICO:
    |       Meta = Venta Técnico
    |       Real = registros de Call Center con subcanal Venta Técnico.
    |
    |   OTROS - Sin meta:
    |       Meta = 0
    |       Real = Venta Digital + Winback + Desarrollos + Distribuidor + Otro
    |
    | IMPORTANTE:
    | Antes se sumaba Autoempresarios + Venta Técnico dentro de
    | Autoempresarios Autorizados. Esa lógica queda separada por el hallazgo
    | del subcanal Venta Técnico.
    |--------------------------------------------------------------------------
    */
    return [
        'CALL CENTER' => [
            'meta' => ['Call Center BTL', 'Call Center Web'],
            'real' => ['Call Center'],
            'exclude_subcanal' => ['Venta Técnico', 'Venta Tecnico']
        ],
        'CAMBACEO' => [
            'meta' => ['Cambaceo'],
            'real' => ['Cambaceo']
        ],
        'PUNTO DE VENTA' => [
            'meta' => ['Punto de Venta'],
            'real' => ['Punto de Venta']
        ],
        'ECOMMERCE' => [
            'meta' => ['Ecommerce'],
            'real' => ['eCommerce']
        ],
        'AUTOEMPRESARIOS AUTORIZADOS' => [
            'meta' => ['Autoempresarios'],
            'real' => ['Autoempresarios Autorizados']
        ],
        'VENTA TÉCNICO' => [
            'meta' => ['Venta Técnico'],
            'real' => ['Call Center'],
            'only_subcanal' => ['Venta Técnico', 'Venta Tecnico']
        ],
        'OTROS - Sin meta' => [
            'meta' => [],
            'real' => ['Venta Digital', 'Winback', 'Desarrollos', 'Distribuidor', 'Otro']
        ],
    ];
}

function tx_subcanal_column_instalaciones($conexion) {
    /*
     * Campo esperado: instalaciones.subcanal.
     * Se valida de forma defensiva para no romper el dashboard si en algún
     * ambiente el campo aún no existe. Si no existe, no aplica filtro.
     */
    static $col = null;
    if ($col !== null) return $col;

    $candidatos = ['subcanal', 'sub_canal', 'subcanal_venta'];
    foreach ($candidatos as $cand) {
        $cand_esc = mysqli_real_escape_string($conexion, $cand);
        $r = mysqli_query($conexion, "SHOW COLUMNS FROM instalaciones LIKE '$cand_esc'");
        if ($r && mysqli_num_rows($r) > 0) {
            $col = $cand;
            return $col;
        }
    }

    $col = '';
    return $col;
}

function tx_sql_subcanal_filter_dashboard($conexion, $canales, $mode = 'only') {
    $col = tx_subcanal_column_instalaciones($conexion);
    if ($col === '' || empty($canales)) return '';

    $col_sql = "`" . str_replace("`", "", $col) . "`";
    $canales_sql = tx_sql_in_upper_trim($conexion, $canales);

    if ($mode === 'exclude') {
        return " AND (
            $col_sql IS NULL
            OR TRIM($col_sql) = ''
            OR UPPER(TRIM($col_sql)) NOT IN ($canales_sql)
        )";
    }

    return " AND UPPER(TRIM(COALESCE($col_sql,''))) IN ($canales_sql)";
}

function tx_sql_in_upper_trim($conexion, $vals) {
    $vals = array_values(array_filter(array_unique($vals), function($v) {
        return trim((string)$v) !== '';
    }));
    if (empty($vals)) return "''";
    return "'" . implode("','", array_map(function($v) use ($conexion) {
        return mysqli_real_escape_string($conexion, strtoupper(trim((string)$v)));
    }, $vals)) . "'";
}

function tx_meta_canal_dashboard($conexion, $canal, $mes, $anio, $rango_mode, $dia_inicio, $dia_fin, $scope_sql = '') {
    $where_scope = $scope_sql !== '' ? " AND distrito IN ($scope_sql)" : "";
    $mapa = tx_canales_cumplimiento_dashboard();
    $canales_meta = $mapa[$canal]['meta'] ?? [];

    if (empty($canales_meta)) return 0;

    $canales_sql = tx_sql_in_upper_trim($conexion, $canales_meta);

    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - META POR CANAL DE VENTA
    |--------------------------------------------------------------------------
    |
    | La meta por canal se calcula con:
    |
    |     META = SUM(meta_diaria)
    |
    | usando el rango seleccionado en el calendario del dashboard.
    |
    | Esto respeta la carga diaria real de metas:
    | si un día viene con meta 0, especial o ajustada, se toma tal cual.
    |
    | La suma de metas por canal debe cuadrar con la meta del velocímetro
    | Avance vs Meta, siempre que ambos usen el mismo rango y el mismo scope.
    |--------------------------------------------------------------------------
    */

    $r = mysqli_query($conexion, "
        SELECT COALESCE(SUM(meta_diaria),0) AS meta_rango
        FROM metas_instalacion
        WHERE " . tx_dashboard_meta_fecha_where() . "
          AND UPPER(TRIM(canal)) IN ($canales_sql)
          $where_scope
    ");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)round((float)($row['meta_rango'] ?? 0));
}

function tx_real_inst_canal_dashboard($conexion, $canal, $mes, $anio, $cond_dia_fecha, $scope_sql = '') {
    $where_scope = $scope_sql !== '' ? " AND distrito IN ($scope_sql)" : "";
    $mapa = tx_canales_cumplimiento_dashboard();
    $regla = $mapa[$canal] ?? [];
    $canales_real = $regla['real'] ?? [];

    if (empty($canales_real)) return 0;

    $canales_sql = tx_sql_in_upper_trim($conexion, $canales_real);
    $where_subcanal = '';

    if (!empty($regla['only_subcanal'])) {
        $where_subcanal = tx_sql_subcanal_filter_dashboard($conexion, $regla['only_subcanal'], 'only');
    } elseif (!empty($regla['exclude_subcanal'])) {
        $where_subcanal = tx_sql_subcanal_filter_dashboard($conexion, $regla['exclude_subcanal'], 'exclude');
    }

    $r = mysqli_query($conexion, "
        SELECT COUNT(cuenta) AS total
        FROM instalaciones
        WHERE " . tx_dashboard_where_fecha('fecha') . "
          AND origen_prospecto IS NOT NULL
          AND origen_prospecto <> '-'
          AND UPPER(TRIM(origen_prospecto)) IN ($canales_sql)
          $where_subcanal
          $where_scope
    ");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)($row['total'] ?? 0);
}

function tx_arpu_instalaciones_dashboard($conexion, $mes, $anio, $cond_dia_fecha, $scope_sql = '', $folios = []) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - ARPU COMERCIAL
    |--------------------------------------------------------------------------
    |
    | Fuente oficial:
    |   instalaciones.precio_pronto_pago
    |
    | Regla de negocio:
    |   ARPU = SUM(precio_pronto_pago) / instalaciones válidas
    |
    | Exclusiones:
    |   - precio_pronto_pago <= 0
    |   - origen_prospecto '-' o vacío
    |   - registros sin estructura comercial: lider '-' o vacío
    |
    | Esto evita contaminar el ARPU comercial con instalaciones corporativas,
    | Solución a la Medida o registros no asignados.
    |
    | La función respeta el mismo rango de días y scope jerárquico del dashboard.
    |--------------------------------------------------------------------------
    */
    $where_scope = $scope_sql !== '' ? " AND distrito IN ($scope_sql)" : "";
    $where_folios = "";

    if (!empty($folios)) {
        $folios_sql = tx_sql_in_escaped($conexion, $folios);
        $where_folios = " AND folio_empleado IN ($folios_sql)";
    }

    $r = mysqli_query($conexion, "
        SELECT 
            COALESCE(SUM(precio_pronto_pago),0) AS ingreso,
            COUNT(cuenta) AS instalaciones_validas
        FROM instalaciones
        WHERE MONTH(fecha)=".(int)$mes."
          AND YEAR(fecha)=".(int)$anio."
          $cond_dia_fecha
          AND precio_pronto_pago > 0
          AND origen_prospecto IS NOT NULL
          AND TRIM(origen_prospecto) <> ''
          AND origen_prospecto <> '-'
          AND lider IS NOT NULL
          AND TRIM(lider) <> ''
          AND lider <> '-'
          $where_scope
          $where_folios
    ");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $instalaciones = (int)($row['instalaciones_validas'] ?? 0);
    $ingreso = (float)($row['ingreso'] ?? 0);

    return $instalaciones > 0 ? round($ingreso / $instalaciones, 2) : 0;
}

function tx_meta_acum_distrito($conexion, $distrito, $mes, $anio, $dias) {
    $dsql = tx_sql_in_escaped($conexion, tx_distrito_equivalentes_array($distrito));
    $r = mysqli_query($conexion, "SELECT SUM(meta_diaria) AS meta FROM metas_instalacion WHERE " . tx_dashboard_meta_fecha_where() . " AND distrito IN ($dsql)");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    // Meta ya es SUM(meta_diaria) del rango exacto; no multiplicar otra vez por días.
    return (int)round((float)($row['meta'] ?? 0));
}

function tx_real_inst_distrito($conexion, $distrito, $mes, $anio, $cond_dia_fecha) {
    $dsql = tx_sql_in_escaped($conexion, tx_distrito_equivalentes_array($distrito));
    $r = mysqli_query($conexion, "SELECT COUNT(cuenta) AS total FROM instalaciones WHERE " . tx_dashboard_where_fecha('fecha') . " AND origen_prospecto <> '-' AND distrito IN ($dsql)");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)($row['total'] ?? 0);
}

function tx_direct_children($conexion, $root_id, $semana, $anio, $target_nivel = '') {
    $root = mysqli_real_escape_string($conexion, (string)$root_id);
    $sql = "
        SELECT DISTINCT id_posicion, nombre_colaborador, posicion, puesto_lr, distrito
        FROM hc
        WHERE posicion_lr = '$root'
          AND semana = ".(int)$semana."
          AND anio = ".(int)$anio."
          AND nombre_colaborador <> 'VACANTE'
          AND numero_talento_gs NOT LIKE '%VACANTE%'
        ORDER BY nombre_colaborador
    ";
    $res = mysqli_query($conexion, $sql);
    $out = [];
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $nivel_row = nivel_dashboard_hc($row['posicion'] ?? '', $row['puesto_lr'] ?? '');
        if ($target_nivel === '' || $nivel_row === $target_nivel) {
            $out[] = $row;
        }
    }
    return $out;
}

function tx_hc_activo_posiciones($conexion, $ids, $semana, $anio, $puestos_comerciales) {
    if (empty($ids)) return 0;
    $ids_sql = tx_sql_in_escaped($conexion, $ids);
    $r = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM hc WHERE semana=".(int)$semana." AND anio=".(int)$anio." AND numero_talento_gs NOT LIKE '%VACANTE%' AND posicion IN ($puestos_comerciales) AND id_posicion IN ($ids_sql)");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)($row['total'] ?? 0);
}

function tx_real_inst_folios($conexion, $folios, $mes, $anio, $cond_dia_fecha) {
    if (empty($folios)) return 0;
    $folios_sql = tx_sql_in_escaped($conexion, $folios);
    $r = mysqli_query($conexion, "SELECT COUNT(cuenta) AS total FROM instalaciones WHERE " . tx_dashboard_where_fecha('fecha') . " AND origen_prospecto <> '-' AND folio_empleado IN ($folios_sql)");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)($row['total'] ?? 0);
}

function tx_columna_existe_dashboard($conexion, $tabla, $columna) {
    static $cache = [];
    $key = $tabla . '.' . $columna;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $tabla_esc = mysqli_real_escape_string($conexion, $tabla);
    $col_esc = mysqli_real_escape_string($conexion, $columna);
    $r = mysqli_query($conexion, "SHOW COLUMNS FROM `$tabla_esc` LIKE '$col_esc'");
    $cache[$key] = ($r && mysqli_num_rows($r) > 0);
    return $cache[$key];
}

function tx_tabla_existe_dashboard($conexion, $tabla) {
    static $cache = [];
    if (array_key_exists($tabla, $cache)) return $cache[$tabla];

    $tabla_esc = mysqli_real_escape_string($conexion, $tabla);
    $r = mysqli_query($conexion, "SHOW TABLES LIKE '$tabla_esc'");
    $cache[$tabla] = ($r && mysqli_num_rows($r) > 0);
    return $cache[$tabla];
}

function tx_segmento_instalacion_expr_dashboard($conexion, $alias = '') {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - RESIDENCIAL / NEGOCIOS
    |--------------------------------------------------------------------------
    | Misma lógica base de Ranking Vendedor:
    | el paquete instalado se clasifica contra catalogo_paquetes.
    |
    | Matching:
    |   instalaciones.plan / nombre_plan / paquete / nombre_paquete
    |   contra catalogo_paquetes.nombre_plan.
    |
    | Negocios:
    |   si el valor clasificador contiene NEGOC / BUSINESS / EMPRES / PYME / SME.
    | Resto:
    |   Residencial.
    |--------------------------------------------------------------------------
    */
    $prefix = $alias !== '' ? "`" . str_replace("`", "", $alias) . "`." : "";

    $plan_cols = ['plan', 'nombre_plan', 'paquete', 'nombre_paquete'];
    $plan_parts = [];
    foreach ($plan_cols as $col) {
        if (tx_columna_existe_dashboard($conexion, 'instalaciones', $col)) {
            $plan_parts[] = "NULLIF(TRIM(" . $prefix . "`" . str_replace("`", "", $col) . "`),'')";
        }
    }
    $plan_expr = !empty($plan_parts) ? "COALESCE(" . implode(", ", $plan_parts) . ", '')" : "''";

    $sources = [];

    if (
        tx_tabla_existe_dashboard($conexion, 'catalogo_paquetes') &&
        tx_columna_existe_dashboard($conexion, 'catalogo_paquetes', 'nombre_plan') &&
        !empty($plan_parts)
    ) {
        $cat_cols = [
            'segmento','tipo_segmento','tipo_cliente','tipo_servicio','tipo_venta',
            'mercado','unidad_negocio','negocio','linea_negocio','categoria',
            'familia','tipo','producto'
        ];
        $cat_parts = [];
        foreach ($cat_cols as $col) {
            if (tx_columna_existe_dashboard($conexion, 'catalogo_paquetes', $col)) {
                $cat_parts[] = "NULLIF(TRIM(cp.`" . str_replace("`", "", $col) . "`),'')";
            }
        }
        $cat_parts[] = "NULLIF(TRIM(cp.`nombre_plan`),'')";

        $sources[] = "(
            SELECT COALESCE(" . implode(", ", $cat_parts) . ", '')
            FROM catalogo_paquetes cp
            WHERE UPPER(TRIM(cp.`nombre_plan`)) = UPPER(TRIM($plan_expr))
            LIMIT 1
        )";
    }

    // Respaldo defensivo si falta catálogo o no empata el plan.
    $inst_cols = [
        'segmento','tipo_segmento','tipo_cliente','tipo_servicio','tipo_venta',
        'mercado','unidad_negocio','negocio','linea_negocio','canal_segmento','categoria'
    ];
    foreach ($inst_cols as $col) {
        if (tx_columna_existe_dashboard($conexion, 'instalaciones', $col)) {
            $sources[] = "NULLIF(TRIM(" . $prefix . "`" . str_replace("`", "", $col) . "`),'')";
        }
    }
    if (!empty($plan_parts)) $sources[] = $plan_expr;

    if (empty($sources)) return "'RES'";

    $segmento_expr = "UPPER(COALESCE(" . implode(", ", $sources) . ", ''))";

    return "CASE
        WHEN $segmento_expr LIKE '%NEGOC%'
          OR $segmento_expr LIKE '%BUSINESS%'
          OR $segmento_expr LIKE '%EMPRES%'
          OR $segmento_expr LIKE '%PYME%'
          OR $segmento_expr LIKE '%SME%'
        THEN 'NEG'
        ELSE 'RES'
    END";
}


function tx_segmento_venta_expr_dashboard($conexion, $alias = '') {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - VENTAS POR OFERTA RES-NEG
    |--------------------------------------------------------------------------
    | Regla final:
    |   ventas.nombre_plan = catalogo_paquetes.nombre_plan
    |   catalogo_paquetes.tipo = RESIDENCIAL / NEGOCIOS
    |
    | IMPORTANTE:
    | Se usa MATCH EXACTO contra nombre_plan. No usar LIKE parcial porque puede
    | cruzar contra paquetes incorrectos y distorsionar la mezcla RES-NEG.
    |--------------------------------------------------------------------------
    */
    $prefix = $alias !== '' ? "`" . str_replace("`", "", $alias) . "`." : "";

    if (
        !tx_columna_existe_dashboard($conexion, 'ventas', 'nombre_plan') ||
        !tx_tabla_existe_dashboard($conexion, 'catalogo_paquetes') ||
        !tx_columna_existe_dashboard($conexion, 'catalogo_paquetes', 'nombre_plan') ||
        !tx_columna_existe_dashboard($conexion, 'catalogo_paquetes', 'tipo')
    ) {
        return "'RESIDENCIAL'";
    }

    return "COALESCE((
        SELECT cp.`tipo`
        FROM catalogo_paquetes cp
        WHERE UPPER(TRIM(cp.`nombre_plan`)) = UPPER(TRIM(" . $prefix . "`nombre_plan`))
        LIMIT 1
    ), 'RESIDENCIAL')";
}

function tx_mix_res_neg_where_dashboard($conexion, $where_sql) {
    $expr = tx_segmento_instalacion_expr_dashboard($conexion);
    $sql = "
        SELECT
            COUNT(cuenta) AS total,
            SUM(CASE WHEN ($expr) = 'NEG' THEN 1 ELSE 0 END) AS negocios
        FROM instalaciones
        WHERE $where_sql
    ";
    $r = mysqli_query($conexion, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;

    $total = (int)($row['total'] ?? 0);
    $neg = (int)($row['negocios'] ?? 0);
    if ($total <= 0) return ['total'=>0,'res_pct'=>null,'neg_pct'=>null];

    $neg_pct = (int)round(($neg / $total) * 100);
    $res_pct = max(0, 100 - $neg_pct);
    return ['total'=>$total, 'res_pct'=>$res_pct, 'neg_pct'=>$neg_pct];
}

function tx_mix_res_neg_distrito_dashboard($conexion, $distrito, $mes, $anio, $cond_dia_fecha) {
    $dsql = tx_sql_in_escaped($conexion, tx_distrito_equivalentes_array($distrito));
    return tx_mix_res_neg_where_dashboard(
        $conexion,
        tx_dashboard_where_fecha('fecha') . "
         AND origen_prospecto <> '-'
         AND distrito IN ($dsql)"
    );
}

function tx_mix_res_neg_folios_dashboard($conexion, $folios, $mes, $anio, $cond_dia_fecha) {
    if (empty($folios)) return ['total'=>0,'res_pct'=>null,'neg_pct'=>null];

    $folios_sql = tx_sql_in_escaped($conexion, $folios);
    return tx_mix_res_neg_where_dashboard(
        $conexion,
        tx_dashboard_where_fecha('fecha') . "
         AND origen_prospecto <> '-'
         AND folio_empleado IN ($folios_sql)"
    );
}

function tx_mix_res_neg_canal_dashboard($conexion, $canal, $mes, $anio, $cond_dia_fecha, $scope_sql = '') {
    $mapa = tx_canales_cumplimiento_dashboard();
    $regla = $mapa[$canal] ?? [];
    $canales_real = $regla['real'] ?? [];
    if (empty($canales_real)) return ['total'=>0,'res_pct'=>null,'neg_pct'=>null];

    $canales_sql = tx_sql_in_upper_trim($conexion, $canales_real);
    $where_subcanal = '';
    if (!empty($regla['only_subcanal'])) {
        $where_subcanal = tx_sql_subcanal_filter_dashboard($conexion, $regla['only_subcanal'], 'only');
    } elseif (!empty($regla['exclude_subcanal'])) {
        $where_subcanal = tx_sql_subcanal_filter_dashboard($conexion, $regla['exclude_subcanal'], 'exclude');
    }
    $where_scope = $scope_sql !== '' ? " AND distrito IN ($scope_sql)" : "";

    return tx_mix_res_neg_where_dashboard(
        $conexion,
        tx_dashboard_where_fecha('fecha') . "
         AND origen_prospecto IS NOT NULL
         AND origen_prospecto <> '-'
         AND UPPER(TRIM(origen_prospecto)) IN ($canales_sql)
         $where_subcanal
         $where_scope"
    );
}



function tx_meta_operativa_asignada($conexion, $anio, $semana, $id_superior, $id_subordinado, $nivel_superior, $nivel_subordinado) {
    // 1) Búsqueda exacta: semana/año + superior + subordinado + niveles.
    $sql = "
        SELECT COALESCE(SUM(meta_asignada),0) AS meta
        FROM ejecucion_operativa_metas
        WHERE anio = ?
          AND semana = ?
          AND id_superior = ?
          AND id_subordinado = ?
          AND nivel_superior = ?
          AND nivel_subordinado = ?
    ";
    $stmt = mysqli_prepare($conexion, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            "iissss",
            $anio,
            $semana,
            $id_superior,
            $id_subordinado,
            $nivel_superior,
            $nivel_subordinado
        );
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $meta = (int)($row['meta'] ?? 0);
        if ($meta > 0) return $meta;
    }

    // 2) Respaldo: si el dashboard está consultando desde admin/scope y el id_superior no coincide,
    // tomar la última meta positiva capturada para ese subordinado en la misma semana/año/niveles.
    $sql = "
        SELECT meta_asignada AS meta
        FROM ejecucion_operativa_metas
        WHERE anio = ?
          AND semana = ?
          AND id_subordinado = ?
          AND nivel_superior = ?
          AND nivel_subordinado = ?
          AND meta_asignada > 0
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param(
        $stmt,
        "iisss",
        $anio,
        $semana,
        $id_subordinado,
        $nivel_superior,
        $nivel_subordinado
    );
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['meta'] ?? 0);
}


function tx_meta_operativa_asignada_periodo($conexion, $fecha_inicio, $fecha_fin, $id_superior, $id_subordinado, $nivel_superior, $nivel_subordinado) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - META OPERATIVA VIGENTE PRORRATEADA AL PERIODO
    |--------------------------------------------------------------------------
    |
    | Corrige la tarjeta "Cumplimiento del nivel inferior vs meta".
    |
    | Regla vigente:
    |   Si existe meta_asignada > 0 en ejecucion_operativa_metas, se toma la
    |   meta semanal vigente del subordinado y se prorratea contra el rango
    |   seleccionado del dashboard.
    |
    | Formula:
    |   meta_periodo = (meta_semanal_vigente / 6) * dias_habiles_del_periodo
    |
    | Ejemplo:
    |   Maria Jose:
    |       meta semanal vigente = 170
    |       rango 01-16 junio = 14 dias habiles
    |       meta_periodo = 170 / 6 * 14 = 396.67 ~= 397
    |
    | IMPORTANTE:
    |   Antes se sumaban solo las semanas que tenian registro capturado. Eso
    |   provocaba que, si una semana del periodo no tenia captura, la meta
    |   quedara subestimada. Ahora se toma la meta vigente y se proyecta al
    |   periodo completo.
    |--------------------------------------------------------------------------
    */

    $inicio_ts = strtotime($fecha_inicio);
    $fin_ts    = strtotime($fecha_fin);
    if (!$inicio_ts || !$fin_ts) return 0;

    if ($inicio_ts > $fin_ts) {
        $tmp = $inicio_ts;
        $inicio_ts = $fin_ts;
        $fin_ts = $tmp;
    }

    $anio_fin = (int)date('o', $fin_ts);
    $semana_fin = (int)date('W', $fin_ts);

    $id_superior_esc = mysqli_real_escape_string($conexion, (string)$id_superior);
    $id_subordinado_esc = mysqli_real_escape_string($conexion, (string)$id_subordinado);
    $nivel_superior_esc = mysqli_real_escape_string($conexion, (string)$nivel_superior);
    $nivel_subordinado_esc = mysqli_real_escape_string($conexion, (string)$nivel_subordinado);

    // 1) Busqueda preferente: misma linea superior -> subordinado.
    $sql = "
        SELECT meta_asignada
        FROM ejecucion_operativa_metas
        WHERE id_superior = '$id_superior_esc'
          AND id_subordinado = '$id_subordinado_esc'
          AND nivel_superior = '$nivel_superior_esc'
          AND nivel_subordinado = '$nivel_subordinado_esc'
          AND meta_asignada > 0
          AND (
                anio < $anio_fin
                OR (anio = $anio_fin AND semana <= $semana_fin)
          )
        ORDER BY anio DESC, semana DESC, updated_at DESC, id DESC
        LIMIT 1
    ";
    $r = mysqli_query($conexion, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $meta_semanal = (int)($row['meta_asignada'] ?? 0);

    // 2) Respaldo: si el dashboard esta en scope/admin y no coincide id_superior,
    // toma la ultima meta positiva vigente para ese subordinado.
    if ($meta_semanal <= 0) {
        $sql = "
            SELECT meta_asignada
            FROM ejecucion_operativa_metas
            WHERE id_subordinado = '$id_subordinado_esc'
              AND nivel_superior = '$nivel_superior_esc'
              AND nivel_subordinado = '$nivel_subordinado_esc'
              AND meta_asignada > 0
              AND (
                    anio < $anio_fin
                    OR (anio = $anio_fin AND semana <= $semana_fin)
              )
            ORDER BY anio DESC, semana DESC, updated_at DESC, id DESC
            LIMIT 1
        ";
        $r = mysqli_query($conexion, $sql);
        $row = $r ? mysqli_fetch_assoc($r) : null;
        $meta_semanal = (int)($row['meta_asignada'] ?? 0);
    }

    if ($meta_semanal <= 0) return 0;

    $dias_periodo = tx_dias_habiles_rango_fechas(
        $conexion,
        date('Y-m-d', $inicio_ts),
        date('Y-m-d', $fin_ts)
    );

    if ($dias_periodo <= 0) return 0;

    // Meta semanal operativa estandar: 6 dias habiles por semana.
    return (int)round(((float)$meta_semanal / 6.0) * (float)$dias_periodo);
}

function tx_meta_propia_operativa_periodo_dashboard($conexion, $fecha_inicio, $fecha_fin, $id_posicion) {
    /*
     * Meta propia del tablero actual para Lider, Coach o Vendedor.
     *
     * Toma la meta semanal vigente capturada para el id_subordinado y la
     * prorratea al rango seleccionado:
     *
     *   meta_periodo = (meta_semanal_vigente / 6) * dias_habiles_del_periodo
     */
    $inicio_ts = strtotime($fecha_inicio);
    $fin_ts    = strtotime($fecha_fin);
    if (!$inicio_ts || !$fin_ts || empty($id_posicion)) return 0;

    if ($inicio_ts > $fin_ts) {
        $tmp = $inicio_ts;
        $inicio_ts = $fin_ts;
        $fin_ts = $tmp;
    }

    $anio_fin = (int)date('o', $fin_ts);
    $semana_fin = (int)date('W', $fin_ts);
    $id_posicion_esc = mysqli_real_escape_string($conexion, (string)$id_posicion);

    $sql = "
        SELECT meta_asignada
        FROM ejecucion_operativa_metas
        WHERE id_subordinado = '$id_posicion_esc'
          AND meta_asignada > 0
          AND (
                anio < $anio_fin
                OR (anio = $anio_fin AND semana <= $semana_fin)
          )
        ORDER BY anio DESC, semana DESC, updated_at DESC, id DESC
        LIMIT 1
    ";
    $r = mysqli_query($conexion, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $meta_semanal = (int)($row['meta_asignada'] ?? 0);

    if ($meta_semanal <= 0) return 0;

    $dias_periodo = tx_dias_habiles_rango_fechas(
        $conexion,
        date('Y-m-d', $inicio_ts),
        date('Y-m-d', $fin_ts)
    );

    if ($dias_periodo <= 0) return 0;

    // Meta semanal operativa estandar: 6 dias habiles por semana.
    return (int)round(((float)$meta_semanal / 6.0) * (float)$dias_periodo);
}

function tx_nivel_operativo_meta($nivel_dashboard) {
    if ($nivel_dashboard === 'director_distrital') return 'DIRECTOR_DISTRITAL';
    if ($nivel_dashboard === 'lider') return 'LIDER_VENTAS';
    if ($nivel_dashboard === 'coach') return 'COACH_VENTAS';
    return '';
}

function tx_nivel_operativo_subordinado($nivel_dashboard) {
    if ($nivel_dashboard === 'lider') return 'LIDER_VENTAS';
    if ($nivel_dashboard === 'coach') return 'COACH_VENTAS';
    if ($nivel_dashboard === 'vendedor') return 'VENDEDOR';
    return '';
}


$cumplimiento_inferior_titulo = 'Cumplimiento del nivel inferior vs meta';
$cumplimiento_inferior_subtitulo = '';
$cumplimiento_inferior_labels = [];
$cumplimiento_inferior_real = [];
$cumplimiento_inferior_meta = [];
$cumplimiento_inferior_pct = [];
$cumplimiento_inferior_fuente = [];
$cumplimiento_inferior_visual_pct = [];
$cumplimiento_inferior_res_pct = [];
$cumplimiento_inferior_neg_pct = [];
$cumplimiento_inferior_mix_total = [];
$cumplimiento_inferior_items = [];

$nivel_actual_dashboard = $scope_activo ? $scope_nivel : ($rol === 'admin' ? 'admin' : nivel_dashboard_hc($posicion_usuario ?? '', ''));
$root_dashboard_id = $scope_activo ? ($scope_registro['id_posicion'] ?? '') : $id_posicion;
$root_dashboard_distrito = $scope_activo ? ($distrito_scope ?? $distrito_usuario) : $distrito_usuario;


// Determinar vendedor ANTES de evaluar las ramas; el STAGING previo lo evaluaba sin inicializar.
$tx_stg_es_vendedor = ($rol === 'vendedor' || ($scope_activo && $scope_nivel === 'vendedor'));
// Solo vendedor y administracion regional sin scope mantienen el bloque legado de esta tarjeta.
if ($tx_stg_es_vendedor || (!$scope_activo && in_array($rol, ['admin','director_regional'], true))) {
if (in_array($rol, ['admin','director_regional'], true) && !$scope_activo) {
    $cumplimiento_inferior_subtitulo = 'Distritos · Real vs Meta';
    $distritos_raw = [];
    $res_dist = mysqli_query($conexion, "
        SELECT DISTINCT distrito FROM metas_instalacion
        WHERE " . tx_dashboard_meta_fecha_where() . "
        UNION
        SELECT DISTINCT distrito FROM instalaciones
        WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-'
    ");
    while ($res_dist && $drow = mysqli_fetch_assoc($res_dist)) {
        $d = trim((string)($drow['distrito'] ?? ''));
        if ($d === '') continue;
        $norm = ($d === 'COATZA MINA' || $d === 'COATZA / MINA') ? 'COATZA / MINA' : $d;
        $distritos_raw[$norm] = $norm;
    }

    $tmp = [];
    foreach ($distritos_raw as $dist) {
        $real = tx_real_inst_distrito($conexion, $dist, $mes_actual, $anio_query, $cond_dia_fecha);
        $meta = tx_meta_acum_distrito($conexion, $dist, $mes_actual, $anio_query, $dias_transcurridos);
        if ($real <= 0 && $meta <= 0) continue;
        $pct = $meta > 0 ? round(($real / $meta) * 100, 1) : 0;
        $mix_res_neg = tx_mix_res_neg_distrito_dashboard($conexion, $dist, $mes_actual, $anio_query, $cond_dia_fecha);
        $tmp[] = [
            'label'=>$dist,
            'real'=>$real,
            'meta'=>$meta,
            'pct'=>$pct,
            'res_pct'=>$mix_res_neg['res_pct'],
            'neg_pct'=>$mix_res_neg['neg_pct'],
            'mix_total'=>$mix_res_neg['total']
        ];
    }
} else {
    $target_nivel_inferior = '';
    if ($nivel_actual_dashboard === 'director_distrital') $target_nivel_inferior = 'lider';
    elseif ($nivel_actual_dashboard === 'lider') $target_nivel_inferior = 'coach';
    elseif ($nivel_actual_dashboard === 'coach') $target_nivel_inferior = 'vendedor';

    $tmp = [];
    if ($target_nivel_inferior !== '' && $root_dashboard_id !== '') {
        $cumplimiento_inferior_subtitulo = label_nivel_dashboard($target_nivel_inferior) . ' · Real vs Meta';
        $children = tx_direct_children($conexion, $root_dashboard_id, $semana_actual, $anio_actual, $target_nivel_inferior);
        $meta_base = tx_meta_acum_distrito($conexion, $root_dashboard_distrito, $mes_actual, $anio_query, $dias_transcurridos);

        $children_calc = [];
        $hc_total_children = 0;
        foreach ($children as $child) {
            $child_ids = getTodosSubordinados($conexion, $child['id_posicion'], 6, $semana_actual, $anio_actual);
            $child_ids[] = $child['id_posicion'];
            $child_ids = array_unique(array_values($child_ids));
            $child_folios = tx_hic_folios_por_posiciones_dashboard($conexion, $child_ids);
            // Parche HIC-Fallback v1.0 en cumplimiento inferior:
            // reales por folios actuales + históricos; HC/meta permanecen con plantilla vigente.
            if (!empty($child_folios)) {
                $child_folios = tx_hic_aliases_folios_dashboard($conexion, $child_folios);
            }
            $child_hc = tx_hc_activo_posiciones($conexion, $child_ids, $semana_actual, $anio_actual, $puestos_comerciales);
            if ($target_nivel_inferior === 'vendedor' && $child_hc === 0) $child_hc = 1;
            $hc_total_children += $child_hc;
            $children_calc[] = [
                'id_posicion' => $child['id_posicion'] ?? '',
                'label' => $child['nombre_colaborador'] ?? '',
                'folios' => $child_folios,
                'hc' => $child_hc,
                'nivel_dashboard' => $target_nivel_inferior
            ];
        }

        foreach ($children_calc as $child) {
            $real = tx_real_inst_folios($conexion, $child['folios'], $mes_actual, $anio_query, $cond_dia_fecha);

            // Meta oficial de Ejecución Operativa:
            // Si existe meta_asignada > 0 en ejecucion_operativa_metas, se calcula con la meta semanal vigente
            // prorrateada contra los dias habiles del rango seleccionado.
            // Si no existe o es 0, se conserva el cálculo automático por proporción de HC.
            $nivel_superior_meta = tx_nivel_operativo_meta($nivel_actual_dashboard);
            $nivel_subordinado_meta = tx_nivel_operativo_subordinado($target_nivel_inferior);
            $meta_oficial = 0;
            if ($nivel_superior_meta !== '' && $nivel_subordinado_meta !== '' && !empty($child['id_posicion'])) {
                $meta_oficial = tx_meta_operativa_asignada_periodo(
                    $conexion,
                    $fecha_inicio_dashboard,
                    $fecha_fin_dashboard,
                    $root_dashboard_id,
                    $child['id_posicion'],
                    $nivel_superior_meta,
                    $nivel_subordinado_meta
                );
            }

            if ($meta_oficial > 0) {
                $meta = $meta_oficial;
                $meta_fuente = 'operativa';
            } elseif (in_array($nivel_actual_dashboard, ['lider','coach'], true)) {
                // Para Líder -> Coaches y Coach -> Vendedores:
                // si no hay meta capturada en ejecucion_operativa_metas, NO se calcula meta artificial.
                // Se muestra el desempeño como "sin meta" para no confundir con una meta oficial.
                $meta = 0;
                $meta_fuente = 'sin_meta';
            } else {
                // Fallback para otros niveles donde aún no exista meta operativa asignada.
                $meta = ($hc_total_children > 0) ? round($meta_base * ($child['hc'] / $hc_total_children)) : 0;
                $meta_fuente = 'hc';
            }

            if ($real <= 0 && $meta <= 0) continue;
            $pct = $meta > 0 ? round(($real / $meta) * 100, 1) : 0;
            $mix_res_neg = tx_mix_res_neg_folios_dashboard($conexion, $child['folios'], $mes_actual, $anio_query, $cond_dia_fecha);
            $tmp[] = [
                'label'=>$child['label'],
                'real'=>$real,
                'meta'=>$meta,
                'pct'=>$pct,
                'meta_fuente'=>$meta_fuente,
                'res_pct'=>$mix_res_neg['res_pct'],
                'neg_pct'=>$mix_res_neg['neg_pct'],
                'mix_total'=>$mix_res_neg['total']
            ];
        }
    }
}

}

// ============================================================================
// TalIA INDEX STAGING v1.0 - integracion de motores sin modificar Produccion.
// NO cambia selectores, KPIs superiores, canal de ventas ni perfil vendedor.
// Motor HC v0.6.2: solo Cambaceo/PDV con jerarquia certificada.
// Motor Comercial v0.2.0: resumen comercial de LAS MISMAS cuentas HC.
// Vista gerencial: ARPU/Mix universales por registros; Lider/Coach mantienen HC.
// ============================================================================
// Vista diaria SOLO para un rango mensual personalizado elegido por calendario.
// No modifica los KPI del corte ni la vista VENDEDOR.
$tx_evo_daily_custom = !$tx_stg_es_vendedor && $periodo === 'mensual'
    && $rango_mode === 'custom' && $fecha_inicio_dashboard >= '2026-01-01';
$tx_stg_aplicable = !$tx_stg_es_vendedor && (int)$anio_query >= 2026;
$tx_stg_ok = false;
$tx_stg_error = null;
$tx_stg_arpu_labels = [];
$tx_stg_arpu_data = [];
$tx_stg_mix_2p = 0;
$tx_stg_mix_3p = 0;
$tx_stg_mix_sin_clasificar = 0;
$tx_stg_universo_mes = 0;
$tx_stg_resumen_mes = [];
$tx_stg_eventos_mes = [];
$tx_stg_ambito = 'SIN_ACCESO';
$tx_stg_distrito = null;
$tx_stg_posicion = '';
$tx_stg_catalogo_lideres = [];
$tx_stg_hc_foto = [];
$tx_stg_hic_canonicos = [];

// Alcance comercial gerencial: todas las instalaciones de TODOS los canales.
// Jamás se obtiene de parámetros GET. Una vista drill-down prevalece sobre rol.
$tx_stg_scope_gerencial = null;
if ($tx_stg_aplicable && !$scope_activo && in_array($rol, ['admin','director_regional'], true)) {
    $tx_stg_scope_gerencial = ['tipo'=>'REGION'];
} elseif ($tx_stg_aplicable && (($scope_activo && $scope_nivel === 'director_distrital') || (!$scope_activo && $rol === 'director_distrital'))) {
    $tx_stg_scope_gerencial = [
        'tipo'=>'DISTRITO',
        'distrito'=>(string)($scope_activo ? ($distrito_scope ?? '') : $distrito_usuario),
    ];
}
$tx_stg_gerencial = ($tx_stg_scope_gerencial !== null);
$tx_stg_ambito_comercial_label = $tx_stg_gerencial
    ? (($tx_stg_scope_gerencial['tipo'] === 'REGION') ? 'Todos los canales · Región SUR' : 'Todos los canales · Distrito')
    : 'HC certificado Cambaceo/PDV';
$tx_stg_hc_ok = false;
$tx_stg_auditoria_arpu = ['validos'=>0,'catalogo_actual'=>0];

if ($tx_stg_aplicable && !($tx_stg_gerencial && !$scope_activo && in_array($rol, ['admin','director_regional'], true))) {
    try {
        if (!($conexion instanceof mysqli)) {
            throw new RuntimeException('No hay conexion mysqli activa para motores TalIA');
        }
        require_once __DIR__ . '/includes/motor_hc.php';
        require_once __DIR__ . '/includes/motor_comercial_STAGING.php';
        if (!class_exists('RankingAtribucionServiceV06') || !class_exists('MotorComercial')) {
            throw new RuntimeException('Motores no disponibles');
        }
        $tx_stg_motor_hc = new RankingAtribucionServiceV06($conexion);
        $tx_stg_motor_com = new MotorComercial($conexion);
        // El rol de sesion manda. Administradores y direccion regional NO requieren HC propio.
        if ($scope_activo) {
            $tx_stg_nivel = $scope_nivel;
            $tx_stg_distrito = RankingAtribucionServiceV06::normalizarDistrito((string)($distrito_scope ?? ''));
            $tx_stg_posicion = trim((string)($scope_registro['id_posicion'] ?? ''));
        } else {
            $tx_stg_nivel = $rol;
            $tx_stg_distrito = RankingAtribucionServiceV06::normalizarDistrito((string)$distrito_usuario);
            $tx_stg_posicion = trim((string)$id_posicion);
        }
        if (!$scope_activo && in_array($rol, ['admin','director_regional'], true)) {
            $tx_stg_ambito = 'REGION';
        } elseif ($tx_stg_nivel === 'director_distrital' && $tx_stg_distrito !== null) {
            $tx_stg_ambito = 'DISTRITO';
        } elseif ($tx_stg_nivel === 'lider' && $tx_stg_distrito !== null && $tx_stg_posicion !== '') {
            $tx_stg_ambito = 'LIDER';
        } elseif ($tx_stg_nivel === 'coach' && $tx_stg_distrito !== null && $tx_stg_posicion !== '') {
            $tx_stg_ambito = 'COACH';
        }
        // Fail-closed: no convertir un perfil desconocido o sin HC en vista regional.
        if ($tx_stg_ambito === 'SIN_ACCESO') {
            throw new RuntimeException('El perfil no tiene linea HC certificable para esta vista');
        }

        // UNA sola certificacion HC para toda la tendencia Ene-2026..periodo elegido.
        // Cada mes se recorta al rango seleccionado (MTD, custom o completo).
        $tx_stg_inicio_tendencia = $tx_evo_daily_custom ? $fecha_inicio_dashboard : (($periodo === 'semanal') ? min('2025-12-29', $fecha_inicio_dashboard) : min('2026-01-01', $fecha_inicio_dashboard));
        $tx_stg_hasta_tendencia = $fecha_fin_dashboard;
        if ($tx_stg_hasta_tendencia < $tx_stg_inicio_tendencia) {
            throw new RuntimeException('Periodo anterior a enero de 2026');
        }
        $tx_stg_respuesta_hc = $tx_stg_motor_hc->obtenerInstalacionesJerarquia(
            $tx_stg_inicio_tendencia, $tx_stg_hasta_tendencia
        );
        if (empty($tx_stg_respuesta_hc['conciliacion_comercial']['verificada'])) {
            throw new RuntimeException('La conciliacion del Motor HC no es valida');
        }
        $tx_stg_por_mes = [];
        foreach (($tx_stg_respuesta_hc['cuentas'] ?? []) as $tx_stg_evento) {
            $tx_stg_lid = (string)($tx_stg_evento['lider_id'] ?? '');
            $tx_stg_partes = explode('|', $tx_stg_lid, 2);
            $tx_stg_distrito_evento = $tx_stg_partes[0] ?? '';
            $tx_stg_lider_pos = $tx_stg_partes[1] ?? '';
            $tx_stg_permitido = false;
            if ($tx_stg_ambito === 'REGION') $tx_stg_permitido = true;
            elseif ($tx_stg_ambito === 'DISTRITO') $tx_stg_permitido = ($tx_stg_distrito_evento === $tx_stg_distrito);
            elseif ($tx_stg_ambito === 'LIDER') $tx_stg_permitido = ($tx_stg_distrito_evento === $tx_stg_distrito && $tx_stg_lider_pos === $tx_stg_posicion);
            elseif ($tx_stg_ambito === 'COACH') $tx_stg_permitido = ($tx_stg_distrito_evento === $tx_stg_distrito && (string)($tx_stg_evento['coach_id'] ?? '') === $tx_stg_posicion);
            // Segundo control: un drill-down no amplía la línea del usuario autenticado.
            // Admin/Regional no necesitan HC propio; distrital/lider/coach sí.
            if ($scope_activo && $tx_stg_permitido && !in_array($rol, ['admin','director_regional'], true)) {
                $tx_stg_distrito_dueno = RankingAtribucionServiceV06::normalizarDistrito((string)$distrito_usuario);
                if ($rol === 'director_distrital') {
                    $tx_stg_permitido = ($tx_stg_distrito_evento === $tx_stg_distrito_dueno);
                } elseif ($rol === 'lider') {
                    $tx_stg_permitido = ($tx_stg_lid === ($tx_stg_distrito_dueno . '|' . trim((string)$id_posicion)));
                } elseif ($rol === 'coach') {
                    $tx_stg_permitido = ($tx_stg_distrito_evento === $tx_stg_distrito_dueno && (string)($tx_stg_evento['coach_id'] ?? '') === trim((string)$id_posicion));
                } else {
                    $tx_stg_permitido = false;
                }
            }
            if (!$tx_stg_permitido) continue;
            $tx_stg_fecha = substr((string)($tx_stg_evento['fecha'] ?? ''), 0, 10);
            if ($tx_stg_fecha < $tx_stg_inicio_tendencia || $tx_stg_fecha > $tx_stg_hasta_tendencia) continue;
            $tx_stg_ym = substr($tx_stg_fecha, 0, 7);
            $tx_stg_por_mes[$tx_stg_ym][] = $tx_stg_evento;
        }

        $tx_stg_inicio_mes = new DateTimeImmutable('2026-01-01');
        $tx_stg_fin_mes = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio_query, $mes_actual));
        for ($tx_stg_mes_obj = $tx_stg_inicio_mes; $tx_stg_mes_obj <= $tx_stg_fin_mes; $tx_stg_mes_obj = $tx_stg_mes_obj->modify('first day of next month')) {
            $tx_stg_ym = $tx_stg_mes_obj->format('Y-m');
            $tx_stg_ultimo_dia = (int)$tx_stg_mes_obj->format('t');
            if ($rango_mode === 'completo' || $periodo === 'semanal' || $tx_dashboard_cruza_mes) {
                $tx_stg_dia_ini = 1;
                $tx_stg_dia_fin = $tx_stg_ultimo_dia;
            } else {
                $tx_stg_dia_ini = min((int)$dia_inicio_dashboard, $tx_stg_ultimo_dia);
                $tx_stg_dia_fin = min((int)$dia_fin_dashboard, $tx_stg_ultimo_dia);
            }
            $tx_stg_desde = sprintf('%s-%02d', $tx_stg_ym, $tx_stg_dia_ini);
            $tx_stg_hasta = sprintf('%s-%02d', $tx_stg_ym, $tx_stg_dia_fin);
            if ($tx_evo_inicio_rango_cruzado !== null && $tx_stg_ym === substr($tx_evo_inicio_rango_cruzado,0,7)) {
                $tx_stg_desde = max($tx_stg_desde,$tx_evo_inicio_rango_cruzado);
            }
            // Nunca consultar dias posteriores al corte elegido del mes actual.
            if ($tx_stg_ym === substr($fecha_fin_dashboard, 0, 7) && $tx_stg_hasta > $fecha_fin_dashboard) {
                $tx_stg_hasta = $fecha_fin_dashboard;
            }
            $tx_stg_eventos = array_values(array_filter($tx_stg_por_mes[$tx_stg_ym] ?? [], static function(array $e) use ($tx_stg_desde, $tx_stg_hasta): bool {
                $f = substr((string)($e['fecha'] ?? ''), 0, 10);
                return $f >= $tx_stg_desde && $f <= $tx_stg_hasta;
            }));
            $tx_stg_detalles = $tx_stg_motor_com->resumirVendedores($tx_stg_eventos, $tx_stg_desde, $tx_stg_hasta);
            $tx_stg_total = $tx_stg_neto = $tx_stg_precios = 0;
            $tx_stg_p2 = $tx_stg_p3 = $tx_stg_indef = 0;
            foreach ($tx_stg_detalles as $tx_stg_resumen) {
                $tx_stg_total += (int)$tx_stg_resumen['total'];
                $tx_stg_p2 += (int)$tx_stg_resumen['doble_play'];
                $tx_stg_p3 += (int)$tx_stg_resumen['triple_play'];
                $tx_stg_indef += (int)$tx_stg_resumen['play_sin_clasificar'];
                $tx_stg_neto += (float)$tx_stg_resumen['arpu_ingreso_neto'];
                $tx_stg_precios += (int)$tx_stg_resumen['arpu_cuentas'];
            }
            if ($tx_stg_total !== count($tx_stg_eventos) || $tx_stg_total !== ($tx_stg_p2 + $tx_stg_p3 + $tx_stg_indef)) {
                throw new LogicException('Index STAGING: conciliacion comercial distinta al universo Motor HC');
            }
            $tx_stg_arpu_labels[] = $tx_stg_mes_obj->format('M Y');
            $tx_stg_arpu_data[] = $tx_stg_precios > 0 ? round($tx_stg_neto / $tx_stg_precios, 2) : null;
            if ($tx_stg_ym === sprintf('%04d-%02d', $anio_query, $mes_actual)) {
                $tx_stg_mix_2p = $tx_stg_p2;
                $tx_stg_mix_3p = $tx_stg_p3;
                $tx_stg_mix_sin_clasificar = $tx_stg_indef;
                $tx_stg_universo_mes = $tx_stg_total;
                $tx_stg_resumen_mes = $tx_stg_detalles;
                $tx_stg_eventos_mes = $tx_stg_eventos;
            }
        }
        if (($periodo === 'semanal' || $tx_dashboard_cruza_mes) && !$tx_stg_gerencial) {
            // Resumen operativo por fechas REALES, incluso si cruza dos meses.
            // Evita mostrar Mix o cumplimiento de la foto mensual cuando se eligió una semana.
            $eventosSeleccionados=[];
            foreach ($tx_stg_por_mes as $listaMensual) foreach ($listaMensual as $e) {
                $f=substr((string)($e['fecha']??''),0,10);
                if ($f < $fecha_inicio_dashboard || $f > $fecha_fin_dashboard) continue;
                if ($tx_dashboard_dias_iso && !in_array((int)date('N',strtotime($f)),$tx_dashboard_dias_iso,true)) continue;
                $eventosSeleccionados[]=$e;
            }
            $detallesSeleccionados = $tx_stg_motor_com->resumirVendedores(
                $eventosSeleccionados,$fecha_inicio_dashboard,$fecha_fin_dashboard);
            $sum2=$sum3=$sumIndef=$sumTotal=0;
            foreach($detallesSeleccionados as $r) {
                $sum2+=(int)($r['doble_play']??0);
                $sum3+=(int)($r['triple_play']??0);
                $sumIndef+=(int)($r['play_sin_clasificar']??0);
                $sumTotal+=(int)($r['total']??0);
            }
            if ($sumTotal !== count($eventosSeleccionados) || $sumTotal !== $sum2+$sum3+$sumIndef) {
                throw new LogicException('Motor Comercial HC: rango semanal no concilia');
            }
            $tx_stg_eventos_mes=$eventosSeleccionados;
            $tx_stg_resumen_mes=$detallesSeleccionados;
            $tx_stg_universo_mes=$sumTotal;
            $tx_stg_mix_2p=$sum2;
            $tx_stg_mix_3p=$sum3;
            $tx_stg_mix_sin_clasificar=$sumIndef;
        }
        $tx_stg_ok = true;

        // Solo para la tarjeta de nivel inferior, usa el catalogo/foto del MOTOR HC.
        // El ADMIN/REGIONAL sin scope conserva el comparativo regional de TODOS los canales.
        if (!(!$scope_activo && in_array($rol, ['admin','director_regional'], true))) {
            $tx_stg_cumplimiento_nivel = $scope_activo ? $scope_nivel : $rol;
            $tx_stg_catalogo_lideres = $tx_stg_motor_hc->catalogoLideres($fecha_inicio_dashboard, $fecha_fin_dashboard);
            $tx_stg_hc_fotografias = $tx_stg_motor_hc->cargarHc($fecha_inicio_dashboard, $fecha_fin_dashboard);
            $tx_stg_fotos = array_keys($tx_stg_hc_fotografias['datos'] ?? []);
            usort($tx_stg_fotos, static fn($a, $b) => strcmp($b, $a));
            $tx_stg_hc_foto = $tx_stg_hc_fotografias['datos'][$tx_stg_fotos[0] ?? ''] ?? [];
            if ($tx_stg_cumplimiento_nivel === 'coach') {
                $tx_stg_hic = RankingAtribucionServiceV06::resolverEquivalenciasHic($tx_stg_motor_hc->cargarHic());
                $tx_stg_hic_canonicos = $tx_stg_hic['canonicos'] ?? [];
            }
        }
    } catch (Throwable $tx_stg_ex) {
        $tx_stg_error = $tx_stg_ex->getMessage();
        $tx_stg_ok = false;
        error_log('TalIA index_STAGING: ' . $tx_stg_error);
    }
}

// Preservar resultado de MOTOR HC para la tarjeta jerárquica.
// En perfiles gerenciales, ARPU y Mix se calculan con un universo independiente.
$tx_stg_hc_ok = $tx_stg_ok;
if ($tx_stg_aplicable && $tx_stg_gerencial && $periodo !== 'semanal' && !$tx_evo_daily_custom) {
    try {
        require_once __DIR__ . '/includes/motor_comercial_STAGING.php';
        $tx_stg_motor_gerencial = (isset($tx_stg_motor_com) && $tx_stg_motor_com instanceof MotorComercial)
            ? $tx_stg_motor_com : new MotorComercial($conexion);
        $tx_stg_indicador_modo = ($tx_dashboard_cruza_mes ? 'completo' : $rango_mode);
        $tx_stg_indicador_dia_ini = ($tx_stg_indicador_modo === 'completo') ? 1 : (int)$dia_inicio_dashboard;
        $tx_stg_indicador_dia_fin = ($tx_stg_indicador_modo === 'completo') ? 31 : (int)$dia_fin_dashboard;
        $tx_stg_res_gerencial = $tx_stg_motor_gerencial->indicadoresInstalacionesPorCatalogo(
            '2026-01-01', $fecha_fin_dashboard, $tx_stg_indicador_modo,
            $tx_stg_indicador_dia_ini, $tx_stg_indicador_dia_fin,
            $tx_stg_scope_gerencial, $tx_evo_inicio_rango_cruzado
        );
        $tx_stg_arpu_labels = [];
        $tx_stg_arpu_data = [];
        $tx_stg_mix_2p = $tx_stg_mix_3p = $tx_stg_mix_sin_clasificar = $tx_stg_universo_mes = 0;
        $tx_stg_ym_vigente = sprintf('%04d-%02d',$anio_query,$mes_actual);
        foreach ($tx_stg_res_gerencial['meses'] as $tx_stg_ym) {
            $tx_stg_r = $tx_stg_res_gerencial['datos'][$tx_stg_ym];
            $tx_stg_arpu_labels[] = (new DateTimeImmutable($tx_stg_ym.'-01'))->format('M Y');
            $tx_stg_arpu_data[] = $tx_stg_r['arpu'];
            if ($tx_stg_ym === $tx_stg_ym_vigente) {
                $tx_stg_mix_2p = (int)$tx_stg_r['doble_play'];
                $tx_stg_mix_3p = (int)$tx_stg_r['triple_play'];
                $tx_stg_mix_sin_clasificar = (int)$tx_stg_r['play_sin_clasificar'];
                $tx_stg_universo_mes = (int)$tx_stg_r['total'];
                $tx_stg_auditoria_arpu = [
                    'validos'=>(int)$tx_stg_r['arpu_cuentas'],
                    'catalogo_actual'=>(int)$tx_stg_r['arpu_fuente_catalogo'],
                ];
            }
        }
        if ($tx_stg_universo_mes !== ($tx_stg_mix_2p + $tx_stg_mix_3p + $tx_stg_mix_sin_clasificar)) {
            throw new LogicException('STAGING: Mix gerencial no concilia con total instalaciones');
        }
        if ($periodo === 'semanal' || $tx_dashboard_cruza_mes) {
            // El Mix es de las fechas reales elegidas, no solo del último mes.
            // La tendencia histórica mensual conserva sus propias series parciales.
            $gruposFechas = [];
            if ($periodo === 'semanal') {
                $fechas = $tx_fechas_seleccionadas;
                $iniBloque = $finBloque = null;
                foreach ($fechas as $f) {
                    if ($iniBloque === null) { $iniBloque=$finBloque=$f; continue; }
                    $diaSiguiente = (new DateTimeImmutable($finBloque))->modify('+1 day')->format('Y-m-d');
                    if ($f === $diaSiguiente) { $finBloque=$f; continue; }
                    $gruposFechas[]=[$iniBloque,$finBloque];$iniBloque=$finBloque=$f;
                }
                if ($iniBloque !== null) $gruposFechas[]=[$iniBloque,$finBloque];
            } else $gruposFechas=[[$fecha_inicio_dashboard,$fecha_fin_dashboard]];
            $suma=['total'=>0,'doble_play'=>0,'triple_play'=>0,'play_sin_clasificar'=>0,'arpu_cuentas'=>0,'arpu_fuente_catalogo'=>0];
            foreach ($gruposFechas as [$inicioGrupo,$finGrupo]) {
                $rangoRes=$tx_stg_motor_gerencial->indicadoresInstalacionesPorCatalogo(
                    $inicioGrupo,$finGrupo,'completo',1,31,$tx_stg_scope_gerencial);
                foreach ($rangoRes['datos'] as $resMes) {
                    foreach (array_keys($suma) as $k) $suma[$k]+=(int)($resMes[$k]??0);
                }
            }
            $tx_stg_universo_mes=$suma['total'];
            $tx_stg_mix_2p=$suma['doble_play'];
            $tx_stg_mix_3p=$suma['triple_play'];
            $tx_stg_mix_sin_clasificar=$suma['play_sin_clasificar'];
            $tx_stg_auditoria_arpu = [
                'validos'=>$suma['arpu_cuentas'],
                'catalogo_actual'=>$suma['arpu_fuente_catalogo'],
            ];
            if ($tx_stg_universo_mes !== ($tx_stg_mix_2p+$tx_stg_mix_3p+$tx_stg_mix_sin_clasificar)) {
                throw new LogicException('Mix seleccionado Motor Comercial no concilia');
            }
        }
        $tx_stg_ok = true;
        $tx_stg_error = null;
    } catch (Throwable $tx_stg_gerencial_ex) {
        $tx_stg_ok = false;
        $tx_stg_error = $tx_stg_gerencial_ex->getMessage();
        error_log('TalIA INDEX ARPU/MIX STAGING: '.$tx_stg_error);
    }
}

// Reconstruir SOLO la tarjeta jerarquica de DIRECTOR/LIDER/COACH con universo HC.
// Metrica real = cuentas certificadas Cambaceo/PDV del motor, sin atribucion textual.
if ($tx_stg_aplicable && $tx_stg_hc_ok && !(!$scope_activo && in_array($rol, ['admin','director_regional'], true))) {
    $tmp = [];
    $tx_stg_nivel = $scope_activo ? $scope_nivel : $rol;
    $tx_stg_children = [];
    $tx_stg_names = [];
    foreach ($tx_stg_hc_foto as $tx_stg_hc_row) {
        $tx_stg_d = RankingAtribucionServiceV06::normalizarDistrito((string)($tx_stg_hc_row['distrito'] ?? ''));
        if ($tx_stg_d !== $tx_stg_distrito) continue;
        $tx_stg_p = (string)($tx_stg_hc_row['id_posicion'] ?? '');
        $tx_stg_f = (string)($tx_stg_hc_row['numero_talento_gs'] ?? '');
        if ($tx_stg_p !== '') $tx_stg_names['pos:'.$tx_stg_p] = (string)($tx_stg_hc_row['nombre_colaborador'] ?? '');
        if ($tx_stg_f !== '') {
            $tx_stg_key = $tx_stg_hic_canonicos[$tx_stg_f] ?? $tx_stg_f;
            $tx_stg_names['tal:'.$tx_stg_key] = (string)($tx_stg_hc_row['nombre_colaborador'] ?? '');
        }
        // Plazas SIN eventos: incluir solo relaciones explicitas de la foto HC.
        // Las autorreferencias se incorporan exclusivamente si el Motor HC las certifica.
        $tx_stg_parent = (string)($tx_stg_hc_row['posicion_lr'] ?? '');
        $tx_stg_nom = trim((string)($tx_stg_hc_row['nombre_colaborador'] ?? ''));
        if ($tx_stg_nom === '' || strtoupper($tx_stg_nom) === 'VACANTE' || stripos($tx_stg_f, 'VACANTE') !== false) continue;
        if ($tx_stg_nivel === 'lider' && $tx_stg_parent === $tx_stg_posicion && $tx_stg_p !== $tx_stg_posicion && stripos((string)($tx_stg_hc_row['puesto_lr'] ?? ''), 'LIDER') !== false) {
            $tx_stg_children[$tx_stg_p] = ['id'=>$tx_stg_p, 'pos'=>$tx_stg_p, 'nombre'=>$tx_stg_nom, 'nivel'=>'coach', 'real'=>0];
        } elseif ($tx_stg_nivel === 'coach' && $tx_stg_parent === $tx_stg_posicion && $tx_stg_p !== $tx_stg_posicion && $tx_stg_f !== '') {
            $tx_stg_key = $tx_stg_hic_canonicos[$tx_stg_f] ?? $tx_stg_f;
            $tx_stg_children[$tx_stg_key] = ['id'=>$tx_stg_key, 'pos'=>$tx_stg_p, 'nombre'=>$tx_stg_nom, 'nivel'=>'vendedor', 'real'=>0];
        }
    }
    if ($tx_stg_nivel === 'director_distrital') {
        $cumplimiento_inferior_subtitulo = 'Lideres · HC certificado Cambaceo/PDV vs meta EO';
        foreach ($tx_stg_catalogo_lideres as $tx_stg_id => $tx_stg_lider) {
            if (($tx_stg_lider['distrito'] ?? null) !== $tx_stg_distrito) continue;
            $tx_stg_pos = (string)($tx_stg_lider['plaza'] ?? '');
            if ($tx_stg_pos === '') continue;
            $tx_stg_children[$tx_stg_id] = ['id'=>$tx_stg_id, 'pos'=>$tx_stg_pos, 'nombre'=>(string)($tx_stg_lider['nombre'] ?? $tx_stg_pos), 'nivel'=>'lider', 'real'=>0];
        }
    } elseif ($tx_stg_nivel === 'lider') {
        $cumplimiento_inferior_subtitulo = 'Coaches · HC certificado Cambaceo/PDV vs meta EO';
    } elseif ($tx_stg_nivel === 'coach') {
        $cumplimiento_inferior_subtitulo = 'Vendedores · HC certificado Cambaceo/PDV vs meta EO';
    }
    foreach ($tx_stg_eventos_mes as $tx_stg_e) {
        $tx_stg_child_id = ($tx_stg_nivel === 'director_distrital') ? (string)$tx_stg_e['lider_id'] : (($tx_stg_nivel === 'lider') ? (string)$tx_stg_e['coach_id'] : (string)$tx_stg_e['vendedor_id']);
        if (!isset($tx_stg_children[$tx_stg_child_id])) {
            $tx_stg_child_pos = ($tx_stg_nivel === 'director_distrital') ? (string)($tx_stg_e['lider_posicion'] ?? '') : (($tx_stg_nivel === 'lider') ? (string)($tx_stg_e['coach_id'] ?? '') : '');
            $tx_stg_display_name = ($tx_stg_nivel === 'director_distrital') ? (string)($tx_stg_catalogo_lideres[$tx_stg_child_id]['nombre'] ?? $tx_stg_child_id) : (($tx_stg_nivel === 'lider') ? (string)($tx_stg_names['pos:'.$tx_stg_child_id] ?? $tx_stg_child_id) : (string)($tx_stg_names['tal:'.$tx_stg_child_id] ?? $tx_stg_child_id));
            $tx_stg_children[$tx_stg_child_id] = ['id'=>$tx_stg_child_id, 'pos'=>$tx_stg_child_pos, 'nombre'=>$tx_stg_display_name, 'nivel'=>($tx_stg_nivel==='director_distrital'?'lider':($tx_stg_nivel==='lider'?'coach':'vendedor')), 'real'=>0];
        }
        $tx_stg_children[$tx_stg_child_id]['real']++;
    }
    foreach ($tx_stg_children as $tx_stg_child) {
        $tx_stg_child_id = (string)$tx_stg_child['id'];
        $tx_stg_meta = 0;
        $tx_stg_meta_fuente = 'sin_meta';
        $tx_stg_nivel_sup = tx_nivel_operativo_meta($tx_stg_nivel);
        $tx_stg_nivel_sub = tx_nivel_operativo_subordinado($tx_stg_child['nivel']);
        if ($tx_stg_nivel_sup !== '' && $tx_stg_nivel_sub !== '' && $tx_stg_child['pos'] !== '') {
            $tx_stg_meta = tx_meta_operativa_asignada_periodo($conexion, $fecha_inicio_dashboard, $fecha_fin_dashboard, $tx_stg_posicion, $tx_stg_child['pos'], $tx_stg_nivel_sup, $tx_stg_nivel_sub);
            if ($tx_stg_meta > 0) $tx_stg_meta_fuente = 'operativa';
        }
        $tx_stg_res = $tx_stg_neg = 0;
        foreach ($tx_stg_resumen_mes as $tx_stg_grupo_id => $tx_stg_stats) {
            $tx_stg_ids = explode('|', (string)$tx_stg_grupo_id);
            $tx_stg_match = ($tx_stg_nivel === 'director_distrital') ? (implode('|', array_slice($tx_stg_ids, 0, 2)) === $tx_stg_child_id) : (($tx_stg_nivel === 'lider') ? (($tx_stg_ids[2] ?? '') === $tx_stg_child_id) : (($tx_stg_ids[3] ?? '') === $tx_stg_child_id));
            if (!$tx_stg_match) continue;
            $tx_stg_res += (int)($tx_stg_stats['residencial'] ?? 0);
            $tx_stg_neg += (int)($tx_stg_stats['negocios'] ?? 0);
        }
        $tx_stg_real = (int)$tx_stg_child['real'];
        if ($tx_stg_real <= 0 && $tx_stg_meta <= 0) continue;
        $tmp[] = [
            'label'=>$tx_stg_child['nombre'], 'real'=>$tx_stg_real,
            'meta'=>$tx_stg_meta, 'pct'=>$tx_stg_meta > 0 ? round($tx_stg_real * 100 / $tx_stg_meta, 1) : 0,
            'meta_fuente'=>$tx_stg_meta_fuente,
            'res_pct'=>$tx_stg_real > 0 ? (int)round($tx_stg_res * 100 / $tx_stg_real) : null,
            'neg_pct'=>$tx_stg_real > 0 ? (int)round($tx_stg_neg * 100 / $tx_stg_real) : null,
            'mix_total'=>$tx_stg_real,
        ];
    }
}

usort($tmp, function($a, $b) {
    if ($a['pct'] == $b['pct']) return $b['real'] <=> $a['real'];
    return $b['pct'] <=> $a['pct'];
});
$tmp = array_slice($tmp, 0, 10);

$max_real_sin_meta = 0;
foreach ($tmp as $r_tmp) {
    if (($r_tmp['meta_fuente'] ?? '') === 'sin_meta') {
        $max_real_sin_meta = max($max_real_sin_meta, (int)($r_tmp['real'] ?? 0));
    }
}

foreach ($tmp as $r) {
    $cumplimiento_inferior_labels[] = $r['label'];
    $cumplimiento_inferior_real[] = (int)$r['real'];
    $cumplimiento_inferior_meta[] = (int)$r['meta'];
    $cumplimiento_inferior_pct[] = (float)$r['pct'];
    $cumplimiento_inferior_fuente[] = $r['meta_fuente'] ?? '';
    $cumplimiento_inferior_res_pct[] = $r['res_pct'] ?? null;
    $cumplimiento_inferior_neg_pct[] = $r['neg_pct'] ?? null;
    $cumplimiento_inferior_mix_total[] = (int)($r['mix_total'] ?? 0);
    $cumplimiento_inferior_visual_pct[] = (($r['meta_fuente'] ?? '') === 'sin_meta' && $max_real_sin_meta > 0)
        ? round(((int)$r['real'] / $max_real_sin_meta) * 100, 1)
        : (float)$r['pct'];
}



// ── CUMPLIMIENTO POR CANAL DE VENTA ──────────────────────────────────────────
// Visible únicamente para Administrador, Director Regional y Director Distrital.
// Compara Venta instalada (instalaciones.origen_prospecto) vs meta por canal en metas_instalacion.
// Usa mapa consolidado para homologar nombres y separar Venta Técnico del Call Center.
$cumplimiento_canal_items = [];
$mostrar_cumplimiento_canal = false;
$scope_canal_sql = '';

if ($rol_consulta === 'admin') {
    $mostrar_cumplimiento_canal = true;
    $scope_canal_sql = '';
} elseif ($scope_filtrar_por_distrito) {
    $mostrar_cumplimiento_canal = true;
    $scope_canal_sql = $scope_distritos_sql;
} elseif (!$scope_activo && in_array($rol, ['director_regional','director_distrital'], true)) {
    $mostrar_cumplimiento_canal = true;
    if ($rol === 'director_distrital') {
        $scope_canal_sql = $distritos_sql;
    }
}

if ($mostrar_cumplimiento_canal) {
    // Catálogo fijo consolidado para evitar duplicados como:
    // Call Center / Call Center BTL / Call Center Web.
    $canales_cumplimiento = array_keys(tx_canales_cumplimiento_dashboard());

    foreach ($canales_cumplimiento as $canal) {
        $real_canal = tx_real_inst_canal_dashboard(
            $conexion,
            $canal,
            $mes_actual,
            $anio_query,
            $cond_dia_fecha,
            $scope_canal_sql
        );
        $meta_canal = tx_meta_canal_dashboard(
            $conexion,
            $canal,
            $mes_actual,
            $anio_query,
            $rango_mode,
            $dia_inicio_dashboard,
            $dia_fin_dashboard,
            $scope_canal_sql
        );

        if ($real_canal <= 0 && $meta_canal <= 0) continue;

        $pct_canal = $meta_canal > 0 ? round(($real_canal / $meta_canal) * 100, 1) : 0;
        $mix_res_neg_canal = tx_mix_res_neg_canal_dashboard($conexion, $canal, $mes_actual, $anio_query, $cond_dia_fecha, $scope_canal_sql);
        $cumplimiento_canal_items[] = [
            'nombre' => $canal,
            'real' => $real_canal,
            'meta' => $meta_canal,
            'pct' => $pct_canal,
            'visual_pct' => $pct_canal,
            'res_pct' => $mix_res_neg_canal['res_pct'],
            'neg_pct' => $mix_res_neg_canal['neg_pct'],
            'mix_total' => $mix_res_neg_canal['total']
        ];
    }

    usort($cumplimiento_canal_items, function($a, $b) {
        if ((float)$a['pct'] == (float)$b['pct']) {
            return ((int)$b['real']) <=> ((int)$a['real']);
        }
        return ((float)$b['pct']) <=> ((float)$a['pct']);
    });
    $cumplimiento_canal_items = array_slice($cumplimiento_canal_items, 0, 10);
}


// ── MIX INSTALACIONES ────────────────────────────────────────────────────────
if ($tx_stg_aplicable) {
    // No ejecutar de nuevo el cálculo legado por LIKE TV: Motor Comercial manda.
    $inst_2p = $tx_stg_ok ? $tx_stg_mix_2p : 0;
    $inst_3p = $tx_stg_ok ? $tx_stg_mix_3p : 0;
} else {
if ($rol_consulta === 'admin') {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-'");
} elseif ($scope_filtrar_por_distrito) {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-' AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-' AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_mix_inst = mysqli_query($conexion, "SELECT 0 as p3, 0 as p2");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_mix = mysqli_prepare($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE $tx_dashboard_where_inst AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
        $tipos = str_repeat('s', count($folio_ids));
        $bind  = array_values($folio_ids);
        mysqli_stmt_bind_param($stmt_mix, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_mix);
        $r_mix_inst = mysqli_stmt_get_result($stmt_mix);
    }
}
$mix_inst = $r_mix_inst ? mysqli_fetch_assoc($r_mix_inst) : ['p3'=>0,'p2'=>0];
$inst_3p = (int)($mix_inst['p3'] ?? 0);
$inst_2p = (int)($mix_inst['p2'] ?? 0);




}

// ── MIX VENTAS ───────────────────────────────────────────────────────────────
if ($rol_consulta === 'admin') {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE $tx_dashboard_where_ventas");
} elseif ($scope_filtrar_por_distrito) {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE $tx_dashboard_where_ventas AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE $tx_dashboard_where_ventas AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_mix_vent = mysqli_query($conexion, "SELECT 0 as p3, 0 as p2");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_mix_v = mysqli_prepare($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE $tx_dashboard_where_ventas AND folio_empleado IN ($ph)");
        $tipos = str_repeat('s', count($folio_ids));
        $bind  = array_values($folio_ids);
        mysqli_stmt_bind_param($stmt_mix_v, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_mix_v);
        $r_mix_vent = mysqli_stmt_get_result($stmt_mix_v);
    }
}
$mix_vent = $r_mix_vent ? mysqli_fetch_assoc($r_mix_vent) : ['p3'=>0,'p2'=>0];
$vent_3p = (int)($mix_vent['p3'] ?? 0);
$vent_2p = (int)($mix_vent['p2'] ?? 0);

// ── EVOLUCIÓN STAGING: desde enero 2026; cada selector tiene sus propias matrices/tablas. ──
// Canal conserva consultas históricas originales, solo amplía ventana de fechas.
$tx_evo_desde_enero = ((int)$anio_query >= 2026 && !$tx_stg_es_vendedor);
$meses_labels = [];
$tx_evo_week_keys = [];
$tx_evo_week_ranges = [];
$tx_evo_daily_dates = [];
if ($tx_evo_daily_custom) {
    $cursorDia = new DateTimeImmutable($fecha_inicio_dashboard);
    $finDia = new DateTimeImmutable($fecha_fin_dashboard);
    for ($d=$cursorDia; $d<=$finDia; $d=$d->modify('+1 day')) {
        $tx_evo_daily_dates[] = $d->format('Y-m-d');
        $meses_labels[] = $d->format('d/m');
    }
    $fecha_inicio_evolucion = $fecha_inicio_dashboard;
} elseif ($periodo === 'semanal' && (int)$tx_nav_anio_semana >= 2026) {
    // ISO 2026-S01: LUN 29/12/2025 a DOM 04/01/2026. No iniciar el 01-ene.
    $tx_evo_primer_lunes = (new DateTimeImmutable('2026-01-04'))->setISODate(2026,1,1);
    $tx_evo_ultimo_lunes = $tx_semana_lunes;
    for ($lunes = $tx_evo_primer_lunes; $lunes <= $tx_evo_ultimo_lunes; $lunes = $lunes->modify('+7 days')) {
        $domingo = $lunes->modify('+6 days');
        // En el último período se rotula la fecha del corte, no un domingo futuro.
        $finRotulo = ($lunes == $tx_evo_ultimo_lunes && $domingo->format('Y-m-d') > $fecha_fin_dashboard)
            ? new DateTimeImmutable($fecha_fin_dashboard) : $domingo;
        $tx_evo_week_keys[] = $lunes->format('oW');
        $esSemanaCambioAnio = $lunes->format('Y') !== $finRotulo->format('Y');
        $meses_labels[] = 'S'.$lunes->format('W').' · '.($esSemanaCambioAnio
            ? $lunes->format('d/m/y').'–'.$finRotulo->format('d/m/y')
            : $lunes->format('d/m').'–'.$finRotulo->format('d/m'));
        $tx_evo_week_ranges[] = [$lunes->format('Y-m-d'),$finRotulo->format('Y-m-d')];
    }
    $fecha_inicio_evolucion = '2025-12-29';
} elseif ($tx_evo_desde_enero) {
    $tx_evo_cursor = new DateTimeImmutable('2026-01-01');
    $tx_evo_hasta = new DateTimeImmutable(sprintf('%04d-%02d-01',$anio_query,$mes_actual));
    while ($tx_evo_cursor <= $tx_evo_hasta) {
        $meses_labels[] = $tx_evo_cursor->format('M Y');
        $tx_evo_cursor = $tx_evo_cursor->modify('+1 month');
    }
    $fecha_inicio_evolucion = '2026-01-01';
} else {
    $fecha_inicio_evolucion = date('Y-m-01',mktime(0,0,0,$mes_actual-5,1,$anio_query));
    for ($i=5;$i>=0;$i--) $meses_labels[]=date('M Y',mktime(0,0,0,$mes_actual-$i,1,$anio_query));
}
$tx_evo_nmeses = count($meses_labels);
$datos_inst_stacked=[];
$datos_vent_stacked=[];
$datos_inst_oferta_stacked=['Residencial'=>array_fill(0,$tx_evo_nmeses,0),'Negocios'=>array_fill(0,$tx_evo_nmeses,0)];
$datos_vent_oferta_stacked=['Residencial'=>array_fill(0,$tx_evo_nmeses,0),'Negocios'=>array_fill(0,$tx_evo_nmeses,0)];
$datos_inst_play_stacked=[];
$datos_vent_play_stacked=[];
$datos_inst_bundle_stacked=[];
$datos_vent_bundle_stacked=[];
$evo_view = in_array($_GET['evo_view'] ?? '',['canal','oferta','play','bundle'],true) ? $_GET['evo_view'] : 'canal';
if ($tx_stg_es_vendedor && in_array($evo_view,['play','bundle'],true)) $evo_view='canal';
$evo_titulo = match($evo_view) {
    'oferta'=>'Evolución — Desde enero 2026 por oferta RES-NEG',
    'play'=>'Evolución — Desde enero 2026 por Plays',
    'bundle'=>'Evolución — Desde enero 2026 por Bundle',
    default=>$tx_evo_desde_enero?'Evolución — Desde enero 2026 por canal':'Evolución — Últimos 6 meses por canal',
};
$evo_participacion_titulo = match($evo_view) {
    'oferta'=>'Participación por oferta RES-NEG — Desde enero 2026 (%)',
    'play'=>'Participación por Plays — Desde enero 2026 (%)',
    'bundle'=>'Participación por Bundle — Desde enero 2026 (%)',
    default=>$tx_evo_desde_enero?'Participación por canal — Desde enero 2026 (%)':'Participación por canal — Últimos 6 meses (%)',
};
$evo_vent_sub=match($evo_view) { 'oferta'=>'Ventas por oferta RES-NEG', 'play'=>'Ventas por Plays', 'bundle'=>'Ventas por Bundle', default=>'Ventas por canal' };
$evo_inst_sub=match($evo_view) { 'oferta'=>'Instalaciones por oferta RES-NEG', 'play'=>'Instalaciones por Plays', 'bundle'=>'Instalaciones por Bundle', default=>'Instalaciones por origen' };
$evo_col_label=match($evo_view) { 'oferta'=>'Oferta', 'play'=>'Play', 'bundle'=>'Bundle', default=>'Canal' };
if ($tx_evo_daily_custom) {
    $tx_nombre_evo=match($evo_view) {'oferta'=>'Oferta RES-NEG','play'=>'Plays','bundle'=>'Bundle',default=>'Canal de venta'};
    $tx_etiqueta_diaria=date('d/m/Y',strtotime($fecha_inicio_dashboard)).' – '.date('d/m/Y',strtotime($fecha_fin_dashboard));
    $evo_titulo='Evolución diaria · '.$tx_nombre_evo.' · '.$tx_etiqueta_diaria;
    $evo_participacion_titulo='Participación diaria por '.$tx_nombre_evo.' (%) · '.$tx_etiqueta_diaria;
}


// En modo semanal los encabezados y las tablas representan SEMANAS ISO.
if ($periodo === 'semanal' && $tx_evo_week_keys) {
    $tx_evo_wlabel = 'Semanal ISO · desde S01 de 2026';
    $evo_titulo = match($evo_view) {
        'oferta' => 'Evolución semanal — Oferta RES-NEG',
        'play' => 'Evolución semanal — Plays',
        'bundle' => 'Evolución semanal — Bundle',
        default => 'Evolución semanal — Canal de venta',
    };
    $evo_participacion_titulo = match($evo_view) {
        'oferta' => 'Participación semanal por oferta RES-NEG (%)',
        'play' => 'Participación semanal por Plays (%)',
        'bundle' => 'Participación semanal por Bundle (%)',
        default => 'Participación semanal por canal (%)',
    };
}
// ARPU anterior del STAGING conserva íntegra su lógica.
if ($tx_evo_daily_custom) {
    $arpu_labels = $meses_labels;
    $arpu_data = array_fill(0, $tx_evo_nmeses, null);
} elseif ($periodo === 'semanal' && $tx_evo_week_keys) {
    $arpu_labels = array_map(static fn($k) => 'S'.(int)substr((string)$k,4),$tx_evo_week_keys);
    $arpu_data = array_fill(0,count($meses_labels),null);
} elseif ($tx_stg_aplicable && $tx_stg_ok) {
    $arpu_labels = $tx_stg_arpu_labels;
    $arpu_data = $tx_stg_arpu_data;
} elseif (!$tx_stg_aplicable) {
    $arpu_labels = $meses_labels;
    $arpu_data = array_fill(0,$tx_evo_nmeses,0);
    foreach ($meses_labels as $idx_arpu=>$etiqueta) {
        $fecha_mes = DateTimeImmutable::createFromFormat('!M Y',$etiqueta);
        if (!$fecha_mes) continue;
        $mes_arpu=(int)$fecha_mes->format('n');$anio_arpu=(int)$fecha_mes->format('Y');
        $ultimo_dia_mes_arpu=(int)$fecha_mes->format('t');
        $cond_arpu_dia=($rango_mode==='completo' || $periodo==='semanal' || $tx_dashboard_cruza_mes)?'':(' AND DAY(fecha) BETWEEN '.min($dia_inicio_dashboard,$ultimo_dia_mes_arpu).' AND '.min($dia_fin_dashboard,$ultimo_dia_mes_arpu));
        if ($rol_consulta==='admin') $arpu_data[$idx_arpu]=tx_arpu_instalaciones_dashboard($conexion,$mes_arpu,$anio_arpu,$cond_arpu_dia);
        elseif ($scope_filtrar_por_distrito) $arpu_data[$idx_arpu]=tx_arpu_instalaciones_dashboard($conexion,$mes_arpu,$anio_arpu,$cond_arpu_dia,$scope_distritos_sql);
        elseif ($por_distrito) $arpu_data[$idx_arpu]=tx_arpu_instalaciones_dashboard($conexion,$mes_arpu,$anio_arpu,$cond_arpu_dia,$distritos_sql);
        else $arpu_data[$idx_arpu]=tx_arpu_instalaciones_dashboard($conexion,$mes_arpu,$anio_arpu,$cond_arpu_dia,'',$folio_ids);
    }
} else { $arpu_labels=[];$arpu_data=[]; }

// CANAL DE VENTA: mismos SELECT, filtros, clasificaciones y participaciones del producto.
// Sólo se amplía el período inicial; se limita al cierre seleccionado.
if (!$tx_evo_daily_custom && ($periodo !== 'semanal' || !$tx_evo_week_keys)) {
$tx_evo_where_cruce_inst = $tx_evo_inicio_rango_cruzado !== null
    ? " AND (fecha < '$tx_evo_filtro_mes_inicio' OR fecha >= '$tx_esc_ini')" : '';
$tx_evo_where_cruce_vent = $tx_evo_inicio_rango_cruzado !== null
    ? " AND (fecha_cierre < '$tx_evo_filtro_mes_inicio' OR fecha_cierre >= '$tx_esc_ini')" : '';
$query_inst = "SELECT MONTH(fecha) as mes, YEAR(fecha) as anio, origen_prospecto, COUNT(*) as total
    FROM instalaciones
    WHERE fecha >= '$fecha_inicio_evolucion' AND fecha <= '$fecha_fin_dashboard' $cond_dia_evolucion_fecha $tx_evo_where_cruce_inst AND origen_prospecto <> '-' ";
if (!in_array($rol_consulta, ['admin','director_regional'],true) && $scope_filtrar_por_distrito) {
    $query_inst .= " AND distrito IN ($scope_distritos_sql)";
} elseif (!in_array($rol_consulta, ['admin','director_regional'],true) && $por_distrito) {
    $query_inst .= " AND distrito IN ($distritos_sql)";
} elseif (!in_array($rol_consulta, ['admin','director_regional'],true) && !empty($folio_ids)) {
    $ph = tx_sql_in_escaped($conexion, $folio_ids);
    $query_inst .= " AND folio_empleado IN ($ph)";
}
$query_inst .= " GROUP BY anio, mes, origen_prospecto";
$res_i = mysqli_query($conexion,$query_inst);
while ($res_i && ($row=mysqli_fetch_assoc($res_i))) {
    $label_mes=date('M Y',mktime(0,0,0,$row['mes'],1,$row['anio']));
    $idx=array_search($label_mes,$meses_labels,true);
    if ($idx!==false) {
        $orig=$row['origen_prospecto']?:'OTROS';
        if (!isset($datos_inst_stacked[$orig])) $datos_inst_stacked[$orig]=array_fill(0,$tx_evo_nmeses,0);
        $datos_inst_stacked[$orig][$idx]=(int)$row['total'];
    }
}
$query_vent = "SELECT MONTH(fecha_cierre) as mes, YEAR(fecha_cierre) as anio, canal_venta, COUNT(*) as total
    FROM ventas
    WHERE fecha_cierre >= '$fecha_inicio_evolucion' AND fecha_cierre <= '$fecha_fin_dashboard' $cond_dia_evolucion_fecha_cierre $tx_evo_where_cruce_vent ";
if (!in_array($rol_consulta, ['admin','director_regional'],true) && $scope_filtrar_por_distrito) {
    $query_vent .= " AND distrito IN ($scope_distritos_sql)";
} elseif (!in_array($rol_consulta, ['admin','director_regional'],true) && $por_distrito) {
    $query_vent .= " AND distrito IN ($distritos_sql)";
} elseif (!in_array($rol_consulta, ['admin','director_regional'],true) && !empty($folio_ids)) {
    $ph = tx_sql_in_escaped($conexion, $folio_ids);
    $query_vent .= " AND folio_empleado IN ($ph)";
}
$query_vent .= " GROUP BY anio, mes, canal_venta";
$res_v = mysqli_query($conexion,$query_vent);
while ($res_v && ($row=mysqli_fetch_assoc($res_v))) {
    $label_mes=date('M Y',mktime(0,0,0,$row['mes'],1,$row['anio']));
    $idx=array_search($label_mes,$meses_labels,true);
    if ($idx!==false) {
        $canal=$row['canal_venta']?:'OTROS';
        if (!isset($datos_vent_stacked[$canal])) $datos_vent_stacked[$canal]=array_fill(0,$tx_evo_nmeses,0);
        $datos_vent_stacked[$canal][$idx]=(int)$row['total'];
    }
}

}
$tx_evo_fuente_catalogo_ok=false;
$tx_evo_catalogo_error=null;
$tx_evo_total_inst=[];$tx_evo_total_vent=[];
if ($tx_evo_desde_enero && $periodo !== 'semanal' && !$tx_evo_daily_custom) {
    try {
        if (!($conexion instanceof mysqli)) throw new RuntimeException('Conexión comercial no disponible');
        require_once __DIR__.'/includes/motor_comercial_STAGING.php';
        $tx_evo_motor = isset($tx_stg_motor_com) && $tx_stg_motor_com instanceof MotorComercial ? $tx_stg_motor_com : new MotorComercial($conexion);
        // Visibilidad ADMIN/REGIONAL sin HC propio; filtro de distrito y línea para demás.
        if ($tx_stg_gerencial) {
            $tx_evo_scope=$tx_stg_scope_gerencial;
        } else {
            $tx_evo_scope=['tipo'=>'FOLIOS','folios'=>$folio_ids];
        }
        $tx_evo_modo = $tx_dashboard_cruza_mes ? 'completo' : $rango_mode;
        $tx_evo_res=$tx_evo_motor->evolucionPorCatalogo(
            $fecha_inicio_evolucion,$fecha_fin_dashboard,$tx_evo_modo,
            $tx_evo_modo === 'completo' ? 1 : (int)$dia_inicio_dashboard,
            $tx_evo_modo === 'completo' ? 31 : (int)$dia_fin_dashboard,
            $tx_evo_scope,$tx_evo_inicio_rango_cruzado
        );
        $tx_evo_total_inst=$tx_evo_res['totales']['instalaciones'];
        $tx_evo_total_vent=$tx_evo_res['totales']['ventas'];
        // Conciliación cruzada contra el Canal de Venta que el ADMIN ya validó.
        // Falla explícita: un universo comercial distinto no se disfraza de igualdad.
        if (!$scope_activo && $rol === 'admin') {
            foreach ($meses_labels as $idx_can => $label_can) {
                $ym_can=date('Y-m',strtotime('1 '.$label_can));
                $c_inst=array_sum(array_map(static fn($a)=>(int)($a[$idx_can]??0),$datos_inst_stacked));
                $c_vent=array_sum(array_map(static fn($a)=>(int)($a[$idx_can]??0),$datos_vent_stacked));
                if ($c_inst !== (int)($tx_evo_total_inst[$ym_can]??-1) || $c_vent !== (int)($tx_evo_total_vent[$ym_can]??-1)) {
                    throw new LogicException('Evolución: no concilia con Canal de Venta para '.$ym_can);
                }
            }
        }
        foreach (['oferta','play','bundle'] as $dim) {
            foreach (['instalaciones','ventas'] as $source) {
                $matrix=[];
                foreach ($tx_evo_res['datos'][$dim][$source] as $cat=>$months) $matrix[$cat]=array_values($months);
                if ($dim==='oferta' && $source==='instalaciones') $datos_inst_oferta_stacked=$matrix;
                elseif ($dim==='oferta') $datos_vent_oferta_stacked=$matrix;
                elseif ($dim==='play' && $source==='instalaciones') $datos_inst_play_stacked=$matrix;
                elseif ($dim==='play') $datos_vent_play_stacked=$matrix;
                elseif ($source==='instalaciones') $datos_inst_bundle_stacked=$matrix;
                else $datos_vent_bundle_stacked=$matrix;
            }
        }
        // Validación ARPU/MIX vs evolución comercial: MISMO universo de instalaciones
        // por mes (incluyendo «Sin clasificar»). Si no concilia, no mostrar cifras.
        if ($tx_stg_gerencial && $tx_stg_ok && $periodo !== 'semanal') {
            foreach (($tx_stg_res_gerencial['meses'] ?? []) as $ym_auditoria) {
                $total_indicadores = (int)($tx_stg_res_gerencial['datos'][$ym_auditoria]['total'] ?? -1);
                $total_evolucion = (int)($tx_evo_total_inst[$ym_auditoria] ?? -2);
                if ($total_indicadores !== $total_evolucion) {
                    $tx_stg_ok = false;
                    $tx_stg_error = 'ARPU/Mix y Evolución no concilian para '.$ym_auditoria;
                    error_log('TalIA STAGING: '.$tx_stg_error);
                    break;
                }
            }
        }
        $tx_evo_fuente_catalogo_ok=true;
    } catch (Throwable $tx_evo_exc) {
        $tx_evo_catalogo_error=$tx_evo_exc->getMessage();
        error_log('TalIA Index Evolution STAGING: '.$tx_evo_catalogo_error);
    }
} elseif ($periodo !== 'semanal' && !$tx_evo_daily_custom) {
    // Perfil vendedor intacto: rama legado de RES/NEG de evolución, sin botón Bundle.
    $segmento_inst_expr_evo=tx_segmento_instalacion_expr_dashboard($conexion);
    $query_inst_oferta = "SELECT MONTH(fecha) as mes, YEAR(fecha) as anio, ($segmento_inst_expr_evo) as oferta, COUNT(*) as total FROM instalaciones
       WHERE fecha >= '$fecha_inicio_evolucion' $cond_dia_evolucion_fecha AND origen_prospecto <> '-' ";
    if ($rol_consulta!=='admin' && $scope_filtrar_por_distrito) $query_inst_oferta.=" AND distrito IN ($scope_distritos_sql)";
    elseif ($rol_consulta!=='admin' && $por_distrito) $query_inst_oferta.=" AND distrito IN ($distritos_sql)";
    elseif ($rol_consulta!=='admin' && !empty($folio_ids)) $query_inst_oferta.=" AND folio_empleado IN (".tx_sql_in_escaped($conexion,$folio_ids).")";
    $query_inst_oferta.=' GROUP BY anio, mes, oferta';
    $res_io=mysqli_query($conexion,$query_inst_oferta);
    while($res_io && ($row=mysqli_fetch_assoc($res_io))) {
        $idx=array_search(date('M Y',mktime(0,0,0,$row['mes'],1,$row['anio'])),$meses_labels,true);
        if ($idx!==false) {
            $cat=in_array(strtoupper((string)$row['oferta']),['NEG','NEGOCIOS'],true)?'Negocios':'Residencial';
            $datos_inst_oferta_stacked[$cat][$idx]=(int)$row['total'];
        }
    }
    $segmento_venta_expr_evo=tx_segmento_venta_expr_dashboard($conexion,'v');
    $query_vent_oferta="SELECT MONTH(v.fecha_cierre) as mes,YEAR(v.fecha_cierre) as anio,($segmento_venta_expr_evo) as oferta,COUNT(*) as total FROM ventas v WHERE v.fecha_cierre >= '$fecha_inicio_evolucion' ".str_replace('fecha_cierre','v.fecha_cierre',$cond_dia_evolucion_fecha_cierre)." ";
    if ($rol_consulta!=='admin' && $scope_filtrar_por_distrito) $query_vent_oferta.=" AND v.distrito IN ($scope_distritos_sql)";
    elseif ($rol_consulta!=='admin' && $por_distrito) $query_vent_oferta.=" AND v.distrito IN ($distritos_sql)";
    elseif ($rol_consulta!=='admin' && !empty($folio_ids)) $query_vent_oferta.=" AND v.folio_empleado IN (".tx_sql_in_escaped($conexion,$folio_ids).")";
    $query_vent_oferta.=' GROUP BY anio, mes, oferta';
    $res_vo=mysqli_query($conexion,$query_vent_oferta);
    while($res_vo && ($row=mysqli_fetch_assoc($res_vo))) {
        $idx=array_search(date('M Y',mktime(0,0,0,$row['mes'],1,$row['anio'])),$meses_labels,true);
        if ($idx!==false) {
            $cat=in_array(strtoupper((string)$row['oferta']),['NEG','NEGOCIOS'],true)?'Negocios':'Residencial';
            $datos_vent_oferta_stacked[$cat][$idx]=(int)$row['total'];
        }
    }
}
// ── SEMANAS ISO: una sola consulta agrupada por fuente; ENERO 2026 empieza 29-DIC-2025 ──
// Nunca obtener el alcance desde GET: se reutiliza el scope de rol ya autorizado.
// El motor STAGING clasifica con las MISMAS funciones que el mensual.
if ($periodo === 'semanal' && $tx_evo_week_keys) {
    try {
        require_once __DIR__.'/includes/motor_comercial_STAGING.php';
        $tx_evo_motor_sem = (isset($tx_stg_motor_com) && $tx_stg_motor_com instanceof MotorComercial)
            ? $tx_stg_motor_com : new MotorComercial($conexion);
        if (!method_exists($tx_evo_motor_sem,'seriesSemanalesPorCatalogo')) {
            throw new RuntimeException('Actualizar también motor_comercial_STAGING.php: faltan series ISO');
        }
        $tx_evo_scope_sem = $tx_stg_gerencial ? $tx_stg_scope_gerencial : ['tipo'=>'FOLIOS','folios'=>$folio_ids];
        $tx_evo_result_sem = $tx_evo_motor_sem->seriesSemanalesPorCatalogo(
            $fecha_inicio_evolucion,$fecha_fin_dashboard,$tx_evo_scope_sem,$tx_dashboard_dias_iso
        );
        if ($tx_evo_result_sem['semanas'] !== $tx_evo_week_keys) {
            throw new LogicException('Semanas ISO de índice y Motor Comercial no concilian');
        }
        $tx_evo_fuente_catalogo_ok = true;
        $tx_evo_total_inst = $tx_evo_result_sem['totales']['instalaciones'];
        $tx_evo_total_vent = $tx_evo_result_sem['totales']['ventas'];
        $tx_evo_dim_arrays = [];
        foreach (['canal','oferta','play','bundle'] as $dim) {
            foreach (['instalaciones','ventas'] as $origen) {
                $matrix = [];
                foreach ($tx_evo_result_sem['datos'][$dim][$origen] as $categoria => $serie) {
                    $matrix[$categoria] = array_values($serie);
                }
                $tx_evo_dim_arrays[$dim][$origen]=$matrix;
            }
        }
        $datos_inst_stacked=$tx_evo_dim_arrays['canal']['instalaciones'];
        $datos_vent_stacked=$tx_evo_dim_arrays['canal']['ventas'];
        $datos_inst_oferta_stacked=$tx_evo_dim_arrays['oferta']['instalaciones'];
        $datos_vent_oferta_stacked=$tx_evo_dim_arrays['oferta']['ventas'];
        $datos_inst_play_stacked=$tx_evo_dim_arrays['play']['instalaciones'];
        $datos_vent_play_stacked=$tx_evo_dim_arrays['play']['ventas'];
        $datos_inst_bundle_stacked=$tx_evo_dim_arrays['bundle']['instalaciones'];
        $datos_vent_bundle_stacked=$tx_evo_dim_arrays['bundle']['ventas'];
        // ARPU gerencial: TODOS los canales. Coach/Líder: SOLO eventos certificados HC.
        if ($tx_stg_gerencial) {
            $arpu_data=array_values($tx_evo_result_sem['arpu']);
            $tx_stg_ok=true; $tx_stg_error=null;
            $tx_stg_auditoria_arpu=[
                'validos'=>(int)($tx_evo_result_sem['arpu_cuentas'][end($tx_evo_week_keys)]??0),
                'catalogo_actual'=>0,
            ];
            $tx_evo_ultima_key=end($tx_evo_week_keys);
            $tx_stg_universo_mes=(int)($tx_evo_total_inst[$tx_evo_ultima_key]??0);
            $tx_stg_mix_2p=(int)($tx_evo_result_sem['datos']['play']['instalaciones']['2P'][$tx_evo_ultima_key]??0);
            $tx_stg_mix_3p=(int)($tx_evo_result_sem['datos']['play']['instalaciones']['3P'][$tx_evo_ultima_key]??0);
            $tx_stg_mix_sin_clasificar=(int)($tx_evo_result_sem['datos']['play']['instalaciones']['Sin clasificar'][$tx_evo_ultima_key]??0);
        } elseif ($tx_stg_aplicable && $tx_stg_hc_ok) {
            if (!method_exists($tx_evo_motor_sem,'indicadoresSemanalesHc')) {
                throw new RuntimeException('Actualizar Motor Comercial STAGING: falta serie HC ISO');
            }
            $eventosHc = [];
            foreach (($tx_stg_por_mes ?? []) as $eventosMes) foreach ($eventosMes as $e) {
                $f=substr((string)($e['fecha']??''),0,10);
                if ($f>=$fecha_inicio_evolucion && $f<=$fecha_fin_dashboard) $eventosHc[]=$e;
            }
            $arpuHcPorSemana = $tx_evo_motor_sem->indicadoresSemanalesHc(
                $eventosHc,$fecha_inicio_evolucion,$fecha_fin_dashboard,$tx_dashboard_dias_iso
            );
            $arpu_data=[];
            foreach ($tx_evo_week_keys as $key) $arpu_data[]=$arpuHcPorSemana[$key]['arpu']??null;
            $tx_stg_ok=true; $tx_stg_error=null;
        } elseif ($tx_stg_aplicable) {
            // No convertir una falla del HC en permiso para mostrar ARPU de otros canales.
            $arpu_data=array_fill(0,count($tx_evo_week_keys),null);
        } else {
            // Vendedor: se conserva alcance limitado exclusivamente a sus folios.
            $arpu_data=array_values($tx_evo_result_sem['arpu']);
        }
        $arpu_labels=array_map(static fn($k) => 'S'.(int)substr((string)$k,4),$tx_evo_week_keys);
        $tx_evo_semana_last=end($tx_evo_week_keys);
        $tx_evo_semana_resumen=(string)($tx_evo_result_sem['rangos'][$tx_evo_semana_last]['desde']??'');
        // El total semanal por dimensión debe coincidir EXACTAMENTE con el KPI de corte
        // solo cuando están seleccionados los mismos días y se usa el mismo scope.
        foreach (['ventas','instalaciones'] as $fuente) foreach (['canal','oferta','play','bundle'] as $dim) {
            foreach ($tx_evo_week_keys as $k) {
                $n=0;
                foreach ($tx_evo_result_sem['datos'][$dim][$fuente] as $serie) $n+=(int)($serie[$k]??0);
                if ($n !== (int)$tx_evo_result_sem['totales'][$fuente][$k]) {
                    throw new LogicException('Índice: totales semanales no concilian '.$fuente.' '.$dim.' '.$k);
                }
            }
        }
    } catch (Throwable $tx_evo_sem_ex) {
        $tx_evo_fuente_catalogo_ok=false;
        $tx_evo_catalogo_error=$tx_evo_sem_ex->getMessage();
        error_log('TalIA INDEX series ISO STAGING: '.$tx_evo_catalogo_error);
        $datos_inst_stacked=$datos_vent_stacked=[];
        $datos_inst_oferta_stacked=$datos_vent_oferta_stacked=[];
        $datos_inst_play_stacked=$datos_vent_play_stacked=[];
        $datos_inst_bundle_stacked=$datos_vent_bundle_stacked=[];
        $arpu_data=array_fill(0,count($meses_labels),null);
        $arpu_labels=$meses_labels;
        if ($tx_stg_aplicable) {
            $tx_stg_ok=false;
            $tx_stg_error=$tx_evo_catalogo_error;
        }
    }
}
// ── RANGO PERSONALIZADO: EVOLUCIÓN DIARIA DEL INTERVALO EXACTO ────────────────
// 28-sep a 10-oct = 13 columnas. Nunca se incluyen enero-agosto ni septiembre 1–27.
// Las 4 dimensiones y el ARPU usan un único resultado comercial clasificado.
if ($tx_evo_daily_custom && $tx_evo_daily_dates) {
    try {
        require_once __DIR__.'/includes/motor_comercial_STAGING.php';
        $motorDiario = isset($tx_stg_motor_com) && $tx_stg_motor_com instanceof MotorComercial
            ? $tx_stg_motor_com : new MotorComercial($conexion);
        if (!method_exists($motorDiario, 'seriesDiariasPorCatalogo')) {
            throw new RuntimeException('Actualizar motor_comercial_STAGING.php: faltan series diarias');
        }
        $scopeDiario = $tx_stg_gerencial ? $tx_stg_scope_gerencial : ['tipo'=>'FOLIOS','folios'=>$folio_ids];
        $resultadoDiario = $motorDiario->seriesDiariasPorCatalogo(
            $fecha_inicio_dashboard, $fecha_fin_dashboard, $scopeDiario
        );
        if ($resultadoDiario['dias'] !== $tx_evo_daily_dates) {
            throw new LogicException('El calendario diario del Motor Comercial no concilia con el INDEX');
        }
        $tx_evo_fuente_catalogo_ok = true;
        $tx_evo_total_inst = $resultadoDiario['totales']['instalaciones'];
        $tx_evo_total_vent = $resultadoDiario['totales']['ventas'];
        $matricesDiarias = [];
        foreach (['canal','oferta','play','bundle'] as $dimension) {
            foreach (['ventas','instalaciones'] as $origen) {
                $matricesDiarias[$dimension][$origen] = [];
                foreach ($resultadoDiario['datos'][$dimension][$origen] as $categoria=>$serie) {
                    $matricesDiarias[$dimension][$origen][$categoria] = array_values($serie);
                }
            }
        }
        $datos_inst_stacked = $matricesDiarias['canal']['instalaciones'];
        $datos_vent_stacked = $matricesDiarias['canal']['ventas'];
        $datos_inst_oferta_stacked = $matricesDiarias['oferta']['instalaciones'];
        $datos_vent_oferta_stacked = $matricesDiarias['oferta']['ventas'];
        $datos_inst_play_stacked = $matricesDiarias['play']['instalaciones'];
        $datos_vent_play_stacked = $matricesDiarias['play']['ventas'];
        $datos_inst_bundle_stacked = $matricesDiarias['bundle']['instalaciones'];
        $datos_vent_bundle_stacked = $matricesDiarias['bundle']['ventas'];
        if ($tx_stg_gerencial) {
            $arpu_labels = $meses_labels;
            $arpu_data = array_values($resultadoDiario['arpu']);
            $tx_stg_universo_mes = array_sum($resultadoDiario['totales']['instalaciones']);
            $tx_stg_mix_2p = array_sum($resultadoDiario['datos']['play']['instalaciones']['2P']);
            $tx_stg_mix_3p = array_sum($resultadoDiario['datos']['play']['instalaciones']['3P']);
            $tx_stg_mix_sin_clasificar = array_sum($resultadoDiario['datos']['play']['instalaciones']['Sin clasificar']);
            $tx_stg_auditoria_arpu = [
                'validos'=>array_sum($resultadoDiario['arpu_cuentas']),
                'catalogo_actual'=>0
            ];
            if ($tx_stg_universo_mes !== ($tx_stg_mix_2p + $tx_stg_mix_3p + $tx_stg_mix_sin_clasificar)) {
                throw new LogicException('El Mix diario no concilia con instalaciones del intervalo');
            }
            if (!$scope_activo && $rol === 'admin') {
                $totalDiasInst = array_sum($resultadoDiario['totales']['instalaciones']);
                $totalDiasVent = array_sum($resultadoDiario['totales']['ventas']);
                if ($totalDiasInst !== (int)$kpi_inst || $totalDiasVent !== (int)$kpi_vent) {
                    throw new LogicException('El rango diario no concilia con KPI: instalaciones o ventas');
                }
            }
            $tx_stg_ok = true;
            $tx_stg_error = null;
        } elseif ($tx_stg_aplicable && $tx_stg_hc_ok) {
            // ARPU de la línea: usar SOLO cuentas certificadas por HC, no todos los folios.
            if (!method_exists($motorDiario,'indicadoresDiariosHc')) {
                throw new RuntimeException('Actualizar Motor Comercial STAGING: falta ARPU diario HC');
            }
            $eventosDiariosHC=[];
            foreach (($tx_stg_por_mes??[]) as $eventosMes) foreach ($eventosMes as $evento) {
                $f=substr((string)($evento['fecha']??''),0,10);
                if ($f>=$fecha_inicio_dashboard && $f<=$fecha_fin_dashboard) $eventosDiariosHC[]=$evento;
            }
            $indicadoresHC=$motorDiario->indicadoresDiariosHc($eventosDiariosHC,$fecha_inicio_dashboard,$fecha_fin_dashboard);
            $arpu_labels=$meses_labels;
            $arpu_data=[];
            foreach ($tx_evo_daily_dates as $dia) $arpu_data[]=$indicadoresHC[$dia]['arpu']??null;
            $tx_stg_ok=true; $tx_stg_error=null;
        }
        foreach (['ventas','instalaciones'] as $fuente) foreach (['canal','oferta','play','bundle'] as $dim) {
            foreach ($tx_evo_daily_dates as $dia) {
                $sum=0;
                foreach ($resultadoDiario['datos'][$dim][$fuente] as $series) $sum+=(int)($series[$dia]??0);
                if ($sum !== (int)$resultadoDiario['totales'][$fuente][$dia]) {
                    throw new LogicException('Evolución diaria no concilia: '.$dim.' '.$fuente.' '.$dia);
                }
            }
        }
    } catch (Throwable $errorDiario) {
        $tx_evo_fuente_catalogo_ok=false;
        $tx_evo_catalogo_error=$errorDiario->getMessage();
        error_log('TalIA INDEX STAGING diaria: '.$tx_evo_catalogo_error);
        $datos_inst_stacked=$datos_vent_stacked=[];
        $datos_inst_oferta_stacked=$datos_vent_oferta_stacked=[];
        $datos_inst_play_stacked=$datos_vent_play_stacked=[];
        $datos_inst_bundle_stacked=$datos_vent_bundle_stacked=[];
        $arpu_labels=$meses_labels;
        $arpu_data=array_fill(0,count($meses_labels),null);
        if ($tx_stg_aplicable) { $tx_stg_ok=false; $tx_stg_error=$tx_evo_catalogo_error; }
    }
}

// El MIX circular se inicializó antes de cargar las series semanales. Sincronizar
// sus variables finales con el Motor Comercial para evitar un donut en cero.
if ((($periodo === 'semanal' && $tx_evo_week_keys) || $tx_evo_daily_custom) && $tx_stg_aplicable && $tx_stg_ok && $tx_stg_gerencial) {
    $inst_2p = (int)$tx_stg_mix_2p;
    $inst_3p = (int)$tx_stg_mix_3p;
}
// La vista vendedor conserva acceso limitado; las 4 dimensiones siguen el motor único.
// Los títulos se ajustan para que la caché JS no restituya textos mensuales.
$tx_evo_periodo_descripcion = $tx_evo_daily_custom ? 'Rango diario personalizado' : (($periodo === 'semanal' && $tx_evo_week_keys)
    ? 'Semanal ISO · desde S01 de 2026' : 'Desde enero 2026');

// Guardar las cuatro vistas completas para navegación instantánea en cliente.
// Canal conserva su fuente original; Oferta/Plays/Bundle provienen del Motor Comercial.
$tx_evo_cache_views = [
    'canal' => [
        'inst' => $datos_inst_stacked,
        'vent' => $datos_vent_stacked,
        'titulo' => $tx_evo_desde_enero ? 'Evolución — Desde enero 2026 por canal' : 'Evolución — Últimos 6 meses por canal',
        'participacion_titulo' => $tx_evo_desde_enero ? 'Participación por canal — Desde enero 2026 (%)' : 'Participación por canal — Últimos 6 meses (%)',
        'vent_sub' => 'Ventas por canal',
        'inst_sub' => 'Instalaciones por origen',
        'col_label' => 'Canal',
        'catalogo' => false,
    ],
    'oferta' => [
        'inst' => $tx_evo_desde_enero && !$tx_evo_fuente_catalogo_ok ? [] : $datos_inst_oferta_stacked,
        'vent' => $tx_evo_desde_enero && !$tx_evo_fuente_catalogo_ok ? [] : $datos_vent_oferta_stacked,
        'titulo' => 'Evolución — Desde enero 2026 por oferta RES-NEG',
        'participacion_titulo' => 'Participación por oferta RES-NEG — Desde enero 2026 (%)',
        'vent_sub' => 'Ventas por oferta RES-NEG',
        'inst_sub' => 'Instalaciones por oferta RES-NEG',
        'col_label' => 'Oferta',
        'catalogo' => true,
    ],
    'play' => [
        'inst' => $tx_evo_fuente_catalogo_ok ? $datos_inst_play_stacked : [],
        'vent' => $tx_evo_fuente_catalogo_ok ? $datos_vent_play_stacked : [],
        'titulo' => 'Evolución — Desde enero 2026 por Plays',
        'participacion_titulo' => 'Participación por Plays — Desde enero 2026 (%)',
        'vent_sub' => 'Ventas por Plays',
        'inst_sub' => 'Instalaciones por Plays',
        'col_label' => 'Play',
        'catalogo' => true,
    ],
    'bundle' => [
        'inst' => $tx_evo_fuente_catalogo_ok ? $datos_inst_bundle_stacked : [],
        'vent' => $tx_evo_fuente_catalogo_ok ? $datos_vent_bundle_stacked : [],
        'titulo' => 'Evolución — Desde enero 2026 por Bundle',
        'participacion_titulo' => 'Participación por Bundle — Desde enero 2026 (%)',
        'vent_sub' => 'Ventas por Bundle',
        'inst_sub' => 'Instalaciones por Bundle',
        'col_label' => 'Bundle',
        'catalogo' => true,
    ],
];

if ($tx_evo_daily_custom) {
    $desdeTitulo = (new DateTimeImmutable($fecha_inicio_dashboard))->format('d/m/Y');
    $hastaTitulo = (new DateTimeImmutable($fecha_fin_dashboard))->format('d/m/Y');
    foreach ($tx_evo_cache_views as $key=>&$cfg) {
        $dimension = match($key) {'oferta'=>'Oferta RES-NEG','play'=>'Plays','bundle'=>'Bundle',default=>'Canal de venta'};
        $cfg['titulo'] = 'Evolución diaria · '.$dimension.' · '.$desdeTitulo.' – '.$hastaTitulo;
        $cfg['participacion_titulo'] = 'Participación diaria por '.$dimension.' (%) · '.$desdeTitulo.' – '.$hastaTitulo;
    }
    unset($cfg);
} elseif ($periodo === 'semanal' && $tx_evo_week_keys) {
    foreach ($tx_evo_cache_views as $key => &$cfg) {
        $label = match($key) {
            'oferta'=>'Oferta RES-NEG', 'play'=>'Plays', 'bundle'=>'Bundle', default=>'Canal de venta'
        };
        $cfg['titulo']='Evolución semanal ISO — '.$label;
        $cfg['participacion_titulo']='Participación semanal ISO por '.$label.' (%)';
    }
    unset($cfg);
}
if ($evo_view==='oferta') {
    $datos_inst_stacked=$tx_evo_cache_views['oferta']['inst'];
    $datos_vent_stacked=$tx_evo_cache_views['oferta']['vent'];
} elseif ($evo_view==='play') {
    $datos_inst_stacked=$tx_evo_cache_views['play']['inst'];
    $datos_vent_stacked=$tx_evo_cache_views['play']['vent'];
} elseif ($evo_view==='bundle') {
    $datos_inst_stacked=$tx_evo_cache_views['bundle']['inst'];
    $datos_vent_stacked=$tx_evo_cache_views['bundle']['vent'];
}
$colores_palette = ['#FF006C', '#7A2BFF', '#00A6FF', '#00E5FF', '#FF6500', '#7CFF00', '#2C2F3A'];

// ── TABLA DE PARTICIPACIÓN POR CANAL ─────────────────────────────────────────
// Calcular totales por mes para instalaciones
$totales_inst_mes = array_fill(0, $tx_evo_nmeses, 0);
foreach ($datos_inst_stacked as $canal => $vals) {
    foreach ($vals as $i => $v) $totales_inst_mes[$i] += $v;
}
// Calcular totales por mes para ventas
$totales_vent_mes = array_fill(0, $tx_evo_nmeses, 0);
foreach ($datos_vent_stacked as $canal => $vals) {
    foreach ($vals as $i => $v) $totales_vent_mes[$i] += $v;
}

// Ordenamiento de tablas de participación.
// Vista inicial: mes en curso (última columna) de mayor a menor.
// Cada encabezado de mes permite alternar mayor-menor / menor-mayor.
$sort_vent_idx = isset($_GET['sort_vent_mes']) ? max(0, min($tx_evo_nmeses - 1, (int)$_GET['sort_vent_mes'])) : ($tx_evo_nmeses - 1);
$sort_vent_dir = (isset($_GET['sort_vent_dir']) && $_GET['sort_vent_dir'] === 'asc') ? 'asc' : 'desc';

$sort_inst_idx = isset($_GET['sort_inst_mes']) ? max(0, min($tx_evo_nmeses - 1, (int)$_GET['sort_inst_mes'])) : ($tx_evo_nmeses - 1);
$sort_inst_dir = (isset($_GET['sort_inst_dir']) && $_GET['sort_inst_dir'] === 'asc') ? 'asc' : 'desc';

$datos_vent_table = $datos_vent_stacked;
uasort($datos_vent_table, function($a, $b) use ($sort_vent_idx, $sort_vent_dir) {
    $av = (float)($a[$sort_vent_idx] ?? 0);
    $bv = (float)($b[$sort_vent_idx] ?? 0);
    if ($av == $bv) return 0;
    return ($sort_vent_dir === 'asc') ? ($av <=> $bv) : ($bv <=> $av);
});

$datos_inst_table = $datos_inst_stacked;
uasort($datos_inst_table, function($a, $b) use ($sort_inst_idx, $sort_inst_dir) {
    $av = (float)($a[$sort_inst_idx] ?? 0);
    $bv = (float)($b[$sort_inst_idx] ?? 0);
    if ($av == $bv) return 0;
    return ($sort_inst_dir === 'asc') ? ($av <=> $bv) : ($bv <=> $av);
});

function dashboard_sort_url($sort_key, $dir_key, $idx, $current_idx, $current_dir) {
    $qs = $_GET;
    $qs[$sort_key] = $idx;
    $qs[$dir_key] = ((int)$current_idx === (int)$idx && $current_dir === 'desc') ? 'asc' : 'desc';
    return '?' . htmlspecialchars(http_build_query($qs), ENT_QUOTES, 'UTF-8');
}

function dashboard_sort_arrow($idx, $current_idx, $current_dir) {
    if ((int)$idx !== (int)$current_idx) return '⇅';
    return $current_dir === 'desc' ? '↓' : '↑';
}



/*
|--------------------------------------------------------------------------
| TOTALXPEDIENT - Parche HIC-Fallback v1.0 global + TOP/BOTTOM
|--------------------------------------------------------------------------
|
| Alcance:
|   - Global para KPIs, scope jerárquico, cumplimiento inferior, evolución, ARPU, mix y georreferencia.
|   - TOP/BOTTOM ya usan el mismo helper HIC, conservando HC vigente como denominador.
|
| Objetivo:
|   Unificar ventas/instalaciones históricas cuando un colaborador cambió
|   de número de talento o se conserva compatibilidad por id_posicion.
|
| Diseño defensivo:
|   El parche detecta columnas disponibles en historial_identidad_colaborador.
|   Si la tabla no existe o no contiene datos compatibles, regresa el folio
|   original y el dashboard sigue funcionando igual que antes.
|--------------------------------------------------------------------------
*/
function tx_hic_cols_dashboard($conexion) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = ['existe'=>false, 'folio_cols'=>[], 'pos_cols'=>[]];
    if (!tx_tabla_existe_dashboard($conexion, 'historial_identidad_colaborador')) {
        return $cache;
    }

    $r = mysqli_query($conexion, "SHOW COLUMNS FROM historial_identidad_colaborador");
    if (!$r) return $cache;

    $cache['existe'] = true;
    while ($row = mysqli_fetch_assoc($r)) {
        $field = (string)($row['Field'] ?? '');
        $f = strtolower($field);

        // Columnas de número de talento / folio.
        if (
            strpos($f, 'talento') !== false ||
            strpos($f, 'folio') !== false ||
            $f === 'numero_empleado' ||
            $f === 'num_empleado'
        ) {
            $cache['folio_cols'][] = $field;
        }

        // Columnas de posición.
        if (strpos($f, 'posicion') !== false || strpos($f, 'posición') !== false) {
            $cache['pos_cols'][] = $field;
        }
    }

    $cache['folio_cols'] = array_values(array_unique($cache['folio_cols']));
    $cache['pos_cols'] = array_values(array_unique($cache['pos_cols']));
    return $cache;
}

function tx_hic_clean_folio_dashboard($folio) {
    $folio = trim((string)$folio);
    if ($folio === '') return '';
    if (stripos($folio, 'VACANTE') !== false) return '';
    return $folio;
}

function tx_hic_aliases_por_identidad_dashboard($conexion, $identidades) {
    /*
     * Entrada:
     *   [ key => ['folio'=>'...', 'id_posicion'=>'...'] ]
     * Salida:
     *   [ key => ['folio_actual', 'folio_historico_1', ...] ]
     */
    $out = [];
    foreach ($identidades as $key => $id) {
        $folio = tx_hic_clean_folio_dashboard($id['folio'] ?? '');
        $out[$key] = $folio !== '' ? [$folio] : [];
    }

    $cfg = tx_hic_cols_dashboard($conexion);
    if (!$cfg['existe'] || (empty($cfg['folio_cols']) && empty($cfg['pos_cols']))) {
        return $out;
    }

    foreach ($identidades as $key => $id) {
        $folio = tx_hic_clean_folio_dashboard($id['folio'] ?? '');
        $pos = trim((string)($id['id_posicion'] ?? ''));
        $where = [];

        if ($folio !== '' && !empty($cfg['folio_cols'])) {
            $folio_esc = mysqli_real_escape_string($conexion, $folio);
            foreach ($cfg['folio_cols'] as $col) {
                $col_sql = "`" . str_replace("`", "", $col) . "`";
                $where[] = "UPPER(TRIM(COALESCE($col_sql,''))) = UPPER('$folio_esc')";
            }
        }

        if ($pos !== '' && !empty($cfg['pos_cols'])) {
            $pos_esc = mysqli_real_escape_string($conexion, $pos);
            foreach ($cfg['pos_cols'] as $col) {
                $col_sql = "`" . str_replace("`", "", $col) . "`";
                $where[] = "UPPER(TRIM(COALESCE($col_sql,''))) = UPPER('$pos_esc')";
            }
        }

        if (empty($where)) continue;

        $cols = array_values(array_unique(array_merge($cfg['folio_cols'], $cfg['pos_cols'])));
        $select_cols = implode(', ', array_map(function($c) {
            return "`" . str_replace("`", "", $c) . "`";
        }, $cols));

        $sql = "SELECT $select_cols FROM historial_identidad_colaborador WHERE " . implode(' OR ', $where);
        $r = mysqli_query($conexion, $sql);
        while ($r && $row = mysqli_fetch_assoc($r)) {
            foreach ($cfg['folio_cols'] as $col) {
                $alias = tx_hic_clean_folio_dashboard($row[$col] ?? '');
                if ($alias !== '') $out[$key][] = $alias;
            }
        }

        $out[$key] = array_values(array_unique(array_filter($out[$key], function($v) {
            return tx_hic_clean_folio_dashboard($v) !== '';
        })));
    }

    return $out;
}

function tx_hic_aliases_folios_dashboard($conexion, $folios, $posiciones = []) {
    $identidades = [];
    foreach (array_values($folios) as $i => $folio) {
        $identidades[$i] = [
            'folio' => $folio,
            'id_posicion' => $posiciones[$i] ?? ''
        ];
    }
    $aliases = tx_hic_aliases_por_identidad_dashboard($conexion, $identidades);
    $out = [];
    foreach ($aliases as $arr) {
        foreach ($arr as $folio_alias) {
            $folio_alias = tx_hic_clean_folio_dashboard($folio_alias);
            if ($folio_alias !== '') $out[] = $folio_alias;
        }
    }
    return array_values(array_unique($out));
}

function tx_hic_aliases_posiciones_dashboard($conexion, $posiciones) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - HIC-Fallback de posiciones
    |--------------------------------------------------------------------------
    | Devuelve id_posicion anterior/nueva equivalentes desde
    | historial_identidad_colaborador. Se usa para reconstruir jerarquía por
    | posicion_lr, no para contar HC.
    |--------------------------------------------------------------------------
    */
    $out = [];
    $posiciones = array_values(array_filter(array_unique(array_map('trim', array_map('strval', $posiciones))), function($v) {
        return $v !== '';
    }));
    if (empty($posiciones)) return [];

    foreach ($posiciones as $p) $out[] = $p;

    $cfg = tx_hic_cols_dashboard($conexion);
    if (!$cfg['existe'] || empty($cfg['pos_cols'])) {
        return array_values(array_unique($out));
    }

    foreach ($posiciones as $pos) {
        $pos_esc = mysqli_real_escape_string($conexion, $pos);
        $where = [];
        foreach ($cfg['pos_cols'] as $col) {
            $col_sql = "`" . str_replace("`", "", $col) . "`";
            $where[] = "UPPER(TRIM(COALESCE($col_sql,''))) = UPPER('$pos_esc')";
        }
        if (empty($where)) continue;

        $select_cols = implode(', ', array_map(function($c) {
            return "`" . str_replace("`", "", $c) . "`";
        }, $cfg['pos_cols']));

        $sql = "SELECT $select_cols FROM historial_identidad_colaborador WHERE " . implode(' OR ', $where);
        $r = mysqli_query($conexion, $sql);
        while ($r && $row = mysqli_fetch_assoc($r)) {
            foreach ($cfg['pos_cols'] as $col) {
                $alias = trim((string)($row[$col] ?? ''));
                if ($alias !== '') $out[] = $alias;
            }
        }
    }

    return array_values(array_unique($out));
}


function tx_hic_folios_por_posiciones_dashboard($conexion, $posiciones_ids) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - HIC-Fallback por id_posicion
    |--------------------------------------------------------------------------
    | Recupera los folios vigentes de HC y agrega folios históricos usando
    | numero_talento_gs + id_posicion. Este punto corrige los casos donde el
    | cambio de nominera conserva la posición, pero el folio vigente no empata
    | por sí solo contra instalaciones históricas.
    |
    | Importante:
    |   Sólo se usa para sumar ventas/instalaciones por folio.
    |   El HC activo se sigue calculando por id_posicion vigente.
    |--------------------------------------------------------------------------
    */
    $folios = [];
    if (empty($posiciones_ids)) return $folios;

    // HIC-Fallback v1.0: ampliar posiciones antes de leer HC.
    // Si un vendedor aún reporta a una posición histórica del Coach, se incluye.
    $posiciones_ids = tx_hic_aliases_posiciones_dashboard($conexion, $posiciones_ids);

    $ids_sql = tx_sql_in_escaped($conexion, $posiciones_ids);
    $sql = "
        SELECT DISTINCT
            numero_talento_gs AS folio,
            id_posicion
        FROM hc
        WHERE id_posicion IN ($ids_sql)
          AND numero_talento_gs IS NOT NULL
          AND numero_talento_gs <> ''
          AND numero_talento_gs NOT LIKE '%VACANTE%'
    ";
    $res = mysqli_query($conexion, $sql);

    $identidades = [];
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $folio = tx_hic_clean_folio_dashboard($row['folio'] ?? '');
        $pos = trim((string)($row['id_posicion'] ?? ''));
        if ($folio === '' && $pos === '') continue;

        $key = $pos !== '' ? $pos . '|' . $folio : $folio;
        $identidades[$key] = [
            'folio' => $folio,
            'id_posicion' => $pos
        ];
    }

    if (empty($identidades)) return [];

    $aliases = tx_hic_aliases_por_identidad_dashboard($conexion, $identidades);
    foreach ($aliases as $arr) {
        foreach ($arr as $folio_alias) {
            $folio_alias = tx_hic_clean_folio_dashboard($folio_alias);
            if ($folio_alias !== '') $folios[] = $folio_alias;
        }
    }

    return array_values(array_unique($folios));
}



// ── TOP REGIONAL PRODUCTIVIDAD VENDEDORES ───────────────────────────────────
// Estas tablas son regionales y se muestran a todos los niveles para incentivar
// mejora y permanencia. No dependen del scope jerárquico del tablero actual.
function tx_get_vendedores_regional_dashboard($conexion, $semana, $anio, $puestos_comerciales) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - TOP REGIONAL PRODUCTIVIDAD
    |--------------------------------------------------------------------------
    |
    | Regla de visualización:
    |   Este ranking se muestra a todos los niveles como referencia regional.
    |
    | Fuente HC:
    |   Se toma la plantilla comercial activa de la última semana cargada en HC.
    |
    | Jerarquía:
    |   Vendedor -> Coach -> Líder -> Director Distrital.
    |
    | Se resuelve con self-joins sobre hc usando:
    |   vendedor.posicion_lr = coach.id_posicion
    |   coach.posicion_lr    = lider.id_posicion
    |   lider.posicion_lr    = director.id_posicion
    |
    | Fecha documentación: Junio 2026
    | Proyecto: TotalXpedient Dashboard Ejecutivo
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT DISTINCT
            h.id_posicion AS id_posicion,
            h.numero_talento_gs AS folio,
            h.nombre_colaborador AS vendedor,
            h.distrito,
            COALESCE(
                NULLIF(TRIM(c.nombre_colaborador), ''),
                NULLIF(TRIM(c_nombre.nombre_colaborador), ''),
                NULLIF(TRIM(h.nombre_linea_reporte), ''),
                ''
            ) AS coach,
            COALESCE(
                NULLIF(TRIM(l.nombre_colaborador), ''),
                NULLIF(TRIM(l_nombre.nombre_colaborador), ''),
                ''
            ) AS lider,
            COALESCE(d.nombre_colaborador, '') AS director
        FROM hc h
        /*
         * FIX Coach vendedor:
         * 1) Match principal por posicion_lr -> id_posicion.
         * 2) Fallback por nombre_linea_reporte dentro de la misma semana/distrito.
         * Esto cubre vendedores cuya posicion_lr quedó desfasada tras una migración,
         * pero conservan correctamente el nombre del Coach en HC.
         */
        LEFT JOIN hc c
            ON c.id_posicion = h.posicion_lr
           AND c.semana = h.semana
           AND c.anio = h.anio
        LEFT JOIN hc c_nombre
            ON UPPER(TRIM(c_nombre.nombre_colaborador)) = UPPER(TRIM(h.nombre_linea_reporte))
           AND c_nombre.distrito = h.distrito
           AND c_nombre.semana = h.semana
           AND c_nombre.anio = h.anio
           AND c_nombre.numero_talento_gs NOT LIKE '%VACANTE%'
           AND UPPER(TRIM(COALESCE(c_nombre.nombre_colaborador,''))) <> 'VACANTE'
        LEFT JOIN hc l
            ON l.id_posicion = COALESCE(NULLIF(c.posicion_lr,''), NULLIF(c_nombre.posicion_lr,''))
           AND l.semana = h.semana
           AND l.anio = h.anio
        LEFT JOIN hc l_nombre
            ON UPPER(TRIM(l_nombre.nombre_colaborador)) = UPPER(TRIM(COALESCE(c.nombre_linea_reporte, c_nombre.nombre_linea_reporte)))
           AND l_nombre.distrito = h.distrito
           AND l_nombre.semana = h.semana
           AND l_nombre.anio = h.anio
           AND l_nombre.numero_talento_gs NOT LIKE '%VACANTE%'
           AND UPPER(TRIM(COALESCE(l_nombre.nombre_colaborador,''))) <> 'VACANTE'
        LEFT JOIN hc d
            ON d.id_posicion = l.posicion_lr
           AND d.semana = h.semana
           AND d.anio = h.anio
        WHERE h.semana=".(int)$semana."
          AND h.anio=".(int)$anio."
          AND h.numero_talento_gs NOT LIKE '%VACANTE%'
          AND h.numero_talento_gs <> ''
          AND UPPER(TRIM(COALESCE(h.nombre_colaborador,''))) <> 'VACANTE'
          AND h.posicion IN ($puestos_comerciales)
        ORDER BY h.distrito, h.nombre_colaborador
    ";

    $res = mysqli_query($conexion, $sql);
    $out = [];
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $folio = trim((string)($row['folio'] ?? ''));
        if ($folio === '') continue;

        $out[$folio] = [
            'folio' => $folio,
            'id_posicion' => $row['id_posicion'] ?? '',
            'folios' => [$folio],
            'vendedor' => $row['vendedor'] ?? '',
            'distrito' => $row['distrito'] ?? '',
            'coach' => $row['coach'] ?? '',
            'instalaciones' => 0,
            'arpu' => 0,
            'productividad' => 0,
            'prod3m' => 0,
            'spark' => []
        ];
    }

    // Parche HIC-Fallback v1.0: agrega folios históricos equivalentes a cada vendedor.
    $identidades_hic = [];
    foreach ($out as $key => $v) {
        $identidades_hic[$key] = [
            'folio' => $v['folio'] ?? '',
            'id_posicion' => $v['id_posicion'] ?? ''
        ];
    }
    $aliases_hic = tx_hic_aliases_por_identidad_dashboard($conexion, $identidades_hic);
    foreach ($aliases_hic as $key => $aliases) {
        if (isset($out[$key])) {
            $out[$key]['folios'] = !empty($aliases) ? $aliases : [$out[$key]['folio']];
        }
    }

    return $out;
}

function tx_sparkline_vendedor_dashboard($conexion, $folio, $fecha_corte_timestamp) {
    // Mini tendencia: instalaciones de las últimas 6 semanas calendario.
    // Parche HIC-Fallback v1.0: $folio puede ser folio único o arreglo de folios equivalentes.
    $out = array_fill(0, 6, 0);
    $fecha_fin = date('Y-m-d', $fecha_corte_timestamp);
    $fecha_ini = date('Y-m-d', strtotime('-5 weeks', strtotime('monday this week', $fecha_corte_timestamp)));
    $folios = is_array($folio) ? $folio : [$folio];
    $folios = array_values(array_filter(array_unique($folios), function($f) {
        return tx_hic_clean_folio_dashboard($f) !== '';
    }));
    if (empty($folios)) return $out;
    $folios_sql = tx_sql_in_escaped($conexion, $folios);

    $sql = "
        SELECT YEARWEEK(fecha, 3) AS yw, COUNT(cuenta) AS total
        FROM instalaciones
        WHERE fecha BETWEEN '$fecha_ini' AND '$fecha_fin'
          AND folio_empleado IN ($folios_sql)
          AND origen_prospecto <> '-'
        GROUP BY YEARWEEK(fecha, 3)
        ORDER BY yw
    ";
    $res = mysqli_query($conexion, $sql);

    $keys = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime('-'.$i.' weeks', strtotime('monday this week', $fecha_corte_timestamp));
        $keys[] = date('oW', $ts);
    }
    $map = array_fill_keys($keys, 0);

    while ($res && $row = mysqli_fetch_assoc($res)) {
        $yw = (string)($row['yw'] ?? '');
        if (isset($map[$yw])) $map[$yw] = (int)($row['total'] ?? 0);
    }

    return array_values($map);
}

function tx_dias_habiles_ultimos_3_meses_dashboard($conexion, $fecha_corte_timestamp) {
    $dias = 0;
    for ($i = 3; $i >= 1; $i--) {
        $ts = strtotime("first day of -$i month", $fecha_corte_timestamp);
        $anio = (int)date('Y', $ts);
        $mes = (int)date('n', $ts);
        $ultimo = (int)date('t', $ts);
        $dias += tx_dias_habiles_rango_mes($conexion, $anio, $mes, 1, $ultimo);
    }
    return max(1, (int)$dias);
}

function tx_build_top_regional_productividad_dashboard($conexion, $vendedores, $mes, $anio, $cond_dia_fecha, $dias_productividad, $fecha_corte_timestamp) {
    if (empty($vendedores)) return [[], []];

    // Parche HIC-Fallback v1.0: cada vendedor puede tener folios históricos equivalentes.
    $folio_to_key = [];
    $folios = [];
    foreach ($vendedores as $key => $v) {
        $aliases = $v['folios'] ?? [$key];
        foreach ($aliases as $alias) {
            $alias = tx_hic_clean_folio_dashboard($alias);
            if ($alias === '') continue;
            $folios[] = $alias;
            if (!isset($folio_to_key[$alias])) $folio_to_key[$alias] = $key;
        }
    }
    $folios = array_values(array_unique($folios));
    if (empty($folios)) return [[], []];
    $folios_sql = tx_sql_in_escaped($conexion, $folios);

    // Desempeño del rango seleccionado.
    $sql = "
        SELECT
            folio_empleado AS folio,
            COUNT(cuenta) AS instalaciones,
            COALESCE(SUM(precio_pronto_pago),0) AS ingreso,
            SUM(CASE WHEN precio_pronto_pago > 0 THEN 1 ELSE 0 END) AS inst_arpu
        FROM instalaciones
        WHERE " . tx_dashboard_where_fecha('fecha') . "
          AND origen_prospecto <> '-'
          AND folio_empleado IN ($folios_sql)
        GROUP BY folio_empleado
    ";
    $res = mysqli_query($conexion, $sql);
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $folio = tx_hic_clean_folio_dashboard($row['folio'] ?? '');
        $key = $folio_to_key[$folio] ?? '';
        if ($key === '' || !isset($vendedores[$key])) continue;

        $inst = (int)($row['instalaciones'] ?? 0);
        $ingreso = (float)($row['ingreso'] ?? 0);
        $inst_arpu = (int)($row['inst_arpu'] ?? 0);

        $vendedores[$key]['instalaciones'] += $inst;
        $vendedores[$key]['ingreso_hic'] = (float)($vendedores[$key]['ingreso_hic'] ?? 0) + $ingreso;
        $vendedores[$key]['inst_arpu_hic'] = (int)($vendedores[$key]['inst_arpu_hic'] ?? 0) + $inst_arpu;
        $vendedores[$key]['productividad'] = $dias_productividad > 0 ? round($vendedores[$key]['instalaciones'] / $dias_productividad, 2) : 0;
        $vendedores[$key]['arpu'] = ((int)$vendedores[$key]['inst_arpu_hic'] > 0) ? round(((float)$vendedores[$key]['ingreso_hic']) / ((int)$vendedores[$key]['inst_arpu_hic']), 2) : 0;
    }

    // Productividad 3M: instalaciones de los 3 meses completos anteriores / días hábiles de esos 3 meses.
    $fecha_ini_3m = date('Y-m-01', strtotime('first day of -3 month', $fecha_corte_timestamp));
    $fecha_fin_3m = date('Y-m-t', strtotime('last day of -1 month', $fecha_corte_timestamp));
    $dias_3m = tx_dias_habiles_ultimos_3_meses_dashboard($conexion, $fecha_corte_timestamp);

    $sql3 = "
        SELECT
            folio_empleado AS folio,
            COUNT(cuenta) AS instalaciones_3m
        FROM instalaciones
        WHERE fecha BETWEEN '$fecha_ini_3m' AND '$fecha_fin_3m'
          AND origen_prospecto <> '-'
          AND folio_empleado IN ($folios_sql)
        GROUP BY folio_empleado
    ";
    $res3 = mysqli_query($conexion, $sql3);
    while ($res3 && $row = mysqli_fetch_assoc($res3)) {
        $folio = tx_hic_clean_folio_dashboard($row['folio'] ?? '');
        $key = $folio_to_key[$folio] ?? '';
        if ($key === '' || !isset($vendedores[$key])) continue;

        $inst3m = (int)($row['instalaciones_3m'] ?? 0);
        $vendedores[$key]['inst3m_hic'] = (int)($vendedores[$key]['inst3m_hic'] ?? 0) + $inst3m;
        $vendedores[$key]['prod3m'] = $dias_3m > 0 ? round(((int)$vendedores[$key]['inst3m_hic']) / $dias_3m, 2) : 0;
    }

    foreach ($vendedores as $folio => &$v) {
        $v['spark'] = tx_sparkline_vendedor_dashboard($conexion, $v['folios'] ?? [$folio], $fecha_corte_timestamp);
    }
    unset($v);

    $lista = array_values(array_filter($vendedores, function($r) {
        return strtoupper(trim((string)($r['vendedor'] ?? ''))) !== 'VACANTE';
    }));

    $top = $lista;
    usort($top, function($a, $b) {
        if ($a['productividad'] == $b['productividad']) return $b['instalaciones'] <=> $a['instalaciones'];
        return $b['productividad'] <=> $a['productividad'];
    });
    $top = array_slice($top, 0, 10);

    // TOP OFFENDER:
    // Prioridad: vendedores con 0 instalaciones en el rango seleccionado.
    // Entre ellos, ordenar por productividad 3M más baja para evitar sesgo alfabético por distrito.
    $off_zero = array_values(array_filter($lista, function($r) {
        return (int)($r['instalaciones'] ?? 0) === 0;
    }));
    usort($off_zero, function($a, $b) {
        if ($a['prod3m'] == $b['prod3m']) {
            return strcmp((string)$a['vendedor'], (string)$b['vendedor']);
        }
        return $a['prod3m'] <=> $b['prod3m'];
    });

    $off = array_slice($off_zero, 0, 10);

    // Si no hay suficientes ceros, completar con los de menor productividad actual.
    if (count($off) < 10) {
        $ya = array_column($off, 'folio');
        $resto = array_values(array_filter($lista, function($r) use ($ya) {
            return !in_array($r['folio'], $ya, true);
        }));
        usort($resto, function($a, $b) {
            if ($a['productividad'] == $b['productividad']) return $a['prod3m'] <=> $b['prod3m'];
            return $a['productividad'] <=> $b['productividad'];
        });
        $off = array_merge($off, array_slice($resto, 0, 10 - count($off)));
    }

    return [$top, $off];
}


// ── TOP REGIONAL PRODUCTIVIDAD COACHES ──────────────────────────────────────
// Se muestra antes del Top de vendedores y es regional para todos los niveles.
// Productividad Coach = instalaciones / HC activo a cargo / días hábiles del rango seleccionado.
// ── TOP/BOTTOM COACHES · FUENTE MOTOR HC v0.6.2 ─────────────────────────────
// La atribución de cada cuenta procede del HC histórico por semana del evento.
// NO se reconstruye el vendedor→coach con nombres de instalaciones o folios
// de la foto vigente. El HC activo se toma de la última foto <= fecha de corte.
// Así el numerador es el mismo universo certificado que Ranking Productividad.

/** Clave de nombre solo para fallback HC de líder autorreferenciado. */
function tx_coach_nombre_clave_hc_staging(string $nombre): string {
    $nombre = RankingAtribucionServiceV06::norm($nombre);
    $partes = array_values(array_filter(preg_split('/\s+/u', $nombre) ?: []));
    sort($partes, SORT_STRING);
    return implode(' ', $partes);
}

/**
 * Catálogo regional de coaches y HC activo de la foto vigente AL CORTE.
 * El nombre y el líder proceden exclusivamente de HC, nunca de instalaciones.
 * El HC usa personas distintas (con HIC verificable), no cantidad de aliases.
 * Cada coach queda identificado como DISTRITO_CANONICO|ID_POSICION.
 */
function tx_coaches_foto_motor_hc_staging(RankingAtribucionServiceV06 $motor, string $inicio, string $fin): array {
    $hc = $motor->cargarHc($inicio, $fin);
    $fotos = $hc['datos'] ?? [];
    if (empty($fotos)) throw new RuntimeException('TOP/BOTTOM Coaches: no existe una foto HC anterior o igual al corte');
    $claveFoto = array_key_last($fotos); // cargarHc entrega las fotos en orden cronológico.
    $filas = $fotos[$claveFoto];
    if (!is_array($filas) || !$filas) throw new RuntimeException('TOP/BOTTOM Coaches: foto HC sin registros');

    $porPosicion = [];
    foreach ($filas as $fila) {
        $distrito = RankingAtribucionServiceV06::normalizarDistrito($fila['distrito'] ?? null);
        $posicion = trim((string)($fila['id_posicion'] ?? ''));
        if ($distrito === null || $posicion === '') continue;
        $porPosicion[$distrito][$posicion][] = $fila;
    }

    // El catálogo de líderes lo certifica el Motor HC. El nombre a mostrar
    // se obtiene del ocupante de esa plaza en la fotografía de corte.
    $lideres = [];
    foreach ($motor->catalogoLideres($inicio, $fin) as $id => $info) {
        $distrito = (string)($info['distrito'] ?? '');
        $plaza = trim((string)($info['plaza'] ?? ''));
        $asientos = $porPosicion[$distrito][$plaza] ?? [];
        if (count($asientos) !== 1) continue;
        $fila = $asientos[0];
        $nombre = trim((string)($fila['nombre_colaborador'] ?? ''));
        $talento = trim((string)($fila['numero_talento_gs'] ?? ''));
        if ($nombre === '' || strtoupper($nombre) === 'VACANTE' || stripos($talento,'VACANTE') !== false) continue;
        $lideres[(string)$id] = $nombre;
    }
    $lideresPorNombre = [];
    foreach ($lideres as $id => $nombre) {
        $d = explode('|', $id, 2)[0];
        $lideresPorNombre[$d][tx_coach_nombre_clave_hc_staging($nombre)][] = $id;
    }

    $coaches = [];
    foreach ($filas as $fila) {
        // Misma señal estructural que usa Motor HC para reconocer el coach:
        // su puesto_lr apunta a un líder de ventas, por posición, no por texto.
        if (stripos((string)($fila['puesto_lr'] ?? ''), 'LIDER') === false) continue;
        $d = RankingAtribucionServiceV06::normalizarDistrito($fila['distrito'] ?? null);
        $pos = trim((string)($fila['id_posicion'] ?? ''));
        $super = trim((string)($fila['posicion_lr'] ?? ''));
        $talento = trim((string)($fila['numero_talento_gs'] ?? ''));
        $nombre = trim((string)($fila['nombre_colaborador'] ?? ''));
        if ($d === null || $pos === '' || $super === '' || $talento === ''
            || stripos($talento, 'VACANTE') !== false || $nombre === '' || strtoupper($nombre) === 'VACANTE') continue;
        if (count($porPosicion[$d][$pos] ?? []) !== 1) continue; // HC duplicado = no certificar.

        if ($super === $pos) {
            // Parche de autorreferencia: solo HC → nombre del líder único de la MISMA foto.
            $nomSuperior = tx_coach_nombre_clave_hc_staging((string)($fila['nombre_linea_reporte'] ?? ''));
            $candidatos = $lideresPorNombre[$d][$nomSuperior] ?? [];
            if ($nomSuperior === '' || count($candidatos) !== 1) continue;
            $idLider = $candidatos[0];
        } else {
            $idLider = $d.'|'.$super;
        }
        if (!isset($lideres[$idLider]) || $idLider === $d.'|'.$pos) continue;
        $clave = $d.'|'.$pos;
        $coaches[$clave] = [
            'id_posicion'=>$pos, 'coach'=>$nombre, 'distrito'=>$d,
            'lider'=>$lideres[$idLider], 'lider_id'=>$idLider,
            'hc_activo'=>0, 'instalaciones'=>0, 'productividad'=>0.0,
            'arpu'=>0.0, 'spark'=>array_fill(0, 6, 0), '_personas'=>[],
            '_ingreso_neto'=>0.0, '_arpu_cuentas'=>0,
        ];
    }

    // HIC identifica PERSONAS, no plazas. Solo equivalencias no ambiguas.
    $hic = $motor->resolverEquivalenciasHic($motor->cargarHic());
    foreach ($filas as $fila) {
        if (stripos((string)($fila['puesto_lr'] ?? ''), 'COACH') === false) continue;
        $d = RankingAtribucionServiceV06::normalizarDistrito($fila['distrito'] ?? null);
        $posCoach = trim((string)($fila['posicion_lr'] ?? ''));
        $folio = trim((string)($fila['numero_talento_gs'] ?? ''));
        $nombre = trim((string)($fila['nombre_colaborador'] ?? ''));
        if ($d === null || $posCoach === '' || $folio === '' || stripos($folio, 'VACANTE') !== false
            || $nombre === '' || strtoupper($nombre) === 'VACANTE') continue;
        $key = $d.'|'.$posCoach;
        if (!isset($coaches[$key])) continue;
        $persona = (($hic['estados'][$folio] ?? '') === 'HIC_AMBIGUA')
            ? $folio : ($hic['canonicos'][$folio] ?? $folio);
        $coaches[$key]['_personas'][(string)$persona] = true;
    }
    foreach ($coaches as $key => &$coach) {
        $coach['hc_activo'] = count($coach['_personas']);
        unset($coach['_personas']);
    }
    unset($coach);
    // Igual que el Ranking Coach, únicamente plazas con vendedores activos.
    return array_filter($coaches, static fn($c)=>(int)$c['hc_activo'] > 0);
}

/**
 * TalIA STAGING — Piso mínimo TOP Five Coaches.
 * 150 instalaciones por mes natural, prorrateadas por días CALENDARIO
 * inclusivos. Un período que cruza meses suma sus componentes, y el
 * redondeo se realiza UNA sola vez al final (hacia arriba).
 * Ejemplo: 26-30 Sep + 01-05 Oct = ceil(150*5/30 + 150*5/31) = 50.
 * Esta regla solo determina elegibilidad del TOP; NO modifica PROD ni BOTTOM.
 */
function tx_piso_volumen_top_coaches_dashboard(string $fecha_inicio, string $fecha_fin, int $piso_mensual = 150): int {
    $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha_inicio);
    $fin = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha_fin);
    if (!$inicio || !$fin || $inicio->format('Y-m-d') !== $fecha_inicio
        || $fin->format('Y-m-d') !== $fecha_fin || $inicio > $fin || $piso_mensual < 0) {
        throw new InvalidArgumentException('TOP Coaches: rango de fechas o piso mensual inválido');
    }

    $piso_prorrateado = 0.0;
    for ($mes = $inicio->modify('first day of this month'); $mes <= $fin; $mes = $mes->modify('first day of next month')) {
        $ultimo_dia = $mes->modify('last day of this month');
        $desde = $inicio > $mes ? $inicio : $mes;
        $hasta = $fin < $ultimo_dia ? $fin : $ultimo_dia;
        $dias_inclusivos = (int)$desde->diff($hasta)->days + 1;
        $dias_del_mes = (int)$mes->format('t');
        $piso_prorrateado += $piso_mensual * $dias_inclusivos / $dias_del_mes;
    }
    // Evitar que errores minúsculos de coma flotante eleven un piso entero exacto.
    return (int)ceil($piso_prorrateado - 1e-9);
}

/** Piso de 150/mes con selección discontinua semanal (un único redondeo). */
function tx_piso_volumen_top_coaches_fechas_dashboard(string $desde,string $hasta,array $diasISO): int {
    $d=new DateTimeImmutable($desde);$end=new DateTimeImmutable($hasta);$total=0.0;
    while($d<=$end){
        if(in_array((int)$d->format('N'),$diasISO,true)) $total+=150.0/(int)$d->format('t');
        $d=$d->modify('+1 day');
    }
    return (int)ceil($total-1e-9);
}

/**
 * Calcula ambos cuadros sobre EXACTAMENTE las cuentas certificadas de Motor HC.
 * TOP mantiene el piso prorrateado (150/mes); BOTTOM no aplica filtro de piso.
 * Reutiliza el cálculo HC ya disponible en INDEX si cubre la ventana necesaria.
 */
function tx_build_coaches_motor_hc_staging(
    mysqli $conexion, RankingAtribucionServiceV06 $motor,
    string $desde, string $hasta, int $diasHabiles,
    ?array $respuestaAnterior = null, ?string $respuestaDesde = null, array $diasIso = []
): array {
    $coaches = tx_coaches_foto_motor_hc_staging($motor, $desde, $hasta);
    $fechaCorte = new DateTimeImmutable($hasta);
    $lunesActual = $fechaCorte->modify('monday this week');
    $inicioSpark = $lunesActual->modify('-5 weeks')->format('Y-m-d');
    $mesesVerificados = false;
    if ($respuestaAnterior !== null && $respuestaDesde !== null && $respuestaDesde <= $inicioSpark
        && !empty($respuestaAnterior['conciliacion_comercial']['verificada'])) {
        $respuesta = $respuestaAnterior;
        $mesesVerificados = true;
    } else {
        $fechaInicioMotor = min($desde, $inicioSpark);
        $respuesta = $motor->obtenerInstalacionesJerarquia($fechaInicioMotor, $hasta);
    }
    if (empty($respuesta['conciliacion_comercial']['verificada'])) {
        throw new RuntimeException('TOP/BOTTOM Coaches: conciliación Motor HC no verificada');
    }

    $clavesSemana = [];
    for ($i = 5; $i >= 0; --$i) {
        $fecha = $lunesActual->modify('-'.$i.' weeks');
        $clavesSemana[$fecha->format('oW')] = count($clavesSemana);
    }

    $eventosPeriodo = []; $claveGrupoCoach = []; $sinPlaza = 0;
    foreach (($respuesta['cuentas'] ?? []) as $evento) {
        $fecha = substr((string)($evento['fecha'] ?? ''), 0, 10);
        if ($fecha < $inicioSpark || $fecha > $hasta) {
            if ($fecha < $desde || $fecha > $hasta) continue;
        }
        $lider = (string)($evento['lider_id'] ?? '');
        $idCoach = trim((string)($evento['coach_id'] ?? ''));
        if ($lider === '' || $idCoach === '') continue;
        $d = explode('|', $lider, 2)[0];
        $clave = $d.'|'.$idCoach;
        if (!isset($coaches[$clave])) { if ($fecha >= $desde && $fecha <= $hasta) ++$sinPlaza; continue; }
        $fechaISO = new DateTimeImmutable($fecha);
        $semana = $fechaISO->format('oW');
        if (isset($clavesSemana[$semana])) {
            $coaches[$clave]['spark'][$clavesSemana[$semana]]++;
        }
        if ($fecha >= $desde && $fecha <= $hasta && (!$diasIso || in_array((int)$fechaISO->format('N'), $diasIso, true))) {
            $coaches[$clave]['instalaciones']++;
            $eventosPeriodo[] = $evento;
            $grupo = $lider.'|'.$idCoach.'|'.(string)($evento['vendedor_id'] ?? '');
            if (isset($claveGrupoCoach[$grupo]) && $claveGrupoCoach[$grupo] !== $clave) {
                throw new LogicException('Motor HC: agrupación comercial de Coach inconsistente');
            }
            $claveGrupoCoach[$grupo] = $clave;
        }
    }

    // Precio lista sin IVA del Motor Comercial, sobre LAS MISMAS cuentas HC.
    if ($eventosPeriodo) {
        require_once __DIR__.'/includes/motor_comercial_STAGING.php';
        if (!class_exists('MotorComercial')) throw new RuntimeException('Motor Comercial STAGING no disponible para ARPU Coach');
        $motorComercial = new MotorComercial($conexion);
        $detalles = $motorComercial->resumirVendedores($eventosPeriodo, $desde, $hasta);
        $acumuladas = 0;
        foreach ($detalles as $grupo => $detalle) {
            $clave = $claveGrupoCoach[$grupo] ?? null;
            if ($clave === null || !isset($coaches[$clave])) throw new LogicException('Grupo HC del Coach sin correspondencia');
            $coaches[$clave]['_ingreso_neto'] += (float)($detalle['arpu_ingreso_neto'] ?? 0);
            $coaches[$clave]['_arpu_cuentas'] += (int)($detalle['arpu_cuentas'] ?? 0);
            $acumuladas += (int)($detalle['total'] ?? 0);
        }
        if ($acumuladas !== count($eventosPeriodo)) throw new LogicException('TOP/BOTTOM Coaches: Motor Comercial no concilia con Motor HC');
    }

    foreach ($coaches as &$coach) {
        $ins = (int)$coach['instalaciones'];
        $hc = (int)$coach['hc_activo'];
        $coach['productividad'] = ($hc > 0 && $diasHabiles > 0) ? round($ins / $hc / $diasHabiles, 2) : 0.0;
        $coach['arpu'] = (int)$coach['_arpu_cuentas'] > 0
            ? round($coach['_ingreso_neto'] / $coach['_arpu_cuentas'], 2) : 0.0;
        unset($coach['_arpu_cuentas'], $coach['_ingreso_neto']);
    }
    unset($coach);

    $lista = array_values($coaches);
    $piso = $diasIso ? tx_piso_volumen_top_coaches_fechas_dashboard($desde, $hasta, $diasIso) : tx_piso_volumen_top_coaches_dashboard($desde, $hasta);
    $top = array_values(array_filter($lista, static fn($c)=>(int)$c['instalaciones'] >= $piso));
    usort($top, static function($a,$b) {
        $r = $b['productividad'] <=> $a['productividad'];
        if ($r !== 0) return $r;
        $r = $b['instalaciones'] <=> $a['instalaciones'];
        return $r !== 0 ? $r : strcmp((string)$a['coach'], (string)$b['coach']);
    });
    $bottom = $lista;
    usort($bottom, static function($a,$b) {
        $r = $a['productividad'] <=> $b['productividad'];
        if ($r !== 0) return $r;
        $r = $a['instalaciones'] <=> $b['instalaciones'];
        return $r !== 0 ? $r : strcmp((string)$a['coach'], (string)$b['coach']);
    });
    return [array_slice($top,0,5), array_slice($bottom,0,5),
        ['coaches'=>count($coaches),'certificadas'=>count($eventosPeriodo),
         'sin_coach_foto'=>$sinPlaza, 'hc_reutilizado'=>$mesesVerificados]];
}

// Productividad vendedor = instalaciones / días hábiles del rango seleccionado.
$dias_productividad_vendedor = tx_dias_habiles_rango_fechas(
    $conexion, $fecha_inicio_dashboard, $fecha_fin_dashboard
);
if ($dias_productividad_vendedor <= 0) $dias_productividad_vendedor = $dias_rango_dashboard;

// Productividad para días exactos elegidos: mismo criterio que Ranking Productividad.
if ($periodo === 'semanal') {
    $tx_habiles = [];
    foreach ($tx_fechas_seleccionadas as $f) {
        if ((int)date('N',strtotime($f)) !== 7) $tx_habiles[$f] = true;
    }
    if ($tx_habiles) {
        $fiSql=mysqli_real_escape_string($conexion,min(array_keys($tx_habiles)));
        $ffSql=mysqli_real_escape_string($conexion,max(array_keys($tx_habiles)));
        $festSql="SELECT fecha FROM dias_inhabiles WHERE activo=1 AND fecha BETWEEN '$fiSql' AND '$ffSql'";
        $festRes=mysqli_query($conexion,$festSql);
        while ($festRes && $feriado=mysqli_fetch_assoc($festRes)) unset($tx_habiles[$feriado['fecha']]);
    }
    $dias_productividad_vendedor=max(1,count($tx_habiles));
}

// TOP/BOTTOM COACHES: cuenta certificada con jerarquía por semana del evento.
// Se calcula REGIONAL para todos los perfiles, independiente del drill-down.
$tx_piso_min_top_coaches = $periodo === 'semanal'
    ? tx_piso_volumen_top_coaches_fechas_dashboard($fecha_inicio_dashboard,$fecha_fin_dashboard,$tx_dashboard_dias_iso)
    : tx_piso_volumen_top_coaches_dashboard($fecha_inicio_dashboard, $fecha_fin_dashboard);
$tx_dias_cal_top_coaches = $dias_rango_dashboard;
$top_productividad_coaches = [];
$top_offender_coaches = [];
$tx_coaches_motor_auditoria = [];
$tx_coaches_motor_error = null;
try {
    require_once __DIR__.'/includes/motor_hc.php';
    if (!class_exists('RankingAtribucionServiceV06')) {
        throw new RuntimeException('La clase Motor HC v0.6.2 no está disponible');
    }
    $tx_motor_coaches = isset($tx_stg_motor_hc) && $tx_stg_motor_hc instanceof RankingAtribucionServiceV06
        ? $tx_stg_motor_hc : new RankingAtribucionServiceV06($conexion);
    $tx_respuesta_previa_coaches = isset($tx_stg_respuesta_hc) && is_array($tx_stg_respuesta_hc)
        ? $tx_stg_respuesta_hc : null;
    list($top_productividad_coaches, $top_offender_coaches, $tx_coaches_motor_auditoria) =
        tx_build_coaches_motor_hc_staging(
            $conexion, $tx_motor_coaches,
            $fecha_inicio_dashboard, $fecha_fin_dashboard,
            $dias_productividad_vendedor,
            $tx_respuesta_previa_coaches,
            $tx_respuesta_previa_coaches !== null ? ($tx_stg_inicio_tendencia ?? null) : null,
            $tx_dashboard_dias_iso
        );
} catch (Throwable $e) {
    // Fallo explícito: NO exhibir números reconstruidos por texto/folios.
    $tx_coaches_motor_error = $e->getMessage();
    error_log('TalIA INDEX STAGING TOP/BOTTOM Coaches Motor HC: '.$tx_coaches_motor_error);
}

// TOP REGIONAL: se calcula con toda la plantilla comercial activa, sin importar el scope del tablero.
$vendedores_regional_productividad = tx_get_vendedores_regional_dashboard(
    $conexion,
    $semana_actual,
    $anio_actual,
    $puestos_comerciales
);

list($top_productividad_vendedores, $top_offender_vendedores) = tx_build_top_regional_productividad_dashboard(
    $conexion,
    $vendedores_regional_productividad,
    $mes_actual,
    $anio_query,
    $cond_dia_fecha,
    $dias_productividad_vendedor,
    $fecha_corte_timestamp
);


// Preparar lista ejecutiva para Cumplimiento del nivel inferior.
// Barra única por nivel: % cumplimiento + Real / Meta.
$cumplimiento_inferior_items = [];
foreach ($cumplimiento_inferior_labels as $idx_ci => $nombre_ci) {
    $cumplimiento_inferior_items[] = [
        'nombre' => $nombre_ci,
        'real'   => (int)($cumplimiento_inferior_real[$idx_ci] ?? 0),
        'meta'   => (int)($cumplimiento_inferior_meta[$idx_ci] ?? 0),
        'pct'    => (float)($cumplimiento_inferior_pct[$idx_ci] ?? 0),
        'visual_pct' => (float)($cumplimiento_inferior_visual_pct[$idx_ci] ?? ($cumplimiento_inferior_pct[$idx_ci] ?? 0)),
        'fuente' => $cumplimiento_inferior_fuente[$idx_ci] ?? '',
        'res_pct' => $cumplimiento_inferior_res_pct[$idx_ci] ?? null,
        'neg_pct' => $cumplimiento_inferior_neg_pct[$idx_ci] ?? null,
        'mix_total' => (int)($cumplimiento_inferior_mix_total[$idx_ci] ?? 0),
    ];
}
usort($cumplimiento_inferior_items, function($a, $b) {
    if ((float)$a['pct'] == (float)$b['pct']) {
        return ((int)$b['real']) <=> ((int)$a['real']);
    }
    return ((float)$b['pct']) <=> ((float)$a['pct']);
});

// Layout dinámico del dashboard:
 // En Admin / Regional / Director Distrital se muestra Cumplimiento por Canal y HC en el renglón superior.
 // En Líder / Coach / Vendedor no aplica Cumplimiento por Canal; por legibilidad,
 // HC regresa al renglón inferior junto a Cumplimiento del nivel inferior y Mix.
$layout_hc_top = !empty($mostrar_cumplimiento_canal);
$layout_hc_bottom = !$layout_hc_top;

$roles_labels = [
    'admin'              => 'Administrador',
    'director_regional'  => 'Director Regional',
    'director_distrital' => 'Director Distrital',
    'lider'              => 'Líder',
    'coach'              => 'Coach',
    'vendedor'           => 'Vendedor',
];

// ── GEORREFERENCIA TOTALXPEDIENT ─────────────────────────────────────────────
// Mapa de calor de instalaciones georreferenciadas.
// Respeta el mismo rango operativo, scope jerárquico y permisos del dashboard.
$tx_geo_heat_points = [];
$tx_geo_marker_points = [];
$tx_geo_total = 0;
$tx_geo_invalidas = 0;
$tx_geo_centro = [20.9674, -89.5926]; // Default Mérida / Región Sur
$tx_geo_zoom = 7;
$tx_geo_zona_caliente = 'Sin datos';
$tx_geo_zona_caliente_total = 0;
$tx_geo_distrito_top = 'Sin datos';
$tx_geo_distrito_top_total = 0;
$tx_geo_cobertura_pct = 0;
$tx_geo_inst_periodo = 0;
$tx_geo_ventas_periodo = (int)$kpi_vent;
$tx_geo_canales_disponibles = [];
$tx_geo_canales_sel = [];
$tx_geo_where_canal = "";
$tx_geo_mostrar_filtro_canales = in_array($rol, ['admin','director_regional','director_distrital'], true);


function tx_geo_instalaciones_columna($conexion, $candidatos) {
    foreach ($candidatos as $cand) {
        $cand_esc = mysqli_real_escape_string($conexion, $cand);
        $r = mysqli_query($conexion, "SHOW COLUMNS FROM instalaciones LIKE '$cand_esc'");
        if ($r && mysqli_num_rows($r) > 0) {
            return $cand;
        }
    }
    return '';
}

function tx_geo_sql_col_expr($col, $default_label) {
    if ($col === '') return "'" . str_replace("'", "''", $default_label) . "'";
    $col_sql = "`" . str_replace("`", "", $col) . "`";
    return "COALESCE(NULLIF(TRIM($col_sql),''),'" . str_replace("'", "''", $default_label) . "')";
}

function tx_geo_sql_multi_col_expr($conexion, $candidatos, $default_label, $extra_exprs = []) {
    /*
     * Devuelve el primer valor no vacío de una lista de columnas candidatas.
     * Esto corrige casos donde existe una columna genérica como vendedor,
     * pero el nombre real viene en otro campo o debe recuperarse desde HC.
     */
    $exprs = [];
    foreach ($candidatos as $cand) {
        $cand_esc = mysqli_real_escape_string($conexion, $cand);
        $r = mysqli_query($conexion, "SHOW COLUMNS FROM instalaciones LIKE '$cand_esc'");
        if ($r && mysqli_num_rows($r) > 0) {
            $col_sql = "`" . str_replace("`", "", $cand) . "`";
            $exprs[] = "NULLIF(TRIM($col_sql),'')";
        }
    }

    foreach ($extra_exprs as $expr) {
        if (trim((string)$expr) !== '') {
            $exprs[] = $expr;
        }
    }

    $default_sql = "'" . str_replace("'", "''", $default_label) . "'";
    if (empty($exprs)) return $default_sql;
    return "COALESCE(" . implode(',', $exprs) . ",$default_sql)";
}

function tx_geo_sql_precio_expr($col) {
    if ($col === '') return "0";
    $col_sql = "`" . str_replace("`", "", $col) . "`";
    return "COALESCE(CAST($col_sql AS DECIMAL(12,2)),0)";
}

$tx_geo_col_coach    = tx_geo_instalaciones_columna($conexion, ['coach','nombre_coach']);
$tx_geo_col_plan     = tx_geo_instalaciones_columna($conexion, ['plan','nombre_plan','paquete']);
$tx_geo_col_precio   = tx_geo_instalaciones_columna($conexion, ['precio_pronto_pago','precio_lista_con_descuento','precio_descuento','precio_lista','precio']);

$tx_geo_hc_vendedor_expr = "(SELECT NULLIF(TRIM(hgeo.nombre_colaborador),'') FROM hc hgeo WHERE hgeo.numero_talento_gs = folio_empleado AND hgeo.numero_talento_gs NOT LIKE '%VACANTE%' AND hgeo.semana = " . (int)$semana_actual . " AND hgeo.anio = " . (int)$anio_actual . " ORDER BY hgeo.id DESC LIMIT 1)";
$tx_geo_expr_vendedor = tx_geo_sql_multi_col_expr(
    $conexion,
    ['nombre_vendedor','vendedor','vendedor_nombre','nombre_colaborador','asesor','ejecutivo'],
    'SIN VENDEDOR',
    [$tx_geo_hc_vendedor_expr]
);
$tx_geo_expr_coach    = tx_geo_sql_multi_col_expr($conexion, ['coach','nombre_coach','coach_nombre'], 'SIN COACH');
$tx_geo_expr_precio   = tx_geo_sql_precio_expr($tx_geo_col_precio);

if ($tx_geo_col_plan !== '') {
    $tx_geo_col_plan_sql = "`" . str_replace("`", "", $tx_geo_col_plan) . "`";
    $tx_geo_expr_play = "CASE WHEN UPPER(COALESCE($tx_geo_col_plan_sql,'')) LIKE '%TV%' THEN '3P' ELSE '2P' END";
} else {
    $tx_geo_expr_play = "'SIN PLAN'";
}

$tx_geo_where_scope = "";
if ($rol_consulta === 'admin') {
    $tx_geo_where_scope = "";
} elseif ($scope_filtrar_por_distrito) {
    $tx_geo_where_scope = " AND distrito IN ($scope_distritos_sql)";
} elseif ($por_distrito) {
    $tx_geo_where_scope = " AND distrito IN ($distritos_sql)";
} else {
    if (empty($folio_ids)) {
        $tx_geo_where_scope = " AND 1=0";
    } else {
        $tx_geo_where_scope = " AND folio_empleado IN (" . tx_sql_in_escaped($conexion, $folio_ids) . ")";
    }
}

$tx_geo_where_base = "
    FROM instalaciones
    WHERE $tx_dashboard_where_inst
      AND origen_prospecto IS NOT NULL
      AND origen_prospecto <> '-'
      $tx_geo_where_scope
";

$tx_geo_where_validas = "
    AND latitud IS NOT NULL
    AND longitud IS NOT NULL
    AND TRIM(CAST(latitud AS CHAR)) <> ''
    AND TRIM(CAST(longitud AS CHAR)) <> ''
    AND CAST(latitud AS DECIMAL(10,6)) BETWEEN 14 AND 33
    AND CAST(longitud AS DECIMAL(10,6)) BETWEEN -119 AND -86
";

// Filtro interactivo por canal de venta dentro de la tarjeta de georreferencia.
// Sólo se muestra para ADMIN, DIRECTOR REGIONAL y DIRECTOR DISTRITAL.
$r_tx_geo_canales = mysqli_query($conexion, "
    SELECT DISTINCT TRIM(origen_prospecto) AS canal
    $tx_geo_where_base
      AND TRIM(COALESCE(origen_prospecto,'')) <> ''
    ORDER BY canal
");
while ($r_tx_geo_canales && $row_geo_canal = mysqli_fetch_assoc($r_tx_geo_canales)) {
    $canal = trim((string)($row_geo_canal['canal'] ?? ''));
    if ($canal !== '' && $canal !== '-') $tx_geo_canales_disponibles[] = $canal;
}
$tx_geo_canales_disponibles = array_values(array_unique($tx_geo_canales_disponibles));

$tx_geo_canales_request = $_GET['geo_canales'] ?? [];
if (!is_array($tx_geo_canales_request)) {
    $tx_geo_canales_request = [$tx_geo_canales_request];
}
foreach ($tx_geo_canales_request as $canal_req) {
    $canal_req = trim((string)$canal_req);
    if ($canal_req !== '' && in_array($canal_req, $tx_geo_canales_disponibles, true)) {
        $tx_geo_canales_sel[] = $canal_req;
    }
}
$tx_geo_canales_sel = array_values(array_unique($tx_geo_canales_sel));

if ($tx_geo_mostrar_filtro_canales && !empty($tx_geo_canales_sel)) {
    $tx_geo_where_canal = " AND origen_prospecto IN (" . tx_sql_in_escaped($conexion, $tx_geo_canales_sel) . ")";
}

// Universo de instalaciones considerado por la tarjeta, ya con filtro de canal si aplica.
$r_tx_geo_inst_periodo = mysqli_query($conexion, "
    SELECT COUNT(*) AS total
    $tx_geo_where_base
    $tx_geo_where_canal
");
if ($r_tx_geo_inst_periodo && $row_geo_inst_periodo = mysqli_fetch_assoc($r_tx_geo_inst_periodo)) {
    $tx_geo_inst_periodo = (int)($row_geo_inst_periodo['total'] ?? 0);
}

// Si la tabla ventas tiene campo de canal compatible, también se calcula ventas consideradas por canal.
// Si no existe, se conserva el universo de ventas del dashboard para no romper compatibilidad.
if ($tx_geo_mostrar_filtro_canales && !empty($tx_geo_canales_sel)) {
    $tx_geo_col_venta_canal = '';
    foreach (['origen_prospecto','canal_venta','canal'] as $cand_col) {
        $cand_col_esc = mysqli_real_escape_string($conexion, $cand_col);
        $r_col = mysqli_query($conexion, "SHOW COLUMNS FROM ventas LIKE '$cand_col_esc'");
        if ($r_col && mysqli_num_rows($r_col) > 0) {
            $tx_geo_col_venta_canal = $cand_col;
            break;
        }
    }

    if ($tx_geo_col_venta_canal !== '') {
        $tx_geo_where_scope_ventas = '';
        if ($rol_consulta === 'admin') {
            $tx_geo_where_scope_ventas = '';
        } elseif ($scope_filtrar_por_distrito) {
            $tx_geo_where_scope_ventas = " AND distrito IN ($scope_distritos_sql)";
        } elseif ($por_distrito) {
            $tx_geo_where_scope_ventas = " AND distrito IN ($distritos_sql)";
        } else {
            $tx_geo_where_scope_ventas = empty($folio_ids) ? " AND 1=0" : " AND folio_empleado IN (" . tx_sql_in_escaped($conexion, $folio_ids) . ")";
        }

        $tx_geo_col_venta_canal_sql = "`" . str_replace("`", "", $tx_geo_col_venta_canal) . "`";
        $r_tx_geo_ventas_periodo = mysqli_query($conexion, "
            SELECT COUNT(*) AS total
            FROM ventas
            WHERE $tx_dashboard_where_ventas
              AND $tx_geo_col_venta_canal_sql IN (" . tx_sql_in_escaped($conexion, $tx_geo_canales_sel) . ")
              $tx_geo_where_scope_ventas
        ");
        if ($r_tx_geo_ventas_periodo && $row_geo_ventas_periodo = mysqli_fetch_assoc($r_tx_geo_ventas_periodo)) {
            $tx_geo_ventas_periodo = (int)($row_geo_ventas_periodo['total'] ?? 0);
        }
    }
}

$r_tx_geo_total = mysqli_query($conexion, "
    SELECT COUNT(*) AS total
    $tx_geo_where_base
    $tx_geo_where_canal
    $tx_geo_where_validas
");
if ($r_tx_geo_total && $row_geo_total = mysqli_fetch_assoc($r_tx_geo_total)) {
    $tx_geo_total = (int)($row_geo_total['total'] ?? 0);
}

$tx_geo_invalidas = max(0, (int)$tx_geo_inst_periodo - (int)$tx_geo_total);

$r_tx_geo = mysqli_query($conexion, "
    SELECT
        ROUND(CAST(latitud AS DECIMAL(10,6)), 5) AS lat,
        ROUND(CAST(longitud AS DECIMAL(10,6)), 5) AS lng,
        COALESCE(NULLIF(TRIM(distrito),''),'SIN DISTRITO') AS distrito,
        COALESCE(NULLIF(TRIM(origen_prospecto),''),'SIN CANAL') AS canal,
        $tx_geo_expr_vendedor AS vendedor,
        $tx_geo_expr_coach AS coach,
        $tx_geo_expr_play AS play,
        $tx_geo_expr_precio AS precio_lista_descuento,
        COUNT(*) AS total,
        COUNT(DISTINCT folio_empleado) AS vendedores,
        MIN(fecha) AS fecha_min,
        MAX(fecha) AS fecha_max
    $tx_geo_where_base
    $tx_geo_where_canal
    $tx_geo_where_validas
    GROUP BY ROUND(CAST(latitud AS DECIMAL(10,6)), 5),
             ROUND(CAST(longitud AS DECIMAL(10,6)), 5),
             COALESCE(NULLIF(TRIM(distrito),''),'SIN DISTRITO'),
             COALESCE(NULLIF(TRIM(origen_prospecto),''),'SIN CANAL'),
             vendedor,
             coach,
             play,
             precio_lista_descuento
    ORDER BY total DESC
    LIMIT 5000
");

$tx_geo_sum_lat = 0;
$tx_geo_sum_lng = 0;
$tx_geo_sum_count = 0;

while ($r_tx_geo && $row_geo = mysqli_fetch_assoc($r_tx_geo)) {
    $lat = (float)($row_geo['lat'] ?? 0);
    $lng = (float)($row_geo['lng'] ?? 0);
    $count = (int)($row_geo['total'] ?? 0);
    if ($lat == 0 || $lng == 0 || $count <= 0) continue;

    $intensity = min(1, 0.25 + ($count / 8));
    $tx_geo_heat_points[] = [$lat, $lng, $intensity];
    $tx_geo_marker_points[] = [
        'lat' => $lat,
        'lng' => $lng,
        'total' => $count,
        'distrito' => $row_geo['distrito'] ?? 'SIN DISTRITO',
        'canal' => $row_geo['canal'] ?? 'SIN CANAL',
        'vendedor' => $row_geo['vendedor'] ?? 'SIN VENDEDOR',
        'coach' => $row_geo['coach'] ?? 'SIN COACH',
        'play' => $row_geo['play'] ?? 'SIN PLAN',
        'precio_lista_descuento' => (float)($row_geo['precio_lista_descuento'] ?? 0),
        'vendedores' => (int)($row_geo['vendedores'] ?? 0),
        'fecha_min' => $row_geo['fecha_min'] ?? '',
        'fecha_max' => $row_geo['fecha_max'] ?? ''
    ];

    $tx_geo_sum_lat += ($lat * $count);
    $tx_geo_sum_lng += ($lng * $count);
    $tx_geo_sum_count += $count;
}

$r_tx_geo_distrito = mysqli_query($conexion, "
    SELECT
        COALESCE(NULLIF(TRIM(distrito),''),'SIN DISTRITO') AS distrito,
        COUNT(*) AS total
    $tx_geo_where_base
    $tx_geo_where_canal
    $tx_geo_where_validas
    GROUP BY COALESCE(NULLIF(TRIM(distrito),''),'SIN DISTRITO')
    ORDER BY total DESC
    LIMIT 1
");
if ($r_tx_geo_distrito && $row_geo_dist = mysqli_fetch_assoc($r_tx_geo_distrito)) {
    $tx_geo_distrito_top = $row_geo_dist['distrito'] ?? 'Sin datos';
    $tx_geo_distrito_top_total = (int)($row_geo_dist['total'] ?? 0);
    $tx_geo_zona_caliente = $tx_geo_distrito_top;
    $tx_geo_zona_caliente_total = $tx_geo_distrito_top_total;
}

$tx_geo_cobertura_pct = ((int)$tx_geo_inst_periodo > 0) ? round(((int)$tx_geo_total / (int)$tx_geo_inst_periodo) * 100, 1) : 0;

if ($tx_geo_sum_count > 0) {
    $tx_geo_centro = [
        round($tx_geo_sum_lat / $tx_geo_sum_count, 6),
        round($tx_geo_sum_lng / $tx_geo_sum_count, 6)
    ];
    $tx_geo_zoom = ($rol_consulta === 'admin' || $rol === 'director_regional') ? 7 : 10;
}
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
    <style>
        .dashboard-range-card{
            margin: -8px 0 22px;
            background: rgba(255,255,255,.72);
            border: 1px solid rgba(226,232,240,.95);
            border-radius: 24px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            box-shadow: 0 12px 28px rgba(22,28,60,.07);
        }
        .dashboard-range-info{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .dashboard-range-label{
            font-size:.68rem;
            text-transform:uppercase;
            letter-spacing:.7px;
            font-weight:900;
            color:#6b7a99;
        }
        .dashboard-range-value{font-weight:900;color:#1a2540}
        .dashboard-range-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .range-dropdown{position:relative}
        .range-trigger,.range-action-btn{
            border:0;
            border-radius:999px;
            padding:10px 14px;
            font-weight:900;
            font-family:inherit;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:7px;
        }
        .range-trigger{
            color:#fff;
            background:linear-gradient(135deg,#7A2BFF,#FF006C);
            box-shadow:0 10px 22px rgba(122,43,255,.20);
        }
        .range-action-btn{
            color:#1a2540;
            background:#eef4ff;
            border:1px solid #dbe4f0;
        }
        .range-action-btn.active{
            color:#fff;
            background:linear-gradient(135deg,#7A2BFF,#FF006C);
            border-color:transparent;
        }
        .range-panel{
            display:none;
            position:absolute;
            left:0;
            top:46px;
            z-index:50;
            width:335px;
            background:#fff;
            border:1px solid #e2e8f0;
            border-radius:22px;
            padding:16px;
            box-shadow:0 24px 50px rgba(15,23,42,.18);
        }
        .range-dropdown.open .range-panel{display:block}
        .range-panel-title{
            font-size:.82rem;
            font-weight:900;
            color:#1a2540;
            margin-bottom:12px;
        }
        .calendar-grid-real{
            display:grid;
            grid-template-columns:repeat(7,1fr);
            gap:6px;
        }
        .calendar-weekday{
            font-size:.66rem;
            font-weight:900;
            text-align:center;
            color:#6b7a99;
            text-transform:uppercase;
        }
        .calendar-empty{min-height:34px}
        .calendar-day{
            border:1px solid #e2e8f0;
            background:#f8fafc;
            border-radius:12px;
            min-height:34px;
            font-weight:900;
            color:#1a2540;
            cursor:pointer;
        }
        .calendar-day:hover{border-color:#7A2BFF;background:#f3e8ff}
        .calendar-day.in-range{background:#ede9fe;border-color:#c4b5fd}
        .calendar-day.selected-start,.calendar-day.selected-end{
            background:linear-gradient(135deg,#7A2BFF,#FF006C);
            border-color:transparent;
            color:#fff;
        }
        .range-summary{
            margin:12px 0 10px;
            color:#6b7a99;
            font-size:.78rem;
            font-weight:800;
        }
        .range-actions{
            display:flex;
            justify-content:flex-end;
            gap:10px;
            align-items:center;
        }
        .range-actions button{
            border:0;
            border-radius:999px;
            padding:10px 14px;
            font-weight:900;
            color:#fff;
            background:linear-gradient(135deg,#7A2BFF,#FF006C);
            cursor:pointer;
        }
        .hierarchy-card{
            margin: 0 0 22px;
            background: rgba(255,255,255,.78);
            border: 1px solid rgba(226,232,240,.95);
            border-radius: 24px;
            padding: 16px;
            box-shadow: 0 12px 28px rgba(22,28,60,.07);
        }
        .hierarchy-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px}
        .hierarchy-title{font-size:.82rem;text-transform:uppercase;letter-spacing:.7px;font-weight:900;color:#6b7a99}
        .hierarchy-current{font-weight:900;color:#1a2540;font-size:1rem}
        .hierarchy-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .hierarchy-select{
            min-width:360px;
            max-width:100%;
            border:1px solid #dbe4f0;
            border-radius:999px;
            padding:12px 14px;
            background:#fff;
            color:#1a2540;
            font-family:inherit;
            font-weight:800;
        }
        .hierarchy-btn,.hierarchy-clear{
            border:0;
            border-radius:999px;
            padding:12px 16px;
            font-weight:900;
            font-family:inherit;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:7px;
        }
        .hierarchy-btn{color:#fff;background:linear-gradient(135deg,#7A2BFF,#FF006C)}
        .hierarchy-clear{color:#1a2540;background:#eef4ff;border:1px solid #dbe4f0}
        .hierarchy-note{font-size:.78rem;color:#6b7a99;font-weight:800}

        .hierarchy-performance-card{
            margin: 0 0 22px;
        }
        .hierarchy-performance-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }
        .hierarchy-performance-sub{
            font-size:.74rem;
            color:#6b7a99;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.7px;
        }
        .hierarchy-performance-wrap{
            height:280px;
            position:relative;
        }
        .hierarchy-performance-note{
            color:#6b7a99;
            font-size:.76rem;
            font-weight:800;
            margin-top:8px;
        }


        .cumplimiento-list{
            display:flex;
            flex-direction:column;
            gap:12px;
            margin-top:14px;
        }
        .cumplimiento-row{
            display:grid;
            grid-template-columns:150px 1fr 130px;
            align-items:center;
            gap:12px;
        }
        .cumplimiento-name{
            font-size:.78rem;
            font-weight:900;
            color:#1a2540;
            text-transform:uppercase;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .cumplimiento-track{
            height:20px;
            background:#EEF2FF;
            border-radius:999px;
            overflow:hidden;
            border:1px solid #E0E3EA;
        }
        .cumplimiento-fill{
            height:100%;
            border-radius:999px;
            min-width:4px;
        }
        .cumplimiento-fill.ok{background:linear-gradient(90deg,#00A6FF,#00E5FF);}
        .cumplimiento-fill.warn{background:linear-gradient(90deg,#7A2BFF,#B026FF);}
        .cumplimiento-fill.risk{background:linear-gradient(90deg,#FF006C,#FF4FA3);}
        .cumplimiento-fill.neutral{background:linear-gradient(90deg,#64748B,#CBD5E1);}

        .cumplimiento-track{ position:relative; }
        .cumplimiento-fill{ position:relative; overflow:hidden; }
        .cumplimiento-bar-block{
            display:flex;
            flex-direction:column;
            gap:4px;
            min-width:0;
        }
        .cumplimiento-resneg-divider{
            position:absolute;
            top:1px;
            bottom:1px;
            width:3px;
            background:#111827;
            border-radius:999px;
            transform:translateX(-1.5px);
            box-shadow:0 0 0 1px rgba(255,255,255,.85);
            z-index:5;
            pointer-events:none;
        }
        .cumplimiento-mix{
            font-size:.66rem;
            color:#6b7280;
            font-weight:900;
            line-height:1.1;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        .cumplimiento-metric{
            font-size:.86rem;
            font-weight:950;
            color:#1a2540;
            text-align:right;
            white-space:nowrap;
        }
        .cumplimiento-sub{
            font-size:.68rem;
            color:#6b7a99;
            font-weight:800;
            margin-left:6px;
        }


        
        .top-productividad-grid{
            display:grid;
            grid-template-columns:minmax(0,1fr) minmax(0,1fr);
            gap:18px;
            margin:20px 0 0;
        }
        .top-productividad-card{
            padding:20px;
            overflow:hidden;
        }
        .top-productividad-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:12px;
            margin-bottom:12px;
        }
        .top-productividad-title{
            font-size:1rem;
            font-weight:950;
            color:#1a2540;
        }
        .top-productividad-sub{
            font-size:.68rem;
            text-transform:uppercase;
            letter-spacing:.08em;
            font-weight:900;
            color:#6b7a99;
            margin-top:4px;
        }
        .top-productividad-table{
            width:100%;
            border-collapse:collapse;
            font-size:.68rem;
            table-layout:fixed;
        }
        .top-productividad-table th{
            text-align:left;
            padding:7px 6px;
            color:#6b7a99;
            text-transform:uppercase;
            letter-spacing:.05em;
            font-size:.64rem;
            border-bottom:1px solid #e2e8f4;
        }
        .top-productividad-table td{
            padding:7px 6px;
            border-bottom:1px solid #edf2fb;
            color:#1a2540;
            font-weight:800;
            vertical-align:middle;
        }
        
        .top-productividad-table-wrap{
            width:100%;
            max-width:100%;
            overflow-x:hidden;
        }
        .top-productividad-table th:nth-child(1),
        .top-productividad-table td:nth-child(1){width:34px;}
        .top-productividad-table th:nth-child(2),
        .top-productividad-table td:nth-child(2){width:31%;}
        .top-productividad-table th:nth-child(3),
        .top-productividad-table td:nth-child(3){width:14%;}
        .top-productividad-table th:nth-child(4),
        .top-productividad-table td:nth-child(4){width:23%;}
        .top-productividad-table th:nth-child(5),
        .top-productividad-table td:nth-child(5){width:12%;}
        .top-productividad-table th:nth-child(6),
        .top-productividad-table td:nth-child(6){width:12%;}
        .top-productividad-table th:nth-child(7),
        .top-productividad-table td:nth-child(7){width:8%;}

.top-productividad-table tr:last-child td{
            border-bottom:0;
        }
        .seller-name{
            font-weight:950;
            max-width:180px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .seller-district{
            color:#6b7a99;
            font-weight:850;
            white-space:nowrap;
        }
        .seller-small{
            color:#334155;
            font-weight:850;
            max-width:145px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .seller-num{
            text-align:right;
            white-space:nowrap;
        }
        .seller-prod{
            display:flex;
            flex-direction:column;
            align-items:flex-end;
            gap:4px;
        }
        .sparkline{
            display:flex;
            align-items:flex-end;
            gap:2px;
            height:16px;
            min-width:46px;
        }
        .sparkline span{
            display:block;
            width:5px;
            min-height:3px;
            border-radius:3px 3px 0 0;
            background:linear-gradient(180deg,#00E5FF,#00A6FF);
        }
        .top-offender .sparkline span{
            background:linear-gradient(180deg,#FF4FA3,#FF006C);
        }
        .rank-badge{
            width:22px;
            height:22px;
            border-radius:999px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:.68rem;
            font-weight:950;
            color:white;
            background:linear-gradient(135deg,#00A6FF,#00E5FF);
        }
        .top-offender .rank-badge{
            background:linear-gradient(135deg,#FF006C,#FF4FA3);
        }
        @media(max-width:1100px){
            .top-productividad-grid{
                grid-template-columns:1fr;
            }
        }

.dashboard-analytics-grid{
            display:grid;
            gap:18px;
            align-items:stretch;
            margin:0 0 22px;
            width:100%;
            max-width:100%;
            overflow:hidden;
        }
        .dashboard-analytics-grid.with-channel{
            /*
             * Admin/Regional/Director Distrital:
             * Cumplimiento inferior 28% | Cumplimiento por canal 28% | ARPU 28% | Mix instalaciones 16%.
             * ARPU necesita más ancho para mostrar completo el último mes.
             */
            grid-template-columns:minmax(0, 28fr) minmax(0, 28fr) minmax(0, 28fr) minmax(0, 16fr);
        }
        .dashboard-analytics-grid.with-hc-bottom{
            /*
             * Líder/Coach/Vendedor:
             * Cumplimiento inferior | Headcount | Mix ventas | Mix instalaciones
             */
            grid-template-columns:minmax(0, 3fr) minmax(0, 3fr) minmax(0, 2fr) minmax(0, 2fr);
        }
        .dashboard-analytics-grid > .evo-card,
        .dashboard-analytics-grid > .kpi-card,
        .dashboard-analytics-grid > .chart-card{
            margin:0;
            min-width:0;
            height:100%;
        }
        .dashboard-analytics-grid .chart-wrap{
            min-height:230px;
        }
        .dashboard-analytics-grid .kpi-speed-layout{
            min-height:230px;
        }
        .dashboard-analytics-grid .chart-card{
            padding:18px;
        }
        .arpu-card .chart-wrap{
            min-height:230px;
        }
        .dashboard-analytics-grid.with-channel .arpu-card .chart-wrap{
            min-height:245px;
        }
        .dashboard-analytics-grid.with-channel .chart-card:last-child{
            padding:14px 10px;
        }
        .dashboard-analytics-grid.with-channel .chart-card:last-child .chart-title{
            font-size:.78rem;
            line-height:1.15;
        }
        .dashboard-analytics-grid.with-channel .chart-card:last-child .chart-wrap{
            min-height:245px;
        }

        .cumplimiento-panel{
            padding:20px;
        }
        .cumplimiento-panel .hierarchy-performance-head{
            margin-bottom:8px;
        }
        .cumplimiento-panel .chart-title{
            font-size:.92rem;
            line-height:1.15;
            font-weight:900;
        }
        .cumplimiento-panel .hierarchy-performance-sub,
        .cumplimiento-panel .hierarchy-performance-note{
            font-size:.64rem;
            line-height:1.15;
        }
        .cumplimiento-panel .cumplimiento-list{
            gap:13px;
            margin-top:12px;
        }
        .cumplimiento-panel .cumplimiento-row{
            display:grid;
            grid-template-columns:1fr auto;
            gap:5px 10px;
            align-items:end;
        }
        .cumplimiento-panel .cumplimiento-name{
            font-size:.72rem;
            grid-column:1;
            grid-row:1;
        }
        .cumplimiento-panel .cumplimiento-track{
            height:22px;
            grid-column:1 / -1;
            grid-row:2;
            box-shadow: inset 0 1px 3px rgba(15,23,42,.08);
        }
        .cumplimiento-panel .cumplimiento-metric{
            grid-column:2;
            grid-row:1;
            text-align:right;
            font-size:.82rem;
            line-height:1;
            font-weight:950;
        }
        .cumplimiento-panel .cumplimiento-sub{
            font-size:.66rem;
            margin-left:6px;
        }

        .kpi-grid-main{
            display:grid;
            gap:18px;
            align-items:stretch;
            margin:0 0 22px;
            width:100%;
            max-width:100%;
            overflow:hidden;
        }
        .kpi-grid-main.with-hc{
            /*
             * Admin/Regional/Director Distrital:
             * Avance vs Meta | Instalaciones | Ventas | Conversión | Headcount
             * Avance vs Meta y Headcount conservan el mismo ancho porque contienen velocímetro.
             * Las 3 tarjetas centrales son más compactas porque sólo muestran KPI numérico.
             */
            grid-template-columns:minmax(0, 28fr) minmax(0, 14fr) minmax(0, 14fr) minmax(0, 14fr) minmax(0, 28fr);
        }
        .kpi-grid-main.without-hc{
            /*
             * Líder/Coach/Vendedor:
             * Headcount baja al segundo renglón para recuperar proporción visual.
             */
            grid-template-columns:minmax(0, 30fr) minmax(0, 23fr) minmax(0, 23fr) minmax(0, 24fr);
        }
        .kpi-grid-main > .kpi-card{
            margin:0;
            min-width:0;
            height:100%;
        }
        .kpi-grid-main .kpi-speed-layout{
            min-height:150px;
            overflow:hidden;
        }
        .kpi-grid-main .speedometer-container{
            transform:scale(.86);
            transform-origin:center;
        }
        .kpi-grid-main .speed-numbers .speed-val{
            font-size:1.85rem;
        }
        .kpi-grid-main .kpi-val{
            font-size:1.9rem;
        }
        .cumplimiento-panel-canal{
            padding:20px;
        }

        /* Contención general para evitar que tarjetas o gráficas rompan el ancho del dashboard */
        .main{
            max-width:100%;
            overflow-x:hidden;
        }
        .kpi-card,
        .chart-card,
        .evo-card,
        .hierarchy-performance-card{
            min-width:0;
            max-width:100%;
            box-sizing:border-box;
            overflow:hidden;
        }
        /* Semanal: todas las semanas caben en el ancho nativo de cada tarjeta.
           Únicamente la matriz de Participación conserva desplazamiento horizontal. */
        .tx-historical-scroll {max-width:100%;min-width:0;}
        .tx-weekly-scroll {width:100%;overflow:hidden;}
        .tx-weekly-scroll .tx-weekly-evo-inner,
        .tx-weekly-scroll .tx-weekly-arpu-inner {width:100%;max-width:100%;min-width:0;}
        .tx-weekly-scroll .tx-weekly-arpu-inner canvas,
        .tx-weekly-scroll .tx-weekly-evo-inner canvas {max-width:100% !important;}
        .chart-wrap,
        .evo-wrap{
            position:relative;
            width:100%;
            max-width:100%;
            overflow:hidden;
        }
        .chart-wrap canvas,
        .evo-wrap canvas{
            max-width:100% !important;
        }
        .dashboard-analytics-grid .chart-wrap{
            min-height:210px;
        }
        .dashboard-analytics-grid .kpi-speed-layout{
            min-height:210px;
            overflow:hidden;
        }
        .dashboard-analytics-grid .speedometer-container{
            max-width:100%;
            transform:scale(.82);
            transform-origin:center;
        }
        .dashboard-analytics-grid .speed-numbers .speed-val{
            font-size:1.65rem;
        }
        .dashboard-analytics-grid .speed-numbers{
            min-width:96px;
        }
        .dashboard-analytics-grid .chart-title{
            font-size:.88rem;
            line-height:1.15;
        }
        .dashboard-analytics-grid .chart-card{
            display:flex;
            flex-direction:column;
            justify-content:space-between;
        }

        @media(max-width:1200px){
            .kpi-grid-main{
                grid-template-columns:1fr 1fr;
            }
        }

        @media(max-width:1200px){
            .dashboard-analytics-grid{
                grid-template-columns:minmax(0,1fr) minmax(0,1fr);
            }
        }

        @media(max-width:760px){
            .dashboard-analytics-grid,
            .kpi-grid-main{
                grid-template-columns:1fr;
            }
        }

        @media(max-width:900px){
            .dashboard-range-card{align-items:flex-start;flex-direction:column}
            .range-panel{left:auto;right:0;width:320px}
            .hierarchy-select{min-width:100%}
        }

        /* ── GEORREFERENCIA TOTALXPEDIENT ───────────────────────────── */
        .geo-card{
            margin-top:22px;
            padding:20px;
            border-radius:22px;
            background:rgba(255,255,255,.86);
            border:1px solid rgba(122,43,255,.12);
            box-shadow:0 18px 45px rgba(35,20,80,.12);
            overflow:hidden;
        }
        .geo-header{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
            margin-bottom:14px;
        }
        .geo-title{
            font-weight:900;
            font-size:1.05rem;
            color:#17213a;
            letter-spacing:.02em;
        }
        .geo-subtitle{
            margin-top:4px;
            font-size:.74rem;
            font-weight:800;
            color:#6b7a99;
            text-transform:uppercase;
            letter-spacing:.08em;
        }
        .geo-filter{
            margin:10px 0 14px;
            padding:12px 14px;
            border-radius:18px;
            background:linear-gradient(135deg,rgba(0,166,255,.07),rgba(122,43,255,.07));
            border:1px solid rgba(122,43,255,.12);
        }
        .geo-filter-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:10px;
        }
        .geo-filter-title{
            font-size:.72rem;
            font-weight:950;
            color:#17213a;
            text-transform:uppercase;
            letter-spacing:.08em;
        }
        .geo-filter-actions{
            display:flex;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }
        .geo-filter-clear,
        .geo-filter-btn{
            border:0;
            border-radius:999px;
            padding:8px 12px;
            font-size:.68rem;
            font-weight:950;
            text-transform:uppercase;
            letter-spacing:.06em;
            cursor:pointer;
            text-decoration:none;
        }
        .geo-filter-btn{
            color:white;
            background:linear-gradient(90deg,var(--magenta),var(--purple));
            box-shadow:0 8px 18px rgba(122,43,255,.18);
        }
        .geo-filter-clear{
            color:#6b7a99;
            background:rgba(255,255,255,.75);
            border:1px solid rgba(107,122,153,.18);
        }
        .geo-channel-pills{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:8px;
            max-height:92px;
            overflow:auto;
            padding-right:4px;
        }
        .geo-channel-pill{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:8px 10px;
            border-radius:999px;
            background:rgba(255,255,255,.72);
            border:1px solid rgba(122,43,255,.13);
            color:#24314d;
            font-size:.7rem;
            font-weight:900;
            cursor:pointer;
            user-select:none;
        }
        .geo-channel-pill input{accent-color:#7A2BFF;}
        .geo-channel-pill.active{
            color:#17213a;
            background:linear-gradient(135deg,rgba(255,0,108,.13),rgba(0,166,255,.13));
            border-color:rgba(122,43,255,.28);
        }
        .geo-kpis{
            display:grid;
            grid-template-columns:repeat(6,minmax(140px,1fr));
            gap:10px;
            margin-bottom:14px;
        }
        .geo-kpi{
            padding:12px 14px;
            border-radius:16px;
            background:linear-gradient(135deg,rgba(122,43,255,.08),rgba(0,166,255,.08));
            border:1px solid rgba(122,43,255,.12);
        }
        .geo-kpi span{
            display:block;
            font-size:.66rem;
            font-weight:900;
            color:#6b7a99;
            text-transform:uppercase;
            letter-spacing:.08em;
        }
        .geo-kpi strong{
            display:block;
            margin-top:4px;
            font-size:1.25rem;
            color:#17213a;
            font-weight:950;
        }
        .geo-kpi small{
            display:block;
            margin-top:2px;
            font-size:.68rem;
            color:#6b7a99;
            font-weight:800;
        }
        .geo-map-wrap{position:relative;}
        .geo-legend{
            position:absolute;
            right:14px;
            bottom:14px;
            z-index:500;
            padding:10px 12px;
            border-radius:14px;
            background:rgba(255,255,255,.92);
            border:1px solid rgba(122,43,255,.14);
            box-shadow:0 10px 25px rgba(35,20,80,.14);
            font-size:.7rem;
            font-weight:900;
            color:#17213a;
        }
        .geo-gradient{
            display:inline-block;
            width:120px;
            height:10px;
            margin:0 7px;
            border-radius:999px;
            background:linear-gradient(90deg,#2878ff,#20e8ff,#4dff5d,#ffe600,#ff2d00);
            vertical-align:middle;
        }
        #txGeoMap{
            height:760px;
            min-height:70vh;
            width:100%;
            border-radius:20px;
            overflow:hidden;
            border:1px solid rgba(122,43,255,.16);
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.45);
            background:#e9edf5;
        }
        .geo-empty{
            height:240px;
            display:flex;
            align-items:center;
            justify-content:center;
            text-align:center;
            border-radius:20px;
            border:1px dashed rgba(107,122,153,.35);
            background:rgba(255,255,255,.5);
            color:#6b7a99;
            font-weight:800;
        }
        @media(max-width:900px){
            .geo-header{flex-direction:column}
            .geo-filter-top{align-items:flex-start;flex-direction:column}
            .geo-kpis{grid-template-columns:1fr}
            #txGeoMap{height:420px}
        }

    
        .evo-card-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            flex-wrap:wrap;
        }
        .evo-toggle{
            display:inline-flex;
            gap:6px;
            padding:4px;
            border-radius:999px;
            background:#EEF2FF;
            border:1px solid #E0E3EA;
        }
        .evo-toggle a{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:7px 11px;
            border-radius:999px;
            font-size:.72rem;
            font-weight:900;
            text-decoration:none;
            color:#64748B;
            white-space:nowrap;
        }
        .evo-toggle a.active{
            color:#fff;
            background:linear-gradient(135deg,#7A2BFF,#FF006C);
            box-shadow:0 8px 18px rgba(122,43,255,.18);
        }


        /* INDEX STAGING – última etiqueta ARPU protegida contra recorte. */
        .tx-arpu-ultimo { display:flex;justify-content:space-between;align-items:baseline;
            flex-wrap:wrap;gap:5px 8px;margin:6px 0 3px;padding:7px 9px;
            background:rgba(122,43,255,.055);border:1px solid rgba(122,43,255,.12);border-radius:10px; }
        .tx-arpu-ultimo span {font-size:.65rem;font-weight:800;color:#617291;}
        .tx-arpu-ultimo strong {font-size:.90rem;font-weight:950;color:#152642;white-space:nowrap;}
        .tx-evo-source-note {font-size:.70rem;color:#64748b;margin:9px 0 3px;line-height:1.5;}
        .tx-evo-error {font-size:.72rem;color:#b91c1c;margin:8px 0;font-weight:800;}
        .tx-evo-table-scroll {
            width:100%;
            max-width:100%;
            overflow-x:scroll !important;
            overflow-y:hidden;
            scrollbar-width:auto;
            scrollbar-gutter:stable both-edges;
            padding-bottom:8px;
            overscroll-behavior-x:contain;
            -webkit-overflow-scrolling:touch;
        }
        .tx-evo-table-scroll::-webkit-scrollbar{height:12px;}
        .tx-evo-table-scroll::-webkit-scrollbar-track{background:#eef2f8;border-radius:999px;}
        .tx-evo-table-scroll::-webkit-scrollbar-thumb{background:#9aa8bf;border-radius:999px;border:2px solid #eef2f8;}
        .tx-evo-table-scroll::-webkit-scrollbar-thumb:hover{background:#71819b;}
        .tx-evo-participacion-table {min-width:1040px;width:max-content !important;table-layout:auto;}
        .tx-evo-participacion-table th:first-child, .tx-evo-participacion-table td:first-child {
            position:sticky;left:0;z-index:2;background:#f0f5ff;min-width:130px;
            box-shadow:2px 0 4px rgba(15,23,42,.06);
        }
        .tx-evo-participacion-table td:first-child {background:#fff;}
        .tx-evo-participacion-table tr:last-child td:first-child {background:#e8f0fe;}
        .tx-evo-participacion-table th:not(:first-child) {min-width:67px;}
        .tx-evo-participacion-table td:not(:first-child) {min-width:67px;}
        .evo-toggle {flex-wrap:wrap;}
        /* STAGING navegabilidad v2.1: dos matrices independientes en su tarjeta.
           minmax(0,1fr) impide que el contenido de 10 meses empuje Instalaciones fuera.
           Sólo afecta Participación; NO cambia la grilla de gráficas Evolución. */
        #txEvoParticipationCard .tx-participacion-grid {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:22px;
            width:100%;
            max-width:100%;
            align-items:start;
        }
        #txEvoParticipationCard .tx-participacion-grid > div {
            min-width:0;
            max-width:100%;
            width:100%;
            overflow:hidden;
        }
        #txEvoParticipationCard .tx-evo-table-scroll {
            display:block;
            width:100%;
            min-width:0;
            max-width:100%;
            overflow-x:auto !important;
            overflow-y:hidden;
            box-sizing:border-box;
            scrollbar-width:thin;
            scrollbar-color:#8b9db7 #eef2f8;
            scrollbar-gutter:auto;
            -webkit-overflow-scrolling:touch;
            touch-action:pan-x pan-y;
        }
        #txEvoParticipationCard .tx-evo-participacion-table {
            width:max-content !important;
            min-width:100%;
            table-layout:auto;
        }
        #txEvoParticipationCard .tx-evo-participacion-table th,
        #txEvoParticipationCard .tx-evo-participacion-table td {white-space:nowrap;}
        #txEvoParticipationCard .tx-evo-participacion-table th:first-child,
        #txEvoParticipationCard .tx-evo-participacion-table td:first-child {min-width:130px;}
        .tx-participacion-nav {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:7px;
        }
        .tx-participacion-nav-controls {display:inline-flex;align-items:center;gap:7px;}
        .tx-participacion-nav-label {font-size:.63rem;font-weight:700;color:#71809d;white-space:nowrap;}
        .tx-participacion-arrow {
            width:29px;height:28px;display:inline-flex;align-items:center;justify-content:center;
            border:1px solid #d8def0;background:#f0f4ff;border-radius:9px;
            color:#6f28cf;font-size:1rem;font-weight:900;cursor:pointer;
        }
        .tx-participacion-arrow:hover:not(:disabled){background:#e7deff;border-color:#ae8aff;}
        .tx-participacion-arrow:disabled{opacity:.3;cursor:default;}
        .tx-participacion-arrow:focus-visible,
        #txEvoParticipationCard .tx-evo-table-scroll:focus-visible{outline:2px solid #8237e8;outline-offset:2px;}
        @media(max-width:900px){
            #txEvoParticipationCard .tx-participacion-grid{grid-template-columns:minmax(0,1fr);}
        }

        /* Navegación homologada a Ranking Productividad — sin tocar sidebar */
        .tx-nav-card{position:relative;overflow:visible;flex-wrap:wrap;z-index:80;}
        .tx-nav-actions{gap:7px;justify-content:flex-end;}
        .tx-nav-modes{display:inline-flex;gap:5px;margin-right:6px;}
        .tx-nav-button{
            background:#fff; border:1px solid #e4e9f4; border-radius:14px; padding:10px 13px;
            color:#25344f; text-decoration:none; font-size:.78rem;font-weight:900;
            cursor:pointer;display:inline-flex;align-items:center;justify-content:center;
            font-family:inherit;white-space:nowrap;min-height:40px;
        }
        .tx-nav-button:hover:not(.is-disabled){background:#f4eaff;border-color:#cbb7ff;}
        .tx-nav-button.is-active{background:linear-gradient(135deg,#7A2BFF,#FF006C);color:white;border-color:transparent;box-shadow:0 7px 18px rgba(122,43,255,.15);}
        .tx-nav-button.is-disabled,.tx-nav-month-arrow.is-disabled{opacity:.35;pointer-events:none;cursor:default;}
        .tx-nav-dropdown{z-index:75;}
        .tx-nav-panel{z-index:100;left:50%;right:auto;transform:translateX(-50%);top:49px;width:348px;max-width:calc(100vw - 32px);}
        .tx-nav-panel .range-actions{justify-content:space-between;margin-top:12px;}
        .tx-nav-month-title{display:flex;align-items:center;justify-content:space-between;gap:8px;text-align:center;}
        .tx-nav-month-arrow{text-decoration:none;font-size:24px;line-height:1;color:#7629c9;}
        .tx-nav-panel .calendar-day.adjacent-month{opacity:.48;}
        .tx-nav-panel .calendar-day.adjacent-month.in-range,
        .tx-nav-panel .calendar-day.adjacent-month.selected-start,
        .tx-nav-panel .calendar-day.adjacent-month.selected-end{opacity:1;}
        .tx-nav-panel .calendar-day.disabled-date{opacity:.2;cursor:not-allowed;}
        .tx-nav-weekday-grid{margin:13px 0 18px;}
        .tx-nav-weekday{min-height:46px;font-size:.68rem;padding:0 2px;}
        .tx-nav-panel .tx-nav-full-btn{display:inline-flex;align-items:center;border:0;border-radius:999px;padding:10px 12px;background:#f1eaff;color:#6d28d9;font-size:.74rem;font-weight:850;text-decoration:none;cursor:pointer;}
        .tx-nav-info{margin:-10px 0 18px;padding:0 4px;color:#71809d;font-size:.74rem;}
        @media(max-width:900px){.tx-nav-panel{left:auto;right:0;transform:none;}.tx-nav-actions{justify-content:flex-start;}}

</style>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body class="page-dashboard">
<?php
$current_page = 'dashboard';
include __DIR__ . '/includes/sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div>
            <h2>
                <?php if ($scope_activo): ?>
                    <?= htmlspecialchars($posicion_scope) ?> · <?= htmlspecialchars($nombre_completo_scope) ?>
                <?php else: ?>
                    <?= htmlspecialchars($roles_labels[$rol] ?? $rol) ?> <?= htmlspecialchars($distrito_usuario) ?>
                <?php endif; ?>
            </h2>
            <p><?= htmlspecialchars($dashboard_fecha_label) ?></p>
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

    <!-- Selector temporal homologado a Ranking Productividad (STAGING) -->
    <section class="dashboard-range-card tx-nav-card">
        <div class="dashboard-range-info">
            <div><div class="dashboard-range-label">Corte operativo</div>
                <div class="dashboard-range-value"><?= htmlspecialchars($dashboard_fecha_label, ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="dashboard-range-label" style="max-width:300px">
                <?= $periodo === 'semanal' ? 'Semana ISO · Selección de días comparables (LUN–DOM)' : 'Rango mensual continuo · Fechas reales, incluso entre meses' ?>
            </div>
        </div>
        <div class="dashboard-range-actions tx-nav-actions">
            <div class="tx-nav-modes" aria-label="Modo de navegación temporal">
                <a class="tx-nav-button <?= $periodo === 'semanal' ? 'is-active' : '' ?>"
                   href="<?= tx_dashboard_link(['periodo'=>'semanal','anio_semana'=>$tx_nav_anio_semana_max,'semana'=>$tx_nav_semana_max,'dias_semana'=>null,'rango_mode'=>null,'fecha_inicio'=>null,'fecha_fin'=>null]) ?>">Semanal</a>
                <a class="tx-nav-button <?= $periodo === 'mensual' ? 'is-active' : '' ?>"
                   href="<?= tx_dashboard_link(['periodo'=>'mensual','mes'=>(int)$tx_fecha_limite->format('n'),'anio'=>(int)$tx_fecha_limite->format('Y'),'anio_mes'=>null,'rango_mode'=>'mtd','dias_semana'=>null,'fecha_inicio'=>null,'fecha_fin'=>null]) ?>">Mensual</a>
            </div>
            <?php if ($periodo === 'semanal'): ?>
                <a class="tx-nav-button" href="<?= tx_dashboard_link(['periodo'=>'semanal','anio_semana'=>(int)$tx_prev_lunes->format('o'),'semana'=>(int)$tx_prev_lunes->format('W'),'dias_semana'=>null]) ?>">← Semana <?= (int)$tx_prev_lunes->format('W') ?></a>
                <div class="range-dropdown tx-nav-dropdown" id="dashboardRangeDropdown">
                    <button class="tx-nav-button is-active tx-nav-picker" id="dashboardRangeTrigger" type="button">Semana <?= (int)$tx_nav_semana ?> · <?= (int)$tx_nav_anio_semana ?> ▾</button>
                    <div class="range-panel tx-nav-panel" id="dashboardRangePanel">
                        <form method="get" id="dashboardRangeForm">
                            <?php foreach ($_GET as $k=>$v): if (in_array($k,['periodo','anio_semana','semana','dias_semana','rango_mode','fecha_inicio','fecha_fin','dia_inicio','dia_fin','mes','anio','anio_mes'],true) || is_array($v)) continue; ?>
                                <input type="hidden" name="<?= htmlspecialchars((string)$k,ENT_QUOTES,'UTF-8') ?>" value="<?= htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8') ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="periodo" value="semanal">
                            <input type="hidden" name="anio_semana" value="<?= (int)$tx_nav_anio_semana ?>">
                            <input type="hidden" name="semana" value="<?= (int)$tx_nav_semana ?>">
                            <input type="hidden" name="dias_semana" id="dashDiasSemanaInput" value="<?= htmlspecialchars(implode(',',$tx_dias_semana_seleccionados)) ?>">
                            <div class="range-panel-title">Selecciona días comparables de Semana <?= (int)$tx_nav_semana ?> · <?= (int)$tx_nav_anio_semana ?></div>
                            <div class="calendar-grid-real tx-nav-weekday-grid" id="dashWeekdayGrid">
                                <?php foreach ($tx_dias_semana_labels as $numero=>$nombre): $dis = !in_array($numero,$tx_dias_semana_disponibles,true); ?>
                                    <button type="button" class="calendar-day tx-nav-weekday <?= in_array($numero,$tx_dias_semana_seleccionados,true)?'selected-start selected-end':'' ?>"
                                       data-weekday="<?= $numero ?>" <?= $dis?'disabled':'' ?>><?= htmlspecialchars($nombre) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <div class="range-actions">
                                <button type="button" class="tx-nav-full-btn" id="dashWeekFullBtn">Semana completa disponible</button>
                                <button type="submit">Aplicar</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php if ($tx_semana_has_next): ?>
                    <a class="tx-nav-button" href="<?= tx_dashboard_link(['periodo'=>'semanal','anio_semana'=>(int)$tx_next_lunes->format('o'),'semana'=>(int)$tx_next_lunes->format('W'),'dias_semana'=>null]) ?>">Semana <?= (int)$tx_next_lunes->format('W') ?> →</a>
                <?php else: ?><span class="tx-nav-button is-disabled">Semana <?= (int)$tx_next_lunes->format('W') ?> →</span><?php endif; ?>
            <?php else: ?>
                <a class="tx-nav-button" href="<?= tx_dashboard_link(['periodo'=>'mensual','anio'=>$anio_prev,'mes'=>$mes_prev,'rango_mode'=>'mtd','fecha_inicio'=>null,'fecha_fin'=>null,'dia_inicio'=>null,'dia_fin'=>null]) ?>">← <?= htmlspecialchars($meses_es[$mes_prev]) ?></a>
                <div class="range-dropdown tx-nav-dropdown" id="dashboardRangeDropdown">
                    <button class="tx-nav-button is-active tx-nav-picker" id="dashboardRangeTrigger" type="button"><?= htmlspecialchars($dashboard_fecha_label) ?> ▾</button>
                    <div class="range-panel tx-nav-panel" id="dashboardRangePanel">
                        <form method="get" id="dashboardRangeForm">
                            <?php foreach ($_GET as $k=>$v): if (in_array($k,['periodo','anio_semana','semana','dias_semana','rango_mode','fecha_inicio','fecha_fin','dia_inicio','dia_fin','mes','anio','anio_mes'],true) || is_array($v)) continue; ?>
                                <input type="hidden" name="<?= htmlspecialchars((string)$k,ENT_QUOTES,'UTF-8') ?>" value="<?= htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8') ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="periodo" value="mensual"><input type="hidden" name="rango_mode" value="custom">
                            <input type="hidden" name="mes" value="<?= (int)$mes_actual ?>"><input type="hidden" name="anio" value="<?= (int)$anio_query ?>">
                            <input type="hidden" name="fecha_inicio" id="dashFechaInicio" value="<?= htmlspecialchars($fecha_inicio_dashboard) ?>">
                            <input type="hidden" name="fecha_fin" id="dashFechaFin" value="<?= htmlspecialchars($fecha_fin_dashboard) ?>">
                            <div class="range-panel-title tx-nav-month-title">
                                <a class="tx-nav-month-arrow" href="<?= tx_dashboard_link(['periodo'=>'mensual','anio'=>$anio_prev,'mes'=>$mes_prev,'rango_mode'=>'mtd','fecha_inicio'=>null,'fecha_fin'=>null]) ?>">‹</a>
                                <span>Selecciona rango de <?= htmlspecialchars($meses_es[$mes_actual]) ?> <?= (int)$anio_query ?></span>
                                <?php if ($puede_mes_next): ?><a class="tx-nav-month-arrow" href="<?= tx_dashboard_link(['periodo'=>'mensual','anio'=>$anio_next,'mes'=>$mes_next,'rango_mode'=>'mtd','fecha_inicio'=>null,'fecha_fin'=>null]) ?>">›</a>
                                <?php else: ?><span class="tx-nav-month-arrow is-disabled">›</span><?php endif; ?>
                            </div>
                            <div class="calendar-grid-real" id="dashCalendarGrid"
                                 data-start-date="<?= htmlspecialchars($fecha_inicio_dashboard) ?>" data-end-date="<?= htmlspecialchars($fecha_fin_dashboard) ?>">
                                <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dayname): ?><div class="calendar-weekday"><?= htmlspecialchars($dayname) ?></div><?php endforeach; ?>
                                <?php for ($idx=0;$idx<42;$idx++):
                                    $fechaCelda=$tx_calendar_grid_start->modify('+'.$idx.' days');
                                    $fechaISO=$fechaCelda->format('Y-m-d');
                                    $celdaClasses=[];
                                    if ((int)$fechaCelda->format('n') !== $mes_actual) $celdaClasses[]='adjacent-month';
                                    if ($fechaISO === $fecha_inicio_dashboard) $celdaClasses[]='selected-start';
                                    if ($fechaISO === $fecha_fin_dashboard) $celdaClasses[]='selected-end';
                                    if ($fechaISO > $fecha_inicio_dashboard && $fechaISO < $fecha_fin_dashboard) $celdaClasses[]='in-range';
                                    if ($fechaISO > $tx_calendar_max_date) $celdaClasses[]='disabled-date';
                                ?>
                                    <button class="calendar-day <?= htmlspecialchars(implode(' ',$celdaClasses)) ?>" type="button"
                                        data-date="<?= $fechaISO ?>" <?= $fechaISO > $tx_calendar_max_date ? 'disabled' : '' ?>><?= (int)$fechaCelda->format('j') ?></button>
                                <?php endfor; ?>
                            </div>
                            <div class="range-summary" id="dashRangeSummary">Rango seleccionado: <?= htmlspecialchars($fecha_inicio_dashboard.' – '.$fecha_fin_dashboard) ?></div>
                            <div class="range-actions">
                                <a class="tx-nav-full-btn" href="<?= tx_dashboard_link(['periodo'=>'mensual','rango_mode'=>'completo','fecha_inicio'=>null,'fecha_fin'=>null,'dia_inicio'=>null,'dia_fin'=>null]) ?>">Mes completo</a>
                                <button type="submit">Aplicar</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php if ($puede_mes_next): ?><a class="tx-nav-button" href="<?= tx_dashboard_link(['periodo'=>'mensual','anio'=>$anio_next,'mes'=>$mes_next,'rango_mode'=>'mtd','fecha_inicio'=>null,'fecha_fin'=>null,'dia_inicio'=>null,'dia_fin'=>null]) ?>"><?= htmlspecialchars($meses_es[$mes_next]) ?> →</a>
                <?php else: ?><span class="tx-nav-button is-disabled"><?= htmlspecialchars($meses_es[$mes_next]) ?> →</span><?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php if ($periodo === 'semanal' || $tx_dashboard_cruza_mes): ?>
        <div class="tx-nav-info">Los indicadores operativos consultan las fechas seleccionadas. Las tendencias desde enero se mantienen como referencia mensual histórica.</div>
    <?php endif; ?>

    <section class="hierarchy-card">
        <div class="hierarchy-head">
            <div>
                <div class="hierarchy-title">Vista jerárquica</div>
                <div class="hierarchy-current">
                    <?php if ($scope_activo): ?>
                        Viendo tablero de <?= htmlspecialchars($nombre_completo_scope) ?> · <?= htmlspecialchars($posicion_scope) ?> · <?= htmlspecialchars($distrito_scope) ?>
                    <?php else: ?>
                        Vista inicial de tu posición
                    <?php endif; ?>
                </div>
                <div class="hierarchy-note">Puedes consultar cualquier nivel permitido por tu línea de reporte. Directores distritales se calculan por distrito para cuadrar contra el tablero inicial.</div>
            </div>
        </div>

        <form method="get" class="hierarchy-form">
            <?php foreach ($_GET as $k => $v): ?>
                <?php if (!in_array($k, ['scope_pos'], true)): ?>
                    <?php if (is_array($v)): ?>
                        <?php foreach ($v as $v_item): ?>
                            <input type="hidden" name="<?= htmlspecialchars($k) ?>[]" value="<?= htmlspecialchars((string)$v_item) ?>">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <select name="scope_pos" class="hierarchy-select">
                <option value="">Mi tablero / vista inicial</option>
                <?php foreach ($jerarquia_opciones as $nivel_op => $opciones): ?>
                    <?php if (!empty($opciones)): ?>
                        <optgroup label="<?= htmlspecialchars(label_nivel_dashboard($nivel_op)) ?>">
                            <?php foreach ($opciones as $op): ?>
                                <option value="<?= htmlspecialchars($op['id_posicion']) ?>" <?= ($scope_pos === $op['id_posicion']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($op['distrito']) ?> · <?= htmlspecialchars($op['nombre_colaborador']) ?> · <?= htmlspecialchars($op['posicion']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="hierarchy-btn">Ver tablero</button>
            <?php if ($scope_activo): ?>
                <?php
                    $qs_clear_scope = $_GET;
                    unset($qs_clear_scope['scope_pos']);
                ?>
                <a class="hierarchy-clear" href="?<?= htmlspecialchars(http_build_query($qs_clear_scope)) ?>">Regresar a mi vista</a>
            <?php endif; ?>
        </form>
    </section>

    <div class="kpi-grid-main <?= $layout_hc_top ? 'with-hc' : 'without-hc' ?>">

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
                    <span class="kpi-sub"><?= (($meta_propia_operativa_dashboard ?? 0) > 0 && !$scope_filtrar_por_distrito && !$por_distrito) ? "Meta EO" : ("Meta (Día " . $dias_transcurridos . ")") ?></span>
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


        <?php if ($layout_hc_top): ?>
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

        <?php endif; ?>


    </div>


    <div class="dashboard-analytics-grid <?= $layout_hc_bottom ? 'with-hc-bottom' : 'with-channel' ?>">

    <?php if ($tx_stg_aplicable && !$tx_stg_ok && !(!$scope_activo && in_array($rol, ['admin','director_regional'], true))): ?>
      <div class="evo-card hierarchy-performance-card cumplimiento-panel">
        <div class="chart-title">Cumplimiento del nivel inferior vs meta</div>
        <div style="margin-top:14px;color:#b91c1c;font-size:13px;">Datos HC no disponibles en STAGING. Consulta de motores fallida; revisa error_log. No se amplía el alcance del perfil.</div>
      </div>
    <?php elseif (!empty($cumplimiento_inferior_items)): ?>
    <div class="evo-card hierarchy-performance-card cumplimiento-panel">
        <div class="hierarchy-performance-head">
            <div>
                <div class="chart-title"><?= htmlspecialchars($cumplimiento_inferior_titulo) ?></div>
                <div class="hierarchy-performance-sub"><?= htmlspecialchars($cumplimiento_inferior_subtitulo) ?></div>
            </div>
            <div class="hierarchy-performance-note">Ordenado por % de cumplimiento</div>
        </div>

        <div class="cumplimiento-list">
            <?php foreach ($cumplimiento_inferior_items as $item): ?>
                <?php
                    $pct_item = (float)($item['pct'] ?? 0);
                    $visual_pct_item = (float)($item['visual_pct'] ?? $pct_item);
                    $real_item = (int)($item['real'] ?? 0);
                    $meta_item = (int)($item['meta'] ?? 0);
                    $sin_meta_item = (($item['fuente'] ?? '') === 'sin_meta');
                    $bar_width = min($visual_pct_item, 100);
                    $bar_class = $sin_meta_item ? 'neutral' : (($pct_item >= 100) ? 'ok' : (($pct_item >= 80) ? 'warn' : 'risk'));
                ?>
                <div class="cumplimiento-row">
                    <div class="cumplimiento-name" title="<?= htmlspecialchars($item['nombre'] ?? '') ?>">
                        <?= htmlspecialchars($item['nombre'] ?? '') ?>
                    </div>
                    <div class="cumplimiento-bar-block">
                        <div class="cumplimiento-track">
                            <div class="cumplimiento-fill <?= $bar_class ?>" style="width:<?= $bar_width ?>%;"></div>
                            <?php if (($item['mix_total'] ?? 0) > 0 && ($item['res_pct'] ?? null) !== null && (int)$item['res_pct'] > 0 && (int)$item['res_pct'] < 100): ?>
                                <span class="cumplimiento-resneg-divider" style="left:<?= (int)$item['res_pct'] ?>%;"></span>
                            <?php endif; ?>
                        </div>
                        <div class="cumplimiento-mix">
                            <?php if (($item['mix_total'] ?? 0) > 0 && ($item['res_pct'] ?? null) !== null): ?>
                                🏠 Res <?= number_format((int)$item['res_pct']) ?>% &nbsp;&nbsp; 🏢 Neg <?= number_format((int)$item['neg_pct']) ?>%
                            <?php else: ?>
                                🏠 Res -- &nbsp;&nbsp; 🏢 Neg --
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cumplimiento-metric">
                        <?php if ($sin_meta_item): ?>
                            <?= number_format($real_item) ?>
                            <span class="cumplimiento-sub">Sin meta</span>
                        <?php else: ?>
                            <?= number_format($pct_item, 0) ?>%
                            <span class="cumplimiento-sub"><?= number_format($real_item) ?> / <?= number_format($meta_item) ?><?= (($item['fuente'] ?? '') === 'operativa') ? ' · EO' : '' ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($layout_hc_bottom): ?>
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

    <?php endif; ?>

    <?php if ($mostrar_cumplimiento_canal && !empty($cumplimiento_canal_items)): ?>
    <div class="evo-card hierarchy-performance-card cumplimiento-panel cumplimiento-panel-canal">
        <div class="hierarchy-performance-head">
            <div>
                <div class="chart-title">Cumplimiento por canal de venta</div>
                <div class="hierarchy-performance-sub">Canales · Venta instalada vs Meta</div>
            </div>
            <div class="hierarchy-performance-note">Ordenado por % de cumplimiento</div>
        </div>

        <div class="cumplimiento-list">
            <?php foreach ($cumplimiento_canal_items as $item): ?>
                <?php
                    $pct_item = (float)($item['pct'] ?? 0);
                    $visual_pct_item = (float)($item['visual_pct'] ?? $pct_item);
                    $real_item = (int)($item['real'] ?? 0);
                    $meta_item = (int)($item['meta'] ?? 0);
                    $bar_width = min($visual_pct_item, 100);
                    $bar_class = ($pct_item >= 100) ? 'ok' : (($pct_item >= 80) ? 'warn' : 'risk');
                ?>
                <div class="cumplimiento-row">
                    <div class="cumplimiento-name" title="<?= htmlspecialchars($item['nombre'] ?? '') ?>">
                        <?= htmlspecialchars($item['nombre'] ?? '') ?>
                    </div>
                    <div class="cumplimiento-bar-block">
                        <div class="cumplimiento-track">
                            <div class="cumplimiento-fill <?= $bar_class ?>" style="width:<?= $bar_width ?>%;"></div>
                            <?php if (($item['mix_total'] ?? 0) > 0 && ($item['res_pct'] ?? null) !== null && (int)$item['res_pct'] > 0 && (int)$item['res_pct'] < 100): ?>
                                <span class="cumplimiento-resneg-divider" style="left:<?= (int)$item['res_pct'] ?>%;"></span>
                            <?php endif; ?>
                        </div>
                        <div class="cumplimiento-mix">
                            <?php if (($item['mix_total'] ?? 0) > 0 && ($item['res_pct'] ?? null) !== null): ?>
                                🏠 Res <?= number_format((int)$item['res_pct']) ?>% &nbsp;&nbsp; 🏢 Neg <?= number_format((int)$item['neg_pct']) ?>%
                            <?php else: ?>
                                🏠 Res -- &nbsp;&nbsp; 🏢 Neg --
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cumplimiento-metric">
                        <?= number_format($pct_item, 0) ?>%
                        <span class="cumplimiento-sub"><?= number_format($real_item) ?> / <?= number_format($meta_item) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>


        <div class="chart-card arpu-card">
            <div class="chart-title"><?= ($tx_evo_daily_custom ? 'ARPU — Evolución diaria del rango seleccionado' : ($periodo === 'semanal' && $tx_evo_week_keys ? 'ARPU — Tendencia semanal ISO desde S01 de 2026' : ($tx_stg_aplicable ? 'ARPU — Tendencia desde enero 2026' : 'ARPU — Tendencia 6 meses'))) ?></div>
            <div class="hierarchy-performance-sub" style="margin-top:2px;"><?= $tx_stg_aplicable ? 'Motor Comercial · Precio lista sin IVA · '.htmlspecialchars($tx_stg_ambito_comercial_label,ENT_QUOTES,'UTF-8') : ($periodo === 'semanal' && $tx_evo_week_keys ? 'Motor Comercial · Precio lista sin IVA · Folios autorizados' : 'Mismo rango seleccionado · precio pronto pago') ?></div>
            <?php if ($tx_stg_aplicable && $tx_stg_ok && count($arpu_labels) > 0):
                $tx_arpu_last = count($arpu_labels)-1;
                $tx_arpu_last_value = $arpu_data[$tx_arpu_last] ?? null;
                $tx_arpu_last_label = $tx_evo_daily_custom ? (new DateTimeImmutable($fecha_fin_dashboard))->format('d/m/Y') : (($periodo === 'semanal' && $tx_evo_week_keys)
                    ? (($meses_labels[$tx_arpu_last] ?? '') ?: ($arpu_labels[$tx_arpu_last] ?? ''))
                    : ($arpu_labels[$tx_arpu_last] ?? ''));
            ?>
                <div class="tx-arpu-ultimo" aria-label="ARPU del último período seleccionado">
                    <span><?= $tx_evo_daily_custom ? 'Último día' : ($periodo === 'semanal' ? 'Última semana' : 'Último mes') ?> · <?= htmlspecialchars((string)$tx_arpu_last_label,ENT_QUOTES,'UTF-8') ?></span>
                    <strong><?= $tx_arpu_last_value !== null && $tx_arpu_last_value > 0 ? '$'.number_format((float)$tx_arpu_last_value,0) : 'Sin precio válido' ?></strong>
                </div>
            <?php endif; ?>
            <?php if ($tx_stg_gerencial && $tx_stg_ok && $periodo !== 'semanal' && !$tx_dashboard_cruza_mes && ($tx_stg_auditoria_arpu['validos'] ?? 0) < $tx_stg_universo_mes): ?>
                <div style="font-size:10px;color:#64748b;padding:3px 0;">ARPU: <?= number_format((int)($tx_stg_auditoria_arpu['validos'] ?? 0)) ?> instalaciones con precio válido de <?= number_format((int)$tx_stg_universo_mes) ?>.</div>
            <?php endif; ?>
            <?php if ($tx_stg_aplicable && $tx_stg_error !== null): ?>
                <div style="font-size:12px;color:#b91c1c;padding:8px 0;">Indicador no disponible: revisar el registro de errores de STAGING. No se muestran valores incompletos.</div>
            <?php endif; ?>
            <div class="tx-historical-scroll<?= $periodo === 'semanal' ? ' tx-weekly-scroll' : '' ?>" id="txArpuScroll"><div class="chart-wrap tx-weekly-arpu-inner"<?= ($tx_stg_aplicable && !$tx_stg_ok) ? ' style="display:none"' : '' ?>><canvas id="cArpuHist"></canvas></div></div>
        </div>

        <div class="chart-card">
            <div class="chart-title">Mix 2P y 3P — Instalaciones<?= ($periodo === 'semanal' ? ' · Semana seleccionada' : (($tx_dashboard_cruza_mes || $tx_evo_daily_custom) ? ' · Rango seleccionado' : '')) ?></div>
            <?php if ($tx_stg_aplicable): ?>
                <div class="hierarchy-performance-sub" style="margin-top:2px;">Motor Comercial · <?= htmlspecialchars($tx_stg_ambito_comercial_label,ENT_QUOTES,'UTF-8') ?> · <?= number_format((int)$tx_stg_universo_mes) ?> instalaciones</div>
                <?php if ($tx_stg_error !== null): ?>
                    <div style="font-size:12px;color:#b91c1c;padding:8px 0;">Indicador no disponible: revisar errores STAGING.</div>
                <?php elseif ($tx_stg_mix_sin_clasificar > 0): ?>
                    <div style="font-size:11px;color:#64748b;padding:4px 0;">Sin clasificar: <?= number_format((int)$tx_stg_mix_sin_clasificar) ?> instalación(es); se muestran separadas.</div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="chart-wrap"<?= ($tx_stg_aplicable && !$tx_stg_ok) ? ' style="display:none"' : '' ?>><canvas id="cInstMix"></canvas></div>
        </div>

    </div>

    <?php
        $qs_evo_canal = $_GET;
        $qs_evo_canal['evo_view'] = 'canal';
        $qs_evo_oferta = $_GET;
        $qs_evo_oferta['evo_view'] = 'oferta';
        $qs_evo_play = $_GET;
        $qs_evo_play['evo_view'] = 'play';
        $qs_evo_bundle = $_GET;
        $qs_evo_bundle['evo_view'] = 'bundle';
    ?>
    <div class="evo-card">
        <div class="evo-card-head">
            <div class="chart-title" id="txEvoTitle"><?= htmlspecialchars($evo_titulo) ?></div>
            <div class="evo-toggle" aria-label="Selector de vista de evolución">
                <a class="tx-evo-toggle-link <?= $evo_view === 'canal' ? 'active' : '' ?>" data-evo-view="canal" href="?<?= htmlspecialchars(http_build_query($qs_evo_canal)) ?>">CANAL DE VENTA</a>
                <a class="tx-evo-toggle-link <?= $evo_view === 'oferta' ? 'active' : '' ?>" data-evo-view="oferta" href="?<?= htmlspecialchars(http_build_query($qs_evo_oferta)) ?>">OFERTA RES-NEG</a>
                <?php if (!$tx_stg_es_vendedor && $tx_evo_desde_enero): ?>
                    <a class="tx-evo-toggle-link <?= $evo_view === 'play' ? 'active' : '' ?>" data-evo-view="play" href="?<?= htmlspecialchars(http_build_query($qs_evo_play)) ?>">PLAYS</a>
                    <a class="tx-evo-toggle-link <?= $evo_view === 'bundle' ? 'active' : '' ?>" data-evo-view="bundle" href="?<?= htmlspecialchars(http_build_query($qs_evo_bundle)) ?>">BUNDLE</a>
                <?php endif; ?>
            </div>
        </div>
        <div id="txEvoSourceNote" class="tx-evo-source-note" style="<?= ($tx_evo_desde_enero && $evo_view !== 'canal') ? '' : 'display:none;' ?>">Motor Comercial STAGING · Clasificación exacta de <code>catalogo_paquetes</code> · Todos los canales dentro del alcance autorizado · Incluye «Sin clasificar» cuando corresponde. Totales por registro según alcance autorizado; en vista SEMANAL, semana ISO 01 incluye los días de diciembre del año natural anterior. En rango personalizado se muestran únicamente los días seleccionados.</div>
        <div id="txEvoError" class="tx-evo-error" style="<?= ($tx_evo_desde_enero && $evo_view !== 'canal' && !$tx_evo_fuente_catalogo_ok) ? '' : 'display:none;' ?>">No se pudo verificar la clasificación comercial. Consulta el log STAGING; no se muestran resultados estimados.</div>
        <div class="evo-grid">
            <div>
                <div class="evo-sub" id="txEvoVentSub"><?= htmlspecialchars($evo_vent_sub) ?></div>
                <div class="tx-historical-scroll<?= $periodo === 'semanal' ? ' tx-weekly-scroll' : '' ?>" id="txVentEvoScroll"><div class="evo-wrap tx-weekly-evo-inner"><canvas id="cVentEvo"></canvas></div></div>
            </div>
            <div>
                <div class="evo-sub" id="txEvoInstSub"><?= htmlspecialchars($evo_inst_sub) ?></div>
                <div class="tx-historical-scroll<?= $periodo === 'semanal' ? ' tx-weekly-scroll' : '' ?>" id="txInstEvoScroll"><div class="evo-wrap tx-weekly-evo-inner"><canvas id="cInstEvo"></canvas></div></div>
            </div>
        </div>
    </div>


    <!-- TABLA DE PARTICIPACIÓN POR CANAL -->
    <?php if (!empty($datos_inst_stacked) || !empty($datos_vent_stacked) || $tx_evo_desde_enero): ?>
    <div class="evo-card" id="txEvoParticipationCard" style="margin-top:20px;">
        <div class="chart-title" id="txEvoParticipationTitle"><?= htmlspecialchars($evo_participacion_titulo) ?></div>
        <div class="evo-grid tx-participacion-grid" style="margin-top:16px;">
            <!-- VENTAS -->
            <div>
                <div class="tx-participacion-nav">
                    <div class="evo-sub" id="txEvoParticipationVentSub"><?= htmlspecialchars($evo_vent_sub) ?></div>
                    <div class="tx-participacion-nav-controls" aria-label="Navegar los períodos de ventas">
                        <button type="button" class="tx-participacion-arrow" data-scroll-table="vent" data-scroll-dir="-1" aria-label="Ver períodos anteriores en Ventas" title="Meses anteriores">&#8592;</button>
                        <span class="tx-participacion-nav-label">Desplazar <?= $tx_evo_daily_custom ? 'días' : ($periodo === 'semanal' ? 'semanas' : 'meses') ?></span>
                        <button type="button" class="tx-participacion-arrow" data-scroll-table="vent" data-scroll-dir="1" aria-label="Ver períodos siguientes en Ventas" title="Meses siguientes">&#8594;</button>
                    </div>
                </div>
                <div class="top-productividad-table-wrap tx-evo-table-scroll" id="txEvoVentScroll" tabindex="0" role="region" aria-label="Matriz de participación de ventas, desplázate horizontalmente para consultar todos los períodos">
                <table class="tx-evo-participacion-table" id="txEvoVentTable" style="width:100%;border-collapse:collapse;font-size:0.75rem;">
                    <thead id="txEvoVentThead">
                        <tr>
                            <th style="text-align:left;padding:7px 10px;background:#e8f0fe;color:#000;border-radius:6px 0 0 0;font-size:0.7rem;"><?= htmlspecialchars($evo_col_label) ?></th>
                            <?php foreach ($meses_labels as $idx_mes => $ml): ?>
                            <th style="text-align:center;padding:7px 8px;background:#e8f0fe;color:#000;font-size:0.7rem;">
                                <a href="<?= dashboard_sort_url('sort_vent_mes','sort_vent_dir',$idx_mes,$sort_vent_idx,$sort_vent_dir) ?>" style="color:#000;text-decoration:none;display:inline-flex;gap:4px;align-items:center;justify-content:center;font-weight:700;">
                                    <?= htmlspecialchars($ml) ?> <span><?= dashboard_sort_arrow($idx_mes,$sort_vent_idx,$sort_vent_dir) ?></span>
                                </a>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="txEvoVentTbody">
                    <?php foreach ($datos_vent_table as $canal => $vals):
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
                <div class="tx-participacion-nav">
                    <div class="evo-sub" id="txEvoParticipationInstSub"><?= htmlspecialchars($evo_inst_sub) ?></div>
                    <div class="tx-participacion-nav-controls" aria-label="Navegar los períodos de instalaciones">
                        <button type="button" class="tx-participacion-arrow" data-scroll-table="inst" data-scroll-dir="-1" aria-label="Ver períodos anteriores en Instalaciones" title="Meses anteriores">&#8592;</button>
                        <span class="tx-participacion-nav-label">Desplazar <?= $tx_evo_daily_custom ? 'días' : ($periodo === 'semanal' ? 'semanas' : 'meses') ?></span>
                        <button type="button" class="tx-participacion-arrow" data-scroll-table="inst" data-scroll-dir="1" aria-label="Ver períodos siguientes en Instalaciones" title="Meses siguientes">&#8594;</button>
                    </div>
                </div>
                <div class="top-productividad-table-wrap tx-evo-table-scroll" id="txEvoInstScroll" tabindex="0" role="region" aria-label="Matriz de participación de instalaciones, desplázate horizontalmente para consultar todos los períodos">
                <table class="tx-evo-participacion-table" id="txEvoInstTable" style="width:100%;border-collapse:collapse;font-size:0.75rem;">
                    <thead id="txEvoInstThead">
                        <tr>
                            <th style="text-align:left;padding:7px 10px;background:#e8f0fe;color:#000;border-radius:6px 0 0 0;font-size:0.7rem;"><?= htmlspecialchars($evo_col_label) ?></th>
                            <?php foreach ($meses_labels as $idx_mes => $ml): ?>
                            <th style="text-align:center;padding:7px 8px;background:#e8f0fe;color:#000;font-size:0.7rem;">
                                <a href="<?= dashboard_sort_url('sort_inst_mes','sort_inst_dir',$idx_mes,$sort_inst_idx,$sort_inst_dir) ?>" style="color:#000;text-decoration:none;display:inline-flex;gap:4px;align-items:center;justify-content:center;font-weight:700;">
                                    <?= htmlspecialchars($ml) ?> <span><?= dashboard_sort_arrow($idx_mes,$sort_inst_idx,$sort_inst_dir) ?></span>
                                </a>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="txEvoInstTbody">
                    <?php foreach ($datos_inst_table as $canal => $vals):
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


    <!-- TOP REGIONAL PRODUCTIVIDAD COACHES -->
    <?php if (!empty($top_productividad_coaches) || !empty($top_offender_coaches) || $tx_coaches_motor_error !== null): ?>
    <div class="top-productividad-grid">
        <?php
            $renderTopCoachTable = function($titulo, $subtitulo, $items, $extraClass = '', $mensajeVacio = 'Sin datos para el rango seleccionado.') {
        ?>
        <div class="evo-card top-productividad-card <?= $extraClass ?>">
            <div class="top-productividad-head">
                <div>
                    <div class="top-productividad-title"><?= htmlspecialchars($titulo) ?></div>
                    <div class="top-productividad-sub"><?= htmlspecialchars($subtitulo) ?></div>
                </div>
                <div class="hierarchy-performance-note">TOP REGIONAL · <?= (int)$GLOBALS['dias_productividad_vendedor'] ?> días hábiles</div>
            </div>
            <div class="top-productividad-table-wrap">
                <table class="top-productividad-table">
                    <thead>
                        <tr>
                            <th style="width:34px;">#</th>
                            <th>Nombre Coach</th>
                            <th>Distrito</th>
                            <th>Líder</th>
                            <th style="text-align:right;">Ventas instaladas</th>
                            <th style="text-align:right;">Prod.</th>
                            <th style="text-align:right;">ARPU</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $row): ?>
                        <?php
                            $spark = $row['spark'] ?? [];
                            $sparkMax = max(1, !empty($spark) ? max($spark) : 1);
                        ?>
                        <tr>
                            <td><span class="rank-badge"><?= $i + 1 ?></span></td>
                            <td><div class="seller-name" title="<?= htmlspecialchars($row['coach'] ?? '') ?>"><?= htmlspecialchars($row['coach'] ?? '') ?></div></td>
                            <td><span class="seller-district"><?= htmlspecialchars($row['distrito'] ?? '') ?></span></td>
                            <td><div class="seller-small" title="<?= htmlspecialchars($row['lider'] ?? '') ?>"><?= htmlspecialchars($row['lider'] ?? '') ?></div></td>
                            <td class="seller-num" title="Cuentas certificadas por Motor HC v0.6.2"><?= number_format((int)($row['instalaciones'] ?? 0)) ?></td>
                            <td class="seller-num">
                                <div class="seller-prod">
                                    <strong title="<?= htmlspecialchars(number_format((int)($row['instalaciones'] ?? 0)).' INS / '.number_format((int)($row['hc_activo'] ?? 0)).' HC activo / '.(int)$GLOBALS['dias_productividad_vendedor'].' días hábiles', ENT_QUOTES, 'UTF-8') ?>"><?= number_format((float)($row['productividad'] ?? 0), 2) ?></strong>
                                    <div class="sparkline" title="Tendencia últimas 6 semanas">
                                        <?php foreach ($spark as $sv): ?>
                                            <span style="height:<?= max(3, round(((int)$sv / $sparkMax) * 16)) ?>px;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="seller-num">$<?= number_format((float)($row['arpu'] ?? 0), 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#6b7a99;padding:18px;"><?= htmlspecialchars($mensajeVacio, ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php }; ?>

        <?php $renderTopCoachTable(
            'TOP Five Coaches',
            'Motor HC · Mejor PROD. · Piso mínimo '.number_format($tx_piso_min_top_coaches).' INS ('.$tx_dias_cal_top_coaches.' días)',
            $top_productividad_coaches,
            'top-productividad',
            ($tx_coaches_motor_error !== null ? 'Sin datos certificados: revisar el log del motor HC.' : 'Ningún coach cumple el piso mínimo de '.number_format($tx_piso_min_top_coaches).' instalaciones para este rango.')
        ); ?>
        <?php $renderTopCoachTable('BOTTOM Five Coaches', 'Motor HC · Menor PROD. regional de coaches de venta', $top_offender_coaches, 'top-offender', $tx_coaches_motor_error !== null ? 'Sin datos certificados: revisar el log del motor HC.' : 'Sin coaches activos para este corte.'); ?>
    </div>
    <?php endif; ?>


    <!-- TOP REGIONAL PRODUCTIVIDAD VENDEDORES -->
    <?php if (!empty($top_productividad_vendedores) || !empty($top_offender_vendedores)): ?>
    <div class="top-productividad-grid">
        <?php
            $renderTopTable = function($titulo, $subtitulo, $items, $extraClass = '') {
        ?>
        <div class="evo-card top-productividad-card <?= $extraClass ?>">
            <div class="top-productividad-head">
                <div>
                    <div class="top-productividad-title"><?= htmlspecialchars($titulo) ?></div>
                    <div class="top-productividad-sub"><?= htmlspecialchars($subtitulo) ?></div>
                </div>
                <div class="hierarchy-performance-note">TOP REGIONAL · <?= (int)$GLOBALS['dias_productividad_vendedor'] ?> días hábiles</div>
            </div>
            <div class="top-productividad-table-wrap">
                <table class="top-productividad-table">
                    <thead>
                        <tr>
                            <th style="width:34px;">#</th>
                            <th>Nombre vendedor</th>
                            <th>Distrito</th>
                            <th>Coach</th>
                            <th style="text-align:right;">Ventas instaladas</th>
                            <th style="text-align:right;">Prod.</th>
                            <th style="text-align:right;">ARPU</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $row): ?>
                        <?php
                            $spark = $row['spark'] ?? [];
                            $sparkMax = max(1, !empty($spark) ? max($spark) : 1);
                        ?>
                        <tr>
                            <td><span class="rank-badge"><?= $i + 1 ?></span></td>
                            <td><div class="seller-name" title="<?= htmlspecialchars($row['vendedor'] ?? '') ?>"><?= htmlspecialchars($row['vendedor'] ?? '') ?></div></td>
                            <td><span class="seller-district"><?= htmlspecialchars($row['distrito'] ?? '') ?></span></td>
                            <td><div class="seller-small" title="<?= htmlspecialchars($row['coach'] ?? '') ?>"><?= htmlspecialchars($row['coach'] ?? '') ?></div></td>
                            <td class="seller-num"><?= number_format((int)($row['instalaciones'] ?? 0)) ?></td>
                            <td class="seller-num">
                                <div class="seller-prod">
                                    <strong><?= number_format((float)($row['productividad'] ?? 0), 2) ?></strong>
                                    <div class="sparkline" title="Tendencia últimas 6 semanas">
                                        <?php foreach ($spark as $sv): ?>
                                            <span style="height:<?= max(3, round(((int)$sv / $sparkMax) * 16)) ?>px;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="seller-num">$<?= number_format((float)($row['arpu'] ?? 0), 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#6b7a99;padding:18px;">Sin datos para el rango seleccionado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php }; ?>

        <?php $renderTopTable('TOP Regional Vendedor', 'Mejor PROD. regional del rango seleccionado', $top_productividad_vendedores, 'top-productividad'); ?>
        <?php $renderTopTable('BOTTOM Regional Vendedor', '0 instalaciones · Productividad 3M más baja', $top_offender_vendedores, 'top-offender'); ?>
    </div>
    <?php endif; ?>



    <!-- GEORREFERENCIA TOTALXPEDIENT -->
    <section class="geo-card">
        <div class="geo-header">
            <div>
                <div class="geo-title">Georreferencia TotalXpedient</div>
                <div class="geo-subtitle">Mapa de calor de instalaciones · <?= htmlspecialchars($dashboard_fecha_label) ?></div>
            </div>
            <div class="geo-subtitle">
                <?= $scope_activo ? 'Vista jerárquica seleccionada' : 'Vista actual del dashboard' ?>
            </div>
        </div>

        <?php if ($tx_geo_mostrar_filtro_canales && !empty($tx_geo_canales_disponibles)): ?>
        <form method="get" class="geo-filter">
            <?php foreach ($_GET as $gk => $gv): ?>
                <?php if ($gk === 'geo_canales') continue; ?>
                <?php if (is_array($gv)): ?>
                    <?php foreach ($gv as $gv_item): ?>
                        <input type="hidden" name="<?= htmlspecialchars($gk) ?>[]" value="<?= htmlspecialchars((string)$gv_item) ?>">
                    <?php endforeach; ?>
                <?php else: ?>
                    <input type="hidden" name="<?= htmlspecialchars($gk) ?>" value="<?= htmlspecialchars((string)$gv) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="geo-filter-top">
                <div>
                    <div class="geo-filter-title">Filtrar mapa por canal de venta</div>
                    <div class="geo-subtitle" style="margin-top:3px;text-transform:none;letter-spacing:.03em;">
                        <?= empty($tx_geo_canales_sel) ? 'Mostrando todos los canales disponibles' : (count($tx_geo_canales_sel) . ' canal(es) seleccionado(s)') ?>
                    </div>
                </div>
                <div class="geo-filter-actions">
                    <button type="submit" class="geo-filter-btn">Aplicar filtro</button>
                    <?php
                        $qs_geo_clear = $_GET;
                        unset($qs_geo_clear['geo_canales']);
                    ?>
                    <a class="geo-filter-clear" href="?<?= htmlspecialchars(http_build_query($qs_geo_clear)) ?>">Todos los canales</a>
                </div>
            </div>
            <div class="geo-channel-pills">
                <?php foreach ($tx_geo_canales_disponibles as $canal_geo): ?>
                    <?php $is_checked_geo = in_array($canal_geo, $tx_geo_canales_sel, true); ?>
                    <label class="geo-channel-pill <?= $is_checked_geo ? 'active' : '' ?>">
                        <input type="checkbox" name="geo_canales[]" value="<?= htmlspecialchars($canal_geo) ?>" <?= $is_checked_geo ? 'checked' : '' ?>>
                        <?= htmlspecialchars($canal_geo) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </form>
        <?php endif; ?>

        <div class="geo-kpis">
            <div class="geo-kpi">
                <span>Ventas consideradas</span>
                <strong><?= number_format((int)$tx_geo_ventas_periodo) ?></strong>
                <small><?= empty($tx_geo_canales_sel) ? 'Universo de ventas del periodo' : 'Ventas según canal seleccionado' ?></small>
            </div>
            <div class="geo-kpi">
                <span>Instalaciones del periodo</span>
                <strong><?= number_format((int)$tx_geo_inst_periodo) ?></strong>
                <small><?= empty($tx_geo_canales_sel) ? 'Base contra conversión' : 'Base filtrada por canal' ?></small>
            </div>
            <div class="geo-kpi">
                <span>Instalaciones georreferenciadas</span>
                <strong><?= number_format((int)$tx_geo_total) ?></strong>
                <small><?= number_format((float)$tx_geo_cobertura_pct, 1) ?>% con coordenada válida</small>
            </div>
            <div class="geo-kpi">
                <span>Puntos agrupados en mapa</span>
                <strong><?= number_format(count($tx_geo_marker_points)) ?></strong>
                <small>Coordenadas visibles agrupadas</small>
            </div>
            <div class="geo-kpi">
                <span>Zona más caliente</span>
                <strong><?= htmlspecialchars($tx_geo_zona_caliente) ?></strong>
                <small><?= number_format((int)$tx_geo_zona_caliente_total) ?> instalaciones</small>
            </div>
            <div class="geo-kpi">
                <span>Sin coordenada válida</span>
                <strong><?= number_format((int)$tx_geo_invalidas) ?></strong>
                <small>Registros no mapeados</small>
            </div>
        </div>

        <?php if (!empty($tx_geo_heat_points)): ?>
            <div class="geo-map-wrap">
                <div id="txGeoMap"></div>
                <div class="geo-kmz-badge">Cobertura <span class="geo-kmz-line"></span> Distrito</div>
                <div class="geo-legend">Menor concentración <span class="geo-gradient"></span> Mayor concentración</div>
            </div>
        <?php else: ?>
            <div class="geo-empty">
                No hay instalaciones con latitud/longitud válida para el rango y jerarquía seleccionados.
            </div>
        <?php endif; ?>
    </section>


</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

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




// --- GEORREFERENCIA TOTALXPEDIENT ---
const txGeoHeatPoints = <?= json_encode($tx_geo_heat_points, JSON_NUMERIC_CHECK) ?>;
const txGeoMarkerPoints = <?= json_encode($tx_geo_marker_points, JSON_NUMERIC_CHECK) ?>;
const txGeoCentro = <?= json_encode($tx_geo_centro, JSON_NUMERIC_CHECK) ?>;
const txGeoZoom = <?= (int)$tx_geo_zoom ?>;
const txGeoKmzLayers = [
    { name: 'Cobertura Mérida', url: 'assets/kmz/MERIDA.geojson' },
    { name: 'Cobertura Cancún', url: 'assets/kmz/CANCUN.geojson' },
    { name: 'Cobertura Tuxtla', url: 'assets/kmz/TUXTLA.geojson' },
    { name: 'Cobertura Villahermosa', url: 'assets/kmz/VILLAHERMOSA.geojson' },
    { name: 'Cobertura Coatza / Mina', url: 'assets/kmz/COATZA_MINA.geojson' }
];

if (document.getElementById('txGeoMap') && typeof L !== 'undefined') {
    const txGeoMap = L.map('txGeoMap', {
        scrollWheelZoom: false
    }).setView(txGeoCentro, txGeoZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(txGeoMap);

    const txGeoOverlayMaps = {};
    const txGeoOverlayControl = L.control.layers(null, txGeoOverlayMaps, { collapsed: false, position: 'topright' }).addTo(txGeoMap);

    txGeoKmzLayers.forEach(layerInfo => {
        const overlayGroup = L.layerGroup().addTo(txGeoMap);
        txGeoOverlayControl.addOverlay(overlayGroup, layerInfo.name);

        fetch(layerInfo.url, { cache: 'no-store' })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (!data) return;
                const geoJsonLayer = L.geoJSON(data, {
                    style: {
                        color: '#7A2BFF',
                        weight: 1.4,
                        opacity: 0.9,
                        fillColor: '#7A2BFF',
                        fillOpacity: 0.10
                    },
                    onEachFeature: function(feature, layer) {
                        const nombre = feature && feature.properties && feature.properties.name ? feature.properties.name : layerInfo.name;
                        layer.bindTooltip('<strong>' + nombre + '</strong><br>Cobertura comercial', { sticky: true, opacity: 0.95 });
                    }
                });
                geoJsonLayer.addTo(overlayGroup);
                overlayGroup.bringToBack();
            })
            .catch(() => {
                console.warn('No se pudo cargar la capa de cobertura:', layerInfo.url);
            });
    });

    if (typeof L.heatLayer !== 'undefined' && txGeoHeatPoints.length) {
        L.heatLayer(txGeoHeatPoints, {
            radius: 27,
            blur: 20,
            maxZoom: 17,
            minOpacity: 0.35
        }).addTo(txGeoMap);
    }

    txGeoMarkerPoints.slice(0, 900).forEach(p => {
        const totalGeo = Number(p.total || 0);
        const canalGeo = p.canal || 'SIN CANAL';
        const distritoGeo = p.distrito || 'SIN DISTRITO';
        const vendedorGeo = p.vendedor || 'SIN VENDEDOR';
        const coachGeo = p.coach || 'SIN COACH';
        const playGeo = p.play || 'SIN PLAN';
        const precioGeo = Number(p.precio_lista_descuento || 0);
        const precioGeoFmt = precioGeo > 0
            ? precioGeo.toLocaleString('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 })
            : 'Sin precio';
        const rangoGeo = (p.fecha_min && p.fecha_max)
            ? `${p.fecha_min}${p.fecha_min !== p.fecha_max ? ' al ' + p.fecha_max : ''}`
            : '';
        const detalleGeo =
            `Canal de venta: <strong>${canalGeo}</strong><br>` +
            `Vendedor: <strong>${vendedorGeo}</strong><br>` +
            `Coach: <strong>${coachGeo}</strong><br>` +
            `Play: <strong>${playGeo}</strong><br>` +
            `Precio lista con descuento: <strong>${precioGeoFmt}</strong>` +
            `${totalGeo > 1 ? '<br>Instalaciones agrupadas: <strong>' + totalGeo.toLocaleString('es-MX') + '</strong>' : ''}`;

        L.circleMarker([p.lat, p.lng], {
            radius: Math.min(13, 4 + totalGeo),
            weight: 1,
            fillOpacity: 0.72
        }).addTo(txGeoMap)
          .bindTooltip(detalleGeo, { sticky: true, direction: 'top', opacity: 0.98 })
          .bindPopup(
            `<strong>${distritoGeo}</strong><br>` +
            detalleGeo +
            `${rangoGeo ? '<br>Rango: <strong>' + rangoGeo + '</strong>' : ''}`
        );
    });

    if (txGeoMarkerPoints.length > 1) {
        const bounds = L.latLngBounds(txGeoMarkerPoints.map(p => [p.lat, p.lng]));
        txGeoMap.fitBounds(bounds, { padding: [24, 24], maxZoom: txGeoZoom });
    }

    setTimeout(() => txGeoMap.invalidateSize(), 250);
}


// --- DONUTS (MIX) ---
const inst2p = <?= $inst_2p ?>; const inst3p = <?= $inst_3p ?>;
const instSinClasificar = <?= ($tx_stg_aplicable && $tx_stg_ok) ? (int)$tx_stg_mix_sin_clasificar : 0 ?>;
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
    data: { labels: instSinClasificar > 0 ? ['2P','3P','Sin clasificar'] : ['2P','3P'], datasets: [{ data: instSinClasificar > 0 ? [inst2p, inst3p, instSinClasificar] : [inst2p, inst3p], backgroundColor: instSinClasificar > 0 ? [txBrandColors.azulElectrico, txBrandColors.cian, '#94a3b8'] : [txBrandColors.azulElectrico, txBrandColors.cian], borderWidth: 0 }] },
    options: donutOpts(),
    plugins: [ChartDataLabels]
});
const txWeeklyMode = <?= ($periodo === 'semanal' && (bool)$tx_evo_week_keys) ? 'true' : 'false' ?>;
const txDailyMode = <?= $tx_evo_daily_custom ? 'true' : 'false' ?>;
const txCompactMode = txWeeklyMode || txDailyMode;
const txDailyFullLabels = <?= json_encode($tx_evo_daily_dates, JSON_UNESCAPED_UNICODE) ?>;
const txArpuFullWeekLabels = <?= json_encode($meses_labels, JSON_UNESCAPED_UNICODE) ?>;
const arpuLabels = <?= json_encode($arpu_labels) ?>;
const arpuData = <?= json_encode($arpu_data) ?>;

// ARPU: gráfica de línea para visualizar tendencia.
// El eje Y NO inicia en cero; se ajusta alrededor de los valores reales:
// límite inferior = 30% abajo del mínimo, límite superior = 30% arriba del máximo.
const arpuValores = arpuData.map(v => Number(v || 0)).filter(v => v > 0);
const arpuMinReal = arpuValores.length ? Math.min(...arpuValores) : 0;
const arpuMaxReal = arpuValores.length ? Math.max(...arpuValores) : 0;
const arpuYMin = arpuMinReal > 0 ? Math.floor((arpuMinReal * 0.70) / 10) * 10 : 0;
const arpuYMax = arpuMaxReal > 0 ? Math.ceil((arpuMaxReal * 1.30) / 10) * 10 : 100;

new Chart(document.getElementById('cArpuHist'), {
    type: 'line',
    data: {
        labels: arpuLabels,
        datasets: [{
            label: 'ARPU',
            data: arpuData,
            borderColor: txBrandColors.magenta,
            backgroundColor: 'rgba(255, 0, 108, 0.10)',
            pointBackgroundColor: txBrandColors.magenta,
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: ctx => txCompactMode ? (ctx.dataIndex === arpuLabels.length-1 ? 3 : 1.4) : (arpuLabels.length > 8 ? 3 : 4),
            pointHoverRadius: 6,
            pointHitRadius: txCompactMode ? 12 : 6,
            borderWidth: 3,
            tension: 0.35,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 32, right: 32, left: 4 } },
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'top',
                offset: ctx => ctx.dataIndex === arpuLabels.length - 1 ? 9 : 5,
                clip: false,
                color: '#1a2540',
                font: { weight: '900', size: 10 },
                display: ctx => (txCompactMode
                    ? ctx.dataIndex === arpuLabels.length - 1
                    : ((arpuLabels.length - 1 - ctx.dataIndex) % 2 === 0))
                    && Number(arpuData[ctx.dataIndex] || 0) > 0,
                formatter: value => value > 0 ? '$' + Math.round(value).toLocaleString('es-MX') : ''
            },
            tooltip: {
                mode: 'index', intersect: false,
                callbacks: {
                    title: contexts => {
                        const i = contexts?.[0]?.dataIndex ?? -1;
                        return txDailyMode ? (txDailyFullLabels[i] ? txDailyFullLabels[i].split('-').reverse().join('/') : (arpuLabels[i] || '')) : (txWeeklyMode ? (txArpuFullWeekLabels[i] || arpuLabels[i] || '') : (arpuLabels[i] || ''));
                    },
                    label: ctx => ctx.parsed.y === null ? ' ARPU: sin precio válido'
                        : ' ARPU: $' + Number(ctx.parsed.y).toLocaleString('es-MX', {maximumFractionDigits: 0})
                }
            }
        },
        scales: {
            y: {
                min: arpuYMin,
                max: arpuYMax,
                grid: { color: '#e2e8f4' },
                ticks: {
                    font: { size: 10 },
                    callback: value => '$' + Number(value).toLocaleString('es-MX')
                }
            },
            x: {
                grid: { display: false },
                ticks: { font: { size: txCompactMode ? 9 : 10, weight: 'bold' },
                    minRotation: 0, maxRotation: txCompactMode ? 0 : 50,
                    autoSkip: true, maxTicksLimit: txDailyMode ? 12 : (txWeeklyMode ? 6 : 7) }
            }
        }
    },
    plugins: [ChartDataLabels]
});

// --- EVOLUCIÓN APILADA (ENERO 2026 → CORTE SELECCIONADO) ---
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
    'Residencial':                 txBrandColors.azulElectrico,
    'Negocios':                    txBrandColors.magenta,
    '2P':                          txBrandColors.azulElectrico,
    '3P':                          txBrandColors.magenta,
    'Bundle':                      txBrandColors.magenta,
    'No Bundle':                   txBrandColors.azulElectrico,
    'Sin clasificar':              '#94a3b8',
};

// ... (Obtención de datasets igual que antes) ...
const instCanales = <?= json_encode(empty($datos_inst_stacked) ? [] : array_keys($datos_inst_stacked)) ?>;
const instData    = <?= json_encode(empty($datos_inst_stacked) ? [] : array_values($datos_inst_stacked)) ?>;
const ventCanales = <?= json_encode(empty($datos_vent_stacked) ? [] : array_keys($datos_vent_stacked)) ?>;
const ventData    = <?= json_encode(empty($datos_vent_stacked) ? [] : array_values($datos_vent_stacked)) ?>;

// CACHE CLIENTE DE EVOLUCIÓN
// Las cuatro vistas se calculan una sola vez en PHP y se entregan juntas.
// Al cambiar de botón no se hace request, no se recarga el dashboard y no se recalculan KPIs.
const txEvoViewCache = <?= json_encode($tx_evo_cache_views, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const txEvoInitialView = <?= json_encode($evo_view) ?>;
const txEvoCatalogOk = <?= $tx_evo_fuente_catalogo_ok ? 'true' : 'false' ?>;
window.txEvoViewCache = txEvoViewCache;
try {
    const txEvoStorageKey = 'talia:evo:' + <?= json_encode(hash('sha256', ($rol ?? '').'|'.($scope_pos ?? '').'|'.$periodo.'|'.$fecha_inicio_dashboard.'|'.$fecha_fin_dashboard.'|'.implode(',',$tx_dashboard_dias_iso))) ?>;
    sessionStorage.setItem(txEvoStorageKey, JSON.stringify(txEvoViewCache));
} catch (e) { /* cache persistente opcional */ }

// 1. PLUGIN PARA LOS TOTALES (Con ajuste de margen superior)
const pluginTotalesArriba = {
    id: 'pluginTotalesArriba',
    afterDatasetsDraw: (chart) => {
        if (!chart.data.datasets || !chart.data.datasets.length) return;
        const ctx = chart.ctx;
        chart.data.datasets[0].data.forEach((_, index) => {
            if (txWeeklyMode && index !== chart.data.labels.length-1) return;
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
                ctx.font = (txDailyMode ? 'bold 10px' : 'bold 13px') + ' "Segoe UI", sans-serif'; 
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(total, metaX, topY - 8); 
                ctx.restore();
            }
        });
    }
};

// 2. OPCIONES DE LAS BARRAS
// FIX: ampliar el eje Y con un colchón de 10% sobre el valor mensual más alto.
// Esto evita que la etiqueta superior del mes más alto quede recortada.
function txStackTotals(matrix){
    if (!matrix || !matrix.length) return [];
    const len = Math.max(...matrix.map(row => row.length || 0));
    const totals = Array(len).fill(0);
    matrix.forEach(row => {
        row.forEach((value, idx) => {
            totals[idx] += Number(value || 0);
        });
    });
    return totals;
}

function txRoundedAxisMax(maxValue){
    const padded = Math.ceil((Number(maxValue || 0) * 1.10));
    if (padded <= 100) return 100;
    const step = padded <= 1000 ? 100 : 200;
    return Math.ceil(padded / step) * step;
}

function makeStackOpts(matrix){
    const totals = txStackTotals(matrix);
    const maxTotal = totals.length ? Math.max(...totals) : 0;
    const axisMax = txRoundedAxisMax(maxTotal);

    return {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
            padding: { top: 26 }
        },
        datasets: { bar: { categoryPercentage: txCompactMode ? 0.92 : 0.8,
            barPercentage: txCompactMode ? 0.9 : 0.9, maxBarThickness: txWeeklyMode ? 16 : (txDailyMode ? 32 : 42) } },
        plugins: { 
            legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } },
            tooltip: { callbacks: { title: contexts => {
                const i = contexts?.[0]?.dataIndex ?? -1;
                return txDailyMode ? (txDailyFullLabels[i] ? txDailyFullLabels[i].split('-').reverse().join('/') : (labels6[i] || '')) : (labels6[i] || '');
            } } },
            datalabels: { 
                display: (context) => !txCompactMode && context.dataset.data[context.dataIndex] > 0, 
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
                max: axisMax,
                grace: '10%',
                grid: { color: '#e2e8f4' },
                ticks: { font: { size: 11 } }
            },
            x: { 
                stacked: true, 
                grid: { display: false },
                ticks: { font: { size: txCompactMode ? 9 : 10, weight: 'bold' }, autoSkip: true,
                    maxTicksLimit: txDailyMode ? 13 : (txWeeklyMode ? 8 : 10), maxRotation: txCompactMode ? 0 : 40 } 
            }
        }
    };
}

const txInstEvoChart = new Chart(document.getElementById('cInstEvo'), {
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
    options: makeStackOpts(instData),
    plugins: [pluginTotalesArriba]
});

const txVentEvoChart = new Chart(document.getElementById('cVentEvo'), {
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
    options: makeStackOpts(ventData),
    plugins: [pluginTotalesArriba]
});

function txEvoMatrix(view, source){
    const cfg = txEvoViewCache[view] || txEvoViewCache.canal || {};
    const matrix = cfg[source] || {};
    const labels = Object.keys(matrix);
    return {
        labels,
        data: labels.map(label => Array.isArray(matrix[label]) ? matrix[label].map(v => Number(v || 0)) : [])
    };
}

function txEvoDatasets(matrix){
    return matrix.labels.map((label, i) => ({
        label,
        data: matrix.data[i],
        backgroundColor: canalColores[label] || txBrandColors.grisClaro,
        borderRadius: i === matrix.labels.length - 1 ? 4 : 0,
    }));
}

const txEvoSortState = {
    vent: { idx: Math.max(0, labels6.length - 1), dir: 'desc' },
    inst: { idx: Math.max(0, labels6.length - 1), dir: 'desc' }
};

function txEscapeHtml(value){
    return String(value ?? '')
        .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
        .replaceAll('"','&quot;').replaceAll("'",'&#039;');
}

function txEvoTotals(matrix){
    const totals = Array(labels6.length).fill(0);
    Object.values(matrix || {}).forEach(vals => {
        (vals || []).forEach((v, i) => { if (i < totals.length) totals[i] += Number(v || 0); });
    });
    return totals;
}

function txRenderEvoTable(view, source){
    const cfg = txEvoViewCache[view] || txEvoViewCache.canal;
    const matrix = cfg[source] || {};
    const totals = txEvoTotals(matrix);
    const stateKey = source === 'vent' ? 'vent' : 'inst';
    const state = txEvoSortState[stateKey];
    const thead = document.getElementById(source === 'vent' ? 'txEvoVentThead' : 'txEvoInstThead');
    const tbody = document.getElementById(source === 'vent' ? 'txEvoVentTbody' : 'txEvoInstTbody');
    if (!thead || !tbody) return;

    const arrow = idx => idx === state.idx ? (state.dir === 'desc' ? '↓' : '↑') : '⇅';
    thead.innerHTML = '<tr>' +
        '<th style="text-align:left;padding:7px 10px;background:#e8f0fe;color:#000;border-radius:6px 0 0 0;font-size:0.7rem;">' + txEscapeHtml(cfg.col_label) + '</th>' +
        labels6.map((label, idx) => '<th style="text-align:center;padding:7px 8px;background:#e8f0fe;color:#000;font-size:0.7rem;">' +
            '<button type="button" class="tx-evo-sort-btn" data-evo-source="' + stateKey + '" data-evo-idx="' + idx + '" style="border:0;background:transparent;color:#000;display:inline-flex;gap:4px;align-items:center;justify-content:center;font-weight:700;cursor:pointer;white-space:nowrap;">' +
            txEscapeHtml(label) + ' <span>' + arrow(idx) + '</span></button></th>').join('') + '</tr>';

    const entries = Object.entries(matrix).filter(([, vals]) => (vals || []).reduce((a,v)=>a+Number(v||0),0) > 0);
    entries.sort((a,b) => {
        const av = Number((a[1] || [])[state.idx] || 0);
        const bv = Number((b[1] || [])[state.idx] || 0);
        return state.dir === 'asc' ? av - bv : bv - av;
    });

    let html = '';
    entries.forEach(([name, vals]) => {
        html += '<tr style="border-bottom:1px solid #e2e8f4;">';
        html += '<td style="padding:6px 10px;font-weight:600;color:#1a2540;">' + txEscapeHtml(name) + '</td>';
        labels6.forEach((_, i) => {
            const v = Number((vals || [])[i] || 0);
            const pct = totals[i] > 0 ? Math.round((v / totals[i]) * 1000) / 10 : 0;
            html += '<td style="text-align:center;padding:6px 8px;color:' + (pct > 0 ? '#1a2540' : '#9ca3af') + ';">' + (pct > 0 ? pct.toLocaleString('es-MX',{maximumFractionDigits:1}) + '%' : '—') + '</td>';
        });
        html += '</tr>';
    });
    html += '<tr style="background:#e8f0fe;font-weight:700;"><td style="padding:6px 10px;color:#2b57a7;">Total</td>' +
        totals.map(t => '<td style="text-align:center;padding:6px 8px;color:#2b57a7;">' + Number(t).toLocaleString('es-MX') + '</td>').join('') + '</tr>';
    tbody.innerHTML = html;

    thead.querySelectorAll('.tx-evo-sort-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = Number(btn.dataset.evoIdx || 0);
            if (state.idx === idx) state.dir = state.dir === 'desc' ? 'asc' : 'desc';
            else { state.idx = idx; state.dir = 'desc'; }
            txRenderEvoTable(view, source);
        });
    });
}

function txApplyEvoView(view, updateUrl = true){
    if (!txEvoViewCache[view]) view = 'canal';
    const cfg = txEvoViewCache[view];
    const inst = txEvoMatrix(view, 'inst');
    const vent = txEvoMatrix(view, 'vent');

    txInstEvoChart.data.datasets = txEvoDatasets(inst);
    txInstEvoChart.options = makeStackOpts(inst.data);
    txInstEvoChart.update();

    txVentEvoChart.data.datasets = txEvoDatasets(vent);
    txVentEvoChart.options = makeStackOpts(vent.data);
    txVentEvoChart.update();

    const mapText = {
        txEvoTitle: cfg.titulo,
        txEvoParticipationTitle: cfg.participacion_titulo,
        txEvoVentSub: cfg.vent_sub,
        txEvoInstSub: cfg.inst_sub,
        txEvoParticipationVentSub: cfg.vent_sub,
        txEvoParticipationInstSub: cfg.inst_sub,
    };
    Object.entries(mapText).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '';
    });

    const sourceNote = document.getElementById('txEvoSourceNote');
    const sourceError = document.getElementById('txEvoError');
    if (sourceNote) sourceNote.style.display = cfg.catalogo ? '' : 'none';
    if (sourceError) sourceError.style.display = (cfg.catalogo && !txEvoCatalogOk) ? '' : 'none';

    document.querySelectorAll('.tx-evo-toggle-link').forEach(a => {
        a.classList.toggle('active', a.dataset.evoView === view);
    });

    txRenderEvoTable(view, 'vent');
    txRenderEvoTable(view, 'inst');

    if (updateUrl && window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.set('evo_view', view);
        history.replaceState({evo_view:view}, '', url.toString());
    }
}

document.querySelectorAll('.tx-evo-toggle-link').forEach(a => {
    a.addEventListener('click', (ev) => {
        ev.preventDefault();
        txApplyEvoView(a.dataset.evoView || 'canal', true);
    });
});

// STAGING navegabilidad: control por matriz, independiente de la otra tabla.
// Por defecto se muestra el mes más reciente, que es el corte ejecutivo vigente.
const txEvoScrollElements = {
    vent: document.getElementById('txEvoVentScroll'),
    inst: document.getElementById('txEvoInstScroll')
};
function txUpdateParticipationArrows(source) {
    const el = txEvoScrollElements[source];
    if (!el) return;
    const max = Math.max(0, el.scrollWidth - el.clientWidth);
    document.querySelectorAll('.tx-participacion-arrow[data-scroll-table="' + source + '"]').forEach(btn => {
        const dir = Number(btn.dataset.scrollDir || 0);
        btn.disabled = dir < 0 ? el.scrollLeft <= 2 : el.scrollLeft >= max - 2;
    });
}
function txResetParticipationToLatest() {
    Object.entries(txEvoScrollElements).forEach(([source, el]) => {
        if (!el) return;
        el.scrollLeft = Math.max(0, el.scrollWidth - el.clientWidth);
        txUpdateParticipationArrows(source);
    });
}
Object.entries(txEvoScrollElements).forEach(([source, el]) => {
    if (el) el.addEventListener('scroll', () => txUpdateParticipationArrows(source), {passive:true});
});
document.querySelectorAll('.tx-participacion-arrow').forEach(btn => {
    btn.addEventListener('click', () => {
        const source = btn.dataset.scrollTable;
        const el = txEvoScrollElements[source];
        if (!el) return;
        const dir = Number(btn.dataset.scrollDir || 0);
        const delta = Math.max(200, Math.round(el.clientWidth * .70));
        el.scrollBy({left:delta * dir,behavior:'smooth'});
    });
});
// En Semanal, no ampliar artificialmente las gráficas: todas las semanas
// quedan visibles; conservar scroll sólo en la tabla de Participación.
// Re-render inicial para que tablas y controles queden bajo el mismo motor JS.
txApplyEvoView(txEvoInitialView, false);
requestAnimationFrame(txResetParticipationToLatest);
window.addEventListener('resize', () => {
    Object.keys(txEvoScrollElements).forEach(txUpdateParticipationArrows);
}, {passive:true});
</script>


<script>
(function(){
    const dropdown=document.getElementById('dashboardRangeDropdown');
    const trigger=document.getElementById('dashboardRangeTrigger');
    const panel=document.getElementById('dashboardRangePanel');
    if(!dropdown || !trigger || !panel)return;
    trigger.addEventListener('click',e=>{e.stopPropagation();dropdown.classList.toggle('open');});
    panel.addEventListener('click',e=>e.stopPropagation());
    document.addEventListener('click',()=>dropdown.classList.remove('open'));

    const weekGrid=document.getElementById('dashWeekdayGrid');
    if(weekGrid){
        const input=document.getElementById('dashDiasSemanaInput');
        const weekButtons=[...weekGrid.querySelectorAll('.tx-nav-weekday:not(:disabled)')];
        function sync(){
            let selected=weekButtons.filter(x=>x.classList.contains('selected-start'));
            if(!selected.length && weekButtons[0]) {weekButtons[0].classList.add('selected-start','selected-end');selected=[weekButtons[0]];}
            input.value=selected.map(x=>Number(x.dataset.weekday)).sort((a,b)=>a-b).join(',');
        }
        weekButtons.forEach(b=>b.addEventListener('click',()=>{
            const chosen=!b.classList.contains('selected-start');
            b.classList.toggle('selected-start',chosen);b.classList.toggle('selected-end',chosen);sync();
        }));
        document.getElementById('dashWeekFullBtn')?.addEventListener('click',()=>{
            weekButtons.forEach(b=>b.classList.add('selected-start','selected-end'));sync();
        });
        sync();
        return;
    }
    const grid=document.getElementById('dashCalendarGrid');
    const inputFrom=document.getElementById('dashFechaInicio');
    const inputTo=document.getElementById('dashFechaFin');
    const summary=document.getElementById('dashRangeSummary');
    if(!grid || !inputFrom || !inputTo)return;
    let start=grid.dataset.startDate, end=grid.dataset.endDate;
    let clickMode='start';
    const months=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    function parseLocal(iso){let p=iso.split('-').map(Number);return new Date(p[0],p[1]-1,p[2],12);}
    function toISO(d){return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;}
    function human(a,b){let x=parseLocal(a),y=parseLocal(b);return x.getFullYear()===y.getFullYear()&&x.getMonth()===y.getMonth()
      ? `${months[x.getMonth()]} ${x.getDate()}–${y.getDate()} ${x.getFullYear()}`
      : `${months[x.getMonth()]} ${x.getDate()} - ${months[y.getMonth()]} ${y.getDate()} ${y.getFullYear()}`;}
    function paint(){
        if(start>end)[start,end]=[end,start];
        inputFrom.value=start;inputTo.value=end;
        grid.querySelectorAll('.calendar-day[data-date]').forEach(btn=>{
            let d=btn.dataset.date;
            btn.classList.toggle('selected-start',d===start);
            btn.classList.toggle('selected-end',d===end);
            btn.classList.toggle('in-range',d>start&&d<end);
        });
        if(summary){
            let d=parseLocal(start),y=d.getFullYear(),m=d.getMonth()-1;
            if(m<0){m=11;y--;}
            let back=new Date(y,m,Math.min(d.getDate(),new Date(y,m+1,0).getDate()),12);
            let n=Math.round((parseLocal(end)-parseLocal(start))/86400000);
            let backEnd=new Date(back);backEnd.setDate(backEnd.getDate()+n);
            summary.textContent=human(toISO(back),toISO(backEnd))+' vs '+human(start,end);
        }
    }
    grid.querySelectorAll('.calendar-day[data-date]:not(:disabled)').forEach(btn=>btn.addEventListener('click',()=>{
        let d=btn.dataset.date;
        if(clickMode==='start'){start=d;end=d;clickMode='end';}
        else {end=d;clickMode='start';}
        paint();
    }));
    paint();
})();
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