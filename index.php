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

            $folio_ids = getFoliosPorPosiciones($conexion, $scope_ids);
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

// Fecha de corte única para todo el dashboard.
// Default: día vencido actual. Ahora permite consultar meses/años anteriores
// usando parámetros GET mes/anio, por ejemplo: ?mes=5&anio=2026.
$fecha_corte_real_timestamp = strtotime('-1 day');

$mes_actual = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('n', $fecha_corte_real_timestamp);
$anio_query = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y', $fecha_corte_real_timestamp);

if ($mes_actual < 1 || $mes_actual > 12) {
    $mes_actual = (int)date('n', $fecha_corte_real_timestamp);
}
if ($anio_query < 2020 || $anio_query > (int)date('Y', $fecha_corte_real_timestamp)) {
    $anio_query = (int)date('Y', $fecha_corte_real_timestamp);
}

// No permitir meses futuros respecto al corte real.
$yyyymm_sel = ((int)$anio_query * 100) + (int)$mes_actual;
$yyyymm_now = ((int)date('Y', $fecha_corte_real_timestamp) * 100) + (int)date('n', $fecha_corte_real_timestamp);
if ($yyyymm_sel > $yyyymm_now) {
    $mes_actual = (int)date('n', $fecha_corte_real_timestamp);
    $anio_query = (int)date('Y', $fecha_corte_real_timestamp);
}

// $fecha_corte_timestamp se ajusta más abajo al fin del rango seleccionado,
// para que ARPU, TOP y productividad 3M respeten el mes analizado.
$fecha_corte_timestamp = $fecha_corte_real_timestamp;
$semana_operativa_dashboard = (int)date('W', $fecha_corte_timestamp);
$anio_operativo_dashboard   = (int)date('Y', $fecha_corte_timestamp);

$meses_es = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];

// Selector de rango operativo del dashboard.
// Default: MTD vencido, comparando todos los meses contra el mismo corte de días.
// Mes completo: muestra la evolución como corte mensual completo.
$ultimo_dia_mes_actual = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio_query, $mes_actual)));
$es_mes_actual_real = ((int)$anio_query === (int)date('Y', $fecha_corte_real_timestamp)
    && (int)$mes_actual === (int)date('n', $fecha_corte_real_timestamp));

// En el mes actual sólo se permite hasta el día vencido.
// En meses anteriores se permite navegar el mes completo.
$dia_max_corte = $es_mes_actual_real
    ? min((int)date('j', $fecha_corte_real_timestamp), $ultimo_dia_mes_actual)
    : $ultimo_dia_mes_actual;

$primer_dia_semana_mes = (int)date('N', strtotime(sprintf('%04d-%02d-01', $anio_query, $mes_actual)));

$rango_mode = $_GET['rango_mode'] ?? 'mtd';
if (!in_array($rango_mode, ['mtd','completo','custom'], true)) {
    $rango_mode = 'mtd';
}

if ($rango_mode === 'completo') {
    $dia_inicio_dashboard = 1;
    $dia_fin_dashboard = $ultimo_dia_mes_actual;
} else {
    if ($rango_mode !== 'custom') {
        $_GET['dia_inicio'] = 1;
        $_GET['dia_fin'] = $dia_max_corte;
    }

    $dia_inicio_dashboard = isset($_GET['dia_inicio']) ? (int)$_GET['dia_inicio'] : 1;
    $dia_fin_dashboard = isset($_GET['dia_fin']) ? (int)$_GET['dia_fin'] : $dia_max_corte;

    $dia_inicio_dashboard = max(1, min($dia_max_corte, $dia_inicio_dashboard));
    $dia_fin_dashboard = max(1, min($dia_max_corte, $dia_fin_dashboard));

    if ($dia_inicio_dashboard > $dia_fin_dashboard) {
        $tmp_dia = $dia_inicio_dashboard;
        $dia_inicio_dashboard = $dia_fin_dashboard;
        $dia_fin_dashboard = $tmp_dia;
    }
}

$dias_rango_dashboard = max(1, $dia_fin_dashboard - $dia_inicio_dashboard + 1);

// Fechas absolutas del rango seleccionado.
// Se usan para prorratear metas semanales de Ejecución Operativa
// cuando el dashboard analiza periodos que cruzan más de una semana.
$fecha_inicio_dashboard = sprintf('%04d-%02d-%02d', $anio_query, $mes_actual, $dia_inicio_dashboard);
$fecha_fin_dashboard    = sprintf('%04d-%02d-%02d', $anio_query, $mes_actual, $dia_fin_dashboard);

// Para consultas históricas, el corte operativo del dashboard debe ser el fin
// del rango seleccionado, no necesariamente el día vencido actual.
$fecha_corte_timestamp = strtotime($fecha_fin_dashboard);
$semana_operativa_dashboard = (int)date('W', $fecha_corte_timestamp);
$anio_operativo_dashboard   = (int)date('Y', $fecha_corte_timestamp);

// Navegación de mes/año dentro del selector de rango.
$mes_prev_ts = strtotime('-1 month', strtotime(sprintf('%04d-%02d-01', $anio_query, $mes_actual)));
$mes_next_ts = strtotime('+1 month', strtotime(sprintf('%04d-%02d-01', $anio_query, $mes_actual)));
$mes_prev = (int)date('n', $mes_prev_ts);
$anio_prev = (int)date('Y', $mes_prev_ts);
$mes_next = (int)date('n', $mes_next_ts);
$anio_next = (int)date('Y', $mes_next_ts);
$puede_mes_next = (($anio_next * 100) + $mes_next) <= $yyyymm_now;

$dashboard_fecha_label = ($rango_mode === 'completo')
    ? 'Mes completo · ' . ($meses_es[$mes_actual] ?? '') . ' ' . $anio_query
    : (
        $dia_inicio_dashboard === $dia_fin_dashboard
            ? sprintf('%02d de %s %04d', $dia_fin_dashboard, strtolower($meses_es[$mes_actual] ?? ''), $anio_query)
            : sprintf('%02d-%02d de %s %04d', $dia_inicio_dashboard, $dia_fin_dashboard, strtolower($meses_es[$mes_actual] ?? ''), $anio_query)
    );

$cond_dia_fecha = " AND DAY(fecha) BETWEEN $dia_inicio_dashboard AND $dia_fin_dashboard";
$cond_dia_fecha_cierre = " AND DAY(fecha_cierre) BETWEEN $dia_inicio_dashboard AND $dia_fin_dashboard";
$cond_dia_evolucion_fecha = ($rango_mode === 'completo') ? '' : $cond_dia_fecha;
$cond_dia_evolucion_fecha_cierre = ($rango_mode === 'completo') ? '' : $cond_dia_fecha_cierre;

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
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query $cond_dia_fecha AND origen_prospecto <> '-'");
} elseif ($scope_filtrar_por_distrito) {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query $cond_dia_fecha AND origen_prospecto <> '-' AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_inst = mysqli_query($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query $cond_dia_fecha AND origen_prospecto <> '-' AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_inst = mysqli_query($conexion, "SELECT 0 as total");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_inst = mysqli_prepare($conexion, "SELECT COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? $cond_dia_fecha AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
        $tipos = 'ii' . str_repeat('s', count($folio_ids));
        $bind  = array_merge([$mes_actual, $anio_query], array_values($folio_ids));
        mysqli_stmt_bind_param($stmt_inst, $tipos, ...$bind);
        mysqli_stmt_execute($stmt_inst);
        $r_inst = mysqli_stmt_get_result($stmt_inst);
    }
}
$kpi_inst = $r_inst ? (mysqli_fetch_assoc($r_inst)['total'] ?? 0) : 0;

// ── VENTAS ───────────────────────────────────────────────────────────────────
if ($rol_consulta === 'admin') {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query $cond_dia_fecha_cierre");
} elseif ($scope_filtrar_por_distrito) {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query $cond_dia_fecha_cierre AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_vent = mysqli_query($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query $cond_dia_fecha_cierre AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_vent = mysqli_query($conexion, "SELECT 0 as total");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_vent = mysqli_prepare($conexion, "SELECT COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? $cond_dia_fecha_cierre AND folio_empleado IN ($ph)");
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
            $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia BETWEEN $dia_inicio_dashboard AND $dia_fin_dashboard");
        } elseif ($scope_filtrar_por_distrito) {
            $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia BETWEEN $dia_inicio_dashboard AND $dia_fin_dashboard AND distrito IN ($scope_distritos_sql)");
        } else {
            $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia BETWEEN $dia_inicio_dashboard AND $dia_fin_dashboard AND distrito IN ($distritos_sql)");
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
        WHERE mes_num = ".(int)$mes."
          AND anio = ".(int)$anio."
          AND dia BETWEEN ".(int)$dia_inicio." AND ".(int)$dia_fin."
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
        WHERE MONTH(fecha)=".(int)$mes."
          AND YEAR(fecha)=".(int)$anio."
          $cond_dia_fecha
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
    $r = mysqli_query($conexion, "SELECT SUM(meta_diaria) AS meta FROM metas_instalacion WHERE mes_num=".(int)$mes." AND anio=".(int)$anio." AND dia=1 AND distrito IN ($dsql)");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return round(((float)($row['meta'] ?? 0)) * (int)$dias);
}

function tx_real_inst_distrito($conexion, $distrito, $mes, $anio, $cond_dia_fecha) {
    $dsql = tx_sql_in_escaped($conexion, tx_distrito_equivalentes_array($distrito));
    $r = mysqli_query($conexion, "SELECT COUNT(cuenta) AS total FROM instalaciones WHERE MONTH(fecha)=".(int)$mes." AND YEAR(fecha)=".(int)$anio." $cond_dia_fecha AND origen_prospecto <> '-' AND distrito IN ($dsql)");
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
    $r = mysqli_query($conexion, "SELECT COUNT(cuenta) AS total FROM instalaciones WHERE MONTH(fecha)=".(int)$mes." AND YEAR(fecha)=".(int)$anio." $cond_dia_fecha AND origen_prospecto <> '-' AND folio_empleado IN ($folios_sql)");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)($row['total'] ?? 0);
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
$cumplimiento_inferior_items = [];

$nivel_actual_dashboard = $scope_activo ? $scope_nivel : ($rol === 'admin' ? 'admin' : nivel_dashboard_hc($posicion_usuario ?? '', ''));
$root_dashboard_id = $scope_activo ? ($scope_registro['id_posicion'] ?? '') : $id_posicion;
$root_dashboard_distrito = $scope_activo ? ($distrito_scope ?? $distrito_usuario) : $distrito_usuario;


if (in_array($rol, ['admin','director_regional'], true) && !$scope_activo) {
    $cumplimiento_inferior_subtitulo = 'Distritos · Real vs Meta';
    $distritos_raw = [];
    $res_dist = mysqli_query($conexion, "
        SELECT DISTINCT distrito FROM metas_instalacion
        WHERE mes_num=".(int)$mes_actual." AND anio=".(int)$anio_query."
        UNION
        SELECT DISTINCT distrito FROM instalaciones
        WHERE MONTH(fecha)=".(int)$mes_actual." AND YEAR(fecha)=".(int)$anio_query." $cond_dia_fecha AND origen_prospecto <> '-'
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
        $tmp[] = ['label'=>$dist, 'real'=>$real, 'meta'=>$meta, 'pct'=>$pct];
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
            $child_folios = getFoliosPorPosiciones($conexion, $child_ids);
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
            $tmp[] = [
                'label'=>$child['label'],
                'real'=>$real,
                'meta'=>$meta,
                'pct'=>$pct,
                'meta_fuente'=>$meta_fuente
            ];
        }
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
        $cumplimiento_canal_items[] = [
            'nombre' => $canal,
            'real' => $real_canal,
            'meta' => $meta_canal,
            'pct' => $pct_canal,
            'visual_pct' => $pct_canal
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
if ($rol_consulta === 'admin') {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query $cond_dia_fecha AND origen_prospecto <> '-'");
} elseif ($scope_filtrar_por_distrito) {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query $cond_dia_fecha AND origen_prospecto <> '-' AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_mix_inst = mysqli_query($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=$mes_actual AND YEAR(fecha)=$anio_query $cond_dia_fecha AND origen_prospecto <> '-' AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_mix_inst = mysqli_query($conexion, "SELECT 0 as p3, 0 as p2");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_mix = mysqli_prepare($conexion, "SELECT SUM(plan LIKE '%TV%') as p3, SUM(plan NOT LIKE '%TV%') as p2 FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? $cond_dia_fecha AND origen_prospecto <> '-' AND folio_empleado IN ($ph)");
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
if ($rol_consulta === 'admin') {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query $cond_dia_fecha_cierre");
} elseif ($scope_filtrar_por_distrito) {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query $cond_dia_fecha_cierre AND distrito IN ($scope_distritos_sql)");
} elseif ($por_distrito) {
    $r_mix_vent = mysqli_query($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=$mes_actual AND YEAR(fecha_cierre)=$anio_query $cond_dia_fecha_cierre AND distrito IN ($distritos_sql)");
} else {
    if (empty($folio_ids)) {
        $r_mix_vent = mysqli_query($conexion, "SELECT 0 as p3, 0 as p2");
    } else {
        $ph = implode(',', array_fill(0, count($folio_ids), '?'));
        $stmt_mix_v = mysqli_prepare($conexion, "SELECT SUM(nombre_plan LIKE '%TV%') as p3, SUM(nombre_plan NOT LIKE '%TV%') as p2 FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? $cond_dia_fecha_cierre AND folio_empleado IN ($ph)");
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


// ── ARPU HISTÓRICO 6 MESES ──────────────────────────────────────────────────
// Sustituye Mix 2P/3P Ventas porque aporta una lectura más ejecutiva.
// Compara el mismo rango de días seleccionado en cada mes.
// Ejemplo MTD vencido 01-09: calcula ARPU del día 01 al 09 de cada mes.
$arpu_labels = $meses_labels;
$arpu_data = array_fill(0, 6, 0);

for ($i_arpu = 5; $i_arpu >= 0; $i_arpu--) {
    $ts_arpu = mktime(0, 0, 0, $mes_actual - $i_arpu, 1, $anio_query);
    $mes_arpu = (int)date('n', $ts_arpu);
    $anio_arpu = (int)date('Y', $ts_arpu);
    $idx_arpu = 5 - $i_arpu;

    $ultimo_dia_mes_arpu = (int)date('t', $ts_arpu);
    if ($rango_mode === 'completo') {
        $cond_arpu_dia = '';
    } else {
        $dia_ini_arpu = min($dia_inicio_dashboard, $ultimo_dia_mes_arpu);
        $dia_fin_arpu = min($dia_fin_dashboard, $ultimo_dia_mes_arpu);
        $cond_arpu_dia = " AND DAY(fecha) BETWEEN $dia_ini_arpu AND $dia_fin_arpu";
    }

    if ($rol_consulta === 'admin') {
        $arpu_data[$idx_arpu] = tx_arpu_instalaciones_dashboard(
            $conexion, $mes_arpu, $anio_arpu, $cond_arpu_dia
        );
    } elseif ($scope_filtrar_por_distrito) {
        $arpu_data[$idx_arpu] = tx_arpu_instalaciones_dashboard(
            $conexion, $mes_arpu, $anio_arpu, $cond_arpu_dia, $scope_distritos_sql
        );
    } elseif ($por_distrito) {
        $arpu_data[$idx_arpu] = tx_arpu_instalaciones_dashboard(
            $conexion, $mes_arpu, $anio_arpu, $cond_arpu_dia, $distritos_sql
        );
    } else {
        $arpu_data[$idx_arpu] = tx_arpu_instalaciones_dashboard(
            $conexion, $mes_arpu, $anio_arpu, $cond_arpu_dia, '', $folio_ids
        );
    }
}

// 1. Datos Instalaciones por Origen
$query_inst = "SELECT MONTH(fecha) as mes, YEAR(fecha) as anio, origen_prospecto, COUNT(*) as total 
    FROM instalaciones 
    WHERE fecha >= '$fecha_inicio_evolucion' $cond_dia_evolucion_fecha AND origen_prospecto <> '-' ";
if ($rol_consulta !== 'admin' && $scope_filtrar_por_distrito) {
    $query_inst .= " AND distrito IN ($scope_distritos_sql)";
} elseif ($rol_consulta !== 'admin' && $por_distrito) {
    $query_inst .= " AND distrito IN ($distritos_sql)";
} elseif ($rol_consulta !== 'admin' && !empty($folio_ids)) {
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
    WHERE fecha_cierre >= '$fecha_inicio_evolucion' $cond_dia_evolucion_fecha_cierre ";
if ($rol_consulta !== 'admin' && $scope_filtrar_por_distrito) {
    $query_vent .= " AND distrito IN ($scope_distritos_sql)";
} elseif ($rol_consulta !== 'admin' && $por_distrito) {
    $query_vent .= " AND distrito IN ($distritos_sql)";
} elseif ($rol_consulta !== 'admin' && !empty($folio_ids)) {
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

// Ordenamiento de tablas de participación.
// Vista inicial: mes en curso (última columna) de mayor a menor.
// Cada encabezado de mes permite alternar mayor-menor / menor-mayor.
$sort_vent_idx = isset($_GET['sort_vent_mes']) ? max(0, min(5, (int)$_GET['sort_vent_mes'])) : 5;
$sort_vent_dir = (isset($_GET['sort_vent_dir']) && $_GET['sort_vent_dir'] === 'asc') ? 'asc' : 'desc';

$sort_inst_idx = isset($_GET['sort_inst_mes']) ? max(0, min(5, (int)$_GET['sort_inst_mes'])) : 5;
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
            h.numero_talento_gs AS folio,
            h.nombre_colaborador AS vendedor,
            h.distrito,
            COALESCE(c.nombre_colaborador, '') AS coach,
            COALESCE(l.nombre_colaborador, '') AS lider,
            COALESCE(d.nombre_colaborador, '') AS director
        FROM hc h
        LEFT JOIN hc c
            ON c.id_posicion = h.posicion_lr
           AND c.semana = h.semana
           AND c.anio = h.anio
        LEFT JOIN hc l
            ON l.id_posicion = c.posicion_lr
           AND l.semana = h.semana
           AND l.anio = h.anio
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

    return $out;
}

function tx_sparkline_vendedor_dashboard($conexion, $folio, $fecha_corte_timestamp) {
    // Mini tendencia: instalaciones de las últimas 6 semanas calendario.
    $out = array_fill(0, 6, 0);
    $fecha_fin = date('Y-m-d', $fecha_corte_timestamp);
    $fecha_ini = date('Y-m-d', strtotime('-5 weeks', strtotime('monday this week', $fecha_corte_timestamp)));
    $folio_esc = mysqli_real_escape_string($conexion, (string)$folio);

    $sql = "
        SELECT YEARWEEK(fecha, 3) AS yw, COUNT(cuenta) AS total
        FROM instalaciones
        WHERE fecha BETWEEN '$fecha_ini' AND '$fecha_fin'
          AND folio_empleado = '$folio_esc'
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

    $folios = array_keys($vendedores);
    $folios_sql = tx_sql_in_escaped($conexion, $folios);

    // Desempeño del rango seleccionado.
    $sql = "
        SELECT
            folio_empleado AS folio,
            COUNT(cuenta) AS instalaciones,
            COALESCE(SUM(precio_pronto_pago),0) AS ingreso,
            SUM(CASE WHEN precio_pronto_pago > 0 THEN 1 ELSE 0 END) AS inst_arpu
        FROM instalaciones
        WHERE MONTH(fecha)=".(int)$mes."
          AND YEAR(fecha)=".(int)$anio."
          $cond_dia_fecha
          AND origen_prospecto <> '-'
          AND folio_empleado IN ($folios_sql)
        GROUP BY folio_empleado
    ";
    $res = mysqli_query($conexion, $sql);
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $folio = (string)($row['folio'] ?? '');
        if (!isset($vendedores[$folio])) continue;

        $inst = (int)($row['instalaciones'] ?? 0);
        $ingreso = (float)($row['ingreso'] ?? 0);
        $inst_arpu = (int)($row['inst_arpu'] ?? 0);

        $vendedores[$folio]['instalaciones'] = $inst;
        $vendedores[$folio]['productividad'] = $dias_productividad > 0 ? round($inst / $dias_productividad, 2) : 0;
        $vendedores[$folio]['arpu'] = $inst_arpu > 0 ? round($ingreso / $inst_arpu, 2) : 0;
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
        $folio = (string)($row['folio'] ?? '');
        if (!isset($vendedores[$folio])) continue;

        $inst3m = (int)($row['instalaciones_3m'] ?? 0);
        $vendedores[$folio]['prod3m'] = $dias_3m > 0 ? round($inst3m / $dias_3m, 2) : 0;
    }

    foreach ($vendedores as $folio => &$v) {
        $v['spark'] = tx_sparkline_vendedor_dashboard($conexion, $folio, $fecha_corte_timestamp);
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
function tx_get_coaches_regional_dashboard($conexion, $semana, $anio, $puestos_comerciales) {
    /*
    |--------------------------------------------------------------------------
    | TOTALXPEDIENT - TOP/BOTTOM COACHES DE VENTA
    |--------------------------------------------------------------------------
    |
    | Esta lista debe coincidir con el universo del Ranking Coach en
    | ranking_productividad.php.
    |
    | Regla:
    |   Coach de Venta = colaborador/vacante que reporta a un Líder de Venta
    |   y que además tiene vendedores asignados debajo en HC.
    |
    | Importante:
    |   No basta con buscar posicion LIKE '%COACH%'. Eso puede incluir perfiles
    |   de acompañamiento/operación que no aparecen en Ranking Coach.
    |
    | Homologación tomada del Ranking Coach:
    |   - Coach reporta al líder: c.nombre_linea_reporte = líder.
    |   - Vendedor reporta al coach: v.nombre_linea_reporte = coach.
    |   - Si el coach es VACANTE, se amarra por v.posicion_lr = c.id_posicion.
    |   - v.puesto_lr LIKE '%COACH%'.
    |--------------------------------------------------------------------------
    */
    $sql = "
        SELECT DISTINCT
            c.id_posicion AS id_posicion,
            c.nombre_colaborador AS coach,
            c.distrito,
            COALESCE(l.nombre_colaborador, c.nombre_linea_reporte, '') AS lider
        FROM hc c
        INNER JOIN hc l
            ON l.nombre_colaborador = c.nombre_linea_reporte
           AND l.distrito = c.distrito
           AND l.semana = c.semana
           AND l.anio = c.anio
           AND UPPER(l.posicion) LIKE '%LIDER VENTAS%'
           AND l.numero_talento_gs NOT LIKE '%VACANTE%'
           AND l.nombre_colaborador <> 'VACANTE'
        WHERE c.semana=".(int)$semana."
          AND c.anio=".(int)$anio."
          AND c.puesto_lr LIKE '%LIDER%'
          AND c.id_posicion IS NOT NULL
          AND c.id_posicion <> ''
          AND c.numero_talento_gs NOT LIKE '%VACANTE%'
          AND UPPER(TRIM(COALESCE(c.nombre_colaborador,''))) <> 'VACANTE'
          AND EXISTS (
              SELECT 1
              FROM hc v
              WHERE v.semana = c.semana
                AND v.anio = c.anio
                AND v.distrito = c.distrito
                AND v.puesto_lr LIKE '%COACH%'
                AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                AND UPPER(TRIM(COALESCE(v.nombre_colaborador,''))) <> 'VACANTE'
                AND (
                    (c.nombre_colaborador <> 'VACANTE' AND v.nombre_linea_reporte = c.nombre_colaborador)
                    OR
                    (c.nombre_colaborador = 'VACANTE' AND v.posicion_lr = c.id_posicion)
                )
          )
        ORDER BY c.distrito, c.nombre_colaborador
    ";

    $res = mysqli_query($conexion, $sql);
    $out = [];

    while ($res && $row = mysqli_fetch_assoc($res)) {
        $id_pos = trim((string)($row['id_posicion'] ?? ''));
        $coach_nombre = trim((string)($row['coach'] ?? ''));
        $distrito = trim((string)($row['distrito'] ?? ''));

        if ($id_pos === '' || $coach_nombre === '' || $distrito === '') continue;

        $id_pos_esc = mysqli_real_escape_string($conexion, $id_pos);
        $coach_esc = mysqli_real_escape_string($conexion, $coach_nombre);
        $distrito_esc = mysqli_real_escape_string($conexion, $distrito);

        /*
         * Folios y HC activo del coach con la misma lógica del Ranking Coach.
         * Evita tomar líneas que no son de venta y evita que entren coaches
         * operativos que no aparecen en el ranking_productividad.php.
         */
        $sql_vendedores = "
            SELECT DISTINCT
                v.numero_talento_gs AS folio_empleado
            FROM hc v
            WHERE v.semana = ".(int)$semana."
              AND v.anio = ".(int)$anio."
              AND v.distrito = '$distrito_esc'
              AND v.puesto_lr LIKE '%COACH%'
              AND (
                    ('$coach_esc' <> 'VACANTE' AND v.nombre_linea_reporte = '$coach_esc')
                    OR
                    ('$coach_esc' = 'VACANTE' AND v.posicion_lr = '$id_pos_esc')
              )
              AND v.numero_talento_gs <> ''
              AND v.numero_talento_gs NOT LIKE '%VACANTE%'
              AND v.nombre_colaborador <> 'VACANTE'
        ";

        $folios = [];
        $res_v = mysqli_query($conexion, $sql_vendedores);
        while ($res_v && $vrow = mysqli_fetch_assoc($res_v)) {
            $folio = trim((string)($vrow['folio_empleado'] ?? ''));
            if ($folio !== '') $folios[] = $folio;
        }
        $folios = array_unique(array_values($folios));

        $out[$id_pos] = [
            'id_posicion' => $id_pos,
            'coach' => $row['coach'] ?? '',
            'distrito' => $row['distrito'] ?? '',
            'lider' => $row['lider'] ?? '',
            'folios' => $folios,
            'hc_activo' => count($folios),
            'instalaciones' => 0,
            'arpu' => 0,
            'productividad' => 0,
            'prod3m' => 0,
            'spark' => []
        ];
    }

    return $out;
}

function tx_sparkline_coach_dashboard($conexion, $folios, $fecha_corte_timestamp) {
    $out = array_fill(0, 6, 0);
    if (empty($folios)) return $out;

    $folios_sql = tx_sql_in_escaped($conexion, $folios);
    $fecha_fin = date('Y-m-d', $fecha_corte_timestamp);
    $fecha_ini = date('Y-m-d', strtotime('-5 weeks', strtotime('monday this week', $fecha_corte_timestamp)));

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

function tx_build_top_regional_coaches_dashboard($conexion, $coaches, $mes, $anio, $cond_dia_fecha, $dias_productividad, $fecha_corte_timestamp) {
    if (empty($coaches)) return [[], []];

    foreach ($coaches as $id_pos => &$coach) {
        $folios = $coach['folios'] ?? [];
        if (empty($folios)) {
            $coach['spark'] = array_fill(0, 6, 0);
            continue;
        }

        $folios_sql = tx_sql_in_escaped($conexion, $folios);
        $sql = "
            SELECT
                COUNT(cuenta) AS instalaciones,
                COALESCE(SUM(precio_pronto_pago),0) AS ingreso,
                SUM(CASE WHEN precio_pronto_pago > 0 THEN 1 ELSE 0 END) AS inst_arpu
            FROM instalaciones
            WHERE MONTH(fecha)=".(int)$mes."
              AND YEAR(fecha)=".(int)$anio."
              $cond_dia_fecha
              AND origen_prospecto <> '-'
              AND folio_empleado IN ($folios_sql)
        ";
        $res = mysqli_query($conexion, $sql);
        $row = $res ? mysqli_fetch_assoc($res) : null;

        $inst = (int)($row['instalaciones'] ?? 0);
        $ingreso = (float)($row['ingreso'] ?? 0);
        $inst_arpu = (int)($row['inst_arpu'] ?? 0);
        $hc_activo = max(0, (int)($coach['hc_activo'] ?? 0));

        $coach['instalaciones'] = $inst;
        $coach['productividad'] = ($dias_productividad > 0 && $hc_activo > 0) ? round($inst / $hc_activo / $dias_productividad, 2) : 0;
        $coach['arpu'] = $inst_arpu > 0 ? round($ingreso / $inst_arpu, 2) : 0;
        $coach['spark'] = tx_sparkline_coach_dashboard($conexion, $folios, $fecha_corte_timestamp);
    }
    unset($coach);

    $fecha_ini_3m = date('Y-m-01', strtotime('first day of -3 month', $fecha_corte_timestamp));
    $fecha_fin_3m = date('Y-m-t', strtotime('last day of -1 month', $fecha_corte_timestamp));
    $dias_3m = tx_dias_habiles_ultimos_3_meses_dashboard($conexion, $fecha_corte_timestamp);

    foreach ($coaches as $id_pos => &$coach) {
        $folios = $coach['folios'] ?? [];
        if (empty($folios)) continue;

        $folios_sql = tx_sql_in_escaped($conexion, $folios);
        $sql3 = "
            SELECT COUNT(cuenta) AS instalaciones_3m
            FROM instalaciones
            WHERE fecha BETWEEN '$fecha_ini_3m' AND '$fecha_fin_3m'
              AND origen_prospecto <> '-'
              AND folio_empleado IN ($folios_sql)
        ";
        $res3 = mysqli_query($conexion, $sql3);
        $row3 = $res3 ? mysqli_fetch_assoc($res3) : null;
        $inst3m = (int)($row3['instalaciones_3m'] ?? 0);
        $hc_activo = max(0, (int)($coach['hc_activo'] ?? 0));
        $coach['prod3m'] = ($dias_3m > 0 && $hc_activo > 0) ? round($inst3m / $hc_activo / $dias_3m, 2) : 0;
    }
    unset($coach);

    $lista = array_values(array_filter($coaches, function($r) {
        return strtoupper(trim((string)($r['coach'] ?? ''))) !== 'VACANTE';
    }));

    $top = $lista;
    usort($top, function($a, $b) {
        if ($a['productividad'] == $b['productividad']) return $b['instalaciones'] <=> $a['instalaciones'];
        return $b['productividad'] <=> $a['productividad'];
    });
    $top = array_slice($top, 0, 5);

    // BOTTOM Five Coaches:
    // Debe salir del mismo universo de Coaches de Venta que Ranking Coach,
    // no de todos los puestos que contengan la palabra COACH.
    // Equivale a tomar los últimos 5 del Ranking Coach regional:
    // menor productividad del rango seleccionado; en empate, menos instalaciones.
    $off = $lista;
    usort($off, function($a, $b) {
        if ($a['productividad'] == $b['productividad']) {
            if ((int)$a['instalaciones'] === (int)$b['instalaciones']) {
                return strcmp((string)$a['coach'], (string)$b['coach']);
            }
            return ((int)$a['instalaciones']) <=> ((int)$b['instalaciones']);
        }
        return $a['productividad'] <=> $b['productividad'];
    });
    $off = array_slice($off, 0, 5);

    return [$top, $off];
}

// Productividad vendedor = instalaciones / días hábiles del rango seleccionado.
$dias_productividad_vendedor = tx_dias_habiles_rango_mes(
    $conexion,
    $anio_query,
    $mes_actual,
    $dia_inicio_dashboard,
    $dia_fin_dashboard
);
if ($dias_productividad_vendedor <= 0) $dias_productividad_vendedor = $dias_rango_dashboard;

// TOP REGIONAL COACHES: se calcula con todos los coaches activos de la región.
$coaches_regional_productividad = tx_get_coaches_regional_dashboard(
    $conexion,
    $semana_actual,
    $anio_actual,
    $puestos_comerciales
);

list($top_productividad_coaches, $top_offender_coaches) = tx_build_top_regional_coaches_dashboard(
    $conexion,
    $coaches_regional_productividad,
    $mes_actual,
    $anio_query,
    $cond_dia_fecha,
    $dias_productividad_vendedor,
    $fecha_corte_timestamp
);

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
    WHERE MONTH(fecha) = " . (int)$mes_actual . "
      AND YEAR(fecha) = " . (int)$anio_query . "
      $cond_dia_fecha
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
            WHERE MONTH(fecha_cierre) = " . (int)$mes_actual . "
              AND YEAR(fecha_cierre) = " . (int)$anio_query . "
              $cond_dia_fecha_cierre
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

    <section class="dashboard-range-card">
        <div class="dashboard-range-info">
            <div>
                <div class="dashboard-range-label">Corte operativo</div>
                <div class="dashboard-range-value"><?= htmlspecialchars($dashboard_fecha_label) ?></div>
            </div>
            <div class="dashboard-range-label">Evolución compara el mismo rango de días en cada mes</div>
        </div>

        <div class="dashboard-range-actions">
            <div class="range-dropdown" id="dashboardRangeDropdown">
                <button type="button" class="range-trigger" id="dashboardRangeTrigger">📅 Seleccionar rango</button>
                <div class="range-panel" id="dashboardRangePanel">
                    <form method="get" id="dashboardRangeForm">
                        <?php foreach ($_GET as $k => $v): ?>
                            <?php if (!in_array($k, ['dia_inicio','dia_fin','rango_mode','mes','anio'], true)): ?>
                                <?php if (is_array($v)): ?>
                                    <?php foreach ($v as $v_item): ?>
                                        <input type="hidden" name="<?= htmlspecialchars($k) ?>[]" value="<?= htmlspecialchars((string)$v_item) ?>">
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <input type="hidden" name="rango_mode" value="custom">
                        <input type="hidden" name="mes" value="<?= htmlspecialchars($mes_actual) ?>">
                        <input type="hidden" name="anio" value="<?= htmlspecialchars($anio_query) ?>">
                        <input type="hidden" name="dia_inicio" id="dashDiaInicioInput" value="<?= htmlspecialchars($dia_inicio_dashboard) ?>">
                        <input type="hidden" name="dia_fin" id="dashDiaFinInput" value="<?= htmlspecialchars($dia_fin_dashboard) ?>">

                        <?php
                            $qs_prev_mes = $_GET;
                            $qs_prev_mes['mes'] = $mes_prev;
                            $qs_prev_mes['anio'] = $anio_prev;
                            $qs_prev_mes['rango_mode'] = 'mtd';
                            $qs_prev_mes['dia_inicio'] = 1;
                            unset($qs_prev_mes['dia_fin']);

                            $qs_next_mes = $_GET;
                            $qs_next_mes['mes'] = $mes_next;
                            $qs_next_mes['anio'] = $anio_next;
                            $qs_next_mes['rango_mode'] = 'mtd';
                            $qs_next_mes['dia_inicio'] = 1;
                            unset($qs_next_mes['dia_fin']);
                        ?>
                        <div class="range-month-nav">
                            <a class="range-month-btn" href="?<?= htmlspecialchars(http_build_query($qs_prev_mes)) ?>">← Mes anterior</a>
                            <div class="range-panel-title">Selecciona rango de <?= htmlspecialchars($meses_es[$mes_actual]) ?> <?= htmlspecialchars($anio_query) ?></div>
                            <?php if ($puede_mes_next): ?>
                                <a class="range-month-btn" href="?<?= htmlspecialchars(http_build_query($qs_next_mes)) ?>">Mes siguiente →</a>
                            <?php else: ?>
                                <span class="range-month-btn disabled">Mes siguiente →</span>
                            <?php endif; ?>
                        </div>
                        <div class="calendar-grid-real" id="dashCalendarGrid"
                             data-days="<?= htmlspecialchars($dia_max_corte) ?>"
                             data-start="<?= htmlspecialchars($dia_inicio_dashboard) ?>"
                             data-end="<?= htmlspecialchars($dia_fin_dashboard) ?>">
                            <div class="calendar-weekday">Lun</div>
                            <div class="calendar-weekday">Mar</div>
                            <div class="calendar-weekday">Mié</div>
                            <div class="calendar-weekday">Jue</div>
                            <div class="calendar-weekday">Vie</div>
                            <div class="calendar-weekday">Sáb</div>
                            <div class="calendar-weekday">Dom</div>

                            <?php for ($i = 1; $i < $primer_dia_semana_mes; $i++): ?>
                                <div class="calendar-empty"></div>
                            <?php endfor; ?>

                            <?php for ($d = 1; $d <= $dia_max_corte; $d++): ?>
                                <?php
                                    $classes = [];
                                    if ($d == $dia_inicio_dashboard) $classes[] = 'selected-start';
                                    if ($d == $dia_fin_dashboard) $classes[] = 'selected-end';
                                    if ($d > $dia_inicio_dashboard && $d < $dia_fin_dashboard) $classes[] = 'in-range';
                                ?>
                                <button type="button" class="calendar-day <?= htmlspecialchars(implode(' ', $classes)) ?>" data-day="<?= htmlspecialchars($d) ?>">
                                    <?= htmlspecialchars($d) ?>
                                </button>
                            <?php endfor; ?>
                        </div>

                        <div class="range-summary" id="dashRangeSummary">
                            Rango seleccionado: día <?= htmlspecialchars($dia_inicio_dashboard) ?> al <?= htmlspecialchars($dia_fin_dashboard) ?>
                        </div>

                        <div class="range-actions">
                            <button type="submit">Aplicar</button>
                        </div>
                    </form>
                </div>
            </div>

            <a class="range-action-btn <?= ($rango_mode === 'mtd') ? 'active' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['rango_mode'=>'mtd','mes'=>$mes_actual,'anio'=>$anio_query,'dia_inicio'=>1,'dia_fin'=>$dia_max_corte])) ?>">
                MTD vencido
            </a>
            <a class="range-action-btn <?= ($rango_mode === 'completo') ? 'active' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['rango_mode'=>'completo','mes'=>$mes_actual,'anio'=>$anio_query,'dia_inicio'=>null,'dia_fin'=>null])) ?>">
                Mes completo
            </a>
        </div>
    </section>

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

    <?php if (!empty($cumplimiento_inferior_items)): ?>
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
                    $bar_width = min($visual_pct_item, 180);
                    $bar_class = $sin_meta_item ? 'neutral' : (($pct_item >= 100) ? 'ok' : (($pct_item >= 80) ? 'warn' : 'risk'));
                ?>
                <div class="cumplimiento-row">
                    <div class="cumplimiento-name" title="<?= htmlspecialchars($item['nombre'] ?? '') ?>">
                        <?= htmlspecialchars($item['nombre'] ?? '') ?>
                    </div>
                    <div class="cumplimiento-track">
                        <div class="cumplimiento-fill <?= $bar_class ?>" style="width:<?= $bar_width ?>%;"></div>
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
                    $bar_width = min($visual_pct_item, 180);
                    $bar_class = ($pct_item >= 100) ? 'ok' : (($pct_item >= 80) ? 'warn' : 'risk');
                ?>
                <div class="cumplimiento-row">
                    <div class="cumplimiento-name" title="<?= htmlspecialchars($item['nombre'] ?? '') ?>">
                        <?= htmlspecialchars($item['nombre'] ?? '') ?>
                    </div>
                    <div class="cumplimiento-track">
                        <div class="cumplimiento-fill <?= $bar_class ?>" style="width:<?= $bar_width ?>%;"></div>
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
            <div class="chart-title">ARPU — Tendencia 6 meses</div>
            <div class="hierarchy-performance-sub" style="margin-top:2px;">Mismo rango seleccionado · precio pronto pago</div>
            <div class="chart-wrap"><canvas id="cArpuHist"></canvas></div>
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
                <div class="top-productividad-table-wrap">
                <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:7px 10px;background:#e8f0fe;color:#000;border-radius:6px 0 0 0;font-size:0.7rem;">Canal</th>
                            <?php foreach ($meses_labels as $idx_mes => $ml): ?>
                            <th style="text-align:center;padding:7px 8px;background:#e8f0fe;color:#000;font-size:0.7rem;">
                                <a href="<?= dashboard_sort_url('sort_vent_mes','sort_vent_dir',$idx_mes,$sort_vent_idx,$sort_vent_dir) ?>" style="color:#000;text-decoration:none;display:inline-flex;gap:4px;align-items:center;justify-content:center;font-weight:700;">
                                    <?= htmlspecialchars($ml) ?> <span><?= dashboard_sort_arrow($idx_mes,$sort_vent_idx,$sort_vent_dir) ?></span>
                                </a>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
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
                <div class="evo-sub">Instalaciones por origen</div>
                <div class="top-productividad-table-wrap">
                <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:7px 10px;background:#e8f0fe;color:#000;border-radius:6px 0 0 0;font-size:0.7rem;">Origen</th>
                            <?php foreach ($meses_labels as $idx_mes => $ml): ?>
                            <th style="text-align:center;padding:7px 8px;background:#e8f0fe;color:#000;font-size:0.7rem;">
                                <a href="<?= dashboard_sort_url('sort_inst_mes','sort_inst_dir',$idx_mes,$sort_inst_idx,$sort_inst_dir) ?>" style="color:#000;text-decoration:none;display:inline-flex;gap:4px;align-items:center;justify-content:center;font-weight:700;">
                                    <?= htmlspecialchars($ml) ?> <span><?= dashboard_sort_arrow($idx_mes,$sort_inst_idx,$sort_inst_dir) ?></span>
                                </a>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
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
    <?php if (!empty($top_productividad_coaches) || !empty($top_offender_coaches)): ?>
    <div class="top-productividad-grid">
        <?php
            $renderTopCoachTable = function($titulo, $subtitulo, $items, $extraClass = '') {
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

        <?php $renderTopCoachTable('TOP Five Coaches', 'Mejor PROD. regional de coaches del rango seleccionado', $top_productividad_coaches, 'top-productividad'); ?>
        <?php $renderTopCoachTable('BOTTOM Five Coaches', 'Menor PROD. regional de coaches de venta', $top_offender_coaches, 'top-offender'); ?>
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

if (document.getElementById('txGeoMap') && typeof L !== 'undefined') {
    const txGeoMap = L.map('txGeoMap', {
        scrollWheelZoom: false
    }).setView(txGeoCentro, txGeoZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(txGeoMap);

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
            pointRadius: 4,
            pointHoverRadius: 6,
            borderWidth: 3,
            tension: 0.35,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 24, right: 8, left: 4 } },
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'top',
                offset: 4,
                color: '#1a2540',
                font: { weight: '900', size: 10 },
                formatter: value => value > 0 ? '$' + Math.round(value).toLocaleString('es-MX') : ''
            },
            tooltip: {
                callbacks: {
                    label: ctx => ' ARPU: $' + Number(ctx.parsed.y || 0).toLocaleString('es-MX', {maximumFractionDigits: 0})
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
                ticks: { font: { size: 10, weight: 'bold' } }
            }
        }
    },
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
                max: axisMax,
                grace: '10%',
                grid: { color: '#e2e8f4' },
                ticks: { font: { size: 11 } }
            },
            x: { 
                stacked: true, 
                grid: { display: false },
                ticks: { font: { size: 11, weight: 'bold' } } 
            }
        }
    };
}

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
    options: makeStackOpts(instData),
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
    options: makeStackOpts(ventData),
    plugins: [pluginTotalesArriba]
});
</script>


<script>
(function(){
    const dropdown = document.getElementById('dashboardRangeDropdown');
    const trigger = document.getElementById('dashboardRangeTrigger');
    const panel = document.getElementById('dashboardRangePanel');
    const grid = document.getElementById('dashCalendarGrid');
    const startInput = document.getElementById('dashDiaInicioInput');
    const endInput = document.getElementById('dashDiaFinInput');
    const summary = document.getElementById('dashRangeSummary');

    if(!dropdown || !trigger || !panel || !grid || !startInput || !endInput) return;

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
            summary.textContent = start === end
                ? 'Rango seleccionado: día ' + end
                : 'Rango seleccionado: día ' + start + ' al ' + end;
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
            } else {
                end = d;
                clickMode = 'start';
            }

            paint();
        });
    });

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