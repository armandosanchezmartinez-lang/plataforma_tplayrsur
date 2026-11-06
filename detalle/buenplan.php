<?php
/**
 * TalIA / TotalXpedient — Promociones / Buen Plan v1.4.0
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
function bp_channel($v): string {
    $v=bp_n($v);
    if($v==='' || $v==='-')return 'SIN CANAL';
    if(str_contains($v,'CALL CENTER'))return 'CALL CENTER';
    if(str_contains($v,'CAMBACEO'))return 'CAMBACEO';
    if(str_contains($v,'PUNTO DE VENTA'))return 'PUNTO DE VENTA';
    if(str_contains($v,'ECOMMERCE') || str_contains($v,'E-COMMERCE'))return 'ECOMMERCE';
    if(str_contains($v,'AUTOEMPRESARIO'))return 'AUTOEMPRESARIOS';
    if(str_contains($v,'VENTA DIGITAL'))return 'VENTA DIGITAL';
    if(str_contains($v,'WINBACK'))return 'WINBACK';
    if(str_contains($v,'DESARROLLO'))return 'DESARROLLOS';
    if(str_contains($v,'DISTRIBUIDOR'))return 'DISTRIBUIDOR';
    return $v;
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
    // Vista gerencial: TODOS los canales de ambos distritos. No se limita al universo
    // Cambaceo/PDV del Motor HC, que no representa el total comercial del distrito.
    // Clasificación canónica: catalogo_paquetes (velocidad, play, tipo), sin inferir por nombre.
    require_once __DIR__.'/../includes/motor_comercial.php';
    $comercialVersion=(string)MotorComercial::VERSION;
    $hcVersion='No aplica: vista gerencial todos los canales';
    $instCols=bp_fields($conexion,'instalaciones');
    $catCols=bp_fields($conexion,'catalogo_paquetes');
    foreach(['cuenta','fecha','plan','distrito','precio_pronto_pago'] as $f)
        if(!isset($instCols[$f]))throw new RuntimeException('Falta instalaciones.'.$f);
    foreach(['nombre_plan','velocidad_agrupada','play','tipo'] as $f)
        if(!isset($catCols[$f]))throw new RuntimeException('Falta catalogo_paquetes.'.$f);
    $fromSql=$conexion->real_escape_string($start->format('Y-m-d'));
    $toSql=$conexion->real_escape_string($lastDay);
    $idSelect=isset($instCols['id'])?'i.id AS registro_id':'NULL AS registro_id';
    $subSelect=isset($instCols['subcanal'])?'i.subcanal':'NULL AS subcanal';
    $sql="SELECT {$idSelect},i.cuenta,i.fecha,i.distrito,i.plan,i.origen_prospecto,{$subSelect},i.precio_pronto_pago AS precio_pp,
            cp.velocidad_agrupada,cp.play,cp.tipo,cp.nombre_plan AS match_catalogo
          FROM instalaciones i
          LEFT JOIN catalogo_paquetes cp
            ON UPPER(TRIM(i.plan))=UPPER(TRIM(cp.nombre_plan))
          WHERE i.fecha BETWEEN '$fromSql' AND '$toSql'
            AND UPPER(TRIM(i.distrito)) IN ('TUXTLA','VILLAHERMOSA')
            AND i.cuenta IS NOT NULL AND TRIM(i.cuenta) <> ''
          ORDER BY i.fecha,i.distrito,i.cuenta";
    $rs=$conexion->query($sql);
    if(!$rs)throw new RuntimeException('Consulta comercial: '.$conexion->error);
    // Clave día/distrito/cuenta evita multiplicar una misma instalación por
    // duplicados del importador. Conflictos distintos se auditan sin asignar plan arbitrario.
    $groups=[];
    while($r=$rs->fetch_assoc()) {
        $dist=bp_norm_district($r['distrito']??'');
        $day=substr((string)$r['fecha'],0,10);
        $account=trim((string)$r['cuenta']);
        if(!bp_date($day)||!in_array($dist,['TUXTLA','VILLAHERMOSA'],true))continue;
        $key=$dist.'|'.$day.'|'.$account;
        $groups[$key][]=$r;
    }
    foreach($groups as $key=>$rr){
        $r=$rr[0];$dist=bp_norm_district($r['distrito']);
        $day=substr((string)$r['fecha'],0,10);
        $account=trim((string)$r['cuenta']);
        if(count($rr)>1){
            $signatures=[];
            foreach($rr as $x){
                $signature=json_encode([
                    bp_n($x['plan']??''),bp_n($x['velocidad_agrupada']??''),
                    bp_n($x['play']??''),bp_n($x['tipo']??''),
                    (string)($x['precio_pp']??'')
                ],JSON_UNESCAPED_UNICODE);
                $signatures[$signature]=true;
            }
            if(count($signatures)===1){$duplicateExact++;$auditBreakdown['REPETIDA_IDENTICA']++;}
            else {
                $duplicate++;$duplicateConflict++;
                $auditRows[]=['cuenta'=>$account,'distrito'=>$dist,'motivo'=>'REGISTROS CONTRADICTORIOS',
                    'filas'=>count($rr),'detalle'=>implode(' | ',array_map(
                        static fn($x)=>substr((string)$x['fecha'],0,10).' · '.(string)$x['plan'].' · PP '.(string)$x['precio_pp'],
                        array_slice($rr,0,5)))];
                $auditBreakdown['PLAN_DIFERENTE']++;
                continue;
            }
        }
        if($r['match_catalogo']===null){$noCatalog++;continue;}
        $tipo=bp_n($r['tipo']??'');
        if($tipo!=='RESIDENCIAL')continue;
        $pl=bp_n($r['play']??'');
        $pl=str_contains($pl,'DOBLE')?'2P':(str_contains($pl,'TRIPLE')?'3P':'ND');
        if($playSel!=='AMBOS' && $pl!==$playSel)continue;
        $rawVel=str_replace([',',' '],'',bp_n((string)($r['velocidad_agrupada']??'')));
        $speed=preg_match('/^(\d+)(?:MB|MBPS|M)$/',$rawVel,$m)?(int)$m[1]:null;
        $promoPlay=bp_promo_play((string)$r['plan']);
        $isPromo=bp_is_promo($day) && $speed===250 && $promoPlay!==null && $promoPlay===$pl;
        $pp=(is_numeric($r['precio_pp']??null) && (float)$r['precio_pp']>0)?(float)$r['precio_pp']:null;
        $events[]=['day'=>$day,'dist'=>$dist,'play'=>$pl,'speed'=>$speed,'pp'=>$pp,
            'promo'=>$isPromo,'channel'=>((bp_channel($r['origen_prospecto']??'')==='CALL CENTER' && in_array(bp_n($r['subcanal']??''),['VENTA TECNICO','VENTA TÉCNICO'],true))?'VENTA TECNICO':bp_channel($r['origen_prospecto']??'')),'candidate'=>false,'price_signal'=>false,
            'price_conflict'=>false,'classified'=>($pl!=='ND'&&$speed!==null)];
    }
    // Incidencias se conservan exclusivamente en auditoría desplegable.
} catch(Throwable $e){$error=$e->getMessage();}
// La auditoría comparte exactamente el universo gerencial de las tarjetas.
$bpDiagnosticoDirecto=[];
foreach($events as $ev){
    if(!$ev['promo'])continue;
    $k=$ev['dist'].'|'.$ev['play'];
    $bpDiagnosticoDirecto[$k]=($bpDiagnosticoDirecto[$k]??0)+1;
}
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

// Tablas ejecutivas: PRE, campaña y POST, sin suponer carga completa de fechas futuras.
$phaseSummary=['PRE'=>$pre,'PROMO'=>$promo,'POST'=>$post];
$phaseArpu=[];
foreach($phaseSummary as $ph=>$r) $phaseArpu[$ph]=$r['income250_n']>0 ? $r['income250']/$r['income250_n'] : null;
$channelStats=[];
$channelPromoByDistrict=[];
foreach($events as $ev){
    if($district!=='SUR' && $ev['dist']!==$district)continue;
    $c=$ev['channel']??'SIN CANAL';$ph=bp_phase($ev['day']);
    $channelStats[$c][$ph]??=['total'=>0,'v250'=>0,'p2'=>0,'p3'=>0,'promo'=>0];
    $channelStats[$c][$ph]['total']++;
    if($ev['speed']===250){$channelStats[$c][$ph]['v250']++;if($ev['play']==='2P')$channelStats[$c][$ph]['p2']++;if($ev['play']==='3P')$channelStats[$c][$ph]['p3']++;}
    if($ev['promo']){
        $channelStats[$c][$ph]['promo']++;
        $channelPromoByDistrict[$ev['dist']][$c]=($channelPromoByDistrict[$ev['dist']][$c]??0)+1;
    }
}
$channelRows=[];
foreach($channelStats as $c=>$phases){
    $b=$phases['PRE']['v250']??0; $now=$phases['PROMO']['v250']??0;
    $channelRows[]=['label'=>$c,'pre'=>$b*$baseFactor,'now'=>$now,'promo'=>$phases['PROMO']['promo']??0,
       'p2'=>$phases['PROMO']['p2']??0,'p3'=>$phases['PROMO']['p3']??0,'growth'=>bp_delta($now,$b*$baseFactor)];
}
usort($channelRows,static fn($a,$b)=>($b['now']<=>$a['now']) ?: strcmp($a['label'],$b['label']));
$channelJson=json_encode($channelRows,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
// Datos consolidados del periodo para el seguimiento ejecutivo.
$phaseRows=[];
foreach(['PRE','PROMO','POST'] as $ph){
    $r=$phaseSummary[$ph];$phaseRows[$ph]=['total'=>$r['total'],'v250'=>$r['v250'],
     'p2'=>$r['p2_250'],'p3'=>$r['p3_250'],'promo'=>$r['promo'],
     'arpu'=>$phaseArpu[$ph],'mix'=>bp_pct($r['v250'],$r['total'])];
}
$periodLabels=['PRE'=>'Antes del 03/11','PROMO'=>'03–09 noviembre','POST'=>'Desde el 10/11'];

// Conciliación secundaria con Motor Comercial. Se utiliza su contrato gerencial
// de catálogo para los DOS distritos. El motor contabiliza registros, mientras
// esta vista deduplica día/distrito/cuenta; las diferencias quedan en auditoría.
$bpMotorAudit=['estado'=>'NO_VERIFICADO','registros'=>null,'diferencia'=>null];
if (!$error && isset($start,$lastDay)) {
    try {
        $bpMc=new MotorComercial($conexion);
        $bpMcData=$bpMc->indicadoresInstalacionesPorCatalogo(
            $start->format('Y-m-d'), $lastDay, 'completo',1,31,
            ['tipo'=>'DISTRITOS','distritos'=>['TUXTLA','VILLAHERMOSA']]
        );
        $raw=0;
        foreach(($bpMcData['datos']??[]) as $month)$raw+=(int)($month['total']??0);
        $bpMotorAudit=['estado'=>'VERIFICADO','registros'=>$raw,
            'diferencia'=>$raw-count($events)];
    } catch(Throwable $motorE) {
        $bpMotorAudit['estado']='NO_DISPONIBLE';
        error_log('Buen Plan referencia Motor Comercial: '.$motorE->getMessage());
    }
}
// Proyección de datos para la UI: mismo universo filtrado, nunca cifras de maqueta.
$bpDistrict=[];
foreach(['TUXTLA','VILLAHERMOSA'] as $name){$r=$districtComparison[$name]??[];
    $bpDistrict[]=['name'=>$name==='TUXTLA'?'Tuxtla':'Villahermosa','key'=>$name,
      'pre'=>(float)($r['pre250']??0),'now'=>(int)($r['campaign250']??0),
      'p2'=>(int)($r['promo2']??0),'p3'=>(int)($r['promo3']??0),
      'mixPre'=>$r['pre_mix']??null,'mixNow'=>$r['promo_mix']??null];
}
$bpSpeed=[];
foreach(['2P','3P','AMBOS'] as $p){$arr=[];
    foreach(array_keys($speeds) as $v){$groups=$p==='AMBOS'?['2P','3P']:[$p];$old=0;$now=0;
      foreach($groups as $g){$old+=(float)($comparablePre['speeds'][$g][$v]??0)*$baseFactor;$now+=(int)($promo['speeds'][$g][$v]??0);}
      $arr[]=['speed'=>(int)$v,'pre'=>$old,'now'=>$now];
    }$bpSpeed[$p]=$arr;
}
$bpDaily=[];
foreach($daily as $date=>$r){$bpDaily[]=['date'=>$date,'total'=>$r['total'],'v250'=>$r['v250'],'promo'=>$r['promo']];}
$bpPhases=[];
foreach(['PRE','PROMO','POST'] as $p){$r=$phaseRows[$p];$bpPhases[$p]=['total'=>$r['total'],'v250'=>$r['v250'],'promo'=>$r['promo'],
  'mix'=>$r['mix'],'arpu'=>$r['arpu']];}
$bpArpuDistrict=[];
foreach(['TUXTLA','VILLAHERMOSA'] as $name){$p=$byDistrictPhase[$name]['PRE']??bp_blank();$a=$byDistrictPhase[$name]['PROMO']??bp_blank();
 $bpArpuDistrict[]=['name'=>$name==='TUXTLA'?'Tuxtla':'Villahermosa',
 'pre'=>$p['income250_n']>0?$p['income250']/$p['income250_n']:null,
 'now'=>$a['income250_n']>0?$a['income250']/$a['income250_n']:null];}
$bpView=[
 'error'=>$error, 'cut'=>$lastDay,'start'=>isset($start)?$start->format('Y-m-d'):null,'district'=>$district,'play'=>$playSel,
 'period'=>$mode,'preWeeks'=>$preWeeks,'preMonths'=>$preMonths,'promoDays'=>$promoDays,
 'kpi'=>['p2'=>$promo2,'p3'=>$promo3,'promo'=>$promoIdentified,
  'v250'=>$promo['v250'],'growth250'=>$change250,'base250'=>$base250,
  'arpu'=>$arpuPromo,'arpuPre'=>$arpuPre,'arpuGrowth'=>$arpudelta,
  'mix'=>$sharePromo,'mixPre'=>$sharePre,'mixGrowth'=>$shareDiff,
  'total'=>$promo['total'],'adoption'=>$adoption],
 'districts'=>$bpDistrict,'districtTotal'=>$districtTotal,
 'phases'=>$bpPhases,'speed'=>$bpSpeed,'channels'=>$channelRows,
 'series'=>$chartData,'daily'=>$bpDaily,'arpuDistrict'=>$bpArpuDistrict,
 'audit'=>$auditRows,'duplicateExact'=>$duplicateExact,'noCatalog'=>$noCatalog,
 'motor'=>$bpMotorAudit,'motorVersion'=>$comercialVersion
];
function bp_json($data):string {return json_encode($data, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buen Plan | Promociones · TotalXpedient</title>
<style>

:root{--violet:#7A2BFF;--magenta:#FF006C;--navy:#1a2540;--mute:#7887a6;--line:#e1e7f2;--cyan:#00bafa;--good:#009e6b;--bad:#ea4266}*{box-sizing:border-box}body{margin:0;font:14px 'Segoe UI',Inter,Arial,sans-serif;color:var(--navy);background:radial-gradient(ellipse at 15% 9%,#f3def9 0,transparent 36%),linear-gradient(120deg,#fcf6ff,#e8f7ff 95%)}button,select,input{font:inherit}button{cursor:pointer}.app{display:flex;min-height:100vh}.sidebar{width:212px;min-width:212px;background:linear-gradient(155deg,#352043 0%,#1c193b 65%,#181937);color:#e8e1ff;padding:26px 12px;position:sticky;top:0;height:100vh;overflow:auto}.brand{display:flex;flex-direction:column;align-items:center;gap:13px;margin-bottom:40px}.brand-icon{width:90px;height:90px;display:grid;place-items:center;border-radius:27px;background:linear-gradient(135deg,#fd1fc6 0,#8028ff 55%,#00c9fb 100%);box-shadow:0 16px 40px #07102955;font-size:47px;color:white;text-shadow:0 4px 12px #10042eaa}.brand-name{letter-spacing:2.2px;font-weight:900;font-size:12px;color:white}.menuitem{display:flex;align-items:center;gap:12px;padding:14px;border-radius:17px;color:#cfc4de;font-weight:650;margin:7px 0;font-size:13px}.menuitem .mi{font-size:19px;min-width:23px}.menuitem.selected{background:linear-gradient(110deg,#ad16c580,#007ad659);border:1px solid #9c57da80;color:white}.main{width:calc(100% - 212px);max-width:1740px;margin:auto;padding:19px 24px 45px}.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:20px}.top h1{margin:0;font-size:29px;letter-spacing:-.7px;font-weight:800}.subtitle{color:#647598;font-size:14px;margin:4px 0}.user{border-radius:28px;padding:8px 18px 8px 9px;display:flex;align-items:center;gap:11px;background:#ffffffca;box-shadow:0 8px 25px #3b28601a;font-size:12px;white-space:nowrap}.avatar{display:grid;place-items:center;background:linear-gradient(135deg,#d800e1,#009fff);width:36px;height:36px;border-radius:50%;color:white;font-weight:800}.small{font-size:11px;color:var(--mute)}.filters{background:#ffffffe6;border:1px solid var(--line);border-radius:25px;padding:14px 17px;display:flex;align-items:end;gap:11px;flex-wrap:wrap;box-shadow:0 10px 23px #20234e10}.filter{display:flex;flex-direction:column;gap:6px;font-size:11px;font-weight:800;color:#586d97;min-width:125px}.filter select,.filter input{border:1px solid #d8e1f0;background:white;border-radius:13px;height:37px;padding:7px 11px;color:#152446;max-width:180px}.filter.grow{flex:1}.filter.grow input{max-width:none;width:100%}.cut{margin-right:8px;min-width:190px;border-right:1px solid var(--line);padding-right:16px}.cut strong{font-size:16px;display:block;color:#131d38;margin-top:3px}.period{display:flex;gap:4px;border-radius:999px;background:#f1f3fb;padding:3px}.period button{border:0;background:transparent;border-radius:999px;padding:10px 12px;font-weight:800;color:#29365a;font-size:12px}.period button.active,.apply{background:linear-gradient(110deg,#7A2BFF,#FF006C)!important;color:white!important;box-shadow:0 8px 20px #9128d22b}.apply{border:0;border-radius:99px;padding:11px 16px;font-weight:800}.kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px;margin:14px 0}.kpi{border-radius:21px;background:#fffdffdd;border:1px solid white;box-shadow:0 10px 22px #25244a14;padding:16px 15px;min-height:141px}.kpi:nth-child(odd){background:linear-gradient(150deg,#fff9fd,#fbeefb)}.kpi:nth-child(even){background:linear-gradient(150deg,#fdfdff,#eaf7ff)}.kpi .head{display:flex;align-items:center;gap:11px;font-size:13px;font-weight:700}.glyph{border-radius:13px;background:#f5e3ff;color:#9426d0;width:38px;height:38px;display:grid;place-items:center;font-size:20px}.kpi:nth-child(even) .glyph{background:#e0f2ff;color:#187be9}.metric{font-size:32px;font-weight:850;margin:4px 0 0 52px;letter-spacing:-1px}.positive{color:var(--good)}.negative{color:var(--bad)}.kpi .note{font-size:12px;color:#506184;margin:0 0 0 52px}.tabs{display:flex;gap:4px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#ffffffd7;border:1px solid white;border-radius:20px;padding:6px;box-shadow:0 8px 18px #2420470d;margin:16px 0}.tabs button{border:0;border-radius:16px;background:transparent;color:#322253;padding:11px 15px;font-weight:750;font-size:12px;white-space:nowrap}.tabs button.active{background:linear-gradient(120deg,#8525e6,#d900bd);color:white}.grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.panel{min-width:0;background:#ffffffeb;border:1px solid #dde3f0;border-radius:22px;padding:18px 19px;box-shadow:0 9px 24px #27234e11;margin-bottom:13px}.panel h3{margin:0;font-size:16px;font-weight:800}.panel .desc{color:#70819e;font-size:12px;margin:4px 0 14px}.span2{grid-column:span 2}.titlebar{border-left:5px solid #ae18e7;padding-left:12px;margin:22px 0 12px;font-size:19px;font-weight:850}.table{width:100%;border-collapse:collapse;text-align:right;font-size:12px}.table th{background:#eaf0fb;color:#354a71;font-size:11px;padding:12px 8px}.table td{border-bottom:1px solid #e7eaf2;padding:12px 8px}.table th:first-child,.table td:first-child{text-align:left}.table tr:last-child td{font-weight:800;background:#f5effc}.hbar{height:12px;border-radius:999px;background:#e8effa;overflow:hidden}.hbar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#7A2BFF,#00cbef)}.legend{font-size:12px;color:#586b8b;display:flex;gap:16px;flex-wrap:wrap;margin-top:12px}.swatch{display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:5px}.chart{width:100%;height:240px;overflow:hidden}.chart svg{width:100%;height:100%}.step{display:grid;grid-template-columns:repeat(3,1fr);gap:11px}.phase{border:1px solid #e0e6f0;border-radius:18px;padding:16px;background:linear-gradient(130deg,#f5faff,#fff)}.phase:nth-child(2){background:linear-gradient(125deg,#fdf1fd,#fff)}.phase b{color:#7920df}.phase .number{font-size:27px;font-weight:850;margin-top:9px}.phase .sub{font-size:11px;color:#607392}.notsourced{padding:20px;border-radius:15px;background:#f5f7fc;color:#61718e;text-align:center}.audit{font-size:12px;color:#65728d;margin:14px 0 0;padding:11px 14px;border-radius:12px;background:#f5f8ff}.pill{display:inline-flex;font-size:10px;font-weight:800;color:#8f20cd;background:#f5e9ff;padding:5px 9px;border-radius:99px;margin-left:9px}.footer{font-size:11px;color:#71809a;margin:12px 0}.hide{display:none!important}button:focus-visible,select:focus-visible,input:focus-visible{outline:3px solid #a560f5;outline-offset:2px}@media(max-width:1200px){.kpis{grid-template-columns:repeat(3,1fr)}.sidebar{width:175px;min-width:175px}.main{width:calc(100% - 175px);padding:18px}.tabs{justify-content:flex-start}}@media(max-width:780px){.sidebar{display:none}.main{width:100%;padding:13px}.top h1{font-size:23px}.user{display:none}.kpis{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.span2{grid-column:auto}.step{grid-template-columns:1fr}.filters{gap:8px}.cut{border:0}.tabs button{padding:9px 11px}}

/* Ajustes para versión conectada: los estilos base provienen del último prototipo aprobado. */
.menuitem a{color:inherit;text-decoration:none;display:flex;align-items:center;gap:12px;width:100%}
.menuitem.selected{background:linear-gradient(110deg,#7a228e,#182e64)}
.tabs button{cursor:pointer}.apply,.period button{cursor:pointer}
.scrolltable{overflow-x:auto}.bp-value{color:#7630d8;font-size:24px;font-weight:850}
.norecord{padding:18px;color:#6b7991;background:#f5f7fc;border-radius:14px;text-align:center}
.panels3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.panels3 .panel{margin-bottom:0}
.auditwrap{margin-top:18px}.auditwrap summary{cursor:pointer;font-weight:800;padding:8px}
.status{font-size:11px;color:#677893;padding:8px 0}
@media(max-width:1050px){.panels3{grid-template-columns:1fr}}

/* v1.5.1 · Homologación visual al prototipo aprobado de TalIA TotalXpedient. */
:root { --navy:#171735; --mute:#7986a3; --line:#e3e3f1; --violet:#9c19f2; --magenta:#dd08bb; }
body { font-family: 'Poppins','Segoe UI',Arial,sans-serif; font-size:14px; color:#161b3a;
 background: radial-gradient(ellipse at 15% 42%, rgba(246,201,247,.62) 0,transparent 43%),linear-gradient(115deg,#f8f1fe 0%,#eef6ff 96%); }
.sidebar { width:194px; min-width:194px; background:linear-gradient(165deg,#38213f,#21193d 62%,#181936); padding:24px 11px; }
.brand { margin-bottom:32px; gap:16px; }
.brand-icon { width:88px;height:88px;border-radius:25px;font-size:22px;position:relative;background:linear-gradient(135deg,#f528ca 4%,#8225ed 57%,#00c6ff); }
.bp-logo-stack { display:grid;place-items:center;width:45px;height:42px;border:2px solid #efceff;border-radius:7px;background:linear-gradient(135deg,#ad39e7aa,#009cfab9);box-shadow:9px 7px 0 #5729d488,-7px -4px 0 #e323d399;transform:translate(-2px,-2px) }
.bp-logo-cube {color:white;font-size:20px;text-shadow:0 1px 4px #1b124c}
.brand-name {font-size:12px;letter-spacing:2px;font-weight:900}
.menuitem { font-size:13px; min-height:44px; margin:5px 0; font-weight:650; border-radius:16px;padding:11px 13px; }
.menuitem.selected {border:1px solid #7857ce7b;background:linear-gradient(110deg,#9b258d96,#00508a70)}
.main { width:calc(100% - 194px);max-width:none;padding:16px 25px 44px; }
.top {margin-bottom:15px;align-items:center}.top h1 {font-size:29px;font-weight:800;letter-spacing:-.8px;line-height:1.15}.subtitle{font-size:14px;color:#667697;margin-top:6px}
.user{padding:9px 17px 9px 9px;font-size:12px;border-radius:35px;max-width:340px}.user b{display:block;max-width:255px;overflow:hidden;text-overflow:ellipsis}.avatar{width:38px;height:38px;font-size:16px}
.filters { border-radius:22px; padding:14px 16px;gap:9px;box-shadow:0 8px 28px #291e5612;min-height:82px; }
.filter {min-width:102px;font-size:10px;letter-spacing:.15px;gap:5px}.filter select,.filter input { height:39px; font-size:12px; font-weight:650; border-radius:12px; max-width:210px }
.cut{min-width:182px}.cut strong{font-size:17px}.period button{font-size:12px}.apply{padding:12px 18px;white-space:nowrap}
.kpis {gap:11px;margin:14px 0 17px;grid-template-columns:repeat(5,minmax(0,1fr))}
.kpi{border-radius:22px;min-height:137px;padding:17px 14px;display:flex;flex-direction:column;justify-content:center;box-shadow:0 8px 21px #4c316018;border:1px solid #ffffffea}
.kpi .head{font-size:13px;line-height:1.25;gap:12px;font-weight:750}
.kpi .glyph {width:44px;min-width:44px;height:44px;border-radius:17px;font-size:24px;background:#f4dcff;color:#a025da}
.metric{font-size:33px;letter-spacing:-.8px;line-height:1.14;margin:4px 0 2px 55px;font-weight:850}
.kpi .note{margin:0 0 0 55px;font-size:12px;line-height:1.4;color:#45577a}
.tabs{border-radius:23px;min-height:48px;padding:5px;margin:15px 0 19px;gap:2px}.tabs button{border-radius:18px;padding:12px 13px;font-size:12px;letter-spacing:0;font-weight:750}.tabs button.active{background:linear-gradient(100deg,#9821ed,#db0abf);box-shadow:0 6px 13px #aa2bce22}
.grid{gap:14px}.grid.panorama-grid{grid-template-columns:repeat(6,minmax(0,1fr));gap:14px}
.panorama-grid>.panel{grid-column:span 2}.panorama-grid>.panel:nth-child(4),.panorama-grid>.panel:nth-child(5){grid-column:span 3}
.panel {border:1px solid #e0dfef;border-radius:21px;background:#ffffffee;padding:18px 20px;box-shadow:0 8px 25px #39275310;min-height:0;margin-bottom:0}
.panel h3{font-size:17px;font-weight:800;letter-spacing:-.25px;line-height:1.23}.panel .desc{font-size:12px;margin:4px 0 13px}
.panorama-grid>.panel:nth-child(-n+3) {min-height:265px}.panorama-grid>.panel:nth-child(n+4){min-height:240px}
.chart{height:205px;overflow:visible}.chart svg{display:block;width:100%;height:100%;overflow:visible}
.legend {font-size:11px;gap:11px;margin-top:8px}.table{font-size:12px}.table th{background:#eeedfa;color:#28345d;font-size:11px;padding:10px 7px}.table td{padding:10px 7px}.table tr:last-child td{background:#f0e3fa}
.phase{padding:14px;min-height:132px;border-radius:16px}.phase .number{font-size:26px}.step{gap:9px}
.bp-donut-wrap{display:flex;align-items:center;gap:18px;justify-content:space-evenly;min-height:176px}.bp-donut{width:165px;min-width:130px;aspect-ratio:1;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#9e22e5 0 var(--bp-fill),#ece5f7 var(--bp-fill) 100%);position:relative}.bp-donut:before{content:'';position:absolute;inset:22%;border-radius:50%;background:white}.bp-donut-center{z-index:1;text-align:center;font-size:22px;font-weight:850;line-height:1.1}.bp-donut-center small{font-size:11px;font-weight:700}.bp-donut-list{flex:1;max-width:210px;font-size:12px}.bp-donut-row{display:flex;justify-content:space-between;gap:9px;padding:9px 0;border-bottom:1px solid #eceaf5}.bp-donut-row:last-child{border:0}.bp-dot{height:10px;width:10px;border-radius:50%;display:inline-block;background:#ae21e8;margin-right:6px}
.bp-line-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:#5e6b87;padding-top:7px}.bp-line-legend i{height:10px;width:10px;display:inline-block;border-radius:3px;margin-right:4px}
@media(max-width:1380px){.main{padding:16px}.kpi .head{font-size:12px}.kpi .note{font-size:11px}.kpi .glyph{width:38px;height:38px;min-width:38px}.metric,.kpi .note{margin-left:50px}.tabs button{font-size:11px;padding:11px 8px}.bp-donut{width:130px}}
@media(max-width:1100px){.sidebar{width:174px;min-width:174px}.main{width:calc(100% - 174px)}.grid.panorama-grid{grid-template-columns:1fr 1fr}.panorama-grid>.panel,.panorama-grid>.panel:nth-child(4),.panorama-grid>.panel:nth-child(5){grid-column:auto}.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:780px){.sidebar{display:none}.main{width:100%;padding:12px}.top h1{font-size:23px}.kpis{grid-template-columns:repeat(2,1fr)}.grid.panorama-grid{grid-template-columns:1fr}.panorama-grid>.panel,.panorama-grid>.panel:nth-child(4),.panorama-grid>.panel:nth-child(5){grid-column:auto}.bp-donut-wrap{flex-wrap:wrap}}
</style></head>
<body><div class="app">
<aside class="sidebar">
 <div class="brand"><div class="brand-icon"><span class="bp-logo-stack"><span class="bp-logo-cube">▶</span></span></div><div class="brand-name">TOTALXPEDIENT</div></div>
 <div class="menuitem"><a href="../index.php"><span class="mi">▦</span> Dashboard</a></div>
 <div class="menuitem"><span class="mi">🏆</span> Ranking</div>
 <div class="menuitem"><span class="mi">◉</span> Pulso</div>
 <div class="menuitem"><span class="mi">📈</span> Adiciones Netas</div>
 <div class="menuitem"><span class="mi">♟</span> Headcount</div>
 <div class="menuitem"><span class="mi">▤</span> REAI</div>
 <div class="menuitem"><span class="mi">🎯</span> Captura FCST</div>
 <div class="menuitem"><span class="mi">🚀</span> Ejecución Operativa</div>
 <div class="menuitem selected"><span class="mi">🟪</span> Promociones</div>
</aside>
<main class="main">
<header class="top"><div><h1>Promociones | Buen Plan</h1><div class="subtitle">Región SUR · Tuxtla + Villahermosa · 250 Mbps + Netflix Estándar con anuncios</div></div>
<div class="user"><span class="avatar"><?=bp_h(mb_substr((string)($_SESSION['usuario']??'T'),0,1))?></span><div><b><?=bp_h((string)($_SESSION['nombre_completo']??$_SESSION['usuario']??'TalIA Xpedient'))?></b><div class="small"><?=bp_h($rol==='admin'?'Administrador':'Dirección Regional')?></div></div></div></header>
<form method="get" class="filters" id="filterForm">
<div class="cut"><div class="small" style="font-weight:800">CORTE OPERATIVO</div><strong><?=bp_h($lastDay?date('d/m/Y',strtotime($lastDay)):'Sin corte')?></strong></div>
<label class="filter">DISTRITO<select name="distrito" id="district"><?php foreach(['SUR'=>'SUR participante','TUXTLA'=>'Tuxtla','VILLAHERMOSA'=>'Villahermosa'] as $key=>$text):?><option value="<?=$key?>" <?=$district===$key?'selected':''?>><?=$text?></option><?php endforeach;?></select></label>
<label class="filter">PLAY<select name="play" id="play"><?php foreach(['AMBOS'=>'2P + 3P','2P'=>'2P','3P'=>'3P'] as $key=>$text):?><option value="<?=$key?>" <?=$playSel===$key?'selected':''?>><?=$text?></option><?php endforeach;?></select></label>
<label class="filter">SEMANAS PRE<select name="pre_semanas" id="pre"><?php for($i=1;$i<=12;$i++):?><option value="<?=$i?>" <?=$preWeeks===$i?'selected':''?>><?=$i?> semana<?=$i===1?'':'s'?></option><?php endfor;?></select></label>
<label class="filter">MESES PRE<input type="number" name="pre_meses" min="1" max="6" value="<?=$preMonths?>" style="width:70px"></label>
<input type="hidden" name="periodo" id="periodInput" value="<?=bp_h($mode)?>">
<div class="filter"><div>PERÍODO</div><div class="period"><?php foreach(['diario'=>'Diario','semanal'=>'Semanal','mensual'=>'Mensual'] as $key=>$text):?><button type="button" class="<?=$mode===$key?'active':''?>" data-period="<?=$key?>"><?=$text?></button><?php endforeach;?></div></div>
<label class="filter grow">FECHA DE CORTE<input id="cutoff" name="corte" type="date" value="<?=bp_h($lastDay??'')?>" max="<?=bp_h($lastDay??'')?>"></label>
<button type="submit" class="apply">Aplicar filtros</button>
</form>
<?php if($error): ?><div class="panel" style="border-color:#eeabc0"><h3>No se pudieron cargar los indicadores</h3><p><?=bp_h($error)?></p><p class="small">No se muestran métricas simuladas.</p></div><?php else: ?>
<div class="kpis" id="kpis"></div>
<nav class="tabs" aria-label="Secciones de Buen Plan">
<button type="button" data-tab="panorama" class="active">▥ &nbsp; Panorama</button>
<button type="button" data-tab="distritos">♙ &nbsp; Distrito y Región</button>
<button type="button" data-tab="prepost">◷ &nbsp; PRE–PROMO–POST</button>
<button type="button" data-tab="arpu">＄ &nbsp; ARPU</button>
<button type="button" data-tab="velocidades">ϟ &nbsp; Velocidad</button>
<button type="button" data-tab="canales">♟ &nbsp; Canal de venta</button>
<button type="button" data-tab="evolucion">⌁ &nbsp; Evolución</button>
</nav>
<div id="content"></div>
<details class="panel auditwrap"><summary>Auditoría y conciliación de datos</summary>
<div class="status">Motor Comercial <?=bp_h($comercialVersion)?> · estado <?=bp_h($bpMotorAudit['estado'])?> · registros fuente <?=bp_fmt($bpMotorAudit['registros'])?> · diferencia contra cuentas únicas <?=bp_fmt($bpMotorAudit['diferencia'])?>. El motor cuenta registros y esta página deduplica día/distrito/cuenta; el conteo puede variar también por exclusiones de segmento, canal y catálogo.</div>
<div class="status">Registros idénticos consolidados: <?=bp_fmt($duplicateExact)?> · paquetes sin catálogo: <?=bp_fmt($noCatalog)?> · conflictos excluidos: <?=bp_fmt(count($auditRows))?>.</div>
<div class="scrolltable"><table class="table"><thead><tr><th>Cuenta</th><th>Distrito</th><th>Motivo</th><th>Filas</th><th>Detalle</th></tr></thead><tbody>
<?php if(!$auditRows):?><tr><td colspan="5">Sin conflictos que requieran revisión.</td></tr><?php else: foreach($auditRows as $r):?><tr><td><?=bp_h($r['cuenta'])?></td><td><?=bp_h($r['distrito'])?></td><td><?=bp_h($r['motivo'])?></td><td><?=bp_fmt($r['filas'])?></td><td><?=bp_h($r['detalle'])?></td></tr><?php endforeach;endif;?></tbody></table></div></details>
<div class="footer">TotalXpedient · Buen Plan v1.5.1 · Instalaciones comerciales de todos los canales de Tuxtla y Villahermosa. Catálogo oficial de paquetes para velocidad, Play y tipo. Promoción por nombre exacto del paquete VELOZ Netflix (2P/3P), del 03 al 09/11/2026. ARPU basado en precio pronto pago histórico válido, sin dividir IVA. POST solo con fechas realmente cargadas. Reporte de instalaciones, no activaciones de Netflix.</div>
<?php endif;?></main></div>
<script>
const D=<?=bp_json($bpView)?>;
if(!D.error){
const fmt=(v,d=0)=>v==null?'ND':Number(v).toLocaleString('es-MX',{minimumFractionDigits:d,maximumFractionDigits:d});
const usd=v=>v==null?'ND':'$'+fmt(v,2);
const pct=(a,b)=>b>0?(a/b-1)*100:null;
const showpct=(v,unit='%')=>v==null?'ND':(v>0?'+':'')+fmt(v,1)+unit;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const metric=(label,value,desc,ico,positive=false)=>`<div class="kpi"><div class="head"><span class="glyph">${ico}</span><span>${label}</span></div><div class="metric ${positive?'positive':''}">${value}</div><div class="note">${desc}</div></div>`;
const panel=(title,desc,content,wide=false)=>`<article class="panel ${wide?'span2':''}"><h3>${title}</h3><p class="desc">${desc}</p>${content}</article>`;
const chartmsg=msg=>`<div class="norecord">${msg}</div>`;
const color=(v)=>v==null?'':v<0?'negative':'positive';
const tabler=(headers,rows)=>`<div class="scrolltable"><table class="table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(r=>`<tr>${r.map(c=>`<td>${c}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}">Sin datos para este corte</td></tr>`}</tbody></table></div>`;
const bargroups=(rows)=>{
 const w=550,h=240,bottom=192,top=22,max=Math.max(1,...rows.flatMap(r=>[r.pre,r.now]));
 const gap=(w-70)/Math.max(rows.length,1),bw=Math.min(32,gap*.24);
 let svg=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Comparativo PRE y promoción">`;
 for(let i=0;i<=4;i++){const y=bottom-i*160/4;svg+=`<line x1="32" y1="${y}" x2="${w-18}" y2="${y}" stroke="#e8edf7"/><text x="25" y="${y+4}" font-size="11" text-anchor="end" fill="#8b94aa">${fmt(max*i/4,1)}</text>`;}
 rows.forEach((r,i)=>{let x=44+gap*(i+.5),hp=r.pre/max*155,hn=r.now/max*155;
 svg+=`<rect x="${x-bw-3}" y="${bottom-hp}" width="${bw}" height="${hp}" rx="4" fill="#9bd5fc"/><rect x="${x+3}" y="${bottom-hn}" width="${bw}" height="${hn}" rx="4" fill="#277bed"/><text x="${x-bw/2-3}" y="${bottom-hp-9}" font-size="12" font-weight="700" text-anchor="middle" fill="#253759">${fmt(r.pre,2)}</text><text x="${x+bw/2+3}" y="${bottom-hn-9}" font-size="12" font-weight="700" text-anchor="middle" fill="#253759">${fmt(r.now)}</text><text x="${x}" y="${bottom+24}" font-size="12" text-anchor="middle" fill="#263253">${esc(r.name)}</text>`;
 });return `<div class="chart">${svg}</svg></div><div class="legend"><span><i class="swatch" style="background:#9bd5fc"></i>PRE equivalente</span><span><i class="swatch" style="background:#277bed"></i>Campaña</span></div>`;
};
const lined=(rows,field='promo',title='Evolución')=>{
 if(!rows.length)return chartmsg('Sin instalaciones para la serie seleccionada');
 const w=710,h=250,l=48,r=22,t=28,b=43,inner=w-l-r,hh=h-t-b;
 const max=Math.max(1,...rows.map(x=>Number(x[field]||0))),pts=rows.map((a,i)=>[l+(rows.length===1?inner/2:i*inner/(rows.length-1)),t+hh*(1-Number(a[field]||0)/max)]);
 let svg=`<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="${esc(title)}"><line x1="${l}" y1="${h-b}" x2="${w-r}" y2="${h-b}" stroke="#d5e0f0"/>`;
 rows.forEach((a,i)=>{let x=pts[i][0];if(a.phase==='PROMO')svg+=`<rect x="${Math.max(l,x-8)}" y="${t}" width="16" height="${hh}" fill="#f6d8f6" opacity=".7"/>`;});
 svg+=`<polyline fill="none" stroke="#aa25e9" stroke-width="3.3" points="${pts.map(p=>p.join(',')).join(' ')}"/>`;
 pts.forEach((p,i)=>{svg+=`<circle cx="${p[0]}" cy="${p[1]}" r="4.5" fill="#a126df"/><text x="${p[0]}" y="${Math.max(17,p[1]-10)}" font-size="11" fill="#3b2a63" text-anchor="middle">${fmt(rows[i][field]||0)}</text>`;
 if(rows.length<=17||i%Math.ceil(rows.length/17)===0)svg+=`<text x="${p[0]}" y="${h-15}" font-size="10" text-anchor="middle" fill="#556783">${esc(rows[i].label||rows[i].date?.slice(5)||'')}</text>`;});
 return `<div class="chart">${svg}</svg></div>`;
};
const districtTable=()=>{
 const rs=D.districts.map(r=>[esc(r.name),fmt(r.pre,2),fmt(r.now),showpct(pct(r.now,r.pre)),fmt(r.p2),fmt(r.p3),`<b>${fmt(r.p2+r.p3)}</b>`]);
 const tot=D.districtTotal;rs.push(['<b>Región participante</b>',fmt(tot.pre250,2),fmt(tot.campaign250),showpct(pct(tot.campaign250,tot.pre250)),fmt(tot.promo2),fmt(tot.promo3),`<b>${fmt(tot.promo)}</b>`]);
 return tabler(['Distrito','PRE 250','Campaña 250','Variación','Buen Plan 2P','Buen Plan 3P','Total BP'],rs);
};
const promoTable=()=>tabler(['Distrito','Buen Plan 2P','Buen Plan 3P','Total'],[...D.districts.map(r=>[esc(r.name),fmt(r.p2),fmt(r.p3),fmt(r.p2+r.p3)]),['Región participante',fmt(D.districtTotal.promo2),fmt(D.districtTotal.promo3),fmt(D.districtTotal.promo)]]);
const phases=()=>`<div class="step">${['PRE','PROMO','POST'].map(k=>{let p=D.phases[k];return `<div class="phase"><b>${k}</b><div class="number">${k==='POST'&&D.cut<'2026-11-10'?'—':fmt(p.v250)}</div><div class="sub">Instalaciones 250 Mbps · ${k==='PRE'?'ventana histórica (sin prorratear)':k==='PROMO'?'03–09 nov al corte':'desde 10 nov'}<div>Mix: ${p.mix==null?'ND':fmt(p.mix,1)+'%'}</div><div>ARPU: ${usd(p.arpu)}</div></div></div>`}).join('')}</div>`;
const arpuSvg=()=>{
 let vals=[D.kpi.arpuPre,D.kpi.arpu],w=530,h=225,lo=Math.min(...vals.filter(x=>x!=null),600)-10,hi=Math.max(...vals.filter(x=>x!=null),700)+10;
 if(!vals.some(v=>v!=null))return chartmsg('Sin pronto pago válido para ARPU');
 if(hi<=lo)hi=lo+20;
 let points=vals.map((v,i)=>[130+i*275,v==null?null:179-(v-lo)/(hi-lo)*125]);
 let path=points.every(p=>p[1]!==null)?`<path d="M${points[0].join(' ')} L${points[1].join(' ')}" stroke="#af22df" stroke-width="4" fill="none"/>`:'';
 return `<div class="chart"><svg viewBox="0 0 ${w} ${h}"><line x1="65" y1="180" x2="480" y2="180" stroke="#ccd6e4"/>${path}${points.map((p,i)=>p[1]===null?'':`<circle cx="${p[0]}" cy="${p[1]}" r="6" fill="white" stroke="#a127e0" stroke-width="3"/><text x="${p[0]}" y="${p[1]-15}" font-size="14" text-anchor="middle" font-weight="800">${usd(vals[i])}</text>`).join('')}<text x="130" y="210" text-anchor="middle">PRE</text><text x="405" y="210" text-anchor="middle">PROMO</text></svg></div>`;
};
const velocity=()=>{
 const cols=D.play==='AMBOS'?['2P','3P','AMBOS']:[D.play,'AMBOS'];
 return `<div class="panels3">${cols.map(p=>{
 let rs=D.speed[p]||[],total=rs.reduce((x,r)=>x+r.now,0),rows=rs.map(r=>[`${fmt(r.speed)} Mbps`,fmt(r.pre,2),fmt(r.now),showpct(pct(r.now,r.pre)),r.now===0||total===0?'0.0%':fmt(r.now/total*100,1)+'%']);
 return panel(`Velocidades · ${p==='AMBOS'?'2P + 3P':p}`,'PRE homologado a días comparables',tabler(['Velocidad','PRE','Campaña','Δ %','Mix'],rows)+bargroups(rs.map(r=>({name:r.speed+'',pre:r.pre,now:r.now}))))
 }).join('')}</div>`;
};
const channels=()=>{
 const rs=D.channels||[];const labels=rs.map(r=>r.label),rows=rs.map(r=>[esc(r.label),fmt(r.pre,2),fmt(r.now),showpct(r.growth),fmt(r.p2),fmt(r.p3),fmt(r.promo)]);
 return `<div class="grid">${panel('Comportamiento por canal de venta','250 Mbps residencial · PRE comparable vs promoción',tabler(['Canal','PRE','Campaña','Δ %','2P','3P','Buen Plan'],rows))}${panel('Distribución de 250 Mbps por canal','Instalaciones de campaña y paquetes Buen Plan',rs.length?rs.map(r=>`<div style="margin:14px 0"><b>${esc(r.label)}</b> <span style="float:right">${fmt(r.now)} / BP ${fmt(r.promo)}</span><div class="hbar"><span style="width:${Math.round(r.now/Math.max(1,...rs.map(x=>x.now))*100)}%"></span></div></div>`).join(''):chartmsg('Sin instalaciones por canal'))}</div>`;
};
const evolution=()=>{
 const rows=D.series.map(r=>({...r,label:r.label}));
 return `<div class="grid">${panel('Evolución de Buen Plan','Paquetes VELOZ Netflix · '+esc(D.period),lined(rows,'promo','Paquetes Buen Plan'))}${panel('Evolución 250 Mbps','Instalaciones residenciales de 250 Mbps',lined(rows,'v250','250 Mbps'))}${panel('Residencial total','Todas las velocidades y Plays del filtro seleccionado',lined(rows,'total','Residencial total'),true)}</div>`;
};
const arpuDistrict=()=>tabler(['Distrito','ARPU PRE','ARPU PROMO','Var.'],D.arpuDistrict.map(r=>[esc(r.name),usd(r.pre),usd(r.now),showpct(r.now!=null&&r.pre!=null?pct(r.now,r.pre):null)]));

/* Panorama institucional: gráficos compactos del prototipo, sin cifras simuladas. */
const bpDonut=()=>{
 const speeds=(D.speed.AMBOS||[]).filter(r=>r.now>0);
 const n=speeds.reduce((x,r)=>x+Number(r.now||0),0);
 const first=speeds[0]||null,colors=['#a323e6','#7f62ed','#3b9eff','#92c0f7','#d4c2f5'];
 let sum=0;const arc=speeds.map((r,i)=>{const from=sum;sum+=r.now/n*100;return `${colors[i%colors.length]} ${from}% ${sum}%`;}).join(',');
 const fill=n?`conic-gradient(${arc})`:'conic-gradient(#ebe8f3 0 100%)';
 return `<div class="bp-donut-wrap"><div class="bp-donut" style="background:${fill}"><div class="bp-donut-center">${n?fmt(n):'—'}<br><small>Instalaciones</small></div></div><div class="bp-donut-list">${speeds.length?speeds.slice(0,5).map((r,i)=>`<div class="bp-donut-row"><span><i class="bp-dot" style="background:${colors[i%colors.length]}"></i>${fmt(r.speed)} Mbps</span><b>${fmt(r.now)} · ${fmt(r.now/n*100,1)}%</b></div>`).join(''):'<div class="small">Sin instalaciones en el corte</div>'}</div></div>`;
};
const bpChannelCompact=()=>{
 const list=D.channels.filter(r=>r.now>0||r.promo>0).slice(0,5);
 if(!list.length)return chartmsg('Sin registros por canal al corte');
 return tabler(['Canal','Inst. 250','Buen Plan','Mix BP'],list.map(r=>[esc(r.label),fmt(r.now),fmt(r.promo),r.now>0?fmt(r.promo/r.now*100,1)+'%':'—']));
};
const bpEvoCompact=()=>{
 const a=D.daily.filter(r=>r.date>='2026-11-03'&&r.date<=D.cut).map(r=>({label:r.date.slice(8)+'/'+r.date.slice(5,7),date:r.date,promo:r.promo}));
 if(!a.length)return chartmsg('No hay instalaciones en el periodo PROMO al corte');
 return lined(a,'promo','Evolución de Buen Plan en campaña');
};
const bpArpuChange=()=>{
 const v=D.kpi.arpuGrowth;
 return arpuSvg()+(v==null?'':`<div class="bp-line-legend"><span><i style="background:#a125df"></i>ARPU PRE</span><span>Variación: <b class="${color(v)}">${showpct(v)}</b></span></div>`);
};

const panorama=()=>`<div class="grid panorama-grid">
 ${panel('Comportamiento por distrito','Instalaciones 250 Mbps · PRE vs campaña',bargroups(D.districts))}
 ${panel('Evolución del ARPU (Precio Pronto Pago)','250 Mbps · Región participante',bpArpuChange())}
 ${panel('Instalaciones por velocidad','Mix comercial residencial · 2P + 3P',bpDonut())}
 ${panel('Desempeño por canal de venta','250 Mbps · instalaciones de campaña',bpChannelCompact())}
 ${panel('Evolución de las instalaciones','Paquetes Buen Plan · 03 al 09 de noviembre',bpEvoCompact())}
 </div>`;
const render=()=>{
 const k=D.kpi;
 document.getElementById('kpis').innerHTML=metric('Instalaciones 250 Mbps',showpct(k.growth250),`${fmt(k.v250)} vs ${fmt(k.base250,2)} PRE comparable`,'◉',k.growth250>=0)+
 metric('ARPU (PP)',showpct(k.arpuGrowth),`${usd(k.arpu)} vs ${usd(k.arpuPre)}`,'♕',k.arpuGrowth>=0)+
 metric('Buen Plan 2P',fmt(k.p2),'VELOZ Netflix STD con anuncios','◇')+
 metric('Buen Plan 3P',fmt(k.p3),'VELOZ Netflix STD con anuncios TV','▣')+
 metric('Total Buen Plan',fmt(k.promo),`${fmt(k.adoption,1)}% de las instalaciones 250 Mbps`,'◇');
 const views={
 panorama:panorama,
 distritos:()=>`<div class="grid">${panel('Comportamiento por distrito y región','250 Mbps · PRE vs campaña',districtTable())}${panel('Comparativo gráfico','Volumen comparable',bargroups(D.districts))}${panel('Promoción Buen Plan','Adopción de 2P y 3P',promoTable(),true)}</div>`,
 prepost:()=>`<div class="grid">${panel('Periodos PRE · PROMO · POST','250 Mbps residencial · PRE histórico vs PROMO parcial',phases(),true)}${panel('PRE frente a promoción','Comparación homologada al número de días transcurridos',tabler(['Indicador','PRE comparable','PROMO','Δ'],[['Instalaciones 250 Mbps',fmt(k.base250,2),fmt(k.v250),showpct(k.growth250)],['ARPU PP 250',usd(k.arpuPre),usd(k.arpu),showpct(k.arpuGrowth)],['Mix 250',fmt(k.mixPre,1)+'%',fmt(k.mix,1)+'%',showpct(k.mixGrowth,' pp')]]))}${panel('Detalle de promoción','Instalaciones identificadas por paquete y distrito',promoTable())}</div>`,
 arpu:()=>`<div class="grid">${panel('Comportamiento del ARPU','Precio pronto pago por instalación válida',arpuSvg())}${panel('ARPU por distrito','Tuxtla y Villahermosa',arpuDistrict())}${panel('ARPU por etapa','PRE · PROMO · POST',tabler(['Etapa','ARPU PP','Instalaciones 250'],['PRE','PROMO','POST'].map(key=>[key,usd(D.phases[key].arpu),fmt(D.phases[key].v250)])),true)}</div>`,
 velocidades:velocity,canales:channels,evolucion:evolution
 };
 document.getElementById('content').innerHTML=(views[activeTab]||views.panorama)();
};
let activeTab=new URLSearchParams(location.search).get('vista')||'panorama';
if(!['panorama','distritos','prepost','arpu','velocidades','canales','evolucion'].includes(activeTab))activeTab='panorama';
const updateNav=()=>document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab===activeTab));
document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{activeTab=b.dataset.tab;history.replaceState({},'',location.pathname+'?'+new URLSearchParams([...new URLSearchParams(location.search).entries()].filter(([k])=>k!=='vista').concat([['vista',activeTab]])).toString());updateNav();render()}));
updateNav();render();
}
document.querySelectorAll('[data-period]').forEach(b=>b.addEventListener('click',()=>{document.querySelector('#periodInput').value=b.dataset.period;document.querySelectorAll('[data-period]').forEach(x=>x.classList.toggle('active',x===b));document.getElementById('filterForm').requestSubmit()}));
</script></body></html>
