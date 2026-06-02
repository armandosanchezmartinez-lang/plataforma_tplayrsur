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
// Se usa el día vencido para evitar mezclar meses en cierres o inicios de mes.
$fecha_corte_timestamp = strtotime('-1 day');
$mes_actual   = (int)date('n', $fecha_corte_timestamp);
$anio_query   = (int)date('Y', $fecha_corte_timestamp);

$meses_es = [
    1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
    7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
];

// Selector de rango operativo del dashboard.
// Default: MTD vencido, comparando todos los meses contra el mismo corte de días.
// Mes completo: muestra la evolución como corte mensual completo.
$ultimo_dia_mes_actual = (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio_query, $mes_actual)));
$dia_max_corte = min((int)date('j', $fecha_corte_timestamp), $ultimo_dia_mes_actual);
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
$mostrar_meta = $por_distrito || $scope_filtrar_por_distrito;

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
    if ($rol_consulta === 'admin') {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1");
    } elseif ($scope_filtrar_por_distrito) {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1 AND distrito IN ($scope_distritos_sql)");
    } else {
        $r_meta = mysqli_query($conexion, "SELECT SUM(meta_diaria) as meta_diaria_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1 AND distrito IN ($distritos_sql)");
    }

    if ($r_meta && $row_meta = mysqli_fetch_assoc($r_meta)) {
        $meta_diaria_total = (float)($row_meta['meta_diaria_total'] ?? 0);
        $kpi_meta_acum     = round($meta_diaria_total * $dias_transcurridos);
        $kpi_meta_pct      = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
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

$cumplimiento_inferior_titulo = 'Cumplimiento del nivel inferior vs meta';
$cumplimiento_inferior_subtitulo = '';
$cumplimiento_inferior_labels = [];
$cumplimiento_inferior_real = [];
$cumplimiento_inferior_meta = [];
$cumplimiento_inferior_pct = [];
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
                'label' => $child['nombre_colaborador'] ?? '',
                'folios' => $child_folios,
                'hc' => $child_hc
            ];
        }

        foreach ($children_calc as $child) {
            $real = tx_real_inst_folios($conexion, $child['folios'], $mes_actual, $anio_query, $cond_dia_fecha);
            $meta = ($hc_total_children > 0) ? round($meta_base * ($child['hc'] / $hc_total_children)) : 0;
            if ($real <= 0 && $meta <= 0) continue;
            $pct = $meta > 0 ? round(($real / $meta) * 100, 1) : 0;
            $tmp[] = ['label'=>$child['label'], 'real'=>$real, 'meta'=>$meta, 'pct'=>$pct];
        }
    }
}

usort($tmp, function($a, $b) {
    if ($a['pct'] == $b['pct']) return $b['real'] <=> $a['real'];
    return $b['pct'] <=> $a['pct'];
});
$tmp = array_slice($tmp, 0, 10);

foreach ($tmp as $r) {
    $cumplimiento_inferior_labels[] = $r['label'];
    $cumplimiento_inferior_real[] = (int)$r['real'];
    $cumplimiento_inferior_meta[] = (int)$r['meta'];
    $cumplimiento_inferior_pct[] = (float)$r['pct'];
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


// Preparar lista ejecutiva para Cumplimiento del nivel inferior.
// Barra única por nivel: % cumplimiento + Real / Meta.
$cumplimiento_inferior_items = [];
foreach ($cumplimiento_inferior_labels as $idx_ci => $nombre_ci) {
    $cumplimiento_inferior_items[] = [
        'nombre' => $nombre_ci,
        'real'   => (int)($cumplimiento_inferior_real[$idx_ci] ?? 0),
        'meta'   => (int)($cumplimiento_inferior_meta[$idx_ci] ?? 0),
        'pct'    => (float)($cumplimiento_inferior_pct[$idx_ci] ?? 0),
    ];
}
usort($cumplimiento_inferior_items, function($a, $b) {
    if ((float)$a['pct'] == (float)$b['pct']) {
        return ((int)$b['real']) <=> ((int)$a['real']);
    }
    return ((float)$b['pct']) <=> ((float)$a['pct']);
});

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
            font-size:.72rem;
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
            height:16px;
            background:#eef2ff;
            border-radius:999px;
            overflow:hidden;
            border:1px solid #e2e8f0;
        }
        .cumplimiento-fill{
            height:100%;
            border-radius:999px;
            min-width:4px;
        }
        .cumplimiento-fill.ok{background:#16a34a;}
        .cumplimiento-fill.warn{background:#f59e0b;}
        .cumplimiento-fill.risk{background:#ef4444;}
        .cumplimiento-metric{
            font-size:.78rem;
            font-weight:900;
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

        @media(max-width:900px){
            .dashboard-range-card{align-items:flex-start;flex-direction:column}
            .range-panel{left:auto;right:0;width:320px}
            .hierarchy-select{min-width:100%}
        }
    </style>
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
                            <?php if (!in_array($k, ['dia_inicio','dia_fin','rango_mode'], true)): ?>
                                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <input type="hidden" name="rango_mode" value="custom">
                        <input type="hidden" name="dia_inicio" id="dashDiaInicioInput" value="<?= htmlspecialchars($dia_inicio_dashboard) ?>">
                        <input type="hidden" name="dia_fin" id="dashDiaFinInput" value="<?= htmlspecialchars($dia_fin_dashboard) ?>">

                        <div class="range-panel-title">Selecciona rango de <?= htmlspecialchars($meses_es[$mes_actual]) ?> <?= htmlspecialchars($anio_query) ?></div>
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
               href="?<?= http_build_query(array_merge($_GET, ['rango_mode'=>'mtd','dia_inicio'=>1,'dia_fin'=>$dia_max_corte])) ?>">
                MTD vencido
            </a>
            <a class="range-action-btn <?= ($rango_mode === 'completo') ? 'active' : '' ?>"
               href="?<?= http_build_query(array_merge($_GET, ['rango_mode'=>'completo','dia_inicio'=>null,'dia_fin'=>null])) ?>">
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
                    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
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


    <?php if (!empty($cumplimiento_inferior_items)): ?>
    <div class="evo-card hierarchy-performance-card">
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
                    $real_item = (int)($item['real'] ?? 0);
                    $meta_item = (int)($item['meta'] ?? 0);
                    $bar_width = min($pct_item, 180);
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
                <div style="overflow-x:auto;">
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