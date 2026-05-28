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

$mensaje = "";
$tipo_mensaje = "";

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

function normalizar_distrito_fcst($distrito) {
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

function nivel_forecast_desde_posicion($posicion) {
    $p = normalizar_texto($posicion);
    if ($p === 'DIRECTOR DISTRITAL') return 'DIRECTOR_DISTRITAL';
    if ($p === 'LIDER VENTAS' || $p === 'LIDER PROMOVENDEDOR/PROMOTOR') return 'LIDER_VENTAS';
    if ($p === 'COACH VENTAS' || $p === 'COACH DE VENTAS' || $p === 'COACH PROMOVENDEDOR PUNTO DE VENTA' || $p === 'COACH PROMOTOR PDV') return 'COACH_VENTAS';
    return 'OTRO';
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

function color_semaforo($pct) {
    if ($pct === null || $pct <= 0) return 'gris';
    if ($pct < 90) return 'rojo';
    if ($pct < 100) return 'amarillo';
    if ($pct < 120) return 'verde';
    return 'azul';
}

function etiqueta_pct($pct) {
    if ($pct === null || $pct <= 0) return '—';
    return round($pct) . '%';
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

function construir_semaforo_2026($conexion, $anio, $distrito, $semana_actual) {
    $datos = [];
    $meta_pct_series = [];
    $fcst_pct_series = [];

    $total_real = 0;
    $total_meta = 0;
    $total_fcst = 0;

    for ($sem = 1; $sem <= $semana_actual; $sem++) {
        $real = 0;
        $meta = 0;
        $fcst = 0;

        $dn_inst = distrito_norm_sql('distrito');
        $sql_real = "SELECT COUNT(cuenta) AS total
                     FROM instalaciones
                     WHERE YEAR(fecha) = ?
                       AND WEEK(fecha, 1) = ?
                       AND $dn_inst = ?
                       AND origen_prospecto <> '-'";
        $stmt = mysqli_prepare($conexion, $sql_real);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iis", $anio, $sem, $distrito);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) $real = (int)$row['total'];
            mysqli_stmt_close($stmt);
        }

        $dn_meta = distrito_norm_sql('distrito');
        $sql_meta = "SELECT SUM(meta) AS total FROM metas_instalacion_semanal WHERE anio = ? AND semana = ? AND $dn_meta = ?";
        $stmt = mysqli_prepare($conexion, $sql_meta);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iis", $anio, $sem, $distrito);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) $meta = (int)$row['total'];
            mysqli_stmt_close($stmt);
        }

        $dn_fcst = distrito_norm_sql('distrito');
        $sql_fcst = "SELECT SUM(forecast) AS total
                     FROM metas_forecast_semanal
                     WHERE anio = ? AND semana = ? AND $dn_fcst = ? AND nivel_forecast = 'DIRECTOR_DISTRITAL'";
        $stmt = mysqli_prepare($conexion, $sql_fcst);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iis", $anio, $sem, $distrito);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) $fcst = (int)$row['total'];
            mysqli_stmt_close($stmt);
        }

        $meta_pct = ($meta > 0) ? ($real / $meta) * 100 : null;
        $fcst_pct = ($fcst > 0) ? ($real / $fcst) * 100 : null;

        $datos[] = [
            'semana' => $sem,
            'real' => $real,
            'meta' => $meta,
            'fcst' => $fcst,
            'meta_pct' => $meta_pct,
            'fcst_pct' => $fcst_pct,
            'meta_color' => color_semaforo($meta_pct),
            'fcst_color' => color_semaforo($fcst_pct)
        ];

        $meta_pct_series[] = $meta_pct ? round($meta_pct, 1) : null;
        $fcst_pct_series[] = $fcst_pct ? round($fcst_pct, 1) : null;

        $total_real += $real;
        $total_meta += $meta;
        $total_fcst += $fcst;
    }

    $prom_meta_pct = ($total_meta > 0) ? ($total_real / $total_meta) * 100 : null;
    $prom_fcst_pct = ($total_fcst > 0) ? ($total_real / $total_fcst) * 100 : null;

    return [
        'datos' => $datos,
        'meta_pct_series' => $meta_pct_series,
        'fcst_pct_series' => $fcst_pct_series,
        'prom_meta_pct' => $prom_meta_pct,
        'prom_fcst_pct' => $prom_fcst_pct,
        'prom_meta_color' => color_semaforo($prom_meta_pct),
        'prom_fcst_color' => color_semaforo($prom_fcst_pct)
    ];
}

$anio_actual = (int)date('Y');
$semana_actual = (int)date('W');
list($semana_anterior, $anio_semana_anterior) = semana_anterior_calc($semana_actual, $anio_actual);

$responsable = buscar_responsable_sesion($conexion, $anio_actual, $semana_actual, $id_posicion_sesion, $numero_talento_sesion, $usuario, $rol);

$id_posicion = (string)$responsable['id_posicion'];
$posicion_lr = $responsable['posicion_lr'];
$numero_talento_gs = $responsable['numero_talento_gs'];
$nombre_responsable = $responsable['nombre_colaborador'];
$puesto_responsable = $responsable['puesto_responsable'];
$distrito = normalizar_distrito_fcst($responsable['distrito']);
$nivel_forecast = nivel_forecast_desde_posicion($puesto_responsable);

$forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
$esta_cerrado = ($compromiso_row && $compromiso_row['estatus'] === 'CERRADO');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar';

    $forecast = isset($_POST['forecast']) ? (int)$_POST['forecast'] : 0;
    $distrito = normalizar_distrito_fcst($distrito);
    $impulso_semana_anterior = trim($_POST['impulso_semana_anterior'] ?? '');
    $resto_semana_anterior = trim($_POST['resto_semana_anterior'] ?? '');
    $competencia = trim($_POST['competencia'] ?? '');
    $acciones_clave = trim($_POST['acciones_clave'] ?? '');
    $necesidades_apoyo = trim($_POST['necesidades_apoyo'] ?? '');

    $compromiso_existente = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);

    if ($compromiso_existente && $compromiso_existente['estatus'] === 'CERRADO') {
        $mensaje = "Este compromiso ya está CERRADO y no puede modificarse.";
        $tipo_mensaje = "error";
    } else {
        mysqli_begin_transaction($conexion);
        try {
            $sql_fcst = "INSERT INTO metas_forecast_semanal (
                            anio, semana, id_posicion, posicion_lr, numero_talento_gs,
                            nombre_responsable, puesto_responsable, distrito, nivel_forecast,
                            forecast, observacion, archivo_origen, usuario_carga
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            posicion_lr = VALUES(posicion_lr),
                            numero_talento_gs = VALUES(numero_talento_gs),
                            nombre_responsable = VALUES(nombre_responsable),
                            puesto_responsable = VALUES(puesto_responsable),
                            distrito = VALUES(distrito),
                            nivel_forecast = VALUES(nivel_forecast),
                            forecast = VALUES(forecast),
                            observacion = VALUES(observacion),
                            usuario_carga = VALUES(usuario_carga),
                            fecha_carga = CURRENT_TIMESTAMP";

            $observacion_fcst = "Captura METAS-FCST";
            $archivo_origen = "captura_web";
            $stmt = mysqli_prepare($conexion, $sql_fcst);
            if (!$stmt) throw new Exception(mysqli_error($conexion));

            mysqli_stmt_bind_param(
                $stmt,
                "iisssssssisss",
                $anio_actual, $semana_actual, $id_posicion, $posicion_lr, $numero_talento_gs,
                $nombre_responsable, $puesto_responsable, $distrito, $nivel_forecast,
                $forecast, $observacion_fcst, $archivo_origen, $usuario
            );

            if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
            mysqli_stmt_close($stmt);

            $forecast_row_nuevo = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
            $forecast_id = $forecast_row_nuevo ? (int)$forecast_row_nuevo['id'] : null;

            $estatus = ($accion === 'cerrar') ? 'CERRADO' : 'BORRADOR';
            $cerrado_en = ($accion === 'cerrar') ? date('Y-m-d H:i:s') : null;
            $cerrado_por = ($accion === 'cerrar') ? $usuario : null;

            $sql_comp = "INSERT INTO metas_fcst_compromiso_semanal (
                            forecast_id, anio, semana, id_posicion,
                            impulso_semana_anterior, resto_semana_anterior, competencia,
                            acciones_clave, necesidades_apoyo, estatus, cerrado_en,
                            cerrado_por, usuario_captura
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            forecast_id = VALUES(forecast_id),
                            impulso_semana_anterior = VALUES(impulso_semana_anterior),
                            resto_semana_anterior = VALUES(resto_semana_anterior),
                            competencia = VALUES(competencia),
                            acciones_clave = VALUES(acciones_clave),
                            necesidades_apoyo = VALUES(necesidades_apoyo),
                            estatus = VALUES(estatus),
                            cerrado_en = VALUES(cerrado_en),
                            cerrado_por = VALUES(cerrado_por),
                            usuario_captura = VALUES(usuario_captura)";

            $stmt = mysqli_prepare($conexion, $sql_comp);
            if (!$stmt) throw new Exception(mysqli_error($conexion));

            mysqli_stmt_bind_param(
                $stmt,
                "iiissssssssss",
                $forecast_id, $anio_actual, $semana_actual, $id_posicion,
                $impulso_semana_anterior, $resto_semana_anterior, $competencia,
                $acciones_clave, $necesidades_apoyo, $estatus, $cerrado_en,
                $cerrado_por, $usuario
            );

            if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_error($conexion));
            mysqli_stmt_close($stmt);

            mysqli_commit($conexion);
            $mensaje = ($accion === 'cerrar') ? "✅ Compromiso cerrado correctamente. Ya no podrá modificarse." : "✅ Borrador guardado correctamente.";
            $tipo_mensaje = "exito";
        } catch (Exception $e) {
            mysqli_rollback($conexion);
            $mensaje = "Error al guardar: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }

    $forecast_row = cargar_forecast_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
    $compromiso_row = cargar_compromiso_actual($conexion, $anio_actual, $semana_actual, $id_posicion);
    $esta_cerrado = ($compromiso_row && $compromiso_row['estatus'] === 'CERRADO');
}

$forecast_valor = $forecast_row ? (int)$forecast_row['forecast'] : 0;

$impulso = $compromiso_row['impulso_semana_anterior'] ?? '';
$resto = $compromiso_row['resto_semana_anterior'] ?? '';
$competencia_txt = $compromiso_row['competencia'] ?? '';
$acciones = $compromiso_row['acciones_clave'] ?? '';
$necesidades = $compromiso_row['necesidades_apoyo'] ?? '';
$estatus = $compromiso_row['estatus'] ?? 'BORRADOR';

$disabled = $esta_cerrado ? 'disabled' : '';
$readonly_class = $esta_cerrado ? 'readonly' : '';

$semaforo = construir_semaforo_2026($conexion, $anio_actual, $distrito, $semana_actual);
$datos_semaforo = $semaforo['datos'];
$meta_abs_series = array_map(function($d){ return (int)($d['meta'] ?? 0); }, $datos_semaforo);
$fcst_abs_series = array_map(function($d){ return (int)($d['fcst'] ?? 0); }, $datos_semaforo);
$real_series = array_map(function($d){ return (int)($d['real'] ?? 0); }, $datos_semaforo);$real_series = array_map(function($d){ return (int)($d['real'] ?? 0); }, $datos_semaforo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>METAS-FCST - TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=163">
    <style>
        :root {
            --tx-purple:#7A2BFF; --tx-pink:#FF00B8; --tx-blue:#2563eb;
            --tx-card:rgba(255,255,255,.90); --tx-border:#e2e8f0;
            --tx-text:#1a2540; --tx-muted:#6b7a99; --sidebar:200px;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:'Poppins','Segoe UI',sans-serif;background:radial-gradient(circle at 8% 8%,rgba(255,10,200,.10),transparent 28%),radial-gradient(circle at 92% 14%,rgba(0,216,255,.09),transparent 30%),linear-gradient(180deg,#f7f8ff 0%,#eef5ff 100%);color:var(--tx-text);min-height:100vh;display:flex}
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}
        .page-title h1{margin:0;font-size:1.65rem;letter-spacing:-.4px}
        .page-title p{margin:6px 0 0;color:var(--tx-muted);font-size:.9rem}
        .pill-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
        .pill{border:1px solid var(--tx-border);background:var(--tx-card);padding:8px 12px;border-radius:999px;font-size:.78rem;font-weight:800;color:var(--tx-muted)}
        .pill.active{color:white;background:linear-gradient(135deg,var(--tx-purple) 0%,var(--tx-pink) 100%);border:none}
        .pill.disabled{opacity:.45;cursor:not-allowed}
        .status-card{background:var(--tx-card);border:1px solid var(--tx-border);border-radius:22px;padding:18px 20px;min-width:280px;box-shadow:0 14px 32px rgba(22,28,60,.08)}
        .status-label{font-size:.72rem;text-transform:uppercase;color:var(--tx-muted);font-weight:900;letter-spacing:.7px}
        .status-main{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:8px}
        .badge{display:inline-flex;border-radius:999px;padding:7px 12px;font-size:.78rem;font-weight:900}
        .badge.borrador{color:#92400e;background:#fef3c7}.badge.cerrado{color:#166534;background:#dcfce7}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
        .card{background:var(--tx-card);border:1px solid var(--tx-border);border-radius:24px;padding:22px;box-shadow:0 14px 32px rgba(22,28,60,.08)}
        .card.full{grid-column:1/-1}
        .card-title{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}
        .card-title h2{margin:0;font-size:1.05rem}.card-title span{color:var(--tx-muted);font-size:.78rem;font-weight:800}
        .field{margin-bottom:16px}.field label{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.5px;font-weight:900;color:var(--tx-muted);margin-bottom:8px}
        .field input,.field textarea{width:100%;border:1.5px solid #dbe4f0;border-radius:16px;padding:13px 14px;font-family:inherit;font-size:.92rem;outline:none;background:rgba(255,255,255,.82);color:var(--tx-text)}
        .field textarea{min-height:118px;resize:vertical;line-height:1.45}
        .field input:focus,.field textarea:focus{border-color:var(--tx-purple);box-shadow:0 0 0 4px rgba(122,43,255,.10);background:white}
        .field input:disabled,.field textarea:disabled,.readonly input,.readonly textarea{background:#f8fafc;color:#64748b;cursor:not-allowed}
        .kpi{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px}
        .kpi-box{border-radius:18px;padding:16px;background:rgba(255,255,255,.70);border:1px solid #e2e8f0}
        .kpi-box .label{color:var(--tx-muted);font-weight:900;font-size:.72rem;text-transform:uppercase;letter-spacing:.6px}
        .kpi-box .value{margin-top:8px;font-size:1.55rem;font-weight:900;color:var(--tx-purple)}
        .semaforo-wrap{overflow-x:scroll;border:1px solid #e2e8f0;border-radius:16px;background:rgba(255,255,255,.72)}.semaforo-wrap::-webkit-scrollbar{height:12px}.semaforo-wrap::-webkit-scrollbar-track{background:#f1f5f9;border-radius:999px}.semaforo-wrap::-webkit-scrollbar-thumb{background:linear-gradient(135deg,var(--tx-purple),var(--tx-blue));border-radius:999px}
        .semaforo-table{border-collapse:collapse;width:100%;min-width:1450px;font-size:.72rem}
        .semaforo-table th,.semaforo-table td{border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;padding:8px 7px;text-align:center;white-space:nowrap}
        .semaforo-table th{background:#f8fafc;font-weight:900;color:#475569}
        .semaforo-table td:first-child,.semaforo-table th:first-child{position:sticky;left:0;background:#f8fafc;font-weight:900;z-index:1}
        .dot{width:15px;height:15px;display:inline-block;border-radius:999px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.13)}
        .dot.rojo{background:#ef4444}.dot.amarillo{background:#fbbf24}.dot.verde{background:#65a30d}.dot.azul{background:#2563eb}.dot.gris{background:#cbd5e1}
        .legend{display:flex;flex-wrap:wrap;gap:16px;margin:14px 0 12px;font-size:.76rem;color:#475569;font-weight:800}
        .legend-item{display:flex;align-items:center;gap:7px}
        .mini-chart{width:100%;height:270px;margin-top:8px;background:rgba(255,255,255,.65);border:1px solid #e2e8f0;border-radius:16px;padding:10px}
        .chart-title{margin-top:16px;margin-bottom:6px;font-size:.78rem;text-transform:uppercase;font-weight:900;letter-spacing:.5px;color:var(--tx-muted)}
        .actions{display:flex;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap}.actions-right{display:flex;gap:12px;flex-wrap:wrap}
        .btn{border:none;border-radius:14px;padding:12px 18px;font-weight:900;cursor:pointer;font-size:.9rem;font-family:inherit;text-decoration:none}
        .btn-secondary{background:#e8eef7;color:#1a2540}.btn-primary{color:white;background:linear-gradient(135deg,var(--tx-purple) 0%,var(--tx-pink) 100%);box-shadow:0 12px 28px rgba(122,43,255,.20)}
        .btn-danger{color:white;background:linear-gradient(135deg,#16a34a 0%,#059669 100%);box-shadow:0 12px 28px rgba(22,163,74,.18)}
        .btn:disabled{opacity:.45;cursor:not-allowed}
        .alert{border-radius:16px;padding:14px 16px;margin-bottom:18px;line-height:1.45;font-weight:700}.alert.exito{background:#dcfce7;color:#166534}.alert.error{background:#fee2e2;color:#991b1b}
        .helper{font-size:.78rem;color:var(--tx-muted);line-height:1.45;margin-top:8px}
        @media(max-width:1100px){.grid{grid-template-columns:1fr}.page-header{flex-direction:column}.status-card{width:100%}}
    </style>
</head>
<body>
<?php
$current_page = 'fcst_captura';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <div class="page-title">
            <h1>🎯 METAS-FCST</h1>
            <p>Captura semanal de forecast, hallazgos y compromisos de ejecución.</p>
            <div class="pill-row">
                <span class="pill active">SEMANAL</span>
                <span class="pill disabled">MENSUAL · Próximamente</span>
            </div>
        </div>
        <div class="status-card">
            <div class="status-label">Semana actual</div>
            <div class="status-main">
                <div>
                    <strong>SEM <?= h($semana_actual) ?> · <?= h($anio_actual) ?></strong><br>
                    <span style="font-size:.78rem;color:var(--tx-muted);font-weight:800;">Semana anterior: SEM <?= h($semana_anterior) ?></span>
                </div>
                <span class="badge <?= strtolower($estatus) ?>"><?= $estatus === 'CERRADO' ? '🔒 CERRADO' : '✏️ BORRADOR' ?></span>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert <?= h($tipo_mensaje) ?>"><?= h($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" class="<?= h($readonly_class) ?>">
        <div class="grid">
            <div class="card">
                <div class="card-title"><h2>Responsable</h2><span>Línea de reporte</span></div>
                <div class="kpi">
                    <div class="kpi-box"><div class="label">Responsable</div><div class="value" style="font-size:1rem;line-height:1.25;"><?= h($nombre_responsable) ?></div></div>
                    <div class="kpi-box"><div class="label">Distrito</div><div class="value"><?= h($distrito ?: 'N/D') ?></div></div>
                </div>
                <div class="kpi">
                    <div class="kpi-box"><div class="label">Puesto</div><div class="value" style="font-size:1rem;line-height:1.25;"><?= h($puesto_responsable) ?></div></div>
                    <div class="kpi-box"><div class="label">Nivel Forecast</div><div class="value" style="font-size:1rem;line-height:1.25;"><?= h($nivel_forecast) ?></div></div>
                </div>
                <div class="field">
                    <label>Forecast compromiso de la semana actual</label>
                    <input type="number" name="forecast" min="0" step="1" value="<?= h($forecast_valor) ?>" <?= $disabled ?>>
                    <div class="helper">Este número se guarda en <strong>metas_forecast_semanal</strong>.</div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><h2>Semana anterior</h2><span>Hallazgos</span></div>
                <div class="field">
                    <label>Lo que me impulsó en la semana anterior</label>
                    <textarea name="impulso_semana_anterior" <?= $disabled ?>><?= h($impulso) ?></textarea>
                </div>
                <div class="field">
                    <label>Lo que me restó en la semana anterior</label>
                    <textarea name="resto_semana_anterior" <?= $disabled ?>><?= h($resto) ?></textarea>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><h2>Competencia</h2><span>Información clave</span></div>
                <div class="field">
                    <label>Información clave de la competencia</label>
                    <textarea name="competencia" <?= $disabled ?>><?= h($competencia_txt) ?></textarea>
                </div>
            </div>

            
<div class="card">
                <div class="card-title"><h2>Semana actual</h2><span>Ejecución</span></div>
                <div class="field">
                    <label>Acciones clave a ejecutar</label>
                    <textarea name="acciones_clave" <?= $disabled ?>><?= h($acciones) ?></textarea>
                </div>
                <div class="field">
                    <label>Necesidades y apoyos requeridos</label>
                    <textarea name="necesidades_apoyo" <?= $disabled ?>><?= h($necesidades) ?></textarea>
                </div>
            </div>

            
<div class="card full">
                <div class="card-title"><h2>Resultados 2026</h2><span>Semaforización</span></div>
                <div class="semaforo-wrap">
                    <table class="semaforo-table">
                        <thead>
                            <tr>
                                <th>Indicador</th>
                                <?php foreach ($datos_semaforo as $d): ?><th>SEM <?= h($d['semana']) ?></th><?php endforeach; ?>
                                <th>PROMEDIO TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>META</td>
                                <?php foreach ($datos_semaforo as $d): ?><td><span class="dot <?= h($d['meta_color']) ?>"></span></td><?php endforeach; ?>
                                <td><span class="dot <?= h($semaforo['prom_meta_color']) ?>"></span></td>
                            </tr>
                            <tr>
                                <td>FCST</td>
                                <?php foreach ($datos_semaforo as $d): ?><td><span class="dot <?= h($d['fcst_color']) ?>"></span></td><?php endforeach; ?>
                                <td><span class="dot <?= h($semaforo['prom_fcst_color']) ?>"></span></td>
                            </tr>
                            <tr>
                                <td>META %</td>
                                <?php foreach ($datos_semaforo as $d): ?><td><?= h(etiqueta_pct($d['meta_pct'])) ?></td><?php endforeach; ?>
                                <td><strong><?= h(etiqueta_pct($semaforo['prom_meta_pct'])) ?></strong></td>
                            </tr>
                            <tr>
                                <td>FCST %</td>
                                <?php foreach ($datos_semaforo as $d): ?><td><?= h(etiqueta_pct($d['fcst_pct'])) ?></td><?php endforeach; ?>
                                <td><strong><?= h(etiqueta_pct($semaforo['prom_fcst_pct'])) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="legend">
                    <span class="legend-item"><span class="dot rojo"></span> ROJO &lt; 90%</span>
                    <span class="legend-item"><span class="dot amarillo"></span> AMARILLO ≥ 90% y &lt; 100%</span>
                    <span class="legend-item"><span class="dot verde"></span> VERDE ≥ 100% y &lt; 120%</span>
                    <span class="legend-item"><span class="dot azul"></span> AZUL ≥ 120%</span>
                </div>
                <div class="chart-title">Evolución 2026 · Barras: instalaciones reales · Líneas: META y FCST</div>
                <canvas id="miniGrafico" class="mini-chart"></canvas>
            </div>

            <div class="card full">
                <div class="card-title"><h2>Control de compromiso</h2><span><?= $esta_cerrado ? 'Solo lectura' : 'Editable hasta cerrar' ?></span></div>
                <?php if ($esta_cerrado): ?>
                    <div class="helper">Este compromiso fue cerrado por <strong><?= h($compromiso_row['cerrado_por'] ?? '') ?></strong> el <strong><?= h($compromiso_row['cerrado_en'] ?? '') ?></strong>. Ya no puede modificarse.</div>
                <?php else: ?>
                    <div class="helper">Puedes guardar como borrador y regresar a editar. Al seleccionar <strong>Cerrar compromiso</strong>, la información quedará bloqueada.</div>
                <?php endif; ?>
                <div class="actions">
                    <a href="../index.php" class="btn btn-secondary">Volver</a>
                    <div class="actions-right">
                        <button type="submit" name="accion" value="guardar" class="btn btn-primary" <?= $disabled ?>>Guardar borrador</button>
                        <button type="submit" name="accion" value="cerrar" class="btn btn-danger" <?= $disabled ?> onclick="return confirm('¿Confirmas cerrar el compromiso? Una vez cerrado ya no podrá modificarse.');">Cerrar compromiso</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
const meta_abs_series = <?= json_encode($meta_abs_series, JSON_NUMERIC_CHECK) ?>;
const fcst_abs_series = <?= json_encode($fcst_abs_series, JSON_NUMERIC_CHECK) ?>;
const real_series = <?= json_encode($real_series, JSON_NUMERIC_CHECK) ?>;

(function dibujarMiniGrafico(){
    const canvas = document.getElementById('miniGrafico');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();

    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    const w = rect.width;
    const h = rect.height;
    const padL = 56;
    const padR = 28;
    const padT = 30;
    const padB = 38;
    const plotW = w - padL - padR;
    const plotH = h - padT - padB;

    ctx.clearRect(0, 0, w, h);

    const valores = real_series.concat(meta_abs_series, fcst_abs_series).filter(v => v !== null && !isNaN(v));
    const maxVal = Math.max(10, ...valores);
    const escalaMax = Math.ceil(maxVal * 1.15 / 10) * 10;
    const total = Math.max(real_series.length, meta_abs_series.length, fcst_abs_series.length);

    function x(i) {
        if (total <= 1) return padL;
        return padL + (i * plotW / (total - 1));
    }

    function y(v) {
        if (v === null || isNaN(v)) return null;
        return padT + plotH - (v / escalaMax) * plotH;
    }

    ctx.font = '11px Segoe UI';
    ctx.lineWidth = 1;

    // Grid absoluto
    const ticks = [0, Math.round(escalaMax * .25), Math.round(escalaMax * .5), Math.round(escalaMax * .75), escalaMax];
    ticks.forEach(val => {
        const yy = y(val);
        if (yy === null) return;
        ctx.beginPath();
        ctx.strokeStyle = '#e2e8f0';
        ctx.moveTo(padL, yy);
        ctx.lineTo(w - padR, yy);
        ctx.stroke();

        ctx.fillStyle = '#64748b';
        ctx.textAlign = 'right';
        ctx.fillText(String(val), padL - 10, yy + 4);
    });

    ctx.fillStyle = '#64748b';
    ctx.textAlign = 'left';
    ctx.fillText('Inst.', padL, padT - 12);

    // Barras de instalaciones reales
    const barW = Math.max(8, Math.min(28, plotW / Math.max(total, 1) * 0.42));
    real_series.forEach((v, i) => {
        const yy = y(v);
        if (yy === null) return;
        const xx = x(i) - barW / 2;
        const bh = padT + plotH - yy;

        ctx.fillStyle = '#FF00B8';
        ctx.fillRect(xx, yy, barW, bh);

        ctx.fillStyle = '#64748b';
        ctx.font = '10px Segoe UI';
        ctx.textAlign = 'center';
        ctx.fillText(String(v), x(i), Math.max(padT + 10, yy - 6));
    });

    function drawLine(series, color, offsetX) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 3;
        ctx.beginPath();

        let started = false;
        series.forEach((v, i) => {
            const yy = y(v);
            if (yy === null) return;
            const xx = x(i);

            if (!started) {
                ctx.moveTo(xx, yy);
                started = true;
            } else {
                ctx.lineTo(xx, yy);
            }
        });
        ctx.stroke();

        series.forEach((v, i) => {
            const yy = y(v);
            if (yy === null) return;
            const xx = x(i);
            ctx.beginPath();
            ctx.arc(xx, yy, 4, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.lineWidth = 3;
            ctx.strokeStyle = color;
            ctx.stroke();

            ctx.fillStyle = color;
            ctx.font = '10px Segoe UI';
            ctx.textAlign = 'center';
            ctx.textAlign = offsetX >= 0 ? 'left' : 'right';
            ctx.fillText(String(v), xx + offsetX, Math.max(padT + 10, yy - 10));
        });
    }

    drawLine(meta_abs_series, '#7A2BFF', 16);
    drawLine(fcst_abs_series, '#2563eb', -16);

    // Etiquetas de semanas
    ctx.fillStyle = '#64748b';
    ctx.font = '11px Segoe UI';
    ctx.textAlign = 'center';
    for (let i = 0; i < total; i++) {
        if (i === 0 || i === total - 1 || i % 2 === 0) {
            ctx.fillText('S' + (i + 1), x(i), h - 12);
        }
    }

    // Leyenda interna
    const legendY = 14;
    ctx.textAlign = 'left';
    ctx.fillStyle = '#FF00B8';
    ctx.beginPath(); ctx.arc(w - 300, legendY, 5, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#64748b'; ctx.fillText('Instalaciones reales', w - 288, legendY + 4);

    ctx.fillStyle = '#7A2BFF';
    ctx.beginPath(); ctx.arc(w - 162, legendY, 5, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#64748b'; ctx.fillText('META', w - 150, legendY + 4);

    ctx.fillStyle = '#2563eb';
    ctx.beginPath(); ctx.arc(w - 90, legendY, 5, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#64748b'; ctx.fillText('FCST', w - 78, legendY + 4);
})();
</script>
</body>
</html>
