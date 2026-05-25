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

$rol         = $_SESSION['rol'] ?? 'vendedor';
$id_posicion = $_SESSION['id_posicion'] ?? '';
$puestos_comerciales = ['PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'];
$puestos_in = "'" . implode("','", $puestos_comerciales) . "'";

$semana_actual = null; $anio_actual = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_actual = (int)$row_sem['semana'];
    $anio_actual   = (int)$row_sem['anio'];
}

$roles_labels = [
    'admin'              => 'Administrador',
    'director_regional'  => 'Director Regional',
    'director_distrital' => 'Director Distrital',
    'lider'              => 'Líder',
    'coach'              => 'Coach',
    'vendedor'           => 'Vendedor',
];

function pct($activo, $total) {
    return $total > 0 ? round(($activo / $total) * 100) : 0;
}

function getDirectores($conexion, $rol, $id_posicion, $semana, $anio) {
    if ($rol === 'admin' || $rol === 'director_regional') {
        $sql = "SELECT DISTINCT id_posicion, nombre_colaborador FROM hc WHERE posicion = 'DIRECTOR DISTRITAL' AND semana = ? AND anio = ? ORDER BY nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "ii", $semana, $anio);
    } elseif ($rol === 'director_distrital') {
        $sql = "SELECT DISTINCT id_posicion, nombre_colaborador FROM hc WHERE id_posicion = ? AND semana = ? AND anio = ? LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana, $anio);
    } elseif ($rol === 'lider') {
        $sql = "SELECT DISTINCT h2.id_posicion, h2.nombre_colaborador FROM hc h1 INNER JOIN hc h2 ON h1.posicion_lr = h2.id_posicion WHERE h1.id_posicion = ? AND h1.semana = ? AND h1.anio = ? LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana, $anio);
    } elseif ($rol === 'coach') {
        $sql = "SELECT DISTINCT h3.id_posicion, h3.nombre_colaborador FROM hc h1 INNER JOIN hc h2 ON h1.posicion_lr = h2.id_posicion INNER JOIN hc h3 ON h2.posicion_lr = h3.id_posicion WHERE h1.id_posicion = ? AND h1.semana = ? AND h1.anio = ? LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $id_posicion, $semana, $anio);
    } else { return []; }
    mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt);
    $dirs = []; while ($row = mysqli_fetch_assoc($res)) $dirs[] = $row;
    mysqli_stmt_close($stmt); return $dirs;
}

function getLideres($conexion, $dir_id_posicion, $rol, $mi_id_posicion, $semana, $anio) {
    if ($rol === 'coach') {
        $sql = "SELECT DISTINCT h2.id_posicion, h2.nombre_colaborador FROM hc h1 INNER JOIN hc h2 ON h1.posicion_lr = h2.id_posicion WHERE h1.id_posicion = ? AND h1.semana = ? AND h1.anio = ? LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $mi_id_posicion, $semana, $anio);
    } else {
        $sql = "SELECT DISTINCT id_posicion, nombre_colaborador FROM hc WHERE posicion_lr = ? AND posicion LIKE '%LIDER VENTA%' AND semana = ? AND anio = ? ORDER BY nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $dir_id_posicion, $semana, $anio);
    }
    mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt);
    $lids = []; while ($row = mysqli_fetch_assoc($res)) $lids[] = $row;
    mysqli_stmt_close($stmt); return $lids;
}

function getCoaches($conexion, $lider_id_posicion, $rol, $mi_id_posicion, $semana, $anio) {
    if ($rol === 'coach') {
        $sql = "SELECT DISTINCT id_posicion, nombre_colaborador FROM hc WHERE id_posicion = ? AND semana = ? AND anio = ? LIMIT 1";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $mi_id_posicion, $semana, $anio);
    } else {
        $sql = "SELECT DISTINCT id_posicion, nombre_colaborador FROM hc WHERE posicion_lr = ? AND posicion LIKE '%COACH%' AND semana = ? AND anio = ? ORDER BY nombre_colaborador";
        $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $lider_id_posicion, $semana, $anio);
    }
    mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt);
    $coaches = []; while ($row = mysqli_fetch_assoc($res)) $coaches[] = $row;
    mysqli_stmt_close($stmt); return $coaches;
}

function getVendedores($conexion, $coach_id_posicion, $semana, $anio, $puestos_in) {
    $sql = "SELECT nombre_colaborador, numero_talento_gs FROM hc WHERE posicion_lr = ? AND posicion IN ($puestos_in) AND semana = ? AND anio = ? ORDER BY numero_talento_gs LIKE '%VACANTE%', nombre_colaborador";
    $stmt = mysqli_prepare($conexion, $sql); mysqli_stmt_bind_param($stmt, "sii", $coach_id_posicion, $semana, $anio);
    mysqli_stmt_execute($stmt); $res = mysqli_stmt_get_result($stmt);
    $vendedores = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $es_vacante = stripos($row['numero_talento_gs'], 'VACANTE') !== false;
        $vendedores[] = ['nombre' => $row['nombre_colaborador'], 'es_vacante' => $es_vacante, 'activo' => $es_vacante ? 0 : 1, 'vacante' => $es_vacante ? 1 : 0];
    }
    mysqli_stmt_close($stmt); return $vendedores;
}

$directores = getDirectores($conexion, $rol, $id_posicion, $semana_actual, $anio_actual);
$matriz = [];
foreach ($directores as $dir) {
    $lideres = getLideres($conexion, $dir['id_posicion'], $rol, $id_posicion, $semana_actual, $anio_actual);
    $dir_activo = 0; $dir_vacante = 0; $lids_data = [];
    foreach ($lideres as $lid) {
        $coaches = getCoaches($conexion, $lid['id_posicion'], $rol, $id_posicion, $semana_actual, $anio_actual);
        $lid_activo = 0; $lid_vacante = 0; $coaches_data = [];
        foreach ($coaches as $coach) {
            $vendedores = getVendedores($conexion, $coach['id_posicion'], $semana_actual, $anio_actual, $puestos_in);
            $c_activo  = array_sum(array_column($vendedores, 'activo'));
            $c_vacante = array_sum(array_column($vendedores, 'vacante'));
            $lid_activo  += $c_activo; $lid_vacante += $c_vacante;
            $coaches_data[] = ['nombre' => $coach['nombre_colaborador'], 'activo' => $c_activo, 'vacante' => $c_vacante, 'total' => $c_activo + $c_vacante, 'vendedores' => $vendedores];
        }
        $dir_activo += $lid_activo; $dir_vacante += $lid_vacante;
        $lids_data[] = ['nombre' => $lid['nombre_colaborador'], 'activo' => $lid_activo, 'vacante' => $lid_vacante, 'total' => $lid_activo + $lid_vacante, 'coaches' => $coaches_data];
    }
    $matriz[] = ['nombre' => $dir['nombre_colaborador'], 'activo' => $dir_activo, 'vacante' => $dir_vacante, 'total' => $dir_activo + $dir_vacante, 'lideres' => $lids_data];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Headcount — TOTALXPEDIENT</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=160">
</head>
<body class="page-hc">
<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/img/logo-xpedient.png?v=3" alt="Xpedient">
    </div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>
    <a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span> Dashboard</a>
    <a href="ranking_productividad.php?anio=<?= $anio_actual ?>&semana=<?= $semana_actual ?>" class="nav-item"><span class="nav-icon">🏆</span> Ranking</a>
    <a href="hc_detalle.php" class="nav-item active"><span class="nav-icon">👥</span> Headcount</a>
    <a href="reai.php" class="nav-item"><span class="nav-icon">📋</span> REAI</a>
    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-btn">⎋ Cerrar sesión</a>
    </div>
</aside>

<main class="main">
    <div class="page-header">
        <div>
            <h2>Headcount Comercial <span class="semana-badge">Semana <?= $semana_actual ?> · <?= $anio_actual ?></span></h2>
            <p><?= date('d \d\e F Y') ?> · <?= htmlspecialchars($roles_labels[$rol] ?? $rol) ?></p>
        </div>
    </div>

    <div class="table-card">
        <table class="hc-table">
            <colgroup>
                <col class="col-director">
                <col class="col-lider">
                <col class="col-coach">
                <col class="col-vendedor">
                <col class="col-metric">
                <col class="col-metric">
                <col class="col-metric">
                <col class="col-metric">
            </colgroup>
            <thead>
                <tr>
                    <th>Director</th>
                    <th>Líder</th>
                    <th>Coach</th>
                    <th>Vendedor</th>
                    <th class="num">Activo</th>
                    <th class="num">Vacante</th>
                    <th class="num">Total</th>
                    <th class="num">% Ocup.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($matriz as $di => $dir):
                $dir_pct = pct($dir['activo'], $dir['total']);
            ?>
                <!-- DIRECTOR -->
                <tr class="row-director" onclick="toggleDir(<?= $di ?>)">
                    <td colspan="4"><span class="toggle-btn" id="icon-dir-<?= $di ?>">▶</span><?= htmlspecialchars($dir['nombre']) ?></td>
                    <td class="num"><span class="badge badge-green"><?= $dir['activo'] ?></span></td>
                    <td class="num"><span class="badge <?= $dir['vacante'] > 0 ? 'badge-red' : 'badge-zero' ?>"><?= $dir['vacante'] ?></span></td>
                    <td class="num"><span class="badge badge-gray"><?= $dir['total'] ?></span></td>
                    <td class="num"><span class="pct-badge"><?= $dir_pct ?>%</span></td>
                </tr>

                <?php foreach ($dir['lideres'] as $li => $lid):
                    $lid_pct = pct($lid['activo'], $lid['total']);
                ?>
                <!-- LIDER -->
                <tr class="row-lider dir-<?= $di ?> hc-hidden" onclick="toggleLid(<?= $di ?>,<?= $li ?>)">
                    <td></td>
                    <td colspan="3"><span class="toggle-btn" id="icon-lid-<?= $di ?>-<?= $li ?>">▶</span><?= htmlspecialchars($lid['nombre']) ?></td>
                    <td class="num"><span class="badge badge-green"><?= $lid['activo'] ?></span></td>
                    <td class="num"><span class="badge <?= $lid['vacante'] > 0 ? 'badge-red' : 'badge-zero' ?>"><?= $lid['vacante'] ?></span></td>
                    <td class="num"><span class="badge badge-gray"><?= $lid['total'] ?></span></td>
                    <td class="num"><span class="pct-badge"><?= $lid_pct ?>%</span></td>
                </tr>

                <?php foreach ($lid['coaches'] as $ci => $coach):
                    $coach_pct = pct($coach['activo'], $coach['total']);
                ?>
                <!-- COACH -->
                <tr class="row-coach dir-<?= $di ?> lid-<?= $di ?>-<?= $li ?> hc-hidden" onclick="toggleCoach(<?= $di ?>,<?= $li ?>,<?= $ci ?>)">
                    <td></td><td></td>
                    <td colspan="2"><span class="toggle-btn" id="icon-coach-<?= $di ?>-<?= $li ?>-<?= $ci ?>">▶</span><?= htmlspecialchars($coach['nombre']) ?></td>
                    <td class="num"><span class="badge badge-green"><?= $coach['activo'] ?></span></td>
                    <td class="num"><span class="badge <?= $coach['vacante'] > 0 ? 'badge-red' : 'badge-zero' ?>"><?= $coach['vacante'] ?></span></td>
                    <td class="num"><span class="badge badge-gray"><?= $coach['total'] ?></span></td>
                    <td class="num"><span class="pct-badge"><?= $coach_pct ?>%</span></td>
                </tr>

                <?php foreach ($coach['vendedores'] as $vend): ?>
                <!-- VENDEDOR -->
                <tr class="row-vendedor <?= $vend['es_vacante'] ? 'vacante' : '' ?> dir-<?= $di ?> lid-<?= $di ?>-<?= $li ?> coach-<?= $di ?>-<?= $li ?>-<?= $ci ?> hc-hidden">
                    <td></td><td></td><td></td>
                    <td><?= htmlspecialchars($vend['nombre']) ?></td>
                    <td class="num"><span class="badge <?= $vend['activo'] ? 'badge-green' : 'badge-zero' ?>"><?= $vend['activo'] ?></span></td>
                    <td class="num"><span class="badge <?= $vend['vacante'] ? 'badge-red' : 'badge-zero' ?>"><?= $vend['vacante'] ?></span></td>
                    <td class="num"><span class="badge badge-gray">1</span></td>
                    <td class="num"></td>
                </tr>
                <?php endforeach; ?>

                <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
function hideRows(selector) {
    document.querySelectorAll(selector).forEach(row => {
        row.classList.add('hc-hidden');
    });
}

function showRows(selector) {
    document.querySelectorAll(selector).forEach(row => {
        row.classList.remove('hc-hidden');
    });
}

function setIcon(id, open) {
    const icon = document.getElementById(id);
    if (icon) icon.textContent = open ? '▼' : '▶';
}

function toggleDir(di) {
    const lideres = document.querySelectorAll('.row-lider.dir-' + di);
    const isClosed = lideres.length > 0 && lideres[0].classList.contains('hc-hidden');

    if (isClosed) {
        showRows('.row-lider.dir-' + di);
        setIcon('icon-dir-' + di, true);
    } else {
        hideRows('.row-lider.dir-' + di);
        hideRows('.row-coach.dir-' + di);
        hideRows('.row-vendedor.dir-' + di);

        setIcon('icon-dir-' + di, false);

        document.querySelectorAll('[id^="icon-lid-' + di + '-"]').forEach(i => i.textContent = '▶');
        document.querySelectorAll('[id^="icon-coach-' + di + '-"]').forEach(i => i.textContent = '▶');
    }
}

function toggleLid(di, li) {
    if (window.event) window.event.stopPropagation();

    const coaches = document.querySelectorAll('.row-coach.lid-' + di + '-' + li);
    const isClosed = coaches.length > 0 && coaches[0].classList.contains('hc-hidden');

    if (isClosed) {
        showRows('.row-coach.lid-' + di + '-' + li);
        setIcon('icon-lid-' + di + '-' + li, true);
    } else {
        hideRows('.row-coach.lid-' + di + '-' + li);
        hideRows('.row-vendedor.lid-' + di + '-' + li);

        setIcon('icon-lid-' + di + '-' + li, false);

        document.querySelectorAll('[id^="icon-coach-' + di + '-' + li + '-"]').forEach(i => i.textContent = '▶');
    }
}

function toggleCoach(di, li, ci) {
    if (window.event) window.event.stopPropagation();

    const vendedores = document.querySelectorAll('.row-vendedor.coach-' + di + '-' + li + '-' + ci);
    const isClosed = vendedores.length > 0 && vendedores[0].classList.contains('hc-hidden');

    if (isClosed) {
        showRows('.row-vendedor.coach-' + di + '-' + li + '-' + ci);
        setIcon('icon-coach-' + di + '-' + li + '-' + ci, true);
    } else {
        hideRows('.row-vendedor.coach-' + di + '-' + li + '-' + ci);
        setIcon('icon-coach-' + di + '-' + li + '-' + ci, false);
    }
}
</script>
</body>
</html>