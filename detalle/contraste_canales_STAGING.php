<?php
/**
 * TalIA · CONTRASTE MOTOR HC + COMERCIAL · STAGING · 13 OCT 2026.
 * Lectura 100%, NO ALTERA BD. No modifica archivos de producción.
 * Compara motor_hc.php (real) con motor_hc_STAGING.php (todos orígenes)
 * y valida la clasificación de RAFAEL con motores comerciales equivalentes.
 * Ruta: /plataforma/detalle/contraste_canales_STAGING.php
 * Solo admin / director_regional. S40 y S41 de 2026.
 */
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
session_start();
$role = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (empty($_SESSION['usuario']) || !in_array($role,['admin','director_regional'],true)) {
    http_response_code(403);
    exit('No autorizado. Inicia sesión como administrador o director regional.');
}
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/../includes/motor_hc.php';
require_once __DIR__.'/../includes/motor_hc_STAGING.php';
require_once __DIR__.'/../includes/motor_comercial.php';
require_once __DIR__.'/../includes/motor_comercial_STAGING.php';

function sx($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function nf($v,int $digits=0): string { return number_format((float)$v,$digits,'.',','); }
function delta(int $v): string { return $v > 0 ? '+'.$v : (string)$v; }
function origen(?string $s): string {
    $o=trim((string)$s);
    return $o===''?'(VACÍO)':$o;
}
function byAccount(array $arr): array {
    $o=[];
    foreach($arr as $r) $o[(string)$r['cuenta']]=$r;
    return $o;
}
function groupId(array $r): string {
    return (string)($r['lider_id']??'').'|'.(string)($r['coach_id']??'');
}
function summarize(array $cert): array {
    $district=[];$leaders=[];$coaches=[];$coachLabels=[];$origins=[];
    foreach ($cert as $r) {
        $lid=(string)($r['lider_id']??'');
        $pos=(string)($r['coach_id']??'');
        $d=explode('|',$lid,2)[0];
        $district[$d]=($district[$d]??0)+1;
        $leaders[$lid]=($leaders[$lid]??0)+1;
        $id=$lid.'|'.$pos;
        $coaches[$id]=($coaches[$id]??0)+1;
        $coachLabels[$id]=(string)($r['coach_evento']??'PLAZA '.$pos);
        $o=origen($r['origen_prospecto']??'');
        $origins[$o]=($origins[$o]??0)+1;
    }
    return compact('district','leaders','coaches','coachLabels','origins');
}
function protectCsv($v): string {
    $s=(string)$v;
    return preg_match('/^\s*[=+@\-\t\r]/u',$s) ? "'".$s : $s;
}

$semana=(int)($_GET['semana']??41);
if (!in_array($semana,[40,41],true)) $semana=41;
$ini=(new DateTimeImmutable())->setISODate(2026,$semana,1);
$fin=$ini->modify('+6 days');
$desde=$ini->format('Y-m-d');$hasta=$fin->format('Y-m-d');
$elapsed=[];$error='';$old=[];$candidate=[];$oldNames=[];$newNames=[];
$casos=[
    '143879818'=>['coach'=>'IRRA','motivo'=>'HC ausente: folio 1171285'],
    '143880795'=>['coach'=>'IRRA','motivo'=>'HC ausente: folio 1171285'],
    '143887511'=>['coach'=>'IRRA','motivo'=>'HC ausente: folio 1171285'],
    '143887708'=>['coach'=>'IRRA','motivo'=>'HC ausente: folio 1171285'],
    '143852338'=>['coach'=>'RAFAEL','motivo'=>'Desarrollos / folio 17005210'],
    '143854383'=>['coach'=>'RAFAEL','motivo'=>'Desarrollos / folio 17005210'],
    '143865451'=>['coach'=>'RAFAEL','motivo'=>'Desarrollos / folio 17005210'],
    '143868242'=>['coach'=>'RAFAEL','motivo'=>'eCommerce / folio 17075856']
];
$baseSummary=[];$newSummary=[];$add=[];$lost=[];$moved=[];$originNew=[];
$comRafaelOld=null;$comRafaelNew=null;$comError='';$catalog=[];
try {
    if (!isset($conexion) || !$conexion instanceof mysqli) throw new RuntimeException('Conexión no disponible');
    $mOld=new RankingAtribucionServiceV06($conexion);
    $mStg=new RankingAtribucionServiceV06Staging($conexion);
    $t=microtime(true);
    $old=$mOld->obtenerInstalacionesJerarquia($desde,$hasta);
    $elapsed['produccion']=round(microtime(true)-$t,2);
    $t=microtime(true);
    $candidate=$mStg->obtenerInstalacionesJerarquia($desde,$hasta);
    $elapsed['staging']=round(microtime(true)-$t,2);
    if (($old['version']??'')!=='0.6.2-hc-position-staging') throw new RuntimeException('Motor de producción cambió de versión: '.($old['version']??'-'));
    if (($candidate['version']??'')!=='0.6.3-hc-all-channels-staging') throw new RuntimeException('Motor de STAGING incompatible');
    $catalog=$mStg->catalogoLideres($desde,$hasta);
    $byOld=byAccount($old['cuentas']); $byNew=byAccount($candidate['cuentas']);
    if (count($byOld)!==count($old['cuentas']) || count($byNew)!==count($candidate['cuentas'])) {
        throw new LogicException('Un motor entregó cuentas certificadas duplicadas');
    }
    $allOld=byAccount(array_merge($old['cuentas'],$old['auditoria_pendientes']));
    $allNew=byAccount(array_merge($candidate['cuentas'],$candidate['auditoria_pendientes']));
    $baseSummary=summarize($old['cuentas']);
    $newSummary=summarize($candidate['cuentas']);
    foreach($byNew as $cuenta=>$r) {
        if (!isset($byOld[$cuenta])) {
            $add[$cuenta]=$r;
            $o=origen($r['origen_prospecto']??'');
            $originNew[$o]=($originNew[$o]??0)+1;
        } elseif (groupId($byOld[$cuenta])!==groupId($r) || (string)$byOld[$cuenta]['vendedor_id']!==(string)$r['vendedor_id']) {
            $moved[$cuenta]=['antes'=>$byOld[$cuenta],'despues'=>$r];
        }
    }
    foreach($byOld as $cuenta=>$r) if (!isset($byNew[$cuenta])) $lost[$cuenta]=$r;
    if (count($candidate['cuentas'])!==count($old['cuentas'])+count($add)-count($lost)) {
        throw new LogicException('La variación no concilia cuenta por cuenta');
    }
    arsort($originNew);
    $filterRafael=static fn(array $r)=>
        ($r['coach_id']??null)==='10005653' && str_starts_with((string)($r['lider_id']??''),'CANCUN|');
    $rafaelOld=array_values(array_filter($old['cuentas'],$filterRafael));
    $rafaelNew=array_values(array_filter($candidate['cuentas'],$filterRafael));
    try {
        $cOld=new MotorComercial($conexion);
        $cStg=new MotorComercialStaging($conexion);
        $oldStats=$cOld->resumirVendedores($rafaelOld,$desde,$hasta);
        $newStats=$cStg->resumirVendedores($rafaelNew,$desde,$hasta);
        $aggregate=function(array $groups):array {
            $a=['total'=>0,'doble_play'=>0,'triple_play'=>0,'residencial'=>0,'negocios'=>0,
                'bundle'=>0,'no_bundle'=>0,'arpu_cuentas'=>0,'arpu_ingreso_neto'=>0.,
                'instalacion_no_encontrada'=>0,'catalogo_sin_match'=>0];
            foreach($groups as $g) foreach($a as $k=>$_) $a[$k]+=$g[$k]??0;
            $a['arpu']=$a['arpu_cuentas']>0?round($a['arpu_ingreso_neto']/$a['arpu_cuentas'],2):null;
            return $a;
        };
        $comRafaelOld=$aggregate($oldStats);
        $comRafaelNew=$aggregate($newStats);
        if ($comRafaelOld['total']!==count($rafaelOld) || $comRafaelNew['total']!==count($rafaelNew)) {
            throw new LogicException('Motor Comercial no concilia con las cuentas HC de RAFAEL');
        }
        if ($comRafaelNew['instalacion_no_encontrada']>0) {
            throw new LogicException('Motor Comercial STAGING no encontró ficha de alguna cuenta certificada de RAFAEL');
        }
    } catch (Throwable $exc) { $comError=$exc->getMessage(); }
} catch (Throwable $exc) {
    $error=$exc->getMessage();
}
$blocking=($lost!==[] || $moved!==[] || $comError!=='');
$differences=[];
foreach($add as $c=>$r) $differences[]=['tipo'=>'CUENTA NUEVA CERTIFICADA','cuenta'=>$c,'antes'=>$allOld[$c]??null,'despues'=>$r];
foreach($lost as $c=>$r) $differences[]=['tipo'=>'PÉRDIDA DE CERTIFICACIÓN','cuenta'=>$c,'antes'=>$r,'despues'=>$allNew[$c]??null];
foreach($moved as $c=>$pair) $differences[]=['tipo'=>'REASIGNACIÓN HC','cuenta'=>$c,'antes'=>$pair['antes'],'despues'=>$pair['despues']];
if (!$error) {
    if (($_GET['csv']??'')==='1') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contraste_STAGING_S'.$semana.'_2026.csv"');
        $fp=fopen('php://output','w');
        fwrite($fp,"\xEF\xBB\xBF");
        fputcsv($fp,['tipo','cuenta','fecha','origen','folio','estado_anterior','lider_anterior','coach_anterior','estado_staging','lider_staging','coach_staging']);
        foreach($differences as $entry) {
            $a=$entry['antes']??[];$b=$entry['despues']??[];
            $r=$b ?: $a;
            $fields=[
                $entry['tipo'],$entry['cuenta'],$r['fecha']??'',$r['origen_prospecto']??'',
                $r['vendedor_folio_original']??'',
                $a['estado']??'NO ELEGIBLE',$a['lider_id']??'',$a['coach_id']??'',
                $b['estado']??'NO ELEGIBLE',$b['lider_id']??'',$b['coach_id']??''
            ];
            fputcsv($fp,array_map('protectCsv',$fields));
        }
        fclose($fp);
        exit;
    }
}
$districts=['CANCUN','COATZA-MINA','MERIDA','TUXTLA','VILLAHERMOSA'];
$coaches=array_unique(array_merge(array_keys($baseSummary['coaches']??[]),array_keys($newSummary['coaches']??[])));
$leaders=array_unique(array_merge(array_keys($baseSummary['leaders']??[]),array_keys($newSummary['leaders']??[])));
$labels=$newSummary['coachLabels']??[];
foreach(($baseSummary['coachLabels']??[]) as $k=>$v) if(!isset($labels[$k]))$labels[$k]=$v;
$coachRows=[];
foreach ($coaches as $key) {
    $a=(int)($baseSummary['coaches'][$key]??0);
    $b=(int)($newSummary['coaches'][$key]??0);
    $coachRows[]=['key'=>$key,'a'=>$a,'b'=>$b,'delta'=>$b-$a,'label'=>$labels[$key]??$key];
}
usort($coachRows,static fn($x,$y)=>($y['delta']<=>$x['delta'])?:strcmp($x['key'],$y['key']));
$leaderRows=[];
foreach ($leaders as $key) {
    $a=(int)($baseSummary['leaders'][$key]??0);
    $b=(int)($newSummary['leaders'][$key]??0);
    $leaderRows[]=['key'=>$key,'a'=>$a,'b'=>$b,'delta'=>$b-$a,'label'=>$catalog[$key]['nombre']??$key];
}
usort($leaderRows,static fn($x,$y)=>($y['delta']<=>$x['delta'])?:strcmp($x['key'],$y['key']));
$oldCount=count($old['cuentas']??[]);$newCount=count($candidate['cuentas']??[]);
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>STAGING · Contraste de canales · S<?= $semana ?></title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f3f6fa;color:#152238}*{box-sizing:border-box}
body{margin:0}.wrap{max-width:1520px;margin:0 auto;padding:28px 20px 60px}.top{background:#14243c;color:white;padding:20px 26px}
.top strong{font-size:21px}.top small{color:#b4c8df;display:block;margin-top:3px}.nav{display:flex;flex-wrap:wrap;gap:9px;margin:20px 0}
.btn{display:inline-block;background:#fff;border:1px solid #ccd6e1;border-radius:9px;padding:10px 14px;color:#17385b;text-decoration:none;font-size:13px;font-weight:700}.btn.active{background:#174b83;color:#fff;border-color:#174b83}
.card{background:white;border-radius:12px;box-shadow:0 2px 12px #2036580d;padding:18px 21px;margin:14px 0}
.card h2{margin:2px 0 12px;font-size:18px}.muted{color:#60738b}.fine{font-size:12px}.warning{border-left:5px solid #b87512}.danger{border-left:5px solid #bb3636}.good{border-left:5px solid #25816d}
.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.kpi{background:white;padding:19px;border-radius:11px;box-shadow:0 2px 12px #2036580d}.kpi label{display:block;color:#667993;font-size:12px;text-transform:uppercase}.kpi b{font-size:32px;display:block;margin:6px 0}
@media(max-width:850px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:450px){.kpis{grid-template-columns:1fr}}
.scroll{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px 11px;text-align:left;border-bottom:1px solid #e6edf4;white-space:nowrap}th{background:#eaf0f7;color:#324960;position:sticky;top:0}tr:nth-child(even) td{background:#fafcff}.n{text-align:right;font-variant-numeric:tabular-nums}.plus{font-weight:800;color:#16805b}.minus{font-weight:800;color:#bf3b3b}.zero{color:#7c8b9c}.tag{border-radius:5px;font-size:11px;font-weight:700;padding:3px 6px;background:#dbece8;color:#166950}
.mini{font-size:12px;line-height:1.5;color:#54677c}code{font-family:ui-monospace,Consolas,monospace;background:#eaf0f5;padding:2px 4px;border-radius:4px}.flex{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
</style></head><body>
<div class="top"><strong>🧪 TalIA · Contraste de motores STAGING</strong><small>S<?= $semana ?> · <?= sx($desde) ?> al <?= sx($hasta) ?> · Producción vs TODOS los orígenes · SIN escrituras en BD</small></div>
<div class="wrap"><nav class="nav">
<a class="btn <?= $semana===41?'active':'' ?>" href="?semana=41">Semana 41</a>
<a class="btn <?= $semana===40?'active':'' ?>" href="?semana=40">Semana 40</a>
<a class="btn" href="ranking_productividad_STAGING.php?periodo=semanal&anio=2026&semana=<?= $semana ?>">Ranking semanal STAGING</a>
<a class="btn" href="ranking_productividad_STAGING.php?periodo=mensual&anio_mes=2026&mes=10&rango_mode=completo">Ranking mensual STAGING</a>
<?php if(!$error): ?><a class="btn" href="?semana=<?= $semana ?>&csv=1">Exportar diferencias CSV</a><?php endif; ?>
</nav>
<?php if($error): ?><div class="card danger"><h2>El contraste no pudo completarse</h2><p><?= sx($error) ?></p><p>Comprueba que los cuatro archivos de los motores existen en <code>/plataforma/includes/</code> y que estás usando los motores productivos compatibles.</p></div>
<?php else: ?>
<div class="kpis">
<div class="kpi"><label>Certificadas producción</label><b><?= nf($oldCount) ?></b><span class="mini">Cambaceo y PDV</span></div>
<div class="kpi"><label>Certificadas STAGING</label><b><?= nf($newCount) ?></b><span class="mini">Todos los orígenes, HC certificado</span></div>
<div class="kpi"><label>Variación neta</label><b class="<?= $newCount-$oldCount>=0?'plus':'minus' ?>"><?= sx(delta($newCount-$oldCount)) ?></b><span class="mini">Cuentas únicas, Región SUR</span></div>
<div class="kpi"><label>Cuentas que necesitan revisión</label><b class="<?= $blocking?'minus':'plus' ?>"><?= nf(count($lost)+count($moved)) ?></b><span class="mini"><?= nf(count($lost)) ?> pérdidas · <?= nf(count($moved)) ?> reasignaciones</span></div>
</div>
<div class="card <?= $blocking?'danger':'good' ?>"><h2><?= $blocking?'⚠️ Detectadas variaciones que requieren investigación':'✓ Sin pérdidas ni reasignaciones de atribuciones previas' ?></h2>
<p>La comparación es <strong>cuenta por cuenta</strong>, no una diferencia artificial entre totales. Certificadas nuevas: <strong><?= nf(count($add)) ?></strong>. Pérdidas: <strong><?= nf(count($lost)) ?></strong>. Cambios de líder, coach o vendedor en cuentas previamente certificadas: <strong><?= nf(count($moved)) ?></strong>.</p>
<?php if($comError): ?><p style="color:#b43838"><strong>Contraste comercial de RAFAEL pendiente:</strong> <?= sx($comError) ?></p><?php endif; ?>
<p class="mini">Este contraste no autoriza despliegue productivo. Si hay pérdidas/reasignaciones o errores comerciales, investigar antes de cualquier cambio.</p></div>
<?php if($semana===41): ?>
<div class="card"><h2>Control Salesforce · Cancún · Semana 41</h2>
<p class="mini">Este bloque recupera dinámicamente los conteos por ID de posición de Coach. No presupone que RAFAEL subirá cuatro cuentas; lo verifica contra el Motor HC.</p>
<div class="scroll"><table><thead><tr><th>Coach HC</th><th class="n">Salesforce</th><th class="n">Producción</th><th class="n">STAGING</th><th class="n">SF − STAGING</th></tr></thead><tbody>
<?php foreach([['nombre'=>'RAFAEL','id'=>'10005653','sf'=>30],['nombre'=>'IRRA','id'=>'5523502','sf'=>32]] as $probe):
 $a=0;$b=0;
 foreach(($baseSummary['coaches']??[]) as $key=>$val)if(str_starts_with($key,'CANCUN|')&&str_ends_with($key,'|'.$probe['id']))$a+=$val;
 foreach(($newSummary['coaches']??[]) as $key=>$val)if(str_starts_with($key,'CANCUN|')&&str_ends_with($key,'|'.$probe['id']))$b+=$val;
 $remaining=$probe['sf']-$b;
 ?><tr><td><strong><?= sx($probe['nombre']) ?></strong> · <code><?= sx($probe['id']) ?></code></td><td class="n"><?= nf($probe['sf']) ?></td><td class="n"><?= nf($a) ?></td><td class="n"><strong><?= nf($b) ?></strong></td><td class="n <?= $remaining===0?'plus':'minus' ?>"><?= sx(delta($remaining)) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p class="mini">La referencia SF (RAFAEL 30 / IRRA 32) procede del diagnóstico previo; verificar siempre los mismos días y filtros.</p></div>
<?php endif; ?>
<div class="card"><div class="flex"><h2>1 · Totales por distrito</h2><span class="muted fine">S<?= $semana ?> · Instalaciones certificadas</span></div>
<div class="scroll"><table><thead><tr><th>Distrito</th><th class="n">Producción</th><th class="n">STAGING</th><th class="n">Variación</th></tr></thead><tbody>
<?php foreach($districts as $dist):$a=(int)($baseSummary['district'][$dist]??0);$b=(int)($newSummary['district'][$dist]??0);$d=$b-$a; ?><tr><td><strong><?= sx($dist) ?></strong></td><td class="n"><?= nf($a) ?></td><td class="n"><?= nf($b) ?></td><td class="n <?= $d>0?'plus':($d<0?'minus':'zero') ?>"><?= sx(delta($d)) ?></td></tr><?php endforeach; ?>
<tr><td><strong>REGIÓN SUR</strong></td><td class="n"><strong><?= nf($oldCount) ?></strong></td><td class="n"><strong><?= nf($newCount) ?></strong></td><td class="n <?= $newCount-$oldCount>=0?'plus':'minus' ?>"><?= sx(delta($newCount-$oldCount)) ?></td></tr></tbody></table></div></div>
<div class="card"><h2>2 · Vista previa del Ranking por líder</h2><div class="scroll"><table><thead><tr><th>Distrito | Plaza líder</th><th>Líder (foto HC)</th><th class="n">Producción</th><th class="n">STAGING</th><th class="n">Δ</th></tr></thead><tbody>
<?php foreach($leaderRows as $r): ?><tr><td><code><?= sx($r['key']) ?></code></td><td><?= sx($r['label']) ?></td><td class="n"><?= nf($r['a']) ?></td><td class="n"><strong><?= nf($r['b']) ?></strong></td><td class="n <?= $r['delta']>0?'plus':($r['delta']<0?'minus':'zero') ?>"><?= sx(delta($r['delta'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>3 · Vista previa del Ranking por coach</h2><p class="mini">Ordenado por variación descendente para identificar primero los impactos de la ampliación. Las plazas mantienen su identidad única por distrito y líder.</p>
<div class="scroll"><table><thead><tr><th>Plaza líder / Coach</th><th>Coach HC</th><th class="n">Producción</th><th class="n">STAGING</th><th class="n">Δ</th></tr></thead><tbody>
<?php foreach($coachRows as $r): ?><tr><td><code><?= sx($r['key']) ?></code></td><td><?= sx($r['label']) ?></td><td class="n"><?= nf($r['a']) ?></td><td class="n"><strong><?= nf($r['b']) ?></strong></td><td class="n <?= $r['delta']>0?'plus':($r['delta']<0?'minus':'zero') ?>"><?= sx(delta($r['delta'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>4 · Las 8 cuentas diagnosticadas en Cancún</h2><p class="mini">En S40 estas cuentas no corresponden al intervalo. En S41 esperamos RAFAEL +4 e IRRA sin cambio, hasta resolver su HC con Capital Humano.</p>
<div class="scroll"><table><thead><tr><th>Coach SF</th><th>Cuenta</th><th>Origen del diagnóstico</th><th>Estado producción</th><th>Estado STAGING</th><th>Coach HC STAGING</th></tr></thead><tbody>
<?php foreach($casos as $c=>$e): $a=$allOld[$c]??null;$b=$allNew[$c]??null; ?><tr><td><strong><?= sx($e['coach']) ?></strong></td><td><code><?= sx($c) ?></code></td><td><?= sx($e['motivo']) ?></td><td><?= sx($a ? (($a['fuente']??'')==='HC_FOTO'?'CERTIFICADA':($a['estado']??'PENDIENTE')):'NO ELEGIBLE / FUERA DE FECHA') ?></td><td class="<?= $b&&($b['fuente']??'')==='HC_FOTO'?'plus':'minus' ?>"><?= sx($b ? (($b['fuente']??'')==='HC_FOTO'?'CERTIFICADA':($b['estado']??'PENDIENTE')):'NO ENCONTRADA') ?></td><td><?= sx($b['coach_id']??'—') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>5 · Canales de las nuevas cuentas certificadas</h2><div class="scroll"><table><thead><tr><th>Origen</th><th class="n">Cuentas nuevas</th></tr></thead><tbody><?php foreach($originNew as $canal=>$cantidad): ?><tr><td><?= sx($canal) ?></td><td class="n plus">+<?= nf($cantidad) ?></td></tr><?php endforeach; ?><?php if(!$originNew):?><tr><td colspan="2">Sin adiciones certificadas</td></tr><?php endif; ?></tbody></table></div>
<p class="mini">Las cuentas pendientes de certificación HC no se incorporan por el simple hecho de ampliar los orígenes.</p></div>
<div class="card"><h2>6 · Control Motor Comercial: Coach RAFAEL (10005653)</h2>
<?php if($comError): ?><p class="minus">No se pudo certificar el comparativo comercial: <?= sx($comError) ?></p>
<?php elseif($comRafaelOld!==null && $comRafaelNew!==null): ?><div class="scroll"><table><thead><tr><th>Indicador</th><th class="n">Producción</th><th class="n">STAGING</th></tr></thead><tbody>
<?php foreach(['total'=>'Instalaciones HC','doble_play'=>'2P','triple_play'=>'3P','residencial'=>'Residencial','negocios'=>'Negocios','bundle'=>'Bundle','no_bundle'=>'No Bundle','arpu_cuentas'=>'Cuentas con ARPU','instalacion_no_encontrada'=>'Cuentas sin ficha comercial','catalogo_sin_match'=>'Sin match catálogo'] as $key=>$label):?><tr><td><?= sx($label) ?></td><td class="n"><?= nf($comRafaelOld[$key]??0) ?></td><td class="n"><?= nf($comRafaelNew[$key]??0) ?></td></tr><?php endforeach; ?>
<tr><td><strong>ARPU neto sin IVA</strong></td><td class="n"><?= $comRafaelOld['arpu']===null?'—':'$'.nf($comRafaelOld['arpu'],2) ?></td><td class="n"><?= $comRafaelNew['arpu']===null?'—':'$'.nf($comRafaelNew['arpu'],2) ?></td></tr></tbody></table></div><?php endif; ?></div>
<div class="card"><h2>7 · Auditoría y tiempos de ejecución</h2><p>Producción: <strong><?= sx($elapsed['produccion']??'-') ?> s</strong> · STAGING: <strong><?= sx($elapsed['staging']??'-') ?> s</strong>.</p>
<p>Universo original: <?= nf($old['conciliacion_comercial']['total_cambaceo_pdv']??0) ?> cuentas Cambaceo/PDV; universo candidato: <?= nf($candidate['conciliacion_comercial']['total_todos_origenes']??0) ?> cuentas de todos los orígenes. Pendientes HC original: <?= nf(count($old['auditoria_pendientes'])) ?>; pendientes STAGING: <?= nf(count($candidate['auditoria_pendientes'])) ?>.</p>
<p class="mini">Los dos motores usan los mismos datos de instalaciones, fotografías de HC, HIC, catálogo de líderes y fechas. Ambos leen BD sin escribir. Descarga el CSV para inspeccionar cada variación antes de autorización productiva.</p></div>
<?php endif; ?>
</div></body></html>
