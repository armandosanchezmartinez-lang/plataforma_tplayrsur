<?php
/**
 * Sidebar centralizado TOTALXPEDIENT
 *
 * Uso desde /plataforma/index.php:
 *   $current_page = 'dashboard';
 *   include __DIR__ . '/includes/sidebar.php';
 *
 * Uso desde /plataforma/detalle/*.php:
 *   $current_page = 'ranking'; // hc | reai | fcst_captura | fcst_dashboard | ejecucion_operativa_captura
 *   include __DIR__ . '/../includes/sidebar.php';
 */
$current_page = $current_page ?? '';

$is_detalle = in_array($current_page, ['ranking', 'hc', 'reai', 'fcst_captura', 'fcst_dashboard', 'ejecucion_operativa_captura', 'ejecucion_operativa_consulta', 'ejecucion_operativa_acompanamientos'], true);
$root_path  = $is_detalle ? '../' : '';
$det_path   = $is_detalle ? '' : 'detalle/';

function txp_nav_active($page, $current_page) {
    return $page === $current_page ? ' active' : '';
}
?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="<?= $root_path ?>assets/img/logo-xpedient.png?v=3" alt="Xpedient">
    </div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>

    <a href="<?= $root_path ?>index.php" class="nav-item<?= txp_nav_active('dashboard', $current_page) ?>">
        <span class="nav-icon">⊞</span> Dashboard
    </a>
    <a href="<?= $det_path ?>ranking_productividad.php?periodo=semanal" class="nav-item<?= txp_nav_active('ranking', $current_page) ?>">
        <span class="nav-icon">🏆</span> Ranking
    </a>
    <a href="<?= $det_path ?>hc_detalle.php" class="nav-item<?= txp_nav_active('hc', $current_page) ?>">
        <span class="nav-icon">👥</span> Headcount
    </a>
    <a href="<?= $det_path ?>reai.php" class="nav-item<?= txp_nav_active('reai', $current_page) ?>">
        <span class="nav-icon">📋</span> REAI
    </a>
    <a href="<?= $det_path ?>metas_fcst_captura.php" class="nav-item<?= txp_nav_active('fcst_captura', $current_page) ?>">
        <span class="nav-icon">🎯</span> Captura FCST
    </a>
    <a href="<?= $det_path ?>metas_fcst_dashboard.php" class="nav-item<?= txp_nav_active('fcst_dashboard', $current_page) ?>">
        <span class="nav-icon">🚦</span> Dashboard METAS/FCST/EJECUCION
    </a>

    <a href="<?= $det_path ?>ejecucion_operativa_captura.php" class="nav-item<?= txp_nav_active('ejecucion_operativa_captura', $current_page) ?>">
        <span class="nav-icon">🚀</span> Ejecución Operativa
    </a>
    <a href="<?= $det_path ?>ejecucion_operativa_acompanamientos.php" class="nav-item<?= txp_nav_active('ejecucion_operativa_acompanamientos', $current_page) ?>" style="padding-left:42px;font-size:.88rem;">
        <span class="nav-icon">🤝</span> Acompañamientos
    </a>

    <div class="sidebar-bottom">
        <?php if ($is_detalle): ?>
            <a href="<?= $root_path ?>logout.php" class="logout-btn">⎋ Cerrar sesión</a>
        <?php endif; ?>
    </div>
</aside>
