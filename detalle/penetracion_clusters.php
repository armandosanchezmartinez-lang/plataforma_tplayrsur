<?php
/**
 * TotalXpedient | Penetración de Clústeres v1.3 | 2026-11-10
 * Ubicación: /plataforma/detalle/penetracion_clusters.php
 * Solo lectura. Requiere Clusters_BDU y Clusters_BDU_Catalogo (esquema v2).
 * Restricción SQL de distrito basada en sesión + HC para Director Distrital.
 */
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (empty($_SESSION['usuario'])) {
    header('Location: /plataforma/login.php');
    exit;
}
$rol = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (!in_array($rol, ['admin', 'director_regional', 'director_distrital'], true)) {
    http_response_code(403);
    exit('Acceso restringido: módulo de Penetración disponible para ADMIN, DIRECTOR REGIONAL y DIRECTOR DISTRITAL.');
}
require_once __DIR__ . '/../conexion.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(500);
    exit('Conexión de base de datos no disponible.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->set_charset('utf8mb4');

function pcStr(string $key, string $fallback=''): string {
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $fallback;
}
function pcHtml(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pcNormDistrito(string $str): string {
    $str = strtoupper(trim(strtr($str, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N'
    ])));
    $str = preg_replace('/\s+/u', ' ', $str) ?? $str;
    $str = preg_replace('/\s*\/\s*/', '/', $str) ?? $str;
    return match ($str) {
        'CANCUN' => 'CANCUN',
        'MERIDA' => 'MERIDA',
        'TUXTLA', 'TUXTLA GUTIERREZ' => 'TUXTLA',
        'VILLAHERMOSA' => 'VILLAHERMOSA',
        'COATZA/MINA','COATZA MINA','COATZA-MINA','COATZACOALCOS-MINATITLAN','COATZACOALCOS/MINATITLAN' => 'COATZA/MINA',
        default => '', // fail closed: nunca tomar un distrito sin equivalencia segura
    };
}
function pcQuery(mysqli $db, string $sql, string $types='', array $args=[]): array {
    $stmt = $db->prepare($sql);
    try {
        if ($types !== '') $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}
function pcDistritoAutorizado(mysqli $db): string {
    // Iguala la prioridad de identidad de index.php: Talento GS y, como respaldo, ID posición.
    $talento = trim((string)($_SESSION['numero_talento_gs'] ?? ''));
    $posicion = trim((string)($_SESSION['id_posicion'] ?? ''));
    if ($talento !== '') {
        $rows = pcQuery($db, "SELECT distrito FROM hc WHERE numero_talento_gs = ?
                 AND nombre_colaborador IS NOT NULL AND nombre_colaborador NOT LIKE '%VACANTE%'
                 ORDER BY anio DESC, semana DESC LIMIT 1", 's', [$talento]);
        if ($rows) return pcNormDistrito((string)($rows[0]['distrito'] ?? ''));
    }
    if ($posicion !== '') {
        $rows = pcQuery($db, "SELECT distrito FROM hc WHERE id_posicion = ?
                 AND nombre_colaborador IS NOT NULL AND nombre_colaborador NOT LIKE '%VACANTE%'
                 ORDER BY anio DESC, semana DESC LIMIT 1", 's', [$posicion]);
        if ($rows) return pcNormDistrito((string)($rows[0]['distrito'] ?? ''));
    }
    return '';
}
function pcFechaCorte(int $anio, string $tipo, int $numero): string {
    if ($tipo === 'MENSUAL') {
        return sprintf('%04d-%02d-%02d', $anio, $numero, (int)date('t', strtotime(sprintf('%04d-%02d-01', $anio, $numero))));
    }
    // Semanas ISO lunes-domingo. Sólo para ordenar cortes (no afirma fecha oficial Qlik).
    return (new DateTimeImmutable())->setISODate($anio, $numero, 7)->format('Y-m-d');
}
function pcMesNombre(int $n): string {
    $m = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
          7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $m[$n] ?? (string)$n;
}
/** Enlaces de navegación temporal: sólo cortes existentes, mismos filtros, sin fechas diarias. */
function pcCutUrl(array $cut): string {
    $query = [];
    foreach (['distrito','municipio','padre','nivel','umbral_pen','umbral_vel','min_hp'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key]) && strlen($_GET[$key]) <= 250) {
            $query[$key] = $_GET[$key];
        }
    }
    $query['periodo'] = (string)$cut['periodo'];
    $query['corte'] = (string)$cut['key'];
    return 'penetracion_clusters.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function pcCutButtonLabel(array $cut): string {
    return $cut['periodo'] === 'SEMANAL'
        ? 'Semana '.$cut['numero_periodo']
        : pcMesNombre((int)$cut['numero_periodo']);
}
function pcRows(mysqli $db, int $anio, string $periodo, int $numero, ?string $distrito): array {
    $sql = "SELECT b.distrito, b.cluster_padre, b.cluster_hijo, b.tipo,
                   b.base_usuarios, b.casas_pasadas, b.penetracion, b.velocidad_penetracion_anual,
                   b.antiguedad_anios, b.cancelaciones_mes, b.adiciones_netas_ultimo_trimestre,
                   c.plaza_municipio_localidad AS municipio
            FROM Clusters_BDU AS b
            LEFT JOIN Clusters_BDU_Catalogo AS c
              ON c.distrito = b.distrito AND c.cluster_hijo = b.cluster_hijo
            WHERE b.anio = ? AND b.periodo = ? AND b.numero_periodo = ?";
    $args = [$anio, $periodo, $numero];
    $types = 'isi';
    if ($distrito !== null) {
        $sql .= ' AND b.distrito = ?';
        $types .= 's';
        $args[] = $distrito;
    }
    $sql .= ' ORDER BY b.distrito, b.cluster_padre, b.tipo, b.cluster_hijo';
    return pcQuery($db, $sql, $types, $args);
}
function pcFiltered(array $rows, string $municipio, string $padre): array {
    return array_values(array_filter($rows, static function(array $r) use ($municipio,$padre): bool {
        $loc = trim((string)($r['municipio'] ?? ''));
        if ($municipio === '__PENDIENTE__' && $loc !== '') return false;
        if ($municipio !== '' && $municipio !== '__PENDIENTE__' && $municipio !== $loc) return false;
        if ($padre !== '' && $padre !== (string)$r['cluster_padre']) return false;
        return true;
    }));
}
/** En KPI: mismo universo con base_usuarios conocida y casas_pasadas > 0. */
function pcAgg(array $rows): array {
    $sumHP = 0; $sumU = 0; $comp = 0; $plotRows = 0; $sumVelHP = 0.0; $velHP = 0;
    $parents=[]; $ages=0.0; $agesN=0; $missingCatalog=0;
    foreach ($rows as $r) {
        $parents[$r['distrito'].'|'.$r['cluster_padre']] = true;
        if (trim((string)($r['municipio'] ?? '')) === '') $missingCatalog++;
        if ($r['antiguedad_anios'] !== null) {
            $ages += (float)$r['antiguedad_anios']; $agesN++;
        }
        if ($r['casas_pasadas'] === null || (int)$r['casas_pasadas'] <= 0 || $r['base_usuarios'] === null) continue;
        $hp = (int)$r['casas_pasadas']; $u = (int)$r['base_usuarios'];
        $sumHP += $hp; $sumU += $u; $comp++;
        if ($r['velocidad_penetracion_anual'] !== null) {
            $sumVelHP += $hp * (float)$r['velocidad_penetracion_anual']; $velHP += $hp;
            $plotRows++;
        }
    }
    return [
        'hijos'=>count($rows), 'padres'=>count($parents), 'comparables'=>$comp,
        'hp'=>$sumHP, 'usuarios'=>$sumU,
        'pen'=>$sumHP > 0 ? 100.0*$sumU/$sumHP : null,
        'vel'=>$velHP > 0 ? 100.0*$sumVelHP/$velHP : null,
        'antiguedad'=>$agesN > 0 ? $ages/$agesN : null,
        'graficables'=>$plotRows, 'sin_catalogo'=>$missingCatalog
    ];
}
function pcBubbles(array $rows, string $nivel, float $minHP, float $limX, float $limY): array {
    $groups=[];
    foreach ($rows as $r) {
        $hp = $r['casas_pasadas'] !== null ? (int)$r['casas_pasadas'] : 0;
        if ($hp <= 0 || $r['base_usuarios'] === null || $r['velocidad_penetracion_anual'] === null) continue;
        $key = $r['distrito'].'|'.$r['tipo'].'|'.$r['cluster_padre'];
        if ($nivel === 'HIJO') $key .= '|'.$r['cluster_hijo'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'label'=>$nivel === 'PADRE' ? $r['cluster_padre'] : $r['cluster_hijo'],
                'padre'=>$r['cluster_padre'], 'distrito'=>$r['distrito'], 'tipo'=>$r['tipo'],
                'hp'=>0, 'usuarios'=>0, 'velSum'=>0.0, 'nHijos'=>0, 'locs'=>[]
            ];
        }
        $g = &$groups[$key];
        $g['hp'] += $hp;
        $g['usuarios'] += (int)$r['base_usuarios'];
        $g['velSum'] += $hp * (float)$r['velocidad_penetracion_anual'];
        $g['nHijos']++;
        $g['locs'][] = trim((string)($r['municipio'] ?? ''));
        unset($g);
    }
    $out=['NORMAL'=>[],'CLUSTER_A'=>[]];
    foreach ($groups as $g) {
        if ($g['hp'] < $minHP) continue;
        $pen = 100.0*$g['usuarios']/$g['hp'];
        $vel = 100.0*$g['velSum']/$g['hp'];
        $quadrant = $pen >= $limX
            ? ($vel >= $limY ? 'Liderazgo en expansión' : 'Crecimiento limitado')
            : ($vel >= $limY ? 'Oportunidad de crecimiento' : 'Rezago en mercado');
        $locs = array_unique(array_filter($g['locs'], static fn($v) => $v !== ''));
        $out[$g['tipo']][] = [
            'label'=>$g['label'], 'padre'=>$g['padre'], 'distrito'=>$g['distrito'],
            'tipo'=>$g['tipo'], 'hp'=>$g['hp'], 'usuarios'=>$g['usuarios'],
            'pen'=>round($pen,3), 'vel'=>round($vel,3),
            'hijos'=>$g['nHijos'], 'municipio'=>count($locs)===1?reset($locs):(count($locs)>1?'Varias localidades':'Sin catalogar'),
            'cuadrante'=>$quadrant
        ];
    }
    foreach ($out as &$items) usort($items, static fn($a,$b) => $b['hp'] <=> $a['hp']);
    unset($items);
    return $out;
}
function pcNum(?float $v, int $dec=0): string {
    return $v === null ? 'ND' : number_format($v, $dec, '.', ',');
}
function pcPct(?float $v, int $dec=1): string {
    return $v === null ? 'ND' : number_format($v, $dec, '.', ',').'%';
}
function pcPp(?float $current, ?float $old): string {
    return $current === null || $old === null ? 'ND' : sprintf('%+.2f pp', $current - $old);
}

try {
    $scopeDistrict = null;
    $districts = ['CANCUN','COATZA/MINA','MERIDA','TUXTLA','VILLAHERMOSA'];
    if ($rol === 'director_distrital') {
        $scopeDistrict = pcDistritoAutorizado($conexion);
        if ($scopeDistrict === '' || !in_array($scopeDistrict, $districts, true)) {
            http_response_code(403);
            exit('No fue posible identificar de forma segura el distrito de tu usuario en HC. Solicita validar tu Talento GS/posición antes de acceder.');
        }
    } else {
        $requestedDistrict = pcNormDistrito(pcStr('distrito'));
        $scopeDistrict = $requestedDistrict !== '' ? $requestedDistrict : null;
    }

    $cutSql = 'SELECT DISTINCT anio, periodo, numero_periodo FROM Clusters_BDU';
    $cuts = $scopeDistrict === null
        ? pcQuery($conexion, $cutSql)
        : pcQuery($conexion, $cutSql.' WHERE distrito = ?', 's', [$scopeDistrict]);
    foreach ($cuts as &$cut) {
        $cut['anio'] = (int)$cut['anio']; $cut['numero_periodo'] = (int)$cut['numero_periodo'];
        $cut['key'] = $cut['anio'].'|'.$cut['periodo'].'|'.$cut['numero_periodo'];
        $cut['fecha'] = pcFechaCorte($cut['anio'], $cut['periodo'], $cut['numero_periodo']);
        $cut['label'] = ($cut['periodo'] === 'SEMANAL' ? 'Semana '.$cut['numero_periodo'] : pcMesNombre($cut['numero_periodo'])).' · '.$cut['anio'];
    }
    unset($cut);
    usort($cuts, static fn($a,$b) => strcmp($b['fecha'],$a['fecha']) ?: strcmp($b['key'],$a['key']));

    $tipoRequest = strtoupper(pcStr('periodo'));
    if (!in_array($tipoRequest, ['SEMANAL','MENSUAL'], true)) $tipoRequest = '';
    $cutRequest = pcStr('corte');
    $selected = null;
    foreach ($cuts as $cut) {
        if ($cut['key'] === $cutRequest && ($tipoRequest === '' || $cut['periodo'] === $tipoRequest)) {
            $selected=$cut; break;
        }
    }
    if ($selected === null) {
        foreach ($cuts as $cut) {
            if ($tipoRequest === '' || $cut['periodo'] === $tipoRequest) { $selected=$cut; break; }
        }
    }
    if ($selected !== null) $tipoRequest = $selected['periodo'];

    // Navegación canónica: separados SEMANAL y MENSUAL, únicamente cortes importados.
    // Se ordena por el año/número del período, sin calendarios ni días comparables.
    $navCuts = ['SEMANAL'=>[], 'MENSUAL'=>[]];
    foreach ($cuts as $cut) $navCuts[$cut['periodo']][] = $cut;
    foreach ($navCuts as &$navRows) {
        usort($navRows, static fn($a,$b) => ($b['anio'] <=> $a['anio']) ?: ($b['numero_periodo'] <=> $a['numero_periodo']));
    }
    unset($navRows);
    $navPrevious = null; // anterior: corte más antiguo disponible, no necesariamente semana consecutiva
    $navNext = null;     // siguiente: corte más reciente disponible
    if ($selected !== null) {
        foreach ($navCuts[$selected['periodo']] as $idx=>$cut) {
            if ($cut['key'] === $selected['key']) {
                $navNext = $navCuts[$selected['periodo']][$idx-1] ?? null;
                $navPrevious = $navCuts[$selected['periodo']][$idx+1] ?? null;
                break;
            }
        }
    }

    $selectedDistrict = $scopeDistrict ?? '';
    $rawRows = $selected === null ? [] : pcRows($conexion,$selected['anio'],$selected['periodo'],$selected['numero_periodo'],$scopeDistrict);
    $munis = []; $parents = [];
    foreach ($rawRows as $r) {
        $loc = trim((string)($r['municipio'] ?? ''));
        if ($loc !== '') $munis[$loc] = true;
    }
    $municipio = pcStr('municipio');
    if ($municipio !== '__PENDIENTE__' && !isset($munis[$municipio])) $municipio='';
    foreach ($rawRows as $r) {
        $loc = trim((string)($r['municipio'] ?? ''));
        if ($municipio === '__PENDIENTE__' && $loc !== '') continue;
        if ($municipio !== '' && $municipio !== '__PENDIENTE__' && $municipio !== $loc) continue;
        $parents[$r['cluster_padre']] = true;
    }
    $parent = pcStr('padre');
    if (!isset($parents[$parent])) $parent='';
    $rows = pcFiltered($rawRows,$municipio,$parent);
    $nivel = strtoupper(pcStr('nivel','PADRE'));
    if (!in_array($nivel,['PADRE','HIJO'],true)) $nivel='PADRE';
    $xLimit = is_numeric(pcStr('umbral_pen')) ? (float)pcStr('umbral_pen') : 29.0;
    $yLimit = is_numeric(pcStr('umbral_vel')) ? (float)pcStr('umbral_vel') : 7.0;
    $xLimit = max(0.0, min(100.0, $xLimit));
    $yLimit = max(-30.0, min(60.0, $yLimit));
    $minHP = is_numeric(pcStr('min_hp')) ? max(0,min(500000, (int)pcStr('min_hp'))) : 0;

    $summary = pcAgg($rows);
    $mature = pcAgg(array_values(array_filter($rows, static fn($r)=>$r['tipo']==='NORMAL')));
    $young = pcAgg(array_values(array_filter($rows, static fn($r)=>$r['tipo']==='CLUSTER_A')));
    $bubbles = pcBubbles($rows, $nivel, $minHP, $xLimit, $yLimit);
    $byDistrict = [];
    foreach ($rows as $r) $byDistrict[$r['distrito']][] = $r;
    ksort($byDistrict);

    $previous = null;
    $prevAgg = null;
    if ($selected !== null) {
        $sameKind = array_values(array_filter($cuts, static fn($cut)=>$cut['periodo']===$selected['periodo']));
        foreach ($sameKind as $i=>$cut) {
            if ($cut['key'] === $selected['key'] && isset($sameKind[$i+1])) {
                $previous = $sameKind[$i+1];
                break;
            }
        }
        if ($previous !== null) {
            $oldRows = pcRows($conexion, $previous['anio'],$previous['periodo'],$previous['numero_periodo'],$scopeDistrict);
            $prevAgg = pcAgg(pcFiltered($oldRows,$municipio,$parent));
        }
    }
} catch (Throwable $e) {
    error_log('penetracion_clusters.php: '.$e->getMessage());
    http_response_code(500);
    exit('No fue posible consultar el tablero. Verifica que el esquema Clusters_BDU v2 esté instalado y consulta el registro de errores PHP.');
}

ksort($munis, SORT_NATURAL | SORT_FLAG_CASE);
ksort($parents, SORT_NATURAL | SORT_FLAG_CASE);
$chartJson = json_encode([
    'NORMAL'=>$bubbles['NORMAL'],'CLUSTER_A'=>$bubbles['CLUSTER_A'],
    'umbralPen'=>$xLimit,'umbralVel'=>$yLimit,'nivel'=>$nivel,
    'canDistrict'=>$rol !== 'director_distrital',
    'corte'=>$selected['key'] ?? '', 'periodo'=>$selected['periodo'] ?? '',
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
$current_page = 'penetracion';
$displayScope = $scopeDistrict ?? 'REGIÓN SUR';
$roleLabel = ['admin'=>'ADMIN','director_regional'=>'DIRECCIÓN REGIONAL','director_distrital'=>'DIRECCIÓN DISTRITAL'][$rol];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Penetración de Clústeres | TotalXpedient</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=160">
    <link rel="stylesheet" href="../assets/css/penetracion_clusters.css?v=1.3.0">
</head>
<body class="page-dashboard pc-page">
<div class="tx-layout">
    <?php
    // Esta pantalla reside en /detalle/. El sidebar requiere tratar 'penetracion' como vista detalle; ver guía de integración.
    // Nunca se sobrescribe el sidebar productivo desde este paquete.
    if (is_file(__DIR__ . '/../includes/sidebar.php')) {
        include __DIR__ . '/../includes/sidebar.php';
    }
    ?>
    <main class="tx-main">
        <div class="tx-container">
            <header class="pc-header">
                <div>
                    <p class="pc-eyebrow">TOTALXPEDIENT · INTELIGENCIA COMERCIAL · REGIÓN SUR</p>
                    <h1>Penetración de Clústeres</h1>
                    <p class="pc-subtitle">Situación actual y mapa de oportunidades · Fuente: Qlik / Clusters_BDU</p>
                </div>
                <div class="pc-heading-meta">
                    <span class="pc-pill"><?=pcHtml($roleLabel)?></span>
                    <span class="pc-user"><?=pcHtml((string)($_SESSION['usuario'] ?? ''))?></span>
                    <a href="../index.php" class="pc-return">← Volver al dashboard</a>
                </div>
            </header>

            <!-- Navegación temporal estándar de TotalXpedient: SIN calendario diario. -->
            <nav class="pc-period-nav" aria-label="Navegación de períodos disponibles">
                <div class="pc-period-context">
                    <strong><?= $selected !== null ? pcHtml($selected['label']) : 'Sin cortes disponibles' ?></strong>
                    <span>Fotografía Qlik · cortes semanales y mensuales, sin selección de días</span>
                </div>
                <div class="pc-period-controls">
                    <div class="pc-period-tabs" role="group" aria-label="Tipo de período">
                        <?php foreach (['SEMANAL'=>'Semanal','MENSUAL'=>'Mensual'] as $kind=>$kindLabel): ?>
                            <?php if (!empty($navCuts[$kind])): $recent=$navCuts[$kind][0]; ?>
                                <a href="<?=pcHtml(pcCutUrl($recent))?>" class="pc-period-btn<?= ($selected!==null && $selected['periodo']===$kind) ? ' is-active' : '' ?>" <?= ($selected!==null && $selected['periodo']===$kind) ? 'aria-current="true"' : '' ?>><?=pcHtml($kindLabel)?></a>
                            <?php else: ?>
                                <span class="pc-period-btn is-disabled" aria-disabled="true" title="Sin cortes <?=pcHtml(strtolower($kindLabel))?> importados"><?=pcHtml($kindLabel)?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="pc-period-steps" role="group" aria-label="Corte anterior, actual y siguiente">
                        <?php if ($navPrevious !== null): ?>
                            <a class="pc-period-btn pc-period-arrow" href="<?=pcHtml(pcCutUrl($navPrevious))?>" title="Corte anterior disponible">← <?=pcHtml(pcCutButtonLabel($navPrevious))?></a>
                        <?php else: ?>
                            <span class="pc-period-btn pc-period-arrow is-disabled" aria-disabled="true">← Anterior</span>
                        <?php endif; ?>
                        <span class="pc-period-btn pc-period-current" aria-current="date"><?= $selected !== null ? pcHtml(pcCutButtonLabel($selected)) : 'Sin datos' ?></span>
                        <?php if ($navNext !== null): ?>
                            <a class="pc-period-btn pc-period-arrow" href="<?=pcHtml(pcCutUrl($navNext))?>" title="Corte siguiente disponible"><?=pcHtml(pcCutButtonLabel($navNext))?> →</a>
                        <?php else: ?>
                            <span class="pc-period-btn pc-period-arrow is-disabled" aria-disabled="true">Siguiente →</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($selected !== null && count($navCuts[$selected['periodo']])>1): ?>
                    <details class="pc-period-history">
                        <summary class="pc-period-btn pc-period-history-toggle">Otros cortes <span aria-hidden="true">⌄</span></summary>
                        <div class="pc-period-history-menu" role="group" aria-label="Cortes importados de <?=pcHtml(strtolower($selected['periodo']))?>">
                            <?php foreach ($navCuts[$selected['periodo']] as $historyCut): ?>
                                <a href="<?=pcHtml(pcCutUrl($historyCut))?>" class="<?= $selected['key']===$historyCut['key'] ? 'is-current' : '' ?>" <?= $selected['key']===$historyCut['key'] ? 'aria-current="true"' : '' ?>><?=pcHtml($historyCut['label'])?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </nav>

            <form class="pc-filters" id="filters" method="get" autocomplete="off">
                <?php if ($selected !== null): ?>
                    <input type="hidden" name="periodo" value="<?=pcHtml($selected['periodo'])?>">
                    <input type="hidden" name="corte" value="<?=pcHtml($selected['key'])?>">
                <?php endif; ?>
                <div class="pc-filter-title"><span>Filtros de análisis</span><small>Vista autorizada: <?=pcHtml($displayScope)?></small></div>
                <div class="pc-filter-grid">
                    <?php if ($rol !== 'director_distrital'): ?>
                    <label>Distrito
                        <select name="distrito" id="distrito">
                            <option value="">Región SUR (5 distritos)</option>
                            <?php foreach ($districts as $district): ?>
                            <option value="<?=pcHtml($district)?>" <?=$scopeDistrict===$district?'selected':''?>><?=pcHtml($district)?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php else: ?>
                    <label>Distrito asignado
                        <input type="text" readonly value="<?=pcHtml((string)$scopeDistrict)?>" aria-label="Distrito restringido por tu perfil">
                    </label>
                    <?php endif; ?>
                    <label>Municipio / localidad
                        <select name="municipio" id="municipio">
                            <option value="">Todos</option>
                            <option value="__PENDIENTE__" <?=$municipio==='__PENDIENTE__'?'selected':''?>>Sin catalogar</option>
                            <?php foreach ($munis as $muni=>$unused): ?>
                            <option value="<?=pcHtml($muni)?>" <?=$municipio===$muni?'selected':''?>><?=pcHtml($muni)?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Clúster padre
                        <select name="padre" id="padre">
                            <option value="">Todos los padres</option>
                            <?php foreach ($parents as $p=>$unused): ?>
                            <option value="<?=pcHtml($p)?>" <?=$parent===$p?'selected':''?>><?=pcHtml($p)?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Nivel de burbuja
                        <select name="nivel" id="nivel">
                            <option value="PADRE" <?=$nivel==='PADRE'?'selected':''?>>Clúster padre (consolidado)</option>
                            <option value="HIJO" <?=$nivel==='HIJO'?'selected':''?>>Clúster hijo (detalle)</option>
                        </select>
                    </label>
                    <label>Umbral X · Penetración (%)
                        <input name="umbral_pen" type="number" min="0" max="100" step="0.1" value="<?=pcHtml((string)$xLimit)?>">
                    </label>
                    <label>Umbral Y · Velocidad anual (%)
                        <input name="umbral_vel" type="number" min="-30" max="60" step="0.1" value="<?=pcHtml((string)$yLimit)?>">
                    </label>
                    <label>HPs mínimos por burbuja
                        <input name="min_hp" type="number" min="0" max="500000" step="1" value="<?=pcHtml((string)$minHP)?>">
                    </label>
                </div>
                <div class="pc-filter-footer">
                    <button type="submit" class="pc-primary">Aplicar filtros</button>
                    <a class="pc-secondary" href="penetracion_clusters.php">Restablecer</a>
                    <span>Las referencias 29% y 7% son del War Room, editables; no son metas oficiales.</span>
                </div>
            </form>

            <?php if ($selected===null): ?>
                <section class="pc-empty">Aún no hay cortes de Clusters_BDU disponibles para tu perfil. Revisa la importación.</section>
            <?php else: ?>
            <section class="pc-scope-row">
                <div><strong><?=pcHtml($displayScope)?></strong> · <?=pcHtml($selected['label'])?> · <span class="pc-muted"><?=pcHtml($nivel==='PADRE'?'Burbujas por clúster padre':'Burbujas por clúster hijo')?></span></div>
                <?php if ($previous !== null): ?>
                <div class="pc-comparison">Comparativo homogéneo: <?=pcHtml($previous['label'])?> · Δ Penetración <strong><?=pcHtml(pcPp($summary['pen'],$prevAgg['pen']))?></strong></div>
                <?php else: ?>
                <div class="pc-comparison">Comparativo: ND · Falta corte anterior <?=pcHtml(strtolower($selected['periodo']))?></div>
                <?php endif; ?>
            </section>

            <section class="pc-kpis" aria-label="Indicadores ejecutivos">
                <article class="pc-kpi"><span>Penetración ponderada</span><strong><?=pcHtml(pcPct($summary['pen']))?></strong><small><?=pcHtml(pcNum((float)$summary['usuarios']))?> suscriptores / <?=pcHtml(pcNum((float)$summary['hp']))?> HPs comparables</small></article>
                <article class="pc-kpi"><span>Velocidad anual ponderada</span><strong><?=pcHtml(pcPct($summary['vel']))?></strong><small>Promedio ponderado por HPs con velocidad válida</small></article>
                <article class="pc-kpi"><span>Clústeres hijo / padres</span><strong><?=$summary['hijos']?> <em>/ <?=$summary['padres']?></em></strong><small><?=$summary['comparables']?> hijos con bases comparables</small></article>
                <article class="pc-kpi <?= $summary['sin_catalogo']?'pc-warn':'' ?>"><span>Municipios pendientes</span><strong><?=$summary['sin_catalogo']?></strong><small>Clústeres sin localidad catalogada en el corte</small></article>
            </section>

            <?php if ($rol !== 'director_distrital' && $scopeDistrict === null && count($byDistrict)>0): ?>
            <section class="pc-table-card" aria-label="Desglose jerárquico por distrito">
                <div class="pc-section-head"><div><h2>Comparativo regional por distrito</h2><p>Selecciona un distrito para bajar un nivel dentro del mismo corte.</p></div></div>
                <div class="pc-table-scroll"><table class="pc-summary-table">
                    <thead><tr><th>Distrito</th><th>HPs comparables</th><th>Suscriptores</th><th>Penetración</th><th>Velocidad anual</th><th>Clústeres padre</th><th>Clústeres hijo</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($byDistrict as $districtName => $districtRows):
                        $ds = pcAgg($districtRows);
                        $districtParams = $_GET;
                        $districtParams['distrito'] = $districtName;
                        $districtParams['periodo'] = $selected['periodo'];
                        $districtParams['corte'] = $selected['key'];
                        unset($districtParams['municipio'], $districtParams['padre']);
                        $districtURL = 'penetracion_clusters.php?'.http_build_query($districtParams);
                    ?>
                    <tr><th><?=pcHtml($districtName)?></th><td><?=pcHtml(pcNum((float)$ds['hp']))?></td><td><?=pcHtml(pcNum((float)$ds['usuarios']))?></td><td><?=pcHtml(pcPct($ds['pen']))?></td><td><?=pcHtml(pcPct($ds['vel']))?></td><td><?=$ds['padres']?></td><td><?=$ds['hijos']?></td><td><a class="pc-drill-link" href="<?=pcHtml($districtURL)?>">Explorar distrito →</a></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
            <?php endif; ?>
            <section class="pc-charts" aria-label="Cuadrantes de penetración y crecimiento">
                <article class="pc-chart-card">
                    <header><div><h2>Clústeres maduros</h2><p>Tipo NORMAL · por <?=$nivel==='PADRE'?'padre':'hijo'?></p></div><span class="pc-dot" style="--dot:#0057FF"></span></header>
                    <div class="pc-svg-area" id="chart-normal" role="img" aria-label="Cuadrantes de clústeres maduros"></div>
                    <p class="pc-chart-caption"><b><?=count($bubbles['NORMAL'])?></b> burbujas graficables · clic en una burbuja para consultar su detalle.</p>
                </article>
                <article class="pc-chart-card pc-chart-card-a">
                    <header><div><h2>Clústeres_A</h2><p>Tipo CLUSTER_A · por <?=$nivel==='PADRE'?'padre':'hijo'?></p></div><span class="pc-dot" style="--dot:#7A2BFF"></span></header>
                    <div class="pc-svg-area" id="chart-a" role="img" aria-label="Cuadrantes de clústeres A"></div>
                    <p class="pc-chart-caption"><b><?=count($bubbles['CLUSTER_A'])?></b> burbujas graficables · sin HP, base o velocidad se excluyen del gráfico.</p>
                </article>
            </section>
            <div class="pc-legend"><span><i class="pc-legend-bubble"></i> Tamaño de burbuja = HPs válidos</span><span> Eje X = penetración ponderada</span><span> Eje Y = velocidad anual ponderada</span><label><input id="show-labels" type="checkbox" checked> Mostrar etiquetas</label></div>

            <section class="pc-table-card" aria-label="Resumen por segmento">
                <div class="pc-section-head"><div><h2>Resumen ejecutivo</h2><p>Agregados sobre bases comparables; madurez por tipo Qlik, no por umbral recalculado.</p></div></div>
                <div class="pc-table-scroll"><table class="pc-summary-table">
                    <thead><tr><th>Segmento</th><th>HPs comparables</th><th>Suscriptores</th><th>Penetración</th><th>Velocidad anual</th><th>Padres</th><th>Hijos</th><th>Antigüedad prom.</th></tr></thead>
                    <tbody>
                    <?php foreach ([['Región / distrito',$summary],['Maduros (NORMAL)',$mature],['CLUSTER_A',$young]] as [$label,$s]): ?>
                    <tr><th><?=pcHtml($label)?></th><td><?=pcHtml(pcNum((float)$s['hp']))?></td><td><?=pcHtml(pcNum((float)$s['usuarios']))?></td><td><?=pcHtml(pcPct($s['pen']))?></td><td><?=pcHtml(pcPct($s['vel']))?></td><td><?=$s['padres']?></td><td><?=$s['hijos']?></td><td><?= $s['antiguedad']===null ? 'ND' : pcHtml(pcNum($s['antiguedad'],1).' años') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="pc-fineprint">ARPU BDU no se muestra porque ese indicador no existe en Clusters_BDU v2. La velocidad es el dato de Qlik ponderado por HPs, no un crecimiento calculado entre dos snapshots. Para el agregado de penetración se usa Σ suscriptores / Σ HPs únicamente donde ambas bases son válidas.</p>
            </section>

            <section class="pc-table-card" aria-label="Detalle por clúster">
                <div class="pc-section-head"><div><h2>Detalle de clústeres hijo</h2><p>Drill-down Región → Distrito → Padre → Hijo, con catálogo geográfico independiente.</p></div><div class="pc-search"><input type="search" id="pc-search" placeholder="Buscar distrito, localidad o clúster" aria-label="Buscar clúster"></div></div>
                <div class="pc-table-scroll"><table class="pc-detail-table" id="pc-detail-table">
                    <thead><tr><th>Distrito</th><th>Municipio / localidad</th><th>Clúster padre</th><th>Clúster hijo</th><th>Tipo</th><th>HPs</th><th>Suscriptores</th><th>Penetración</th><th>Vel. anual</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $hp = $r['casas_pasadas']!==null ? (int)$r['casas_pasadas'] : null;
                        $u = $r['base_usuarios']!==null ? (int)$r['base_usuarios'] : null;
                        $valid = ($hp !== null && $hp > 0 && $u !== null);
                        $hasV = $r['velocidad_penetracion_anual']!==null;
                        $loc = trim((string)($r['municipio']??''));
                    ?>
                    <tr><td><?=pcHtml((string)$r['distrito'])?></td>
                        <td class="<?= $loc===''?'pc-null':'' ?>"><?= $loc===''?'Sin catalogar':pcHtml($loc) ?></td>
                        <td><?=pcHtml((string)$r['cluster_padre'])?></td><td><b><?=pcHtml((string)$r['cluster_hijo'])?></b></td>
                        <td><span class="pc-tag <?=$r['tipo']==='NORMAL'?'pc-tag-normal':'pc-tag-a'?>"><?=pcHtml((string)$r['tipo'])?></span></td>
                        <td><?=$hp===null?'ND':pcHtml(pcNum((float)$hp))?></td><td><?=$u===null?'ND':pcHtml(pcNum((float)$u))?></td>
                        <td><?=$valid?pcHtml(pcPct(100.0*$u/$hp)):'ND'?></td>
                        <td><?=$hasV?pcHtml(pcPct(100.0*(float)$r['velocidad_penetracion_anual'])):'ND'?></td>
                        <td><?=!$valid?'Sin base':(!$hasV?'Sin velocidad':'Comparable')?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="pc-table-footer"><span id="pc-table-count"><?=count($rows)?> registros</span><span>Los registros sin base o sin velocidad permanecen visibles en el detalle.</span></div>
            </section>
            <?php endif; ?>
            <footer class="pc-footer">TotalXpedient · Penetración de Clústeres v1.3 · Datos Qlik por fotografía semanal o mensual · Acceso jerárquico por rol.</footer>
        </div>
    </main>
</div>
<?php if ($selected!==null): ?>
<script id="pc-chart-data" type="application/json"><?=$chartJson?></script>
<script src="../assets/js/penetracion_clusters.js?v=1.3.0" defer></script>
<?php endif; ?>
</body>
</html>
