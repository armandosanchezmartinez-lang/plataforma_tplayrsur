<?php
/** Reto Buen Mes · Noviembre 2026 · v1.2.0. Modelo de solo lectura. PHP >= 8.0. */
declare(strict_types=1);
const RBM_INICIO = '2026-11-01';
const RBM_FIN = '2026-11-30';
const RBM_SIGUIENTE = '2026-12-01';
const RBM_ESCALA = 10000000;
const RBM_PISO = 54180;
const RBM_METAS = [
    'MERIDA'=>['nombre'=>'MÉRIDA','meta'=>1000000],
    'COATZA-MINA'=>['nombre'=>'COATZA / MINA','meta'=>460000],
    'CANCUN'=>['nombre'=>'CANCÚN','meta'=>960000],
    'TUXTLA'=>['nombre'=>'TUXTLA','meta'=>850000],
    'VILLAHERMOSA'=>['nombre'=>'VILLAHERMOSA','meta'=>540000],
];
function rbm_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function rbm_fecha(string $d): bool {
    $f=DateTimeImmutable::createFromFormat('!Y-m-d',$d);
    return $f!==false && $f->format('Y-m-d')===$d;
}
function rbm_dias(string $a=RBM_INICIO,string $b=RBM_FIN): array {
    $out=[]; if (!rbm_fecha($a)||!rbm_fecha($b)||$a>$b) return $out;
    for($d=new DateTimeImmutable($a);$d<=new DateTimeImmutable($b);$d=$d->modify('+1 day')) $out[]=$d->format('Y-m-d');
    return $out;
}
function rbm_semana(string $f): string { $d=new DateTimeImmutable($f); return $d->format('o').'-'.(int)$d->format('W'); }
function rbm_money(int $v): string { return '$'.number_format($v/RBM_ESCALA,2,'.',','); }
function rbm_exact(int $v): string { $s=$v<0?'-':''; $v=abs($v); return $s.intdiv($v,RBM_ESCALA).'.'.str_pad((string)($v%RBM_ESCALA),7,'0',STR_PAD_LEFT); }
function rbm_decimal($v): ?int {
    if ($v===null) return null;
    $s=trim((string)$v);
    if(!preg_match('/^([+-]?)(\d{1,8})(?:\.(\d{1,7}))?$/D',$s,$m)) return null;
    $n=((int)$m[2])*RBM_ESCALA+(int)str_pad($m[3]??'',7,'0');
    return $m[1]==='-'?-$n:$n;
}
function rbm_suma(int $a,int $b): int {
    if (($b>0&&$a>PHP_INT_MAX-$b)||($b<0&&$a<PHP_INT_MIN-$b)) throw new OverflowException('Importe acumulado fuera del rango entero de 64 bits');
    return $a+$b;
}
function rbm_pct(int $real,int $meta): ?float { return $meta>0?100.0*$real/$meta:null; }
/** Podio por alcance del rango, independiente del orden visible de las filas. */
function rbm_medallas_rango(array $rows): array {
    $eligible=array_values(array_filter($rows,static fn($r)=>($r['range']??0)>0 && isset($r['alcance_rango'])));
    usort($eligible,static function($a,$b){
        return ($b['alcance_rango'] <=> $a['alcance_rango'])
            ?: ($b['actual'] <=> $a['actual'])
            ?: strcmp($a['n']['name'],$b['n']['name'])
            ?: strcmp($a['id'],$b['id']);
    });
    $out=[];foreach(array_slice($eligible,0,3) as $i=>$r)$out[$r['id']]=$i+1;
    return $out;
}
function rbm_num_pct(?float $p): string { return $p===null?'—':number_format($p,1).'%'; }
function rbm_query(mysqli $db,string $sql,string $types='',array $params=[]): array {
    $st=$db->prepare($sql); if(!$st) throw new RuntimeException('No se pudo preparar consulta del reto');
    try { if($types!=='') $st->bind_param($types,...$params); $st->execute(); $rs=$st->get_result(); if(!$rs) throw new RuntimeException('Consulta sin resultado'); return $rs->fetch_all(MYSQLI_ASSOC); }
    finally { $st->close(); }
}
function rbm_persona($tal,array $hic): ?string {
    $t=trim((string)$tal); if(in_array(strtoupper($t),['','-','VACANTE'],true)) return null;
    if(($hic['estados'][$t]??'')==='HIC_AMBIGUA') return null;
    return (string)($hic['canonicos'][$t]??$t);
}
function rbm_cuenta($v): string { return trim((string)$v); }
function rbm_account_key(string $v): string { return 'A:'.$v; }
/** Duplicados idénticos se colapsan; discrepancia en cualquiera de los dos importes se audita y excluye. */
function rbm_catalogo(array $rows): array {
    $out=[]; $blank=0;
    foreach($rows as $r){
        $c=rbm_cuenta($r['CUENTA']??''); if($c===''){ $blank++; continue; }
        $k=rbm_account_key($c);
        if(!isset($out[$k]))$out[$k]=['cuenta'=>$c,'rentas'=>[],'brm'=>[],'filas'=>0];
        $v=rbm_decimal($r['rentaSinImpuestos']??null); $p=rbm_decimal($r['PRECIO_BRM']??null);
        $out[$k]['rentas'][$v===null?'NULL':(string)$v]=$v;
        $out[$k]['brm'][$p===null?'NULL':(string)$p]=$p;
        $out[$k]['filas']++;
    }
    foreach($out as &$r){
        $r['renta']=count($r['rentas'])===1?reset($r['rentas']):null;
        $r['precio']=count($r['brm'])===1?reset($r['brm']):null;
        $r['conflicto']=count($r['rentas'])>1||count($r['brm'])>1;
        $r['valida']=!$r['conflicto']&&$r['renta']!==null;
    } unset($r);
    return ['cuentas'=>$out,'vacias'=>$blank];
}
/** La ausencia se evalúa en días naturales, con última foto conocida por semana.
 * Una foto faltante no es baja; la primera foto real sin la persona inicia una baja estimada.
 * Reaparición con el mismo HIC abre un nuevo tramo. Fechas sin foto quedan desconocidas. */
function rbm_disponibilidad(array $hc,array $hic,array $standby): array {
    $presence=[];$people=[];
    foreach($hc['datos'] as $foto=>$rows){
        foreach($rows as $r){
            $p=rbm_persona($r['numero_talento_gs']??null,$hic);
            if($p===null||str_contains(strtoupper(trim((string)($r['nombre_colaborador']??''))),'VACANT'))continue;
            $presence[$foto][$p]=true;
            if(!isset($people[$p]))$people[$p]=['alta'=>null];
            $a=substr(trim((string)($r['fecha_alta']??'')),0,10);
            if(rbm_fecha($a)&&$a!=='0000-00-00'&&($people[$p]['alta']===null||$a<$people[$p]['alta']))$people[$p]['alta']=$a;
        }
    }
    $days=rbm_dias(); $result=[];
    foreach($people as $p=>$info){
        $aus=[];$vac=[];$inc=[];$baja=[];$unknown=[];$alta=[];$outside=[];$active=[];$prev=null;$prevPresent=false;
        $events=$standby['por_talento'][$p]['eventos']??[];
        foreach($events as $e){
            $i=max(RBM_INICIO,(string)($e['overlap_inicio']??$e['fecha_inicio']??RBM_INICIO));
            $f=min(RBM_FIN,(string)($e['overlap_fin']??$e['fecha_fin']??RBM_FIN));
            foreach(rbm_dias($i,$f) as $d){ $aus[$d]=true; if(($e['tipo_standby']??'')==='VACACIONES')$vac[$d]=true; if(($e['tipo_standby']??'')==='INCAPACIDAD')$inc[$d]=true; }
        }
        foreach($days as $d){
            $week=rbm_semana($d); $ph=$hc['fotos'][$week]??null;
            $key=$ph?implode('-',$ph):null;
            $present=$key!==null&&isset($presence[$key][$p]);
            if($key!==null&&$key===$week&&$key!==$prev&&$prevPresent&&!$present)$baja[]=$d;
            $prev=$key;$prevPresent=$present;
            if($info['alta']!==null&&$d<$info['alta']){$alta[$d]=true;continue;}
            if($key===null||!isset($hc['datos'][$key])){$unknown[$d]=true;continue;}
            if(!$present){$outside[$d]=true;continue;}
            if(isset($aus[$d]))continue;
            $active[$d]=true;
        }
        // Se muestra la primera fecha de discontinuidad posterior a una presencia conocida.
        // La fecha inicial de noviembre puede ser domingo de una semana iniciada en octubre.
        $result[$p]=['activos'=>count($active),'ausencias'=>count($aus),'vacaciones'=>count($vac),'incapacidad'=>count($inc),'alta'=>$info['alta'],'prealta'=>count($alta),'sin_hc'=>count($outside),'desconocidos'=>count($unknown),'baja'=>$baja[0]??null,'eventos'=>$events,'dias_activos'=>array_keys($active),'dias_ausencia'=>array_keys($aus),'dias_sin_hc'=>array_keys($outside),'dias_desconocidos'=>array_keys($unknown)];
    }
    return $result;
}
function rbm_district_node(string $d): string { return 'D:'.$d; }
function rbm_node_key(string $parent,string $role,string $id): string { return $role.':'.hash('sha256',$parent.'|'.$id); }
function rbm_node(array &$nodes,string $key,?string $parent,string $role,string $name,string $district,?string $person=null,?string $position=null): string {
    if(!isset($nodes[$key])){
        $nodes[$key]=['id'=>$key,'parent'=>$parent,'role'=>$role,'name'=>$name,'district'=>$district,'person'=>$person,'position'=>$position,'count'=>0,'renta'=>0,'children'=>[],'accounts'=>[],'directors'=>[]];
        if($parent!==null)$nodes[$parent]['children'][$key]=true;
    } elseif($name!==''&&$name!=='VACANTE'&&$person!==null) $nodes[$key]['name']=$name;
    return $key;
}
function rbm_row_person(array $r,array $hic): ?string {
    if(str_contains(strtoupper(trim((string)($r['nombre_colaborador']??''))),'VACANT'))return null;
    return rbm_persona($r['numero_talento_gs']??null,$hic);
}
function rbm_hc_index(array $rows): array {
    $idx=[]; foreach($rows as $r){$d=RankingAtribucionServiceV06::normalizarDistrito($r['distrito']??null);$pos=trim((string)($r['id_posicion']??''));if($d&&$pos!=='')$idx[$d][$pos][]=$r;}return $idx;
}
function rbm_hc_row(array $idx,string $d,?string $pos): ?array { if($pos===null||count($idx[$d][$pos]??[])!==1)return null; return $idx[$d][$pos][0]; }
function rbm_role_node(array &$nodes,string $parent,string $role,string $d,?array $row,array $hic,?string $pos,?string $fallbackName=null,?string $fallbackPerson=null): string {
    $person=$row!==null?rbm_row_person($row,$hic):$fallbackPerson;
    $name=trim((string)($row['nombre_colaborador']??$fallbackName??''));
    if($name==='')$name=['L'=>'LÍDER NO IDENTIFICADO','C'=>'COACH NO IDENTIFICADO','V'=>'VENDEDOR NO IDENTIFICADO'][$role]??'NO IDENTIFICADO';
    $identity=$person!==null?'P:'.$person:($pos!==null?'PLAZA:'.$pos:'SIN_IDENTIDAD');
    return rbm_node($nodes,rbm_node_key($parent,$role,$identity),$parent,$role,$name,$d,$person,$pos);
}
function rbm_director(array &$nodes,string $d,array $row,array $hic): void {
    $p=rbm_row_person($row,$hic);$pos=(string)($row['id_posicion']??'');$id=$p!==null?'P:'.$p:'PLAZA:'.$pos;
    $n=trim((string)($row['nombre_colaborador']??''));
    $nodes[rbm_district_node($d)]['directors'][$id]=['name'=>$n!==''?$n:'DIRECTOR NO IDENTIFICADO','person'=>$p,'position'=>$pos];
}
/** Estructura de cada foto real o fallback. Usa las plazas y las relaciones de reporte del motor.
 * Los nombres no se utilizan como identificador de persona. */
function rbm_roster(array &$nodes,array $idx,array $hic,array $catalogo): void {
    foreach($idx as $d=>$positions){
        if(!isset(RBM_METAS[$d]))continue;
        $root=rbm_district_node($d);$leaderKeys=[];$coachKeys=[];
        // El catálogo oficial es la autoridad: reportar a Dirección no basta.
        // Además, la plaza debe estar reconocida en ESTA foto, no sólo en otra
        // semana del catálogo mensual (una posición puede cambiar de función).
        $leaderRefs=[];
        foreach($positions as $rr)foreach($rr as $r){
            if(stripos((string)($r['puesto_lr']??''),'LIDER')===false)continue;
            $super=trim((string)($r['posicion_lr']??''));
            if($super!=='')$leaderRefs[$super]=true;
        }
        foreach($positions as $pos=>$rr){
            if(count($rr)!==1)continue;$r=$rr[0];
            if(!isset($catalogo[$d.'|'.$pos],$leaderRefs[$pos]))continue;
            $sup=RankingAtribucionServiceV06::norm($r['puesto_lr']??'');
            if($sup!==''&&!str_contains($sup,'DIRECTOR DISTRITAL'))continue;
            $leaderKeys[$pos]=rbm_role_node($nodes,$root,'L',$d,$r,$hic,(string)$pos);
            $dir=rbm_hc_row($idx,$d,(string)($r['posicion_lr']??'')); if($dir!==null)rbm_director($nodes,$d,$dir,$hic);
        }
        foreach($positions as $pos=>$rr){
            if(count($rr)!==1)continue;$r=$rr[0];$parent=(string)($r['posicion_lr']??'');
            if(!str_contains(RankingAtribucionServiceV06::norm($r['puesto_lr']??''),'LIDER'))continue;
            if($parent===(string)$pos){
                // Mismo fallback unívoco del motor: nombre de superior HC, nunca texto de instalación.
                $key=rbm_name_key((string)($r['nombre_linea_reporte']??''));$cand=[];
                foreach($leaderKeys as $lp=>$ln){if($key!==''&&rbm_name_key($nodes[$ln]['name'])===$key)$cand[]=(string)$lp;}
                if(count($cand)!==1)continue;$parent=$cand[0];
            }
            if(!isset($leaderKeys[$parent]))continue;
            $coachKeys[$pos]=rbm_role_node($nodes,$leaderKeys[$parent],'C',$d,$r,$hic,(string)$pos);
        }
        foreach($positions as $pos=>$rr){
            if(count($rr)!==1)continue;$r=$rr[0];$parent=(string)($r['posicion_lr']??'');
            if(!isset($coachKeys[$parent])||!str_contains(RankingAtribucionServiceV06::norm($r['puesto_lr']??''),'COACH'))continue;
            if(rbm_row_person($r,$hic)===null)continue; // no mostrar vacantes de vendedor sin instalaciones
            rbm_role_node($nodes,$coachKeys[$parent],'V',$d,$r,$hic,(string)$pos);
        }
    }
}
/** Meta mensual de líder: una meta por coach identificado en la rama histórica. */
function rbm_leader_goal(array $node, array $nodes): int {
    $people=[];
    foreach(array_keys($node['children']??[]) as $id){
        $c=$nodes[$id]??[];
        if(($c['role']??'')==='C'&&($c['person']??null)!==null)$people[(string)$c['person']]=true;
    }
    return count($people)*RBM_PISO*RBM_ESCALA;
}
/** Meta del intervalo: días naturales inclusivos / 30, redondeo a 7 decimales. */
function rbm_range_goal(int $monthly,int $days): int {
    if($monthly<=0)return 0;
    $days=max(0,min(30,$days));
    return intdiv($monthly,30)*$days + intdiv(($monthly%30)*$days+15,30);
}
function rbm_name_key(string $n): string { $p=array_values(array_filter(explode(' ',RankingAtribucionServiceV06::norm($n))));sort($p,SORT_STRING);return implode(' ',$p); }
function rbm_increment(array &$nodes,string $id,array $a): void {
    while($id!==''){
        $nodes[$id]['count']++;$nodes[$id]['renta']=rbm_suma($nodes[$id]['renta'],$a['renta']);$nodes[$id]['accounts'][]=$a;
        $id=$nodes[$id]['parent']??'';
    }
}
/** Fecha estable para una cuenta en todo el mes; si hay varias, primera fecha y auditoría. */
function rbm_fecha_eventos(array $evs): array {
    $dates=[];foreach($evs as $e){$d=substr((string)($e['fecha']??''),0,10);if(rbm_fecha($d)&&$d>=RBM_INICIO&&$d<=RBM_FIN)$dates[$d]=true;}
    $d=array_keys($dates);sort($d,SORT_STRING);return $d;
}
function rbm_audit(array &$audit,string $motivo,string $cuenta,?string $fecha=null,string $detalle=''): void { $audit[]=['motivo'=>$motivo,'cuenta'=>$cuenta,'fecha'=>$fecha,'detalle'=>$detalle]; }
function rbm_build(mysqli $db,RankingAtribucionServiceV06 $motor,?int $dayFrom,?int $dayTo): array {
    if(PHP_INT_SIZE<8)throw new RuntimeException('Se requiere PHP de 64 bits');
    // Universo principal: una cuenta del reto; carga separada evita collation y multiplicación JOIN.
    $cat=rbm_catalogo(rbm_query($db,'SELECT `CUENTA`,`rentaSinImpuestos`,`PRECIO_BRM` FROM `buen_mes_reto`'));
    $reto=$cat['cuentas'];$groups=[];$events=[];$last=null;
    // Rango sargable; instalaciones sólo aporta fecha/distrito/atribución y canales.
    foreach(rbm_query($db,'SELECT cuenta,fecha,distrito,folio_empleado,lider,coach,origen_prospecto,subcanal FROM instalaciones WHERE fecha>=? AND fecha<?','ss',[RBM_INICIO,RBM_SIGUIENTE]) as $e){
        $c=rbm_cuenta($e['cuenta']??'');$k=rbm_account_key($c);if(!isset($reto[$k]))continue;
        $d=substr((string)$e['fecha'],0,10);if(!rbm_fecha($d))continue;
        $e['cuenta']=$c;$e['lider_id']=null;$groups[$k][]=$e;$events[]=$e;
        if($last===null||$d>$last)$last=$d;
    }
    $defaultEnd=$last!==null?(int)substr($last,8,2):1;
    $from=max(1,min(30,$dayFrom??1));$to=max(1,min(30,$dayTo??$defaultEnd));if($from>$to){[$from,$to]=[$to,$from];}
    $start=sprintf('2026-11-%02d',$from);$end=sprintf('2026-11-%02d',$to);
    usort($events,static fn($a,$b)=>strcmp((string)$a['fecha'],(string)$b['fecha'])?:strcmp((string)$a['folio_empleado'],(string)$b['folio_empleado']));
    $hc=$motor->cargarHc(RBM_INICIO,RBM_FIN);$hic=RankingAtribucionServiceV06::resolverEquivalenciasHic($motor->cargarHic());
    $standby=$motor->cargarStandby(RBM_INICIO,RBM_FIN,[],$hic['canonicos']);
    $catalogo=$motor->catalogoLideres(RBM_INICIO,RBM_FIN);
    $disponibilidad=rbm_disponibilidad($hc,$hic,$standby);
    $resolved=[];foreach($motor->resolverFilas($events,$hc,$hic['equivalencias'],$hic['canonicos'],$hic['estados']) as $r)$resolved[rbm_account_key((string)$r['cuenta'])]=$r;
    $indexes=[];foreach($hc['datos'] as $f=>$rows)$indexes[$f]=rbm_hc_index($rows);
    $nodes=[];rbm_node($nodes,'SUR',null,'R','REGIÓN SUR','');
    foreach(RBM_METAS as $d=>$m)rbm_node($nodes,rbm_district_node($d),'SUR','D',$m['nombre'],$d);
    // Incluir cualquier colaborador presente en una foto que cruce el rango, no sólo la foto final.
    $photos=[];foreach(rbm_dias($start,$end) as $day){$ph=$hc['fotos'][rbm_semana($day)]??null;if($ph)$photos[implode('-',$ph)]=true;}
    foreach(array_keys($photos) as $ph)rbm_roster($nodes,$indexes[$ph]??[],$hic,$catalogo);
    $audit=[];$counts=['catalogo'=>count($reto),'filas_vacias'=>$cat['vacias'],'sin_match'=>0,'conflictos'=>0,'nulos'=>0,'sin_distrito'=>0,'sin_jerarquia'=>0,'duplicados'=>0,'fechas_multiples'=>0];
    $daily=[];foreach(rbm_dias() as $d)$daily[$d]=['count'=>0,'renta'=>0];
    foreach($reto as $k=>$b){
        $c=$b['cuenta'];$evs=$groups[$k]??[];usort($evs,static fn($a,$b)=>strcmp((string)$a['fecha'],(string)$b['fecha'])?:strcmp((string)$a['folio_empleado'],(string)$b['folio_empleado']));$dates=rbm_fecha_eventos($evs);$fecha=$dates[0]??null;
        if($b['filas']>1){$counts['duplicados']++;rbm_audit($audit,$b['conflicto']?'IMPORTES_CONFLICTIVOS':'DUPLICADO_IDENTICO',$c,$fecha,'Filas reto: '.$b['filas'].($b['conflicto']?' | Rentas: '.implode(', ',array_map(static fn($v)=>$v===null?'NULL':rbm_exact($v),array_values($b['rentas']))).' | Precio BRM: '.implode(', ',array_map(static fn($v)=>$v===null?'NULL':rbm_exact($v),array_values($b['brm']))):''));}
        if(!$dates){$counts['sin_match']++;rbm_audit($audit,'SIN_INSTALACION_NOVIEMBRE',$c,null);}
        if($b['conflicto']){$counts['conflictos']++;continue;}
        if($b['renta']===null){$counts['nulos']++;rbm_audit($audit,'RENTA_NULA_O_INVALIDA',$c,$fecha);continue;}
        if(!$dates)continue;
        if(count($dates)>1){$counts['fechas_multiples']++;rbm_audit($audit,'FECHAS_MULTIPLES',$c,$fecha,'Primera fecha del mes: '.implode(', ',$dates));}
        $r=$resolved[$k]??[];$lid=(string)($r['lider_id']??'');$hcD=$lid!==''?explode('|',$lid,2)[0]:null;
        $districts=[];$other=false;
        foreach($evs as $e){$raw=trim((string)($e['distrito']??''));$d=RankingAtribucionServiceV06::normalizarDistrito($raw);if($d!==null)$districts[$d]=true;elseif($raw!=='')$other=true;}
        $d=count($districts)===1&&!$other?(string)array_key_first($districts):(!count($districts)&&!$other&&isset(RBM_METAS[$hcD??''])?$hcD:null);
        if($d===null){$counts['sin_distrito']++;rbm_audit($audit,'DISTRITO_NO_UNIVOCO_O_FUERA_SUR',$c,$fecha,implode(', ',array_keys($districts)));continue;}
        $root=rbm_district_node($d);$leaf=$root;$assigned=false;
        $cert=($r['fuente']??'')==='HC_FOTO'&&$hcD===$d&&$lid!==''&&isset($catalogo[$lid])&&!empty($r['coach_id']);
        $ph=(string)($r['hc_foto']??'');$idx=$indexes[$ph]??[];
        $lpos=(string)($r['lider_posicion']??'');$cpos=(string)($r['coach_id']??'');
        $lr=$cert?rbm_hc_row($idx,$d,$lpos):null;$cr=$cert?rbm_hc_row($idx,$d,$cpos):null;
        if($cert&&$lr!==null&&$cr!==null){
            $assigned=true;
        }
        // Auditoría de todo noviembre (el resumen visible separa las cifras del filtro).
        if(!$assigned){$counts['sin_jerarquia']++;rbm_audit($audit,'SIN_JERARQUIA_CERTIFICADA',$c,$fecha,($hcD!==null&&$hcD!==$d)?'Distrito HC distinto al de instalación':(string)($r['estado']??'SIN_RESOLUCION'));}
        if($fecha<$start||$fecha>$end)continue;
        if($assigned){
            $ln=rbm_role_node($nodes,$root,'L',$d,$lr,$hic,$lpos);$cn=rbm_role_node($nodes,$ln,'C',$d,$cr,$hic,$cpos);
            $vp=(string)($r['vendedor_id']??'');$vname=(string)($r['vendedor_nombre']??'');
            $leaf=rbm_role_node($nodes,$cn,'V',$d,null,$hic,null,$vname!==''?$vname:'VENDEDOR NO IDENTIFICADO',$vp!==''?$vp:null);
            $dir=rbm_hc_row($idx,$d,(string)($lr['posicion_lr']??''));if($dir!==null)rbm_director($nodes,$d,$dir,$hic);
        } else {
            $leaf=rbm_node($nodes,rbm_node_key($root,'L','SIN_JERARQUIA'),$root,'L','SIN JERARQUÍA CERTIFICADA',$d);
        }
        $a=['cuenta'=>$c,'fecha'=>$fecha,'renta'=>$b['renta'],'precio'=>$b['precio'],'distrito'=>$d,'canal'=>(string)($evs[0]['origen_prospecto']??''),'subcanal'=>(string)($evs[0]['subcanal']??''),'estado'=>$assigned?'CERTIFICADA':'SIN JERARQUÍA'];
        rbm_increment($nodes,$leaf,$a);$daily[$fecha]['count']++;$daily[$fecha]['renta']=rbm_suma($daily[$fecha]['renta'],$b['renta']);
    }
    // Las metas mensuales de líder no cambian al seleccionar un subrango de días.
    // Se usa la unión de coaches identificados de las fotos de noviembre conocidas.
    $monthlyRoster=[];rbm_node($monthlyRoster,'SUR',null,'R','REGIÓN SUR','');
    foreach(RBM_METAS as $d=>$m)rbm_node($monthlyRoster,rbm_district_node($d),'SUR','D',$m['nombre'],$d);
    foreach($indexes as $idx)rbm_roster($monthlyRoster,$idx,$hic,$catalogo);
    foreach($nodes as $id=>&$n){
        if($n['role']==='L')$n['monthly_goal']=isset($monthlyRoster[$id])?rbm_leader_goal($monthlyRoster[$id],$monthlyRoster):rbm_leader_goal($n,$nodes);
    }unset($n);
    // Si un coach cambia de líder durante el rango, su piso es personal y no se divide por plaza.
    $coachTotals=[];foreach($nodes as $n){if($n['role']==='C'&&$n['person']!==null){$k=$n['district'].'|'.$n['person'];$coachTotals[$k]=rbm_suma($coachTotals[$k]??0,$n['renta']);}}
    foreach($nodes as &$n){
        if($n['role']==='C'&&$n['person']!==null)$n['coach_total']=$coachTotals[$n['district'].'|'.$n['person']]??0;
        if($n['children']){
            $sum=0;$cnt=0;foreach(array_keys($n['children']) as $kid){$sum=rbm_suma($sum,$nodes[$kid]['renta']);$cnt+=$nodes[$kid]['count'];}
            if($sum!==$n['renta']||$cnt!==$n['count'])throw new LogicException('Conciliación de árbol inconsistente: '.$n['name']);
        }
    }unset($n);
    usort($audit,static fn($a,$b)=>strcmp((string)$b['fecha'],(string)$a['fecha'])?:strcmp($a['cuenta'],$b['cuenta']));
    return compact('nodes','counts','audit','disponibilidad','daily','last','from','to','start','end','defaultEnd')+['photos'=>array_keys($photos)];
}
