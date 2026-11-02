<?php
/**
 * Talia Mix Comercial Standalone v1.0.5 — módulo de /plataforma.
 * Requiere sesión del sistema, conexion.php, motor_hc.php y motor_comercial.php 0.2.2.
 * No carga componentes de WordPress ni modifica la conexión compartida.
 */
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['usuario'])) {
    if (isset($_GET['api'])) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'Sesión no iniciada']); }
    else { header('Location: ../login.php'); }
    exit;
}
require_once dirname(__DIR__) . '/conexion.php';
require_once dirname(__DIR__) . '/includes/motor_hc.php';
require_once dirname(__DIR__) . '/includes/motor_comercial.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) { http_response_code(500); exit('Conexión MySQL no disponible'); }
if (!class_exists('RankingAtribucionServiceV06') || !class_exists('MotorComercial') || !method_exists('MotorComercial','distribucionVelocidadHc')) {
    http_response_code(500); exit('Se requiere Motor HC y Motor Comercial con extensión de velocidades');
}
$conexion->set_charset('utf8mb4');
if (empty($_SESSION['talia_mix_csrf'])) $_SESSION['talia_mix_csrf'] = bin2hex(random_bytes(32));

function mxQ(mysqli $db, string $sql, string $types = '', array $values = []): array {
    $st = $db->prepare($sql);
    if (!$st) throw new RuntimeException('No se pudo preparar consulta de HC');
    if ($types !== '') $st->bind_param($types, ...$values);
    if (!$st->execute()) { $st->close(); throw new RuntimeException('Consulta de HC fallida'); }
    $r = $st->get_result(); $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $st->close(); return $rows;
}
function mxUpper(?string $v): string { return RankingAtribucionServiceV06::norm($v); }
function mxDistrict(?string $s): ?string { return RankingAtribucionServiceV06::normalizarDistrito($s); }
function mxLevel(?string $p): string {
    $p=mxUpper($p);
    if (str_contains($p,'DIRECTOR DISTRITAL')) return 'director';
    if (str_contains($p,'LIDER VENTAS')) return 'lider';
    if (str_contains($p,'COACH')) return 'coach';
    if (in_array($p,['VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO','PROMOVENDEDOR PUNTO DE VENTA'],true)) return 'vendedor';
    return 'otro';
}
function mxRoster(mysqli $db): array {
    $last = mxQ($db, 'SELECT anio,semana FROM hc ORDER BY anio DESC,semana DESC LIMIT 1');
    if (!$last) throw new RuntimeException('No hay fotografía HC disponible');
    $a=(int)$last[0]['anio']; $w=(int)$last[0]['semana'];
    $records=mxQ($db,'SELECT id_posicion,posicion_lr,posicion,distrito,nombre_colaborador,numero_talento_gs,puesto_lr FROM hc WHERE anio=? AND semana=?','ii',[$a,$w]);
    $byId=[]; $children=[];
    foreach($records as $r) {
        $pos=trim((string)$r['id_posicion']); $d=mxDistrict($r['distrito']??'');
        if($pos==='' || $d===null) continue;
        $r['nivel']=mxLevel((string)$r['posicion']); $r['distrito_can']=$d;
        $byId[$pos] = $r;
        $parent=trim((string)$r['posicion_lr']);
        if($parent!=='' && $parent!==$pos) $children[$parent][$pos]=true;
    }
    return ['by_id'=>$byId,'children'=>$children,'anio'=>$a,'semana'=>$w];
}
function mxIdentity(array $roster): array {
    $role=strtolower(trim((string)($_SESSION['rol']??'')));
    $admin=($role==='admin' || $role==='director_regional');
    if ($admin) return ['admin'=>true,'role'=>$role,'root'=>null,'allowed'=>array_map('strval',array_keys($roster['by_id'])),'name'=>(string)$_SESSION['usuario']];
    $tal=trim((string)($_SESSION['numero_talento_gs']??''));
    $pos=trim((string)($_SESSION['id_posicion']??''));
    $matches=[];
    if ($tal!=='') {
        foreach ($roster['by_id'] as $id=>$row) if ((string)$row['numero_talento_gs']===$tal) $matches[]=$id;
        if (count($matches)===1) $pos=(string)$matches[0];
        elseif(count($matches)>1) throw new RuntimeException('Identidad HC duplicada; no se amplía acceso');
    }
    if ($pos==='' || !isset($roster['by_id'][$pos])) throw new RuntimeException('La sesión no tiene posición HC vigente; acceso denegado');
    if ($tal!=='' && (string)$roster['by_id'][$pos]['numero_talento_gs']!==$tal) throw new RuntimeException('Folio de sesión no coincide con HC');
    $seen=[]; $queue=[$pos];
    while($queue) {
        $id=array_shift($queue);
        if(isset($seen[$id])) continue;
        $seen[$id]=true;
        foreach(array_keys($roster['children'][$id]??[]) as $child) {
            if(isset($roster['by_id'][$child]) && $roster['by_id'][$child]['distrito_can']===$roster['by_id'][$pos]['distrito_can']) $queue[]=$child;
        }
    }
    return ['admin'=>false,'role'=>$role,'root'=>$pos,'allowed'=>array_map('strval',array_keys($seen)),'name'=>(string)$roster['by_id'][$pos]['nombre_colaborador']];
}
function mxOptions(array $roster,array $who): array {
    $out=[];
    foreach($who['allowed'] as $id) {
        $r=$roster['by_id'][$id]??null;
        if(!$r || !in_array($r['nivel'],['director','lider','coach','vendedor'],true))continue;
        $out[]=['id'=>(string)$id,'name'=>(string)$r['nombre_colaborador'],'district'=>$r['distrito_can'],'level'=>$r['nivel']];
    }
    $ord=['director'=>0,'lider'=>1,'coach'=>2,'vendedor'=>3];
    usort($out,static fn($a,$b)=>[$ord[$a['level']],$a['district'],$a['name']]<=>[$ord[$b['level']],$b['district'],$b['name']]);
    return $out;
}
function mxDate(string $v): DateTimeImmutable {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    if(!$d || $d->format('Y-m-d')!==$v) throw new InvalidArgumentException('Fecha inválida');
    return $d;
}
function mxShiftMonth(string $v): string {
    $d=mxDate($v);$anchor=$d->modify('first day of this month')->modify('-1 month');
    return $anchor->setDate((int)$anchor->format('Y'),(int)$anchor->format('n'),min((int)$anchor->format('t'),(int)$d->format('j')))->format('Y-m-d');
}
function mxPeriods(mysqli $db,array $input): array {
    $last=mxQ($db,'SELECT MAX(fecha) AS ultima FROM instalaciones WHERE fecha < CURDATE()');
    $cut=(string)($last[0]['ultima']??'');
    $limit = preg_match('/^\d{4}-\d{2}-\d{2}$/',$cut) ? mxDate($cut) : new DateTimeImmutable('yesterday');
    $mode=(($input['periodo']??'mensual')==='mensual') ? 'mensual' : 'semanal';
    if($mode==='semanal') {
        $year=(int)($input['anio_semana']??$limit->format('o'));
        $week=(int)($input['semana']??$limit->format('W'));
        if($year<2020 || $year>2100 || $week<1 || $week>53) throw new InvalidArgumentException('Semana fuera de rango');
        $mon=(new DateTimeImmutable('today'))->setISODate($year,$week,1);
        if ((int)$mon->format('o')!==$year || (int)$mon->format('W')!==$week) throw new InvalidArgumentException('Semana ISO inexistente');
        $limitMon=$limit->modify('monday this week');
        if($mon>$limitMon) $mon=$limitMon;
        $avail=[];
        for($n=1;$n<=7;$n++) if($mon->modify('+'.($n-1).' days')<=$limit) $avail[]=$n;
        $days=[];$raw=(string)($input['dias_semana']??'');
        if($raw!=='') foreach(explode(',',$raw) as $n) if(ctype_digit($n) && in_array((int)$n,$avail,true))$days[]=(int)$n;
        $days=array_values(array_unique($days?:$avail));sort($days);
        $a=$mon->modify('+'.(min($days)-1).' days');$b=$mon->modify('+'.(max($days)-1).' days');
        return ['mode'=>$mode,'current'=>['from'=>$a->format('Y-m-d'),'to'=>$b->format('Y-m-d'),'days'=>$days],
            'previous'=>['from'=>$a->modify('-7 days')->format('Y-m-d'),'to'=>$b->modify('-7 days')->format('Y-m-d'),'days'=>$days],
            'week'=>(int)$mon->format('W'),'week_year'=>(int)$mon->format('o'),'available_days'=>$avail,
            'limit'=>$limit->format('Y-m-d')];
    }
    $ref=(string)($input['mes_ref']??$limit->format('Y-m'));
    if(!preg_match('/^\d{4}-\d{2}$/',$ref)) throw new InvalidArgumentException('Mes inválido');
    $first=mxDate($ref.'-01');
    if($first>$limit->modify('first day of this month')) $first=$limit->modify('first day of this month');
    $start=$first; $end=$first->modify('last day of this month');
    if($end>$limit)$end=$limit;
    if(($input['rango_mode']??'')==='custom') {
        $start=mxDate((string)($input['fecha_inicio']??''));
        $end=mxDate((string)($input['fecha_fin']??''));
        if($start>$end || $end>$limit || $start->format('Y')<'2020') throw new InvalidArgumentException('Rango mensual no permitido');
        if($start->diff($end)->days>365) throw new InvalidArgumentException('Rango máximo de 366 días');
    }
    return ['mode'=>$mode,'current'=>['from'=>$start->format('Y-m-d'),'to'=>$end->format('Y-m-d'),'days'=>[]],
        'previous'=>['from'=>mxShiftMonth($start->format('Y-m-d')),'to'=>mxShiftMonth($end->format('Y-m-d')),'days'=>[]],
        'month'=>$end->format('Y-m'),'limit'=>$limit->format('Y-m-d')];
}
function mxEvents(RankingAtribucionServiceV06 $hc,array $period): array {
    return $hc->obtenerInstalacionesJerarquia($period['from'],$period['to'],null,null,null,$period['days']);
}
function mxSlice(array $events,array $node): array {
    return array_values(array_filter($events,static function($e)use($node){
        $lid=(string)($e['lider_id']??'');$coach=(string)($e['coach_id']??'');$seller=(string)($e['vendedor_id']??'');
        $district=strstr($lid,'|',true)?:'';
        if(isset($node['district']) && $node['district']!==$district) return false;
        if(isset($node['leader']) && $node['leader']!==$lid) return false;
        if(isset($node['coach']) && $node['coach']!==$coach) return false;
        if(isset($node['seller']) && !in_array($seller,$node['seller'],true)) return false;
        return true;
    }));
}
function mxScopeForMix(array $input,array $roster,array $identity,RankingAtribucionServiceV06 $hc): array {
    $id=trim((string)($input['scope_pos']??''));
    if($id==='') {
        if($identity['admin']) return ['kind'=>'all','scope'=>[],'label'=>'Mi tablero / Región'];
        $id=(string)$identity['root'];
    }
    if(!in_array($id,$identity['allowed'],true) || !isset($roster['by_id'][$id])) throw new RuntimeException('Posición jerárquica no autorizada');
    $r=$roster['by_id'][$id];$d=$r['distrito_can'];
    switch($r['nivel']) {
        case 'director': return ['kind'=>'district','scope'=>['district'=>$d],'label'=>$r['nombre_colaborador']];
        case 'lider': return ['kind'=>'hc','scope'=>['district'=>$d,'leader'=>$d.'|'.$id],'label'=>$r['nombre_colaborador']];
        case 'coach': return ['kind'=>'hc','scope'=>['district'=>$d,'coach'=>$id],'label'=>$r['nombre_colaborador']];
        case 'vendedor':
            $tal=(string)$r['numero_talento_gs'];
            $hic=RankingAtribucionServiceV06::resolverEquivalenciasHic($hc->cargarHic());
            if(($hic['estados'][$tal]??'')==='HIC_AMBIGUA') return ['kind'=>'hc','scope'=>['district'=>$d,'seller'=>[]],'label'=>$r['nombre_colaborador']];
            $canon=(string)($hic['canonicos'][$tal]??$tal);
            return ['kind'=>'hc','scope'=>['district'=>$d,'seller'=>[$canon]],'label'=>$r['nombre_colaborador']];
        default: throw new RuntimeException('La posición seleccionada no es comercial');
    }
}
function mxPayload(MotorComercial $motor, array $events, array $period): array {
    return $motor->distribucionVelocidadHc($events,$period['from'],$period['to'],$period['days'])['por_play'];
}
function mxSummary(array $m): array {
    return ['instalaciones'=>$m['total_instalaciones'],'arpu'=>$m['arpu'],'arpu_ingreso_neto'=>$m['arpu_ingreso_neto'],'arpu_cuentas'=>$m['arpu_cuentas']];
}
function mxCompare(array $a,array $b): array {
    $na=(int)$a['instalaciones'];$nb=(int)$b['instalaciones'];
    $aa=$a['arpu'];$ab=$b['arpu'];
    return ['previous'=>$a,'current'=>$b,'diff'=>$nb-$na,'pct'=>$na>0?round(100*($nb-$na)/$na,1):($nb>0?null:0),
        'arpu_pct'=>($aa!==null && (float)$aa>0 && $ab!==null)?round(100*((float)$ab-(float)$aa)/(float)$aa,1):null];
}
function mxEmpty(MotorComercial $motor): array {
    $vs=[];foreach($motor::velocidadesMix() as $k) $vs[$k]=['instalaciones'=>0,'participacion_pct'=>0,'arpu'=>null,'bundle'=>0,'no_bundle'=>0,'bundle_sin_clasificar'=>0,'bundle_pct'=>0,'no_bundle_pct'=>0,'bundle_sin_clasificar_pct'=>0];
    return ['total_instalaciones'=>0,'arpu'=>null,'arpu_ingreso_neto'=>0,'arpu_cuentas'=>0,'velocidades'=>$vs,'dias'=>[]];
}
function mxNodeStats(MotorComercial $motor,array $old,array $new,array $periods): array {
    $prior=mxPayload($motor,$old,$periods['previous'])['TODAS'];
    $current=mxPayload($motor,$new,$periods['current'])['TODAS'];
    return mxCompare(mxSummary($prior),mxSummary($current));
}
function mxNodeFilter(string $id): array {
    if($id==='') return [];
    $parts=explode(':',$id,2);if(count($parts)!==2)throw new InvalidArgumentException('Nodo inválido');
    $p=explode('|',$parts[1]);
    switch($parts[0]) {
        case 'd': if(count($p)!==1)break; return ['district'=>$p[0]];
        case 'l': if(count($p)!==2)break;return ['district'=>$p[0],'leader'=>$p[0].'|'.$p[1]];
        case 'c': if(count($p)!==3)break;return ['district'=>$p[0],'leader'=>$p[0].'|'.$p[1],'coach'=>$p[2]];
        case 'v': if(count($p)!==4)break;return ['district'=>$p[0],'leader'=>$p[0].'|'.$p[1],'coach'=>$p[2],'seller'=>[$p[3]]];
    }
    throw new InvalidArgumentException('Nodo inválido');
}
function mxTree(array $roster,array $current,array $previous,array $canonical=[]): array {
    $nodes=[];$regions=['CANCUN','COATZA-MINA','MERIDA','TUXTLA','VILLAHERMOSA'];
    foreach($regions as $d) $nodes['d:'.$d]=['id'=>'d:'.$d,'label'=>'DIRECTOR DISTRITAL','district'=>$d,'parent'=>'','kind'=>'director'];
    foreach($roster['by_id'] as $id=>$r) {
        $d=$r['distrito_can'];$name=trim((string)$r['nombre_colaborador']);
        if($r['nivel']==='director')$nodes['d:'.$d]['label']=$name ?: 'DIRECCIÓN DISTRITAL';
        if($r['nivel']==='lider') {
            $key='l:'.$d.'|'.$id;
            $nodes[$key]=['id'=>$key,'label'=>$name ?: 'LÍDER VACANTE','district'=>$d,'parent'=>'d:'.$d,'kind'=>'lider'];
        }
    }
    foreach($roster['by_id'] as $id=>$r) {
        $d=$r['distrito_can'];$name=trim((string)$r['nombre_colaborador']);$parent=(string)$r['posicion_lr'];
        if($r['nivel']==='coach') {
            $lead='l:'.$d.'|'.$parent;
            if(isset($nodes[$lead])) {
                $key='c:'.$d.'|'.$parent.'|'.$id;
                $nodes[$key]=['id'=>$key,'label'=>$name ?: 'COACH VACANTE','district'=>$d,'parent'=>$lead,'kind'=>'coach'];
            }
        }
        if($r['nivel']==='vendedor') {
            // El Motor HC usa identidad HIC canónica; se agregan vendedores certificados abajo.
        }
    }
    // Plantilla actual: los vendedores con cero instalaciones también permanecen visibles.
    // Folios históricos verificables se homologan con la misma HIC que usa Motor HC.
    foreach($roster['by_id'] as $id=>$r) {
        if($r['nivel']!=='vendedor') continue;
        $d=$r['distrito_can']; $coachPos=(string)$r['posicion_lr'];
        $folio=trim((string)($r['numero_talento_gs']??''));
        if($folio==='' || mxUpper($folio)==='VACANTE') continue;
        foreach($nodes as $coachKey=>$coachNode) {
            if($coachNode['kind']!=='coach' || $coachNode['district']!==$d) continue;
            if(!str_ends_with($coachKey,'|'.$coachPos)) continue;
            $seller=(string)($canonical[$folio]??$folio);
            $key='v:'.substr($coachKey,2).'|'.$seller;
            $nodes[$key]=['id'=>$key,'label'=>(string)$r['nombre_colaborador'],'district'=>$d,'parent'=>$coachKey,'kind'=>'vendedor'];
        }
    }
    foreach(array_merge($previous,$current) as $e) {
        $lid=(string)($e['lider_id']??'');$coach=(string)($e['coach_id']??'');$seller=(string)($e['vendedor_id']??'');
        $split=explode('|',$lid,2);if(count($split)!==2 || $coach==='' || $seller==='')continue;
        [$d,$pos]=$split;if(!in_array($d,$regions,true))continue;
        $lk='l:'.$d.'|'.$pos;$ck='c:'.$d.'|'.$pos.'|'.$coach;
        if(!isset($nodes[$lk]))$nodes[$lk]=['id'=>$lk,'label'=>'LÍDER (HISTÓRICO) · '.$pos,'district'=>$d,'parent'=>'d:'.$d,'kind'=>'lider'];
        if(!isset($nodes[$ck]))$nodes[$ck]=['id'=>$ck,'label'=>(string)($e['coach_evento']??'COACH') ?: 'COACH','district'=>$d,'parent'=>$lk,'kind'=>'coach'];
        $vk='v:'.$d.'|'.$pos.'|'.$coach.'|'.$seller;
        if(!isset($nodes[$vk]))$nodes[$vk]=['id'=>$vk,'label'=>(string)($e['vendedor_nombre']??'VENDEDOR NO IDENTIFICADO') ?: 'VENDEDOR NO IDENTIFICADO','district'=>$d,'parent'=>$ck,'kind'=>'vendedor'];
    }
    return $nodes;
}
function mxMatrix(MotorComercial $motor,array $r0,array $r1,array $periods,array $input,array $canonical=[]): array {
    $district=(string)($input['district']??'TODOS');
    $allowed=['TODOS','CANCUN','COATZA-MINA','MERIDA','TUXTLA','VILLAHERMOSA'];
    if(!in_array($district,$allowed,true))throw new InvalidArgumentException('Distrito no reconocido');
    $a=$r0['cuentas'];$b=$r1['cuentas'];
    if($district!=='TODOS'){$a=mxSlice($a,['district'=>$district]);$b=mxSlice($b,['district'=>$district]);}
    $nodes=mxTree($GLOBALS['mxRoster'],$r1['cuentas'],$r0['cuentas'],$canonical);
    $selected=(string)($input['node']??'');
    if($selected!=='' && (!isset($nodes[$selected]) || ($district!=='TODOS' && $nodes[$selected]['district']!==$district)))throw new InvalidArgumentException('Nodo no autorizado');
    $selector=mxNodeFilter($selected);
    $parentOld=mxSlice($a,$selector);$parentNew=mxSlice($b,$selector);
    $payloadOld=mxPayload($motor,$parentOld,$periods['previous'])['TODAS'];
    $payloadNew=mxPayload($motor,$parentNew,$periods['current'])['TODAS'];
    $details=mxCompare(mxSummary($payloadOld),mxSummary($payloadNew));
    $children=[];
    foreach($nodes as $node) {
        if($node['parent']!==$selected)continue;
        if($district!=='TODOS' && $node['district']!==$district)continue;
        $filter=mxNodeFilter($node['id']);
        $subsetOld=mxSlice($a,$filter);$subsetNew=mxSlice($b,$filter);
        $stats=mxNodeStats($motor,$subsetOld,$subsetNew,$periods);
        $children[]=['id'=>$node['id'],'name'=>$node['label'],'district'=>$node['district'],'kind'=>$node['kind'],'stats'=>$stats];
    }
    usort($children,static fn($x,$y)=>[$x['district'],$x['name']]<=>[$y['district'],$y['name']]);
    $trail=[];$walk=$selected;
    while($walk!=='' && isset($nodes[$walk])){$trail[]= ['id'=>$walk,'name'=>$nodes[$walk]['label']];$walk=$nodes[$walk]['parent'];}
    return ['view'=>'matrix','selected'=>$selected,'trail'=>array_reverse($trail),'rows'=>$children,'summary'=>$details,
        'old'=>$payloadOld,'new'=>$payloadNew,'periods'=>$periods,'district'=>$district,
        'audit'=>['unattributed_previous'=>$r0['conciliacion_comercial']['pendientes_hc_o_fuera_scope']??0,'unattributed_current'=>$r1['conciliacion_comercial']['pendientes_hc_o_fuera_scope']??0]];
}
function mxMix(MotorComercial $motor,RankingAtribucionServiceV06 $hc,array $roster,array $who,array $periods,array $input): array {
    $scope=mxScopeForMix($input,$roster,$who,$hc);
    $out=[];
    if($scope['kind']==='all' || $scope['kind']==='district') {
        $filter=$scope['kind']==='all'?['tipo'=>'REGION']:['tipo'=>'DISTRITO','distrito'=>$scope['scope']['district']];
        foreach(['previous','current'] as $period) {
            $p=$periods[$period];
            $out[$period]=$motor->distribucionVelocidadPorCatalogo($p['from'],$p['to'],$filter,$p['days'])['por_play'];
        }
        $universe='REGISTROS_INSTALACIONES';
    } else {
        foreach(['previous','current'] as $period) {
            $p=$periods[$period];
            $data=mxEvents($hc,$p);
            $events=mxSlice($data['cuentas'],$scope['scope']);
            $out[$period]=mxPayload($motor,$events,$p);
        }
        $universe='CUENTAS_HC_CERTIFICADAS';
    }
    $cards=[];
    foreach(['2P','3P','TODAS'] as $play) {
        $cards[$play]=mxCompare(mxSummary($out['previous'][$play]),mxSummary($out['current'][$play]));
    }
    return ['view'=>'mix','cards'=>$cards,'old'=>$out['previous'],'new'=>$out['current'],
        'scope'=>$scope['label'],'universe'=>$universe,'periods'=>$periods];
}
function mxHtml(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

try {
    $mxRoster=mxRoster($conexion);
    $who=mxIdentity($mxRoster);
    if(isset($_GET['api'])) {
        if($_SERVER['REQUEST_METHOD']!=='POST')throw new InvalidArgumentException('Método no admitido');
        $payload=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($payload))throw new InvalidArgumentException('Solicitud JSON inválida');
        if(!hash_equals((string)$_SESSION['talia_mix_csrf'],(string)($payload['csrf']??''))) {
            http_response_code(403);throw new RuntimeException('Token CSRF inválido');
        }
        $p=mxPeriods($conexion,$payload);$motor=new MotorComercial($conexion);
        if(($payload['view']??'mix')==='matrix') {
            $hc=new RankingAtribucionServiceV06($conexion);
            // Cada período se certifica por separado: una cuenta repetida entre
            // semanas no debe invalidar accidentalmente su fotografía histórica.
            $prev=mxEvents($hc,$p['previous']);$curr=mxEvents($hc,$p['current']);
            $canon=RankingAtribucionServiceV06::resolverEquivalenciasHic($hc->cargarHic());
            $result=mxMatrix($motor,$prev,$curr,$p,$payload,$canon['canonicos']);
        } else {
            $hc=new RankingAtribucionServiceV06($conexion);
            $result=mxMix($motor,$hc,$mxRoster,$who,$p,$payload);
        }
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        echo json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;
    }
    $filters=mxPeriods($conexion,$_GET);
    $options=mxOptions($mxRoster,$who);
} catch(Throwable $e) {
    if(isset($_GET['api'])) {
        if(http_response_code()<400)http_response_code($e instanceof InvalidArgumentException?400:500);
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        error_log('[Talia Mix] '.$e->getMessage());
        $message=$e instanceof InvalidArgumentException?$e->getMessage():'No fue posible procesar la consulta. Consulta el registro de errores PHP.';
        echo json_encode(['error'=>$message],JSON_INVALID_UTF8_SUBSTITUTE);exit;
    }
    http_response_code(403);exit('Talia Mix Comercial: '.mxHtml($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mix comercial</title><link rel="stylesheet" href="../assets/css/mix_comercial.css?v=1.0.4"></head>
<body><main class="mx-root" id="talia-mix-comercial">
    <header class="mx-header mx-hero">
      <div class="mx-hero-copy">
        <span class="mx-hero-kicker">Comercial</span>
        <h1 id="mx-page-title">Mix comercial</h1>
        <p class="mx-hero-subtitle">Distribución comercial y desempeño de instalaciones</p>
        <p class="mx-hero-meta" id="mx-page-meta">Comparativo del periodo seleccionado · Inicializando tablero…</p>
      </div>
      <div class="mx-hero-user">
        <span class="mx-hero-user-label">Usuario actual</span>
        <strong><?= mxHtml($who['name']) ?></strong>
      </div>
    </header>
    <nav class="mx-tabs" aria-label="Vistas"><button type="button" class="is-active" data-view="mix">Mix comercial por velocidad</button><button type="button" data-view="matrix">Matriz de desempeño</button></nav>
    <div class="mx-controls">
      <div class="mx-scope" data-mix-only><label for="mx-person">Vista jerárquica</label><select id="mx-person"><option value="">Mi tablero / vista inicial</option><?php foreach($options as $opt): ?><option value="<?=mxHtml($opt['id'])?>"><?=mxHtml($opt['district'].' · '.$opt['name'].' · '.$opt['level'])?></option><?php endforeach ?></select></div>
      <div class="mx-scope" data-matrix-only hidden><label for="mx-district">Distrito</label><select id="mx-district"><option value="TODOS">Todos los distritos</option><?php foreach(['CANCUN'=>'Cancún','COATZA-MINA'=>'Coatza-Mina','MERIDA'=>'Mérida','TUXTLA'=>'Tuxtla','VILLAHERMOSA'=>'Villahermosa'] as $k=>$name): ?><option value="<?=mxHtml($k)?>"><?=mxHtml($name)?></option><?php endforeach ?></select></div>
      <div class="mx-time"><div class="mx-toggle"><button type="button" data-mode="semanal">Semanal</button><button type="button" data-mode="mensual">Mensual</button></div>
        <button type="button" data-prev>← Anterior</button><button type="button" class="mx-period" data-popover-open>Periodo <span>▾</span></button><button type="button" data-next>Siguiente →</button>
        <div class="mx-popover" hidden id="mx-popover"><div class="mx-week" hidden><strong>Selecciona días comparables</strong><div class="mx-day-grid"><?php foreach(['LUN','MAR','MIÉ','JUE','VIE','SÁB','DOM'] as $i=>$day): ?><button type="button" data-weekday="<?=$i+1?>"><?=$day?></button><?php endforeach ?></div><button type="button" class="mx-primary" data-apply-week>Aplicar</button></div>
          <div class="mx-month" hidden><div class="mx-month-head"><button type="button" data-cal-prev>‹</button><strong id="mx-cal-title">Selecciona un rango</strong><button type="button" data-cal-next>›</button></div><div class="mx-calendar" id="mx-calendar"></div><p class="mx-month-caption" id="mx-month-caption">Selecciona fecha inicial y final</p><div class="mx-pop-bottom"><button type="button" data-full-month>Mes completo</button><button type="button" class="mx-primary" data-apply-month>Aplicar</button></div></div>
        </div>
      </div>
    </div>
    <div class="mx-error" role="alert" hidden></div><div class="mx-loading" aria-live="polite">Consultando indicadores…</div>
    <section class="mx-panel" data-panel="mix"><div class="mx-panel-title"><div><h2>Mix comercial por velocidad</h2><p>Comparativo simultáneo del universo visible: Doble Play (2P) y Triple Play (3P)</p></div><span id="mx-mix-scope"></span></div><div class="mx-table-scroll"><table class="mx-table" id="mx-play-table"><thead></thead><tbody></tbody></table></div><p class="mx-footnote">Selecciona una fila para actualizar el desglose y las gráficas.</p></section>
    <section class="mx-panel" data-panel="matrix" hidden><div class="mx-panel-title"><div><h2>Matriz de desempeño</h2><p>Instalaciones y ARPU del mismo universo jerárquico visible</p></div><span id="mx-matrix-level">NIVEL: DIRECTORES</span></div><div class="mx-breadcrumb" id="mx-breadcrumb"></div><div class="mx-table-scroll"><table class="mx-table" id="mx-matrix-table"><thead></thead><tbody></tbody></table></div><p class="mx-footnote" id="mx-matrix-audit"></p></section>
    <section class="mx-detail"><div class="mx-panel-title"><div><h2>Mix comercial por velocidad</h2><p>Distribución por velocidad de las instalaciones del universo seleccionado</p></div><span id="mx-current-name"></span></div><div class="mx-table-scroll"><table class="mx-table" id="mx-speed-table"><thead></thead><tbody></tbody></table></div></section>
    <section class="mx-charts"><div class="mx-chart-box"><h3>Participación por velocidad</h3><p id="mx-speed-description">Distribución porcentual diaria de instalaciones</p><div class="mx-legend" id="mx-speed-legend"></div><div class="mx-chart" id="mx-speed-chart"></div></div><div class="mx-chart-box"><h3>Fluctuación del ARPU</h3><p>ARPU recalculado sobre instalaciones del universo visible por fecha</p><div class="mx-legend" id="mx-arpu-legend"></div><div class="mx-chart" id="mx-arpu-chart"></div></div></section>
    <footer class="mx-footer">Talia Mix Comercial Standalone v1.0.5 · Fuente Motor Comercial + Motor HC · Sin WordPress</footer>
</main>
<script>window.TaliaMixConfig=<?= json_encode(['csrf'=>$_SESSION['talia_mix_csrf'],'periods'=>$filters,'options'=>$options,'defaultView'=>(($_GET['view']??'mix')==='matrix'?'matrix':'mix')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<script src="../assets/js/mix_comercial.js?v=1.0.5" defer></script></body></html>
