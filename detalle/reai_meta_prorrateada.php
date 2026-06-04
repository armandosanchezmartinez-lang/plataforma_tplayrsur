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

$rol              = $_SESSION['rol'] ?? 'vendedor';
$id_posicion      = $_SESSION['id_posicion'] ?? '';
$talento_gs_coach = $_SESSION['numero_talento_gs'] ?? '';
$puestos_comerciales = ['PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'];
$puestos_in = "'" . implode("','", $puestos_comerciales) . "'";
$puede_capturar = ($rol === 'coach');

// Meta base por productividad diaria.
// Criterio REAI: 0.70 instalaciones por día hábil transcurrido.
$meta_productividad_diaria = 0.70;

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_num($v, $d = 0) { return number_format((float)($v ?? 0), $d); }
function fmt_prod($v) { return number_format((float)($v ?? 0), 2); }
function fmt_meta($v) { return number_format((float)($v ?? 0), 1); }
function table_exists($conexion, $table) {
    $table = mysqli_real_escape_string($conexion, $table);
    $res = mysqli_query($conexion, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}
function contar_dias_habiles($conexion, $fecha_inicio, $fecha_fin) {
    if (!$fecha_inicio || !$fecha_fin) return 1;
    $inicio = new DateTime($fecha_inicio);
    $fin    = new DateTime($fecha_fin);
    if ($inicio > $fin) { $tmp = $inicio; $inicio = $fin; $fin = $tmp; }

    $festivos = [];
    if (table_exists($conexion, 'dias_inhabiles')) {
        $fi = mysqli_real_escape_string($conexion, $inicio->format('Y-m-d'));
        $ff = mysqli_real_escape_string($conexion, $fin->format('Y-m-d'));
        $res = mysqli_query($conexion, "SELECT fecha FROM dias_inhabiles WHERE activo = 1 AND fecha BETWEEN '$fi' AND '$ff'");
        while ($res && $row = mysqli_fetch_assoc($res)) $festivos[$row['fecha']] = true;
    }

    $habiles = 0;
    for ($d = clone $inicio; $d <= $fin; $d->modify('+1 day')) {
        $fecha = $d->format('Y-m-d');
        if ((int)$d->format('N') === 7) continue; // domingo
        if (isset($festivos[$fecha])) continue;
        $habiles++;
    }
    return max(1, $habiles);
}
function fecha_iso_inicio($anio, $semana) {
    $d = new DateTime();
    $d->setISODate((int)$anio, (int)$semana, 1);
    return $d;
}
function periodo_label($periodo, $base, $actual) {
    if ($periodo === 'mensual') {
        $meses = [1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];
        return [$meses[(int)date('n', strtotime($base['inicio']))], $meses[(int)date('n', strtotime($actual['inicio']))]];
    }
    return ['SEM'.$base['semana'], 'SEM'.$actual['semana']];
}

// ── HISTORIAL AJAX ────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'historial') {
    $talento = mysqli_real_escape_string($conexion, $_GET['talento_gs'] ?? '');
    $res = mysqli_query($conexion, "SELECT * FROM reai WHERE numero_talento_gs = '$talento' ORDER BY fecha DESC, created_at DESC");
    $registros = [];
    while ($res && $row = mysqli_fetch_assoc($res)) $registros[] = $row;
    header('Content-Type: application/json');
    echo json_encode($registros);
    exit();
}

// ── GUARDAR REAI ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puede_capturar && isset($_POST['action']) && $_POST['action'] === 'guardar') {
    $talento_vendedor = mysqli_real_escape_string($conexion, $_POST['numero_talento_gs'] ?? '');
    $nombre_vendedor  = mysqli_real_escape_string($conexion, $_POST['nombre_colaborador'] ?? '');
    $asunto           = $_POST['asunto'] ?? '';
    $fecha            = $_POST['fecha'] ?? '';
    $descripcion      = mysqli_real_escape_string($conexion, $_POST['descripcion'] ?? '');
    $evidencia_nombre = '';
    $asuntos_validos  = ['Retroalimentación','ECNUs','Acta Administrativa','Incidencia'];

    if (!in_array($asunto, $asuntos_validos, true)) {
        echo json_encode(['status'=>'error','msg'=>'Asunto no válido']); exit();
    }
    if (!empty($_FILES['evidencia']['name'])) {
        $ext     = pathinfo($_FILES['evidencia']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg','jpeg','png','pdf','doc','docx'];
        if (in_array(strtolower($ext), $allowed, true)) {
            $upload_dir = '../uploads/reai/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $nombre_archivo = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['evidencia']['name']);
            if (move_uploaded_file($_FILES['evidencia']['tmp_name'], $upload_dir . $nombre_archivo)) {
                $evidencia_nombre = $nombre_archivo;
            }
        } else {
            echo json_encode(['status'=>'error','msg'=>'Formato no permitido']); exit();
        }
    }
    $asunto_esc = mysqli_real_escape_string($conexion, $asunto);
    $sql = "INSERT INTO reai (numero_talento_gs, nombre_colaborador, asunto, fecha, descripcion, evidencia, capturado_por, talento_gs_coach, id_posicion_coach)
            VALUES ('$talento_vendedor','$nombre_vendedor','$asunto_esc','$fecha','$descripcion','$evidencia_nombre','$talento_gs_coach','$talento_gs_coach','$id_posicion')";
    echo mysqli_query($conexion, $sql)
        ? json_encode(['status'=>'ok','msg'=>'Registro guardado correctamente'])
        : json_encode(['status'=>'error','msg'=>'Error: '.mysqli_error($conexion)]);
    exit();
}

// ── PERÍODO SEMANAL / MENSUAL ────────────────────────────────────────────────
$periodo = $_GET['periodo'] ?? 'semanal';
if (!in_array($periodo, ['semanal','mensual'], true)) $periodo = 'semanal';

$ultima_fecha = date('Y-m-d', strtotime('-1 day'));
$res_uf = mysqli_query($conexion, "SELECT MAX(fecha) AS ultima_fecha FROM instalaciones WHERE fecha IS NOT NULL AND fecha <= CURDATE()");
if ($res_uf && $row_uf = mysqli_fetch_assoc($res_uf)) {
    if (!empty($row_uf['ultima_fecha'])) $ultima_fecha = $row_uf['ultima_fecha'];
}

$periodo_base = [];
$periodo_actual = [];
if ($periodo === 'mensual') {
    $anio_act = (int)date('Y', strtotime($ultima_fecha));
    $mes_act  = (int)date('n', strtotime($ultima_fecha));
    $dia_fin  = (int)date('j', strtotime($ultima_fecha));

    $inicio_actual = sprintf('%04d-%02d-01', $anio_act, $mes_act);
    $fin_actual    = sprintf('%04d-%02d-%02d', $anio_act, $mes_act, $dia_fin);

    $prev = strtotime($inicio_actual . ' -1 month');
    $anio_base = (int)date('Y', $prev);
    $mes_base  = (int)date('n', $prev);
    $ultimo_dia_base = (int)date('t', $prev);
    $dia_fin_base = min($dia_fin, $ultimo_dia_base);
    $inicio_base = sprintf('%04d-%02d-01', $anio_base, $mes_base);
    $fin_base    = sprintf('%04d-%02d-%02d', $anio_base, $mes_base, $dia_fin_base);

    $periodo_base   = ['inicio'=>$inicio_base, 'fin'=>$fin_base];
    $periodo_actual = ['inicio'=>$inicio_actual, 'fin'=>$fin_actual];
} else {
    $anio_act = (int)date('o', strtotime($ultima_fecha));
    $sem_act  = (int)date('W', strtotime($ultima_fecha));
    $sem_inicio = fecha_iso_inicio($anio_act, $sem_act);
    $dow_ultima = (int)date('N', strtotime($ultima_fecha));
    $fin_actual_dt = clone $sem_inicio;
    $fin_actual_dt->modify('+'.($dow_ultima - 1).' days');

    $base_inicio = clone $sem_inicio;
    $base_inicio->modify('-1 week');
    $base_fin = clone $base_inicio;
    $base_fin->modify('+'.($dow_ultima - 1).' days');

    $periodo_actual = ['inicio'=>$sem_inicio->format('Y-m-d'), 'fin'=>$fin_actual_dt->format('Y-m-d'), 'semana'=>$sem_act, 'anio'=>$anio_act];
    $periodo_base   = ['inicio'=>$base_inicio->format('Y-m-d'), 'fin'=>$base_fin->format('Y-m-d'), 'semana'=>(int)$base_inicio->format('W'), 'anio'=>(int)$base_inicio->format('o')];
}

$dias_habiles_base   = contar_dias_habiles($conexion, $periodo_base['inicio'], $periodo_base['fin']);
$dias_habiles_actual = contar_dias_habiles($conexion, $periodo_actual['inicio'], $periodo_actual['fin']);
[$label_base, $label_actual] = periodo_label($periodo, $periodo_base, $periodo_actual);

// Última semana HC para plantilla / jerarquía
$semana_hc = null; $anio_hc = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_hc = (int)$row_sem['semana'];
    $anio_hc   = (int)$row_sem['anio'];
}

// ── OBTENER VENDEDORES SEGÚN JERARQUÍA ───────────────────────────────────────
$vendedores = [];
if ($semana_hc && $anio_hc) {
    if ($rol === 'coach') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) AS antiguedad,
                     c.nombre_colaborador AS nombre_coach, c.numero_talento_gs AS talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE v.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_hc, $anio_hc);
    } elseif ($rol === 'lider') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) AS antiguedad,
                     c.nombre_colaborador AS nombre_coach, c.numero_talento_gs AS talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE c.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_hc, $anio_hc);
    } elseif ($rol === 'director_distrital') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) AS antiguedad,
                     c.nombre_colaborador AS nombre_coach, c.numero_talento_gs AS talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     INNER JOIN hc l ON c.posicion_lr = l.id_posicion AND l.semana = v.semana AND l.anio = v.anio
                     WHERE l.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY l.nombre_colaborador, c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_hc, $anio_hc);
    } else {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) AS antiguedad,
                     c.nombre_colaborador AS nombre_coach, c.numero_talento_gs AS talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "ii", $semana_hc, $anio_hc);
    }
    mysqli_stmt_execute($stmt);
    $res_vend = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res_vend)) $vendedores[] = $row;
    mysqli_stmt_close($stmt);
}

// ── MÉTRICAS POR VENDEDOR ────────────────────────────────────────────────────
$stats = [];
if (!empty($vendedores)) {
    $talentos = array_column($vendedores, 'numero_talento_gs');
    $ph = implode(',', array_fill(0, count($talentos), '?'));
    $tipos = str_repeat('s', count($talentos));

    $fi_base = $periodo_base['inicio'];
    $ff_base = $periodo_base['fin'];
    $fi_act  = $periodo_actual['inicio'];
    $ff_act  = $periodo_actual['fin'];

    $stmt_ib = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) AS total FROM instalaciones WHERE fecha BETWEEN ? AND ? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_ib, 'ss'.$tipos, $fi_base, $ff_base, ...array_values($talentos));
    mysqli_stmt_execute($stmt_ib);
    $res_ib = mysqli_stmt_get_result($stmt_ib);
    while ($r = mysqli_fetch_assoc($res_ib)) $stats[$r['folio_empleado']]['inst_base'] = (int)$r['total'];
    mysqli_stmt_close($stmt_ib);

    $stmt_ia = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) AS total FROM instalaciones WHERE fecha BETWEEN ? AND ? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_ia, 'ss'.$tipos, $fi_act, $ff_act, ...array_values($talentos));
    mysqli_stmt_execute($stmt_ia);
    $res_ia = mysqli_stmt_get_result($stmt_ia);
    while ($r = mysqli_fetch_assoc($res_ia)) $stats[$r['folio_empleado']]['inst_actual'] = (int)$r['total'];
    mysqli_stmt_close($stmt_ia);

    $stmt_rc = mysqli_prepare($conexion, "SELECT numero_talento_gs, asunto, COUNT(*) AS total, MAX(fecha) AS ultima_fecha FROM reai WHERE numero_talento_gs IN ($ph) GROUP BY numero_talento_gs, asunto");
    mysqli_stmt_bind_param($stmt_rc, $tipos, ...array_values($talentos));
    mysqli_stmt_execute($stmt_rc);
    $res_rc = mysqli_stmt_get_result($stmt_rc);
    while ($r = mysqli_fetch_assoc($res_rc)) {
        $t = $r['numero_talento_gs'];
        $stats[$t]['reai'][$r['asunto']] = (int)$r['total'];
        $stats[$t]['reai_total'] = ($stats[$t]['reai_total'] ?? 0) + (int)$r['total'];
        if (empty($stats[$t]['ultima_reai']) || $r['ultima_fecha'] > $stats[$t]['ultima_reai']) $stats[$t]['ultima_reai'] = $r['ultima_fecha'];
    }
    mysqli_stmt_close($stmt_rc);
}

$total_hc = count($vendedores);
$total_con_reai = 0;
$total_sin_reai = 0;
$total_riesgo = 0;
foreach ($vendedores as $vend) {
    $tgs = $vend['numero_talento_gs'];
    $st = $stats[$tgs] ?? [];
    $inst_base = $st['inst_base'] ?? 0;
    $inst_actual = $st['inst_actual'] ?? 0;
    $reai_total = $st['reai_total'] ?? 0;
    if ($reai_total > 0) $total_con_reai++; else $total_sin_reai++;
    $prod_actual_tmp = $dias_habiles_actual > 0 ? round($inst_actual / $dias_habiles_actual, 2) : 0;
    $dif_tmp = $inst_actual - $inst_base;
    $pct_tmp = $inst_base > 0 ? round(($dif_tmp / $inst_base) * 100, 0) : ($inst_actual > 0 ? 100 : 0);
    $caida_pronunciada_tmp = ($pct_tmp <= -50 || $dif_tmp <= -2);
    $caida_leve_tmp = ($dif_tmp < 0 && !$caida_pronunciada_tmp);
    // Sólo contar riesgo cuando existe deterioro real vs período anterior.
    // Si va igual o mejorando, no se genera acción aunque la productividad aún sea amarilla/roja.
    if ($reai_total == 0 && $dif_tmp < 0) {
        if ($caida_pronunciada_tmp || $prod_actual_tmp < .70) $total_riesgo++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REAI v6 — TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=161">
    <style>
        body.page-reai .modal-overlay.active{display:flex !important;}
        body.page-reai .modal-box{max-width:560px;max-height:90vh;overflow-y:auto;}
        body.page-reai .modal-header{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:20px;}
        body.page-reai .modal-close{background:none;border:none;font-size:1.4rem;color:var(--text2);cursor:pointer;line-height:1;}
        body.page-reai .form-group{margin-bottom:16px;text-align:left;}
        body.page-reai .form-group label{display:block;font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
        body.page-reai .form-group select,
        body.page-reai .form-group input,
        body.page-reai .form-group textarea{width:100%;padding:10px 14px;border:1px solid rgba(122,43,255,.14);border-radius:12px;background:rgba(245,247,255,.85);color:var(--text);font-size:.9rem;outline:none;}
        body.page-reai .form-group textarea{resize:vertical;min-height:90px;}
        body.page-reai .btn-primary{width:100%;padding:12px;border:none;border-radius:14px;background:var(--grad-main);color:white;font-size:.92rem;font-weight:800;cursor:pointer;}
        body.page-reai .btn-primary:disabled{opacity:.6;cursor:not-allowed;}
        body.page-reai .historial-item{border:1px solid rgba(122,43,255,.12);border-radius:14px;padding:14px;margin-bottom:10px;background:rgba(255,255,255,.62);text-align:left;}
        body.page-reai .historial-header{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;}
        body.page-reai .historial-asunto{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.74rem;font-weight:800;}
        body.page-reai .asunto-r{background:#DBEAFE;color:#1D4ED8;}
        body.page-reai .asunto-e{background:#FEF3C7;color:#92400E;}
        body.page-reai .asunto-a{background:#FEE2E2;color:#991B1B;}
        body.page-reai .asunto-i{background:#F3E8FF;color:#6B21A8;}
        body.page-reai .historial-fecha,body.page-reai .historial-desc,body.page-reai .historial-evidencia a{font-size:.8rem;}
        body.page-reai .historial-fecha{color:var(--text2);}
        body.page-reai .historial-evidencia a{color:#7A2BFF;text-decoration:none;font-weight:700;}
        body.page-reai .divider{border:none;border-top:1px solid rgba(122,43,255,.12);margin:20px 0;}
        body.page-reai .section-label{font-size:.76rem;color:var(--text2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;font-weight:800;text-align:left;}
        body.page-reai .toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;font-size:.85rem;font-weight:700;z-index:9999;display:none;color:white;box-shadow:var(--shadow);}
        body.page-reai .toast.show{display:block;}
        body.page-reai .toast.success{background:#065F46;}
        body.page-reai .toast.error{background:#991B1B;}
        body.page-reai .empty-state{text-align:center;padding:48px;color:var(--text2);}
        body.page-reai .kpi-mini-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 16px;}
        body.page-reai .kpi-mini{background:rgba(255,255,255,.72);border:1px solid rgba(122,43,255,.10);border-radius:18px;padding:14px 16px;box-shadow:0 12px 30px rgba(19,32,64,.05);}
        body.page-reai .kpi-mini-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);font-weight:800;}
        body.page-reai .kpi-mini-val{font-size:1.45rem;font-weight:900;margin-top:4px;color:var(--text);}
        body.page-reai .toolbar-v2{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px;}
        body.page-reai .period-tabs{display:flex;gap:8px;background:rgba(255,255,255,.65);border-radius:16px;padding:6px;border:1px solid rgba(122,43,255,.10);}
        body.page-reai .period-tabs a{padding:8px 13px;border-radius:12px;text-decoration:none;font-size:.78rem;font-weight:900;color:var(--text2);}
        body.page-reai .period-tabs a.active{background:var(--grad-main);color:white;}
        body.page-reai .status-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:.72rem;font-weight:900;white-space:nowrap;}
        body.page-reai .status-ok{background:#DCFCE7;color:#166534;}
        body.page-reai .status-seg{background:#FEF3C7;color:#92400E;}
        body.page-reai .status-alert{background:#FFEDD5;color:#9A3412;}
        body.page-reai .status-risk{background:#FEE2E2;color:#991B1B;}
        body.page-reai .prod-pill{display:inline-flex;min-width:46px;justify-content:center;padding:5px 8px;border-radius:999px;font-weight:900;font-size:.74rem;}
        body.page-reai .prod-good{background:#DCFCE7;color:#166534;}
        body.page-reai .prod-mid{background:#FEF3C7;color:#92400E;}
        body.page-reai .prod-bad{background:#FEE2E2;color:#991B1B;}
        body.page-reai .pct-up{color:#059669;font-weight:900;}
        body.page-reai .pct-down{color:#DC2626;font-weight:900;}
        body.page-reai .pct-flat{color:var(--text2);font-weight:900;}

        /* Ajuste v3: tabla más compacta y headers ordenables */
        body.page-reai .table-card{border-radius:16px;overflow:hidden;}
        body.page-reai .table-card table{table-layout:fixed;width:100%;}
        body.page-reai .table-card th{padding:9px 10px;font-size:.68rem;line-height:1.05;white-space:nowrap;}
        body.page-reai .table-card td{padding:8px 10px;font-size:.76rem;line-height:1.12;vertical-align:middle;}
        body.page-reai .table-card tbody tr{height:46px;}
        body.page-reai .table-card th:nth-child(1), body.page-reai .table-card td:nth-child(1){width:28%;}
        body.page-reai .table-card th:nth-child(2), body.page-reai .table-card td:nth-child(2){width:7%;}
        body.page-reai .table-card th:nth-child(3), body.page-reai .table-card td:nth-child(3){width:7%;}
        body.page-reai .table-card th:nth-child(4), body.page-reai .table-card td:nth-child(4){width:7%;}
        body.page-reai .table-card th:nth-child(5), body.page-reai .table-card td:nth-child(5){width:9%;}
        body.page-reai .table-card th:nth-child(6), body.page-reai .table-card td:nth-child(6){width:8%;}
        body.page-reai .table-card th:nth-child(7), body.page-reai .table-card td:nth-child(7){width:9%;}
        body.page-reai .table-card th:nth-child(8), body.page-reai .table-card td:nth-child(8){width:8%;}
        body.page-reai .table-card th:nth-child(9), body.page-reai .table-card td:nth-child(9){width:11%;}
        body.page-reai .table-card th:nth-child(10), body.page-reai .table-card td:nth-child(10){width:6%;}
        body.page-reai .table-card .sub-text{font-size:.62rem;line-height:1;margin-top:1px;}
        body.page-reai .reai-badge{min-width:24px;height:24px;padding:0 6px;margin:0 1px;font-size:.68rem;border-radius:8px;}
        body.page-reai .prod-pill{min-width:42px;padding:4px 7px;font-size:.7rem;}
        body.page-reai .meta-pill{display:inline-flex;min-width:42px;justify-content:center;padding:4px 7px;border-radius:999px;font-weight:900;font-size:.7rem;background:#EEF2FF;color:#3730A3;}
        body.page-reai .alcance-good{color:#059669;font-weight:900;}
        body.page-reai .alcance-mid{color:#D97706;font-weight:900;}
        body.page-reai .alcance-bad{color:#DC2626;font-weight:900;}
        body.page-reai .status-pill{padding:5px 8px;font-size:.68rem;}
        body.page-reai th.sortable{cursor:pointer;user-select:none;position:relative;}
        body.page-reai th.sortable::after{content:'↕';font-size:.62rem;margin-left:6px;color:var(--text2);opacity:.55;}
        body.page-reai th.sortable.sort-asc::after{content:'↑';opacity:1;color:#7A2BFF;}
        body.page-reai th.sortable.sort-desc::after{content:'↓';opacity:1;color:#7A2BFF;}
        @media(max-width:1100px){body.page-reai .kpi-mini-row{grid-template-columns:repeat(2,minmax(0,1fr));}.toolbar-v2{align-items:flex-start;flex-direction:column;}}
    </style>
</head>
<body class="page-reai">
<?php
$current_page = 'reai';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main">
    <div class="page-header">
        <h2>Seguimiento REAI</h2>
        <p>
            <?= $periodo === 'mensual' ? 'Vista mensual' : 'Vista semanal' ?> ·
            <?= date('d/m/Y', strtotime($periodo_base['inicio'])) ?> - <?= date('d/m/Y', strtotime($periodo_base['fin'])) ?> vs
            <?= date('d/m/Y', strtotime($periodo_actual['inicio'])) ?> - <?= date('d/m/Y', strtotime($periodo_actual['fin'])) ?> ·
            <?php if ($puede_capturar): ?><span style="color:#059669;font-weight:700;">✓ Captura habilitada</span><?php else: ?><span style="color:var(--text2);">Solo visualización</span><?php endif; ?>
        </p>
    </div>

    <div class="kpi-mini-row">
        <div class="kpi-mini"><div class="kpi-mini-label">HC visible</div><div class="kpi-mini-val"><?= fmt_num($total_hc) ?></div></div>
        <div class="kpi-mini"><div class="kpi-mini-label">Con REAI</div><div class="kpi-mini-val" style="color:#7A2BFF;"><?= fmt_num($total_con_reai) ?></div></div>
        <div class="kpi-mini"><div class="kpi-mini-label">Sin REAI</div><div class="kpi-mini-val" style="color:#64748B;"><?= fmt_num($total_sin_reai) ?></div></div>
        <div class="kpi-mini"><div class="kpi-mini-label">Requiere atención</div><div class="kpi-mini-val" style="color:#DC2626;"><?= fmt_num($total_riesgo) ?></div></div>
    </div>

    <?php if (empty($vendedores)): ?>
        <div class="table-card"><div class="empty-state">No se encontraron colaboradores.</div></div>
    <?php else: ?>

    <div class="toolbar-v2">
        <div class="search-bar">
            <input type="text" class="search-input" id="buscador" placeholder="Buscar colaborador..." oninput="filtrarTabla()">
        </div>
        <div class="period-tabs">
            <a class="<?= $periodo === 'semanal' ? 'active' : '' ?>" href="?periodo=semanal">Semanal</a>
            <a class="<?= $periodo === 'mensual' ? 'active' : '' ?>" href="?periodo=mensual">Mensual</a>
        </div>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th class="left sortable" data-sort="text">Nombre</th>
                    <th class="sortable" data-sort="num">Antig.</th>
                    <th class="sortable" data-sort="num"><?= h($label_base) ?></th>
                    <th class="sortable" data-sort="num"><?= h($label_actual) ?></th>
                    <th class="sortable" data-sort="num">Dif %</th>
                    <th class="sortable" data-sort="num">Prod Día</th>
                    <th class="sortable" data-sort="num">Meta pror.</th>
                    <th class="sortable" data-sort="num">% Alcance</th>
                    <th class="sortable" data-sort="text">Acción sugerida</th>
                    <th class="sep">REAI</th>
                </tr>
            </thead>
            <tbody id="tablaBody">
            <?php foreach ($vendedores as $vend):
                $tgs       = $vend['numero_talento_gs'];
                $nombre    = $vend['nombre_colaborador'];
                $antig     = (int)($vend['antiguedad'] ?? 0);
                $st        = $stats[$tgs] ?? [];
                $inst_base = (int)($st['inst_base'] ?? 0);
                $inst_act  = (int)($st['inst_actual'] ?? 0);
                $dif       = $inst_act - $inst_base;
                $pct       = $inst_base > 0 ? round(($dif / $inst_base) * 100, 0) : ($inst_act > 0 ? 100 : 0);
                $prod      = $dias_habiles_actual > 0 ? round($inst_act / $dias_habiles_actual, 2) : 0;
                $prod_cls  = $prod >= .70 ? 'prod-good' : ($prod >= .40 ? 'prod-mid' : 'prod-bad');

                // Meta prorrateada por productividad:
                // meta del período a la fecha = productividad objetivo diaria * días hábiles transcurridos.
                // Equivale a: meta semanal/mensual completa / días hábiles del período * días hábiles transcurridos.
                $meta_prorrateada = round($meta_productividad_diaria * $dias_habiles_actual, 1);
                $pct_alcance = $meta_prorrateada > 0 ? round(($inst_act / $meta_prorrateada) * 100, 0) : 0;
                $alcance_cls = $pct_alcance >= 100 ? 'alcance-good' : ($pct_alcance >= 80 ? 'alcance-mid' : 'alcance-bad');

                $pct_cls   = $pct > 0 ? 'pct-up' : ($pct < 0 ? 'pct-down' : 'pct-flat');
                $reai      = $st['reai'] ?? [];
                $cnt_r     = $reai['Retroalimentación'] ?? 0;
                $cnt_e     = $reai['ECNUs'] ?? 0;
                $cnt_a     = $reai['Acta Administrativa'] ?? 0;
                $cnt_i     = $reai['Incidencia'] ?? 0;
                $reai_total = (int)($st['reai_total'] ?? 0);

                // Regla de acción sugerida v6:
                // - Nunca sugerir I; Incidencia es sólo documental/informativa.
                // - Si el colaborador va igual o mejor que el período anterior (dif >= 0), NO sugerir acción.
                // - Si la productividad es buena (verde >= .70), NO sugerir acción aunque exista caída leve.
                // - Sólo accionar cuando hay deterioro real (dif < 0) y productividad no verde.
                // - Leve: caída negativa leve + productividad amarilla => Aplicar R.
                // - Medio: caída negativa leve + productividad roja => Aplicar E.
                // - Grave: caída negativa pronunciada o cero productividad con caída => Aplicar A.
                $caida_pronunciada = ($pct <= -50 || $dif <= -2);
                $caida_leve        = ($dif < 0 && !$caida_pronunciada);

                if ($reai_total > 0) {
                    $estatus_txt = 'En seguimiento'; $estatus_cls = 'status-seg';
                } elseif ($dif >= 0 || $prod >= .70) {
                    $estatus_txt = 'OK'; $estatus_cls = 'status-ok';
                } elseif ($caida_pronunciada || $inst_act == 0) {
                    $estatus_txt = 'Aplicar A'; $estatus_cls = 'status-risk';
                } elseif ($prod < .40 && $caida_leve) {
                    $estatus_txt = 'Aplicar E'; $estatus_cls = 'status-risk';
                } elseif ($prod >= .40 && $prod < .70 && $caida_leve) {
                    $estatus_txt = 'Aplicar R'; $estatus_cls = 'status-alert';
                } else {
                    $estatus_txt = 'OK'; $estatus_cls = 'status-ok';
                }
            ?>
            <tr data-nombre="<?= strtolower(h($nombre)) ?>">
                <td class="left" data-sort-value="<?= h($nombre) ?>">
                    <div style="font-weight:600;">
                        <a href="detalle_vendedor.php?tgs=<?= urlencode($tgs) ?>&periodo=<?= urlencode($periodo) ?>" style="color:var(--blue);text-decoration:none;font-weight:700;" title="Ver detalle del vendedor">
                            <?= h($nombre) ?>
                        </a>
                    </div>
                    <div class="sub-text"><?= h($tgs) ?></div>
                </td>
                <td data-sort-value="<?= $antig ?>"><span style="font-weight:800;"><?= $antig ?></span> <span class="sub-text">m</span></td>
                <td data-sort-value="<?= $inst_base ?>"><span style="font-weight:900;"><?= fmt_num($inst_base) ?></span></td>
                <td data-sort-value="<?= $inst_act ?>"><span style="font-weight:900;"><?= fmt_num($inst_act) ?></span></td>
                <td data-sort-value="<?= $pct ?>"><span class="<?= $pct_cls ?>"><?= $dif >= 0 ? '+' : '' ?><?= fmt_num($dif) ?> / <?= $pct >= 0 ? '+' : '' ?><?= fmt_num($pct) ?>%</span></td>
                <td data-sort-value="<?= $prod ?>"><span class="prod-pill <?= $prod_cls ?>"><?= fmt_prod($prod) ?></span></td>
                <td data-sort-value="<?= $meta_prorrateada ?>"><span class="meta-pill"><?= fmt_meta($meta_prorrateada) ?></span></td>
                <td data-sort-value="<?= $pct_alcance ?>"><span class="<?= $alcance_cls ?>"><?= fmt_num($pct_alcance) ?>%</span></td>
                <td data-sort-value="<?= h($estatus_txt) ?>"><span class="status-pill <?= $estatus_cls ?>"><?= h($estatus_txt) ?></span></td>
                <td class="sep">
                    <?php
                    $asuntos_map = [
                        'R' => ['Retroalimentación',   $cnt_r],
                        'E' => ['ECNUs',               $cnt_e],
                        'A' => ['Acta Administrativa', $cnt_a],
                        'I' => ['Incidencia',           $cnt_i],
                    ];
                    foreach ($asuntos_map as $letra => [$asunto_val, $cnt]):
                        $tgs_js    = addslashes($tgs);
                        $nombre_js = addslashes($nombre);
                        $asunto_js = addslashes($asunto_val);
                    ?>
                        <?php if ($puede_capturar): ?>
                            <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'can-add' ?>"
                                onclick="abrirModal('<?= $tgs_js ?>','<?= $nombre_js ?>','<?= $asunto_js ?>')"
                                title="<?= $cnt > 0 ? h($asunto_val.' ('.$cnt.')') : h('Agregar '.$asunto_val) ?>">
                                <?= $cnt > 0 ? $letra.$cnt : $letra ?>
                            </button>
                        <?php else: ?>
                            <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'no-data' ?>"
                                <?= $cnt > 0 ? "onclick=\"abrirModal('$tgs_js','$nombre_js','$asunto_js')\"" : 'disabled' ?>>
                                <?= $cnt > 0 ? $letra.$cnt : $letra ?>
                            </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="modalOverlay" onclick="cerrarModal(event)">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle"></div>
            <button class="modal-close" onclick="cerrarModalBtn()">×</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
let currentTalento = '', currentNombre = '', currentAsunto = '';
const puedeCapturar = <?= $puede_capturar ? 'true' : 'false' ?>;
const asuntoColors = {
    'Retroalimentación':'asunto-r','ECNUs':'asunto-e',
    'Acta Administrativa':'asunto-a','Incidencia':'asunto-i'
};
const endpointActual = window.location.pathname.split('/').pop() || '';

function filtrarTabla() {
    const q = document.getElementById('buscador').value.toLowerCase();
    document.querySelectorAll('#tablaBody tr').forEach(tr => {
        const n = tr.dataset.nombre || '';
        tr.classList.toggle('hidden', q !== '' && !n.includes(q));
    });
}

function inicializarOrdenamiento() {
    document.querySelectorAll('th.sortable').forEach((th, index) => {
        th.addEventListener('click', () => ordenarTabla(index, th.dataset.sort || 'text', th));
    });
}
function ordenarTabla(colIndex, tipo, th) {
    const tbody = document.getElementById('tablaBody');
    if (!tbody) return;
    const asc = th.dataset.order !== 'asc';
    document.querySelectorAll('th.sortable').forEach(h => { h.classList.remove('sort-asc','sort-desc'); h.dataset.order = ''; });
    th.dataset.order = asc ? 'asc' : 'desc';
    th.classList.add(asc ? 'sort-asc' : 'sort-desc');

    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        const ca = a.children[colIndex];
        const cb = b.children[colIndex];
        let va = ca?.dataset.sortValue ?? ca?.innerText ?? '';
        let vb = cb?.dataset.sortValue ?? cb?.innerText ?? '';
        if (tipo === 'num') {
            va = parseFloat(String(va).replace(/,/g,''));
            vb = parseFloat(String(vb).replace(/,/g,''));
            va = Number.isFinite(va) ? va : 0;
            vb = Number.isFinite(vb) ? vb : 0;
            return asc ? va - vb : vb - va;
        }
        va = String(va).toLowerCase();
        vb = String(vb).toLowerCase();
        return asc ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
    });
    rows.forEach(row => tbody.appendChild(row));
}
document.addEventListener('DOMContentLoaded', inicializarOrdenamiento);
function abrirModal(talento, nombre, asunto) {
    currentTalento = talento; currentNombre = nombre; currentAsunto = asunto;
    document.getElementById('modalTitle').textContent = nombre + ' — ' + asunto;
    document.getElementById('modalOverlay').classList.add('active');
    document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text2);">Cargando...</div>';

    fetch(endpointActual + '?action=historial&talento_gs=' + encodeURIComponent(talento))
        .then(r => r.json())
        .then(data => {
            const filtrados = data.filter(r => r.asunto === asunto);
            let html = '';
            if (filtrados.length > 0) {
                html += `<div class="section-label">Historial (${filtrados.length})</div>`;
                filtrados.forEach(r => {
                    html += `<div class="historial-item">
                        <div class="historial-header">
                            <span class="historial-asunto ${asuntoColors[r.asunto]||''}">${r.asunto}</span>
                            <span class="historial-fecha">${r.fecha}</span>
                        </div>
                        <div class="historial-desc">${r.descripcion||'—'}</div>
                        ${r.evidencia?`<div class="historial-evidencia"><br><a href="../uploads/reai/${r.evidencia}" target="_blank">📎 Ver evidencia</a></div>`:''}
                    </div>`;
                });
            } else {
                html += '<div style="text-align:center;padding:16px 0;color:var(--text2);font-size:0.85rem;">Sin registros previos</div>';
            }
            if (puedeCapturar) {
                const hoy = new Date().toISOString().split('T')[0];
                html += `<hr class="divider">
                <div class="section-label">Nuevo registro</div>
                <div class="form-group"><label>Asunto</label>
                    <select id="f_asunto">
                        <option value="Retroalimentación" ${asunto==='Retroalimentación'?'selected':''}>Retroalimentación</option>
                        <option value="ECNUs" ${asunto==='ECNUs'?'selected':''}>ECNUs</option>
                        <option value="Acta Administrativa" ${asunto==='Acta Administrativa'?'selected':''}>Acta Administrativa</option>
                        <option value="Incidencia" ${asunto==='Incidencia'?'selected':''}>Incidencia</option>
                    </select>
                </div>
                <div class="form-group"><label>Fecha</label><input type="date" id="f_fecha" value="${hoy}"></div>
                <div class="form-group"><label>Descripción</label><textarea id="f_descripcion" placeholder="Escribe los detalles..."></textarea></div>
                <div class="form-group"><label>Evidencia (jpg, png, pdf, doc)</label><input type="file" id="f_evidencia" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"></div>
                <button class="btn-primary" id="btnGuardar" onclick="guardarReai()">Guardar registro</button>`;
            }
            document.getElementById('modalBody').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--red);">Error al cargar los datos.</div>';
            console.error('Error cargando historial:', err);
        });
}
function guardarReai() {
    const btn = document.getElementById('btnGuardar');
    btn.disabled = true; btn.textContent = 'Guardando...';
    const fd = new FormData();
    fd.append('action','guardar');
    fd.append('numero_talento_gs', currentTalento);
    fd.append('nombre_colaborador', currentNombre);
    fd.append('asunto', document.getElementById('f_asunto').value);
    fd.append('fecha', document.getElementById('f_fecha').value);
    fd.append('descripcion', document.getElementById('f_descripcion').value);
    const ev = document.getElementById('f_evidencia');
    if (ev && ev.files[0]) fd.append('evidencia', ev.files[0]);

    fetch(endpointActual, { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                mostrarToast(data.msg, 'success');
                cerrarModalBtn();
                setTimeout(() => location.reload(), 800);
            } else {
                mostrarToast(data.msg, 'error');
                btn.disabled = false; btn.textContent = 'Guardar registro';
            }
        })
        .catch(() => { mostrarToast('Error de conexión al servidor','error'); btn.disabled = false; btn.textContent = 'Guardar registro'; });
}
function cerrarModal(e) { if (e.target.id === 'modalOverlay') cerrarModalBtn(); }
function cerrarModalBtn() {
    document.getElementById('modalOverlay').classList.remove('active');
    document.getElementById('modalBody').innerHTML = '';
}
function mostrarToast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + tipo;
    setTimeout(() => t.className = 'toast', 3000);
}
</script>
</body>
</html>
