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

/* =========================================================
   Parche HIC-Fallback v1.0 — HC Detalle
   - Aplica historial_identidad_colaborador en memoria para
     homologar id_posicion / posicion_lr históricos.
   - Reconstruye jerarquía con fallback cuando existan
     posiciones autoreferenciadas o ligas históricas.
   ========================================================= */

function norm_txt($v) {
    return strtoupper(trim((string)$v));
}

function is_blank_id($v) {
    $v = trim((string)$v);
    return $v === '' || $v === '-' || strtoupper($v) === 'NULL' || strtoupper($v) === '0';
}

function is_role_pos($pos, $role) {
    $p = norm_txt($pos);
    if ($role === 'director') return $p === 'DIRECTOR DISTRITAL';
    if ($role === 'lider')    return strpos($p, 'LIDER VENTA') !== false || strpos($p, 'LÍDER VENTA') !== false;
    if ($role === 'coach')    return strpos($p, 'COACH') !== false;
    return false;
}

function is_puesto_comercial($pos, $puestos_comerciales) {
    return in_array(norm_txt($pos), array_map('norm_txt', $puestos_comerciales), true);
}

function is_vacante_row($row) {
    return stripos((string)($row['numero_talento_gs'] ?? ''), 'VACANTE') !== false
        || stripos((string)($row['nombre_colaborador'] ?? ''), 'VACANTE') !== false;
}

function table_exists($conexion, $table) {
    $table = mysqli_real_escape_string($conexion, $table);
    $res = mysqli_query($conexion, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}

function table_columns($conexion, $table) {
    $cols = [];
    $table = mysqli_real_escape_string($conexion, $table);
    $res = mysqli_query($conexion, "SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
    }
    return $cols;
}

function hic_alias_map($conexion) {
    $map = [];
    if (!table_exists($conexion, 'historial_identidad_colaborador')) return $map;

    $cols = table_columns($conexion, 'historial_identidad_colaborador');
    if (!$cols) return $map;

    $id_cols = [];
    foreach ($cols as $c) {
        $lc = strtolower($c);
        if (strpos($lc, 'id_posicion') !== false || strpos($lc, 'posicion') !== false) $id_cols[] = $c;
    }
    if (!$id_cols) return $map;

    $select = '`' . implode('`,`', array_map(function($c){ return str_replace('`','',$c); }, $id_cols)) . '`';
    $res = mysqli_query($conexion, "SELECT $select FROM historial_identidad_colaborador");
    if (!$res) return $map;

    while ($r = mysqli_fetch_assoc($res)) {
        $ids = [];
        foreach ($id_cols as $c) {
            $v = trim((string)($r[$c] ?? ''));
            if (!is_blank_id($v)) $ids[] = $v;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) < 2) continue;

        // Se toma como canónico el último id_posicion no vacío del registro.
        // Esto permite que posiciones antiguas apunten a la identidad vigente.
        $canon = end($ids);
        foreach ($ids as $id) $map[$id] = $canon;
    }

    // Aplanar cadenas de alias: A->B->C queda A->C.
    foreach (array_keys($map) as $k) {
        $seen = [];
        $v = $k;
        while (isset($map[$v]) && !isset($seen[$v])) {
            $seen[$v] = true;
            $v = $map[$v];
        }
        $map[$k] = $v;
    }
    return $map;
}

function canon_id($id, $alias) {
    $id = trim((string)$id);
    if ($id === '') return $id;
    return $alias[$id] ?? $id;
}

function load_hc_snapshot($conexion, $semana, $anio, $alias) {
    $sql = "SELECT * FROM hc WHERE semana = ? AND anio = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $semana, $anio);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['_id_original'] = (string)($row['id_posicion'] ?? '');
        $row['_lr_original'] = (string)($row['posicion_lr'] ?? '');
        $row['id_posicion'] = canon_id($row['_id_original'], $alias);
        $row['posicion_lr'] = canon_id($row['_lr_original'], $alias);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function first_row_by_id($rows, $id, $role = null) {
    $id = trim((string)$id);
    foreach ($rows as $r) {
        if ((string)($r['id_posicion'] ?? '') !== $id) continue;
        if ($role && !is_role_pos($r['posicion'] ?? '', $role)) continue;
        return $r;
    }
    return null;
}

function unique_people($rows, $role = null) {
    $out = []; $seen = [];
    foreach ($rows as $r) {
        if ($role && !is_role_pos($r['posicion'] ?? '', $role)) continue;
        $id = (string)($r['id_posicion'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $out[] = ['id_posicion' => $id, 'nombre_colaborador' => $r['nombre_colaborador'] ?? '', '_row' => $r];
    }
    usort($out, function($a, $b){ return strcmp($a['nombre_colaborador'], $b['nombre_colaborador']); });
    return $out;
}

function children_by_parent($rows, $parent_id, $role = null) {
    $out = []; $seen = [];
    $parent_id = trim((string)$parent_id);
    foreach ($rows as $r) {
        if ((string)($r['posicion_lr'] ?? '') !== $parent_id) continue;
        if ((string)($r['id_posicion'] ?? '') === $parent_id) continue; // evita autoreferenciados
        if ($role && !is_role_pos($r['posicion'] ?? '', $role)) continue;
        $id = (string)($r['id_posicion'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $out[] = ['id_posicion' => $id, 'nombre_colaborador' => $r['nombre_colaborador'] ?? '', '_row' => $r];
    }
    usort($out, function($a, $b){ return strcmp($a['nombre_colaborador'], $b['nombre_colaborador']); });
    return $out;
}

function same_scope($a, $b) {
    $dist_a = norm_txt($a['distrito'] ?? '');
    $dist_b = norm_txt($b['distrito'] ?? '');
    return $dist_a !== '' && $dist_a === $dist_b;
}

function find_parent_row($rows, $child_row, $parent_role) {
    $lr = (string)($child_row['posicion_lr'] ?? '');
    $id = (string)($child_row['id_posicion'] ?? '');
    if (!is_blank_id($lr) && $lr !== $id) {
        $p = first_row_by_id($rows, $lr, $parent_role);
        if ($p) return $p;
    }

    // Fallback por liga original sin canonizar.
    $lr_orig = (string)($child_row['_lr_original'] ?? '');
    if (!is_blank_id($lr_orig) && $lr_orig !== (string)($child_row['_id_original'] ?? '')) {
        foreach ($rows as $r) {
            if (((string)($r['_id_original'] ?? '') === $lr_orig || (string)($r['id_posicion'] ?? '') === $lr_orig)
                && is_role_pos($r['posicion'] ?? '', $parent_role)) return $r;
        }
    }

    // Fallback de alcance: si solo existe un posible padre del rol esperado en el mismo distrito, se toma.
    $candidates = [];
    foreach ($rows as $r) {
        if (!is_role_pos($r['posicion'] ?? '', $parent_role)) continue;
        if (same_scope($child_row, $r)) $candidates[] = $r;
    }
    if (count($candidates) === 1) return $candidates[0];

    return null;
}

function getDirectoresMem($rows, $rol, $id_posicion) {
    if ($rol === 'admin' || $rol === 'director_regional') return unique_people($rows, 'director');

    $me = first_row_by_id($rows, $id_posicion);
    if (!$me) return [];

    if ($rol === 'director_distrital') return is_role_pos($me['posicion'] ?? '', 'director') ? [[ 'id_posicion'=>$me['id_posicion'], 'nombre_colaborador'=>$me['nombre_colaborador'], '_row'=>$me ]] : [];
    if ($rol === 'lider') {
        $dir = find_parent_row($rows, $me, 'director');
        return $dir ? [[ 'id_posicion'=>$dir['id_posicion'], 'nombre_colaborador'=>$dir['nombre_colaborador'], '_row'=>$dir ]] : [];
    }
    if ($rol === 'coach') {
        $lid = find_parent_row($rows, $me, 'lider');
        $dir = $lid ? find_parent_row($rows, $lid, 'director') : null;
        return $dir ? [[ 'id_posicion'=>$dir['id_posicion'], 'nombre_colaborador'=>$dir['nombre_colaborador'], '_row'=>$dir ]] : [];
    }
    return [];
}

function getLideresMem($rows, $dir, $rol, $mi_id_posicion) {
    if ($rol === 'coach') {
        $me = first_row_by_id($rows, $mi_id_posicion, 'coach');
        $lid = $me ? find_parent_row($rows, $me, 'lider') : null;
        return $lid ? [[ 'id_posicion'=>$lid['id_posicion'], 'nombre_colaborador'=>$lid['nombre_colaborador'], '_row'=>$lid ]] : [];
    }
    if ($rol === 'lider') {
        $me = first_row_by_id($rows, $mi_id_posicion, 'lider');
        return $me ? [[ 'id_posicion'=>$me['id_posicion'], 'nombre_colaborador'=>$me['nombre_colaborador'], '_row'=>$me ]] : [];
    }
    return children_by_parent($rows, $dir['id_posicion'], 'lider');
}

function getCoachesMem($rows, $lider, $rol, $mi_id_posicion) {
    if ($rol === 'coach') {
        $me = first_row_by_id($rows, $mi_id_posicion, 'coach');
        return $me ? [[ 'id_posicion'=>$me['id_posicion'], 'nombre_colaborador'=>$me['nombre_colaborador'], '_row'=>$me ]] : [];
    }

    $coaches = children_by_parent($rows, $lider['id_posicion'], 'coach');
    $seen = [];
    foreach ($coaches as $c) $seen[$c['id_posicion']] = true;

    // Fallback: agrega coaches autoreferenciados/históricos cuyo padre real sea este líder.
    foreach ($rows as $r) {
        if (!is_role_pos($r['posicion'] ?? '', 'coach')) continue;
        $id = (string)($r['id_posicion'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;
        $parent = find_parent_row($rows, $r, 'lider');
        if ($parent && (string)$parent['id_posicion'] === (string)$lider['id_posicion']) {
            $seen[$id] = true;
            $coaches[] = ['id_posicion'=>$id, 'nombre_colaborador'=>$r['nombre_colaborador'] ?? '', '_row'=>$r];
        }
    }
    usort($coaches, function($a, $b){ return strcmp($a['nombre_colaborador'], $b['nombre_colaborador']); });
    return $coaches;
}

function getVendedoresMem($rows, $coach, $puestos_comerciales) {
    $vendedores = [];
    foreach ($rows as $row) {
        if (!is_puesto_comercial($row['posicion'] ?? '', $puestos_comerciales)) continue;
        if ((string)($row['posicion_lr'] ?? '') !== (string)$coach['id_posicion']) continue;
        if ((string)($row['id_posicion'] ?? '') === (string)$coach['id_posicion']) continue;
        $es_vacante = is_vacante_row($row);
        $vendedores[] = [
            'nombre' => $row['nombre_colaborador'] ?? '',
            'es_vacante' => $es_vacante,
            'activo' => $es_vacante ? 0 : 1,
            'vacante' => $es_vacante ? 1 : 0
        ];
    }
    usort($vendedores, function($a, $b){
        if ($a['es_vacante'] !== $b['es_vacante']) return $a['es_vacante'] ? 1 : -1;
        return strcmp($a['nombre'], $b['nombre']);
    });
    return $vendedores;
}

$hic_alias = hic_alias_map($conexion);
$hc_rows = load_hc_snapshot($conexion, $semana_actual, $anio_actual, $hic_alias);
$id_posicion_canon = canon_id($id_posicion, $hic_alias);

$directores = getDirectoresMem($hc_rows, $rol, $id_posicion_canon);
$matriz = [];
foreach ($directores as $dir) {
    $lideres = getLideresMem($hc_rows, $dir, $rol, $id_posicion_canon);
    $dir_activo = 0; $dir_vacante = 0; $lids_data = [];
    foreach ($lideres as $lid) {
        $coaches = getCoachesMem($hc_rows, $lid, $rol, $id_posicion_canon);
        $lid_activo = 0; $lid_vacante = 0; $coaches_data = [];
        foreach ($coaches as $coach) {
            $vendedores = getVendedoresMem($hc_rows, $coach, $puestos_comerciales);
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
<?php
$current_page = 'hc';
include __DIR__ . '/../includes/sidebar.php';
?>

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