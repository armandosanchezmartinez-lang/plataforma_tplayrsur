<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

$conexion_path_1 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';
$conexion_path_2 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/conexion.php';

if (file_exists($conexion_path_1)) {
    include $conexion_path_1;
} elseif (file_exists($conexion_path_2)) {
    include $conexion_path_2;
} else {
    die("No se encontró archivo de conexión.");
}

$usuario = $_SESSION['usuario'] ?? 'sistema';
$rol = $_SESSION['rol'] ?? 'vendedor';
$id_posicion_sesion = $_SESSION['id_posicion'] ?? '';
$numero_talento_sesion = $_SESSION['numero_talento_gs'] ?? '';

$mensaje = '';
$tipo_mensaje = '';

function h($txt) {
    return htmlspecialchars((string)$txt, ENT_QUOTES, 'UTF-8');
}

function normalizar_texto($txt) {
    $txt = trim((string)$txt);
    $txt = mb_strtoupper($txt, 'UTF-8');
    $buscar  = ['Á','É','Í','Ó','Ú','Ü'];
    $cambiar = ['A','E','I','O','U','U'];
    $txt = str_replace($buscar, $cambiar, $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    return $txt;
}

function normalizar_distrito_eo($distrito) {
    $raw = normalizar_texto($distrito);
    $clean = str_replace(['/', '-'], ' ', $raw);
    $clean = preg_replace('/\s+/', ' ', $clean);
    if (strpos($clean, 'COATZA') !== false && strpos($clean, 'MINA') !== false) {
        return 'COATZA / MINA';
    }
    return $raw;
}

function distrito_norm_sql($col = 'distrito') {
    return "CASE
        WHEN UPPER(TRIM($col)) LIKE '%COATZA%' AND UPPER(TRIM($col)) LIKE '%MINA%'
        THEN 'COATZA / MINA'
        ELSE UPPER(TRIM($col))
    END";
}

function nivel_desde_posicion($posicion) {
    $p = normalizar_texto($posicion);
    if ($p === 'DIRECTOR DISTRITAL') return 'DIRECTOR_DISTRITAL';
    if ($p === 'LIDER VENTAS' || $p === 'LIDER PROMOVENDEDOR/PROMOTOR') return 'LIDER_VENTAS';
    if ($p === 'COACH VENTAS' || $p === 'COACH DE VENTAS' || $p === 'COACH PROMOVENDEDOR PUNTO DE VENTA' || $p === 'COACH PROMOTOR PDV') return 'COACH_VENTAS';
    if ($p === 'VENDEDOR' || $p === 'VENDEDOR NEGOCIOS' || $p === 'VENDEDOR NEGOCIO' || $p === 'PROMOVENDEDOR PUNTO DE VENTA') return 'VENDEDOR';
    return 'OTRO';
}

function siguiente_nivel($nivel) {
    if ($nivel === 'DIRECTOR_DISTRITAL') return 'LIDER_VENTAS';
    if ($nivel === 'LIDER_VENTAS') return 'COACH_VENTAS';
    if ($nivel === 'COACH_VENTAS') return 'VENDEDOR';
    return null;
}

function etiqueta_nivel($nivel) {
    $map = [
        'DIRECTOR_DISTRITAL' => 'Director Distrital',
        'LIDER_VENTAS' => 'Líder de Venta',
        'COACH_VENTAS' => 'Coach de Venta',
        'VENDEDOR' => 'Vendedor',
        'OTRO' => 'Otro'
    ];
    return $map[$nivel] ?? $nivel;
}

function semana_anterior_calc($semana, $anio) {
    $sem_ant = (int)$semana - 1;
    $anio_ant = (int)$anio;
    if ($sem_ant <= 0) {
        $sem_ant = 52;
        $anio_ant--;
    }
    return [$sem_ant, $anio_ant];
}

function buscar_responsable_sesion($conexion, $anio_actual, $semana_actual, $id_posicion_sesion, $numero_talento_sesion, $usuario, $rol) {
    $responsable = null;

    if ($id_posicion_sesion !== '') {
        $sql = "SELECT id_posicion, posicion_lr, numero_talento_gs, nombre_colaborador, posicion AS puesto_responsable, distrito
                FROM hc
                WHERE id_posicion = ? AND anio = ? AND semana = ? AND nombre_colaborador NOT LIKE '%VACANTE%'
                LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sii", $id_posicion_sesion, $anio_actual, $semana_actual);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $responsable = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }

        if (!$responsable) {
            $sql = "SELECT id_posicion, posicion_lr, numero_talento_gs, nombre_colaborador, posicion AS puesto_responsable, distrito
                    FROM hc
                    WHERE id_posicion = ? AND nombre_colaborador NOT LIKE '%VACANTE%'
                    ORDER BY anio DESC, semana DESC
                    LIMIT 1";
            $stmt = mysqli_prepare($conexion, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $id_posicion_sesion);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $responsable = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);
            }
        }
    }

    if (!$responsable && $numero_talento_sesion !== '') {
        $sql = "SELECT id_posicion, posicion_lr, numero_talento_gs, nombre_colaborador, posicion AS puesto_responsable, distrito
                FROM hc
                WHERE numero_talento_gs = ? AND nombre_colaborador NOT LIKE '%VACANTE%'
                ORDER BY anio DESC, semana DESC
                LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $numero_talento_sesion);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $responsable = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }
    }

    if (!$responsable) {
        $responsable = [
            'id_posicion' => $id_posicion_sesion ?: 'SIN_POSICION',
            'posicion_lr' => null,
            'numero_talento_gs' => $numero_talento_sesion,
            'nombre_colaborador' => $usuario,
            'puesto_responsable' => strtoupper($rol),
            'distrito' => ''
        ];
    }

    return $responsable;
}

function cargar_forecast_actual($conexion, $anio, $semana, $id_posicion) {
    $sql = "SELECT * FROM metas_forecast_semanal WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
}

function cargar_compromiso_actual($conexion, $anio, $semana, $id_posicion) {
    $sql = "SELECT * FROM metas_fcst_compromiso_semanal WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
}

function cargar_o_crear_ejecucion($conexion, $anio, $semana, $resp, $nivel, $distrito, $usuario) {
    $id_posicion = (string)$resp['id_posicion'];
    $sql = "SELECT * FROM ejecucion_operativa WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    if ($row) return $row;

    $sql = "INSERT INTO ejecucion_operativa (
                anio, semana, id_posicion, posicion_lr, numero_talento_gs,
                nombre_responsable, puesto_responsable, nivel_ejecucion, distrito, usuario_captura
            ) VALUES (?,?,?,?,?,?,?,?,?,?)";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) throw new Exception(mysqli_error($conexion));

    $posicion_lr = $resp['posicion_lr'] ?? null;
    $numero_talento_gs = $resp['numero_talento_gs'] ?? null;
    $nombre = $resp['nombre_colaborador'] ?? '';
    $puesto = $resp['puesto_responsable'] ?? '';

    mysqli_stmt_bind_param($stmt, "iissssssss", $anio, $semana, $id_posicion, $posicion_lr, $numero_talento_gs, $nombre, $puesto, $nivel, $distrito, $usuario);
    if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conexion, "SELECT * FROM ejecucion_operativa WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
}


function cargar_ejecucion_existente($conexion, $anio, $semana, $id_posicion) {
    $stmt = mysqli_prepare($conexion, "SELECT * FROM ejecucion_operativa WHERE anio = ? AND semana = ? AND id_posicion = ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function cargar_plan($conexion, $id_ejecucion) {
    $sql = "SELECT * FROM ejecucion_operativa_plan WHERE id_ejecucion = ? LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row;
}

function cargar_palancas($conexion) {
    $rows = [];
    $res = mysqli_query($conexion, "SELECT * FROM ejecucion_operativa_palancas WHERE activo = 1 ORDER BY id ASC");
    while ($res && $row = mysqli_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

function cargar_palancas_seleccionadas($conexion, $id_ejecucion) {
    $map = [];
    $sql = "SELECT pp.*, p.nombre, p.descripcion
            FROM ejecucion_operativa_plan_palancas pp
            INNER JOIN ejecucion_operativa_palancas p ON p.id = pp.id_palanca
            WHERE pp.id_ejecucion = ?
            ORDER BY
                CASE pp.prioridad
                    WHEN 'ALTA' THEN 1
                    WHEN 'MEDIA' THEN 2
                    WHEN 'BAJA' THEN 3
                    ELSE 4
                END,
                p.nombre ASC";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return $map;
    mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $map[(int)$row['id_palanca']] = $row;
    mysqli_stmt_close($stmt);
    return $map;
}

function cargar_acciones($conexion, $id_ejecucion) {
    $rows = [];
    $sql = "SELECT * FROM ejecucion_operativa_acciones
            WHERE id_ejecucion = ?
            ORDER BY
                CASE WHEN fecha_compromiso IS NULL THEN 1 ELSE 0 END,
                fecha_compromiso ASC,
                CASE prioridad
                    WHEN 'ALTA' THEN 1
                    WHEN 'MEDIA' THEN 2
                    WHEN 'BAJA' THEN 3
                    ELSE 4
                END,
                id ASC";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return $rows;
    mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
}

function fecha_lunes_iso_semana($anio, $semana) {
    $dt = new DateTime();
    $dt->setISODate((int)$anio, (int)$semana, 1);
    $dt->setTime(0, 0, 0);
    return $dt;
}

function acciones_por_dia_semana($acciones, $anio, $semana) {
    $dias = [];
    $lunes = fecha_lunes_iso_semana($anio, $semana);
    for ($i = 0; $i < 7; $i++) {
        $d = clone $lunes;
        $d->modify("+{$i} days");
        $dias[$d->format('Y-m-d')] = [
            'fecha' => $d,
            'acciones' => []
        ];
    }

    foreach ($acciones as $a) {
        $fecha = $a['fecha_compromiso'] ?? '';
        if ($fecha && isset($dias[$fecha])) {
            $dias[$fecha]['acciones'][] = $a;
        }
    }

    return $dias;
}

function etiqueta_dia_corta($n) {
    $map = [
        1 => 'LUNES',
        2 => 'MARTES',
        3 => 'MIÉRCOLES',
        4 => 'JUEVES',
        5 => 'VIERNES',
        6 => 'SÁBADO',
        7 => 'DOMINGO'
    ];
    return $map[(int)$n] ?? '';
}

function formato_fecha_mx($dt) {
    return $dt->format('d/m/Y');
}

function accion_prioridad_cls($prioridad) {
    $p = strtoupper((string)$prioridad);
    if ($p === 'ALTA') return 'alta';
    if ($p === 'BAJA') return 'baja';
    return 'media';
}

function accion_estatus_label($estatus) {
    $map = [
        'PENDIENTE' => 'Pendiente',
        'EN_PROCESO' => 'En proceso',
        'COMPLETADA' => 'Completada',
        'CANCELADA' => 'Cancelada'
    ];
    return $map[$estatus] ?? $estatus;
}

function cargar_acompanamientos_por_accion($conexion, $id_ejecucion) {
    $map = [];
    $stmt = mysqli_prepare($conexion, "SELECT * FROM ejecucion_operativa_acompanamientos WHERE id_ejecucion = ? AND id_accion IS NOT NULL ORDER BY fecha_hora DESC, id DESC");
    if (!$stmt) return $map;
    mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $id_accion = (int)($row['id_accion'] ?? 0);
        if ($id_accion <= 0) continue;
        if (!isset($map[$id_accion])) $map[$id_accion] = [];
        $map[$id_accion][] = $row;
    }
    mysqli_stmt_close($stmt);
    return $map;
}

function tipo_acompanamiento_label($tipo) {
    $map = [
        'SHADOWING' => 'Shadowing',
        'COACHING_1_1' => 'Coaching 1:1',
        'SEGUIMIENTO' => 'Seguimiento',
        'SUPERVISION_CAMPO' => 'Supervisión campo',
        'OTRO' => 'Otro'
    ];
    return $map[$tipo] ?? $tipo;
}

function evidencia_link($ruta) {
    $ruta = trim((string)$ruta);
    if ($ruta === '') return '';
    if (preg_match('/^https?:\/\//i', $ruta)) return $ruta;
    return '../' . ltrim($ruta, '/');
}

function fecha_hora_mx($fecha_hora) {
    if (!$fecha_hora) return '—';
    $ts = strtotime($fecha_hora);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha_hora;
}


function cargar_subordinados_directos($conexion, $anio, $semana, $id_posicion, $nivel_sub) {
    $rows = [];
    $sql = "SELECT id_posicion, posicion_lr, numero_talento_gs, nombre_colaborador, posicion, distrito
            FROM hc
            WHERE anio = ?
              AND semana = ?
              AND posicion_lr = ?
              AND nombre_colaborador NOT LIKE '%VACANTE%'
              AND numero_talento_gs NOT LIKE '%VACANTE%'
            ORDER BY nombre_colaborador ASC";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return $rows;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $nivel_detectado = nivel_desde_posicion($row['posicion'] ?? '');
        if ($nivel_detectado === $nivel_sub) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function cargar_metas_asignadas_por_superior($conexion, $anio, $semana, $id_superior) {
    $map = [];
    $stmt = mysqli_prepare($conexion, "SELECT * FROM ejecucion_operativa_metas WHERE anio = ? AND semana = ? AND id_superior = ?");
    if (!$stmt) return $map;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_superior);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $map[$row['id_subordinado']] = $row;
    mysqli_stmt_close($stmt);
    return $map;
}

function obtener_meta_responsable($conexion, $anio, $semana, $id_posicion, $nivel, $distrito) {
    if ($nivel === 'DIRECTOR_DISTRITAL') {
        $meta = 0;
        $dn_meta = distrito_norm_sql('distrito');
        $sql = "SELECT SUM(meta) AS total FROM metas_instalacion_semanal WHERE anio = ? AND semana = ? AND $dn_meta = ?";
        $stmt = mysqli_prepare($conexion, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $distrito);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) $meta = (int)($row['total'] ?? 0);
            mysqli_stmt_close($stmt);
        }
        return $meta;
    }

    $sql = "SELECT meta_asignada FROM ejecucion_operativa_metas WHERE anio = ? AND semana = ? AND id_subordinado = ? ORDER BY updated_at DESC LIMIT 1";
    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $id_posicion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $meta = 0;
    if ($row = mysqli_fetch_assoc($res)) $meta = (int)($row['meta_asignada'] ?? 0);
    mysqli_stmt_close($stmt);
    return $meta;
}

function obtener_ins_semana($conexion, $anio, $semana, $nivel, $distrito, $folio) {
    $total = 0;
    if ($nivel === 'DIRECTOR_DISTRITAL') {
        $dn_inst = distrito_norm_sql('distrito');
        $sql = "SELECT COUNT(cuenta) AS total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND $dn_inst=? AND origen_prospecto <> '-'";
        $stmt = mysqli_prepare($conexion, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $distrito);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) $total = (int)($row['total'] ?? 0);
            mysqli_stmt_close($stmt);
        }
    } elseif ($folio !== '') {
        $sql = "SELECT COUNT(cuenta) AS total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND folio_empleado=? AND origen_prospecto <> '-'";
        $stmt = mysqli_prepare($conexion, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iis", $anio, $semana, $folio);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) $total = (int)($row['total'] ?? 0);
            mysqli_stmt_close($stmt);
        }
    }
    return $total;
}

$anio_hoy = (int)date('Y');
$semana_hoy = (int)date('W');

$anio_actual = isset($_GET['anio']) ? (int)$_GET['anio'] : $anio_hoy;
$semana_actual = isset($_GET['semana']) ? (int)$_GET['semana'] : $semana_hoy;
if ($semana_actual < 1) $semana_actual = 1;
if ($semana_actual > 53) $semana_actual = 53;

$max_anio_nav = $anio_hoy;
$max_semana_nav = $semana_hoy + 1;
if ($max_semana_nav > 53) {
    $max_semana_nav = 1;
    $max_anio_nav++;
}

if ($anio_actual > $max_anio_nav || ($anio_actual === $max_anio_nav && $semana_actual > $max_semana_nav)) {
    $anio_actual = $max_anio_nav;
    $semana_actual = $max_semana_nav;
}

list($semana_anterior, $anio_semana_anterior) = semana_anterior_calc($semana_actual, $anio_actual);

/*
 * Regla HC / Línea de reporte:
 * - Semana pasada o histórica: HC de la misma semana seleccionada.
 * - Semana corriente y una semana futura permitida: HC de la última semana cerrada disponible.
 *   Ejemplo: SEM 22 y SEM 23 usan HC SEM 21 cuando SEM 22 es la corriente.
 */
if ($anio_actual < $anio_hoy || ($anio_actual === $anio_hoy && $semana_actual < $semana_hoy)) {
    $anio_hc = $anio_actual;
    $semana_hc = $semana_actual;
} else {
    list($semana_hc, $anio_hc) = semana_anterior_calc($semana_hoy, $anio_hoy);
}

$es_semana_pasada = ($anio_actual < $anio_hoy || ($anio_actual === $anio_hoy && $semana_actual < $semana_hoy));
$es_semana_actual = ($anio_actual === $anio_hoy && $semana_actual === $semana_hoy);
$es_semana_futura = ($anio_actual > $anio_hoy || ($anio_actual === $anio_hoy && $semana_actual > $semana_hoy));

$id_posicion_consulta = isset($_GET['id_posicion']) ? trim((string)$_GET['id_posicion']) : $id_posicion_sesion;
$responsable = buscar_responsable_sesion($conexion, $anio_hc, $semana_hc, $id_posicion_consulta, '', $usuario, $rol);
$id_posicion = (string)($responsable['id_posicion'] ?? $id_posicion_consulta);
$posicion_lr = $responsable['posicion_lr'] ?? null;
$numero_talento_gs = $responsable['numero_talento_gs'] ?? '';
$nombre_responsable = $responsable['nombre_colaborador'] ?? $usuario;
$puesto_responsable = $responsable['puesto_responsable'] ?? strtoupper($rol);
$distrito = normalizar_distrito_eo($responsable['distrito'] ?? '');
$nivel_ejecucion = nivel_desde_posicion($puesto_responsable);
$nivel_subordinado = siguiente_nivel($nivel_ejecucion);

if (!in_array($nivel_ejecucion, ['DIRECTOR_DISTRITAL','LIDER_VENTAS','COACH_VENTAS'], true)) {
    die('No se encontró un responsable válido para consultar Ejecución Operativa.');
}

$ejecucion = cargar_ejecucion_existente($conexion, $anio_actual, $semana_actual, $id_posicion);
$id_ejecucion = $ejecucion ? (int)$ejecucion['id'] : 0;
$estatus_ejecucion = $ejecucion['estatus'] ?? 'SIN PLAN';
$bloqueado = true;

$forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$plan_row = cargar_plan($conexion, $id_ejecucion);

/* Consulta solo lectura: no procesa POST. */

$meta_responsable = obtener_meta_responsable($conexion, $anio_actual, $semana_actual, $id_posicion, $nivel_ejecucion, $distrito);
$forecast_valor = $forecast_row ? (int)$forecast_row['forecast'] : 0;
$ins_semana = obtener_ins_semana($conexion, $anio_actual, $semana_actual, $nivel_ejecucion, $distrito, $numero_talento_gs);
$avance_meta_pct = ($meta_responsable > 0) ? round(($ins_semana / $meta_responsable) * 100) : 0;
$avance_fcst_pct = ($forecast_valor > 0) ? round(($ins_semana / $forecast_valor) * 100) : 0;

$impulso = $compromiso_row['impulso_semana_anterior'] ?? '';
$resto = $compromiso_row['resto_semana_anterior'] ?? '';
$competencia_txt = $compromiso_row['competencia'] ?? '';
$acciones_fcst = $compromiso_row['acciones_clave'] ?? '';
$necesidades = $compromiso_row['necesidades_apoyo'] ?? '';

$estrategia_general = $plan_row['estrategia_general'] ?? '';
$riesgos_detectados = $plan_row['riesgos_detectados'] ?? '';
$apoyos_requeridos = $plan_row['apoyos_requeridos'] ?? '';
$observaciones = $plan_row['observaciones'] ?? '';

$palancas = cargar_palancas($conexion);
$palancas_seleccionadas = cargar_palancas_seleccionadas($conexion, $id_ejecucion);
$acciones_guardadas = cargar_acciones($conexion, $id_ejecucion);
$acompanamientos_por_accion = cargar_acompanamientos_por_accion($conexion, $id_ejecucion);
$acciones_calendario = acciones_por_dia_semana($acciones_guardadas, $anio_actual, $semana_actual);
$subordinados = $nivel_subordinado ? cargar_subordinados_directos($conexion, $anio_hc, $semana_hc, $id_posicion, $nivel_subordinado) : [];
$metas_asignadas = cargar_metas_asignadas_por_superior($conexion, $anio_actual, $semana_actual, $id_posicion);

$meta_distribuida = 0;
foreach ($subordinados as $sub) {
    $sid = $sub['id_posicion'];
    $meta_distribuida += (int)($metas_asignadas[$sid]['meta_asignada'] ?? 0);
}
$pendiente_asignar = max(0, $meta_responsable - $meta_distribuida);
$disabled = 'disabled';
$readonly_class = 'readonly';

// Consulta: no se agregan filas vacías de acciones.

list($semana_nav_prev, $anio_nav_prev) = semana_anterior_calc($semana_actual, $anio_actual);
$semana_nav_next = $semana_actual + 1;
$anio_nav_next = $anio_actual;
if ($semana_nav_next > 53) {
    $semana_nav_next = 1;
    $anio_nav_next++;
}
$mostrar_next = !($anio_nav_next > $max_anio_nav || ($anio_nav_next === $max_anio_nav && $semana_nav_next > $max_semana_nav));
$modo_semana = 'SOLO LECTURA';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta Ejecución Operativa - TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=174">
    <style>
        :root{--tx-purple:#7A2BFF;--tx-pink:#FF0AC8;--tx-cyan:#00D8FF;--tx-card:rgba(255,255,255,.90);--tx-border:#e2e8f0;--tx-text:#1a2540;--tx-muted:#6b7a99;--tx-green:#10b981;--tx-red:#ef4444;--tx-orange:#f59e0b;}
        *{box-sizing:border-box}
        body{margin:0;font-family:'Poppins','Segoe UI',sans-serif;background:radial-gradient(circle at 8% 8%,rgba(255,10,200,.10),transparent 28%),radial-gradient(circle at 92% 14%,rgba(0,216,255,.09),transparent 30%),linear-gradient(180deg,#f7f8ff 0%,#eef5ff 100%);color:var(--tx-text);min-height:100vh;display:flex}
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}
        .page-title h1{margin:0;font-size:1.65rem;letter-spacing:-.4px}.page-title p{margin:6px 0 0;color:var(--tx-muted);font-size:.9rem}
        .pill-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.pill{border:1px solid var(--tx-border);background:var(--tx-card);padding:8px 12px;border-radius:999px;font-size:.78rem;font-weight:800;color:var(--tx-muted)}.pill.active{color:white;background:linear-gradient(135deg,var(--tx-purple) 0%,var(--tx-pink) 100%);border:none}
        .status-card{background:var(--tx-card);border:1px solid var(--tx-border);border-radius:22px;padding:18px 20px;min-width:300px;box-shadow:0 14px 32px rgba(22,28,60,.08)}.status-label{font-size:.72rem;text-transform:uppercase;color:var(--tx-muted);font-weight:900;letter-spacing:.7px}.status-main{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:8px}
        .badge{display:inline-flex;border-radius:999px;padding:7px 12px;font-size:.78rem;font-weight:900}.badge.borrador{color:#92400e;background:#fef3c7}.badge.enviado{color:#1d4ed8;background:#dbeafe}.badge.cerrado{color:#166534;background:#dcfce7}.badge.rojo{color:#991b1b;background:#fee2e2}.badge.verde{color:#166534;background:#dcfce7}.badge.morado{color:#5b21b6;background:#ede9fe}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{background:var(--tx-card);border:1px solid var(--tx-border);border-radius:24px;padding:22px;box-shadow:0 14px 32px rgba(22,28,60,.08)}.card.full{grid-column:1/-1}.card-title{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.card-title h2{margin:0;font-size:1.05rem}.card-title span{color:var(--tx-muted);font-size:.78rem;font-weight:800}
        .kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}.kpi-box{border-radius:18px;padding:16px;background:rgba(255,255,255,.70);border:1px solid #e2e8f0}.kpi-box .label{color:var(--tx-muted);font-weight:900;font-size:.72rem;text-transform:uppercase;letter-spacing:.6px}.kpi-box .value{margin-top:8px;font-size:1.45rem;font-weight:900;color:var(--tx-purple);line-height:1.1}.kpi-box .sub{font-size:.72rem;color:var(--tx-muted);margin-top:5px;font-weight:700}
        .field{margin-bottom:16px}.field label{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;font-weight:900;color:var(--tx-muted);margin-bottom:8px}.field input,.field textarea,.field select{width:100%;border:1.5px solid #dbe4f0;border-radius:16px;padding:12px 13px;font-family:inherit;font-size:.88rem;outline:none;background:rgba(255,255,255,.82);color:var(--tx-text)}.field textarea{min-height:108px;resize:vertical;line-height:1.45}.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--tx-purple);box-shadow:0 0 0 4px rgba(122,43,255,.10);background:white}.field input:disabled,.field textarea:disabled,.field select:disabled,.readonly input,.readonly textarea,.readonly select{background:#f8fafc;color:#64748b;cursor:not-allowed}
        .alert{border-radius:16px;padding:14px 16px;margin-bottom:18px;line-height:1.45;font-weight:700}.alert.exito{background:#dcfce7;color:#166534}.alert.error{background:#fee2e2;color:#991b1b}.helper{font-size:.78rem;color:var(--tx-muted);line-height:1.45;margin-top:8px}
        .table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:16px;background:rgba(255,255,255,.70)}table{width:100%;border-collapse:collapse;font-size:.8rem}th,td{padding:12px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{background:#f8fafc;color:#475569;text-transform:uppercase;letter-spacing:.45px;font-size:.7rem;font-weight:900}tr:last-child td{border-bottom:none}.num{text-align:right}.sub-name{font-weight:900}.sub-meta-input{max-width:120px;text-align:right;font-weight:900}.mini{font-size:.72rem;color:var(--tx-muted);font-weight:700;margin-top:2px}
        .palanca-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.palanca-item{border:1px solid #e2e8f0;background:rgba(255,255,255,.68);border-radius:18px;padding:14px}.palanca-top{display:flex;align-items:center;gap:10px;margin-bottom:8px}.palanca-top input{width:auto}.palanca-name{font-weight:900}
        .palancas-consulta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
        .palanca-ficha{position:relative;border:1px solid #e2e8f0;background:rgba(255,255,255,.78);border-radius:20px;padding:16px 16px 15px 18px;box-shadow:0 10px 24px rgba(22,28,60,.06);overflow:hidden}
        .palanca-ficha:before{content:'';position:absolute;left:0;top:0;bottom:0;width:6px;background:#94a3b8}
        .palanca-ficha.prioridad-alta:before{background:#ef4444}
        .palanca-ficha.prioridad-media:before{background:#f59e0b}
        .palanca-ficha.prioridad-baja:before{background:#10b981}
        .palanca-ficha-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px}
        .palanca-ficha-title{font-size:1rem;font-weight:950;color:var(--tx-text);line-height:1.2}
        .prioridad-chip{border-radius:999px;padding:5px 9px;font-size:.68rem;font-weight:950;white-space:nowrap}
        .prioridad-chip.alta{background:#fee2e2;color:#991b1b}
        .prioridad-chip.media{background:#fef3c7;color:#92400e}
        .prioridad-chip.baja{background:#dcfce7;color:#166534}
        .palanca-ficha-desc{font-size:.76rem;color:var(--tx-muted);font-weight:800;line-height:1.35;margin-bottom:10px}
        .palanca-ficha-comment{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:11px 12px;color:#334155;font-size:.82rem;line-height:1.4;min-height:44px}
        .palanca-ficha-comment .comment-label{display:block;text-transform:uppercase;font-size:.64rem;letter-spacing:.5px;color:#64748b;font-weight:950;margin-bottom:4px}
        .empty-state{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:18px;padding:16px;color:#64748b;font-weight:850}
        .actions-grid td input,.actions-grid td textarea,.actions-grid td select{font-size:.78rem;padding:9px;border-radius:12px}.actions-grid textarea{min-height:50px}
        .calendar-wrap{overflow-x:auto;border:1px solid #dbe4f0;border-radius:18px;background:rgba(255,255,255,.82)}
        .calendar-table{width:100%;min-width:1050px;border-collapse:collapse;table-layout:fixed;background:white}
        .calendar-table th{padding:10px 8px;text-align:center;border-right:1px solid #dbe4f0;border-bottom:1px solid #dbe4f0;background:#f8fafc;color:#111827;font-size:.92rem;font-weight:950;letter-spacing:.2px}
        .calendar-table th.domingo{color:#dc2626}
        .calendar-table .date-row th{font-size:.82rem;background:#fff;color:#111827}
        .calendar-table .date-row th.domingo{color:#dc2626}
        .calendar-table td{height:210px;vertical-align:top;border-right:1px solid #dbe4f0;padding:10px;background:#fff}
        .calendar-table th:last-child,.calendar-table td:last-child{border-right:none}
        .calendar-empty{height:100%;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.78rem;font-weight:800;text-align:center}
        .calendar-action{position:relative;margin-bottom:9px;border:1px solid #e2e8f0;border-radius:14px;padding:10px 10px 9px 12px;background:#f8fafc;box-shadow:0 6px 14px rgba(22,28,60,.05);overflow:hidden}
        .calendar-action:before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:#f59e0b}
        .calendar-action.prioridad-alta:before{background:#ef4444}
        .calendar-action.prioridad-media:before{background:#f59e0b}
        .calendar-action.prioridad-baja:before{background:#10b981}
        .calendar-action-title{font-size:.82rem;font-weight:950;color:#1a2540;line-height:1.25;margin-bottom:5px}
        .calendar-action-desc{font-size:.72rem;color:#475569;line-height:1.35;margin-bottom:7px}
        .calendar-action-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        .calendar-chip{border-radius:999px;padding:4px 7px;font-size:.62rem;font-weight:950;background:#e8eef7;color:#334155}
        .calendar-chip.alta{background:#fee2e2;color:#991b1b}
        .calendar-chip.media{background:#fef3c7;color:#92400e}
        .calendar-chip.baja{background:#dcfce7;color:#166534}
        .calendar-resp{font-size:.68rem;color:#64748b;font-weight:850;margin-top:6px}
        .calendar-comment{font-size:.68rem;color:#64748b;margin-top:6px;line-height:1.3}
        .calendar-eye-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px}
        .acomp-count{font-size:.66rem;color:#64748b;font-weight:900}
        .btn-eye{border:1px solid #d8c7ff;background:#f4efff;color:#4c1d95;border-radius:999px;padding:6px 10px;font-size:.72rem;font-weight:950;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px}.btn-eye:hover{background:linear-gradient(135deg,rgba(122,43,255,.13),rgba(255,10,200,.10));border-color:#a78bfa}
        .modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9998;padding:24px;align-items:center;justify-content:center}.modal-backdrop.active{display:flex}
        .modal-card{width:min(980px,96vw);max-height:88vh;overflow:auto;background:white;border-radius:24px;border:1px solid #e2e8f0;box-shadow:0 24px 80px rgba(15,23,42,.35)}
        .modal-head{position:sticky;top:0;background:white;z-index:2;display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:18px 20px;border-bottom:1px solid #e2e8f0}.modal-title{font-weight:950;font-size:1.05rem;color:var(--tx-text)}.modal-sub{font-size:.76rem;color:var(--tx-muted);font-weight:800;margin-top:3px}.modal-close{border:none;background:#e8eef7;color:#1a2540;border-radius:12px;padding:8px 11px;font-weight:950;cursor:pointer}.modal-body{padding:18px 20px}
        .acomp-card{border:1px solid #e2e8f0;background:#f8fafc;border-radius:18px;padding:14px 15px;margin-bottom:12px}.acomp-top{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}.acomp-person{font-weight:950;color:#1a2540}.acomp-meta{font-size:.72rem;color:#64748b;font-weight:850;margin-top:2px}.acomp-chip{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:.68rem;font-weight:950;background:#ede9fe;color:#5b21b6}.acomp-section{font-size:.8rem;color:#334155;line-height:1.4;margin-top:8px}.acomp-section strong{color:#1a2540}.evidencia-btn{display:inline-flex;align-items:center;gap:6px;margin-top:10px;text-decoration:none;border-radius:999px;padding:8px 11px;background:#eef2ff;color:#3730a3;font-weight:950;font-size:.74rem;border:1px solid #c7d2fe}

        .actions{display:flex;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap}.actions-right{display:flex;gap:12px;flex-wrap:wrap}.btn{border:none;border-radius:14px;padding:12px 18px;font-weight:900;cursor:pointer;font-size:.9rem;font-family:inherit;text-decoration:none}.btn-secondary{background:#e8eef7;color:#1a2540}.btn-primary{color:white;background:linear-gradient(135deg,var(--tx-purple) 0%,var(--tx-pink) 100%);box-shadow:0 12px 28px rgba(122,43,255,.20)}.btn-danger{color:white;background:linear-gradient(135deg,#16a34a 0%,#059669 100%);box-shadow:0 12px 28px rgba(22,163,74,.18)}.btn:disabled{opacity:.45;cursor:not-allowed}.btn-add{background:#e8eef7;color:#1a2540;border:1px solid var(--tx-border);margin-top:14px}.week-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}.week-nav a,.week-nav span{display:inline-flex;align-items:center;border-radius:999px;padding:9px 14px;font-size:.78rem;font-weight:900;text-decoration:none}.week-nav a{border:1px solid var(--tx-border);background:rgba(255,255,255,.88);color:var(--tx-text)}.week-nav .current{color:white;background:linear-gradient(135deg,var(--tx-purple) 0%,var(--tx-pink) 100%)}.week-nav .disabled{opacity:.45;background:#e8eef7;color:#64748b}.mode-note{font-size:.74rem;color:var(--tx-muted);font-weight:800;margin-top:8px}
        @media(max-width:1150px){.grid{grid-template-columns:1fr}.kpi{grid-template-columns:1fr 1fr}.palanca-grid{grid-template-columns:1fr}.page-header{flex-direction:column}.status-card{width:100%}}
    </style>
</head>
<body>
<?php
$current_page = 'ejecucion_operativa_captura';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div class="page-title">
            <h1>🔎 Consulta Ejecución Operativa</h1>
            <p>Captura jerárquica de FCST, plan operativo y distribución de metas.</p>
            <div class="pill-row">
                <span class="pill active">CONSULTA</span>
                <span class="pill"><?= h(etiqueta_nivel($nivel_ejecucion)) ?></span>
                <span class="pill"><?= h($modo_semana) ?></span>
                <span class="pill">SEM <?= h($semana_actual) ?> · <?= h($anio_actual) ?></span><span class="pill">HC SEM <?= h($semana_hc) ?> · <?= h($anio_hc) ?></span>
            </div>
            <div class="week-nav">
                <a href="?anio=<?= h($anio_nav_prev) ?>&semana=<?= h($semana_nav_prev) ?>">← SEM <?= h($semana_nav_prev) ?></a>
                <span class="current">SEM <?= h($semana_actual) ?></span>
                <?php if ($mostrar_next): ?>
                    <a href="?anio=<?= h($anio_nav_next) ?>&semana=<?= h($semana_nav_next) ?>">SEM <?= h($semana_nav_next) ?> →</a>
                <?php else: ?>
                    <span class="disabled">SEM <?= h($semana_nav_next) ?> →</span>
                <?php endif; ?>
            </div>
            <div class="mode-note">Consulta de plan operativo en solo lectura.</div>
        </div>
        <div class="status-card">
            <div class="status-label">Consulta del plan</div>
            <div class="status-main">
                <div>
                    <strong><?= h($nombre_responsable) ?></strong><br>
                    <span style="font-size:.78rem;color:var(--tx-muted);font-weight:800;"><?= h($distrito ?: 'N/D') ?> · <?= h($puesto_responsable) ?></span>
                </div>
                <span class="badge <?= strtolower($estatus_ejecucion) ?>"><?= h($estatus_ejecucion) ?></span>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert <?= h($tipo_mensaje) ?>"><?= h($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" class="<?= h($readonly_class) ?>">
        <div class="card full">
            <div class="card-title"><h2>Resumen ejecutivo</h2><span>Automático</span></div>
            <div class="kpi">
                <div class="kpi-box"><div class="label">Meta</div><div class="value"><?= number_format($meta_responsable) ?></div><div class="sub">Meta semanal</div></div>
                <div class="kpi-box"><div class="label">FCST</div><div class="value"><?= number_format($forecast_valor) ?></div><div class="sub">Compromiso capturado</div></div>
                <div class="kpi-box"><div class="label">INS Semana</div><div class="value"><?= number_format($ins_semana) ?></div><div class="sub"><?= h($avance_meta_pct) ?>% vs meta · <?= h($avance_fcst_pct) ?>% vs FCST</div></div>
                <div class="kpi-box"><div class="label">Meta distribuida</div><div class="value"><?= number_format($meta_distribuida) ?></div><div class="sub"><?= $pendiente_asignar > 0 ? 'Pendiente: '.number_format($pendiente_asignar) : 'Completa / excedida' ?></div></div>
            </div>
        </div>

        <div class="grid" style="margin-top:20px;">
            <div class="card full">
                <div class="card-title"><h2>1. Distribución de metas</h2><span><?= h(etiqueta_nivel($nivel_ejecucion)) ?> → <?= h(etiqueta_nivel($nivel_subordinado)) ?></span></div>
                <div class="helper" style="margin-bottom:12px;">Regla: para enviar el plan, la suma asignada debe ser igual o superior a tu meta semanal.</div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Subordinado</th><th>Puesto</th><th>Distrito</th><th class="num">Meta asignada</th></tr></thead>
                        <tbody>
                            <?php if (empty($subordinados)): ?>
                                <tr><td colspan="4">No se encontraron subordinados directos con la línea de reporte HC utilizada.</td></tr>
                            <?php else: ?>
                                <?php foreach ($subordinados as $sub):
                                    $sid = $sub['id_posicion'];
                                    $meta_sub = (int)($metas_asignadas[$sid]['meta_asignada'] ?? 0);
                                ?>
                                    <tr>
                                        <td><div class="sub-name"><?= h($sub['nombre_colaborador']) ?></div><div class="mini">ID posición: <?= h($sid) ?> · Talento: <?= h($sub['numero_talento_gs']) ?></div></td>
                                        <td><?= h($sub['posicion']) ?></td>
                                        <td><?= h(normalizar_distrito_eo($sub['distrito'] ?? '')) ?></td>
                                        <td class="num"><input class="sub-meta-input" type="number" min="0" step="1" name="meta_sub[<?= h($sid) ?>]" value="<?= h($meta_sub) ?>" <?= $disabled ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pill-row">
                    <span class="pill">Meta propia: <?= number_format($meta_responsable) ?></span>
                    <span class="pill">Distribuida: <?= number_format($meta_distribuida) ?></span>
                    <span class="pill <?= $pendiente_asignar > 0 ? '' : 'active' ?>"><?= $pendiente_asignar > 0 ? 'Pendiente: '.number_format($pendiente_asignar) : 'Distribución completa' ?></span>
                </div>
            </div>

            <div class="card full">
                <div class="card-title"><h2>2. Plan Operativo</h2><span>¿Cómo lo voy a lograr?</span></div>
                    <div class="field"><label>Estrategia general</label><textarea name="estrategia_general" <?= $disabled ?>><?= h($estrategia_general) ?></textarea></div>
                    <div class="field"><label>Riesgos detectados</label><textarea name="riesgos_detectados" <?= $disabled ?>><?= h($riesgos_detectados) ?></textarea></div>
                    <div class="field"><label>Apoyos requeridos</label><textarea name="apoyos_requeridos" <?= $disabled ?>><?= h($apoyos_requeridos) ?></textarea></div>
                    <div class="field"><label>Observaciones</label><textarea name="observaciones" <?= $disabled ?>><?= h($observaciones) ?></textarea></div>
                </div>
            </div>

            <div class="card full">
                <div class="card-title"><h2>3. Palancas de ejecución</h2><span>Solo palancas seleccionadas · Prioridad alta a baja</span></div>
                <?php if (empty($palancas_seleccionadas)): ?>
                    <div class="empty-state">No hay palancas seleccionadas para este plan operativo.</div>
                <?php else: ?>
                    <div class="palancas-consulta-grid">
                        <?php foreach ($palancas_seleccionadas as $pid => $p):
                            $prioridad = strtoupper($p['prioridad'] ?? 'MEDIA');
                            $comentario = trim((string)($p['comentario'] ?? ''));
                            $prioridad_cls = strtolower($prioridad);
                        ?>
                            <div class="palanca-ficha prioridad-<?= h($prioridad_cls) ?>">
                                <div class="palanca-ficha-head">
                                    <div class="palanca-ficha-title"><?= h($p['nombre'] ?? 'Palanca') ?></div>
                                    <span class="prioridad-chip <?= h($prioridad_cls) ?>"><?= h($prioridad) ?></span>
                                </div>
                                <?php if (!empty($p['descripcion'])): ?>
                                    <div class="palanca-ficha-desc"><?= h($p['descripcion']) ?></div>
                                <?php endif; ?>
                                <div class="palanca-ficha-comment">
                                    <span class="comment-label">Comentario</span>
                                    <?= $comentario !== '' ? h($comentario) : 'Sin comentario capturado.' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card full">
                <div class="card-title"><h2>4. Acciones clave</h2><span>Calendario semanal de compromisos</span></div>
                <?php if (empty($acciones_guardadas)): ?>
                    <div class="empty-state">No hay acciones clave capturadas para este plan.</div>
                <?php else: ?>
                    <div class="calendar-wrap">
                        <table class="calendar-table">
                            <thead>
                                <tr>
                                    <?php foreach ($acciones_calendario as $fecha_iso => $dia): 
                                        $num_dia = (int)$dia['fecha']->format('N');
                                        $es_domingo_cal = $num_dia === 7;
                                    ?>
                                        <th class="<?= $es_domingo_cal ? 'domingo' : '' ?>"><?= h(etiqueta_dia_corta($num_dia)) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                                <tr class="date-row">
                                    <?php foreach ($acciones_calendario as $fecha_iso => $dia): 
                                        $num_dia = (int)$dia['fecha']->format('N');
                                        $es_domingo_cal = $num_dia === 7;
                                    ?>
                                        <th class="<?= $es_domingo_cal ? 'domingo' : '' ?>"><?= h(formato_fecha_mx($dia['fecha'])) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <?php foreach ($acciones_calendario as $fecha_iso => $dia): ?>
                                        <td>
                                            <?php if (empty($dia['acciones'])): ?>
                                                <div class="calendar-empty">Sin actividades</div>
                                            <?php else: ?>
                                                <?php foreach ($dia['acciones'] as $a): 
                                                    $pr = strtoupper($a['prioridad'] ?? 'MEDIA');
                                                    $pr_cls = accion_prioridad_cls($pr);
                                                    $estatus = $a['estatus'] ?? 'PENDIENTE';
                                                ?>
                                                    <?php
                                                        $id_accion_cal = (int)($a['id'] ?? 0);
                                                        $acomps_accion = $acompanamientos_por_accion[$id_accion_cal] ?? [];
                                                        $acomps_count = count($acomps_accion);
                                                    ?>
                                                    <div class="calendar-action prioridad-<?= h($pr_cls) ?>">
                                                        <div class="calendar-action-title"><?= h($a['accion'] ?? 'Acción') ?></div>
                                                        <?php if (!empty($a['descripcion'])): ?>
                                                            <div class="calendar-action-desc"><?= h($a['descripcion']) ?></div>
                                                        <?php endif; ?>
                                                        <div class="calendar-action-meta">
                                                            <span class="calendar-chip <?= h($pr_cls) ?>"><?= h($pr) ?></span>
                                                            <span class="calendar-chip"><?= h(accion_estatus_label($estatus)) ?></span>
                                                        </div>
                                                        <?php if (!empty($a['responsable'])): ?>
                                                            <div class="calendar-resp">Responsable: <?= h($a['responsable']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($a['comentario'])): ?>
                                                            <div class="calendar-comment">Comentario: <?= h($a['comentario']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($acomps_count > 0): ?>
                                                            <div class="calendar-eye-row">
                                                                <span class="acomp-count"><?= h($acomps_count) ?> acompañamiento<?= $acomps_count === 1 ? '' : 's' ?></span>
                                                                <button type="button" class="btn-eye" data-modal-target="modal-acomp-<?= h($id_accion_cal) ?>">👁 Ver</button>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php foreach ($acciones_guardadas as $a_modal):
                $id_accion_modal = (int)($a_modal['id'] ?? 0);
                $acomps_modal = $acompanamientos_por_accion[$id_accion_modal] ?? [];
                if (empty($acomps_modal)) continue;
            ?>
                <div class="modal-backdrop" id="modal-acomp-<?= h($id_accion_modal) ?>" aria-hidden="true">
                    <div class="modal-card">
                        <div class="modal-head">
                            <div>
                                <div class="modal-title">👁 Acompañamientos · <?= h($a_modal['accion'] ?? 'Acción clave') ?></div>
                                <div class="modal-sub"><?= h(count($acomps_modal)) ?> registro<?= count($acomps_modal) === 1 ? '' : 's' ?> documentado<?= count($acomps_modal) === 1 ? '' : 's' ?> para esta acción</div>
                            </div>
                            <button type="button" class="modal-close" data-modal-close>✕</button>
                        </div>
                        <div class="modal-body">
                            <?php foreach ($acomps_modal as $acomp): ?>
                                <div class="acomp-card">
                                    <div class="acomp-top">
                                        <div>
                                            <div class="acomp-person"><?= h($acomp['nombre_colaborador'] ?? 'Colaborador') ?></div>
                                            <div class="acomp-meta"><?= h($acomp['tipo_colaborador'] ?? '') ?> · <?= h(fecha_hora_mx($acomp['fecha_hora'] ?? '')) ?></div>
                                        </div>
                                        <span class="acomp-chip"><?= h(tipo_acompanamiento_label($acomp['tipo_acompanamiento'] ?? '')) ?></span>
                                    </div>
                                    <div class="acomp-section"><strong>Jefe que acompaña:</strong> <?= h($acomp['nombre_jefe'] ?? '—') ?></div>
                                    <div class="acomp-section"><strong>Hallazgos principales:</strong><br><?= nl2br(h($acomp['hallazgos_principales'] ?? '—')) ?></div>
                                    <div class="acomp-section"><strong>Compromisos:</strong><br><?= nl2br(h($acomp['compromisos'] ?? '—')) ?></div>
                                    <?php if (!empty($acomp['evidencia'])): ?>
                                        <a class="evidencia-btn" href="<?= h(evidencia_link($acomp['evidencia'])) ?>" target="_blank">📎 Ver evidencia</a>
                                    <?php else: ?>
                                        <div class="acomp-section"><strong>Evidencia:</strong> Sin evidencia adjunta.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card full">
                <div class="card-title"><h2>Control de consulta</h2><span>Solo lectura</span></div>
                <div class="helper">Esta vista permite revisar el plan operativo sin modificar la captura original.</div>
                <div class="actions">
                    <a href="metas_fcst_dashboard.php?anio=<?= h($anio_actual) ?>&semana=<?= h($semana_actual) ?>" class="btn btn-secondary">Volver al Dashboard Metas-FCST</a>
                </div>
            </div>
        </div>
    </form>
</main>



<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-modal-target]').forEach(function(btn){
        btn.addEventListener('click', function(){
            const modal = document.getElementById(btn.getAttribute('data-modal-target'));
            if(modal) modal.classList.add('active');
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function(btn){
        btn.addEventListener('click', function(){
            const modal = btn.closest('.modal-backdrop');
            if(modal) modal.classList.remove('active');
        });
    });
    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop){
        backdrop.addEventListener('click', function(e){
            if(e.target === backdrop) backdrop.classList.remove('active');
        });
    });
    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape') document.querySelectorAll('.modal-backdrop.active').forEach(m => m.classList.remove('active'));
    });
});
</script>

</body>

</html>
