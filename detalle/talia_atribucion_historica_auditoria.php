<?php
/**
 * TalIA - Auditoría de atribución histórica v0.1 (SOLO LECTURA).
 * Ejecutar a través de ranking_productividad_HISTORICO_STAGING.php?auditoria_historica=1
 * Requiere $conexion mysqli y sesión autorizada ya inicializadas por el Ranking.
 * No actualiza hc, instalaciones, HIC ni catálogos.
 */
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(500); exit('Conexión no disponible');
}
function ahs($x): string { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); }
function ah_month($v): string { return substr((string)$v, 0, 7); }
function ah_snapshots(mysqli $db, string $start, string $end): array {
    $res = $db->query('SELECT DISTINCT anio, semana FROM hc WHERE anio IS NOT NULL AND semana IS NOT NULL ORDER BY anio, semana');
    if (!$res) throw new RuntimeException($db->error);
    $avail = [];
    while ($r=$res->fetch_assoc()) {
        $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monday = $d->setISODate((int)$r['anio'], (int)$r['semana'], 1)->format('Y-m-d');
        $avail[] = ['anio'=>(int)$r['anio'],'semana'=>(int)$r['semana'],'monday'=>$monday];
    }
    $months=[];
    $cursor=new DateTimeImmutable(substr($start,0,7).'-01');
    $last=new DateTimeImmutable(substr($end,0,7).'-01');
    for (; $cursor <= $last; $cursor=$cursor->modify('+1 month')) {
        $next=$cursor->modify('+1 month');
        $nextWeekStart=$next->modify('monday this week')->format('Y-m-d');
        $eligible=array_values(array_filter($avail, fn($s)=>$s['monday'] < $nextWeekStart));
        $best=end($eligible);
        if (!$best) throw new RuntimeException('No existe fotografía HC para '. $cursor->format('Y-m'));
        $months[]=['mes'=>$cursor->format('Y-m'),'anio'=>$best['anio'],'semana'=>$best['semana']];
    }
    return $months;
}
function ah_audit(mysqli $db,string $from,string $to,string $district,string $leaderPos): array {
    $months=ah_snapshots($db,$from,$to);
    $rows=[];
    foreach($months as $m) {
        $rows[]="SELECT '".$db->real_escape_string($m['mes'])."' AS mes, ".(int)$m['anio']." AS anio, ".(int)$m['semana']." AS semana";
    }
    $mapping=implode(' UNION ALL ', $rows);
    $from=$db->real_escape_string($from);$endExclusive=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
    $endExclusive=$db->real_escape_string($endExclusive);
    $district=$db->real_escape_string($district);$pos=$db->real_escape_string($leaderPos);
    // Cada cuenta se reduce a una fila antes de asociar personas. En colisión se marca la cuenta para revisión.
    // No se suman personas ni posiciones ambiguas.
    $sql="WITH mapa AS ({$mapping}),
    eventos AS (
        SELECT TRIM(i.cuenta) cuenta, MIN(i.fecha) fecha,
               MIN(TRIM(i.folio_empleado)) folio,
               MIN(TRIM(i.coach)) coach_sf,
               MIN(TRIM(i.lider)) lider_sf,
               COUNT(*) filas,
               COUNT(DISTINCT TRIM(i.folio_empleado)) folios_distintos,
               COUNT(DISTINCT i.fecha) fechas_distintas
        FROM instalaciones i
        WHERE i.fecha >= '{$from}' AND i.fecha < '{$endExclusive}'
          AND i.cuenta IS NOT NULL AND TRIM(i.cuenta)<>''
          AND UPPER(TRIM(i.distrito))=UPPER('{$district}')
        GROUP BY TRIM(i.cuenta)
    ),
    candidatos AS (
        SELECT e.cuenta, e.fecha, e.folio, e.coach_sf, e.lider_sf,
               e.filas,e.folios_distintos,e.fechas_distintas,
               h.id_posicion vendedor_pos, h.nombre_colaborador vendedor,
               h.posicion_lr coach_pos, h.nombre_linea_reporte coach_hc,
               m.anio,m.semana,
               CASE WHEN TRIM(h.numero_sf)=e.folio THEN 1
                    WHEN TRIM(h.numero_talento_gs)=e.folio THEN 2
                    WHEN TRIM(h.numero_base_comisiones)=e.folio THEN 3
                    ELSE 4 END via_priority
        FROM eventos e
        JOIN mapa m ON m.mes=DATE_FORMAT(e.fecha,'%Y-%m')
        JOIN hc h ON h.anio=m.anio AND h.semana=m.semana
          AND h.nombre_colaborador <> 'VACANTE'
          AND (TRIM(h.numero_sf)=e.folio
               OR TRIM(h.numero_talento_gs)=e.folio
               OR TRIM(h.numero_base_comisiones)=e.folio
               OR EXISTS (
                 SELECT 1 FROM historial_identidad_colaborador hi
                 WHERE (hi.nombre_colaborador IS NULL OR hi.nombre_colaborador='' OR UPPER(TRIM(hi.nombre_colaborador))=UPPER(TRIM(h.nombre_colaborador)))
                   AND (e.folio=hi.numero_talento_anterior OR e.folio=hi.numero_talento_nuevo)
                   AND (h.numero_talento_gs=hi.numero_talento_anterior OR h.numero_talento_gs=hi.numero_talento_nuevo)
               ))
        WHERE h.distrito='{$district}'
    ),
    evaluados AS (
        SELECT c.cuenta,c.fecha,c.folio,c.coach_sf,c.lider_sf,c.filas,c.folios_distintos,c.fechas_distintas,
               COUNT(DISTINCT c.vendedor_pos) AS personas,
               COUNT(DISTINCT c.coach_pos) AS coaches,
               MIN(c.vendedor_pos) vendedor_pos,
               MIN(c.vendedor) vendedor,
               MIN(c.coach_pos) coach_pos,
               MIN(c.coach_hc) coach_hc,
               MIN(c.semana) fotografia_hc,
               MIN(c.via_priority) via_priority
        FROM candidatos c GROUP BY c.cuenta,c.fecha,c.folio,c.coach_sf,c.lider_sf,c.filas,c.folios_distintos,c.fechas_distintas
    ),
    clasificados AS (
       SELECT e.cuenta,e.fecha,e.folio,e.coach_sf,e.lider_sf,
          a.vendedor,a.vendedor_pos,a.coach_hc,a.coach_pos,a.fotografia_hc,a.via_priority,
          CASE WHEN e.folios_distintos > 1 OR e.fechas_distintas > 1 THEN 'EVENTO_AMBIGUO'
               WHEN a.cuenta IS NULL THEN 'SIN_IDENTIDAD_HC'
               WHEN a.personas <> 1 OR a.coaches <> 1 THEN 'HC_AMBIGUO'
               WHEN EXISTS (SELECT 1 FROM hc hc_coach
                            WHERE hc_coach.anio=YEAR(e.fecha)
                              AND hc_coach.id_posicion=a.coach_pos
                              AND hc_coach.posicion_lr='{$pos}'
                              AND hc_coach.semana=a.fotografia_hc)
                  THEN 'ATRIBUIDA'
               ELSE 'OTRA_ESTRUCTURA'
          END estado
       FROM eventos e LEFT JOIN evaluados a ON a.cuenta=e.cuenta
    )
    SELECT * FROM clasificados
    WHERE estado='ATRIBUIDA'
       OR UPPER(TRIM(lider_sf)) IN (
           SELECT UPPER(TRIM(h.nombre_colaborador)) FROM hc h
           WHERE h.id_posicion='{$pos}' AND h.puesto_lr LIKE '%DIRECTOR%'
       )
    ORDER BY fecha, cuenta";
    $res=$db->query($sql);
    if (!$res) throw new RuntimeException($db->error);
    $data=[];while($r=$res->fetch_assoc()) $data[]=$r;
    return [$months,$data];
}
$from=(string)($_GET['desde'] ?? '2026-09-28');
$to=(string)($_GET['hasta'] ?? '2026-10-04');
$district=(string)($_GET['distrito_hc'] ?? 'MERIDA');
$leaderPos=(string)($_GET['posicion_lider'] ?? '1739397');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||$from>$to||((strtotime($to)-strtotime($from))/86400)>90) { http_response_code(400);exit('Rango inválido (máximo 90 días)'); }
try { [$months,$data]=ah_audit($conexion,$from,$to,$district,$leaderPos); }
catch(Throwable $e){http_response_code(500);exit('<pre>Error de auditoría: '.ahs($e->getMessage()).'</pre>');}
$totals=[];foreach($data as $r){$k=$r['estado'].' | '.($r['coach_hc'] ?: '(sin HC)');$totals[$k]=($totals[$k]??0)+1;}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>TalIA | Auditoría histórica</title><style>body{font:14px system-ui;padding:24px;color:#17202a}table{border-collapse:collapse;width:100%;font-size:12px}td,th{padding:7px;border-bottom:1px solid #ddd;text-align:left}th{background:#eee;position:sticky;top:0}h1{font-size:22px}.warn{color:#a33}</style></head><body>
<h1>TalIA | Auditoría temporal (solo lectura)</h1>
<p><b>Rango:</b> <?=ahs($from)?> a <?=ahs($to)?> &nbsp; <b>Distrito HC:</b> <?=ahs($district)?> &nbsp; <b>Posición líder:</b> <?=ahs($leaderPos)?></p>
<p><b>Fotografías seleccionadas:</b> <?php foreach($months as $m) echo ahs($m['mes'].' → SEM'.$m['semana'].'/'.$m['anio']).' &nbsp; '; ?></p>
<p><b>Cuentas dentro de la auditoría:</b> <?=count($data)?>. El universo incluye asignaciones verificadas a la posición y eventos con líder registrado en las fotografías disponibles; no supone que todas las cuentas del distrito pertenezcan a esa posición.</p>
<h2>Conteos y excepciones</h2><table><tr><th>Estado / coach</th><th>Cuentas</th></tr><?php foreach($totals as $k=>$count):?><tr><td><?=ahs($k)?></td><td><?= (int)$count ?></td></tr><?php endforeach;?></table>
<h2>Detalle por cuenta</h2><table><tr><?php foreach(['cuenta','fecha','folio','lider_sf','coach_sf','vendedor','coach_hc','coach_pos','fotografia_hc','via_priority','estado'] as $col):?><th><?=ahs($col)?></th><?php endforeach;?></tr>
<?php foreach($data as $r):?><tr><?php foreach(['cuenta','fecha','folio','lider_sf','coach_sf','vendedor','coach_hc','coach_pos','fotografia_hc','via_priority','estado'] as $col):?><td><?=ahs($r[$col]??'')?></td><?php endforeach;?></tr><?php endforeach;?></table>
<p class="warn">PREVALIDACIÓN: pendiente contrastar resultados contra la base operativa antes de activar el motor en el Ranking.</p>
</body></html>
