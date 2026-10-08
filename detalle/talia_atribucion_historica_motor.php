<?php
/** TalIA - motor de atribución histórica, candidato STAGING, solo lectura.
 * Devuelve una fila por cuenta para el período solicitado. No contiene IDs de personas ni plazas específicas.
 */
function talia_snapshots(mysqli $db, string $start, string $end): array {
    $q=$db->query('SELECT DISTINCT anio, semana FROM hc WHERE anio IS NOT NULL AND semana IS NOT NULL ORDER BY anio, semana');
    if (!$q) throw new RuntimeException($db->error);
    $available=[];
    while ($r=$q->fetch_assoc()) {
        $monday=(new DateTimeImmutable('2026-01-01'))->setISODate((int)$r['anio'],(int)$r['semana'],1)->format('Y-m-d');
        $available[]=['anio'=>(int)$r['anio'],'semana'=>(int)$r['semana'],'monday'=>$monday];
    }
    $out=[];
    $cursor=new DateTimeImmutable(substr($start,0,7).'-01');
    $last=new DateTimeImmutable(substr($end,0,7).'-01');
    while ($cursor<=$last) {
        $next=$cursor->modify('+1 month');
        $cutoff=$next->modify('monday this week')->format('Y-m-d');
        $eligible=array_values(array_filter($available,fn($s)=>$s['monday']<$cutoff));
        $best=end($eligible);
        if (!$best) throw new RuntimeException('Falta HC para '.$cursor->format('Y-m'));
        $out[]=['mes'=>$cursor->format('Y-m'),'anio'=>$best['anio'],'semana'=>$best['semana']];
        $cursor=$next;
    }
    return $out;
}

function talia_historico_eventos(mysqli $db, string $desde, string $hasta, array $diasMysql = []): array {
    $map = talia_snapshots($db, $desde, $hasta);
    $parts=[];
    foreach ($map as $m) {
        $parts[]="SELECT '".$db->real_escape_string($m['mes'])."' mes, ".(int)$m['anio']." anio, ".(int)$m['semana']." semana";
    }
    $mapSql=implode(' UNION ALL ', $parts);
    $ini=$db->real_escape_string($desde);
    $fin=$db->real_escape_string((new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d'));
    $days = array_values(array_unique(array_filter(array_map('intval',$diasMysql),fn($n)=>$n>=1&&$n<=7)));
    $whereDays=$days ? ' AND DAYOFWEEK(i.fecha) IN ('.implode(',',$days).')' : '';
    // Primero se reduce cada cuenta a una fila, luego se resuelve HC. No se suma un fallback agregado.
    $sql="WITH mapa AS ($mapSql),
    eventos AS (
       SELECT TRIM(i.cuenta) cuenta, MIN(i.fecha) fecha,
              MIN(TRIM(i.folio_empleado)) folio,
              MIN(UPPER(TRIM(i.distrito))) distrito_sf,
              MIN(TRIM(i.lider)) lider_sf, MIN(TRIM(i.coach)) coach_sf,
              MIN(i.plan) plan,
              COUNT(DISTINCT TRIM(i.folio_empleado)) folios,
              COUNT(DISTINCT i.fecha) fechas,
              COUNT(DISTINCT UPPER(TRIM(i.distrito))) distritos
       FROM instalaciones i
       WHERE i.fecha >= '$ini' AND i.fecha < '$fin' $whereDays
         AND i.cuenta IS NOT NULL AND TRIM(i.cuenta)<>''
       GROUP BY TRIM(i.cuenta)
    ),
    candidatos AS (
       SELECT e.cuenta,h.id_posicion vendedor_pos,h.numero_talento_gs talento,
              h.nombre_colaborador vendedor,h.fecha_alta,
              h.posicion_lr coach_pos,h.nombre_linea_reporte coach_hc,
              h.distrito distrito_hc,m.semana,m.anio,
              CASE WHEN TRIM(h.numero_sf)=e.folio THEN 1
                   WHEN TRIM(h.numero_talento_gs)=e.folio THEN 2
                   WHEN TRIM(h.numero_base_comisiones)=e.folio THEN 3 ELSE 4 END via
       FROM eventos e
       JOIN mapa m ON m.mes=DATE_FORMAT(e.fecha,'%Y-%m')
       JOIN hc h ON h.anio=m.anio AND h.semana=m.semana
           AND h.nombre_colaborador <> 'VACANTE'
           AND (TRIM(h.numero_sf)=e.folio OR TRIM(h.numero_talento_gs)=e.folio
                OR TRIM(h.numero_base_comisiones)=e.folio
                OR EXISTS (SELECT 1 FROM historial_identidad_colaborador hi
                   WHERE (hi.nombre_colaborador IS NULL OR hi.nombre_colaborador=''
                          OR UPPER(TRIM(hi.nombre_colaborador))=UPPER(TRIM(h.nombre_colaborador)))
                     AND (e.folio=hi.numero_talento_anterior OR e.folio=hi.numero_talento_nuevo)
                     AND (h.numero_talento_gs=hi.numero_talento_anterior OR h.numero_talento_gs=hi.numero_talento_nuevo)))
    ),
    asignacion AS (
       SELECT c.cuenta,COUNT(DISTINCT CONCAT(c.distrito_hc,'|',c.vendedor_pos)) personas,
              COUNT(DISTINCT CONCAT(c.distrito_hc,'|',c.coach_pos)) coaches,
              MIN(c.vendedor_pos) vendedor_pos,MIN(c.talento) talento,
              MIN(c.vendedor) vendedor,MIN(c.fecha_alta) fecha_alta,
              MIN(c.coach_pos) coach_pos, MIN(c.coach_hc) coach_hc,
              MIN(c.distrito_hc) distrito_hc,MIN(c.semana) fotografia,
              MIN(c.anio) anio_hc,MIN(c.via) via
       FROM candidatos c GROUP BY c.cuenta
    ),
    final_hc AS (
       SELECT e.*,a.vendedor_pos,a.talento,a.vendedor,a.fecha_alta,a.coach_pos,a.coach_hc,
              a.distrito_hc,a.fotografia,a.anio_hc,a.via,
              (SELECT CASE WHEN COUNT(DISTINCT CONCAT(TRIM(l.posicion_lr),'|',TRIM(l.distrito)))=1
                    THEN MIN(TRIM(l.posicion_lr)) ELSE NULL END
               FROM hc l WHERE l.anio=a.anio_hc AND l.semana=a.fotografia
                 AND TRIM(l.id_posicion)=TRIM(a.coach_pos)
                 AND UPPER(TRIM(l.distrito))=UPPER(TRIM(a.distrito_hc))
                 AND l.puesto_lr LIKE '%LIDER%') lider_pos,
              CASE WHEN e.folios<>1 OR e.fechas<>1 OR e.distritos<>1 THEN 'EVENTO_AMBIGUO'
                   WHEN a.cuenta IS NULL THEN 'SIN_IDENTIDAD_HC'
                   WHEN a.personas<>1 OR a.coaches<>1 THEN 'HC_AMBIGUO'
                   ELSE 'IDENTIFICADA' END estado
       FROM eventos e LEFT JOIN asignacion a ON a.cuenta=e.cuenta
    ) SELECT * FROM final_hc";
    $res=$db->query($sql);
    if (!$res) throw new RuntimeException('Motor histórico: '.$db->error);
    $out=[];
    while($r=$res->fetch_assoc()) $out[]=$r;
    return $out;
}
