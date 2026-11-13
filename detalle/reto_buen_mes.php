<?php
/** TalIA · Reto Buen Mes · Noviembre 2026 · v1.2.1 · Sólo lectura. */
declare(strict_types=1);
header('Cache-Control: no-cache, no-store, must-revalidate');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$roles = ['admin','director_regional','director_distrital','lider','coach','vendedor'];
$rol = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (empty($_SESSION['usuario']) || !in_array($rol, $roles, true)) {
    http_response_code(403);
    exit('Sesión o perfil de TalIA no autorizado.');
}

if (($_GET['ver_comunicado'] ?? '') === '1') {
    $pdf = realpath(__DIR__ . '/../assets/docs/reto_buen_mes_noviembre_2026.pdf');
    if (!$pdf || !is_file($pdf)) {
        http_response_code(404);
        exit('Comunicado no disponible.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Reto-Buen-Mes-Noviembre-2026.pdf"');
    header('Content-Length: ' . (string)filesize($pdf));
    readfile($pdf);
    exit;
}

require __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/motor_hc.php';
require_once __DIR__ . '/../includes/reto_buen_mes_modelo.php';

function rbm_param(string $k): ?string { return isset($_GET[$k]) && is_scalar($_GET[$k]) ? (string)$_GET[$k] : null; }
function rbm_href(array $args = []): string { return '?' . http_build_query($args); }
function rbm_dates_url(array $m, array $args = []): string { return rbm_href(array_merge(['desde' => $m['from'], 'hasta' => $m['to']], $args)); }
function rbm_avail(array $m, ?string $person): ?array { return $person !== null ? ($m['disponibilidad'][$person] ?? null) : null; }
function rbm_format_date(?string $ymd): string {
    if ($ymd === null || $ymd === '') return '—';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d ? $d->format('d/m/Y') : $ymd;
}
function rbm_path(array $nodes, string $id): array {
    $out = [];
    while (isset($nodes[$id])) { $out[] = $nodes[$id]; $id = $nodes[$id]['parent'] ?? ''; }
    return array_reverse($out);
}
function rbm_role_label(string $role): string {
    return ['R' => 'Concentrado regional', 'D' => 'Distrito / Director Distrital', 'L' => 'Líder', 'C' => 'Coach', 'V' => 'Vendedor'][$role] ?? 'Resumen';
}
function rbm_estado(?array $a, ?bool $commercial): array {
    if ($commercial === null) return ['—', 'muted'];
    if (!$commercial) return ['Meta pendiente', 'pending'];
    if ($a === null || ($a['desconocidos'] ?? 0) > 0) return ['Días por verificar', 'pending'];
    if (($a['activos'] ?? 0) < 20) return ['Menos de 20 días', 'bad'];
    return ['Cumple previsión', 'good'];
}
function rbm_avail_html(?array $a): string {
    if ($a === null) return '<span class="rbm-muted">Sin dato</span>';
    $label = (int)$a['activos'] . ' / 30';
    $style = ($a['desconocidos'] ?? 0) > 0 ? 'pending' : ((int)$a['activos'] >= 20 ? 'good' : 'bad');
    $tip = 'Ausencias: ' . (int)$a['ausencias'] . '; antes del alta: ' . (int)$a['prealta'] . '; sin presencia HC: ' . (int)$a['sin_hc'] . '; sin foto: ' . (int)$a['desconocidos'];
    if (!empty($a['baja'])) $tip .= '; baja estimada: ' . $a['baja'];
    return '<span class="rbm-chip ' . rbm_h($style) . '" title="' . rbm_h($tip) . '">' . rbm_h($label) . '</span>';
}
function rbm_state_html(?array $a, ?bool $commercial): string {
    [$label, $class] = rbm_estado($a, $commercial);
    return '<span class="rbm-chip ' . rbm_h($class) . '">' . rbm_h($label) . '</span>';
}
function rbm_progress(int $v, int $goal): string {
    $p = rbm_pct($v, $goal);
    return '<div class="rbm-progress"><span style="width:' . rbm_h((string)max(0, min(100, $p ?? 0))) . '%"></span></div>';
}
function rbm_calendar_cells(): array {
    $cells = [];
    foreach ([26,27,28,29,30,31] as $d) $cells[] = ['type' => 'other', 'label' => $d, 'day' => null];
    for ($d=1; $d<=30; $d++) $cells[] = ['type' => 'current', 'label' => $d, 'day' => $d];
    while (count($cells) < 42) { $n = count($cells) - 35; $cells[] = ['type' => 'other', 'label' => $n, 'day' => null]; }
    return $cells;
}

$error = null; $model = null;
try {
    if (!isset($conexion) || !($conexion instanceof mysqli)) throw new RuntimeException('Conexión mysqli no disponible');
    if (!class_exists('RankingAtribucionServiceV06') || RankingAtribucionServiceV06::VERSION_PRODUCTIVIDAD !== '0.6.9-hc-productividad-jornadas') throw new RuntimeException('Se requiere Motor HC v0.6.9');
    $from = rbm_param('desde');
    $to = rbm_param('hasta');
    $model = rbm_build($conexion, new RankingAtribucionServiceV06($conexion), $from !== null ? (int)$from : null, $to !== null ? (int)$to : null);
} catch (Throwable $e) {
    error_log('[Reto Buen Mes] ' . $e->getMessage());
    $error = 'No fue posible cargar el Reto Buen Mes. Verifica la conexión, las tablas y la versión del Motor HC. Consulta el registro de errores PHP para conocer el detalle.';
    http_response_code(500);
}

$nodes = $model['nodes'] ?? [];
$selected = 'SUR';
if ($model) {
    $p = rbm_param('n');
    $d = rbm_param('distrito');
    if ($p !== null && isset($nodes[$p])) $selected = $p;
    elseif ($d !== null && isset(RBM_METAS[$d])) $selected = rbm_district_node($d);
}
$current = $nodes[$selected] ?? null;
$path = $current ? rbm_path($nodes, $selected) : [];
$district = $current['district'] ?? '';
$districtNode = $district !== '' ? ($nodes[rbm_district_node($district)] ?? null) : null;
$districtGoal = $district !== '' ? (RBM_METAS[$district]['meta'] * RBM_ESCALA) : 0;
$regionalGoal = array_sum(array_column(RBM_METAS, 'meta')) * RBM_ESCALA;
$districtOK = $districtNode !== null && $districtGoal > 0 && $districtNode['renta'] >= $districtGoal;
$role = $current['role'] ?? 'R';
$leaderGoal = $current && $role === 'L' ? ($current['monthly_goal'] ?? 0) : 0;
$rangeDays = $model ? $model['to'] - $model['from'] + 1 : 0;
$showGoal = $current ? ($role === 'R' ? $regionalGoal : ($role === 'D' ? $districtGoal : ($role === 'L' ? $leaderGoal : ($role === 'C' ? RBM_PISO * RBM_ESCALA : 0)))) : 0;
$goalActual = $role === 'C' ? ($current['coach_total'] ?? $current['renta'] ?? 0) : ($current['renta'] ?? 0);
$rangeGoal = rbm_range_goal($showGoal, $rangeDays);
$detail = rbm_param('detalle') === '1';
$audit = rbm_param('auditoria') === '1';
$page = max(1, (int)(rbm_param('pagina') ?? 1));
$search = trim((string)(rbm_param('buscar') ?? ''));
$detailSort = rbm_param('orden') ?? 'fecha';
$detailDir = rbm_param('dir') === 'desc' ? 'desc' : 'asc';
$displayName = $current['name'] ?? 'REGIÓN SUR';
$rangeLabel = sprintf('%s – %s', rbm_format_date($model['start'] ?? RBM_INICIO), rbm_format_date($model['end'] ?? RBM_FIN));
$defaultLabel = sprintf('01/11/2026 – %s', rbm_format_date(sprintf('2026-11-%02d', $model['defaultEnd'] ?? 1)));
$calendarCells = rbm_calendar_cells();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reto Buen Mes — TALIA</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=162">
<link rel="stylesheet" href="../assets/css/reto_buen_mes.css?v=1.2.1">
</head>
<body class="page-ranking page-reto-buen-mes">
<?php $current_page='reto_buen_mes'; include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main"><div class="rbm-root">
<?php if ($error): ?>
    <div class="rbm-alert" role="alert"><?= rbm_h($error) ?></div>
<?php else: ?>
<header class="rbm-hero rbm-panel">
    <div class="rbm-hero-info">
        <div><h1>Reto Buen Mes</h1><p class="rbm-subtitle">Región SUR · rentas instaladas sin impuestos · noviembre 2026</p></div>
    </div>
    <div class="rbm-filter-layout">
    <div class="rbm-hero-lines">
        <div class="rbm-line-box"><span>INICIO DEL RETO</span><strong>01/11/2026</strong></div>
        <div class="rbm-line-box"><span>CORTE OPERATIVO</span><strong><?= rbm_h(rbm_format_date($model['last'] ?? '')) ?></strong></div>
        <a class="rbm-outline-btn" target="_blank" rel="noopener" href="<?= rbm_h(rbm_href(['ver_comunicado'=>1])) ?>">Ver comunicado</a>
    </div>
    <form method="get" class="rbm-toolbar" data-default-end="<?= (int)$model['defaultEnd'] ?>" action="reto_buen_mes.php">
        <?php if ($selected !== 'SUR'): ?><input type="hidden" name="n" value="<?= rbm_h($selected) ?>"><input type="hidden" name="distrito" value="<?= rbm_h($district) ?>"><?php endif; ?>
        <input type="hidden" id="rbm-from-input" name="desde" value="<?= (int)$model['from'] ?>">
        <input type="hidden" id="rbm-to-input" name="hasta" value="<?= (int)$model['to'] ?>">
        <div class="rbm-toolbar-group rbm-range-group">
            <span class="rbm-toolbar-label">RANGO DE FECHAS</span>
            <button type="button" class="rbm-range-trigger" id="rbm-range-trigger" aria-haspopup="dialog" aria-expanded="false">
                <span class="rbm-range-icon" aria-hidden="true">🗓️</span>
                <span id="rbm-range-label"><?= rbm_h($rangeLabel) ?></span>
                <span class="rbm-caret" aria-hidden="true">▾</span>
            </button>
            <div class="rbm-range-popover" id="rbm-range-popover" hidden>
                <div class="rbm-cal-head">
                    <button type="button" class="rbm-cal-nav" disabled aria-label="Mes anterior">‹</button>
                    <strong>noviembre de 2026</strong>
                    <button type="button" class="rbm-cal-nav" disabled aria-label="Mes siguiente">›</button>
                </div>
                <div class="rbm-cal-weekdays"><span>LUN</span><span>MAR</span><span>MIÉ</span><span>JUE</span><span>VIE</span><span>SÁB</span><span>DOM</span></div>
                <div class="rbm-cal-grid" id="rbm-cal-grid">
                    <?php foreach ($calendarCells as $cell): ?>
                        <?php if ($cell['type'] === 'other'): ?>
                            <button type="button" class="rbm-cal-day is-other" disabled><?= (int)$cell['label'] ?></button>
                        <?php else: ?>
                            <button type="button" class="rbm-cal-day" data-day="<?= (int)$cell['day'] ?>"><?= (int)$cell['label'] ?></button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <p class="rbm-muted">Selecciona fecha inicial y final; vuelve a tocar para iniciar otro rango.</p>
                <div class="rbm-cal-actions">
                    <button type="button" class="rbm-outline-btn small" id="rbm-cal-reset">Restablecer</button>
                    <button type="button" class="rbm-btn small" id="rbm-cal-apply">Aplicar</button>
                </div>
            </div>
        </div>
        <button class="rbm-btn" type="submit">Aplicar filtros</button>
        <a class="rbm-outline-btn" href="<?= rbm_h(rbm_href($selected === 'SUR' ? [] : ['n' => $selected])) ?>">Restablecer</a>
    </form>
    </div>
    <nav class="rbm-districts" aria-label="Filtrar distrito">
        <a class="<?= $district === '' ? 'selected' : '' ?>" href="<?= rbm_h(rbm_dates_url($model)) ?>">Todos</a>
        <?php foreach (RBM_METAS as $d => $meta): ?>
            <a class="<?= $d === $district ? 'selected' : '' ?>" href="<?= rbm_h(rbm_dates_url($model, ['n' => rbm_district_node($d)])) ?>"><?= rbm_h($meta['nombre']) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="rbm-topnote">Última instalación coincidente: <strong><?= rbm_h(rbm_format_date($model['last'] ?? '')) ?></strong> · Meta mensual completa y meta del rango proporcional a <?= $rangeDays ?> / 30 días naturales.</p>
</header>

<nav class="rbm-crumb" aria-label="Ruta de navegación">
    <?php foreach ($path as $i => $p): ?>
        <?php if ($i): ?><span>/</span><?php endif; ?>
        <a href="<?= rbm_h(rbm_dates_url($model, ['n' => $p['id']])) ?>" <?= $p['id'] === $selected ? 'aria-current="page"' : '' ?>><?= rbm_h($p['name']) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($model['last'] === null): ?>
    <p class="rbm-notice">No hay cuentas del reto con instalación en noviembre. Se muestran las metas sin avance.</p>
<?php elseif (($model['end'] ?? RBM_FIN) > ($model['last'] ?? '')): ?>
    <p class="rbm-notice">El rango incluye fechas posteriores a la última instalación coincidente. Esos días no tienen avance cargado.</p>
<?php endif; ?>

<div class="rbm-heading">
    <div>
        <h2><?= rbm_h($displayName) ?></h2>
        <p><?= rbm_h($rangeLabel) ?> · <?= rbm_h(rbm_role_label($role)) ?></p>
    </div>
    <a class="rbm-link" href="<?= rbm_h(rbm_dates_url($model, ['n' => $selected, 'detalle' => 1])) ?>">Consultar cuentas (<?= number_format($current['count']) ?>)</a>
</div>

<section class="rbm-kpis">
    <div class="rbm-kpi">
        <span>Rentas instaladas</span>
        <strong><?= rbm_money($current['renta']) ?></strong>
        <small>Suma de rentaSinImpuestos</small>
    </div>
    <div class="rbm-kpi">
        <span>Instalaciones válidas</span>
        <strong><?= number_format($current['count']) ?></strong>
        <small>Cuentas únicas del reto</small>
    </div>
    <?php if ($showGoal > 0): ?>
        <div class="rbm-kpi">
            <span><?php
                echo $role === 'C' ? 'Meta mensual del coach' : ($role === 'L' ? 'Meta mensual del líder' : 'Meta mensual del reto');
            ?></span>
            <strong><?= rbm_money($showGoal) ?></strong>
            <small><?php
                echo $role === 'L' ? 'Sumatoria de metas de sus coaches' : ($role === 'C' ? 'Piso mensual individual' : 'Meta mensual aplicable');
            ?></small>
        </div>
        <div class="rbm-kpi">
            <span>Alcance de meta mensual</span>
            <strong class="<?= $goalActual >= $showGoal ? 'rbm-green' : 'rbm-violet' ?>"><?= rbm_num_pct(rbm_pct($goalActual, $showGoal)) ?></strong>
            <?= rbm_progress($goalActual, $showGoal) ?>
            <small>Faltan <?= rbm_money(max(0, $showGoal - $goalActual)) ?></small>
            <small>Meta rango: <?= rbm_money($rangeGoal) ?> · Alcance: <?= rbm_num_pct(rbm_pct($goalActual,$rangeGoal)) ?></small>
        </div>
    <?php else: ?>
        <div class="rbm-kpi">
            <span>Aportación distrito</span>
            <strong><?= rbm_num_pct(rbm_pct($current['renta'], $districtNode['renta'] ?? 0)) ?></strong>
            <small>Respecto a las rentas del distrito</small>
        </div>
        <div class="rbm-kpi">
            <span>Cumplimiento del distrito</span>
            <strong><?= rbm_num_pct(rbm_pct($districtNode['renta'] ?? 0, $districtGoal)) ?></strong>
            <small><?= $districtGoal > 0 ? rbm_money($districtGoal) : 'Sin meta directa' ?></small>
        </div>
    <?php endif; ?>
</section>

<?php if ($role === 'C'): ?>
    <p class="rbm-notice">Piso individual: <?= rbm_money($goalActual) ?> / <?= rbm_money(RBM_PISO * RBM_ESCALA) ?>. Cumplimiento distrital: <?= rbm_num_pct(rbm_pct($districtNode['renta'] ?? 0, $districtGoal)) ?>. Si cambió de líder, el piso suma sus rentas de todo el distrito; el aporte de cada equipo conserva su atribución histórica.</p>
<?php elseif ($role === 'L' && $leaderGoal > 0): ?>
    <p class="rbm-notice">La meta del líder se calcula como la sumatoria de metas de sus coaches: <?= rbm_money($leaderGoal) ?>.</p>
<?php endif; ?>

<?php if (!$detail && !$audit): ?>
<section class="rbm-panel">
    <div class="rbm-person-head"><h3><?= ['R'=>'Cumplimiento por distrito','D'=>'Aportación por líder','L'=>'Aportación por coach','C'=>'Aportación por vendedor','V'=>'Instalaciones del vendedor'][$role] ?></h3><span class="rbm-muted"><?= in_array($role,['R','D','L'],true)?'Medallas por alcance del rango · ':'' ?>Ordena haciendo clic en los encabezados</span></div>
    <?php if ($current['children']):
        $hasGoal=in_array($role,['R','D','L'],true);
        $nameLabel=['R'=>'Distrito / Director Distrital','D'=>'Líder','L'=>'Coach','C'=>'Vendedor'][$role]??'Nombre';
        $headers=[['nombre',$nameLabel,'text'],['inst','Inst.','num'],['renta','Rentas instaladas','num']];
        if($hasGoal) $headers=array_merge($headers,[['meta','Meta mensual','num'],['alcance','Alcance de meta mensual','num'],['meta_rango','Meta del rango','num'],['alcance_rango','Alcance del rango','num'],['pendiente','Pendiente mensual','num']]);
        if($role!=='R')$headers[]=['aporte','Aportación distrito','num'];
        $headers[]=['dias','Días activos','num'];
        if($hasGoal)$headers[]=['premio','Condición premio','num'];
        $rows=[];$sumGoals=0;$sumEligibleRents=0;
        foreach(array_keys($current['children']) as $id){
            $n=$nodes[$id];$dNode=$nodes[rbm_district_node($n['district'])];
            $dGoal=RBM_METAS[$n['district']]['meta']*RBM_ESCALA;$dOk=$dNode['renta'] >= $dGoal;
            $a=rbm_avail($model,$n['person']);
            $monthly=$role==='R'?$dGoal:($role==='D'?($n['monthly_goal']??0):($role==='L'&&$n['person']!==null?RBM_PISO*RBM_ESCALA:0));
            $actual=$role==='L'?($n['coach_total']??$n['renta']):$n['renta'];
            $range=rbm_range_goal($monthly,$rangeDays);
            $commercial=$role==='R'?$dOk:($role==='D'?($n['person']!==null&&$monthly>0?$actual>=$monthly:null):($role==='L'&&$n['person']!==null?$dOk&&$actual>=$monthly:null));
            $persons=$role==='R'?array_map(static fn($dir)=>rbm_avail($model,$dir['person']),array_values($n['directors'])):[$a];
            $days=array_values(array_filter(array_map(static fn($av)=>$av['activos']??null,$persons),static fn($x)=>$x!==null));
            $states=array_map(static fn($av)=>rbm_estado($av,$commercial)[1],$persons?:[null]);
            $stateRanks=['muted'=>0,'bad'=>1,'pending'=>2,'good'=>3];
            $stateRank=min(array_map(static fn($st)=>$stateRanks[$st]??0,$states));
            $rows[]=['id'=>$id,'n'=>$n,'monthly'=>$monthly,'range'=>$range,'actual'=>$actual,'commercial'=>$commercial,'a'=>$a,'dNode'=>$dNode,'days'=>$days?min($days):null,'stateRank'=>$stateRank,'alcance'=>rbm_pct($actual,$monthly),'alcance_rango'=>rbm_pct($actual,$range)];
            if($monthly>0){$sumGoals=rbm_suma($sumGoals,$monthly);$sumEligibleRents=rbm_suma($sumEligibleRents,$n['renta']);}
        }
        $medallas=$hasGoal?rbm_medallas_rango($rows):[];
        // Orden inicial por alcance mensual; sin meta definida, las filas se colocan al final.
        usort($rows,static function($a,$b)use($hasGoal){
            if($hasGoal){$av=$a['alcance'];$bv=$b['alcance'];if($av===null&&$bv!==null)return 1;if($bv===null&&$av!==null)return -1;if($av!==null&&$bv!==null&&$av!==$bv)return $bv<=>$av;}
            return $b['n']['renta']<=>$a['n']['renta']?:strcmp($a['n']['name'],$b['n']['name']);
        });
    ?>
    <div class="rbm-table-wrap">
        <table class="rbm-table rbm-table-summary" data-default-sort="<?= $hasGoal?'alcance':'aporte' ?>" data-default-dir="desc">
            <thead><tr><?php foreach($headers as [$key,$label,$type]): ?><th scope="col" data-key="<?=rbm_h($key)?>" data-type="<?=rbm_h($type)?>" aria-sort="<?= $key===($hasGoal?'alcance':'aporte')?'descending':'none' ?>"><button type="button" class="rbm-sort-btn"><?=rbm_h($label)?> <span class="rbm-sort-icon" aria-hidden="true"><?= $key===($hasGoal?'alcance':'aporte')?'↓':'↕' ?></span></button></th><?php endforeach;?></tr></thead>
            <tbody>
            <?php foreach($rows as $row):
                $id=$row['id'];$n=$row['n'];$monthly=$row['monthly'];$range=$row['range'];$actual=$row['actual'];$a=$row['a'];$commercial=$row['commercial'];
                $aporte=rbm_pct($n['renta'],$row['dNode']['renta']);
            ?>
            <tr>
                <td data-label="<?=rbm_h($nameLabel)?>" data-sort="<?=rbm_h($n['name'])?>"><div class="rbm-name-cell"><?php if(isset($medallas[$id])): $rank=$medallas[$id];?><span class="rbm-medal" role="img" aria-label="<?=$rank?> lugar por alcance del rango" title="<?=$rank?> lugar por alcance del rango"><?= ['🥇','🥈','🥉'][$rank-1] ?></span><?php elseif($hasGoal):?><span class="rbm-medal rbm-medal-empty" aria-hidden="true"></span><?php endif;?><div class="rbm-name-content"><a class="rbm-entity" href="<?=rbm_h(rbm_dates_url($model,['n'=>$id]))?>"><?=rbm_h($n['name'])?> <span aria-hidden="true">›</span></a><?php if($n['role']==='D'):foreach($n['directors'] as $dir):?><small class="rbm-block rbm-muted"><?=rbm_h($dir['name'])?></small><?php endforeach;elseif(!empty($n['position'])):?><small class="rbm-block rbm-muted">Posición <?=rbm_h($n['position'])?></small><?php endif;?></div></div></td>
                <td data-label="Inst." data-sort="<?=$n['count']?>"><?=number_format($n['count'])?></td>
                <td data-label="Rentas instaladas" data-sort="<?=$n['renta']?>" class="rbm-amount"><?=rbm_money($n['renta'])?></td>
                <?php if($hasGoal):?>
                <td data-label="Meta mensual" data-sort="<?=$monthly>0?$monthly:''?>"><?=$monthly>0?rbm_money($monthly):'—'?></td>
                <td data-label="Alcance de meta mensual" data-sort="<?=rbm_h($row['alcance']??'')?>"><?=rbm_num_pct($row['alcance'])?><?php if($monthly>0):?><?=rbm_progress($actual,$monthly)?><?php if($actual!==$n['renta']):?><small class="rbm-block rbm-muted">Total coach <?=rbm_money($actual)?></small><?php endif;endif;?></td>
                <td data-label="Meta del rango" data-sort="<?=$range>0?$range:''?>"><?=$range>0?rbm_money($range):'—'?></td>
                <td data-label="Alcance del rango" data-sort="<?=rbm_h($row['alcance_rango']??'')?>"><?=rbm_num_pct($row['alcance_rango'])?><?php if($range>0):?><?=rbm_progress($actual,$range)?><?php endif;?></td>
                <td data-label="Pendiente mensual" data-sort="<?=$monthly>0?max(0,$monthly-$actual):''?>"><?=$monthly>0?rbm_money(max(0,$monthly-$actual)):'—'?></td>
                <?php endif;?>
                <?php if($role!=='R'):?><td data-label="Aportación distrito" data-sort="<?=rbm_h($aporte??'')?>"><?=rbm_num_pct($aporte)?></td><?php endif;?>
                <td data-label="Días activos" data-sort="<?=rbm_h($row['days']??'')?>"><?php if($role==='R'):if(!$n['directors']):?>—<?php else:foreach($n['directors'] as $dir):?><div title="<?=rbm_h($dir['name'])?>"><?=rbm_avail_html(rbm_avail($model,$dir['person']))?></div><?php endforeach;endif;else:?><?=rbm_avail_html($a)?><?php endif;?></td>
                <?php if($hasGoal):?><td data-label="Condición premio" data-sort="<?=$row['stateRank']?>"><?php if($role==='R'):if(!$n['directors']):?><?=rbm_state_html(null,$commercial)?><?php else:foreach($n['directors'] as $dir):?><div title="<?=rbm_h($dir['name'])?>"><?=rbm_state_html(rbm_avail($model,$dir['person']),$commercial)?></div><?php endforeach;endif;else:?><?=rbm_state_html($a,$commercial)?><?php endif;?></td><?php endif;?>
            </tr>
            <?php endforeach;?>
            </tbody>
            <tfoot><tr><th scope="row">TOTAL</th><td data-label="Inst."><?=number_format($current['count'])?></td><td data-label="Rentas instaladas"><?=rbm_money($current['renta'])?></td>
            <?php if($hasGoal):
                $totalGoal=$role==='R'?$regionalGoal:($role==='L'?$leaderGoal:$sumGoals);
                // Para líderes del distrito, el alcance del total usa sólo rentas asignadas a líderes con meta.
                $totalActual=$role==='D'?$sumEligibleRents:$current['renta'];$totalRange=rbm_range_goal($totalGoal,$rangeDays);
            ?>
            <td data-label="Meta mensual"><?=$totalGoal>0?rbm_money($totalGoal):'—'?></td><td data-label="Alcance de meta mensual"><?=rbm_num_pct(rbm_pct($totalActual,$totalGoal))?></td><td data-label="Meta del rango"><?=$totalRange>0?rbm_money($totalRange):'—'?></td><td data-label="Alcance del rango"><?=rbm_num_pct(rbm_pct($totalActual,$totalRange))?></td><td data-label="Pendiente mensual"><?=$totalGoal>0?rbm_money(max(0,$totalGoal-$totalActual)):'—'?></td>
            <?php endif;?>
            <?php if($role!=='R'):?><td data-label="Aportación distrito"><?=rbm_num_pct(rbm_pct($current['renta'],$districtNode['renta']??0))?></td><?php endif;?><td data-label="Días activos">—</td><?php if($hasGoal):?><td data-label="Condición premio">—</td><?php endif;?></tr></tfoot>
        </table>
    </div>
    <?php else: ?><p class="rbm-muted">No hay colaboradores de nivel inferior identificados para este rango. Consulta las cuentas para ver el detalle.</p><a class="rbm-link" href="<?=rbm_h(rbm_dates_url($model,['n'=>$selected,'detalle'=>1]))?>">Ver detalle de instalaciones</a><?php endif; ?>
    <p class="rbm-muted rbm-footnote">Meta del rango = meta mensual × <?= $rangeDays ?> / 30 días naturales. El premio se evalúa contra la meta mensual. El total distrital conserva las cuentas sin jerarquía certificada.<?php if($role==='D'):?> El alcance total de líderes considera sólo sus rentas asignadas; la meta distrital oficial se muestra arriba.<?php elseif($role==='L'):?> La fila total compara las rentas del líder con su meta mensual; el piso individual de un coach usa sus rentas de todo el distrito.<?php endif;?> Los días activos son una previsión mensual, no asistencia certificada.</p>
</section>

<section class="rbm-panel">
    <h3>Rentas por día · rango seleccionado</h3>
    <?php
        $daily = [];
        foreach (rbm_dias($model['start'], $model['end']) as $d) $daily[$d] = ['renta' => 0, 'count' => 0];
        foreach ($current['accounts'] as $a) {
            if (!isset($daily[$a['fecha']])) continue;
            $daily[$a['fecha']]['renta'] = rbm_suma($daily[$a['fecha']]['renta'], $a['renta']);
            $daily[$a['fecha']]['count']++;
        }
        $max = 1;
        foreach ($daily as $v) $max = max($max, abs($v['renta']));
    ?>
    <div class="rbm-chart-scroll"><div class="rbm-chart" role="img" aria-label="Rentas por día"><?php foreach ($daily as $d => $v): ?><div class="rbm-bar-cell" title="<?= rbm_h($d . ' · ' . rbm_money($v['renta']) . ' · ' . $v['count'] . ' instalaciones') ?>"><small><?= rbm_money($v['renta']) ?></small><div class="rbm-bar-track"><div class="rbm-bar <?= $v['renta'] < 0 ? 'negative' : '' ?>" style="height:<?= rbm_h((string)max(0, min(100, 100 * abs($v['renta']) / $max))) ?>%"></div></div><span><?= (int)substr($d, 8) ?></span></div><?php endforeach; ?></div></div>
    <p class="rbm-muted">Día de noviembre · importes de renta sin impuestos</p>
</section>
<?php endif; ?>

<?php if ($detail || $audit || $role === 'V'): ?>
    <?php
        $isAudit = $audit;
        $records = $isAudit ? $model['audit'] : $current['accounts'];
        if ($search !== '') $records = array_values(array_filter($records, static fn($a) => stripos($a['cuenta'], $search) !== false || stripos((string)($a['motivo'] ?? ''), $search) !== false));
        $validSort=$isAudit?['cuenta','fecha','motivo','detalle']:['cuenta','fecha','distrito','renta','precio','canal','jerarquia'];
        if(!in_array($detailSort,$validSort,true))$detailSort='fecha';
        $getSortValue=static function(array $a,string $key){
            if($key==='distrito')return RBM_METAS[$a['distrito']]['nombre']??$a['distrito']??'';
            if($key==='canal')return ($a['canal']??'').' / '.($a['subcanal']??'');
            if($key==='jerarquia')return $a['estado']??'';
            return $a[$key]??null;
        };
        usort($records,static function($a,$b)use($getSortValue,$detailSort,$detailDir){
            $av=$getSortValue($a,$detailSort);$bv=$getSortValue($b,$detailSort);
            if($av===null||$bv===null){if($av!==$bv)return $av===null?1:-1;$cmp=0;}
            elseif(in_array($detailSort,['renta','precio'],true))$cmp=$av<=>$bv;
            else $cmp=strnatcasecmp((string)$av,(string)$bv);
            return ($detailDir==='desc'?-$cmp:$cmp)?:strcmp((string)$a['cuenta'],(string)$b['cuenta']);
        });
        $total = count($records); $pages = max(1, (int)ceil($total / 100)); $page = min($page, $pages); $records = array_slice($records, ($page - 1) * 100, 100);
        $args = ['n' => $selected, $isAudit ? 'auditoria' : 'detalle' => 1,'orden'=>$detailSort,'dir'=>$detailDir];
    ?>
    <section class="rbm-panel">
        <div class="rbm-person-head"><h3><?= $isAudit ? 'Auditoría del reto · todo noviembre' : 'Detalle de cuentas · rango seleccionado' ?></h3><a class="rbm-link" href="<?= rbm_h(rbm_dates_url($model, ['n' => $selected])) ?>">Volver al resumen</a></div>
        <form method="get" class="rbm-search">
            <input type="hidden" name="desde" value="<?= (int)$model['from'] ?>"><input type="hidden" name="hasta" value="<?= (int)$model['to'] ?>"><input type="hidden" name="n" value="<?= rbm_h($selected) ?>"><input type="hidden" name="<?= $isAudit ? 'auditoria' : 'detalle' ?>" value="1">
            <input type="hidden" name="orden" value="<?=rbm_h($detailSort)?>"><input type="hidden" name="dir" value="<?=$detailDir?>"><label for="buscar">Buscar cuenta<?= $isAudit ? ' o motivo' : '' ?></label><input id="buscar" name="buscar" value="<?= rbm_h($search) ?>"><button class="rbm-btn" type="submit">Buscar</button>
        </form>
        <div class="rbm-table-wrap">
            <table class="rbm-table rbm-table-detail" data-default-sort="<?=rbm_h($detailSort)?>" data-default-dir="<?=$detailDir?>" data-server-sort="1">
                <thead><tr><?php $detailHeaders=[['cuenta','Cuenta','text'],['fecha','Fecha','text']];$detailHeaders=array_merge($detailHeaders,$isAudit?[['motivo','Motivo','text'],['detalle','Detalle','text']]:[['distrito','Distrito','text'],['renta','Renta sin impuestos','num'],['precio','Precio BRM','num'],['canal','Canal','text'],['jerarquia','Jerarquía','text']]);foreach($detailHeaders as [$key,$label,$type]):?><th scope="col" data-key="<?=rbm_h($key)?>" data-type="<?=$type?>" aria-sort="<?=$key===$detailSort?($detailDir==='asc'?'ascending':'descending'):'none'?>"><button type="button" class="rbm-sort-btn"><?=rbm_h($label)?> <span class="rbm-sort-icon" aria-hidden="true"><?=$key===$detailSort?($detailDir==='asc'?'↑':'↓'):'↕'?></span></button></th><?php endforeach;?></tr></thead>
                <tbody>
                    <?php foreach ($records as $a): ?>
                        <tr>
                            <td data-label="Cuenta"><?= rbm_h($a['cuenta']) ?></td>
                            <td data-label="Fecha"><?= rbm_h($a['fecha'] ?? '—') ?></td>
                            <?php if ($isAudit): ?>
                                <td data-label="Motivo"><?= rbm_h($a['motivo']) ?></td>
                                <td data-label="Detalle"><?= rbm_h($a['detalle']) ?></td>
                            <?php else: ?>
                                <td data-label="Distrito"><?= rbm_h(RBM_METAS[$a['distrito']]['nombre']) ?></td>
                                <td data-label="Renta sin impuestos" data-sort="<?=$a['renta']?>" title="<?= rbm_h(rbm_exact($a['renta'])) ?>"><?= rbm_money($a['renta']) ?></td>
                                <td data-label="Precio BRM" data-sort="<?=rbm_h($a['precio']??'')?>"><?= $a['precio'] !== null ? rbm_money($a['precio']) : '—' ?></td>
                                <td data-label="Canal"><?= rbm_h($a['canal'] . ' / ' . $a['subcanal']) ?></td>
                                <td data-label="Jerarquía"><?= rbm_h($a['estado']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$records): ?><tr><td colspan="<?= $isAudit ? 4 : 7 ?>">Sin registros.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="rbm-pagination"><span><?= $total ?> registros · página <?= $page ?> de <?= $pages ?></span><?php if ($page > 1): ?><a href="<?= rbm_h(rbm_dates_url($model, $args + ['pagina' => $page - 1, 'buscar' => $search])) ?>">Anterior</a><?php endif; ?><?php if ($page < $pages): ?><a href="<?= rbm_h(rbm_dates_url($model, $args + ['pagina' => $page + 1, 'buscar' => $search])) ?>">Siguiente</a><?php endif; ?></div>
    </section>
<?php endif; ?>

<footer class="rbm-footer">Reto Buen Mes v1.2.1 · Noviembre 2026 · Consulta sin modificaciones a la base de datos</footer>

<script src="../assets/js/reto_buen_mes.js?v=1.2.0" defer></script>
<?php endif; ?>
</div></main>
</body>
</html>
