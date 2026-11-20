<?php
/**
 * TotalXpedient / TalIA - Ranking NPPF v1.0.0 (2026-11-19)
 * Ruta prevista: /plataforma/detalle/ranking_nppf.php
 * Dependencias: /plataforma/conexion.php, /plataforma/includes/motor_hc.php,
 *               /plataforma/includes/sidebar.php, /plataforma/assets/css/xpedient-v2.css
 * Lectura pura. No modifica las seis tablas NPPF ni el Motor HC.
 *
 * REGLAS:
 *  - El período semanal/mensual se refiere al VENCIMIENTO de la primera factura,
 *    NO a la fecha de importación de NPPF (y no a la semana de la instalación).
 *  - Vencimiento: fecha_activacion + 1 mes calendario (día ajustado al último día)
 *    + 5 días. M1 inicia el MISMO día del vencimiento; antes es PREVENTIVO.
 *  - Clasificación al día de consulta; pendiente de pago según ÚLTIMO corte OK.
 *  - TIPO='REVERSO' cuenta como NPPF; etiqueta informativa en nivel CUENTA.
 *  - Denominador operativo: instalaciones certificadas por Motor HC/HIC de la
 *    misma cohorte de vencimiento; NO es el % oficial mensual de SACYCOB.
 *  - Comparaciones BASE/ACTUAL: dos cohortes de vencimiento, evaluadas ambas
 *    contra el MISMO último snapshot NPPF. NO son un histórico "as of".
 *  - Cuentas NPPF no certificadas se muestran en auditoría, NO se asignan a
 *    líderes/coaches/vendedores por coincidencia de nombre.
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
function np_metrics(): array { return ['ins_base'=>0,'ins_actual'=>0,'m1_base'=>0,'m1_actual'=>0,'prevent_base'=>0,'prevent_actual'=>0,'reverso_base'=>0,'reverso_actual'=>0]; }
function np_apply(array &$scores, string $key, string $side, string $metric): void {
    if (!isset($scores[$key])) $scores[$key]=np_metrics();
    $field=$metric.'_'.$side;
    ++$scores[$key][$field];
}
function np_total(array $rows): array {
    $t=np_metrics();
    foreach($rows as $r) foreach($t as $k=>$_) $t[$k]+=(int)($r[$k]??0);
    return $t;
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
    $subtitleRange='Semana '.$prevWeek->format('W').' vs Semana '.$weekCurrent->format('W').' · '.$year;
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
    $subtitleRange=$meses[(int)$prevMonth->format('n')].' vs '.$meses[$month].' · '.$monthYear;
}
$fromDue=min($baseStart,$actualStart);$toDue=max($baseEnd,$actualEnd);
// Un rango amplio alrededor de las cosechas candidatas; el vencimiento exacto
// se calcula posteriormente en PHP (no depende del comportamiento de DATE_ADD MySQL).
$activationFrom=np_date($fromDue)->modify('-51 days')->format('Y-m-d');
$activationTo=np_date($toDue)->modify('-20 days')->format('Y-m-d');
if($activationTo>$now) $activationTo=$now;
if($activationFrom>$activationTo) $activationFrom=$activationTo;

function np_bucket(string $due, string $baseStart,string $baseEnd,string $actualStart,string $actualEnd,array $days,string $periodo): ?string {
    if($periodo==='semanal' && !in_array((int)np_date($due)->format('N'),$days,true))return null;
    if($due>=$actualStart && $due<=$actualEnd)return 'actual';
    if($due>=$baseStart && $due<=$baseEnd)return 'base';
    return null;
}
$catalog=[];$coaches=[];$vendors=[];$records=[];$matched=[];$scores=[];$raw=[];$audit=['base'=>[], 'actual'=>[]];
$photo=null;$vendorNames=[];$sellerPositions=[];$accountDetail=[];
$metricsEmpty=np_metrics();

if(!$errors){
 try {
    $motor=new RankingAtribucionServiceV06($conexion);
    $res=$motor->obtenerInstalacionesJerarquia($activationFrom,$activationTo);
    if(($res['version']??'')!=='0.6.8-hc-historical-seller-name-all-channels')
        throw new RuntimeException('Versión no compatible de motor_hc (se requiere API 0.6.8 de motor 0.6.9).');
    $catalog=$motor->catalogoLideres($activationFrom,$activationTo);
    if(!$catalog) throw new RuntimeException('No hay plazas de líder certificadas por motor_hc en este período.');
    $hic=RankingAtribucionServiceV06::resolverEquivalenciasHic($motor->cargarHic());
    $photo=$motor->construirFotoProductividad($activationFrom,$activationTo,$catalog,$hic['canonicos'],[]);
    foreach($photo['coaches'] as $c){
        $lid=(string)$c['lider_id'];$cid=(string)$c['id'];
        $coaches[np_team_key($lid,$cid)]=['id'=>$cid,'lid'=>$lid,'nombre'=>(string)$c['nombre'],'distrito'=>(string)$c['distrito']];
    }
    foreach($photo['vendedores'] as $key=>$seats){
        foreach($seats as $s){
            if(!empty($s['vacante'])||!empty($s['es_vacante_status'])||empty($s['persona']))continue;
            $pos=(string)$s['persona'];
            $vendors[$key.'|'.$pos]=['id'=>$pos,'nombre'=>(string)$s['nombre'],'fecha_alta'=>(string)($s['fecha_alta']??'')];
        }
    }
    // El motor es la ÚNICA autoridad para jerarquía: no usar vendedor de NPPF para asignar.
    foreach($res['cuentas'] as $event){
        $key=np_account($event['cuenta']??'');
        $date=substr((string)($event['fecha']??''),0,10);
        if($key==='' || !np_date_valid($date)) continue;
        $due=np_invoice_due($date);
        $side=np_bucket($due,$baseStart,$baseEnd,$actualStart,$actualEnd,$days,$periodo);
        if($side===null)continue;
        $lid=(string)($event['lider_id']??'');$co=(string)($event['coach_id']??'');$vendor=(string)($event['vendedor_id']??'');
        if(!isset($catalog[$lid])||$co===''||$vendor==='')continue;
        // Puede ser un vendedor histórico que ya salió de la foto HC vigente.
        $ck=np_team_key($lid,$co);
        if(!isset($coaches[$ck]))$coaches[$ck]=['id'=>$co,'lid'=>$lid,'nombre'=>(string)($event['coach_evento']??'COACH HISTÓRICO'),'distrito'=>(string)$catalog[$lid]['distrito']];
        $vk=$ck.'|'.$vendor;
        if(!isset($vendors[$vk]))$vendors[$vk]=['id'=>$vendor,'nombre'=>(string)($event['vendedor_nombre']??'VENDEDOR NO IDENTIFICADO'),'fecha_alta'=>(string)($event['vendedor_fecha_alta']??'')];
        $event['_due']=$due;$event['_side']=$side;
        if(isset($matched[$key])) { // Indica anomalía en fuente de instalaciones, sin duplicar.
            $warnings['duplicada']='Motor HC devolvió una cuenta en más de una asignación; revisa conciliación.';
            continue;
        }
        $matched[$key]=$event;
        foreach([$lid,$ck,$vk] as $hier)np_apply($scores,$hier,$side,'ins');
    }
    // NPPF: seleccionar SOLO el corte publicado actual, nunca mezclar cortes.
    $s=$conexion->prepare("SELECT cuenta,cuenta_clave,fecha_activacion,tipo,atraso,distrito,region,canal_venta,desc_plan,monto_factura,vendedor
        FROM nppf_registros
        WHERE presente_en_ultimo_corte=1 AND UPPER(TRIM(region))='SUR'
          AND fecha_activacion>=? AND fecha_activacion<=?");
    if(!$s)throw new RuntimeException('No pudo prepararse la consulta de NPPF: '.$conexion->error);
    $s->bind_param('ss',$activationFrom,$activationTo);$s->execute();
    $rs=$s->get_result();
    $seenRaw=[];
    while($r=$rs->fetch_assoc()){
        $date=(string)($r['fecha_activacion']??'');if(!np_date_valid($date))continue;
        $due=np_invoice_due($date);
        $side=np_bucket($due,$baseStart,$baseEnd,$actualStart,$actualEnd,$days,$periodo);
        if($side===null)continue;
        $key=np_account($r['cuenta_clave']??$r['cuenta']);
        $rawId=$key.'|'.$date;
        if(isset($seenRaw[$rawId])){ $audit[$side][$rawId]='DUPLICADA EN NPPF';continue; }
        $seenRaw[$rawId]=true;
        $r['_due']=$due;$r['_side']=$side;$r['_class']=($eval>=$due?'M1':'PREVENTIVO');
        $r['_reverso']=(strtoupper(trim((string)$r['tipo']))==='REVERSO');
        if(isset($raw[$side][$key])){ $audit[$side][$key]='DUPLICADO POR CUENTA'; continue; }
        $raw[$side][$key]=$r;
    }
    $s->close();
    foreach($raw as $side=>$source){
        foreach($source as $key=>$r){
            $ev=$matched[$key]??null;
            if(!$ev){$audit[$side][$key]='SIN INSTALACIÓN CERTIFICADA';continue;}
            // Un match por cuenta NUNCA debe atribuir un evento de otra activación.
            $delta=abs((int)np_date(substr((string)$ev['fecha'],0,10))->diff(np_date((string)$r['fecha_activacion']))->format('%r%a'));
            if($delta>1 || $ev['_side']!==$side){$audit[$side][$key]='FECHA DE ACTIVACIÓN DIFERENTE';continue;}
            $lid=(string)$ev['lider_id'];$co=(string)$ev['coach_id'];$ven=(string)$ev['vendedor_id'];
            if(np_district((string)$r['distrito']) !== np_district((string)($catalog[$lid]['distrito']??''))){
                $audit[$side][$key]='DISTRITO NPPF DIFERENTE DE HC';continue;
            }
            $metric=$r['_class']==='M1'?'m1':'prevent';
            foreach([$lid,np_team_key($lid,$co),np_team_key($lid,$co,$ven)] as $hier){
                np_apply($scores,$hier,$side,$metric);
                if($r['_reverso'])np_apply($scores,$hier,$side,'reverso');
            }
            $accountDetail[$lid.'|'.$co.'|'.$ven][$key]=$r;
        }
    }
    foreach($catalog as $lid=>$l){if(!isset($scores[$lid]))$scores[$lid]=np_metrics();}
    foreach($coaches as $ck=>$c){if(!isset($scores[$ck]))$scores[$ck]=np_metrics();}
    foreach($vendors as $vk=>$v){if(!isset($scores[$vk]))$scores[$vk]=np_metrics();}
    $notCertified=count($audit['actual']);
    if($notCertified) $warnings['pendientes']="{$notCertified} cuentas NPPF del período actual no pudieron asignarse de forma certificada a un líder; aparecen en auditoría, no en el ranking.";
    if($corte<$eval)$warnings['frescura']='Pendientes observados al '.$corte.'; clasificación M1/Preventivo calculada al '.$eval.'. Algunos clientes pudieron pagar después del corte.';
 }catch(Throwable $e){$errors[]='Motor NPPF / HC: '.$e->getMessage();}
}

$rows=[];$detailRows=[];$viewLabel='Ranking NPPF';
if(!$errors){
    $scopeRows=[];
    if($view==='lideres'){
        $viewLabel='Ranking NPPF · Líder';
        foreach($catalog as $lid=>$l)$scopeRows[]=['key'=>$lid,'distrito'=>(string)$l['distrito'],'entidad'=>(string)$l['nombre'],'lid'=>$lid,'co'=>'','v'=>''];
    }elseif($view==='ranking_coach' || $view==='coaches'){
        $viewLabel=$view==='ranking_coach'?'Ranking NPPF · Coach':'Coaches del Líder';
        if($view==='coaches' && !isset($catalog[$liderId]))$errors[]='Líder no identificado en el catálogo HC.';
        foreach($coaches as $k=>$c){
            if($view==='coaches' && $c['lid']!==$liderId)continue;
            $scopeRows[]=['key'=>$k,'distrito'=>$c['distrito'],'entidad'=>$c['nombre'],'lid'=>$c['lid'],'co'=>$c['id'],'v'=>''];
        }
    }elseif($view==='vendedores' || $view==='cuentas'){
        $viewLabel=$view==='vendedores'?'Vendedores del Coach':'Detalle de cuentas NPPF';
        $keyCoach=np_team_key($liderId,$coachId);
        if(!isset($catalog[$liderId]) || !isset($coaches[$keyCoach]))$errors[]='Líder o Coach no identificado en HC.';
        foreach($vendors as $k=>$v){
            if(!str_starts_with($k,$keyCoach.'|'))continue;
            if($view==='cuentas' && $v['id']!==$vendorId)continue;
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
        $row['pct_m1_actual']=np_rate($row['m1_actual'],$row['ins_actual']);
        $row['pct_prevent_actual']=np_rate($row['prevent_actual'],$row['ins_actual']);
        $rows[]=$row;
    }
    // Ranking de riesgo: el porcentaje M1 más alto aparece arriba. Sin denominador al final.
    usort($rows,static function($a,$b){
        $aa=$a['pct_m1_actual'];$bb=$b['pct_m1_actual'];
        if($aa===null && $bb!==null)return 1;
        if($bb===null && $aa!==null)return -1;
        return ($bb<=>$aa) ?: ($b['m1_actual']<=>$a['m1_actual']) ?: strcmp($a['entidad'],$b['entidad']);
    });
    if($view==='cuentas' && $scopeRows){
        foreach($scopeRows as $r){
            foreach($accountDetail[$r['key']]??[] as $record)$detailRows[]=$record;
        }
        usort($detailRows,static fn($a,$b)=>($a['_class']==='M1'?0:1)<=>($b['_class']==='M1'?0:1) ?: strcmp($a['_due'],$b['_due']));
    }
}
$tot=np_total($rows);
if($view==='cuentas' && $rows)$tot=$scores[$rows[0]['key']]??np_metrics();
$totM1=np_rate($tot['m1_actual'],$tot['ins_actual']);
$totPrev=np_rate($tot['prevent_actual'],$tot['ins_actual']);
$navLevel=['view'=>$view,'lider_id'=>$liderId,'coach_pos'=>$coachId,'folio'=>$vendorId,'distrito'=>null];
$rootLink=np_q(['view'=>'lideres','lider_id'=>null,'coach_pos'=>null,'folio'=>null]);
$coachLink=np_q(['view'=>'ranking_coach','lider_id'=>null,'coach_pos'=>null,'folio'=>null]);
$backLeaders=np_q(['view'=>'coaches','coach_pos'=>null,'folio'=>null]);
$backVendors=np_q(['view'=>'vendedores','folio'=>null]);
$monthsDisplay=$meses[$month];
$previousMonthsLabel=$meses[(int)$prevMonth->format('n')];
$periodTitle=$periodo==='semanal'?'Semana '.$week.' · '.$year:$monthsDisplay.' · '.$monthYear;
$gridStart=$monthCurrent->modify('-'.((int)$monthCurrent->format('N')-1).' days');
$roleNames=['admin'=>'Administrador','director_regional'=>'Director Regional','director_distrital'=>'Director Distrital','lider'=>'Líder','coach'=>'Coach','vendedor'=>'Vendedor'];
$normalizedCohortStart=np_date($actualStart)->modify('-36 days')->format('Y-m-d');
$normalizedCohortEnd=np_date($actualEnd)->modify('-33 days')->format('Y-m-d');
// Extraer los días de activación exactos equivalentes al rango de vencimiento:
$actualActivationDays=[];
for($t=np_date($activationFrom);$t<=np_date($activationTo);$t=$t->modify('+1 day')){
    $du=np_invoice_due($t->format('Y-m-d'));
    if(np_bucket($du,$baseStart,$baseEnd,$actualStart,$actualEnd,$days,$periodo)==='actual')$actualActivationDays[]=$t->format('Y-m-d');
}
if($actualActivationDays){$normalizedCohortStart=min($actualActivationDays);$normalizedCohortEnd=max($actualActivationDays);}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ranking NPPF — TotalXpedient</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=162">
<style>
body.page-ranking .nppf-caption{font-size:12px;color:#65708a;line-height:1.6}
body.page-ranking .nppf-note{background:#fffaf0;border:1px solid #f3dfb4;padding:10px 14px;border-radius:12px;margin:10px 0;color:#885b19;font-size:12px}
body.page-ranking .nppf-good{background:#e7f8ed;color:#13623a}.nppf-risk{color:#a22b3a;background:#fee8ed}.nppf-caution{color:#9a5a10;background:#fff3d3}.nppf-muted{color:#667085;background:#eff1f7}
body.page-ranking .nppf-pill{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
body.page-ranking .nppf-table{min-width:1570px;width:100%}body.page-ranking .nppf-table th{white-space:nowrap;padding:11px 8px;font-size:11px;vertical-align:middle}body.page-ranking .nppf-table td{padding:12px 8px;font-size:12px}
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
body.page-ranking .nppf-rangepanel{min-width:315px}body.page-ranking .nppf-detail-table{min-width:1160px}
body.page-ranking .nppf-raw{font-weight:700;color:#5a2678}
body.page-ranking .nppf-filters{display:flex;align-items:center;flex-wrap:wrap;gap:7px}
@media(max-width:860px){body.page-ranking .nppf-month-form{display:flex;flex-wrap:wrap;gap:6px}}
</style></head>
<body class="page-ranking">
<?php $current_page='ranking'; include __DIR__.'/../includes/sidebar.php'; ?>
<main class="main">
<section class="topbar">
 <div class="page-title">
  <h1>Ranking NPPF <span class="week-pill"><?=np_h($periodTitle)?></span></h1>
  <p>Cosechas por vencimiento de primera factura · <?=np_h($subtitleRange)?> · <?=np_h($roleNames[$rol]??$rol)?></p>
  <p class="nppf-caption">Pendientes según archivo del <strong><?=np_h($corte)?></strong> · Clasificación evaluada al <strong><?=np_h($eval)?></strong> · M1 inicia el último día de precio de lista</p>
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
       <div class="range-panel-title">Selecciona días comparables · Semana <?=$week?></div>
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
      <div class="range-panel-title">Selecciona rango de <?=$monthsDisplay?> · <?=$monthYear?></div>
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
<section class="cards" id="nppfCards">
 <div class="card"><div class="label">Cosecha · Instalaciones <?=np_h($labelActual)?></div><div class="value" id="kpiIns"><?=np_num($tot['ins_actual'])?></div><div class="hint">Base <?=np_h($labelBase)?>: <span id="kpiBaseIns"><?=np_num($tot['ins_base'])?></span><div class="nppf-card-hint">INS entre <?=np_h($normalizedCohortStart)?> y <?=np_h($normalizedCohortEnd)?></div></div></div>
 <div class="card"><div class="label">NPPF M1 · <?=np_h($labelActual)?></div><div class="value" id="kpiM1"><?=np_num($tot['m1_actual'])?></div><div class="hint"><span id="kpiM1Pct"><?=np_pct($totM1)?></span> de instalaciones · Base <span id="kpiM1Base"><?=np_num($tot['m1_base'])?></span></div></div>
 <div class="card"><div class="label">NPPF M1 Preventivo</div><div class="value" id="kpiPre"><?=np_num($tot['prevent_actual'])?></div><div class="hint"><span id="kpiPrePct"><?=np_pct($totPrev)?></span> de instalaciones · Base <span id="kpiPreBase"><?=np_num($tot['prevent_base'])?></span></div></div>
 <div class="card"><div class="label">Pendientes NPPF · <?=np_h($labelActual)?></div><div class="value" id="kpiPend"><?=np_num($tot['m1_actual']+$tot['prevent_actual'])?></div><div class="hint">M1 + Preventivo · <span id="kpiReverso"><?=np_num($tot['reverso_actual'])?></span> REVERSO incluidos</div></div>
</section>
<section class="nppf-panel" style="margin-top:12px">
 <strong>Metodología NPPF</strong><div class="nppf-caption">Cada período agrupa <strong>cuentas por semana/mes de vencimiento de su primera factura</strong>; la fecha de instalación es aproximadamente un mes antes. Los porcentajes operativos se calculan sobre instalaciones de esa misma cohorte <strong>certificadas por Motor HC</strong>. Ambos períodos se evalúan contra el último archivo publicado, por lo que <strong>no representan saldos históricos a la fecha de cada semana</strong>. La meta trimestral oficial no se aplica automáticamente como semáforo a estas tasas.</div>
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
<div class="table-wrap"><table class="nppf-table" id="nppfTable"><thead>
<tr><th rowspan="2" class="center">#</th><th rowspan="2">Distrito</th><th rowspan="2"><?=($view==='lideres')?'Líder':($view==='vendedores'?'Vendedor':'Coach')?></th><th rowspan="2" class="center nppf-sort" data-key="antiguedad_meses">Antigüedad<br>prom. equipo ↕</th>
 <th colspan="3" class="nppf-group ins">COSECHA · INSTALACIONES</th><th colspan="4" class="nppf-group m1">NPPF M1</th><th colspan="4" class="nppf-group prevent">NPPF M1 PREVENTIVO</th></tr>
<tr><th class="num nppf-sort" data-key="ins_base">INS<br><?=np_h($labelBase)?> ↕</th><th class="num nppf-sort" data-key="ins_actual">INS<br><?=np_h($labelActual)?> ↕</th><th class="num nppf-sort" data-key="dif">Dif. ↕</th>
<th class="num nppf-sort" data-key="m1_base"># <?=np_h($labelBase)?> ↕</th><th class="num nppf-sort" data-key="m1_actual"># <?=np_h($labelActual)?> ↕</th><th class="center nppf-sort" data-key="pct_m1_base">% <?=np_h($labelBase)?> ↕</th><th class="center nppf-sort" data-key="pct_m1_actual">% <?=np_h($labelActual)?> ↕</th>
<th class="num nppf-sort" data-key="prevent_base"># <?=np_h($labelBase)?> ↕</th><th class="num nppf-sort" data-key="prevent_actual"># <?=np_h($labelActual)?> ↕</th><th class="center nppf-sort" data-key="pct_prevent_base">% <?=np_h($labelBase)?> ↕</th><th class="center nppf-sort" data-key="pct_prevent_actual">% <?=np_h($labelActual)?> ↕</th>
</tr></thead><tbody>
<?php foreach($rows as $i=>$r):
 $pM1B=np_rate($r['m1_base'],$r['ins_base']);$pPreB=np_rate($r['prevent_base'],$r['ins_base']);
 $href=$view==='lideres'?np_q(['view'=>'coaches','lider_id'=>$r['lid'],'coach_pos'=>null,'folio'=>null])
    :(in_array($view,['ranking_coach','coaches'],true)?np_q(['view'=>'vendedores','lider_id'=>$r['lid'],'coach_pos'=>$r['co'],'folio'=>null]):np_q(['view'=>'cuentas','lider_id'=>$r['lid'],'coach_pos'=>$r['co'],'folio'=>$r['v']]));
 $dataset=['ins_base'=>$r['ins_base'],'ins_actual'=>$r['ins_actual'],'dif'=>$r['ins_actual']-$r['ins_base'],'m1_base'=>$r['m1_base'],'m1_actual'=>$r['m1_actual'],'prevent_base'=>$r['prevent_base'],'prevent_actual'=>$r['prevent_actual'],'pct_m1_base'=>$pM1B??-1,'pct_m1_actual'=>$r['pct_m1_actual']??-1,'pct_prevent_base'=>$pPreB??-1,'pct_prevent_actual'=>$r['pct_prevent_actual']??-1,'antiguedad_meses'=>$r['antiguedad_meses']??-1,'reverso_actual'=>$r['reverso_actual']];
?><tr class="nppf-row data-row" tabindex="0" data-district="<?=np_h($r['distrito'])?>" data-href="<?=np_h($href)?>" <?php foreach($dataset as $k=>$v)echo ' data-'.str_replace('_','-', $k).'="'.np_h($v).'"';?> >
<td class="center rank"><?=$i+1?></td><td><?=np_h($r['distrito'])?></td><td class="nppf-entity"><?=np_h($r['entidad'])?></td><td class="center"><?=np_h($r['antiguedad'])?></td>
<td class="num"><?=np_num($r['ins_base'])?></td><td class="num"><?=np_num($r['ins_actual'])?></td><td class="num"><?=np_num($r['ins_actual']-$r['ins_base'])?></td>
<td class="num"><?=np_num($r['m1_base'])?></td><td class="num"><?=np_num($r['m1_actual'])?></td><td class="center"><?=np_pct($pM1B)?></td><td class="center"><span class="nppf-pill <?=np_m1_class($r['pct_m1_actual'])?>"><?=np_pct($r['pct_m1_actual'])?></span></td>
<td class="num"><?=np_num($r['prevent_base'])?></td><td class="num"><?=np_num($r['prevent_actual'])?></td><td class="center"><?=np_pct($pPreB)?></td><td class="center"><span class="nppf-pill nppf-caution"><?=np_pct($r['pct_prevent_actual'])?></span></td></tr>
<?php endforeach;?>
<tr class="total-row" id="totalRow"><td colspan="3"><strong>TOTAL CERTIFICADO</strong></td><td class="center">—</td><td class="num" id="tInsB"><?=np_num($tot['ins_base'])?></td><td class="num" id="tInsA"><?=np_num($tot['ins_actual'])?></td><td class="num" id="tDif"><?=np_num($tot['ins_actual']-$tot['ins_base'])?></td><td class="num" id="tM1B"><?=np_num($tot['m1_base'])?></td><td class="num" id="tM1A"><?=np_num($tot['m1_actual'])?></td><td class="center" id="tPctM1B"><?=np_pct(np_rate($tot['m1_base'],$tot['ins_base']))?></td><td class="center" id="tPctM1A"><?=np_pct($totM1)?></td><td class="num" id="tPreB"><?=np_num($tot['prevent_base'])?></td><td class="num" id="tPreA"><?=np_num($tot['prevent_actual'])?></td><td class="center" id="tPctPreB"><?=np_pct(np_rate($tot['prevent_base'],$tot['ins_base']))?></td><td class="center" id="tPctPreA"><?=np_pct($totPrev)?></td></tr>
</tbody></table></div></section>
<?php else: ?>
<section class="table-card"><div class="table-head"><strong>Detalle por cuenta · <?=np_h($vendors[np_team_key($liderId,$coachId,$vendorId)]['nombre']??'VENDEDOR')?></strong><span>REVERSO no se excluye de NPPF</span></div>
<div class="table-wrap"><table class="nppf-table nppf-detail-table"><thead><tr><th>Cuenta</th><th>Instalación</th><th>Vencimiento</th><th>Estado M1</th><th>TIPO</th><th>Atraso</th><th>Distrito</th><th>Canal</th><th>Plan</th><th class="num">Factura</th></tr></thead><tbody>
<?php foreach($detailRows as $d):?><tr><td><?=np_h($d['cuenta'])?></td><td><?=np_h($d['fecha_activacion'])?></td><td><?=np_h($d['_due'])?></td><td><span class="nppf-pill <?=$d['_class']==='M1'?'nppf-risk':'nppf-caution'?>"><?=np_h($d['_class'])?></span></td><td><?php if($d['_reverso']):?><span class="nppf-pill nppf-caution" title="Pago realizado, posteriormente revertido por el banco. Sigue siendo NPPF.">REVERSO</span><?php else:?><?=np_h($d['tipo'])?><?php endif;?></td><td><?=np_h($d['atraso']??'—')?></td><td><?=np_h($d['distrito'])?></td><td><?=np_h($d['canal_venta'])?></td><td><?=np_h($d['desc_plan'])?></td><td class="num"><?=number_format((float)$d['monto_factura'],2,'.',',')?></td></tr><?php endforeach;?>
<?php if(!$detailRows):?><tr><td colspan="10" class="center">Sin cuentas pendientes certificadas para esta cohorte.</td></tr><?php endif;?>
</tbody></table></div></section>
<?php endif;?>
<?php if(in_array($view,['lideres','ranking_coach'],true) && (count($audit['actual'])||count($audit['base']))):?>
<div class="nppf-panel nppf-alert"><strong>Conciliación NPPF / HC</strong><p class="nppf-caption">Cuentas pendientes sin asociación certificada: <?=count($audit['base'])?> en <?=np_h($labelBase)?> y <?=count($audit['actual'])?> en <?=np_h($labelActual)?>. Estas cuentas no se asignan a ningún colaborador ni se incluyen en los porcentajes del ranking.</p></div>
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
     if(summary)summary.textContent='Vencimientos: '+start+' a '+end;
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
 const tbody=table.tBodies[0],total= document.getElementById('totalRow');
 const rows=()=>[...tbody.querySelectorAll('.data-row')];
 const n=(r,k)=>Number(r.getAttribute('data-'+k.replace(/_/g,'-')))||0;
 let filter='ALL',sortKey='pct_m1_actual',dir='desc';
 function num(x){return Math.round(x).toLocaleString('en-US')};function pct(a,b){return b>0?(100*a/b).toFixed(1)+'%':'—'};
 function visible(){return rows().filter(r=>r.style.display!=='none')}
 function paint(){
   rows().forEach(r=>r.style.display=(filter==='ALL'||r.dataset.district===filter)?'':'none');
   const rr=visible();rr.forEach((r,i)=>{r.querySelector('.rank').textContent=i+1});
   const keys=['ins_base','ins_actual','m1_base','m1_actual','prevent_base','prevent_actual','reverso_actual'];
   const s=Object.fromEntries(keys.map(k=>[k,rr.reduce((acc,r)=>acc+n(r,k),0)]));
   const set=(id,v)=>{let e=document.getElementById(id);if(e)e.textContent=v};
   set('kpiIns',num(s.ins_actual));set('kpiBaseIns',num(s.ins_base));set('kpiM1',num(s.m1_actual));set('kpiM1Pct',pct(s.m1_actual,s.ins_actual));set('kpiM1Base',num(s.m1_base));
   set('kpiPre',num(s.prevent_actual));set('kpiPrePct',pct(s.prevent_actual,s.ins_actual));set('kpiPreBase',num(s.prevent_base));set('kpiPend',num(s.m1_actual+s.prevent_actual));set('kpiReverso',num(s.reverso_actual));
   set('tInsB',num(s.ins_base));set('tInsA',num(s.ins_actual));set('tDif',num(s.ins_actual-s.ins_base));set('tM1B',num(s.m1_base));set('tM1A',num(s.m1_actual));set('tPreB',num(s.prevent_base));set('tPreA',num(s.prevent_actual));
   set('tPctM1B',pct(s.m1_base,s.ins_base));set('tPctM1A',pct(s.m1_actual,s.ins_actual));set('tPctPreB',pct(s.prevent_base,s.ins_base));set('tPctPreA',pct(s.prevent_actual,s.ins_actual));
   set('visibleCounter','Mostrando '+rr.length+' de '+rows().length+' registros'+(filter==='ALL'?'':' · '+filter));
 }
 document.querySelectorAll('.filter-btn[data-district]').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.filter-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');filter=b.dataset.district;paint()}));
 document.querySelectorAll('.nppf-sort').forEach(th=>th.addEventListener('click',()=>{const key=th.dataset.key;dir=(sortKey===key&&dir==='desc')?'asc':'desc';sortKey=key;rows().sort((a,b)=>{const aa=n(a,key),bb=n(b,key);return dir==='desc'?bb-aa:aa-bb}).forEach(r=>tbody.insertBefore(r,total));paint()}));
 rows().forEach(r=>{r.addEventListener('click',()=>{if(r.dataset.href)location.href=r.dataset.href});r.addEventListener('keydown',e=>{if(e.key==='Enter'&&r.dataset.href)location.href=r.dataset.href})});
 paint();
})();
</script></body></html>
