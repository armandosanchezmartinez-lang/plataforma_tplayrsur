<?php
/**
 * TalIA / TotalXpedient — Promociones / Buen Plan v1.2.0
 * Ruta propuesta: /plataforma/detalle/buenplan.php
 * Solo lectura. NO modifica sidebar, BD ni motores.
 * Identifica 250 MB desde catálogo, Play/Tipo desde catálogo y precio PP desde instalaciones.
 * Tres estados: Buen Plan confirmado / candidato por validar / 250 MB base.
 * Sin cambios a Motor HC, Motor Comercial, catálogo ni sidebar.
 * Requiere PHP 8+, motor_hc.php y motor_comercial.php de producción.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store, no-cache, must-revalidate');
session_start();
if (empty($_SESSION['usuario'])) { http_response_code(403); exit('Sesión TalIA no disponible.'); }
require_once __DIR__.'/../conexion.php';
$roles = ['admin','director_regional','director_distrital','lider','coach','vendedor'];
$rol = strtolower(trim((string)($_SESSION['rol'] ?? '')));
if (!in_array($rol,$roles,true)) { http_response_code(403); exit('Perfil no autorizado.'); }
function bp_h($v): string {return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8');}
function bp_date(string $v): ?DateTimeImmutable { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); return $d && $d->format('Y-m-d')===$v ? $d : null; }
function bp_fmt($v,int $n=0): string {return $v===null?'ND':number_format((float)$v,$n,'.',',');}
function bp_pct($n,$d): ?float{return $d>0?100.0*$n/$d:null;}
function bp_delta($v,$b): ?float{return $b>0?100.0*($v-$b)/$b:null;}
function bp_n($v): string {return strtoupper(trim(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',(string)$v) ?: (string)$v));}
function bp_norm_district($v): string {$v=bp_n($v);return str_replace(['Á','É','Í','Ó','Ú'],'',$v);}
function bp_fields(mysqli $db,string $table): array { $r=$db->query('SHOW COLUMNS FROM `'.$table.'`'); if (!$r) throw new RuntimeException('No se puede leer '.$table);$out=[];while($x=$r->fetch_assoc())$out[$x['Field']]=true;return $out; }
function bp_week(DateTimeImmutable $d): string {return 'S'.$d->format('W').' · '.$d->format('o');}
function bp_month(DateTimeImmutable $d): string {return ['','ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC'][(int)$d->format('n')].' '.$d->format('Y');}
/** Nombres comerciales proporcionados por operación: no hacer LIKE %NETFLIX% genérico. */
function bp_promo_play(string $plan): ?string {
    $key=preg_replace('/\s+/', ' ', bp_n($plan));
    if ($key==='TURBO NETFLIX STD CON ANUNCIOS TV') return '3P';
    if ($key==='TURBO NETFLIX STD CON ANUNCIOS') return '2P';
    return null;
}
function bp_prefer_group(string $day,string $phase):bool {return bp_phase($day)===$phase;}
function bp_rate_diff(?float $now,?float $pre):?float {return $pre!==null && $pre>0 && $now!==null ? 100*($now/$pre-1):null;}
function bp_add(array &$a,array $e): void {
    $a['total']++; $play=$e['play']; $vel=$e['speed'];
    if ($play==='2P')$a['p2']++; elseif($play==='3P')$a['p3']++; else $a['sin_play']++;
    if ($vel===250) { $a['v250']++; if ($play==='2P')$a['p2_250']++;elseif ($play==='3P')$a['p3_250']++; }
    if ($vel!==null && in_array($play,['2P','3P'],true)) $a['speeds'][$play][$vel]=($a['speeds'][$play][$vel]??0)+1;
    if ($e['pp']!==null) {$a['income']+=$e['pp'];$a['income_n']++;}
    if ($vel===250 && $e['pp']!==null) {$a['income250']+=$e['pp'];$a['income250_n']++;$a['pp250']+=$e['pp'];$a['pp250_n']++;}
    if ($e['promo']) { $a['promo']++; $a['promo_'.$play]++; }
    if ($e['candidate']) { $a['candidate']++; if ($play==='2P'||$play==='3P')$a['candidate_'.$play]++; }
    if ($e['price_signal'])$a['price_signal']++;
    if ($e['price_conflict'])$a['price_conflict']++;
    if (!$e['classified'])$a['incomplete']++;
}
function bp_blank(): array {return ['total'=>0,'p2'=>0,'p3'=>0,'sin_play'=>0,'v250'=>0,'p2_250'=>0,'p3_250'=>0,'income'=>0.0,'income_n'=>0,'income250'=>0.0,'income250_n'=>0,'pp250'=>0.0,'pp250_n'=>0,'incomplete'=>0,'promo'=>0,'promo_2P'=>0,'promo_3P'=>0,'candidate'=>0,'candidate_2P'=>0,'candidate_3P'=>0,'price_signal'=>0,'price_conflict'=>0,'speeds'=>['2P'=>[],'3P'=>[]]];}
function bp_sum(array $rows): array {$out=bp_blank();foreach($rows as $a){foreach(['total','p2','p3','sin_play','v250','p2_250','p3_250','income','income_n','income250','income250_n','pp250','pp250_n','incomplete','promo','promo_2P','promo_3P','candidate','candidate_2P','candidate_3P','price_signal','price_conflict'] as $k)$out[$k]+=$a[$k];foreach(['2P','3P'] as $p)foreach($a['speeds'][$p] as $s=>$n)$out['speeds'][$p][$s]=($out['speeds'][$p][$s]??0)+$n;}return $out;}
function bp_group(DateTimeImmutable $d,string $mode):string {return $mode==='mensual'?$d->format('Y-m'):($mode==='semanal'?$d->format('o-\WW'):$d->format('Y-m-d'));}
function bp_lab(string $key,string $mode):string {if($mode==='diario')return substr($key,8,2).'/'.substr($key,5,2);if($mode==='mensual')return bp_month(new DateTimeImmutable($key.'-01'));return 'S'.substr($key,-2);}
function bp_is_promo(string $day):bool{return $day>='2026-11-03' && $day<='2026-11-09';}
function bp_phase(string $day):string {return $day<'2026-11-03'?'PRE':($day<='2026-11-09'?'PROMO':'POST');}
$promoStart=new DateTimeImmutable('2026-11-03');$promoEnd=new DateTimeImmutable('2026-11-09');
$mode=(string)($_GET['periodo']??'semanal');if(!in_array($mode,['semanal','mensual','diario'],true))$mode='semanal';
$district=(string)($_GET['distrito']??'SUR');if(!in_array($district,['SUR','TUXTLA','VILLAHERMOSA'],true))$district='SUR';
$playSel=(string)($_GET['play']??'AMBOS');if(!in_array($playSel,['AMBOS','2P','3P'],true))$playSel='AMBOS';
$preWeeks=max(1,min(12,(int)($_GET['pre_semanas']??4)));
$preMonths=max(1,min(6,(int)($_GET['pre_meses']??1)));
$cutArg=(string)($_GET['corte']??'');
$error='';$warn=[];$events=[];$lastDay=null;$confirmed=0;$unmatched=0;$duplicate=0;$noCatalog=0;$flagPromoAvailable=false;
$comercialVersion='';$hcVersion='';$countsByPeriod=[];$reco='';
try {
    if (!($conexion instanceof mysqli))throw new RuntimeException('Conexión mysqli no disponible');
    $latest=$conexion->query("SELECT UPPER(TRIM(distrito)) AS distrito, MAX(fecha) AS fecha FROM instalaciones WHERE fecha IS NOT NULL AND fecha <= CURDATE() AND UPPER(TRIM(distrito)) IN ('TUXTLA','VILLAHERMOSA') GROUP BY UPPER(TRIM(distrito))");
    $districtCuts=[];while($latest && ($r=$latest->fetch_assoc()))$districtCuts[$r['distrito']]=$r['fecha'];
    $dbMax=(isset($districtCuts['TUXTLA'],$districtCuts['VILLAHERMOSA'])) ? min($districtCuts['TUXTLA'],$districtCuts['VILLAHERMOSA']) : null;
    if(!$dbMax)$warn[]='Falta carga en uno de los dos distritos participantes; se suspende el comparativo hasta tener corte común.';
    if (!$dbMax || !bp_date($dbMax))throw new RuntimeException('No se encontraron instalaciones con fechas válidas');
    $maxDate=bp_date($dbMax);$today=new DateTimeImmutable('today');if($maxDate>$today)$maxDate=$today;
    $userCut=bp_date($cutArg);$cut=$userCut??$maxDate;
    if($cut>$maxDate){$cut=$maxDate;$warn[]='El corte solicitado excede la última fecha cargada; se ajustó automáticamente.';}
    $lastDay=$cut->format('Y-m-d');
    // La ventana histórica incluye PRE, campaña y POST. Un mes PRE = mes(es) calendario antes de noviembre.
    $preStart=$mode==='mensual' ? (new DateTimeImmutable('2026-11-01'))->modify('-'.$preMonths.' months') : (new DateTimeImmutable('2026-11-03'))->modify('-'.($preWeeks*7).' days');
    $start=$preStart;
    if($mode==='diario')$start=(new DateTimeImmutable('2026-11-03'))->modify('-'.($preWeeks*7).' days');
    if($start>$cut)$start=$cut;
    // No cerrar el intervalo en 09-nov: el POST se habilita al existir cortes reales posteriores.
    require_once __DIR__.'/../includes/motor_hc.php';
    require_once __DIR__.'/../includes/motor_comercial.php';
    $motor=new RankingAtribucionServiceV06($conexion);
    $catalogo=$motor->catalogoLideres($start->format('Y-m-d'),$lastDay);
    $hc=$motor->obtenerInstalacionesJerarquia($start->format('Y-m-d'),$lastDay,null,null,null,[]);
    $hcVersion=(string)($hc['version']??'SIN VERSION');
    if(!isset($hc['cuentas']) || !is_array($hc['cuentas']))throw new RuntimeException('Motor HC no entregó cuentas certificadas');
    // Motor comercial se invoca con el MISMO universo de cuentas para contrastar totales;
    // nunca se reconstruye ni se altera la atribución jerárquica del Motor HC.
    $mc=new MotorComercial($conexion);$comercialVersion=(string)MotorComercial::VERSION;
    $summaries=$mc->resumirVendedores($hc['cuentas'],$start->format('Y-m-d'),$lastDay,[]);
    $totalComm=0;foreach($summaries as $s)$totalComm+=(int)($s['total']??0);
    if($totalComm!==count($hc['cuentas']))throw new RuntimeException('No concilian Motor HC ('.count($hc['cuentas']).') y Comercial ('.$totalComm.'). Sin resultados hasta resolver.');
    $instCols=bp_fields($conexion,'instalaciones');$catCols=bp_fields($conexion,'catalogo_paquetes');
    foreach(['cuenta','fecha','plan'] as $f)if(!isset($instCols[$f]))throw new RuntimeException('Falta instalaciones.'.$f);
    foreach(['nombre_plan','velocidad_agrupada','play','tipo'] as $f)if(!isset($catCols[$f]))throw new RuntimeException('Falta catalogo_paquetes.'.$f);
    // La regla canónica de ARPU es precio_pronto_pago directo y vigente al evento.
    // Nunca usar precio de lista / 1.16 ni reemplazar un precio histórico faltante
    // por uno del catálogo para CONFIRMAR la promoción.
    if(!isset($instCols['precio_pronto_pago']))$warn[]='Sin precio PP en instalaciones: no se pueden confirmar contratos de Buen Plan.';
    // Selección segura: IDs certificados y distrito derivado del catálogo histórico de líderes.
    $wanted=[];$unmappedRegion=0;
    foreach($hc['cuentas'] as $e){
        $account=trim((string)($e['cuenta']??''));if($account==='')continue;
        $lid=(string)($e['lider_id']??'');
        $dist=bp_norm_district($catalogo[$lid]['distrito']??($e['distrito']??''));
        if(!in_array($dist,['TUXTLA','VILLAHERMOSA'],true)){if($dist==='')$unmappedRegion++;continue;}
        // Retener ambos distritos para comparativa permanente; el filtro visual opera al agregar.
        $wanted[$account]=['dist'=>$dist,'lider'=>$lid];
    }
    if($unmappedRegion)$warn[]='Hay '.number_format($unmappedRegion).' cuenta(s) sin distrito comprobable; se excluyen de la comparación.';
    // Una consulta por bloques para evitar IN masivos. Si hay varias filas para una cuenta,
    // se marca ambigüedad y se evita adjudicarle un paquete arbitrario.
    $records=[];$keys=array_keys($wanted);
    foreach(array_chunk($keys,350) as $batch){
        $quoted=array_map(static fn($a)=>"'".mysqli_real_escape_string($conexion,$a)."'",$batch);
        $sql='SELECT i.cuenta, i.fecha, i.plan, i.precio_pronto_pago AS precio_pp, i.precio_lista, cp.velocidad_agrupada, cp.play, cp.tipo, cp.nombre_plan AS match_catalogo FROM instalaciones i LEFT JOIN catalogo_paquetes cp ON UPPER(TRIM(i.plan))=UPPER(TRIM(cp.nombre_plan)) '.
             "WHERE i.cuenta IN (".implode(',',$quoted).") AND i.fecha BETWEEN '".$conexion->real_escape_string($start->format('Y-m-d'))."' AND '".$conexion->real_escape_string($lastDay)."'";
        $rs=$conexion->query($sql);if(!$rs)throw new RuntimeException('Consulta de clasificación falló: '.$conexion->error);
        while($r=$rs->fetch_assoc())$records[(string)$r['cuenta']][]=$r;
    }
    foreach($wanted as $account=>$meta){
        $rr=$records[$account]??[];if(count($rr)!==1){if(count($rr)>1)$duplicate++;else$unmatched++;continue;}
        $r=$rr[0];$day=substr((string)$r['fecha'],0,10);if(!bp_date($day)){$unmatched++;continue;}
        $promoPlay=bp_promo_play((string)$r['plan']);
        if($r['match_catalogo']===null){$noCatalog++;}
        $tipo=bp_n($r['tipo']??'');
        // Sólo 250 MB residencial con clasificación respaldada por catálogo.
        if($tipo!=='RESIDENCIAL')continue;
        $pl=bp_n($r['play']??'');$pl=str_contains($pl,'DOBLE')?'2P':(str_contains($pl,'TRIPLE')?'3P':'ND');
        if($playSel!=='AMBOS' && $pl!==$playSel)continue;
        $rawVel=bp_n((string)($r['velocidad_agrupada']??''));
        $rawVel=str_replace([',',' '],'',$rawVel);
        $speed=preg_match('/^(\d+)MB$/',$rawVel,$m)?(int)$m[1]:null;
        $pp=(is_numeric($r['precio_pp']??null) && (float)$r['precio_pp']>0)?(float)$r['precio_pp']:null;
        $expectedPP=$pl==='3P'?739.0:($pl==='2P'?600.0:null);
        $priceSignal=$speed===250 && $pp!==null && $expectedPP!==null && abs($pp-$expectedPP)<0.011;
        $correctName=$promoPlay!==null && $promoPlay===$pl;
        $validPeriod=bp_is_promo($day);
        $confirmed=$validPeriod && $speed===250 && $correctName && $priceSignal;
        $nameHasNetflix=str_contains(bp_n($r['plan']??''),'NETFLIX');
        // Un 250 MB Netflix histórico sin precio promocional no prueba Buen Plan.
        $candidate=$validPeriod && $speed===250 && !$confirmed && ($priceSignal || $promoPlay!==null || $nameHasNetflix);
        $priceConflict=$validPeriod && $speed===250 && $correctName && $pp!==null && !$priceSignal;
        $events[]=['day'=>$day,'dist'=>$meta['dist'],'play'=>$pl,'speed'=>$speed,'pp'=>$pp,
           'promo'=>$confirmed,'candidate'=>$candidate,
           'price_signal'=>$validPeriod && $priceSignal && !$confirmed,
           'price_conflict'=>$priceConflict,'classified'=>($pl!=='ND'&&$speed!==null)];
    }
    if($noCatalog)$warn[]='Hay '.number_format($noCatalog).' cuenta(s) sin coincidencia en catalogo_paquetes; no se clasifican como residencial ni como 250 MB.';
    if($duplicate || $unmatched)$warn[]='Conciliación de detalle: '.number_format($duplicate).' cuenta(s) con múltiples registros y '.number_format($unmatched).' sin instalación recuperable. No se atribuyen a ningún paquete.';
    $warn[]='Buen Plan confirmado exige 250 MB residencial + Play del catálogo + nombre promocional exacto + PP histórico ($600/$739) + vigencia. Una señal parcial queda como candidato; no se infiere velocidad por TURBO.';
} catch(Throwable $e){$error=$e->getMessage();}
$series=[];$daily=[];$preDaily=[];$promoDaily=[];$postDaily=[];
$months=[];$weeks=[];$days=[];$pre=bp_blank();$promo=bp_blank();$post=bp_blank();$byDistrict=[];$byDistrictPhase=[];$byPlay=[];$phaseByDay=[];
if(!$error){
    foreach($events as $e){
        if($district!=='SUR' && $district!==$e['dist'])continue;
        $day=$e['day'];if($mode==='mensual' && $day>='2026-11-01' && $day<'2026-11-03')continue; $d=new DateTimeImmutable($day);$g=bp_group($d,$mode);
        $series[$g]??=bp_blank();bp_add($series[$g],$e);
        $daily[$day]??=bp_blank();bp_add($daily[$day],$e);
        $phase=bp_phase($day);if($phase==='PRE')bp_add($pre,$e);elseif($phase==='PROMO')bp_add($promo,$e);else bp_add($post,$e);
        $byDistrict[$e['dist']]??=bp_blank();bp_add($byDistrict[$e['dist']],$e);
        $byDistrictPhase[$e['dist']][$phase]??=bp_blank();bp_add($byDistrictPhase[$e['dist']][$phase],$e);
        $byPlay[$e['play']]??=bp_blank();bp_add($byPlay[$e['play']],$e);
    }
    ksort($series);ksort($daily);
}
// PRE semanal: promedio de 4 semanas; PRE mensual: promedio de meses completos;
// diario: promedio de los días cargados dentro de ventana elegida.
$preUnits=$mode==='mensual'?$preMonths:$preWeeks;
$preReference=bp_blank();foreach($pre as $k=>$v)$preReference[$k]=$v;
$preNorm=$preUnits>0?$pre['v250']/$preUnits:0;
$promoDur=$lastDay?max(0,min(7,($lastDay>='2026-11-03' ? ((int)$promoStart->diff(new DateTimeImmutable($lastDay))->days+1) : 0))):0;
if($lastDay && $lastDay<'2026-11-03')$promoDur=0;
$promoDays=$promoDur;
// Comparador PRE por días ISO equivalentes, evitando campañas parciales vs semanas completas.
$comparablePre=bp_blank();
foreach($events as $ev){
    if(($district!=='SUR' && $district!==$ev['dist']) || bp_phase($ev['day'])!=='PRE')continue;
    if($mode==='mensual')continue;
    $w=(int)(new DateTimeImmutable($ev['day']))->format('N');
    if($promoDays>0 && in_array($w,array_map(static fn($j)=>(($j+1)%7)+1,range(0,$promoDays-1)),true))bp_add($comparablePre,$ev);
}
$base250=$mode==='mensual'?($preUnits ? $pre['v250']/$preUnits*($promoDays/30.0):0):($preUnits?$comparablePre['v250']/$preUnits:0);
$comparison250=$promo['v250'];$targetRate=$base250;
$change250=$promoDays>0?bp_delta($comparison250,$targetRate):null;
$baseTotal=$mode==='mensual'?($preUnits?$pre['total']/$preUnits*($promoDays/30.0):0):($preUnits?$comparablePre['total']/$preUnits:0);
$promoTotal=$promo['total'];
$changeTotal=$promoDays>0?bp_delta($promoTotal,$baseTotal):null;
$sharePre=bp_pct($pre['v250'],$pre['total']);$sharePromo=bp_pct($promo['v250'],$promo['total']);
$shareDiff=($sharePre!==null&&$sharePromo!==null)?$sharePromo-$sharePre:null;
$arpuPre=$pre['income250_n']?$pre['income250']/$pre['income250_n']:null;
$arpuPromo=$promo['income250_n']?$promo['income250']/$promo['income250_n']:null;
$arpudelta=($arpuPre!==null&&$arpuPromo!==null)?bp_delta($arpuPromo,$arpuPre):null;
$targetVal=$mode==='mensual'?($preUnits?$pre['income250']/$preUnits*($promoDays/30.0):0):($preUnits?$comparablePre['income250']/$preUnits:0);
$promoVal=$promo['income250'];
$valueDelta=$promoDays>0&&$targetVal>0?bp_delta($promoVal,$targetVal):null;
$speeds=[];foreach([$pre,$promo] as $a)foreach(['2P','3P'] as $p)foreach($a['speeds'][$p] as $s=>$n)$speeds[(int)$s]=true;ksort($speeds,SORT_NUMERIC);
$chartData=[];
foreach($series as $key=>$a){
    $d=$mode==='semanal'?(new DateTimeImmutable('2026-01-01'))->setISODate((int)substr($key,0,4),(int)substr($key,-2),1):($mode==='mensual'?new DateTimeImmutable($key.'-01'):new DateTimeImmutable($key));
    $dt=$d?:$promoStart;
    $phase=($mode==='mensual' && $key==='2026-11')||($mode==='semanal' && $key==='2026-W45')?'PROMO':bp_phase($dt->format('Y-m-d'));
    $chartData[]=['key'=>$key,'label'=>bp_lab($key,$mode),'total'=>$a['total'],'v250'=>$a['v250'],'p2'=>$a['p2_250'],'p3'=>$a['p3_250'],'phase'=>$phase,'promo'=>$a['promo']];
}
$chartJson=json_encode($chartData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
$barData=[];foreach(array_keys($speeds) as $s){$p2Pre=($pre['speeds']['2P'][$s]??0)/$preUnits;$p3Pre=($pre['speeds']['3P'][$s]??0)/$preUnits;$p2Now=$promo['speeds']['2P'][$s]??0;$p3Now=$promo['speeds']['3P'][$s]??0;$barData[]=['speed'=>$s,'delta'=>($p2Now+$p3Now)-($p2Pre+$p3Pre)];}
$barJson=json_encode($barData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
// Comparación por distritos, siempre muestra ambos incluso con filtro individual.
// El PRE se normaliza por el número de semanas/meses seleccionados.
// Cuando la promoción es parcial, la comparación de volumen se hace contra días
// equivalentes de la misma semana (ej. martes-miércoles) para evitar falsos descensos.
$districtComparison=[];
foreach(['TUXTLA','VILLAHERMOSA'] as $distName){
    $allP=bp_blank();$allB=bp_blank();$refComparable=bp_blank();
    foreach($events as $ev){
        if($ev['dist']!==$distName)continue;
        if(bp_phase($ev['day'])==='PROMO')bp_add($allP,$ev);
        if(bp_phase($ev['day'])==='PRE'){
            bp_add($allB,$ev);
            if($promoDays>0 && $mode!=='mensual'){
                // Intervalo PRE semanal comienza lunes, campaña inicia martes.
                $isoDow=(int)(new DateTimeImmutable($ev['day']))->format('N');
                if(in_array($isoDow,array_map(static fn($j)=>(($j+1)%7)+1,range(0,$promoDays-1)),true))bp_add($refComparable,$ev);
            }
        }
    }
    $reference=$mode==='mensual'?($preUnits ? $allB['v250']/$preUnits * ($promoDays/30.0):0):($preUnits?$refComparable['v250']/$preUnits:0);
    $baseTotalComparable=$mode==='mensual'?($preUnits?$allB['total']/$preUnits*($promoDays/30.0):0):($preUnits?$refComparable['total']/$preUnits:0);
    $districtComparison[$distName]=[
      'pre250'=>$reference,'campaign250'=>$allP['v250'],
      'growth'=>bp_delta($allP['v250'],$reference),
      'pre_total'=>$baseTotalComparable,'campaign_total'=>$allP['total'],
      'pre_mix'=>bp_pct($allB['v250'],$allB['total']),
      'promo_mix'=>bp_pct($allP['v250'],$allP['total']),
      'promo'=>$allP['promo'],'promo2'=>$allP['promo_2P'],'promo3'=>$allP['promo_3P'],'candidate'=>$allP['candidate'],
      'price_signal'=>$allP['price_signal'],'price_conflict'=>$allP['price_conflict'],
      'adoption'=>bp_pct($allP['promo'],$allP['v250'])
    ];
}
$districtTotal=['pre250'=>0.0,'campaign250'=>0,'pre_total'=>0.0,'campaign_total'=>0,'promo'=>0,'promo2'=>0,'promo3'=>0,'candidate'=>0,'price_signal'=>0,'price_conflict'=>0];
foreach($districtComparison as $d){foreach(array_keys($districtTotal) as $key)$districtTotal[$key]+=$d[$key];}
$districtTotal['growth']=bp_delta($districtTotal['campaign250'],$districtTotal['pre250']);
$districtTotal['adoption']=bp_pct($districtTotal['promo'],$districtTotal['campaign250']);
$districtTotal['pre_mix']=bp_pct($pre['v250'],$pre['total']);
$districtTotal['promo_mix']=bp_pct($districtTotal['campaign250'],$districtTotal['campaign_total']);
// Puntualización: fuente por nombre de plan, no verificación de entrega/activación de Netflix.
$promoIdentified=$promo['promo'];$promo2=$promo['promo_2P'];$promo3=$promo['promo_3P'];
$adoption=bp_pct($promoIdentified,$promo['v250']);$promoCandidates=$promo['candidate'];

if($error){$districtComparison=['TUXTLA'=>['promo2'=>0,'promo3'=>0],'VILLAHERMOSA'=>['promo2'=>0,'promo3'=>0]];}
$params=$_GET;function bp_href(array $change):string {return '?'.http_build_query(array_merge($_GET,$change));}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Buen Plan | Promociones · TotalXpedient</title>
<style>
:root{--ink:#0b2354;--blue:#0878f9;--line:#a4d8f4;--purple:#a517e7;--magenta:#ed04ab;--green:#009768;--red:#c81739;--muted:#617391;--bg:#f5f9fc}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:#172b4d;font:13px Arial,Helvetica,sans-serif}.wrap{max-width:1720px;margin:auto;padding:12px 16px 35px}.heading{display:flex;gap:16px;justify-content:space-between;align-items:start;flex-wrap:wrap;background:linear-gradient(105deg,#f7f1ff,#ecfaff);padding:12px 18px;border-radius:6px;border-bottom:1px solid #d0deed}.heading h1{font-weight:800;color:#091d49;font-size:25px;margin:0 0 5px}.heading small{color:#586b89;font-size:12px}.filters{display:flex;gap:7px;flex-wrap:wrap;align-items:end}.toggle{border:0;border-radius:12px;background:white;color:#172340;font-weight:bold;padding:10px 12px;text-decoration:none;font-size:12px;cursor:pointer}.toggle.active{color:white;background:linear-gradient(120deg,#9817e3,#ed05b2)}.form{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin:12px 0}.form label{display:flex;flex-direction:column;gap:4px;color:#38608a;font-size:11px;font-weight:700}.form select,.form input{height:33px;border:1px solid #b8d6ee;border-radius:7px;background:white;padding:4px 8px;color:#152f50;max-width:165px}.submit{background:#0878f9;color:#fff;border:none;border-radius:8px;padding:9px 13px;cursor:pointer;font-weight:bold}.promo-header{background:white;border:1px solid #dde7ef;border-left:6px solid #b118e5;position:relative;padding:11px 18px;margin:8px 0 12px;border-radius:3px}.promo-header h2{margin:0 0 5px;font-size:25px;color:#112a62}.promo-header p{margin:0;font-weight:bold;color:#254776}.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.chip{background:#f1e9ff;border:1px solid #e6d9fa;border-radius:5px;padding:5px 9px;color:#5823a5;font-weight:bold;font-size:11px}.note{padding:11px 14px;border:1px solid #e3ca8a;background:#fff8e4;color:#70531c;border-radius:7px;margin:10px 0}.error{background:#fff1f2;color:#8b1d30;border:1px solid #ecacb8}.section{font-size:16px;color:#0575e6;margin:17px 0 9px;font-weight:800}.cards{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px}.metric{border-radius:7px;border:1px solid #ffb4c5;background:#fff0f4;min-height:94px;display:flex;flex-direction:column;align-items:center;text-align:center;justify-content:center;padding:9px}.metric:nth-child(2),.metric:nth-child(4){background:#e9f7ff;border-color:#92d4ff}.metric .label{font-size:11px;color:#0778ef;font-weight:800}.metric strong{font-size:27px;margin:9px 0 5px;color:#b60026}.metric strong.good{color:#008e5a}.metric small{font-size:11px;color:#1c69cf}.layout{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(330px,.9fr);gap:16px;margin:10px 0}.panel{background:white;border:1px solid var(--line);border-radius:6px;padding:11px 13px;min-width:0;margin-bottom:10px}.panel h3{margin:0 0 8px;color:#0878ef;font-size:15px}.panel .sub{font-size:11px;color:#415f80;margin-bottom:9px}.stats{width:100%;border-collapse:collapse;font-size:12px}.stats th{background:#dceef7;color:#213b57;text-align:left}.stats th,.stats td{border:1px solid #95a8ba;padding:6px 7px}.stats td:not(:first-child),.stats th:not(:first-child){text-align:right}.stats tfoot{font-weight:bold;background:#eff8ff}.negative{color:#d31842;font-weight:bold}.positive{color:#008e62;font-weight:bold}.secondary{color:#687d91}.chart{height:290px;position:relative}.chart svg{width:100%;height:100%;display:block}.badge{display:inline-block;background:#dff7ee;border:1px solid #a9e2c6;border-radius:5px;padding:5px 9px;font-size:11px;color:#167551;font-weight:bold}.foot{color:#7c8ba1;font-size:11px;margin-top:16px;line-height:1.6}.legend{display:flex;align-items:center;gap:13px;font-size:11px;flex-wrap:wrap;margin:5px 0}.dot{width:10px;height:10px;display:inline-block;border-radius:2px;margin-right:4px}.smallcards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.smallcard{padding:12px;border:1px solid var(--line);background:#e9f6ff;border-radius:5px;color:#2b5373;line-height:1.5}.smallcard b{font-size:14px;color:#066fdf}.empty{display:grid;place-items:center;height:240px;color:#69849d}.scroll{overflow:auto}@media(max-width:1050px){.cards{grid-template-columns:repeat(3,1fr)}.layout{grid-template-columns:1fr}}@media(max-width:640px){.cards{grid-template-columns:repeat(2,1fr)}.smallcards{grid-template-columns:1fr}.heading h1,.promo-header h2{font-size:20px}}
</style></head><body><main class="wrap">
<header class="heading"><div><h1>Promociones | Buen Plan · 2026</h1><small>Región SUR · <?=bp_h($district==='SUR'?'Tuxtla + Villahermosa':$district)?> · Corte <?=bp_h($lastDay?:'ND')?> · Perfil <?=bp_h($rol)?> · Fuente: instalaciones certificadas</small></div><nav class="filters"><a class="toggle <?=$mode==='diario'?'active':''?>" href="<?=bp_h(bp_href(['periodo'=>'diario']))?>">Diario</a><a class="toggle <?=$mode==='semanal'?'active':''?>" href="<?=bp_h(bp_href(['periodo'=>'semanal']))?>">Semanal</a><a class="toggle <?=$mode==='mensual'?'active':''?>" href="<?=bp_h(bp_href(['periodo'=>'mensual']))?>">Mensual</a></nav></header>
<form class="form" method="get"><input type="hidden" name="periodo" value="<?=bp_h($mode)?>"><label>Distrito<select name="distrito"><option value="SUR" <?=$district==='SUR'?'selected':''?>>SUR participante</option><option value="TUXTLA" <?=$district==='TUXTLA'?'selected':''?>>Tuxtla</option><option value="VILLAHERMOSA" <?=$district==='VILLAHERMOSA'?'selected':''?>>Villahermosa</option></select></label><label>Play<select name="play"><option value="AMBOS" <?=$playSel==='AMBOS'?'selected':''?>>2P + 3P</option><option value="2P" <?=$playSel==='2P'?'selected':''?>>Solo 2P</option><option value="3P" <?=$playSel==='3P'?'selected':''?>>Solo 3P</option></select></label><?php if($mode==='mensual'):?><label>Meses PRE<input name="pre_meses" type="number" min="1" max="6" value="<?=$preMonths?>"></label><?php else:?><label>Semanas PRE<input name="pre_semanas" type="number" min="1" max="12" value="<?=$preWeeks?>"></label><?php endif;?><label>Fecha de corte<input name="corte" type="date" max="<?=bp_h($lastDay)?>" value="<?=bp_h($lastDay)?>"></label><button class="submit" type="submit">Aplicar filtros</button></form>
<section class="promo-header"><h2>Buen Plan | 250 MB + Netflix incluido</h2><p>PRE configurable · PROMO 03–09 nov 2026 · POST desde 10 nov 2026 · <?=bp_h(ucfirst($mode))?></p><div class="chips"><span class="chip">250 Mbps · Residencial</span><span class="chip">2P: Internet $600 PP nacional</span><span class="chip">3P: TV + Internet $739 PP nacional</span><span class="chip">Netflix Estándar con anuncios</span><span class="chip">Solo clientes nuevos · vigencia 03–09 nov</span></div></section>
<?php if($error):?><div class="note error"><strong>Datos no disponibles:</strong> <?=bp_h($error)?>. No se presentan cifras estimadas.</div><?php endif;foreach($warn as $w):?><div class="note"><?=bp_h($w)?></div><?php endforeach;?>
<?php if(!$error):?>
<h2 class="section">1 · Indicadores generales · Paquetes elegibles 250 Mbps</h2><div class="cards">
<div class="metric"><div class="label">INSTALACIONES 250 MB</div><strong class="<?=($change250??-1)>=0?'good':''?>"><?=$change250===null?'ND':(($change250>=0?'+':'').bp_fmt($change250,1).'%')?></strong><small><?=bp_fmt($comparison250,2)?> vs <?=bp_fmt($targetRate,2)?> PRE <?= $mode==='diario'?'por día':'por período' ?></small></div>
<div class="metric"><div class="label">ARPU PP 250 MB</div><strong class="<?=($arpudelta??-1)>=0?'good':''?>"><?=$arpudelta===null?'ND':(($arpudelta>=0?'+':'').bp_fmt($arpudelta,1).'%')?></strong><small><?=bp_fmt($arpuPromo,2)?> vs <?=bp_fmt($arpuPre,2)?> · pronto pago directo</small></div>
<div class="metric"><div class="label">MIX 250 MB RESIDENCIAL</div><strong class="<?=($shareDiff??-1)>=0?'good':''?>"><?=$shareDiff===null?'ND':(($shareDiff>=0?'+':'').bp_fmt($shareDiff,1).' pp')?></strong><small><?=bp_fmt($sharePromo,1)?>% vs <?=bp_fmt($sharePre,1)?>% PRE</small></div>
<div class="metric"><div class="label">SUMA PP 250 MB</div><strong class="<?=($valueDelta??-1)>=0?'good':''?>"><?=$valueDelta===null?'ND':(($valueDelta>=0?'+':'').bp_fmt($valueDelta,1).'%')?></strong><small>$<?=bp_fmt($promoVal,0)?> vs $<?=bp_fmt($targetVal,0)?> PRE</small></div>
<div class="metric"><div class="label">INSTALACIONES TOTAL RESIDENCIAL</div><strong class="<?=($changeTotal??-1)>=0?'good':''?>"><?=$changeTotal===null?'ND':(($changeTotal>=0?'+':'').bp_fmt($changeTotal,1).'%')?></strong><small><?=bp_fmt($promoTotal,2)?> vs <?=bp_fmt($baseTotal,2)?> PRE</small></div></div>
<div class="note">Lectura: el universo total de 250 Mbps incluye paquetes regulares y Buen Plan; la adopción se identifica por nombre de plan. El ARPU usa <b>precio pronto pago histórico de instalaciones</b>, sin dividir por IVA. La suma PP es un indicador comercial de valor contratado, <b>no ingreso facturado</b>. El periodo PROMO está <?= $promoDays<7?'parcial':'completo' ?> (<?=$promoDays?> de 7 días al corte). Comparaciones semanal/mensual se muestran con esta advertencia cuando el periodo está incompleto.</div>
<h2 class="section">2 · Buen Plan identificado por paquete · 03–09 noviembre</h2>
<div class="cards" style="grid-template-columns:repeat(4,minmax(130px,1fr))">
<div class="metric"><div class="label">BUEN PLAN · CONFIRMADO</div><strong class="good"><?=bp_fmt($promoIdentified)?></strong><small>Nombre + 250 MB + Play + precio PP</small></div>
<div class="metric"><div class="label">BUEN PLAN · INTERNET (2P)</div><strong class="good"><?=bp_fmt($promo2)?></strong><small>Validación simultánea 2P + $600 PP</small></div>
<div class="metric"><div class="label">BUEN PLAN · TV + INTERNET (3P)</div><strong class="good"><?=bp_fmt($promo3)?></strong><small>Validación simultánea 3P + $739 PP</small></div>
<div class="metric"><div class="label">PENETRACIÓN EN INSTALACIONES 250 MB</div><strong class="good"><?=bp_fmt($adoption,1)?><?= $adoption===null?'':'%' ?></strong><small>Buen Plan identificado / instalaciones residenciales 250 Mbps</small></div>
</div>
<div class="note">Confirmado: <b>nombre exacto + velocidad 250 MB + Play residencial por catálogo + precio PP observado $600/$739 + vigencia</b>. El nombre TURBO existente está catalogado como 350 MB y no se reclasifica. Medimos contrataciones clasificadas por paquete, <b>no la activación técnica del beneficio Netflix</b>.</div>
<div class="note"><b>Candidatos por validar:</b> <?=bp_fmt($promoCandidates)?> en el filtro seleccionado. Requieren verificación; no se suman a Buen Plan confirmado. <b>Corte común:</b> <?=bp_h($lastDay)?> (Tuxtla <?=bp_h($districtCuts['TUXTLA']??'ND')?> · Villahermosa <?=bp_h($districtCuts['VILLAHERMOSA']??'ND')?>). Los días posteriores sin carga no cuentan como cero.</div>
<h2 class="section">3 · Comparativo ejecutivo entre distritos participantes</h2>
<div class="panel scroll"><h3>Tuxtla vs Villahermosa vs consolidado de ambos</h3><div class="sub">250 Mbps residencial · campaña al corte seleccionado vs PRE en días equivalentes de semana (en mensual, referencia proporcional ilustrativa de 30 días). Nunca sumar porcentajes de distritos.</div>
<table class="stats"><thead><tr><th>Distrito</th><th>250 PRE comparable</th><th>250 campaña</th><th>Δ instalaciones</th><th>Mix 250 PRE</th><th>Mix 250 campaña</th><th>Confirmado</th><th>Candidato</th><th>2P</th><th>3P</th><th>% Buen Plan / 250</th><th>Señal precio sin nombre</th></tr></thead><tbody>
<?php foreach(['TUXTLA'=>'Tuxtla','VILLAHERMOSA'=>'Villahermosa','TOTAL'=>'TOTAL SUR participante'] as $k=>$label):$row=$k==='TOTAL'?$districtTotal:$districtComparison[$k];?>
<tr><td><b><?=bp_h($label)?></b></td><td><?=bp_fmt($row['pre250'],2)?></td><td><?=bp_fmt($row['campaign250'])?></td>
<td class="<?=($row['growth']??0)>=0?'positive':'negative'?>"><?= $row['growth']===null?'ND':(($row['growth']>=0?'+':'').bp_fmt($row['growth'],1).'%') ?></td>
<td><?=bp_fmt($row['pre_mix'],1)?>%</td><td><?=bp_fmt($row['promo_mix'],1)?>%</td><td><b><?=bp_fmt($row['promo'])?></b></td><td><?=bp_fmt($row['candidate'])?></td><td><?=bp_fmt($row['promo2'])?></td><td><?=bp_fmt($row['promo3'])?></td><td><?=bp_fmt($row['adoption'],1)?>%</td><td><?=bp_fmt($row['price_signal'])?></td></tr><?php endforeach;?></tbody></table>
</div>
<div class="panel"><h3>Comparativo visual por distrito · Buen Plan identificado</h3><div class="sub">Instalaciones confirmadas con paquete, velocidad 250 MB, Play y pronto pago correcto</div><div class="chart" id="districtChart"></div></div>
<h2 class="section">4 · Análisis PRE vs campaña</h2><div class="layout"><div>
<div class="panel"><h3>Comportamiento de 250 MB</h3><div class="sub">Instalaciones diarias/semanales/mensuales · PRE (lila), PROMO (amarillo), POST (azul) · línea: 250 MB</div><div class="legend"><span><span class="dot" style="background:#a9e7f6"></span>Residencial total</span><span><span class="dot" style="background:#eb079b"></span>250 MB</span></div><div class="chart" id="mainChart"></div></div>
<div class="panel scroll"><h3>Detalle de comportamiento por período</h3><table class="stats"><thead><tr><th>Período</th><th>Total</th><th>250 MB</th><th>2P 250</th><th>3P 250</th><th>Mix 250</th></tr></thead><tbody><?php foreach($chartData as $c):?><tr><td><?=bp_h($c['label'])?> · <?=bp_h($c['phase'])?></td><td><?=$c['total']?></td><td><?=$c['v250']?></td><td><?=$c['p2']?></td><td><?=$c['p3']?></td><td><?=bp_fmt(bp_pct($c['v250'],$c['total']),1)?>%</td></tr><?php endforeach;?></tbody></table></div>
</div><div>
<?php $preLabel=$mode==='mensual'?"promedio $preMonths mes(es)":"promedio $preWeeks semana(s)";foreach([
['ARPU PP 250 MB','$'.bp_fmt($arpuPre,2),'$'.bp_fmt($arpuPromo,2),$arpudelta,'%'],
['Instalaciones 250 MB',bp_fmt($base250,2),bp_fmt($comparison250,2),$change250,'%'],
['Mix de 250 MB',bp_fmt($sharePre,1).'%',bp_fmt($sharePromo,1).'%', $shareDiff,' pp'],
['Precio pronto pago 250 MB', '$'.bp_fmt($pre['pp250_n']?$pre['pp250']/$pre['pp250_n']:null,2),'$'.bp_fmt($promo['pp250_n']?$promo['pp250']/$promo['pp250_n']:null,2),null,''],
] as $row):?><div class="panel"><h3><?=bp_h($row[0])?> · PRE vs PROMO</h3><table class="stats"><thead><tr><th>Segmento</th><th>PRE</th><th>PROMO</th><th>Var.</th></tr></thead><tr><td>250 MB · <?=bp_h($playSel)?></td><td><?=bp_h($row[1])?></td><td><?=bp_h($row[2])?></td><td class="<?=($row[3]??0)>=0?'positive':'negative'?>"><?=$row[3]===null?'ND':(($row[3]>=0?'+':'').bp_fmt($row[3],1).$row[4])?></td></tr></table></div><?php endforeach;?>
<div class="panel"><h3>Desglose por distrito participante</h3><table class="stats"><thead><tr><th>Distrito</th><th>Total en ventana</th><th>250 MB</th><th>Mix</th></tr></thead><tbody><?php foreach($byDistrict as $dis=>$a):?><tr><td><?=bp_h($dis)?></td><td><?=$a['total']?></td><td><?=$a['v250']?></td><td><?=bp_fmt(bp_pct($a['v250'],$a['total']),1)?>%</td></tr><?php endforeach;?></tbody></table></div>
</div></div>
<h2 class="section">5 · Comportamiento por velocidad</h2><div class="layout"><div class="panel scroll"><h3>Instalaciones 2P · comparativo por velocidad</h3><?=bp_render_speed_table('2P',$pre,$promo,$preUnits,$playSel)?>
<h3 style="margin-top:18px">Instalaciones 3P · comparativo por velocidad</h3><?=bp_render_speed_table('3P',$pre,$promo,$preUnits,$playSel)?></div><div>
<div class="panel"><h3>Velocidad | Cambio total 2P + 3P</h3><div class="sub">Δ instalaciones campaña vs promedio PRE de períodos completos; no homologado por días (solo descriptivo)</div><div class="chart" id="speedChart"></div></div>
<div class="panel"><h3>Condiciones comerciales de la promoción</h3><p>Vigencia: <b>03 al 09 de noviembre de 2026</b>. Clientes nuevos, distritos seleccionados, 250 Mbps 2P o 3P con Netflix Estándar con anuncios. Beneficio especial indefinido mientras se mantenga el plan.</p><p><b>Exclusiones:</b> Más Apps, promoción segmentada distrital, descuento a empleado, otras promociones sobre el mismo adicional. Se pierde al cambiar de plan.</p><span class="badge">Promoción documentada · instalación elegible ≠ adopción confirmada</span></div></div></div>
<h2 class="section">6 · Lectura ejecutiva</h2><div class="smallcards"><div class="smallcard"><b>1 · Volumen</b><p><?= $promoDays?('A corte '.$lastDay.', 250 Mbps registra '.bp_fmt($promo['v250']).' instalaciones elegibles durante la promoción, vs '.bp_fmt($base250,2).' por período PRE.'):'La campaña aún no tiene días con registros al corte elegido.'?></p></div><div class="smallcard"><b>2 · Participación</b><p>Mix PRE <?=bp_fmt($sharePre,1)?>%; PROMO <?=bp_fmt($sharePromo,1)?>%. <?= $shareDiff===null?'Sin base suficiente para variación.':('Cambio '.($shareDiff>=0?'+':'').bp_fmt($shareDiff,1).' puntos porcentuales.')?></p></div><div class="smallcard"><b>3 · Calidad del indicador</b><p>Universo clasificado: <?=bp_fmt(count($events))?> instalaciones residenciales; <?=bp_fmt($duplicate+$unmatched)?> cuentas sin detalle inequívoco. Confirmación comercial exige coincidencia de paquete, velocidad, Play y precio; no comprueba activación técnica de Netflix.</p></div></div>
<div class="foot">Fuente: Motor HC <?=bp_h($hcVersion)?> · Motor Comercial <?=bp_h($comercialVersion)?> · catálogo_paquetes. Corte <?=bp_h($lastDay)?>. PRE: <?=bp_h($preLabel)?>; campaña 03–09 nov; POST desde 10 nov. Registros y cifras de Motor Comercial solo lectura, sin modificar atribución ni motores. Buen Plan confirmado sólo con evidencia íntegra; no equivale a activación técnica de Netflix.</div>
<?php endif;?></main>
<script>
const mainData=<?=$chartJson?:'[]'?>,speedData=<?=$barJson?:'[]'?>;
const districtData=<?=json_encode([['name'=>'Tuxtla','p2'=>$districtComparison['TUXTLA']['promo2'],'p3'=>$districtComparison['TUXTLA']['promo3']],['name'=>'Villahermosa','p2'=>$districtComparison['VILLAHERMOSA']['promo2'],'p3'=>$districtComparison['VILLAHERMOSA']['promo3']]],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
function drawDistrict(){let el=document.getElementById('districtChart');if(!el)return;
 let w=Math.max(520,el.clientWidth||520),h=260,base=h-40,max=Math.max(1,...districtData.map(r=>r.p2+r.p3));let svg=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Comparativo Buen Plan por distrito y play">`;
 districtData.forEach((r,i)=>{let x=w*(i+.5)/2,bw=Math.min(90,w/5),scale=160/max,y=base;
 let a=r.p2*scale,b=r.p3*scale;svg+=`<rect x="${x-bw/2}" y="${y-a}" width="${bw}" height="${a}" fill="#0878f9"/><rect x="${x-bw/2}" y="${y-a-b}" width="${bw}" height="${b}" fill="#ea07a7"/><text x="${x}" y="${Math.max(20,y-a-b-7)}" text-anchor="middle" font-size="15" font-weight="bold">${r.p2+r.p3}</text><text x="${x}" y="${h-12}" text-anchor="middle" font-size="13">${r.name}</text>`;});svg+='</svg><div class="legend">🔵 2P · 🔴 3P</div>';el.innerHTML=svg;
}

function drawChart(id,rows,kind){const el=document.getElementById(id);if(!el)return;if(!rows.length){el.innerHTML='<div class="empty">Sin datos para el rango seleccionado</div>';return}const w=Math.max(700,el.clientWidth||700),h=280,L=45,R=18,T=28,B=45,iw=w-L-R,ih=h-T-B;let max=kind==='speed'?Math.max(1,...rows.map(r=>Math.abs(r.delta))):Math.max(1,...rows.map(r=>r.total));const n=rows.length,bw=Math.min(45,iw/n*.58);let svg=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Gráfica ${kind==='speed'?'de variación por velocidad':'de instalaciones por período'}">`;for(let i=0;i<5;i++){const y=T+ih*i/4;svg+=`<path d="M${L} ${y}H${w-R}" stroke="#d9e4eb"/><text x="${L-6}" y="${y+4}" text-anchor="end" font-size="10" fill="#526d8b">${kind==='speed'?(max*(1-2*i/4)).toFixed(0):(max*(1-i/4)).toFixed(0)}</text>`}
if(kind==='speed'){let mid=T+ih/2;svg+=`<path d="M${L} ${mid}H${w-R}" stroke="#7b93a3"/>`;rows.forEach((r,i)=>{let x=L+iw*(i+.5)/n;let hh=Math.abs(r.delta)/max*ih/2;let y=r.delta>=0?mid-hh:mid;svg+=`<rect x="${x-bw/2}" y="${y}" width="${bw}" height="${Math.max(1,hh)}" fill="${r.delta>=0?'#008f61':'#e00045'}"/><text x="${x}" y="${r.delta>=0?y-7:y+hh+12}" font-size="10" text-anchor="middle" fill="#193c60">${r.delta>=0?'+':''}${r.delta.toFixed(2)}</text><text x="${x}" y="${h-12}" text-anchor="middle" font-size="10">${r.speed}</text>`})}else{let points=[];rows.forEach((r,i)=>{let x=L+iw*(i+.5)/n;let y=T+ih-r.total/max*ih;let ly=T+ih-r.v250/max*ih;svg+=`<rect x="${x-bw/2}" y="${y}" width="${bw}" height="${T+ih-y}" fill="#a4e5f8"/><rect x="${x-bw/2}" y="${T}" height="${ih}" width="${bw}" fill="${r.phase==='PROMO'?'#ffcd5038':r.phase==='PRE'?'#d69bee20':'#00a7da08'}"/><text x="${x}" y="${Math.max(11,y-7)}" font-size="10" text-anchor="middle" fill="#0569df">${r.total}</text><text x="${x}" y="${h-12}" font-size="10" text-anchor="middle">${r.label}</text><text x="${x}" y="${Math.min(T+ih-5,ly+17)}" font-size="10" text-anchor="middle" fill="#d80082">${r.v250}</text>`;points.push([x,ly])});if(points.length){svg+=`<polyline fill="none" stroke="#eb078b" stroke-width="2.8" points="${points.map(p=>p.join(',')).join(' ')}"/>`;points.forEach(p=>svg+=`<circle cx="${p[0]}" cy="${p[1]}" r="4" fill="#eb078b"/>`)}}svg+='</svg>';el.innerHTML=svg}
drawChart('mainChart',mainData,'main');drawChart('speedChart',speedData,'speed');drawDistrict();addEventListener('resize',()=>{drawChart('mainChart',mainData,'main');drawChart('speedChart',speedData,'speed');drawDistrict()});
</script></body></html>
<?php
/** Esta función se declara aquí para desacoplar la representación de la base de datos. */
function bp_render_speed_table(string $play,array $pre,array $current,int $n,string $selection):string {
    if($selection!=='AMBOS' && $selection!==$play)return '<p class="secondary">Oculto por filtro de play</p>';
    $speeds=array_unique(array_merge(array_keys($pre['speeds'][$play]),array_keys($current['speeds'][$play])));sort($speeds,SORT_NUMERIC);
    $baseTotal=($play==='2P'?$pre['p2']:$pre['p3'])/$n;$actualTotal=($play==='2P'?$current['p2']:$current['p3']);
    $html='<table class="stats"><thead><tr><th>Velocidad</th><th>PRE prom.</th><th>PROMO</th><th>Δ inst.</th><th>Δ %</th><th>Mix PROMO</th><th>Δ mix pp</th></tr></thead><tbody>';
    foreach($speeds as $speed){$b=($pre['speeds'][$play][$speed]??0)/$n;$a=$current['speeds'][$play][$speed]??0;$delta=$a-$b;$pc=bp_delta($a,$b);$mix=bp_pct($a,$actualTotal);$oldMix=bp_pct($b,$baseTotal);$dp=($mix!==null&&$oldMix!==null)?$mix-$oldMix:null;
        $html.='<tr><td>'.bp_h($speed).' MB</td><td>'.bp_fmt($b,2).'</td><td>'.bp_fmt($a).'</td><td class="'.($delta>=0?'positive':'negative').'">'.($delta>=0?'+':'').bp_fmt($delta,2).'</td><td>'.($pc===null?'ND':(($pc>=0?'+':'').bp_fmt($pc,1).'%')).'</td><td>'.bp_fmt($mix,1).'%</td><td>'.($dp===null?'ND':(($dp>=0?'+':'').bp_fmt($dp,1).' pp')).'</td></tr>';
    }
    $diff=$actualTotal-$baseTotal;$html.='</tbody><tfoot><tr><td>TOTAL '.$play.'</td><td>'.bp_fmt($baseTotal,2).'</td><td>'.bp_fmt($actualTotal).'</td><td>'.($diff>=0?'+':'').bp_fmt($diff,2).'</td><td>'.bp_fmt(bp_delta($actualTotal,$baseTotal),1).'%</td><td>'.($actualTotal>0?'100.0%':'ND').'</td><td>0.0 pp</td></tr></tfoot></table>';return $html;
}
