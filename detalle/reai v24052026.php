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

// Semana y año más recientes
$semana_actual = null; $anio_actual = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_actual = (int)$row_sem['semana'];
    $anio_actual   = (int)$row_sem['anio'];
}

$mes_actual = (int)date('n');
$anio_query = (int)date('Y');

// Día vencido: ayer
$fecha_dia_actual  = date('Y-m-d', strtotime('-1 day'));
// Misma semana anterior: mismo día de la semana pasada
$fecha_dia_anterior = date('Y-m-d', strtotime('-8 days'));

$roles_labels = [
    'admin'              => 'Administrador',
    'director_regional'  => 'Director Regional',
    'director_distrital' => 'Director Distrital',
    'lider'              => 'Líder',
    'coach'              => 'Coach',
    'vendedor'           => 'Vendedor',
];

$puede_capturar = ($rol === 'coach');

// ── HISTORIAL AJAX ────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'historial') {
    $talento = mysqli_real_escape_string($conexion, $_GET['talento_gs'] ?? '');
    $res = mysqli_query($conexion, "SELECT * FROM reai WHERE numero_talento_gs = '$talento' ORDER BY fecha DESC, created_at DESC");
    $registros = [];
    while ($row = mysqli_fetch_assoc($res)) $registros[] = $row;
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

    if (!in_array($asunto, $asuntos_validos)) {
        echo json_encode(['status'=>'error','msg'=>'Asunto no válido']); exit();
    }
    if (!empty($_FILES['evidencia']['name'])) {
        $ext     = pathinfo($_FILES['evidencia']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg','jpeg','png','pdf','doc','docx'];
        if (in_array(strtolower($ext), $allowed)) {
            $upload_dir = '../uploads/reai/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $nombre_archivo = time() . '_' . preg_replace('/[^a-zA-Z0-9._]/', '_', $_FILES['evidencia']['name']);
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
    if (mysqli_query($conexion, $sql)) {
        echo json_encode(['status'=>'ok','msg'=>'Registro guardado correctamente']);
    } else {
        echo json_encode(['status'=>'error','msg'=>'Error: '.mysqli_error($conexion)]);
    }
    exit();
}

// ── OBTENER VENDEDORES SEGÚN JERARQUÍA ────────────────────────────────────────
$vendedores = [];
if ($semana_actual && $anio_actual) {
    if ($rol === 'coach') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE v.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_actual, $anio_actual);
    } elseif ($rol === 'lider') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE c.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_actual, $anio_actual);
    } elseif ($rol === 'director_distrital') {
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     INNER JOIN hc l ON c.posicion_lr = l.id_posicion AND l.semana = v.semana AND l.anio = v.anio
                     WHERE l.posicion_lr = ? AND v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY l.nombre_colaborador, c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana_actual, $anio_actual);
    } else {
        // admin / director_regional
        $sql_vend = "SELECT v.nombre_colaborador, v.numero_talento_gs, v.fecha_alta,
                     TIMESTAMPDIFF(MONTH, v.fecha_alta, CURDATE()) as antiguedad,
                     c.nombre_colaborador as nombre_coach, c.numero_talento_gs as talento_coach
                     FROM hc v
                     INNER JOIN hc c ON v.posicion_lr = c.id_posicion AND c.semana = v.semana AND c.anio = v.anio
                     WHERE v.posicion IN ($puestos_in)
                     AND v.semana = ? AND v.anio = ?
                     AND v.numero_talento_gs NOT LIKE '%VACANTE%'
                     ORDER BY c.nombre_colaborador, v.nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql_vend);
        mysqli_stmt_bind_param($stmt, "ii", $semana_actual, $anio_actual);
    }
    mysqli_stmt_execute($stmt);
    $res_vend = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res_vend)) $vendedores[] = $row;
    mysqli_stmt_close($stmt);
}

// ── VENTAS E INSTALACIONES POR VENDEDOR ──────────────────────────────────────
$stats = [];
if (!empty($vendedores)) {
    $talentos = array_column($vendedores, 'numero_talento_gs');
    $ph = implode(',', array_fill(0, count($talentos), '?'));
    $tipos = str_repeat('s', count($talentos));

    // Ventas del mes
    $stmt_vm = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(*) as total FROM ventas WHERE MONTH(fecha_cierre)=? AND YEAR(fecha_cierre)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_vm, 'ii'.$tipos, $mes_actual, $anio_query, ...array_values($talentos));
    mysqli_stmt_execute($stmt_vm);
    $res_vm = mysqli_stmt_get_result($stmt_vm);
    while ($r = mysqli_fetch_assoc($res_vm)) $stats[$r['folio_empleado']]['ventas_mes'] = $r['total'];
    mysqli_stmt_close($stmt_vm);

    // Ventas día vencido (ayer)
    $stmt_vd = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(*) as total FROM ventas WHERE DATE(fecha_cierre)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_vd, 's'.$tipos, $fecha_dia_actual, ...array_values($talentos));
    mysqli_stmt_execute($stmt_vd);
    $res_vd = mysqli_stmt_get_result($stmt_vd);
    while ($r = mysqli_fetch_assoc($res_vd)) $stats[$r['folio_empleado']]['ventas_dia'] = $r['total'];
    mysqli_stmt_close($stmt_vd);

    // Ventas semana anterior (mismo día -7)
    $stmt_vs = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(*) as total FROM ventas WHERE DATE(fecha_cierre)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_vs, 's'.$tipos, $fecha_dia_anterior, ...array_values($talentos));
    mysqli_stmt_execute($stmt_vs);
    $res_vs = mysqli_stmt_get_result($stmt_vs);
    while ($r = mysqli_fetch_assoc($res_vs)) $stats[$r['folio_empleado']]['ventas_sem_ant'] = $r['total'];
    mysqli_stmt_close($stmt_vs);

    // Instalaciones del mes
    $stmt_im = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) as total FROM instalaciones WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_im, 'ii'.$tipos, $mes_actual, $anio_query, ...array_values($talentos));
    mysqli_stmt_execute($stmt_im);
    $res_im = mysqli_stmt_get_result($stmt_im);
    while ($r = mysqli_fetch_assoc($res_im)) $stats[$r['folio_empleado']]['inst_mes'] = $r['total'];
    mysqli_stmt_close($stmt_im);

    // Instalaciones día vencido
    $stmt_id = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) as total FROM instalaciones WHERE DATE(fecha)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_id, 's'.$tipos, $fecha_dia_actual, ...array_values($talentos));
    mysqli_stmt_execute($stmt_id);
    $res_id = mysqli_stmt_get_result($stmt_id);
    while ($r = mysqli_fetch_assoc($res_id)) $stats[$r['folio_empleado']]['inst_dia'] = $r['total'];
    mysqli_stmt_close($stmt_id);

    // Instalaciones semana anterior
    $stmt_is = mysqli_prepare($conexion, "SELECT folio_empleado, COUNT(cuenta) as total FROM instalaciones WHERE DATE(fecha)=? AND folio_empleado IN ($ph) GROUP BY folio_empleado");
    mysqli_stmt_bind_param($stmt_is, 's'.$tipos, $fecha_dia_anterior, ...array_values($talentos));
    mysqli_stmt_execute($stmt_is);
    $res_is = mysqli_stmt_get_result($stmt_is);
    while ($r = mysqli_fetch_assoc($res_is)) $stats[$r['folio_empleado']]['inst_sem_ant'] = $r['total'];
    mysqli_stmt_close($stmt_is);

    // REAI counts
    $stmt_rc = mysqli_prepare($conexion, "SELECT numero_talento_gs, asunto, COUNT(*) as total FROM reai WHERE numero_talento_gs IN ($ph) GROUP BY numero_talento_gs, asunto");
    mysqli_stmt_bind_param($stmt_rc, $tipos, ...array_values($talentos));
    mysqli_stmt_execute($stmt_rc);
    $res_rc = mysqli_stmt_get_result($stmt_rc);
    while ($r = mysqli_fetch_assoc($res_rc)) $stats[$r['numero_talento_gs']]['reai'][$r['asunto']] = $r['total'];
    mysqli_stmt_close($stmt_rc);
}

$label_dia_act = date('d/m', strtotime($fecha_dia_actual));
$label_dia_ant = date('d/m', strtotime($fecha_dia_anterior));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REAI — TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=161">
    <style>
        /* Soporte específico REAI: modal, historial y toast.
           El layout, sidebar, tabla, buscador y badges vienen del CSS maestro. */
        body.page-reai .modal-overlay.active{display:flex !important;}
        body.page-reai .modal-box{
            max-width:560px;
            max-height:90vh;
            overflow-y:auto;
        }
        body.page-reai .modal-header{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:14px;
            margin-bottom:20px;
        }
        body.page-reai .modal-close{
            background:none;
            border:none;
            font-size:1.4rem;
            color:var(--text2);
            cursor:pointer;
            line-height:1;
        }
        body.page-reai .form-group{
            margin-bottom:16px;
            text-align:left;
        }
        body.page-reai .form-group label{
            display:block;
            font-size:.78rem;
            font-weight:700;
            color:var(--text2);
            text-transform:uppercase;
            letter-spacing:.5px;
            margin-bottom:6px;
        }
        body.page-reai .form-group select,
        body.page-reai .form-group input,
        body.page-reai .form-group textarea{
            width:100%;
            padding:10px 14px;
            border:1px solid rgba(122,43,255,.14);
            border-radius:12px;
            background:rgba(245,247,255,.85);
            color:var(--text);
            font-size:.9rem;
            outline:none;
        }
        body.page-reai .form-group textarea{
            resize:vertical;
            min-height:90px;
        }
        body.page-reai .btn-primary{
            width:100%;
            padding:12px;
            border:none;
            border-radius:14px;
            background:var(--grad-main);
            color:white;
            font-size:.92rem;
            font-weight:800;
            cursor:pointer;
        }
        body.page-reai .btn-primary:disabled{
            opacity:.6;
            cursor:not-allowed;
        }
        body.page-reai .historial-item{
            border:1px solid rgba(122,43,255,.12);
            border-radius:14px;
            padding:14px;
            margin-bottom:10px;
            background:rgba(255,255,255,.62);
            text-align:left;
        }
        body.page-reai .historial-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            margin-bottom:8px;
        }
        body.page-reai .historial-asunto{
            display:inline-flex;
            align-items:center;
            padding:4px 10px;
            border-radius:999px;
            font-size:.74rem;
            font-weight:800;
        }
        body.page-reai .asunto-r{background:#DBEAFE;color:#1D4ED8;}
        body.page-reai .asunto-e{background:#FEF3C7;color:#92400E;}
        body.page-reai .asunto-a{background:#FEE2E2;color:#991B1B;}
        body.page-reai .asunto-i{background:#F3E8FF;color:#6B21A8;}
        body.page-reai .historial-fecha,
        body.page-reai .historial-desc,
        body.page-reai .historial-evidencia a{
            font-size:.8rem;
        }
        body.page-reai .historial-fecha{color:var(--text2);}
        body.page-reai .historial-evidencia a{
            color:#7A2BFF;
            text-decoration:none;
            font-weight:700;
        }
        body.page-reai .divider{
            border:none;
            border-top:1px solid rgba(122,43,255,.12);
            margin:20px 0;
        }
        body.page-reai .section-label{
            font-size:.76rem;
            color:var(--text2);
            text-transform:uppercase;
            letter-spacing:.6px;
            margin-bottom:14px;
            font-weight:800;
            text-align:left;
        }
        body.page-reai .toast{
            position:fixed;
            bottom:24px;
            right:24px;
            padding:12px 20px;
            border-radius:12px;
            font-size:.85rem;
            font-weight:700;
            z-index:9999;
            display:none;
            color:white;
            box-shadow:var(--shadow);
        }
        body.page-reai .toast.show{display:block;}
        body.page-reai .toast.success{background:#065F46;}
        body.page-reai .toast.error{background:#991B1B;}
        body.page-reai .empty-state{
            text-align:center;
            padding:48px;
            color:var(--text2);
        }
    </style>
</head>
<body class="page-reai">
<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/img/logo-xpedient.png?v=3" alt="Xpedient">
    </div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="ranking_productividad.php?anio=<?= $anio_actual ?>&semana=<?= $semana_actual ?>" class="nav-item"><span class="nav-icon">🏆</span> Ranking</a>
    <a href="hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span> Headcount</a>
    <a href="reai.php" class="nav-item active"><span class="nav-icon">📋</span> REAI</a>
    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <div class="page-header">
        <h2>Seguimiento de Equipo</h2>
        <p><?= date('d \d\e F Y') ?> · Comparativa: <?= $label_dia_act ?> vs <?= $label_dia_ant ?> ·
        <?php if ($puede_capturar): ?>
            <span style="color:#059669;font-weight:700;">✓ Captura habilitada</span>
        <?php else: ?>
            <span style="color:var(--text2);">Solo visualización</span>
        <?php endif; ?>
        </p>
    </div>

    <?php if (empty($vendedores)): ?>
        <div class="table-card"><div class="empty-state">No se encontraron colaboradores.</div></div>
    <?php else: ?>

    <div class="reai-toolbar">
        <div class="search-bar">
            <input type="text" class="search-input" id="buscador" placeholder="Buscar colaborador o coach..." oninput="filtrarTabla()">
        </div>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th colspan="3" class="left">Colaborador</th>
                    <th colspan="3">Ventas</th>
                    <th colspan="3">Instalaciones</th>
                    <th colspan="4" class="sep">REAI</th>
                </tr>
                <tr>
                    <th class="left">Nombre</th>
                    <th class="left">Coach</th>
                    <th>Antigüedad</th>
                    <th>Mes</th>
                    <th><?= $label_dia_act ?></th>
                    <th><?= $label_dia_ant ?> / Dif</th>
                    <th>Mes</th>
                    <th><?= $label_dia_act ?></th>
                    <th><?= $label_dia_ant ?> / Dif</th>
                    <th class="sep">R</th>
                    <th>E</th>
                    <th>A</th>
                    <th>I</th>
                </tr>
            </thead>
            <tbody id="tablaBody">
            <?php foreach ($vendedores as $vend):
                $tgs       = $vend['numero_talento_gs'];
                $nombre    = $vend['nombre_colaborador'];
                $antig     = $vend['antiguedad'] ?? 0;
                $coach_nom = $vend['nombre_coach'] ?? '—';
                $st        = $stats[$tgs] ?? [];

                $ventas_mes     = $st['ventas_mes'] ?? 0;
                $ventas_dia     = $st['ventas_dia'] ?? 0;
                $ventas_sem_ant = $st['ventas_sem_ant'] ?? 0;
                $ventas_diff    = $ventas_dia - $ventas_sem_ant;

                $inst_mes     = $st['inst_mes'] ?? 0;
                $inst_dia     = $st['inst_dia'] ?? 0;
                $inst_sem_ant = $st['inst_sem_ant'] ?? 0;
                $inst_diff    = $inst_dia - $inst_sem_ant;

                $reai  = $st['reai'] ?? [];
                $cnt_r = $reai['Retroalimentación'] ?? 0;
                $cnt_e = $reai['ECNUs'] ?? 0;
                $cnt_a = $reai['Acta Administrativa'] ?? 0;
                $cnt_i = $reai['Incidencia'] ?? 0;

                $vd_class = $ventas_diff > 0 ? 'diff-pos' : ($ventas_diff < 0 ? 'diff-neg' : 'diff-neu');
                $id_class = $inst_diff > 0 ? 'diff-pos' : ($inst_diff < 0 ? 'diff-neg' : 'diff-neu');
            ?>
            <tr data-nombre="<?= strtolower(htmlspecialchars($nombre)) ?>" data-coach="<?= strtolower(htmlspecialchars($coach_nom)) ?>">
                <td class="left">
                    <div style="font-weight:600;">
                        <a href="detalle_vendedor.php?tgs=<?= urlencode($tgs) ?>" style="color:var(--blue);text-decoration:none;font-weight:600;" title="Ver seguimiento">
                            <?= htmlspecialchars($nombre) ?>
                        </a>
                    </div>
                    <div class="sub-text"><?= htmlspecialchars($tgs) ?></div>
                </td>
                <td class="left" style="font-size:0.78rem;"><?= htmlspecialchars($coach_nom) ?></td>
                <td><span style="font-weight:700;"><?= $antig ?></span> <span class="sub-text">m</span></td>

                <td><span style="font-weight:700;"><?= $ventas_mes ?></span></td>
                <td><?= $ventas_dia ?></td>
                <td>
                    <span class="sub-text"><?= $ventas_sem_ant ?></span>
                    <span class="diff-badge <?= $vd_class ?>"><?= $ventas_diff >= 0 ? '+' : '' ?><?= $ventas_diff ?></span>
                </td>

                <td><span style="font-weight:700;"><?= $inst_mes ?></span></td>
                <td><?= $inst_dia ?></td>
                <td>
                    <span class="sub-text"><?= $inst_sem_ant ?></span>
                    <span class="diff-badge <?= $id_class ?>"><?= $inst_diff >= 0 ? '+' : '' ?><?= $inst_diff ?></span>
                </td>

                <?php
                $asuntos_map = [
                    'R' => ['Retroalimentación',   $cnt_r],
                    'E' => ['ECNUs',               $cnt_e],
                    'A' => ['Acta Administrativa', $cnt_a],
                    'I' => ['Incidencia',           $cnt_i],
                ];
                $first = true;
                foreach ($asuntos_map as $letra => [$asunto_val, $cnt]):
                    $tgs_js    = addslashes($tgs);
                    $nombre_js = addslashes($nombre);
                    $asunto_js = addslashes($asunto_val);
                    $sep_class = $first ? 'sep' : '';
                    $first = false;
                ?>
                <td class="<?= $sep_class ?>">
                    <?php if ($puede_capturar): ?>
                        <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'can-add' ?>"
                            onclick="abrirModal('<?= $tgs_js ?>','<?= $nombre_js ?>','<?= $asunto_js ?>')"
                            title="<?= $cnt > 0 ? "$asunto_val ($cnt)" : "Agregar $asunto_val" ?>">
                            <?= $cnt > 0 ? $cnt : '+' ?>
                        </button>
                    <?php else: ?>
                        <button class="reai-badge <?= $cnt > 0 ? 'has-data' : 'no-data' ?>"
                            <?= $cnt > 0 ? "onclick=\"abrirModal('$tgs_js','$nombre_js','$asunto_js')\"" : 'disabled' ?>>
                            <?= $cnt > 0 ? $cnt : '—' ?>
                        </button>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
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

// URL Dinámica a PRUEBA DE FALLOS: Siempre buscará en el archivo que esté abierto actualmente
const endpointActual = window.location.pathname.split('/').pop() || '';

function filtrarTabla() {
    const q = document.getElementById('buscador').value.toLowerCase();
    document.querySelectorAll('#tablaBody tr').forEach(tr => {
        const n = tr.dataset.nombre || '', c = tr.dataset.coach || '';
        tr.classList.toggle('hidden', q !== '' && !n.includes(q) && !c.includes(q));
    });
}

function abrirModal(talento, nombre, asunto) {
    currentTalento = talento; currentNombre = nombre; currentAsunto = asunto;
    document.getElementById('modalTitle').textContent = nombre + ' — ' + asunto;
    document.getElementById('modalOverlay').classList.add('active');
    document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text2);">Cargando...</div>';

    // Aquí está la corrección: apuntando al archivo actual
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
            document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:20px;color:var(--red);">Error al cargar los datos. Revisa la consola.</div>';
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

    // Apuntando la petición POST al archivo correcto
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
        .catch(() => { 
            mostrarToast('Error de conexión al servidor','error'); 
            btn.disabled = false; 
            btn.textContent = 'Guardar registro'; 
        });
}

function cerrarModal(e) { 
    if (e.target.id === 'modalOverlay') cerrarModalBtn(); 
}

function cerrarModalBtn() {
    document.getElementById('modalOverlay').classList.remove('active');
    document.getElementById('modalBody').innerHTML = '';
}

function mostrarToast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg; 
    t.className = 'toast show ' + tipo;
    setTimeout(() => t.className = 'toast', 3000);
}
</script>
</body>
</html>