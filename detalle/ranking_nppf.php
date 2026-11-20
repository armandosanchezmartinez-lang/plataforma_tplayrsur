<?php
/**
 * TotalXpedient / TalIA - Ranking NPPF v1.1.2 (2026-11-20)
 * Ruta prevista: /plataforma/detalle/ranking_nppf.php
 * Dependencias: /plataforma/conexion.php, /plataforma/includes/motor_hc.php,
 *               /plataforma/includes/sidebar.php, /plataforma/assets/css/xpedient-v2.css
 * Lectura pura. No modifica las seis tablas NPPF ni el Motor HC.
 *
 * REGLAS v1.1.0 — ESCALERA DE COSECHAS:
 * - Semana/mes visible es PERIODO DE REFERENCIA; instalación M1 = exactamente
 *   los mismos días calendario del mes previo, M2 = dos meses y M3 = tres.
 *   Ej.: semana 47, 16-22 NOV -> M1 16-22 OCT, M2 16-22 SEP, M3 16-22 AGO.
 * - M2/M3 = primera factura AUN pendiente de esas cosechas, NO impago de
 *   segunda/tercera factura ni mediciones históricas al cierre de esos meses.
 * - Corte operativo M1 de la ESCALERA: mismo día de FECHA_ACTIVACION en
 *   el mes siguiente (sin los 5 días de precio de lista). Si fecha de
 *   evaluación >= corte mensual, M1; de lo contrario, PREVENTIVO M1.
 *   Semana 47 al 20-NOV: 16-20 OCT -> M1, 21-22 OCT -> PREVENTIVO.
 * - Fecha límite de precio de lista (+5 días después del corte mensual)
 *   se conserva separadamente en detalle, sin determinar esta clasificación.
 * - NOTA: es un criterio operativo de la escalera distinto del NPPF
 *   vencido por precio de lista; NO confundir ambos indicadores.
 * - Todas las cosechas se observan contra el mismo ÚLTIMO snapshot NPPF OK;
 *   la clasificación M1/preventivo se calcula al día de consulta.
 * - TIPO='REVERSO' SIEMPRE cuenta como NPPF, con insignia en detalle.
 * - Denominador de cada porcentaje = instalaciones certificadas por Motor HC
 *   de esa MISMA cosecha. Cuentas sin certificación quedan en auditoría.
 * - Semanal: el selector elige POSICIONES de días de la semana de referencia,
 *   aplicadas al día calendario equivalente de cada uno de los tres meses.
 * - Mes completo = mes calendario completo para cada una de las 3 cosechas.
 * - No modifica Motor HC, tablas NPPF ni fuente oficial del indicador mensual.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store, no-cache, must-revalidate');
date_default_timezone_set('America/Merida');
session_start();
if (empty($_SESSION['usuario'])) { http_response_code(403); exit('Sesión de TalIA no disponible.'); }
$rol = strtolower(trim((string)($_SESSION['rol'] ?? '')));
$roles = ['admin','director_regional','director_distrital','lider','coach','vendedor'];
if (!in_array($rol, $roles, true)) { http_response_code(403); exit('Perfil de TalIA no autorizado para Ranking.'); }
session_write_close();
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/../includes/motor_hc.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) { http_response_code(500); exit('Conexión MySQL no disponible.'); }
mysqli_set_charset($conexion, 'utf8mb4');

function np_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function np_num($n): string { return number_format((int)$n, 0, '.', ','); }
function np_pct(?float $v): string { return $v===null ? '—' : number_format($v, 1, '.', ',').'%'; }
function np_rate(int $num, int $den): ?float { return $den > 0 ? 100.0*$num/$den : null; }
function np_date(string $s): DateTimeImmutable { return new DateTimeImmutable($s); }
function np_q(array $override=[]): string {
    $p=array_merge($_GET,$override);
    foreach ($p as $k=>$v) if ($v===null || $v==='') unset($p[$k]);
    return '?'.http_build_query($p, '', '&', PHP_QUERY_RFC3986);
}
function np_date_valid(string $date): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $d!==false && $d->format('Y-m-d')===$date;
}
function np_account($value): string {
    $digits=preg_replace('/\D/u','',(string)$value)??'';
    return $digits==='' ? '' : (ltrim($digits,'0') ?: '0');
}
function np_invoice_due(string $activation): string {
    $d=np_date($activation);
    $next=$d->modify('first day of next month');
    $day=min((int)$d->format('j'),(int)$next->format('t'));
    return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),$day)->modify('+5 days')->format('Y-m-d');
}
/** Día de corte de primera factura: aniversario mensual de instalación.
 *  El día de corte determina M1/PREVENTIVO en la escalera, no el plazo extra.
 *  Ej.: 2026-10-20 -> 2026-11-20; 2026-10-21 -> 2026-11-21.
 */
function np_m1_cutoff(string $activation): string {
    $d=np_date($activation);
    $next=$d->modify('first day of next month');
    $day=min((int)$d->format('j'),(int)$next->format('t'));
    return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),$day)->format('Y-m-d');
}
function np_antiguedad(?string $date, string $end): ?int {
    if (!$date || !np_date_valid($date) || $date > $end) return null;
    $d=np_date($date)->diff(np_date($end));
    return (int)$d->y*12 + (int)$d->m;
}
function np_age_fmt(?int $m): string { return $m===null ? '—' : ($m < 12 ? $m.' meses' : intdiv($m,12).' años '.($m%12).' meses'); }
function np_district(string $d): string { return RankingAtribucionServiceV06::normalizarDistrito($d)??'SIN DISTRITO'; }
function np_team_key(string $lider, ?string $coach=null, ?string $vendor=null): string {
    return $lider . ($coach===null?'':'|'.$coach) . ($vendor===null?'':'|'.$vendor);
}
function np_metrics(): array {
    return ['ins_m3'=>0,'ins_m2'=>0,'ins_m1'=>0,
        'nppf_m3'=>0,'nppf_m2'=>0,'nppf_m1'=>0,'prevent_m1'=>0,'reverso'=>0];
}
function np_apply(array &$scores, string $key, string $metric): void {
    if (!isset($scores[$key])) $scores[$key]=np_metrics();
    if (!array_key_exists($metric,$scores[$key])) throw new LogicException('Métrica no definida: '.$metric);
    ++$scores[$key][$metric];
}
function np_total(array $rows): array {
    $t=np_metrics();
    foreach($rows as $r) foreach($t as $k=>$_) $t[$k]+=(int)($r[$k]??0);
    return $t;
}
/** Resta meses conservando el día de mes, ajustando 29/30/31 sin overflow. */
function np_shift_months(string $date, int $monthsBack): string {
    $d=np_date($date);
    $monthIndex=(int)$d->format('Y')*12+(int)$d->format('n')-1-$monthsBack;
    $y=intdiv($monthIndex,12);
    $m=($monthIndex%12)+1;
    $first=np_date(sprintf('%04d-%02d-01',$y,$m));
    $day=min((int)$d->format('j'),(int)$first->format('t'));
    return $first->setDate($y,$m,$day)->format('Y-m-d');
}
function np_cohort_label(array $dateKeys): string {
    if(!$dateKeys)return 'Sin fechas';
    sort($dateKeys,SORT_STRING);
    $a=np_date($dateKeys[0]);$b=np_date(end($dateKeys));
    $names=[1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $m1=$names[(int)$a->format('n')];$m2=$names[(int)$b->format('n')];
    if($a->format('Y-m')===$b->format('Y-m'))return (int)$a->format('j').'–'.(int)$b->format('j').' '.$m1.' '.$b->format('Y');
    return (int)$a->format('j').' '.$m1.' – '.(int)$b->format('j').' '.$m2.' '.$b->format('Y');
}
function np_m1_class(?float $pct): string {
    if ($pct===null) return 'nppf-muted';
    return 'nppf-risk'; // etiqueta de prioridad M1, no semáforo de meta trimestral
}

$now=date('Y-m-d');
$view=(string)($_GET['view']??'lideres');
if (!in_array($view,['lideres','ranking_coach','coaches','vendedores','cuentas'],true)) $view='lideres';
$periodo=(string)($_GET['periodo']??'semanal');
if (!in_array($periodo,['semanal','mensual'],true)) $periodo='semanal';
$distritoParam=np_district((string)($_GET['distrito']??''));
$liderId=trim((string)($_GET['lider_id']??''));
$coachId=trim((string)($_GET['coach_pos']??''));
$vendorId=trim((string)($_GET['folio']??''));
$meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
$diasLabels=[1=>'LUN',2=>'MAR',3=>'MIÉ',4=>'JUE',5=>'VIE',6=>'SÁB',7=>'DOM'];
$errors=[]; $warnings=[]; $snapshot=null;
$load=mysqli_query($conexion,"SELECT id,fecha_corte,archivo_origen FROM nppf_cargas WHERE estado='OK' ORDER BY fecha_corte DESC,id DESC LIMIT 1");
if (!$load) $errors[]='No se pudo consultar nppf_cargas: '.mysqli_error($conexion);
else $snapshot=mysqli_fetch_assoc($load)?:null;
if (!$snapshot) $errors[]='No existe un corte NPPF publicado (estado OK).';
$corte=(string)($snapshot['fecha_corte']??$now);
$eval=$now; // La clasificación operativa se mueve cada día; archivo pendiente no se reescribe.
$fechaMax=max($now,$corte);
// El default abre el período actual; se evita navegar a semanas posteriores a la actual.
$today=np_date($fechaMax);
$weekDefault=$today->modify('monday this week');
$year=(int)($_GET['anio']??$today->format('o'));
$week=(int)($_GET['semana']??$today->format('W'));
if($year<2020 || $year>2100 || $week<1 || $week>53){$year=(int)$today->format('o');$week=(int)$today->format('W');}
$weekCurrent=(new DateTimeImmutable())->setISODate($year,$week,1);
if($weekCurrent>$weekDefault) $weekCurrent=$weekDefault;
$year=(int)$weekCurrent->format('o');$week=(int)$weekCurrent->format('W');
$prevWeek=$weekCurrent->modify('-7 days');$nextWeek=$weekCurrent->modify('+7 days');
$monthDefault=$today->format('Y-m-01');
$monthYear=(int)($_GET['anio_mes']??$today->format('Y'));
$month=(int)($_GET['mes']??$today->format('n'));
if($monthYear<2020 || $monthYear>2100 || $month<1 || $month>12){$monthYear=(int)$today->format('Y');$month=(int)$today->format('n');}
$monthCurrent=np_date(sprintf('%04d-%02d-01',$monthYear,$month));
if($monthCurrent>np_date($monthDefault)) $monthCurrent=np_date($monthDefault);
$monthYear=(int)$monthCurrent->format('Y');$month=(int)$monthCurrent->format('n');
$prevMonth=$monthCurrent->modify('-1 month');$nextMonth=$monthCurrent->modify('+1 month');
$days=[1,2,3,4,5,6,7];
if($periodo==='semanal' && isset($_GET['dias_semana'])) {
    $test=array_values(array_unique(array_map('intval',explode(',',(string)$_GET['dias_semana']))));
    $test=array_values(array_filter($test,static fn($d)=>$d>=1&&$d<=7));
    if($test) {sort($test);$days=$test;}
}
$rangeMode=(string)($_GET['rango_mode']??'completo');
if(!in_array($rangeMode,['completo','custom'],true))$rangeMode='completo';
if($periodo==='semanal'){
    $actualStart=$weekCurrent->format('Y-m-d');
    $actualEnd=$weekCurrent->modify('+6 days')->format('Y-m-d');
    $baseStart=$prevWeek->format('Y-m-d');
    $baseEnd=$prevWeek->modify('+6 days')->format('Y-m-d');
    $labelActual='SEM'.(int)$weekCurrent->format('W');
    $labelBase='SEM'.(int)$prevWeek->format('W');
    $subtitleRange='Referencia Semana '.$weekCurrent->format('W').' · '.$year;
}else{
    $actualStart=$monthCurrent->format('Y-m-d');
    $actualEnd=$monthCurrent->format('Y-m-t');
    if($rangeMode==='custom'){
        $candidateStart=(string)($_GET['fecha_inicio']??'');$candidateEnd=(string)($_GET['fecha_fin']??'');
        if(np_date_valid($candidateStart) && np_date_valid($candidateEnd) && $candidateStart<=$candidateEnd &&
            substr($candidateStart,0,7)===$monthCurrent->format('Y-m') && substr($candidateEnd,0,7)===$monthCurrent->format('Y-m')){
            $actualStart=$candidateStart;$actualEnd=$candidateEnd;
        }else{$rangeMode='completo';}
    }
    if($rangeMode==='custom'){
        $dayStart=(int)substr($actualStart,8,2);$dayEnd=(int)substr($actualEnd,8,2);
        $baseStart=$prevMonth->setDate((int)$prevMonth->format('Y'),(int)$prevMonth->format('m'),min($dayStart,(int)$prevMonth->format('t')))->format('Y-m-d');
        $baseEnd=np_date($baseStart)->modify('+'.((int)np_date($actualStart)->diff(np_date($actualEnd))->days).' days')->format('Y-m-d');
    }else{$baseStart=$prevMonth->format('Y-m-d');$baseEnd=$prevMonth->format('Y-m-t');}
    $labelActual=strtoupper(substr($meses[$month],0,3)).' '.$monthYear;
    $labelBase=strtoupper(substr($meses[(int)$prevMonth->format('n')],0,3)).' '.$prevMonth->format('Y');
    $subtitleRange='Referencia '.$meses[$month].' · '.$monthYear;
}
// Cohortes de instalaciones (no vencimientos). El calendario visible es la referencia.
$cohortDates=['m3'=>[], 'm2'=>[], 'm1'=>[]];
if($periodo==='mensual' && $rangeMode==='completo') {
    foreach([3,2,1] as $m) {
        $start=np_shift_months($monthCurrent->format('Y-m-01'),$m);
        $end=np_date($start)->format('Y-m-t');
        for($d=np_date($start);$d<=np_date($end);$d=$d->modify('+1 day')) {
            $date=$d->format('Y-m-d');$cohortDates['m'.$m][$date]=true;
        }
    }
} elseif($periodo==='mensual') {
    foreach([3,2,1] as $m) {
        $start=np_shift_months($actualStart,$m);
        $end=np_shift_months($actualEnd,$m);
        for($d=np_date($start);$d<=np_date($end);$d=$d->modify('+1 day')) {
            $cohortDates['m'.$m][$d->format('Y-m-d')]=true;
        }
    }
} else {
    for($d=np_date($actualStart);$d<=np_date($actualEnd);$d=$d->modify('+1 day')) {
        if(!in_array((int)$d->format('N'),$days,true))continue;
        $ref=$d->format('Y-m-d');
        foreach([3,2,1] as $m) $cohortDates['m'.$m][np_shift_months($ref,$m)]=true;
    }
}
$cohortLabels=[];$cohortIndex=[];$activationAll=[];
foreach([3,2,1] as $m){
    $name='m'.$m;
    $cohortLabels[$name]=np_cohort_label(array_keys($cohortDates[$name]));
    foreach($cohortDates[$name] as $activation=>$_){
        if(isset($cohortIndex[$activation]) && $cohortIndex[$activation]!==$name){
            $errors[]='Una fecha pertenece a más de una cosecha; revisa el selector.';
            continue;
        }
        $cohortIndex[$activation]=$name;
        $activationAll[]=$activation;
    }
}
if(!$activationAll)$errors[]='No hay días de cosecha seleccionados.';
$activationFrom=$activationAll?min($activationAll):$now;
$activationTo=$activationAll?max($activationAll):$now;

$catalog=[];$coaches=[];$vendors=[];$matched=[];$scores=[];$raw=[];
$audit=['m3'=>[], 'm2'=>[], 'm1'=>[]];
$photo=null;$accountDetail=[];
if(!$errors){
 try {
    $motor=new RankingAtribucionServiceV06($conexion);
    $res=$motor->obtenerInstalacionesJerarquia($activationFrom,$activationTo);
    if(($res['version']??'')!=='0.6.8-hc-historical-seller-name-all-channels')
        throw new RuntimeException('Se requiere API 0.6.8 del motor_hc 0.6.9.');
    $catalog=$motor->catalogoLideres($activationFrom,$activationTo);
    if(!$catalog) throw new RuntimeException('No hay plazas de líder certificadas por motor_hc en este período.');
    $hic=RankingAtribucionServiceV06::resolverEquivalenciasHic($motor->cargarHic());
    $photo=$motor->construirFotoProductividad($activationFrom,$activationTo,$catalog,$hic['canonicos'],[]);
    foreach($photo['coaches'] as $c){
        $lid=(string)$c['lider_id'];$cid=(string)$c['id'];
        $coaches[np_team_key($lid,$cid)]=['id'=>$cid,'lid'=>$lid,'nombre'=>(string)$c['nombre'],'distrito'=>(string)$c['distrito']];
    }
    foreach($photo['vendedores'] as $key=>$seats)foreach($seats as $s){
        if(!empty($s['vacante'])||!empty($s['es_vacante_status'])||empty($s['persona']))continue;
        $person=(string)$s['persona'];
        $vendors[$key.'|'.$person]=['id'=>$person,'nombre'=>(string)$s['nombre'],'fecha_alta'=>(string)($s['fecha_alta']??'')];
    }
    // Fuente exclusiva de jerarquía: cuentas con atribución HC/HIC certificada.
    foreach($res['cuentas'] as $event){
        $key=np_account($event['cuenta']??'');
        $date=substr((string)($event['fecha']??''),0,10);
        if($key===''||!np_date_valid($date)||!isset($cohortIndex[$date]))continue;
        $cohort=$cohortIndex[$date];
        $lid=(string)($event['lider_id']??'');$co=(string)($event['coach_id']??'');$vendor=(string)($event['vendedor_id']??'');
        if(!isset($catalog[$lid])||$co===''||$vendor==='')continue;
        $ck=np_team_key($lid,$co);
        if(!isset($coaches[$ck]))$coaches[$ck]=['id'=>$co,'lid'=>$lid,'nombre'=>(string)($event['coach_evento']??'COACH HISTÓRICO'),'distrito'=>(string)$catalog[$lid]['distrito']];
        $vk=$ck.'|'.$vendor;
        if(!isset($vendors[$vk]))$vendors[$vk]=['id'=>$vendor,'nombre'=>(string)($event['vendedor_nombre']??'VENDEDOR NO IDENTIFICADO'),'fecha_alta'=>(string)($event['vendedor_fecha_alta']??'')];
        if(isset($matched[$key])){
            $warnings['duplicada']='Una cuenta presenta más de una instalación certificada entre las tres cosechas; revisar conciliación, sin duplicar.';
            continue;
        }
        $event['_cohort']=$cohort;
        $matched[$key]=$event;
        foreach([$lid,$ck,$vk] as $hier)np_apply($scores,$hier,'ins_'.$cohort);
    }
    $s=$conexion->prepare("SELECT cuenta,cuenta_clave,fecha_activacion,tipo,atraso,distrito,region,canal_venta,desc_plan,monto_factura,vendedor
        FROM nppf_registros
        WHERE presente_en_ultimo_corte=1 AND UPPER(TRIM(region))='SUR'
          AND fecha_activacion>=? AND fecha_activacion<=?");
    if(!$s)throw new RuntimeException('No pudo prepararse la consulta de NPPF: '.$conexion->error);
    $s->bind_param('ss',$activationFrom,$activationTo);$s->execute();
    $rs=$s->get_result();
    $seenRaw=[];
    while($r=$rs->fetch_assoc()){
        $date=(string)($r['fecha_activacion']??'');
        if(!np_date_valid($date)||!isset($cohortIndex[$date]))continue;
        $cohort=$cohortIndex[$date];
        $key=np_account($r['cuenta_clave']??$r['cuenta']);
        if($key==='')continue;
        $rawId=$key.'|'.$date;
        if(isset($seenRaw[$rawId])){$audit[$cohort][$rawId]='DUPLICADA EN NPPF';continue;}
        $seenRaw[$rawId]=true;
        if(isset($raw[$cohort][$key])){$audit[$cohort][$key]='DUPLICADO POR CUENTA';continue;}
        $monthlyCut=np_m1_cutoff($date);
        $priceListEnd=np_invoice_due($date);
        $r['_monthly_cut']=$monthlyCut;
        $r['_due']=$priceListEnd; // Fin precio de lista; NO se usa para clasificar escalera.
        $r['_cohort']=$cohort;
        $r['_class']=($cohort==='m1' && $eval<$monthlyCut)?'PREVENTIVO M1':strtoupper($cohort);
        $r['_reverso']=strtoupper(trim((string)$r['tipo']))==='REVERSO';
        $raw[$cohort][$key]=$r;
    }
    $s->close();
    foreach($raw as $cohort=>$source)foreach($source as $key=>$r){
        $ev=$matched[$key]??null;
        if(!$ev){$audit[$cohort][$key]='SIN INSTALACIÓN CERTIFICADA';continue;}
        $delta=abs((int)np_date(substr((string)$ev['fecha'],0,10))->diff(np_date((string)$r['fecha_activacion']))->format('%r%a'));
        if($delta>1 || $ev['_cohort']!==$cohort){$audit[$cohort][$key]='FECHA DE ACTIVACIÓN DIFERENTE';continue;}
        $lid=(string)$ev['lider_id'];$co=(string)$ev['coach_id'];$ven=(string)$ev['vendedor_id'];
        if(np_district((string)$r['distrito']) !== np_district((string)($catalog[$lid]['distrito']??''))){
            $audit[$cohort][$key]='DISTRITO NPPF DIFERENTE DE HC';continue;
        }
        $metric=($r['_class']==='PREVENTIVO M1')?'prevent_m1':'nppf_'.$cohort;
        foreach([$lid,np_team_key($lid,$co),np_team_key($lid,$co,$ven)] as $hier){
            np_apply($scores,$hier,$metric);
            if($r['_reverso'])np_apply($scores,$hier,'reverso');
        }
        $accountDetail[np_team_key($lid,$co,$ven)][$key]=$r;
    }
    foreach($catalog as $lid=>$l)$scores[$lid]??=np_metrics();
    foreach($coaches as $ck=>$c)$scores[$ck]??=np_metrics();
    foreach($vendors as $vk=>$v)$scores[$vk]??=np_metrics();
    $notCertified=count($audit['m3'])+count($audit['m2'])+count($audit['m1']);
    if($notCertified)$warnings['pendientes']="{$notCertified} cuentas NPPF en M3/M2/M1 no pudieron asignarse de forma certificada a un líder; se muestran en auditoría, fuera de porcentajes.";
    if($corte<$eval)$warnings['frescura']='Pendientes según archivo del '.$corte.'; M1/Preventivo evaluado al '.$eval.'. Algunos clientes pudieron pagar después del corte.';
 }catch(Throwable $e){$errors[]='Motor NPPF / HC: '.$e->getMessage();}
}

$rows=[];$detailRows=[];$viewLabel='Ranking NPPF';
if(!$errors){
    $scopeRows=[];
    if($view==='lideres'){
        $viewLabel='Ranking NPPF · Líder';
        foreach($catalog as $lid=>$l)$scopeRows[]=['key'=>$lid,'distrito'=>(string)$l['distrito'],'entidad'=>(string)$l['nombre'],'lid'=>$lid,'co'=>'','v'=>''];
    }elseif($view==='ranking_coach'||$view==='coaches'){
        $viewLabel=$view==='ranking_coach'?'Ranking NPPF · Coach':'Coaches del Líder';
        if($view==='coaches'&&!isset($catalog[$liderId]))$errors[]='Líder no identificado en el catálogo HC.';
        foreach($coaches as $k=>$c){
            if($view==='coaches'&&$c['lid']!==$liderId)continue;
            $scopeRows[]=['key'=>$k,'distrito'=>$c['distrito'],'entidad'=>$c['nombre'],'lid'=>$c['lid'],'co'=>$c['id'],'v'=>''];
        }
    }elseif($view==='vendedores'||$view==='cuentas'){
        $viewLabel=$view==='vendedores'?'Vendedores del Coach':'Detalle de cuentas NPPF';
        $keyCoach=np_team_key($liderId,$coachId);
        if(!isset($catalog[$liderId])||!isset($coaches[$keyCoach]))$errors[]='Líder o Coach no identificado en HC.';
        foreach($vendors as $k=>$v){
            if(!str_starts_with($k,$keyCoach.'|'))continue;
            if($view==='cuentas'&&$v['id']!==$vendorId)continue;
            $scopeRows[]=['key'=>$k,'distrito'=>(string)($catalog[$liderId]['distrito']??''),'entidad'=>$v['nombre'],'lid'=>$liderId,'co'=>$coachId,'v'=>$v['id']];
        }
    }
    foreach($scopeRows as $r){
        $score=$scores[$r['key']]??np_metrics();
        $row=array_merge($r,$score);
        $ages=[];
        if($r['v']!==''){
            $age=np_antiguedad($vendors[$r['key']]['fecha_alta']??null,$eval);
            if($age!==null)$ages[]=$age;
        }else{
            $prefix=$r['key'].'|';
            foreach($vendors as $vk=>$v){
                if(!str_starts_with($vk,$prefix))continue;
                $age=np_antiguedad($v['fecha_alta']??null,$eval);
                if($age!==null)$ages[]=$age;
            }
        }
        $row['antiguedad_meses']=$ages?(int)round(array_sum($ages)/count($ages)):null;
        $row['antiguedad']=np_age_fmt($row['antiguedad_meses']);
        foreach([3,2,1] as $m)$row['pct_m'.$m]=np_rate($row['nppf_m'.$m],$row['ins_m'.$m]);
        $row['pct_prevent_m1']=np_rate($row['prevent_m1'],$row['ins_m1']);
        $rows[]=$row;
    }
    // Prioridad default: riesgo de cartera vencida más antigua M3, después M2 y M1.
    usort($rows,static function($a,$b){
        foreach(['pct_m3','pct_m2','pct_m1'] as $p){
            $aa=$a[$p]??-1;$bb=$b[$p]??-1;
            if($aa!==$bb)return $bb<=>$aa;
        }
        return ($b['nppf_m3']<=>$a['nppf_m3']) ?: strcmp($a['entidad'],$b['entidad']);
    });
    if($view==='cuentas'&&$scopeRows){
        foreach($scopeRows as $r)foreach($accountDetail[$r['key']]??[] as $record)$detailRows[]=$record;
        $priority=['M3'=>0,'M2'=>1,'M1'=>2,'PREVENTIVO M1'=>3];
        usort($detailRows,static fn($a,$b)=>($priority[$a['_class']]??4)<=>($priority[$b['_class']]??4) ?: strcmp($a['fecha_activacion'],$b['fecha_activacion']));
    }
}
$tot=np_total($rows);
if($view==='cuentas'&&$rows)$tot=$scores[$rows[0]['key']]??np_metrics();
$rootLink=np_q(['view'=>'lideres','lider_id'=>null,'coach_pos'=>null,'folio'=>null]);
$coachLink=np_q(['view'=>'ranking_coach','lider_id'=>null,'coach_pos'=>null,'folio'=>null]);
$backLeaders=np_q(['view'=>'coaches','coach_pos'=>null,'folio'=>null]);
$backVendors=np_q(['view'=>'vendedores','folio'=>null]);
$monthsDisplay=$meses[$month];
$previousMonthsLabel=$meses[(int)$prevMonth->format('n')];
$periodTitle=$periodo==='semanal'?'Semana '.$week.' · '.$year:$monthsDisplay.' · '.$monthYear;
$gridStart=$monthCurrent->modify('-'.((int)$monthCurrent->format('N')-1).' days');
$roleNames=['admin'=>'Administrador','director_regional'=>'Director Regional','director_distrital'=>'Director Distrital','lider'=>'Líder','coach'=>'Coach','vendedor'=>'Vendedor'];
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ranking NPPF — TotalXpedient</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=164">
<style>
body.page-ranking .nppf-caption{font-size:12px;color:#65708a;line-height:1.6}
body.page-ranking .nppf-note{background:#fffaf0;border:1px solid #f3dfb4;padding:10px 14px;border-radius:12px;margin:10px 0;color:#885b19;font-size:12px}
body.page-ranking .nppf-good{background:#e7f8ed;color:#13623a}.nppf-risk{color:#a22b3a;background:#fee8ed}.nppf-caution{color:#9a5a10;background:#fff3d3}.nppf-muted{color:#667085;background:#eff1f7}
body.page-ranking .nppf-pill{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
body.page-ranking .nppf-table{min-width:1530px;width:100%}body.page-ranking .nppf-table th{white-space:nowrap;padding:11px 8px;font-size:11px;vertical-align:middle}body.page-ranking .nppf-table td{padding:12px 8px;font-size:12px}
body.page-ranking .nppf-table .nppf-group{background:#eaeafa;text-align:center;font-size:11px;font-weight:850;color:#49416b}
body.page-ranking .nppf-table .nppf-group.m1{background:#fce9ef;color:#9b1c3d}body.page-ranking .nppf-table .nppf-group.prevent{background:#fff3db;color:#8d6111}
body.page-ranking .nppf-table .nppf-group.ins{background:#e9f2ff;color:#164b83}
body.page-ranking .nppf-table .nppf-muted-td{color:#667085}
body.page-ranking .nppf-table .nppf-row{cursor:pointer}body.page-ranking .nppf-table .nppf-row:hover{background:#f7f4ff}
body.page-ranking .nppf-table td.nppf-entity{min-width:210px;white-space:normal;font-weight:700}
body.page-ranking .nppf-panel{background:#fff;border:1px solid #e6e9f1;border-radius:16px;padding:14px 18px;margin:14px 0}
body.page-ranking .nppf-alert{border-color:#f4d19e;background:#fffbf2;color:#76521e}
body.page-ranking .nppf-subtle{color:#667085;font-size:12px}
body.page-ranking .nppf-table .nppf-sort{cursor:pointer;user-select:none}body.page-ranking .nppf-table .nppf-sort:hover{color:#7925dc}
body.page-ranking .nppf-card-hint{color:#667085;font-size:11px;margin-top:2px}
body.page-ranking .nppf-month-input{font:inherit;border:1px solid #e0e4f0;border-radius:9px;padding:8px}
body.page-ranking .nppf-rangepanel{min-width:315px}body.page-ranking .nppf-detail-table{min-width:1270px}
body.page-ranking .nppf-raw{font-weight:700;color:#5a2678}
body.page-ranking .nppf-filters{display:flex;align-items:center;flex-wrap:wrap;gap:7px}
body.page-ranking .nppf-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
body.page-ranking .nppf-cards .card{min-width:0}
body.page-ranking .nppf-cards .label{font-size:11px}
body.page-ranking .nppf-cohort-sub{display:block;font-size:10px;font-weight:500;color:#667085;white-space:normal;margin-top:4px}
body.page-ranking .nppf-table .nppf-group.nppf-stair{background:#f3eaff;color:#572688}

/* v1.1.1 - Malla de 15 columnas fija, sin ensanchamiento unilateral de M3.
   4 identificacion + 3 cosecha + 6 NPPF + 2 preventivo = 15. */
body.page-ranking #nppfTable{
  width:100% !important;
  min-width:1380px;
  table-layout:fixed !important;
  border-collapse:collapse;
}
body.page-ranking #nppfTable col.np-rank{width:40px}
body.page-ranking #nppfTable col.np-district{width:108px}
body.page-ranking #nppfTable col.np-name{width:225px}
body.page-ranking #nppfTable col.np-age{width:106px}
body.page-ranking #nppfTable col.np-ins{width:91px}
body.page-ranking #nppfTable col.np-quantity{width:77px}
body.page-ranking #nppfTable col.np-percent{width:78px}
body.page-ranking #nppfTable col.np-prevent-quantity{width:103px}
body.page-ranking #nppfTable col.np-prevent-percent{width:103px}
body.page-ranking #nppfTable th,
body.page-ranking #nppfTable td{
  box-sizing:border-box;
  vertical-align:middle;
  overflow-wrap:normal;
  word-break:normal;
}
body.page-ranking #nppfTable thead th{
  text-align:center !important;
  white-space:normal !important;
  line-height:1.25;
  padding:9px 5px;
}
body.page-ranking #nppfTable thead tr:first-child > th:nth-child(2),
body.page-ranking #nppfTable thead tr:first-child > th:nth-child(3){text-align:left !important}
body.page-ranking #nppfTable .nppf-cohort-sub{
  font-size:10px;
  line-height:1.25;
  white-space:normal;
  margin-top:5px;
  overflow-wrap:normal;
}
body.page-ranking #nppfTable tbody td{
  padding:10px 5px;
  text-align:center !important;
  white-space:nowrap;
  font-variant-numeric:tabular-nums;
}
body.page-ranking #nppfTable tbody td:nth-child(2),
body.page-ranking #nppfTable tbody td:nth-child(3){
  text-align:left !important;
  white-space:normal;
}
body.page-ranking #nppfTable tbody td.nppf-entity{
  width:auto;
  min-width:0 !important;
  overflow-wrap:break-word;
  line-height:1.35;
}
body.page-ranking #nppfTable tbody td:nth-child(5),
body.page-ranking #nppfTable tbody td:nth-child(8),
body.page-ranking #nppfTable tbody td:nth-child(14),
body.page-ranking #nppfTable thead tr:nth-child(2) th:nth-child(4),
body.page-ranking #nppfTable thead tr:nth-child(2) th:nth-child(10){
  border-left:2px solid #dfe3f0;
}
body.page-ranking #nppfTable tbody #totalRow td{
  text-align:center !important;
  white-space:nowrap;
  font-variant-numeric:tabular-nums;
}
body.page-ranking #nppfTable tbody #totalRow td:first-child{
  text-align:left !important;
  padding-left:14px;
}
body.page-ranking #nppfTable .nppf-pill{
  max-width:100%;
  padding:4px 6px;
  box-sizing:border-box;
  font-variant-numeric:tabular-nums;
}
body.page-ranking .table-wrap:has(>#nppfTable){
  overflow-x:auto;
  max-width:100%;
}

@media(max-width:1400px){body.page-ranking .nppf-cards{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:780px){body.page-ranking .nppf-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){body.page-ranking .nppf-cards{grid-template-columns:1fr}}
@media(max-width:860px){body.page-ranking .nppf-month-form{display:flex;flex-wrap:wrap;gap:6px}}
</style></head>
<body class="page-ranking">
<?php $current_page='ranking'; include __DIR__.'/../includes/sidebar.php'; ?>
<main class="main">
<section class="topbar">
 <div class="page-title">
  <h1>Ranking NPPF <span class="week-pill"><?=np_h($periodTitle)?></span></h1>
  <p>Escalera de cosechas M3 / M2 / M1 · <?=np_h($subtitleRange)?> · <?=np_h($roleNames[$rol]??$rol)?></p>
  <p class="nppf-caption">Pendientes según archivo del <strong><?=np_h($corte)?></strong> · Clasificación evaluada al <strong><?=np_h($eval)?></strong> · M1 de escalera desde el corte mensual · <strong>v1.1.2</strong></p>
 </div>
 <div class="week-nav">
  <a class="week-btn <?=$periodo==='semanal'?'week-current':''?>" href="<?=np_h(np_q(['periodo'=>'semanal','view'=>'lideres','lider_id'=>null,'coach_pos'=>null,'folio'=>null]))?>">Semanal</a>
  <a class="week-btn <?=$periodo==='mensual'?'week-current':''?>" href="<?=np_h(np_q(['periodo'=>'mensual','view'=>'lideres','lider_id'=>null,'coach_pos'=>null,'folio'=>null]))?>">Mensual</a>
  <?php if($periodo==='semanal'): ?>
   <a class="week-btn" href="<?=np_h(np_q(['anio'=>$prevWeek->format('o'),'semana'=>$prevWeek->format('W')]))?>">← Semana <?=$prevWeek->format('W')?></a>
   <div class="range-dropdown week-range-dropdown" id="weekDrop">
    <button type="button" class="week-current range-trigger" id="weekTrigger">Semana <?=$week?></button>
    <div class="range-panel week-range-panel" id="weekPanel">
     <form method="get">
       <?php foreach($_GET as $k=>$v): if(in_array($k,['dias_semana','anio','semana','periodo'],true))continue;?><input type="hidden" name="<?=np_h($k)?>" value="<?=np_h($v)?>"><?php endforeach;?>
       <input type="hidden" name="periodo" value="semanal">
       <input type="hidden" name="anio" value="<?=$year?>"><input type="hidden" name="semana" value="<?=$week?>">
       <input type="hidden" name="dias_semana" id="diasInput" value="<?=np_h(implode(',',$days))?>">
       <div class="range-panel-title">Selecciona días de referencia · Semana <?=$week?></div>
       <div class="calendar-grid-real weekday-grid-real" id="daysGrid">
        <?php foreach($diasLabels as $d=>$s):?><div class="calendar-weekday"><?=np_h($s)?></div><?php endforeach;?>
        <?php foreach($diasLabels as $d=>$s):?><button type="button" class="calendar-day weekday-day <?=in_array($d,$days,true)?'selected-start selected-end':''?>" data-day="<?=$d?>"><?=np_h($s)?></button><?php endforeach;?>
       </div><div class="range-actions"><button type="button" id="fullWeek" class="quick-week">Semana completa disponible</button><button type="submit">Aplicar</button></div>
     </form>
    </div>
   </div>
   <?php if($nextWeek<=$weekDefault): ?><a class="week-btn" href="<?=np_h(np_q(['anio'=>$nextWeek->format('o'),'semana'=>$nextWeek->format('W')]))?>">Semana <?=$nextWeek->format('W')?> →</a>
   <?php else:?><span class="week-btn disabled">Semana <?=$nextWeek->format('W')?> →</span><?php endif;?>
  <?php else: ?>
   <a class="week-btn" href="<?=np_h(np_q(['anio_mes'=>$prevMonth->format('Y'),'mes'=>$prevMonth->format('n'),'rango_mode'=>'completo','fecha_inicio'=>null,'fecha_fin'=>null]))?>">← <?=$previousMonthsLabel?></a>
   <div class="range-dropdown" id="monthDrop">
    <button type="button" class="week-current range-trigger" id="monthTrigger"><?=$monthsDisplay?></button>
    <div class="range-panel nppf-rangepanel" id="monthPanel">
     <form method="get" class="nppf-month-form">
      <?php foreach($_GET as $k=>$v): if(in_array($k,['rango_mode','fecha_inicio','fecha_fin','periodo'],true))continue;?><input type="hidden" name="<?=np_h($k)?>" value="<?=np_h($v)?>"><?php endforeach;?>
      <input type="hidden" name="periodo" value="mensual"><input type="hidden" name="rango_mode" value="custom">
      <div class="range-panel-title">Selecciona rango de referencia · <?=$monthsDisplay?> · <?=$monthYear?></div>
      <input type="hidden" id="nppfDateStart" name="fecha_inicio" value="<?=np_h($actualStart)?>">
      <input type="hidden" id="nppfDateEnd" name="fecha_fin" value="<?=np_h($actualEnd)?>">
      <div class="calendar-grid-real" id="nppfMonthGrid" data-start="<?=np_h($actualStart)?>" data-end="<?=np_h($actualEnd)?>">
       <?php foreach(['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dayLabel):?><div class="calendar-weekday"><?=np_h($dayLabel)?></div><?php endforeach;?>
       <?php for($i=0;$i<42;$i++):$gridDate=$gridStart->modify('+'.$i.' days')->format('Y-m-d');$adj=substr($gridDate,0,7)!==$monthCurrent->format('Y-m');$disabled=$adj;$c=[];if($adj)$c[]='adjacent-month';if($gridDate===$actualStart)$c[]='selected-start';if($gridDate===$actualEnd)$c[]='selected-end';if($gridDate>$actualStart&&$gridDate<$actualEnd)$c[]='in-range';?>
        <button type="button" class="calendar-day <?=np_h(implode(' ',$c))?>" data-date="<?=np_h($gridDate)?>" <?=$disabled?'disabled':''?>><?=(int)substr($gridDate,8,2)?></button>
       <?php endfor;?>
      </div>
      <div class="range-summary nppf-caption" id="nppfMonthRangeSummary">Selecciona fecha inicial y final</div>
      <div class="range-actions"><a class="quick-range <?=($rangeMode==='completo')?'active':''?>" href="<?=np_h(np_q(['rango_mode'=>'completo','fecha_inicio'=>null,'fecha_fin'=>null]))?>">Mes completo</a><button type="submit">Aplicar</button></div>
     </form>
    </div>
   </div>
   <?php if($nextMonth<=np_date($monthDefault)):?><a class="week-btn" href="<?=np_h(np_q(['anio_mes'=>$nextMonth->format('Y'),'mes'=>$nextMonth->format('n'),'rango_mode'=>'completo','fecha_inicio'=>null,'fecha_fin'=>null]))?>"><?=np_h($meses[(int)$nextMonth->format('n')])?> →</a>
   <?php else:?><span class="week-btn disabled">Siguiente →</span><?php endif;?>
  <?php endif;?>
 </div>
</section>
<section class="breadcrumb-card"><div class="breadcrumb-top"><div><div class="breadcrumb-title">Nivel de análisis</div><div class="breadcrumb-path">
 <?php if($view==='lideres'):?><span class="breadcrumb-current">🏆 Ranking Líder</span><a class="breadcrumb-link" href="<?=np_h($coachLink)?>">👥 Ranking Coach</a>
 <?php elseif($view==='ranking_coach'):?><a class="breadcrumb-link" href="<?=np_h($rootLink)?>">🏆 Ranking Líder</a><span class="breadcrumb-current">👥 Ranking Coach</span>
 <?php else:?><a class="breadcrumb-link" href="<?=np_h($rootLink)?>">🏆 Ranking Líder</a><a class="breadcrumb-link" href="<?=np_h($coachLink)?>">👥 Ranking Coach</a>
  <span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?=np_h($catalog[$liderId]['nombre']??'LÍDER')?></span>
  <?php if(in_array($view,['vendedores','cuentas'],true)):?><span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?=np_h($coaches[np_team_key($liderId,$coachId)]['nombre']??'COACH')?></span><?php endif;?>
  <?php if($view==='cuentas'):?><span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?=np_h($vendors[np_team_key($liderId,$coachId,$vendorId)]['nombre']??'VENDEDOR')?></span><?php endif;?>
 <?php endif;?></div></div><div class="level-actions">
 <?php if(!in_array($view,['lideres','ranking_coach'],true)):?><a class="level-action" href="<?=np_h($rootLink)?>">← Ver líderes</a><?php endif;?>
 <?php if(in_array($view,['vendedores','cuentas'],true)):?><a class="level-action primary" href="<?=np_h($backLeaders)?>">← Ver coaches</a><?php endif;?>
 <?php if($view==='cuentas'):?><a class="level-action primary" href="<?=np_h($backVendors)?>">← Ver vendedores</a><?php endif;?>
 </div></div></section>
<?php foreach($errors as $error):?><div class="nppf-panel nppf-alert" role="alert">⚠ <?=np_h($error)?></div><?php endforeach;?>
<?php if(!$errors):?>
<section class="cards nppf-cards" id="nppfCards">
 <div class="card"><div class="label">COSECHA · INSTALACIONES M1</div><div class="value" id="kpiInsM1"><?=np_num($tot['ins_m1'])?></div><div class="hint"><?=np_h($cohortLabels['m1'])?><div class="nppf-card-hint">M3: <span id="kpiInsM3"><?=np_num($tot['ins_m3'])?></span> · M2: <span id="kpiInsM2"><?=np_num($tot['ins_m2'])?></span></div></div></div>
 <div class="card"><div class="label">NPPF M3</div><div class="value" id="kpiM3"><?=np_num($tot['nppf_m3'])?></div><div class="hint"><span id="kpiM3Pct"><?=np_pct(np_rate($tot['nppf_m3'],$tot['ins_m3']))?></span> de INS M3<div class="nppf-card-hint"><?=np_h($cohortLabels['m3'])?></div></div></div>
 <div class="card"><div class="label">NPPF M2</div><div class="value" id="kpiM2"><?=np_num($tot['nppf_m2'])?></div><div class="hint"><span id="kpiM2Pct"><?=np_pct(np_rate($tot['nppf_m2'],$tot['ins_m2']))?></span> de INS M2<div class="nppf-card-hint"><?=np_h($cohortLabels['m2'])?></div></div></div>
 <div class="card"><div class="label">NPPF M1</div><div class="value" id="kpiM1"><?=np_num($tot['nppf_m1'])?></div><div class="hint"><span id="kpiM1Pct"><?=np_pct(np_rate($tot['nppf_m1'],$tot['ins_m1']))?></span> de INS M1<div class="nppf-card-hint">Corte mensual alcanzado</div></div></div>
 <div class="card"><div class="label">NPPF M1 PREVENTIVO</div><div class="value" id="kpiPre"><?=np_num($tot['prevent_m1'])?></div><div class="hint"><span id="kpiPrePct"><?=np_pct(np_rate($tot['prevent_m1'],$tot['ins_m1']))?></span> de INS M1<div class="nppf-card-hint">Incluye <span id="kpiReverso"><?=np_num($tot['reverso'])?></span> reversos en todas las cosechas</div></div></div>
</section>
<section class="nppf-panel" style="margin-top:12px">
 <strong>Metodología de cosechas · NPPF</strong>
 <div class="nppf-caption">El período seleccionado es una <strong>referencia calendario</strong>. Las instalaciones M1, M2 y M3 corresponden a los mismos días de <strong>uno, dos y tres meses anteriores</strong>; en mes completo se toman los meses completos. M2 y M3 representan <strong>primeras facturas todavía pendientes</strong>, no la segunda ni tercera factura del cliente. Cada porcentaje utiliza las instalaciones <strong>certificadas por Motor HC de su propia cosecha</strong>. La separación operativa de <strong>M1 y Preventivo</strong> depende del <strong>día de corte mensual</strong> (fecha de instalación trasladada un mes) y de la fecha de evaluación: el mismo día del corte ya cuenta como M1. Los <strong>cinco días posteriores de precio de lista</strong> NO retrasan esa clasificación en este Ranking; su fecha límite se conserva en el detalle por cuenta. <strong>Esta segmentación operativa no equivale al indicador de primera factura vencida al final de precio de lista.</strong> Todos los pendientes provienen del <strong>último archivo NPPF publicado</strong>; no se reconstruyen saldos históricos de esos meses. REVERSO permanece incluido. La meta trimestral oficial no se utiliza como semáforo de estos porcentajes operativos.</div>
</section>
<?php foreach($warnings as $warn):?><div class="nppf-note">⚠ <?=np_h($warn)?></div><?php endforeach;?>
<?php if($view!=='cuentas'):?>
<section class="filters nppf-filters">
 <?php if(in_array($view,['lideres','ranking_coach'],true)):?>
 <span class="filter-label">Distrito:</span><button class="filter-btn active" data-district="ALL">Todos</button>
 <?php foreach(['CANCUN','COATZA-MINA','MERIDA','TUXTLA','VILLAHERMOSA'] as $district):?><button class="filter-btn" data-district="<?=np_h($district)?>"><?=np_h($district)?></button><?php endforeach;?>
 <?php else:?><span class="filter-label">Vista:</span><span class="context-chip"><?=np_h($viewLabel)?></span><?php endif;?>
 <span class="counter" id="visibleCounter">Mostrando <?=count($rows)?> registros</span>
</section>
<section class="table-card"><div class="table-head"><strong><?=np_h($viewLabel)?></strong><span><?= $view==='lideres'?'Click en líder para ver coaches':($view==='ranking_coach'||$view==='coaches'?'Click en coach para ver vendedores':'Click en vendedor para consultar cuentas NPPF')?></span></div>
<div class="table-wrap"><table class="nppf-table" id="nppfTable">
<colgroup>
 <col class="np-rank"><col class="np-district"><col class="np-name"><col class="np-age">
 <col class="np-ins"><col class="np-ins"><col class="np-ins">
 <col class="np-quantity"><col class="np-quantity"><col class="np-quantity">
 <col class="np-percent"><col class="np-percent"><col class="np-percent">
 <col class="np-prevent-quantity"><col class="np-prevent-percent">
</colgroup><thead>
<tr><th rowspan="2" class="center">#</th><th rowspan="2">Distrito</th><th rowspan="2"><?=($view==='lideres')?'Líder':($view==='vendedores'?'Vendedor':'Coach')?></th><th rowspan="2" class="center nppf-sort" data-key="antiguedad_meses">Antigüedad<br>prom. equipo ↕</th>
 <th colspan="3" class="nppf-group ins">COSECHA · INSTALACIONES</th>
 <th colspan="6" class="nppf-group nppf-stair">NPPF</th>
 <th colspan="2" class="nppf-group prevent">NPPF M1 PREVENTIVO</th></tr>
<tr>
 <th class="num nppf-sort" data-key="ins_m3">INS M3 ↕<span class="nppf-cohort-sub"><?=np_h($cohortLabels['m3'])?></span></th>
 <th class="num nppf-sort" data-key="ins_m2">INS M2 ↕<span class="nppf-cohort-sub"><?=np_h($cohortLabels['m2'])?></span></th>
 <th class="num nppf-sort" data-key="ins_m1">INS M1 ↕<span class="nppf-cohort-sub"><?=np_h($cohortLabels['m1'])?></span></th>
 <th class="num nppf-sort" data-key="nppf_m3"># M3 ↕</th>
 <th class="num nppf-sort" data-key="nppf_m2"># M2 ↕</th>
 <th class="num nppf-sort" data-key="nppf_m1"># M1 ↕</th>
 <th class="center nppf-sort" data-key="pct_m3">% M3 ↕</th>
 <th class="center nppf-sort" data-key="pct_m2">% M2 ↕</th>
 <th class="center nppf-sort" data-key="pct_m1">% M1 ↕</th>
 <th class="num nppf-sort" data-key="prevent_m1"># M1 Prev. ↕</th>
 <th class="center nppf-sort" data-key="pct_prevent_m1">% M1 Prev. ↕</th>
</tr></thead><tbody>
<?php foreach($rows as $i=>$r):
 $href=$view==='lideres'?np_q(['view'=>'coaches','lider_id'=>$r['lid'],'coach_pos'=>null,'folio'=>null])
    :(in_array($view,['ranking_coach','coaches'],true)?np_q(['view'=>'vendedores','lider_id'=>$r['lid'],'coach_pos'=>$r['co'],'folio'=>null]):np_q(['view'=>'cuentas','lider_id'=>$r['lid'],'coach_pos'=>$r['co'],'folio'=>$r['v']]));
 $dataset=['ins_m3'=>$r['ins_m3'],'ins_m2'=>$r['ins_m2'],'ins_m1'=>$r['ins_m1'],
   'nppf_m3'=>$r['nppf_m3'],'nppf_m2'=>$r['nppf_m2'],'nppf_m1'=>$r['nppf_m1'],
   'pct_m3'=>$r['pct_m3']??-1,'pct_m2'=>$r['pct_m2']??-1,'pct_m1'=>$r['pct_m1']??-1,
   'prevent_m1'=>$r['prevent_m1'],'pct_prevent_m1'=>$r['pct_prevent_m1']??-1,
   'antiguedad_meses'=>$r['antiguedad_meses']??-1,'reverso'=>$r['reverso']];
?><tr class="nppf-row data-row" tabindex="0" data-district="<?=np_h($r['distrito'])?>" data-href="<?=np_h($href)?>" <?php foreach($dataset as $k=>$v)echo ' data-'.str_replace('_','-', $k).'="'.np_h($v).'"';?> >
<td class="center rank"><?=$i+1?></td><td><?=np_h($r['distrito'])?></td><td class="nppf-entity"><?=np_h($r['entidad'])?></td><td class="center"><?=np_h($r['antiguedad'])?></td>
<td class="num"><?=np_num($r['ins_m3'])?></td><td class="num"><?=np_num($r['ins_m2'])?></td><td class="num"><?=np_num($r['ins_m1'])?></td>
<td class="num"><?=np_num($r['nppf_m3'])?></td><td class="num"><?=np_num($r['nppf_m2'])?></td><td class="num"><?=np_num($r['nppf_m1'])?></td>
<td class="center"><span class="nppf-pill <?=np_m1_class($r['pct_m3'])?>"><?=np_pct($r['pct_m3'])?></span></td>
<td class="center"><span class="nppf-pill <?=np_m1_class($r['pct_m2'])?>"><?=np_pct($r['pct_m2'])?></span></td>
<td class="center"><span class="nppf-pill <?=np_m1_class($r['pct_m1'])?>"><?=np_pct($r['pct_m1'])?></span></td>
<td class="num"><?=np_num($r['prevent_m1'])?></td><td class="center"><span class="nppf-pill nppf-caution"><?=np_pct($r['pct_prevent_m1'])?></span></td></tr>
<?php endforeach;?>
<tr class="total-row" id="totalRow"><td colspan="3"><strong>TOTAL CERTIFICADO</strong></td><td class="center">—</td>
<td class="num" id="tInsM3"><?=np_num($tot['ins_m3'])?></td><td class="num" id="tInsM2"><?=np_num($tot['ins_m2'])?></td><td class="num" id="tInsM1"><?=np_num($tot['ins_m1'])?></td>
<td class="num" id="tM3"><?=np_num($tot['nppf_m3'])?></td><td class="num" id="tM2"><?=np_num($tot['nppf_m2'])?></td><td class="num" id="tM1"><?=np_num($tot['nppf_m1'])?></td>
<td class="center" id="tPctM3"><?=np_pct(np_rate($tot['nppf_m3'],$tot['ins_m3']))?></td>
<td class="center" id="tPctM2"><?=np_pct(np_rate($tot['nppf_m2'],$tot['ins_m2']))?></td>
<td class="center" id="tPctM1"><?=np_pct(np_rate($tot['nppf_m1'],$tot['ins_m1']))?></td>
<td class="num" id="tPre"><?=np_num($tot['prevent_m1'])?></td>
<td class="center" id="tPctPre"><?=np_pct(np_rate($tot['prevent_m1'],$tot['ins_m1']))?></td></tr>
</tbody></table></div></section>
<?php else: ?>
<section class="table-card"><div class="table-head"><strong>Detalle por cuenta · <?=np_h($vendors[np_team_key($liderId,$coachId,$vendorId)]['nombre']??'VENDEDOR')?></strong><span>REVERSO sigue contando como NPPF</span></div>
<div class="table-wrap"><table class="nppf-table nppf-detail-table"><thead><tr><th>Cuenta</th><th>Cosecha</th><th>Instalación</th><th>Corte mensual M1</th><th>Fin precio lista</th><th>Clasificación</th><th>TIPO</th><th>Atraso</th><th>Distrito</th><th>Canal</th><th>Plan</th><th class="num">Factura</th></tr></thead><tbody>
<?php foreach($detailRows as $d):?><tr><td><?=np_h($d['cuenta'])?></td><td><strong><?=np_h(strtoupper($d['_cohort']))?></strong></td><td><?=np_h($d['fecha_activacion'])?></td><td><?=np_h($d['_monthly_cut'])?></td><td><?=np_h($d['_due'])?></td><td><span class="nppf-pill <?=$d['_class']==='PREVENTIVO M1'?'nppf-caution':'nppf-risk'?>"><?=np_h($d['_class'])?></span></td><td><?php if($d['_reverso']):?><span class="nppf-pill nppf-caution" title="El banco revirtió el pago. La cuenta permanece como NPPF.">REVERSO</span><?php else:?><?=np_h($d['tipo'])?><?php endif;?></td><td><?=np_h($d['atraso']??'—')?></td><td><?=np_h($d['distrito'])?></td><td><?=np_h($d['canal_venta'])?></td><td><?=np_h($d['desc_plan'])?></td><td class="num"><?=number_format((float)$d['monto_factura'],2,'.',',')?></td></tr><?php endforeach;?>
<?php if(!$detailRows):?><tr><td colspan="12" class="center">Sin cuentas pendientes certificadas de las cosechas seleccionadas.</td></tr><?php endif;?>
</tbody></table></div></section>
<?php endif;?>
<?php if(in_array($view,['lideres','ranking_coach'],true) && (count($audit['m3'])||count($audit['m2'])||count($audit['m1']))):?>
<div class="nppf-panel nppf-alert"><strong>Conciliación NPPF / HC</strong><p class="nppf-caption">Cuentas pendientes sin asociación certificada: M3 <?=count($audit['m3'])?> · M2 <?=count($audit['m2'])?> · M1 <?=count($audit['m1'])?>. Estas cuentas no se asignan a colaboradores ni se incluyen en los porcentajes del ranking.</p></div>
<?php endif;?>
<?php endif;?>
</main>
<script>
'use strict';
(function(){
 const drop=(id,tr,panel)=>{const box=document.getElementById(id),t=document.getElementById(tr),p=document.getElementById(panel);if(!box||!t||!p)return;t.addEventListener('click',e=>{e.stopPropagation();box.classList.toggle('open')});p.addEventListener('click',e=>e.stopPropagation());document.addEventListener('click',()=>box.classList.remove('open'))};
 drop('weekDrop','weekTrigger','weekPanel');drop('monthDrop','monthTrigger','monthPanel');
 const monthGrid=document.getElementById('nppfMonthGrid');
 if(monthGrid){
   const startInput=document.getElementById('nppfDateStart'),endInput=document.getElementById('nppfDateEnd'),summary=document.getElementById('nppfMonthRangeSummary');
   let start=startInput.value,end=endInput.value,next='start';
   function repaint(){
     if(start>end){const tmp=start;start=end;end=tmp;}
     startInput.value=start;endInput.value=end;
     monthGrid.querySelectorAll('button[data-date]').forEach(b=>{
       const date=b.dataset.date;b.classList.toggle('selected-start',date===start);
       b.classList.toggle('selected-end',date===end);b.classList.toggle('in-range',date>start && date<end);
     });
     if(summary)summary.textContent='Referencia: '+start+' a '+end;
   }
   monthGrid.querySelectorAll('button[data-date]:not([disabled])').forEach(b=>b.addEventListener('click',()=>{
     if(next==='start'){start=b.dataset.date;end=start;next='end';}
     else{end=b.dataset.date;next='start';}repaint();
   }));
   repaint();
 }
 const days=document.querySelectorAll('#daysGrid .weekday-day'),input=document.getElementById('diasInput');
 function syncDays(){if(!input)return;let sel=[...days].filter(b=>b.classList.contains('selected-start')).map(b=>Number(b.dataset.day));if(!sel.length){days[0]?.classList.add('selected-start','selected-end');sel=[1]}input.value=sel.join(',')}
 days.forEach(b=>b.addEventListener('click',()=>{b.classList.toggle('selected-start');b.classList.toggle('selected-end');syncDays()}));
 document.getElementById('fullWeek')?.addEventListener('click',()=>{days.forEach(b=>b.classList.add('selected-start','selected-end'));syncDays()});
 const table=document.getElementById('nppfTable');if(!table)return;
 const tbody=table.tBodies[0],total=document.getElementById('totalRow');
 const rows=()=>[...tbody.querySelectorAll('.data-row')];
 const n=(r,k)=>Number(r.getAttribute('data-'+k.replace(/_/g,'-')))||0;
 let filter='ALL',sortKey='pct_m3',dir='desc';
 function num(x){return Math.round(x).toLocaleString('en-US')};
 function pct(a,b){return b>0?(100*a/b).toFixed(1)+'%':'—'};
 function visible(){return rows().filter(r=>r.style.display!=='none')}
 function paint(){
   rows().forEach(r=>r.style.display=(filter==='ALL'||r.dataset.district===filter)?'':'none');
   const rr=visible();rr.forEach((r,i)=>{r.querySelector('.rank').textContent=i+1});
   const keys=['ins_m3','ins_m2','ins_m1','nppf_m3','nppf_m2','nppf_m1','prevent_m1','reverso'];
   const s=Object.fromEntries(keys.map(k=>[k,rr.reduce((acc,r)=>acc+n(r,k),0)]));
   const set=(id,v)=>{const e=document.getElementById(id);if(e)e.textContent=v};
   for(let m=3;m>=1;m--){set('kpiInsM'+m,num(s['ins_m'+m]));set('kpiM'+m,num(s['nppf_m'+m]));set('kpiM'+m+'Pct',pct(s['nppf_m'+m],s['ins_m'+m]));
     set('tInsM'+m,num(s['ins_m'+m]));set('tM'+m,num(s['nppf_m'+m]));set('tPctM'+m,pct(s['nppf_m'+m],s['ins_m'+m]));}
   set('kpiPre',num(s.prevent_m1));set('kpiPrePct',pct(s.prevent_m1,s.ins_m1));set('kpiReverso',num(s.reverso));
   set('tPre',num(s.prevent_m1));set('tPctPre',pct(s.prevent_m1,s.ins_m1));
   set('visibleCounter','Mostrando '+rr.length+' de '+rows().length+' registros'+(filter==='ALL'?'':' · '+filter));
 }
 document.querySelectorAll('.filter-btn[data-district]').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.filter-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');filter=b.dataset.district;paint()}));
 document.querySelectorAll('.nppf-sort').forEach(th=>th.addEventListener('click',()=>{
   const key=th.dataset.key;dir=(sortKey===key&&dir==='desc')?'asc':'desc';sortKey=key;
   rows().sort((a,b)=>{const aa=n(a,key),bb=n(b,key);return dir==='desc'?bb-aa:aa-bb}).forEach(r=>tbody.insertBefore(r,total));paint();
 }));
 rows().forEach(r=>{r.addEventListener('click',()=>{if(r.dataset.href)location.href=r.dataset.href});r.addEventListener('keydown',e=>{if(e.key==='Enter'&&r.dataset.href)location.href=r.dataset.href})});
 paint();
})();
</script></body></html>
