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

// El nivel principal permanece 'ranking' en ambos módulos. Identificamos el
// archivo ejecutado para marcar SOLO el submenú activo, sin cambiar sus PHP.
$txp_script_actual = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$txp_ranking_paginas = ['ranking_productividad.php', 'ranking_comercial.php'];
$txp_ranking_activo = $current_page === 'ranking'
    || in_array($txp_script_actual, $txp_ranking_paginas, true);
$txp_ranking_productividad_activo = $txp_script_actual === 'ranking_productividad.php';
$txp_ranking_comercial_activo = $txp_script_actual === 'ranking_comercial.php';

$is_detalle = $txp_ranking_activo || in_array($current_page, ['hc', 'reai', 'fcst_captura', 'fcst_dashboard', 'ejecucion_operativa_captura', 'ejecucion_operativa_consulta', 'ejecucion_operativa_acompanamientos'], true);
$root_path  = $is_detalle ? '../' : '';
$det_path   = $is_detalle ? '' : 'detalle/';

function txp_nav_active($page, $current_page) {
    return $page === $current_page ? ' active' : '';
}
?>
<style>
/* TalIA | Submenú Ranking. Alcance CSS limitado a este componente. */
.sidebar .txp-ranking-group { margin: 0; padding: 0; }
.sidebar .txp-ranking-toggle {
    display: flex;
    align-items: center;
    width: 100%;
    box-sizing: border-box;
    cursor: pointer;
    list-style: none;
    user-select: none;
}
.sidebar .txp-ranking-toggle::-webkit-details-marker { display: none; }
.sidebar .txp-ranking-toggle::marker { content: ''; }
.sidebar .txp-ranking-label { flex: 1; }
.sidebar .txp-ranking-chevron {
    margin-left: auto;
    font-size: .78rem;
    opacity: .8;
    transition: transform .18s ease;
}
.sidebar .txp-ranking-group[open] .txp-ranking-chevron { transform: rotate(180deg); }
.sidebar .txp-ranking-submenu {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 5px 12px 9px 38px;
}
.sidebar .txp-ranking-subitem {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 10px;
    box-sizing: border-box;
    border-radius: 10px;
    color: rgba(236, 230, 255, .82);
    font-size: .86rem;
    font-weight: 600;
    text-decoration: none;
    transition: color .15s ease, background .15s ease;
}
.sidebar .txp-ranking-subitem:hover,
.sidebar .txp-ranking-subitem:focus-visible {
    color: #fff;
    background: rgba(255, 255, 255, .10);
}
.sidebar .txp-ranking-subitem.active {
    color: #fff;
    background: rgba(168, 66, 255, .28);
    box-shadow: inset 3px 0 0 #e358ff;
}
.sidebar .txp-ranking-subicon { opacity: .78; font-size: .85rem; }
</style>
<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="<?= $root_path ?>assets/img/logo-xpedient.png?v=3" alt="Xpedient">
    </div>
    <div class="sidebar-brand">TOTALXPEDIENT</div>

    <a href="<?= $root_path ?>index.php" class="nav-item<?= txp_nav_active('dashboard', $current_page) ?>">
        <span class="nav-icon">⊞</span> Dashboard
    </a>
    <!-- Menú Ranking desplegable. Abierto por defecto en cualquiera de sus vistas. -->
    <details class="txp-ranking-group" <?= $txp_ranking_activo ? 'open' : '' ?>>
        <summary class="nav-item txp-ranking-toggle<?= $txp_ranking_activo ? ' active' : '' ?>" aria-label="Desplegar submenú Ranking">
            <span class="nav-icon">🏆</span>
            <span class="txp-ranking-label">Ranking</span>
            <span class="txp-ranking-chevron" aria-hidden="true">⌄</span>
        </summary>
        <nav class="txp-ranking-submenu" aria-label="Opciones de Ranking">
            <a href="<?= $det_path ?>ranking_productividad.php?periodo=semanal"
               class="txp-ranking-subitem<?= $txp_ranking_productividad_activo ? ' active' : '' ?>"
               <?= $txp_ranking_productividad_activo ? 'aria-current="page"' : '' ?>>
                <span class="txp-ranking-subicon" aria-hidden="true">▸</span> Productividad
            </a>
            <a href="<?= $det_path ?>ranking_comercial.php?periodo=semanal"
               class="txp-ranking-subitem<?= $txp_ranking_comercial_activo ? ' active' : '' ?>"
               <?= $txp_ranking_comercial_activo ? 'aria-current="page"' : '' ?>>
                <span class="txp-ranking-subicon" aria-hidden="true">▸</span> Mix Comercial
            </a>
        </nav>
    </details>
    <a href="<?= $det_path ?>hc_detalle.php" class="nav-item<?= txp_nav_active('hc', $current_page) ?>">
        <span class="nav-icon">👥</span> Headcount
    </a>
    <a href="<?= $det_path ?>reai.php" class="nav-item<?= txp_nav_active('reai', $current_page) ?>">
        <span class="nav-icon">📋</span> REAI
    </a>
    <a href="<?= $det_path ?>metas_fcst_captura.php" class="nav-item<?= txp_nav_active('fcst_captura', $current_page) ?>">
        <span class="nav-icon">🎯</span> Captura FCST
    </a>
    <a href="<?= $det_path ?>ejecucion_operativa_captura.php" class="nav-item<?= txp_nav_active('ejecucion_operativa_captura', $current_page) ?>">
        <span class="nav-icon">🚀</span> Ejecución Operativa
    </a>
    <a href="<?= $det_path ?>ejecucion_operativa_acompanamientos.php" class="nav-item<?= txp_nav_active('ejecucion_operativa_acompanamientos', $current_page) ?>" style="padding-left:42px;font-size:.88rem;">
        <span class="nav-icon">🤝</span> Acompañamientos
    </a>

    <a href="<?= $det_path ?>metas_fcst_dashboard.php" class="nav-item<?= txp_nav_active('fcst_dashboard', $current_page) ?>">
        <span class="nav-icon">🚦</span> Dashboard EJECUCION
    </a>
    <div class="sidebar-bottom">
        <?php if ($is_detalle): ?>
            <a href="<?= $root_path ?>logout.php" class="logout-btn">⎋ Cerrar sesión</a>
        <?php endif; ?>
    </div>
</aside>
