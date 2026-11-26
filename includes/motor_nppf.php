<?php
/** TotalXpedient INDEX - bloque ejecutivo NPPF. Solo lectura; v1.0 (2026-11-26).
 * Regla de cosechas / M1 / preventivo alineada a Ranking NPPF v1.2.16.
 * Requiere $conexion, $periodo, fechas Dashboard y variables de alcance del INDEX.
 * NO modifica motor_hc ni tablas NPPF. El universo se rotula según su fuente.
 */
$tx360 = ['ok'=>false,'error'=>null,'cut'=>null,'eval'=>date('Y-m-d'),
    'groups'=>['older'=>[],'m1'=>[]],'trend'=>[],'pies'=>[],'cohortLabels'=>[],
    'universe'=>'','totals'=>[]];
if (!function_exists('tx360_norm_account')) {
function tx360_norm_account($raw): string {
    $v = preg_replace('/\D/u','',(string)$raw) ?? '';
    return $v===''?'':(ltrim($v,'0')?:'0');
}
function tx360_norm_district($d): string {
    $s = strtoupper(trim((string)$d));
    $s = str_replace([' / ','/','  '],[' ',' ',' '],$s);
    $s = preg_replace('/\s+/',' ',$s);
    if (in_array($s,['COATZA MINA','COATZACOALCOS MINATITLAN'],true)) return 'COATZA / MINA';
    return $s;
}
function tx360_shift(string $date,int $back): string {
    $d=new DateTimeImmutable($date); $index=(int)$d->format('Y')*12+(int)$d->format('n')-1-$back;
    $y=intdiv($index,12);$m=$index%12+1;
    $first=new DateTimeImmutable(sprintf('%04d-%02d-01',$y,$m));
    return $first->setDate($y,$m,min((int)$d->format('j'),(int)$first->format('t')))->format('Y-m-d');
}
function tx360_m1_cut(string $date): string {return tx360_shift($date,-1);}
function tx360_rate(array $s): ?float {return $s['ins']>0 ? round(100*$s['nppf']/$s['ins'],2):null;}
function tx360_months(string $first,string $last): array {
    $out=[];$a=new DateTimeImmutable(substr($first,0,7).'-01');$b=new DateTimeImmutable(substr($last,0,7).'-01');
    while($a<=$b){$out[$a->format('Y-m')]=['ins'=>0,'nppf'=>0,'prevent'=>0];$a=$a->modify('+1 month');}
    return $out;
}
function tx360_metric(): array {return ['ins'=>0,'nppf'=>0,'prevent'=>0];}
function tx360_bucket(array &$items,string $key,string $class): void {
    $items[$key]??=tx360_metric();
    $items[$key]['ins']++;
    if($class==='nppf')$items[$key]['nppf']++;
    if($class==='prevent')$items[$key]['prevent']++;
}
function tx360_top(array $data,int $limit=7): array {
    uasort($data,static fn($a,$b)=>($b['nppf']+$b['prevent'])<=>($a['nppf']+$a['prevent']));
    $first=array_slice($data,0,$limit,true); $remaining=array_slice($data,$limit,null,true);
    if($remaining){$other=tx360_metric();foreach($remaining as $v)foreach($other as $k=>$_)$other[$k]+=$v[$k];$first['OTROS']=$other;}
    return $first;
}
}
try {
    if (!($conexion instanceof mysqli)) throw new RuntimeException('Conexión de datos no disponible');
    $cutRes=mysqli_query($conexion,"SELECT fecha_corte FROM nppf_cargas WHERE estado='OK' ORDER BY fecha_corte DESC,id DESC LIMIT 1");
    $snapshot=$cutRes ? mysqli_fetch_assoc($cutRes):null;
    if (!$snapshot) throw new RuntimeException('No existe corte NPPF publicado (estado OK)');
    $tx360['cut']=(string)$snapshot['fecha_corte'];
    // El período visible selecciona el MES/SEMANA de referencia. NPPF usa las cosechas previas.
    $refStart=$periodo==='semanal' ? $tx_semana_lunes->format('Y-m-d') : sprintf('%04d-%02d-01',$anio_query,$mes_actual);
    $refEnd=$periodo==='semanal' ? $tx_semana_lunes->modify('+6 days')->format('Y-m-d') : date('Y-m-t',strtotime($refStart));
    $cohorts=['m4'=>[], 'm3'=>[], 'm2'=>[], 'm1'=>[]];
    if($periodo==='mensual'){
        // El comparativo de calidad usa COSECHAS MENSUALES COMPLETAS, incluso si INDEX está MTD.
        foreach([4,3,2,1] as $n){$a=tx360_shift($refStart,$n);$b=date('Y-m-t',strtotime($a));
            for($d=new DateTimeImmutable($a);$d<=new DateTimeImmutable($b);$d=$d->modify('+1 day'))$cohorts['m'.$n][$d->format('Y-m-d')]=true;
        }
    } else {
        // Igual que Ranking: días ISO elegidos, desplazamiento por día calendario exacto.
        $days=$tx_dashboard_dias_iso ?: range(1,7);
        for($d=new DateTimeImmutable($refStart);$d<=new DateTimeImmutable($refEnd);$d=$d->modify('+1 day')){
            if(!in_array((int)$d->format('N'),$days,true))continue;
            foreach([4,3,2,1] as $n)$cohorts['m'.$n][tx360_shift($d->format('Y-m-d'),$n)]=true;
        }
    }
    $byDate=[];$labels=[];
    foreach($cohorts as $m=>$dates){$keys=array_keys($dates);sort($keys);
        $labels[$m]=$keys ? date('d/m/Y',strtotime($keys[0])).'–'.date('d/m/Y',strtotime(end($keys))) : 'Sin fechas';
        foreach($keys as $date)$byDate[$date]=$m;
    }
    $tx360['cohortLabels']=$labels;
    $m1First=$cohorts['m1']?min(array_keys($cohorts['m1'])):$refStart;
    $m1Last=$cohorts['m1']?max(array_keys($cohorts['m1'])):$refEnd;
    $start='2026-01-01';
    $end=max(array_keys($byDate));
    $raw=[];
    $s=$conexion->prepare("SELECT cuenta,cuenta_clave,fecha_activacion,tipo,distrito,canal_venta,desc_plan FROM nppf_registros WHERE presente_en_ultimo_corte=1 AND UPPER(TRIM(region))='SUR' AND fecha_activacion BETWEEN ? AND ?");
    if(!$s)throw new RuntimeException('Consulta NPPF no disponible');
    $s->bind_param('ss',$start,$end);$s->execute();$rows=$s->get_result();
    while($r=$rows->fetch_assoc()){
        $account=tx360_norm_account($r['cuenta_clave']?:$r['cuenta']);$act=(string)$r['fecha_activacion'];
        if($account===''||$act==='')continue;
        $raw[$account][$act]=$r; // duplicados de snapshot: una cuenta por fecha
    }$s->close();
    $tx360['groups']=['older'=>['district'=>[],'channel'=>[]],'m1'=>['district'=>[],'channel'=>[]]];
    $tx360['pies']=['older'=>[],'m1'=>[],'prevent'=>[]];
    $tx360['trend']=tx360_months($start,$m1Last);
    $tx360['totals']=['older'=>tx360_metric(),'m1'=>tx360_metric()];
    // Director/Admin: todos los canales, como KPI superior por distrito.
    // Líder/Coach: únicamente cuentas HC certificadas dentro de su línea, como motor del INDEX.
    $isRegion=!$scope_activo && in_array($rol,['admin','director_regional'],true);
    $isDistrict=($scope_activo && $scope_nivel==='director_distrital') || (!$scope_activo && $rol==='director_distrital');
    $districtTarget=tx360_norm_district($scope_activo ? ($distrito_scope??'') : $distrito_usuario);
    $hcMode=!$isRegion && !$isDistrict && !$tx_stg_es_vendedor;
    $sellerMode=!$isRegion && !$isDistrict && $tx_stg_es_vendedor;
    if($hcMode && !$tx_stg_hc_ok) throw new RuntimeException('Motor HC no disponible para certificar las cosechas de esta línea');
    $tx360['universe']=$isRegion?'Todos los canales · Región SUR':($isDistrict?'Todos los canales · Distrito':'Instalaciones certificadas por Motor HC · Línea autorizada');
    $seen=[];
    $consume=function($account,$date,$district,$channel,$plan) use (&$tx360,&$raw,&$seen,&$byDate,$districtTarget,$isRegion,$isDistrict,$end){
        $date=substr((string)$date,0,10);$account=tx360_norm_account($account);
        if($account===''||$date===''||$date>$end)return;
        $unique=$account.'|'.$date;
        if(isset($seen[$unique]))return;$seen[$unique]=true;
        $district=tx360_norm_district($district);
        if(!$isRegion && $isDistrict && $district!==$districtTarget)return;
        $channel=strtoupper(trim((string)$channel));$channel=$channel!==''?$channel:'SIN CANAL';
        $plan=strtoupper(trim((string)$plan));$plan=$plan!==''?$plan:'SIN PAQUETE';
        $r=$raw[$account][$date]??null;
        if(!$r){ // Ranking permite diferencia de fecha de activación máxima de 1 día.
            foreach([-1,1] as $off){$near=(new DateTimeImmutable($date))->modify(($off<0?'-':'+').'1 day')->format('Y-m-d');
                if(isset($raw[$account][$near])){$r=$raw[$account][$near];break;}
            }
        }
        if($r && tx360_norm_district((string)$r['distrito'])!==$district)$r=null;
        $class=$r ? (((new DateTimeImmutable($date))->format('Y-m')===(new DateTimeImmutable($end))->format('Y-m') && $tx360['eval']<tx360_m1_cut($date))?'prevent':'nppf') : '';
        $ym=substr($date,0,7);
        if(isset($tx360['trend'][$ym])){
            $tx360['trend'][$ym]['ins']++;
            if($class==='nppf')$tx360['trend'][$ym]['nppf']++;
            if($class==='prevent')$tx360['trend'][$ym]['prevent']++;
        }
        if(!isset($byDate[$date]))return;
        $cohort=$byDate[$date];$group=$cohort==='m1'?'m1':'older';
        // Preventivo se permite ÚNICAMENTE en M1, no en las otras cosechas.
        if($r)$class=$cohort==='m1' && $tx360['eval']<tx360_m1_cut($date)?'prevent':'nppf';
        $tx360['totals'][$group]['ins']++;
        if($class==='nppf')$tx360['totals'][$group]['nppf']++;
        if($class==='prevent')$tx360['totals'][$group]['prevent']++;
        tx360_bucket($tx360['groups'][$group]['district'],$district,$class);
        tx360_bucket($tx360['groups'][$group]['channel'],$channel,$class);
        if($class==='nppf')$tx360['pies'][$group][$plan]=($tx360['pies'][$group][$plan]??0)+1;
        if($class==='prevent')$tx360['pies']['prevent'][$plan]=($tx360['pies']['prevent'][$plan]??0)+1;
    };
    if($hcMode){
        // La pertenencia al equipo proviene ÚNICAMENTE de Motor HC (ya filtrado en INDEX).
        // Canal/paquete se leen del evento real de instalaciones, no de etiquetas HC.
        $certified=[];
        foreach(($tx_stg_por_mes??[]) as $events)foreach($events as $e){
            $acct=tx360_norm_account($e['cuenta']??'');$f=substr((string)($e['fecha']??''),0,10);
            if($acct!=='' && $f!=='')$certified[$acct.'|'.$f]=true;
        }
        if(!$certified)throw new RuntimeException('No hay instalaciones HC certificadas en el alcance seleccionado');
        $q=$conexion->prepare("SELECT cuenta,fecha,distrito,origen_prospecto,plan FROM instalaciones WHERE fecha BETWEEN ? AND ? AND origen_prospecto <> '-'");
        if(!$q)throw new RuntimeException('Consulta HC de instalaciones no disponible');
        $q->bind_param('ss',$start,$end);$q->execute();$rr=$q->get_result();
        while($e=$rr->fetch_assoc()){
            $k=tx360_norm_account($e['cuenta']).'|'.substr((string)$e['fecha'],0,10);
            if(isset($certified[$k]))$consume($e['cuenta'],$e['fecha'],$e['distrito'],$e['origen_prospecto'],$e['plan']);
        }
        $q->close();
    } else {
        // Se filtra distrito por PHP. El vendedor usa solo sus folios autorizados.
        $foliosNppf=$sellerMode ? array_values(array_unique(array_filter(array_map('strval',$folio_ids)))) : [];
        if($sellerMode && !$foliosNppf)throw new RuntimeException('Vendedor sin folios autorizados para NPPF');
        $extra=$sellerMode?' AND folio_empleado IN ('.implode(',',array_fill(0,count($foliosNppf),'?')).')':'';
        $q=$conexion->prepare("SELECT cuenta,fecha,distrito,origen_prospecto,plan FROM instalaciones WHERE fecha BETWEEN ? AND ? AND origen_prospecto <> '-' $extra ORDER BY fecha");
        if(!$q)throw new RuntimeException('Consulta de instalaciones no disponible');
        $params=array_merge([$start,$end],$foliosNppf);
        $q->bind_param(str_repeat('s',count($params)),...$params);$q->execute();$rr=$q->get_result();
        while($e=$rr->fetch_assoc())$consume($e['cuenta'],$e['fecha'],$e['distrito'],$e['origen_prospecto'],$e['plan']);
        $q->close();
    }
    foreach(['older','m1'] as $group){
        foreach(['district','channel'] as $dimension)$tx360['groups'][$group][$dimension]=tx360_top($tx360['groups'][$group][$dimension],$dimension==='district'?10:7);
    }
    foreach($tx360['pies'] as &$pie){arsort($pie);$first=array_slice($pie,0,6,true);$others=array_sum(array_slice($pie,6));if($others>0)$first['OTROS']=$others;$pie=$first;}unset($pie);
    $tx360['ok']=true;
} catch(Throwable $ex){$tx360['error']=$ex->getMessage();error_log('INDEX NPPF 360: '.$tx360['error']);}
