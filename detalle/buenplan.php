<?php
/**
 * TalIA / TotalXpedient — Promociones / Buen Plan v1.3.0
 * Ruta propuesta: /plataforma/detalle/buenplan.php
 * Solo lectura. NO modifica sidebar, BD ni motores.
 * Identifica 250 MB desde catálogo, Play/Tipo desde catálogo y precio PP desde instalaciones.
 * Identificación comercial por nombres VELOZ Netflix exactos (2P/3P); sin filtro por precio.
 * Sin cambios a Motor HC, Motor Comercial, catálogo ni sidebar.
 * Requiere PHP 8+, motor_hc.php y motor_comercial.php de producción.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store, no-cache, must-revalidate');
session_start();
if (empty($_SESSION['usuario'])) { http_response_code(403); exit('Sesión TalIA no disponible.'); }
require_once __DIR__.'/../conexion.php';
$roles = ['admin','director_regional']; // Vista regional: no exponer cuentas de dos distritos a roles sin alcance regional
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
    if ($key==='VELOZ NETFLIX STD CON ANUNCIOS TV V1') return '3P';
    if ($key==='VELOZ NETFLIX STD CON ANUNCIOS V1') return '2P';
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
$error='';$warn=[];$auditRows=[];$events=[];$lastDay=null;$confirmed=0;$unmatched=0;$duplicate=0;$noCatalog=0;$flagPromoAvailable=false;$duplicateExact=0;$duplicateConflict=0;$missingDetail=0;$auditBreakdown=['REPETIDA_IDENTICA'=>0,'PLAN_DIFERENTE'=>0,'PRECIO_DIFERENTE'=>0,'ATRIBUTOS_CATALOGO_DIFERENTES'=>0,'FECHA_DIFERENTE'=>0,'SIN_DETALLE'=>0];
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
        $rr=$records[$account]??[];
        if(!$rr){$unmatched++;$missingDetail++;$auditBreakdown['SIN_DETALLE']++;$auditRows[]=['cuenta'=>$account,'distrito'=>$meta['dist'],'motivo'=>'SIN DETALLE','filas'=>0,'detalle'=>'Sin fila de instalación en el intervalo'];continue;}
        if(count($rr)>1){
            // No adjudicar arbitrariamente una cuenta con planes, precios, fechas o
            // clasificaciones de catálogo distintos. Repeticiones idénticas se
            // consolidan como UNA cuenta y quedan documentadas en auditoría.
            $signatures=[];
            foreach($rr as $record){
                $signature=json_encode([
                    substr((string)($record['fecha']??''),0,10),bp_n($record['plan']??''),
                    (string)($record['precio_pp']??''),(string)($record['precio_lista']??''),
                    bp_n($record['velocidad_agrupada']??''),bp_n($record['play']??''),
                    bp_n($record['tipo']??'')
                ],JSON_UNESCAPED_UNICODE);
                $signatures[$signature]=true;
            }
            if(count($signatures)===1){
                $duplicateExact++;$auditBreakdown['REPETIDA_IDENTICA']++;
            }else{
                $duplicate++;$duplicateConflict++;$auditRows[]=['cuenta'=>$account,'distrito'=>$meta['dist'],'motivo'=>'DATOS CONTRADICTORIOS','filas'=>count($rr),'detalle'=>implode(' | ',array_map(static fn($x)=>substr((string)($x['fecha']??''),0,10).' · '.(string)($x['plan']??'').' · PP '.(string)($x['precio_pp']??'ND'),array_slice($rr,0,5)))];
                foreach(['plan'=>'PLAN_DIFERENTE','precio_pp'=>'PRECIO_DIFERENTE',
                          'precio_lista'=>'PRECIO_DIFERENTE',
                          'fecha'=>'FECHA_DIFERENTE','velocidad_agrupada'=>'ATRIBUTOS_CATALOGO_DIFERENTES',
                          'play'=>'ATRIBUTOS_CATALOGO_DIFERENTES','tipo'=>'ATRIBUTOS_CATALOGO_DIFERENTES'] as $column=>$reason){
                    $distinct=[];foreach($rr as $record)$distinct[json_encode($record[$column]??null)]=true;
                    if(count($distinct)>1)$auditBreakdown[$reason]++;
                }
                continue;
            }
        }
        $r=$rr[0];$day=substr((string)$r['fecha'],0,10);if(!bp_date($day)){$unmatched++;$missingDetail++;$auditBreakdown['SIN_DETALLE']++;$auditRows[]=['cuenta'=>$account,'distrito'=>$meta['dist'],'motivo'=>'FECHA INVÁLIDA','filas'=>count($rr),'detalle'=>'Fecha no válida'];continue;}
        $promoPlay=bp_promo_play((string)$r['plan']);
        if($r['match_catalogo']===null){$noCatalog++;}
        $tipo=bp_n($r['tipo']??'');
        if($tipo!=='RESIDENCIAL')continue;
        $pl=bp_n($r['play']??'');$pl=str_contains($pl,'DOBLE')?'2P':(str_contains($pl,'TRIPLE')?'3P':'ND');
        if($playSel!=='AMBOS' && $pl!==$playSel)continue;
        $rawVel=bp_n((string)($r['velocidad_agrupada']??''));
        $rawVel=str_replace([',',' '],'',$rawVel);
        $speed=preg_match('/^(\d+)MB$/',$rawVel,$m)?(int)$m[1]:null;
        $pp=(is_numeric($r['precio_pp']??null) && (float)$r['precio_pp']>0)?(float)$r['precio_pp']:null;
        // Identificación de los DOS paquetes promocionales aportados por operación.
        // Precio de Salesforce puede contener descuentos, por lo que NO es filtro.
        $correctName=$promoPlay!==null && $promoPlay===$pl;
        $validPeriod=bp_is_promo($day);
        $confirmed=$validPeriod && $tipo==='RESIDENCIAL' && $speed===250 && $correctName;
        // Inconsistencias taxonómicas nunca se adjudican como promoción.
        $candidate=false;$priceSignal=false;$priceConflict=false;
        $events[]=['day'=>$day,'dist'=>$meta['dist'],'play'=>$pl,'speed'=>$speed,'pp'=>$pp,
           'promo'=>$confirmed,'candidate'=>$candidate,
           'price_signal'=>false,
           'price_conflict'=>$priceConflict,'classified'=>($pl!=='ND'&&$speed!==null)];
    }
    // Incidencias se conservan exclusivamente en auditoría desplegable.
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
// Base comparable: mismo conjunto de días ISO que la campaña transcurrida.
// En mensual se usa la tasa diaria de meses PRE COMPLETOS multiplicada por
// los días efectivos, en lugar de dividir arbitrariamente entre 30.
$preUnits=$mode==='mensual'?$preMonths:$preWeeks;
$promoDays=$lastDay && $lastDay>='2026-11-03'
    ? min(7, (int)$promoStart->diff(new DateTimeImmutable($lastDay))->days+1) : 0;
$isoDays=[];
for($i=0;$i<$promoDays;$i++)$isoDays[(int)$promoStart->modify('+'.$i.' days')->format('N')]=true;
$comparablePre=bp_blank();
$preActualDays=0;
if($mode==='mensual'){
    for($d=$start;$d<$promoStart;$d=$d->modify('+1 day')){
        // El PRE mensual acaba el 31 de octubre y es un mes calendario completo.
        if($d->format('Y-m-d')<'2026-11-01')$preActualDays++;
    }
}
foreach($events as $ev){
    if(($district!=='SUR' && $district!==$ev['dist']) || bp_phase($ev['day'])!=='PRE')continue;
    if($mode==='mensual'){
        if($ev['day']<'2026-11-01')bp_add($comparablePre,$ev);
    } elseif(isset($isoDays[(int)(new DateTimeImmutable($ev['day']))->format('N')])) bp_add($comparablePre,$ev);
}
$baseFactor=$mode==='mensual'
    ? ($preActualDays>0?$promoDays/$preActualDays:0.0)
    : ($preUnits>0?1.0/$preUnits:0.0);
$base250=$comparablePre['v250']*$baseFactor;
$comparison250=$promo['v250'];$targetRate=$base250;
$change250=$promoDays>0?bp_delta($comparison250,$targetRate):null;
$baseTotal=$comparablePre['total']*$baseFactor;
$promoTotal=$promo['total'];
$changeTotal=$promoDays>0?bp_delta($promoTotal,$baseTotal):null;
$sharePre=bp_pct($pre['v250'],$pre['total']);$sharePromo=bp_pct($promo['v250'],$promo['total']);
$shareDiff=($sharePre!==null&&$sharePromo!==null)?$sharePromo-$sharePre:null;
$arpuPre=$pre['income250_n']?$pre['income250']/$pre['income250_n']:null;
$arpuPromo=$promo['income250_n']?$promo['income250']/$promo['income250_n']:null;
$arpudelta=($arpuPre!==null&&$arpuPromo!==null)?bp_delta($arpuPromo,$arpuPre):null;
$targetVal=$comparablePre['income250']*$baseFactor;
$promoVal=$promo['income250'];
$valueDelta=$promoDays>0&&$targetVal>0?bp_delta($promoVal,$targetVal):null;
$speeds=[];foreach([$comparablePre,$promo] as $a)foreach(['2P','3P'] as $p)foreach($a['speeds'][$p] as $s=>$n)$speeds[(int)$s]=true;ksort($speeds,SORT_NUMERIC);
$chartData=[];
foreach($series as $key=>$a){
    $d=$mode==='semanal'?(new DateTimeImmutable('2026-01-01'))->setISODate((int)substr($key,0,4),(int)substr($key,-2),1):($mode==='mensual'?new DateTimeImmutable($key.'-01'):new DateTimeImmutable($key));
    $dt=$d?:$promoStart;
    $phase=($mode==='mensual' && $key==='2026-11')||($mode==='semanal' && $key==='2026-W45')?'PROMO':bp_phase($dt->format('Y-m-d'));
    $chartData[]=['key'=>$key,'label'=>bp_lab($key,$mode),'total'=>$a['total'],'v250'=>$a['v250'],'p2'=>$a['p2_250'],'p3'=>$a['p3_250'],'phase'=>$phase,'promo'=>$a['promo']];
}
$chartJson=json_encode($chartData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
$barData=[];
foreach(array_keys($speeds) as $s){
    $p2Pre=($comparablePre['speeds']['2P'][$s]??0)*$baseFactor;
    $p3Pre=($comparablePre['speeds']['3P'][$s]??0)*$baseFactor;
    $p2Now=$promo['speeds']['2P'][$s]??0;
    $p3Now=$promo['speeds']['3P'][$s]??0;
    $barData[]=['speed'=>$s,'delta'=>($p2Now+$p3Now)-($p2Pre+$p3Pre)];
}
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
            if($mode!=='mensual' || $ev['day']<'2026-11-01')bp_add($allB,$ev);
            if($promoDays>0 && $mode!=='mensual'){
                // Intervalo PRE semanal comienza lunes, campaña inicia martes.
                $isoDow=(int)(new DateTimeImmutable($ev['day']))->format('N');
                if(isset($isoDays[$isoDow]))bp_add($refComparable,$ev);
            }elseif($mode==='mensual' && $ev['day']<'2026-11-01')bp_add($refComparable,$ev);
        }
    }
    $reference=$refComparable['v250']*$baseFactor;
    $baseTotalComparable=$refComparable['total']*$baseFactor;
    $districtComparison[$distName]=[
      'pre250'=>$reference,'campaign250'=>$allP['v250'],
      'growth'=>$promoDays>0?bp_delta($allP['v250'],$reference):null,
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
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buen Plan | Promociones · TotalXpedient</title>
<style>
:root{--ink:#0e2450;--blue:#0878ec;--line:#b5d9ef;--purple:#a618e5;--pink:#dc0a99;--green:#00865d;--red:#ce2347;--muted:#61758d;--bg:#f3f8fc}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:13px Arial,sans-serif}.wrap{max-width:1660px;margin:auto;padding:15px 20px 40px}.heading{display:flex;justify-content:space-between;align-items:center;gap:16px;background:linear-gradient(110deg,#f4efff,#e8f9ff);padding:14px 20px;border-radius:8px}.heading h1{font-size:25px;margin:0 0 6px}.meta{color:var(--muted);font-size:12px}.nav{display:flex;gap:7px}.nav a{padding:10px 13px;border-radius:12px;background:white;color:var(--ink);text-decoration:none;font-weight:bold}.nav a.active{background:linear-gradient(125deg,#9118e8,#e709b1);color:white}.form{display:flex;flex-wrap:wrap;align-items:end;gap:11px;margin:12px 0}.form label{display:grid;gap:5px;color:#3b6087;font-size:11px;font-weight:bold}.form select,.form input{height:34px;background:white;border:1px solid #b9d3eb;border-radius:6px;color:var(--ink);padding:6px}.submit{border:0;background:var(--blue);color:white;padding:10px 16px;border-radius:6px;font-weight:bold;cursor:pointer}.hero{background:white;border:1px solid #d9e7ef;border-left:5px solid var(--purple);padding:14px 18px;border-radius:5px;margin:10px 0 17px}.hero h2{font-size:22px;margin:0 0 6px}.hero p{color:#456688;font-size:12px;margin:0}.section{margin:20px 0 10px;font-size:17px;color:var(--blue)}.kpis{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px}.kpis.promo{grid-template-columns:repeat(4,minmax(150px,1fr))}.kpi{border:1px solid #a9d1ef;border-radius:7px;background:#eaf7ff;padding:15px 8px;text-align:center;min-height:110px;display:flex;flex-direction:column;align-items:center;justify-content:center}.kpi:nth-child(odd){background:#fff2f6;border-color:#ffbfd0}.kpi small{color:#096fe0;font-weight:800;font-size:11px}.kpi strong{font-size:27px;margin:9px 0 4px;color:var(--ink)}.kpi strong.up{color:var(--green)}.kpi strong.down{color:var(--red)}.kpi span{color:#4d6b8f;font-size:11px}.panel{border:1px solid var(--line);border-radius:7px;background:white;padding:13px 14px;min-width:0}.panel h3{font-size:15px;color:var(--blue);margin:0 0 7px}.panel p.sub{font-size:11px;color:var(--muted);margin:0 0 10px}.cols{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(320px,.9fr);gap:13px}.side{display:grid;gap:10px;align-content:start}.tablewrap{overflow:auto}.tbl{border-collapse:collapse;width:100%;font-size:12px}.tbl th{background:#dfedf6;color:#243e55}.tbl th,.tbl td{border:1px solid #b1c2d0;padding:7px 8px;white-space:nowrap}.tbl td:not(:first-child),.tbl th:not(:first-child){text-align:right}.tbl tfoot td{background:#edf6fb;font-weight:bold}.up{color:var(--green);font-weight:800}.down{color:var(--red);font-weight:800}.chart{width:100%;height:268px}.chart.small{height:190px}.legend{font-size:11px;color:var(--muted);display:flex;gap:13px;flex-wrap:wrap}.dot{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px}.speedcards{display:grid;grid-template-columns:1fr;gap:12px}.speedgrid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr);gap:12px;align-items:center}.notice{border:1px solid #e8b3b3;background:#fff2f2;padding:12px;border-radius:7px;color:#8c192f}.details{margin-top:18px}.details summary{cursor:pointer;font-weight:bold;color:var(--blue);padding:3px}.foot{margin-top:16px;font-size:11px;color:#718398}.empty{color:#70859e;text-align:center;padding:30px}.hint{font-size:11px;color:var(--muted)}@media(max-width:1100px){.kpis{grid-template-columns:repeat(3,1fr)}.cols,.speedgrid{grid-template-columns:1fr}}@media(max-width:620px){.wrap{padding:10px}.heading{flex-direction:column;align-items:start}.kpis,.kpis.promo{grid-template-columns:repeat(2,1fr)}.hero h2{font-size:19px}}
</style></head><body><main class="wrap">
<header class="heading"><div><h1>Promociones | Buen Plan · 2026</h1><div class="meta">Región SUR · <?=bp_h($district==='SUR'?'Tuxtla + Villahermosa':$district)?> · Corte común: <?=bp_h($lastDay??'ND')?> · Instalaciones certificadas</div></div><nav class="nav"><?php foreach(['diario'=>'Diario','semanal'=>'Semanal','mensual'=>'Mensual'] as $k=>$v):?><a class="<?=$mode===$k?'active':''?>" href="<?=bp_h(bp_href(['periodo'=>$k]))?>"><?=bp_h($v)?></a><?php endforeach;?></nav></header>
<form class="form" method="get"><input name="periodo" type="hidden" value="<?=bp_h($mode)?>"><label>Distrito<select name="distrito"><option value="SUR" <?=$district==='SUR'?'selected':''?>>Tuxtla + Villahermosa</option><option value="TUXTLA" <?=$district==='TUXTLA'?'selected':''?>>Tuxtla</option><option value="VILLAHERMOSA" <?=$district==='VILLAHERMOSA'?'selected':''?>>Villahermosa</option></select></label><label>Play<select name="play"><option value="AMBOS" <?=$playSel==='AMBOS'?'selected':''?>>2P + 3P</option><option value="2P" <?=$playSel==='2P'?'selected':''?>>2P</option><option value="3P" <?=$playSel==='3P'?'selected':''?>>3P</option></select></label><?php if($mode==='mensual'):?><label>Meses PRE<input name="pre_meses" type="number" min="1" max="6" value="<?=$preMonths?>"></label><?php else:?><label>Semanas PRE<input name="pre_semanas" type="number" min="1" max="12" value="<?=$preWeeks?>"></label><?php endif;?><label>Fecha de corte<input name="corte" type="date" value="<?=bp_h($lastDay)?>" max="<?=bp_h($lastDay)?>"></label><button class="submit" type="submit">Aplicar filtros</button></form>
<section class="hero"><h2>Buen Plan | 250 Mbps + Netflix Estándar con anuncios</h2><p>Tuxtla y Villahermosa · Vigencia del 3 al 9 de noviembre de 2026 · PRE configurable · POST desde el 10 de noviembre · <?=bp_h(ucfirst($mode))?></p></section>
<?php if($error):?><div class="notice">Datos no disponibles: <?=bp_h($error)?>. No se muestran indicadores estimados.</div><?php else:?>
<h2 class="section">1 · Resumen ejecutivo · 250 Mbps residencial</h2>
<div class="kpis">
<div class="kpi"><small>INSTALACIONES 250 MB</small><strong class="<?=($change250??0)>=0?'up':'down'?>"><?=$change250===null?'ND':(($change250>=0?'+':'').bp_fmt($change250,1).'%')?></strong><span><?=bp_fmt($promo['v250'])?> vs <?=bp_fmt($base250,2)?> PRE comparable</span></div>
<div class="kpi"><small>MIX 250 MB</small><strong class="<?=($shareDiff??0)>=0?'up':'down'?>"><?=$shareDiff===null?'ND':(($shareDiff>=0?'+':'').bp_fmt($shareDiff,1).' pp')?></strong><span><?=bp_fmt($sharePromo,1)?>% vs <?=bp_fmt($sharePre,1)?>% PRE</span></div>
<div class="kpi"><small>ARPU PP · 250 MB</small><strong class="<?=($arpudelta??0)>=0?'up':'down'?>"><?=$arpudelta===null?'ND':(($arpudelta>=0?'+':'').bp_fmt($arpudelta,1).'%')?></strong><span>$<?=bp_fmt($arpuPromo,2)?> vs $<?=bp_fmt($arpuPre,2)?></span></div>
<div class="kpi"><small>VALOR PP · 250 MB</small><strong class="<?=($valueDelta??0)>=0?'up':'down'?>"><?=$valueDelta===null?'ND':(($valueDelta>=0?'+':'').bp_fmt($valueDelta,1).'%')?></strong><span>$<?=bp_fmt($promoVal,0)?> vs $<?=bp_fmt($targetVal,0)?> PRE</span></div>
<div class="kpi"><small>TOTAL RESIDENCIAL</small><strong class="<?=($changeTotal??0)>=0?'up':'down'?>"><?=$changeTotal===null?'ND':(($changeTotal>=0?'+':'').bp_fmt($changeTotal,1).'%')?></strong><span><?=bp_fmt($promoTotal)?> vs <?=bp_fmt($baseTotal,2)?> PRE</span></div>
</div>
<h2 class="section">2 · Paquetes Buen Plan identificados</h2><div class="kpis promo">
<div class="kpi"><small>TOTAL NETFLIX · 250 MB</small><strong><?=bp_fmt($promoIdentified)?></strong><span>Instalaciones con nombre de paquete Buen Plan</span></div>
<div class="kpi"><small>BUEN PLAN 2P · INTERNET</small><strong><?=bp_fmt($promo2)?></strong><span>VELOZ Netflix Estándar con anuncios</span></div>
<div class="kpi"><small>BUEN PLAN 3P · TV + INTERNET</small><strong><?=bp_fmt($promo3)?></strong><span>VELOZ Netflix Estándar con anuncios TV</span></div>
<div class="kpi"><small>PARTICIPACIÓN EN 250 MB</small><strong><?=bp_fmt($adoption,1)?><?=$adoption===null?'':'%'?></strong><span>Paquetes Buen Plan / instalaciones 250 MB</span></div>
</div>
<h2 class="section">3 · Tuxtla vs Villahermosa</h2>
<div class="cols"><div class="panel tablewrap"><h3>Crecimiento de 250 Mbps</h3><p class="sub">Campaña al corte vs PRE de días equivalentes</p><table class="tbl"><thead><tr><th>Distrito</th><th>PRE comp.</th><th>Campaña</th><th>Δ %</th><th>Mix PRE</th><th>Mix campaña</th><th>Buen Plan 2P</th><th>Buen Plan 3P</th><th>Total Buen Plan</th></tr></thead><tbody><?php foreach(['TUXTLA'=>'Tuxtla','VILLAHERMOSA'=>'Villahermosa','TOTAL'=>'Total ambos'] as $k=>$label):$r=$k==='TOTAL'?$districtTotal:$districtComparison[$k];?><tr><td><b><?=bp_h($label)?></b></td><td><?=bp_fmt($r['pre250'],2)?></td><td><?=bp_fmt($r['campaign250'])?></td><td class="<?=($r['growth']??0)>=0?'up':'down'?>"><?=$r['growth']===null?'ND':(($r['growth']>=0?'+':'').bp_fmt($r['growth'],1).'%')?></td><td><?=bp_fmt($r['pre_mix'],1)?>%</td><td><?=bp_fmt($r['promo_mix'],1)?>%</td><td><?=bp_fmt($r['promo2'])?></td><td><?=bp_fmt($r['promo3'])?></td><td><b><?=bp_fmt($r['promo'])?></b></td></tr><?php endforeach;?></tbody></table></div>
<div class="panel"><h3>Instalaciones 250 Mbps por distrito</h3><p class="sub">PRE comparable vs campaña, con las ventas de paquete Buen Plan identificadas como una sección independiente</p><div id="districtChart" class="chart"></div><div class="legend"><span><i class="dot" style="background:#a9d8f8"></i>PRE</span><span><i class="dot" style="background:#177fe2"></i>Campaña 250</span><span><i class="dot" style="background:#d3089b"></i>Buen Plan (dentro de 250)</span></div></div></div>
<h2 class="section">4 · Evolución de las instalaciones</h2><div class="cols"><div class="panel"><h3>Residencial total vs 250 Mbps</h3><p class="sub">Serie <?=bp_h($mode)?> · PRE / promoción / POST</p><div id="mainChart" class="chart"></div><div class="legend"><span><i class="dot" style="background:#a4dff9"></i>Total residencial</span><span><i class="dot" style="background:#db0a9d"></i>250 Mbps</span></div></div><div class="side"><div class="panel"><h3>Indicadores PRE vs promoción</h3><table class="tbl"><thead><tr><th>Indicador</th><th>PRE</th><th>Campaña</th><th>Variación</th></tr></thead><tbody>
<tr><td>Instalaciones 250</td><td><?=bp_fmt($base250,2)?></td><td><?=bp_fmt($comparison250)?></td><td><?= $change250===null?'ND':(($change250>=0?'+':'').bp_fmt($change250,1).'%')?></td></tr><tr><td>Mix 250</td><td><?=bp_fmt($sharePre,1)?>%</td><td><?=bp_fmt($sharePromo,1)?>%</td><td><?=($shareDiff===null?'ND':(($shareDiff>=0?'+':'').bp_fmt($shareDiff,1).' pp'))?></td></tr><tr><td>ARPU PP 250</td><td>$<?=bp_fmt($arpuPre,2)?></td><td>$<?=bp_fmt($arpuPromo,2)?></td><td><?=$arpudelta===null?'ND':(($arpudelta>=0?'+':'').bp_fmt($arpudelta,1).'%')?></td></tr></tbody></table></div><div class="panel"><h3>Ventana de análisis</h3><div style="font-size:14px;line-height:1.8"><b><?=$promoDays?> de 7</b> días de promoción al corte<br><b><?=bp_h($lastDay)?></b> corte común de ambos distritos<br><b><?=$mode==='mensual'?$preMonths.' mes(es)':$preWeeks.' semana(s)'?></b> PRE</div></div></div></div>
<h2 class="section">5 · Velocidades: efecto en toda la oferta residencial</h2>
<div class="speedcards">
<?php foreach(['2P','3P','TOTAL'] as $kind): if($playSel!=='AMBOS' && $kind!==$playSel && $kind!=='TOTAL')continue; $title=$kind==='TOTAL'?'2P + 3P consolidado':$kind; $rows=[];$preAll=0;$currentAll=0;$sumSpeed=[];foreach([$comparablePre,$promo] as $x)foreach(['2P','3P'] as $p)foreach($x['speeds'][$p] as $sp=>$n)$sumSpeed[(int)$sp]=true;ksort($sumSpeed,SORT_NUMERIC);foreach(array_keys($sumSpeed) as $sp){$plays=$kind==='TOTAL'?($playSel==='AMBOS'?['2P','3P']:[$playSel]):[$kind];$before=0;$now=0;foreach($plays as $pp){$before+=($comparablePre['speeds'][$pp][$sp]??0)*$baseFactor;$now+=($promo['speeds'][$pp][$sp]??0);} $rows[]=['speed'=>$sp,'pre'=>$before,'now'=>$now];$preAll+=$before;$currentAll+=$now;} ?>
<div class="panel"><h3><?=bp_h($title)?> · PRE vs promoción por velocidad</h3><div class="speedgrid"><div class="tablewrap"><table class="tbl"><thead><tr><th>Velocidad</th><th>PRE equiv.</th><th>Campaña</th><th>Δ ins.</th><th>Δ %</th><th>Mix campaña</th></tr></thead><tbody><?php foreach($rows as $r):$delta=$r['now']-$r['pre'];$pct=bp_delta($r['now'],$r['pre']);?><tr><td><?=bp_fmt($r['speed'])?> MB</td><td><?=bp_fmt($r['pre'],2)?></td><td><?=bp_fmt($r['now'])?></td><td class="<?=$delta>=0?'up':'down'?>"><?=($delta>=0?'+':'').bp_fmt($delta,2)?></td><td><?=$pct===null?'ND':(($pct>=0?'+':'').bp_fmt($pct,1).'%')?></td><td><?=bp_fmt(bp_pct($r['now'],$currentAll),1)?>%</td></tr><?php endforeach;?></tbody><tfoot><tr><td>TOTAL</td><td><?=bp_fmt($preAll,2)?></td><td><?=bp_fmt($currentAll)?></td><td><?=bp_fmt($currentAll-$preAll,2)?></td><td><?=bp_fmt(bp_delta($currentAll,$preAll),1)?>%</td><td><?= $currentAll>0?'100%':'ND' ?></td></tr></tfoot></table></div><div class="chart small" id="speed-<?=$kind?>"></div></div></div>
<?php endforeach;?></div>
<details class="panel details"><summary>Auditoría técnica · <?=$duplicate+$unmatched?> cuentas excluidas · <?=$duplicateExact?> duplicados idénticos consolidados</summary><p class="hint">Detalle exclusivo de control interno; una cuenta no se asigna arbitrariamente si existen datos contradictorios. Las incidencias no alteran Motor HC ni Motor Comercial.</p><div class="tablewrap"><table class="tbl"><thead><tr><th>Cuenta</th><th>Distrito</th><th>Motivo</th><th>Filas</th><th>Detalle de origen</th></tr></thead><tbody><?php if(!$auditRows):?><tr><td colspan="5">Sin exclusiones pendientes</td></tr><?php else:foreach($auditRows as $r):?><tr><td><?=bp_h($r['cuenta'])?></td><td><?=bp_h($r['distrito'])?></td><td><?=bp_h($r['motivo'])?></td><td><?=bp_fmt($r['filas'])?></td><td style="white-space:normal"><?=bp_h($r['detalle'])?></td></tr><?php endforeach;endif;?></tbody></table></div></details>
<div class="foot">Fuente: Motor HC <?=bp_h($hcVersion)?> · Motor Comercial <?=bp_h($comercialVersion)?> · catalogo_paquetes · Buen Plan v1.3.0. Indicadores basados en instalaciones certificadas, no ventas contratadas ni activación técnica de Netflix. Nombre de paquete determina identificación; precios no se utilizan para clasificar la promoción.</div>
<?php endif;?></main>
<script>
const mainRows=<?=$chartJson?:'[]'?>;
const districts=<?=json_encode(array_map(static fn($d)=>['pre'=>(float)($d['pre250']??0),'now'=>(int)($d['campaign250']??0),'promo'=>(int)($d['promo']??0)],[$districtComparison['TUXTLA']??[],$districtComparison['VILLAHERMOSA']??[]]),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
const speeds=<?=json_encode((function() use ($comparablePre,$promo,$baseFactor,$playSel){$out=[];foreach(['2P','3P','TOTAL'] as $kind){$arr=[];$ss=[];foreach([$comparablePre,$promo] as $x)foreach(['2P','3P'] as $p)foreach($x['speeds'][$p] as $sp=>$n)$ss[(int)$sp]=1;ksort($ss,SORT_NUMERIC);foreach(array_keys($ss) as $sp){$prev=0;$now=0;foreach(($kind==='TOTAL'?($playSel==='AMBOS'?['2P','3P']:[$playSel]):[$kind]) as $p){$prev+=($comparablePre['speeds'][$p][$sp]??0)*$baseFactor;$now+=($promo['speeds'][$p][$sp]??0);} $arr[]=['speed'=>$sp,'pre'=>$prev,'now'=>$now];}$out[$kind]=$arr;}return $out;})(),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
function plotDistrict(){const el=document.getElementById('districtChart');if(!el)return;let w=590,h=245,top=20,bottom=207,max=Math.max(1,...districts.map(r=>Math.max(r.pre,r.now)));let v=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="PRE y campaña 250 Mbps por distrito">`;districts.forEach((r,i)=>{let x=145+290*i,bar=58,scale=165/max;let pre=r.pre*scale,now=r.now*scale,promo=r.promo*scale;v+=`<rect x="${x-65}" y="${bottom-pre}" width="${bar}" height="${pre}" fill="#a9d8f8"/><rect x="${x+10}" y="${bottom-now}" width="${bar}" height="${now}" fill="#177fe2"/><rect x="${x+10}" y="${bottom-promo}" width="${bar}" height="${promo}" fill="#d3089b"/><text x="${x-36}" y="${bottom-pre-8}" text-anchor="middle" font-size="15" fill="#164c81">${r.pre.toFixed(2)}</text><text x="${x+39}" y="${bottom-now-8}" text-anchor="middle" font-size="15" fill="#164c81">${r.now}</text><text x="${x}" y="235" text-anchor="middle" font-size="15">${i?'Villahermosa':'Tuxtla'}</text>`});v+='</svg>';el.innerHTML=v}
function plotMain(){let el=document.getElementById('mainChart');if(!el)return;if(!mainRows.length){el.innerHTML='<div class="empty">Sin datos</div>';return}let w=Math.max(800,mainRows.length*22+80),h=260,L=34,T=20,B=33,bottom=h-B,inner=bottom-T,max=Math.max(1,...mainRows.map(x=>x.total));let v=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Evolución de instalaciones totales y de 250 Mbps">`,pts=[];mainRows.forEach((r,i)=>{let x=L+(w-L-8)*(i+.5)/mainRows.length,bw=Math.min(28,(w-L-8)/mainRows.length*.65),hh=r.total/max*inner,y=bottom-hh,yp=bottom-r.v250/max*inner;v+=`<rect x="${x-bw/2}" y="${y}" width="${bw}" height="${hh}" fill="#a4dff9"/><rect x="${x-bw/2}" y="${T}" width="${bw}" height="${inner}" fill="${r.phase==='PROMO'?'#ffd75740':r.phase==='PRE'?'#cda4ef12':'#48baff18'}"/><text x="${x}" y="${Math.max(12,y-4)}" text-anchor="middle" font-size="9" fill="#177fe2">${r.total}</text>${mainRows.length<=16?`<text x="${x}" y="${h-7}" text-anchor="middle" font-size="10">${r.label}</text>`:''}`;pts.push([x,yp,r.v250])});v+=`<polyline fill="none" stroke="#db0a9d" stroke-width="2.7" points="${pts.map(x=>x[0]+','+x[1]).join(' ')}"/>`;pts.forEach(x=>v+=`<circle cx="${x[0]}" cy="${x[1]}" r="3" fill="#db0a9d"/>`);v+='</svg>';el.innerHTML=v}
function plotSpeed(id,rows){let el=document.getElementById(id);if(!el)return;if(!rows||!rows.length){el.innerHTML='<div class="empty">Sin datos</div>';return}let w=490,h=185,max=Math.max(1,...rows.map(r=>Math.max(r.pre,r.now))),bottom=154,scale=125/max,bw=Math.min(20,170/rows.length),v=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Instalaciones por velocidad PRE y campaña">`;rows.forEach((r,i)=>{let x=32+(w-50)*(i+.5)/rows.length,p=r.pre*scale,n=r.now*scale;v+=`<rect x="${x-bw-2}" y="${bottom-p}" width="${bw}" height="${p}" fill="#a9d8f8"/><rect x="${x+2}" y="${bottom-n}" width="${bw}" height="${n}" fill="#177fe2"/><text x="${x}" y="177" font-size="10" text-anchor="middle">${r.speed}</text>`});v+='</svg><div class="legend"><span><i class="dot" style="background:#a9d8f8"></i>PRE equiv.</span><span><i class="dot" style="background:#177fe2"></i>Campaña</span></div>';el.innerHTML=v}
plotDistrict();plotMain();Object.keys(speeds).forEach(k=>plotSpeed('speed-'+k,speeds[k]));
</script></body></html>
