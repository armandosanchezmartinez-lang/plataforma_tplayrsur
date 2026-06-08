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
    $stmt = mysqli_prepare($conexion, "SELECT * FROM ejecucion_operativa_plan_palancas WHERE id_ejecucion = ?");
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
    $stmt = mysqli_prepare($conexion, "SELECT * FROM ejecucion_operativa_acciones WHERE id_ejecucion = ? ORDER BY id ASC");
    if (!$stmt) return $rows;
    mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
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

$responsable = buscar_responsable_sesion($conexion, $anio_hc, $semana_hc, $id_posicion_sesion, $numero_talento_sesion, $usuario, $rol);
$id_posicion = (string)$responsable['id_posicion'];
$posicion_lr = $responsable['posicion_lr'] ?? null;
$numero_talento_gs = $responsable['numero_talento_gs'] ?? '';
$nombre_responsable = $responsable['nombre_colaborador'] ?? $usuario;
$puesto_responsable = $responsable['puesto_responsable'] ?? strtoupper($rol);
$distrito = normalizar_distrito_eo($responsable['distrito'] ?? '');
$nivel_ejecucion = nivel_desde_posicion($puesto_responsable);
$nivel_subordinado = siguiente_nivel($nivel_ejecucion);

if (!in_array($nivel_ejecucion, ['DIRECTOR_DISTRITAL','LIDER_VENTAS','COACH_VENTAS'], true)) {
    die('Este módulo está habilitado para Director Distrital, Líder de Venta y Coach de Venta.');
}

try {
    $ejecucion = cargar_o_crear_ejecucion($conexion, $anio_actual, $semana_actual, $responsable, $nivel_ejecucion, $distrito, $usuario);
} catch (Exception $e) {
    die('Error al iniciar Ejecución Operativa: ' . h($e->getMessage()));
}

$id_ejecucion = (int)$ejecucion['id'];
$estatus_ejecucion = $ejecucion['estatus'] ?? 'BORRADOR';
$bloqueado = in_array($estatus_ejecucion, ['ENVIADO','CERRADO'], true) || $es_semana_pasada;

$forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$plan_row = cargar_plan($conexion, $id_ejecucion);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion_form = $_POST['accion_form'] ?? 'guardar';

    if ($es_semana_pasada) {
        $mensaje = 'Semana histórica en modo consulta. No se pueden modificar planes anteriores.';
        $tipo_mensaje = 'error';
    } elseif ($bloqueado) {
        $mensaje = 'Este plan ya fue enviado/cerrado y no puede modificarse.';
        $tipo_mensaje = 'error';
    } else {
        // Ejecución Operativa NO captura ni actualiza FCST.
        // El FCST se mantiene únicamente como referencia de solo lectura desde Captura FCST.

        $estrategia_general = trim($_POST['estrategia_general'] ?? '');
        $riesgos_detectados = trim($_POST['riesgos_detectados'] ?? '');
        $apoyos_requeridos = trim($_POST['apoyos_requeridos'] ?? '');
        $observaciones = trim($_POST['observaciones'] ?? '');

        $metas_sub = $_POST['meta_sub'] ?? [];
        $palancas_sel = $_POST['palanca'] ?? [];
        $palancas_prioridad = $_POST['palanca_prioridad'] ?? [];
        $palancas_comentario = $_POST['palanca_comentario'] ?? [];

        $acciones_form = $_POST['accion_item'] ?? [];
        $acciones_desc = $_POST['accion_desc'] ?? [];
        $acciones_resp = $_POST['accion_resp'] ?? [];
        $acciones_fecha = $_POST['accion_fecha'] ?? [];
        $acciones_prioridad = $_POST['accion_prioridad'] ?? [];
        $acciones_estatus = $_POST['accion_estatus'] ?? [];
        $acciones_comentario = $_POST['accion_comentario'] ?? [];

        $subordinados_tmp = cargar_subordinados_directos($conexion, $anio_hc, $semana_hc, $id_posicion, $nivel_subordinado);
        $meta_responsable_tmp = obtener_meta_responsable($conexion, $anio_actual, $semana_actual, $id_posicion, $nivel_ejecucion, $distrito);
        $meta_distribuida_tmp = 0;
        foreach ($subordinados_tmp as $sub) {
            $sid = $sub['id_posicion'];
            $meta_distribuida_tmp += max(0, (int)($metas_sub[$sid] ?? 0));
        }

        $meta_minima_tmp = (int)ceil($meta_responsable_tmp * 0.80);

        if ($accion_form === 'enviar' && !empty($subordinados_tmp) && $meta_distribuida_tmp < $meta_minima_tmp) {
            $mensaje = "No se puede enviar. La meta distribuida ($meta_distribuida_tmp) es menor al mínimo requerido del 80% ($meta_minima_tmp).";
            $tipo_mensaje = 'error';
        } else {
            mysqli_begin_transaction($conexion);
            try {
                // FCST no se actualiza desde Ejecución Operativa.

                $nuevo_estatus = ($accion_form === 'enviar') ? 'ENVIADO' : 'BORRADOR';
                $sql_eo = "UPDATE ejecucion_operativa
                           SET estatus = ?, usuario_captura = ?, updated_at = CURRENT_TIMESTAMP
                           WHERE id = ?";
                $stmt = mysqli_prepare($conexion, $sql_eo);
                if (!$stmt) throw new Exception(mysqli_error($conexion));
                mysqli_stmt_bind_param($stmt, "ssi", $nuevo_estatus, $usuario, $id_ejecucion);
                if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
                mysqli_stmt_close($stmt);

                $sql_plan = "INSERT INTO ejecucion_operativa_plan (
                                id_ejecucion, estrategia_general, riesgos_detectados, apoyos_requeridos, observaciones
                            ) VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                estrategia_general = VALUES(estrategia_general),
                                riesgos_detectados = VALUES(riesgos_detectados),
                                apoyos_requeridos = VALUES(apoyos_requeridos),
                                observaciones = VALUES(observaciones)";
                $stmt = mysqli_prepare($conexion, $sql_plan);
                if (!$stmt) throw new Exception(mysqli_error($conexion));
                mysqli_stmt_bind_param($stmt, "issss", $id_ejecucion, $estrategia_general, $riesgos_detectados, $apoyos_requeridos, $observaciones);
                if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
                mysqli_stmt_close($stmt);

                foreach ($subordinados_tmp as $sub) {
                    $sid = (string)$sub['id_posicion'];
                    $meta = max(0, (int)($metas_sub[$sid] ?? 0));
                    $snombre = $sub['nombre_colaborador'];
                    $sdistrito = normalizar_distrito_eo($sub['distrito'] ?? $distrito);
                    $sql_meta = "INSERT INTO ejecucion_operativa_metas (
                                    id_ejecucion, anio, semana,
                                    id_superior, nombre_superior, nivel_superior,
                                    id_subordinado, nombre_subordinado, nivel_subordinado,
                                    distrito, meta_asignada, usuario_captura
                                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE
                                    id_ejecucion = VALUES(id_ejecucion),
                                    nombre_superior = VALUES(nombre_superior),
                                    nivel_superior = VALUES(nivel_superior),
                                    nombre_subordinado = VALUES(nombre_subordinado),
                                    nivel_subordinado = VALUES(nivel_subordinado),
                                    distrito = VALUES(distrito),
                                    meta_asignada = VALUES(meta_asignada),
                                    usuario_captura = VALUES(usuario_captura)";
                    $stmt = mysqli_prepare($conexion, $sql_meta);
                    if (!$stmt) throw new Exception(mysqli_error($conexion));
                    mysqli_stmt_bind_param(
                        $stmt,
                        "iiisssssssis",
                        $id_ejecucion, $anio_actual, $semana_actual,
                        $id_posicion, $nombre_responsable, $nivel_ejecucion,
                        $sid, $snombre, $nivel_subordinado,
                        $sdistrito, $meta, $usuario
                    );
                    if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
                    mysqli_stmt_close($stmt);
                }

                $stmt = mysqli_prepare($conexion, "DELETE FROM ejecucion_operativa_plan_palancas WHERE id_ejecucion = ?");
                mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                foreach ($palancas_sel as $id_palanca => $v) {
                    $idp = (int)$id_palanca;
                    if ($idp <= 0) continue;
                    $prioridad = $palancas_prioridad[$idp] ?? 'MEDIA';
                    if (!in_array($prioridad, ['ALTA','MEDIA','BAJA'], true)) $prioridad = 'MEDIA';
                    $comentario = trim($palancas_comentario[$idp] ?? '');
                    $stmt = mysqli_prepare($conexion, "INSERT INTO ejecucion_operativa_plan_palancas (id_ejecucion, id_palanca, prioridad, comentario) VALUES (?,?,?,?)");
                    if (!$stmt) throw new Exception(mysqli_error($conexion));
                    mysqli_stmt_bind_param($stmt, "iiss", $id_ejecucion, $idp, $prioridad, $comentario);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
                    mysqli_stmt_close($stmt);
                }

                $stmt = mysqli_prepare($conexion, "DELETE FROM ejecucion_operativa_acciones WHERE id_ejecucion = ?");
                mysqli_stmt_bind_param($stmt, "i", $id_ejecucion);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $total_acciones = max(count($acciones_form), 5);
                for ($i = 0; $i < $total_acciones; $i++) {
                    $accion_txt = trim($acciones_form[$i] ?? '');
                    if ($accion_txt === '') continue;
                    $desc = trim($acciones_desc[$i] ?? '');
                    $resp_acc = trim($acciones_resp[$i] ?? '');
                    $fecha = trim($acciones_fecha[$i] ?? '');
                    $fecha = $fecha !== '' ? $fecha : null;
                    $prioridad = $acciones_prioridad[$i] ?? 'MEDIA';
                    if (!in_array($prioridad, ['ALTA','MEDIA','BAJA'], true)) $prioridad = 'MEDIA';
                    $estatus_acc = $acciones_estatus[$i] ?? 'PENDIENTE';
                    if (!in_array($estatus_acc, ['PENDIENTE','EN_PROCESO','COMPLETADA','CANCELADA'], true)) $estatus_acc = 'PENDIENTE';
                    $comentario = trim($acciones_comentario[$i] ?? '');
                    $stmt = mysqli_prepare($conexion, "INSERT INTO ejecucion_operativa_acciones (id_ejecucion, accion, descripcion, responsable, fecha_compromiso, prioridad, estatus, comentario) VALUES (?,?,?,?,?,?,?,?)");
                    if (!$stmt) throw new Exception(mysqli_error($conexion));
                    mysqli_stmt_bind_param($stmt, "isssssss", $id_ejecucion, $accion_txt, $desc, $resp_acc, $fecha, $prioridad, $estatus_acc, $comentario);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
                    mysqli_stmt_close($stmt);
                }

                mysqli_commit($conexion);
                $mensaje = ($accion_form === 'enviar') ? '✅ Plan enviado correctamente.' : '✅ Borrador guardado correctamente.';
                $tipo_mensaje = 'exito';
            } catch (Exception $e) {
                mysqli_rollback($conexion);
                $mensaje = 'Error al guardar: ' . $e->getMessage();
                $tipo_mensaje = 'error';
            }
        }
    }

    $ejecucion = cargar_o_crear_ejecucion($conexion, $anio_actual, $semana_actual, $responsable, $nivel_ejecucion, $distrito, $usuario);
    $id_ejecucion = (int)$ejecucion['id'];
    $estatus_ejecucion = $ejecucion['estatus'] ?? 'BORRADOR';
    $bloqueado = in_array($estatus_ejecucion, ['ENVIADO','CERRADO'], true) || $es_semana_pasada;
    $forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
    $compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
    $plan_row = cargar_plan($conexion, $id_ejecucion);
}

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
$subordinados = $nivel_subordinado ? cargar_subordinados_directos($conexion, $anio_hc, $semana_hc, $id_posicion, $nivel_subordinado) : [];
$metas_asignadas = cargar_metas_asignadas_por_superior($conexion, $anio_actual, $semana_actual, $id_posicion);

$meta_distribuida = 0;
foreach ($subordinados as $sub) {
    $sid = $sub['id_posicion'];
    $meta_distribuida += (int)($metas_asignadas[$sid]['meta_asignada'] ?? 0);
}
$meta_minima_requerida = (int)ceil($meta_responsable * 0.80);
$pendiente_minimo = max(0, $meta_minima_requerida - $meta_distribuida);
$pendiente_asignar = max(0, $meta_responsable - $meta_distribuida);
$disabled = $bloqueado ? 'disabled' : '';
$readonly_class = $bloqueado ? 'readonly' : '';

while (count($acciones_guardadas) < 5) $acciones_guardadas[] = [];

list($semana_nav_prev, $anio_nav_prev) = semana_anterior_calc($semana_actual, $anio_actual);
$semana_nav_next = $semana_actual + 1;
$anio_nav_next = $anio_actual;
if ($semana_nav_next > 53) {
    $semana_nav_next = 1;
    $anio_nav_next++;
}
$mostrar_next = !($anio_nav_next > $max_anio_nav || ($anio_nav_next === $max_anio_nav && $semana_nav_next > $max_semana_nav));
$modo_semana = $es_semana_pasada ? 'CONSULTA HISTÓRICA' : ($es_semana_futura ? 'PLAN FUTURO' : 'SEMANA ACTUAL');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ejecución Operativa - TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=170">
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
        .palanca-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.palanca-item{border:1px solid #e2e8f0;background:rgba(255,255,255,.68);border-radius:18px;padding:14px}.palanca-top{display:flex;align-items:center;gap:10px;margin-bottom:8px}.palanca-top input{width:auto}.palanca-name{font-weight:900}.actions-grid td input,.actions-grid td textarea,.actions-grid td select{font-size:.78rem;padding:9px;border-radius:12px}.actions-grid textarea{min-height:50px}
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
            <h1>🚀 Ejecución Operativa</h1>
            <p>Captura jerárquica de FCST, plan operativo y distribución de metas.</p>
            <div class="pill-row">
                <span class="pill active">CAPTURA</span>
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
            <div class="mode-note">Semanas pasadas: solo consulta · Semana actual/futura permitida: editable hasta enviar.</div>
        </div>
        <div class="status-card">
            <div class="status-label">Estado del plan</div>
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
                <div class="kpi-box"><div class="label">Meta distribuida</div><div class="value"><?= number_format($meta_distribuida) ?></div><div class="sub"><?= $pendiente_minimo > 0 ? 'Pendiente mínimo: '.number_format($pendiente_minimo) : 'Cumple mínimo 80%' ?></div></div>
            </div>
        </div>

        <div class="grid" style="margin-top:20px;">
            <div class="card full">
                <div class="card-title"><h2>1. Distribución de metas</h2><span><?= h(etiqueta_nivel($nivel_ejecucion)) ?> → <?= h(etiqueta_nivel($nivel_subordinado)) ?></span></div>
                <div class="helper" style="margin-bottom:12px;">Regla: para enviar el plan, la suma asignada debe cubrir al menos el 80% de tu meta semanal.</div>
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
                    <span class="pill">Mínimo 80%: <?= number_format($meta_minima_requerida) ?></span>
                    <span class="pill">Distribuida: <?= number_format($meta_distribuida) ?></span>
                    <span class="pill <?= $pendiente_minimo > 0 ? '' : 'active' ?>"><?= $pendiente_minimo > 0 ? 'Pendiente mínimo: '.number_format($pendiente_minimo) : 'Cumple mínimo' ?></span>
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
                <div class="card-title"><h2>3. Palancas de ejecución</h2><span>Selecciona prioridades</span></div>
                <?php if (empty($palancas)): ?>
                    <div class="alert error">No hay palancas activas cargadas en <strong>ejecucion_operativa_palancas</strong>.</div>
                <?php else: ?>
                    <div class="palanca-grid">
                        <?php foreach ($palancas as $p):
                            $pid = (int)$p['id'];
                            $sel = isset($palancas_seleccionadas[$pid]);
                            $prioridad = $palancas_seleccionadas[$pid]['prioridad'] ?? 'MEDIA';
                            $comentario = $palancas_seleccionadas[$pid]['comentario'] ?? '';
                        ?>
                            <div class="palanca-item">
                                <div class="palanca-top">
                                    <input type="checkbox" name="palanca[<?= $pid ?>]" value="1" <?= $sel ? 'checked' : '' ?> <?= $disabled ?>>
                                    <div>
                                        <div class="palanca-name"><?= h($p['nombre']) ?></div>
                                        <div class="mini"><?= h($p['descripcion'] ?? '') ?></div>
                                    </div>
                                </div>
                                <div class="grid" style="grid-template-columns:160px 1fr;gap:10px;">
                                    <div class="field" style="margin:0;"><label>Prioridad</label><select name="palanca_prioridad[<?= $pid ?>]" <?= $disabled ?>><option value="ALTA" <?= $prioridad==='ALTA'?'selected':'' ?>>Alta</option><option value="MEDIA" <?= $prioridad==='MEDIA'?'selected':'' ?>>Media</option><option value="BAJA" <?= $prioridad==='BAJA'?'selected':'' ?>>Baja</option></select></div>
                                    <div class="field" style="margin:0;"><label>Comentario</label><input type="text" name="palanca_comentario[<?= $pid ?>]" value="<?= h($comentario) ?>" <?= $disabled ?>></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card full">
                <div class="card-title"><h2>4. Acciones clave</h2><span>Compromisos de ejecución</span></div>
                <div class="table-wrap">
                    <table class="actions-grid">
                        <thead><tr><th>Acción</th><th>Descripción</th><th>Responsable</th><th>Fecha</th><th>Prioridad</th><th>Estatus</th><th>Comentario</th></tr></thead>
                        <tbody id="accionesBody">
                        <?php foreach ($acciones_guardadas as $i => $a): ?>
                            <tr>
                                <td><input type="text" name="accion_item[]" value="<?= h($a['accion'] ?? '') ?>" <?= $disabled ?>></td>
                                <td><textarea name="accion_desc[]" <?= $disabled ?>><?= h($a['descripcion'] ?? '') ?></textarea></td>
                                <td><input type="text" name="accion_resp[]" value="<?= h($a['responsable'] ?? '') ?>" <?= $disabled ?>></td>
                                <td><input type="date" name="accion_fecha[]" value="<?= h($a['fecha_compromiso'] ?? '') ?>" <?= $disabled ?>></td>
                                <td><select name="accion_prioridad[]" <?= $disabled ?>><?php $pr=$a['prioridad']??'MEDIA'; ?><option value="ALTA" <?= $pr==='ALTA'?'selected':'' ?>>Alta</option><option value="MEDIA" <?= $pr==='MEDIA'?'selected':'' ?>>Media</option><option value="BAJA" <?= $pr==='BAJA'?'selected':'' ?>>Baja</option></select></td>
                                <td><select name="accion_estatus[]" <?= $disabled ?>><?php $es=$a['estatus']??'PENDIENTE'; ?><option value="PENDIENTE" <?= $es==='PENDIENTE'?'selected':'' ?>>Pendiente</option><option value="EN_PROCESO" <?= $es==='EN_PROCESO'?'selected':'' ?>>En proceso</option><option value="COMPLETADA" <?= $es==='COMPLETADA'?'selected':'' ?>>Completada</option><option value="CANCELADA" <?= $es==='CANCELADA'?'selected':'' ?>>Cancelada</option></select></td>
                                <td><input type="text" name="accion_comentario[]" value="<?= h($a['comentario'] ?? '') ?>" <?= $disabled ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$bloqueado): ?>
                    <button type="button" class="btn btn-add" id="btnAgregarAccion">+ Agregar acción clave</button>
                <?php endif; ?>
            </div>

            <div class="card full">
                <div class="card-title"><h2>Control de plan</h2><span><?= $bloqueado ? 'Solo lectura' : 'Editable hasta enviar' ?></span></div>
                <?php if ($es_semana_pasada): ?>
                    <div class="helper">Semana histórica en modo consulta. No se permiten modificaciones.</div>
                <?php elseif ($bloqueado): ?>
                    <div class="helper">Este plan ya fue enviado. Para modificarlo se deberá reabrir desde administración en una fase posterior.</div>
                <?php else: ?>
                    <div class="helper">Puedes guardar como borrador. Al enviar, el plan queda bloqueado y validará la distribución de metas.</div>
                <?php endif; ?>
                <div class="actions">
                    <a href="../index.php" class="btn btn-secondary">Volver</a>
                    <div class="actions-right">
                        <button type="submit" name="accion_form" value="guardar" class="btn btn-primary" <?= $disabled ?>>Guardar borrador</button>
                        <button type="submit" name="accion_form" value="enviar" class="btn btn-danger" <?= $disabled ?> onclick="return confirm('¿Confirmas enviar el plan operativo? Una vez enviado ya no podrá modificarse.');">Enviar plan</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btnAgregarAccion');
    const tbody = document.getElementById('accionesBody');

    if (!btn || !tbody) return;

    btn.addEventListener('click', function () {
        const tr = document.createElement('tr');

        tr.innerHTML = `
            <td><input type="text" name="accion_item[]" value=""></td>
            <td><textarea name="accion_desc[]"></textarea></td>
            <td><input type="text" name="accion_resp[]" value=""></td>
            <td><input type="date" name="accion_fecha[]" value=""></td>
            <td>
                <select name="accion_prioridad[]">
                    <option value="ALTA">Alta</option>
                    <option value="MEDIA" selected>Media</option>
                    <option value="BAJA">Baja</option>
                </select>
            </td>
            <td>
                <select name="accion_estatus[]">
                    <option value="PENDIENTE" selected>Pendiente</option>
                    <option value="EN_PROCESO">En proceso</option>
                    <option value="COMPLETADA">Completada</option>
                    <option value="CANCELADA">Cancelada</option>
                </select>
            </td>
            <td><input type="text" name="accion_comentario[]" value=""></td>
        `;

        tbody.appendChild(tr);

        const firstInput = tr.querySelector('input, textarea, select');
        if (firstInput) firstInput.focus();
    });
});
</script>
</body>

</html>
