<?php
/** Adaptador del motor por cuenta a las vistas existentes de TalIA (STAGING). */
function talia_historico_aplicar_ranking(mysqli $db, array $cfg, string $view, array &$rows, array &$matrix,
    string $baseIni,string $baseFin,string $actIni,string $actFin,array $days,
    string $distrito,string $lider,string $coach,string $coachPos,
    int $semBase,int $semAct,int $diasBase,int $diasAct): void {
    if ($view==='ventas') return; // Histórico individual se mantiene legacy en esta candidata.
    $leaders=[];
    foreach (($cfg['leaders']??[]) as $entry) {
        $key=strtoupper(trim((string)($entry['distrito_hc']??''))).'|'.trim((string)($entry['lider_pos']??''));
        if (empty($entry['lider_pos'])) continue;
        if (isset($leaders[$key]) && $leaders[$key]['lider_hc']!==$entry['lider_hc']) {
            throw new RuntimeException('Colisión en catálogo de posiciones líder: '.$key);
        }
        $leaders[$key]=$entry;
    }
    $windows=[['start'=>$baseIni,'end'=>$baseFin,'key'=>'base'],['start'=>$actIni,'end'=>$actFin,'key'=>'actual']];
    $assigned=[];
    $unresolved=[];
    foreach ($windows as $w) {
        $events=talia_historico_eventos($db,$w['start'],$w['end'],$days);
        foreach($events as $e) {
            if ($e['estado']!=='IDENTIFICADA' || !$e['lider_pos']) {
                $unresolved[$w['key']][$e['estado']==='IDENTIFICADA'?'SIN_LIDER_POS':$e['estado']] =
                    ($unresolved[$w['key']][$e['estado']==='IDENTIFICADA'?'SIN_LIDER_POS':$e['estado']]??0)+1;
                continue;
            }
            $key=strtoupper(trim($e['distrito_hc'])).'|'.trim($e['lider_pos']);
            if (!isset($leaders[$key])) {
                $unresolved[$w['key']]['LIDER_NO_CATALOGADO']=($unresolved[$w['key']]['LIDER_NO_CATALOGADO']??0)+1;
                continue;
            }
            $la=$leaders[$key];
            $e['_leader']=$la;
            $e['_period']=$w['key'];
            $assigned[]=$e;
        }
    }
    // Mostrar los eventos no resueltos como incidencia: nunca suplirlos con sumas legacy.
    $GLOBALS['talia_historico_excepciones']=$unresolved;
    $counts=[];
    $salespeople=[];
    $catalog=[];
    if ($view==='vendedores') {
        $q=$db->query('SELECT nombre_plan, play, tipo FROM catalogo_paquetes');
        if (!$q) throw new RuntimeException('Catálogo de paquetes no disponible: '.$db->error);
        while($item=$q->fetch_assoc()) {
            $catalog[strtoupper(trim((string)$item['nombre_plan']))] = $item;
        }
    }
    foreach ($assigned as $e) {
        $la=$e['_leader'];
        $pos=trim((string)$e['coach_pos']);
        $lk=strtoupper(trim($la['distrito_reporte'])).'|'.strtoupper(trim($la['lider_hc']));
        $ck=$lk.'|'.$pos;
        $period=$e['_period'];
        $counts['leader'][$lk][$period]=($counts['leader'][$lk][$period]??0)+1;
        $counts['coach'][$ck][$period]=($counts['coach'][$ck][$period]??0)+1;
        $salespeople[$ck][$period][]=$e;
    }
    if ($view==='vendedores') {
        $matrix=[];
        $lk=strtoupper(trim($distrito)).'|'.strtoupper(trim($lider));
        foreach ($salespeople as $ck=>$periods) {
            if (!str_starts_with($ck,$lk.'|')) continue;
            $pos=substr($ck,strlen($lk)+1);
            if ($coachPos!=='' && $pos!==trim($coachPos)) continue;
            foreach ($periods as $period=>$events) foreach ($events as $e) {
                $identity=trim((string)($e['talento']?:$e['folio']));
                $id='ID|'.$identity;
                if (!isset($matrix[$id])) {
                    $seniority='-';
                    if (!empty($e['fecha_alta']) && $e['fecha_alta']!=='0000-00-00') {
                        $m=(new DateTimeImmutable($e['fecha_alta']))->diff(new DateTimeImmutable($actFin));
                        $seniority=$m->y.' años '.$m->m.' meses';
                    }
                    $matrix[$id]=['vendedor'=>$e['vendedor'],'folio_empleado'=>$identity,'antiguedad'=>$seniority,
                        'semanas'=>array_fill(1,max($semAct,$semBase),0),'total'=>0,
                        'triple_play'=>0,'doble_play'=>0,'negocios'=>0,'residencial'=>0];
                }
                $sem=($period==='actual')?$semAct:$semBase;
                $matrix[$id]['semanas'][$sem]=($matrix[$id]['semanas'][$sem]??0)+1;
                $matrix[$id]['total']++;
                if ($period==='actual') {
                    $pack=$catalog[strtoupper(trim((string)($e['plan']??'')))]??null;
                    if ($pack) {
                        $play=strtoupper(trim((string)$pack['play']));
                        $type=strtoupper(trim((string)$pack['tipo']));
                        if ($play==='TRIPLE PLAY') $matrix[$id]['triple_play']++;
                        if ($play==='DOBLE PLAY') $matrix[$id]['doble_play']++;
                        if ($type==='NEGOCIOS') $matrix[$id]['negocios']++;
                        if ($type==='RESIDENCIAL') $matrix[$id]['residencial']++;
                    }
                }
            }
        }
        uasort($matrix,fn($a,$b)=>(($b['semanas'][$semAct]??0)<=>($a['semanas'][$semAct]??0)) ?: strcmp($a['vendedor'],$b['vendedor']));
        return;
    }
    $isLeader=($view==='lideres');
    $updated=[];
    foreach ($rows as $r) {
        $lk=strtoupper(trim($r['distrito'])).'|'.strtoupper(trim($r['lider']));
        $ck=$lk.'|'.trim((string)($r['coach_pos']??''));
        $key=$isLeader?$lk:$ck;
        $bucket=$counts[$isLeader?'leader':'coach'][$key]??[];
        $r['ins_sem_base']=$bucket['base']??0;
        $r['ins_sem_actual']=$bucket['actual']??0;
        $r['dif']=$r['ins_sem_actual']-$r['ins_sem_base'];
        $r['pct_dif']=$r['ins_sem_base']>0?round($r['dif']/$r['ins_sem_base']*100):null;
        $r['prod_base']=($r['hc_activo_base']??0)>0?round($r['ins_sem_base']/$r['hc_activo_base']/$diasBase,2):null;
        $r['prod_actual']=($r['hc_activo_actual']??0)>0?round($r['ins_sem_actual']/$r['hc_activo_actual']/$diasAct,2):null;
        $updated[$key]=true;
        $rowsOut[]=$r;
    }
    // Si el HC de cierre ya no lista al coach histórico, crear la fila con HC=0;
    // nunca perder instalaciones que sí tienen atribución válida.
    foreach ($counts[$isLeader?'leader':'coach']??[] as $key=>$bucket) {
        if (isset($updated[$key])) continue;
        $parts=explode('|',$key);
        $district=$parts[0]??''; $leaderName=$parts[1]??'';
        $coachPosition=$isLeader?'':($parts[2]??'');
        $name=$leaderName;
        if (!$isLeader) {
            $name='POSICIÓN COACH '.$coachPosition;
            foreach ($assigned as $e) {
                if (strtoupper(trim($e['_leader']['distrito_reporte'])).'|'.strtoupper(trim($e['_leader']['lider_hc'])).'|'.trim((string)$e['coach_pos'])===$key) {
                    $name=$e['coach_hc']?:$name;break;
                }
            }
        }
        $base=$bucket['base']??0; $current=$bucket['actual']??0;
        $rowsOut[]=['distrito'=>$district,'entidad'=>$name,'lider'=>$leaderName,
            'coach'=>$isLeader?'':$name,'coach_pos'=>$coachPosition,'folio_empleado'=>'',
            'ins_sem_base'=>$base,'ins_sem_actual'=>$current,'dif'=>$current-$base,
            'pct_dif'=>$base?round(100*($current-$base)/$base):null,
            'hc_activo_base'=>0,'hc_activo_actual'=>0,'hc_con_ins_base'=>0,'hc_con_ins_actual'=>0,
            'hc_sin_venta_base'=>0,'hc_sin_venta_actual'=>0,'pct_hc_sin_ins_base'=>null,
            'pct_hc_sin_ins_actual'=>null,'prod_base'=>null,'prod_actual'=>null,
            'activo_base'=>0,'vacante_base'=>0,'hc_total_base'=>0,
            'activo_actual'=>0,'vacante_actual'=>0,'hc_total_actual'=>0];
    }
    $rows=$rowsOut??[];
    usort($rows,fn($a,$b)=>(($b['prod_actual']??-1)<=>($a['prod_actual']??-1)) ?: (($b['ins_sem_actual']??0)<=>($a['ins_sem_actual']??0)));
}
