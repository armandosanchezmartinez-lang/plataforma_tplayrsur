<?php
declare(strict_types=1);
/**
 * TalIA | Certificación diagnóstica HC/HIC real v1.0
 * SOLO LECTURA; no altera tablas ni modifica servicios existentes.
 * Mantener acceso ADMIN; retirar del servidor al concluir validación.
 */
ini_set('display_errors','0');
error_reporting(E_ALL);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');
session_start();
if (empty($_SESSION['usuario']) || (string)($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403); exit('Acceso restringido al administrador.');
}
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/ranking_atribucion_service.php';
function cert_h($x): string { return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function cert_name($x): string { return preg_replace('/\s+/u',' ',mb_strtoupper(trim((string)$x),'UTF-8')) ?? ''; }
function cert_summary(array $rows): array {
    $s=['cuentas'=>count($rows),'hic_verificable'=>0,'hic_ambigua'=>0,'sin_cadena_hic'=>0,
        'coach_provisional'=>0,'sin_coach'=>0,'sin_vendedor'=>0,'otros_estados'=>[]];
    foreach ($rows as $r) {
        $id=(string)($r['identidad_hic']??'');
        if ($id==='HIC_VERIFICABLE') ++$s['hic_verificable'];
        elseif ($id==='HIC_AMBIGUA') ++$s['hic_ambigua'];
        else ++$s['sin_cadena_hic'];
        if (str_starts_with((string)($r['coach_id']??''),'EVENT|')) ++$s['coach_provisional'];
        if (empty($r['coach_id'])) ++$s['sin_coach'];
        if (empty($r['vendedor_id'])) ++$s['sin_vendedor'];
        if (($r['estado']??'OK')!=='OK') $s['otros_estados'][$r['estado']]=($s['otros_estados'][$r['estado']]??0)+1;
    }
    return $s;
}
$report=[]; $err=null;
try {
    if (!isset($conexion) || !($conexion instanceof mysqli)) throw new RuntimeException('Conexión MariaDB no disponible');
    // La consulta utiliza la interfaz HIC del MISMO motor actualmente instalado.
    $motor=new RankingAtribucionService($conexion);
    if (!method_exists($motor,'cargarHic') || !method_exists(RankingAtribucionService::class,'resolverEquivalenciasHic')) {
        throw new RuntimeException('El servicio instalado no corresponde a v0.3 HC/HIC');
    }
    $raw=$motor->cargarHic();
    $graph=RankingAtribucionService::resolverEquivalenciasHic($raw);
    $edgeSet=[]; $ignored=0; $nonempty=0;
    foreach ($raw as $r) {
        $a=trim((string)($r['numero_talento_anterior']??''));
        $b=trim((string)($r['numero_talento_nuevo']??''));
        if ($a==='' || $b==='' || $a==='-' || $b==='-' || $a===$b) { ++$ignored; continue; }
        ++$nonempty;
        $edgeSet[$a.'>'. $b]=true;
    }
    $states=array_count_values($graph['estados']??[]);
    $report['hic']=[
        'filas'=>count($raw),'validas'=>$nonempty,'enlaces_unicos'=>count($edgeSet),
        'duplicadas'=>$nonempty-count($edgeSet),'ignoradas'=>$ignored,
        'folios_con_cadena'=>count($graph['estados']??[]),
        'folios_verificables'=>(int)($states['HIC_VERIFICABLE']??0),
        'folios_ambiguos'=>(int)($states['HIC_AMBIGUA']??0)
    ];
    // Control independiente: folios en un mismo componente HIC que figuran bajo
    // múltiples nombres distintos en la misma fotografía HC. Solo señal de revisión.
    $report['weeks']=[];
    foreach ([40,41] as $sem) {
        $monday=(new DateTimeImmutable('2026-01-04'))->setISODate(2026,$sem,1);
        $desde=$monday->format('Y-m-d');
        $hasta=$monday->modify('+6 days')->format('Y-m-d');
        $result=$motor->obtenerInstalacionesJerarquia($desde,$hasta,null,null,null,[1,2,3]);
        $rows=$result['cuentas'];
        $summary=cert_summary($rows);
        $leaderOk=true;
        foreach (($result['conciliacion_global']['lideres']??[]) as $v) {
            if (!$v['coincide_coaches'] || !$v['coincide_vendedores']) $leaderOk=false;
        }
        $report['weeks'][]=[
            'semana'=>$sem,'periodo'=>$desde.' — '.$monday->modify('+2 days')->format('Y-m-d'),
            'version'=>$result['version']??'NO DISPONIBLE',
            'foto'=>implode('; ',array_map(static fn($p)=>$p?implode('/',$p):'NO DISPONIBLE', $result['fotografias_hc']??[])),
            'jerarquia_conserva'=>$leaderOk,
            'datos'=>$summary
        ];
    }
} catch (Throwable $e) {
    error_log('[TALIA_CERT_HIC_REAL] '.$e->getMessage());
    $err='No se completó la certificación. Consulta el registro de errores PHP en Hostinger. Comprueba que el motor v0.3 y las tablas HIC/HC estén disponibles.';
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TalIA | Certificación real HC/HIC</title>
<style>body{margin:0;background:#f3f5f9;font:15px/1.55 system-ui,Segoe UI,sans-serif;color:#17243b}main{max-width:1100px;margin:auto;padding:32px 18px}.panel{border:1px solid #dce3ef;background:white;border-radius:12px;padding:20px;margin:15px 0;overflow:auto}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #e6eaf2;padding:10px;text-align:left}th{background:#edf1f8}td.num{text-align:right;font-variant-numeric:tabular-nums}.ok{color:#157449}.warn{color:#a24b16}h1{margin:0}h2{font-size:19px}small{color:#60718a}</style></head><body><main>
<h1>TalIA · Certificación técnica HC/HIC real</h1><p>Consulta a MariaDB en solo lectura. Alcance: SEM40 y SEM41 de 2026, lunes a miércoles. Sin ajustes de plantillas, sin reasignaciones.</p>
<?php if($err):?><section class="panel warn"><?=cert_h($err)?></section><?php else:?>
<section class="panel"><h2>1. Historial HIC real</h2><table><tbody>
<?php foreach (['filas'=>'Filas HIC cargadas','validas'=>'Registros de cambio con dos folios válidos','enlaces_unicos'=>'Relaciones únicas anterior → nuevo','duplicadas'=>'Relaciones repetidas','ignoradas'=>'Filas sin cambio o inválidas','folios_con_cadena'=>'Folios presentes en cadenas','folios_verificables'=>'Folios en cadenas estructuralmente unívocas','folios_ambiguos'=>'Folios en cadenas ambiguas / ciclos'] as $k=>$title):?><tr><td><?=cert_h($title)?></td><td class="num"><?=cert_h($report['hic'][$k]??0)?></td></tr><?php endforeach;?></tbody></table>
<p><small>"Cadena estructuralmente unívoca" indica ausencia de bifurcación/ciclo en los pares de folios. No certifica por sí sola que ambos folios sean de la misma persona; esa propiedad requiere comprobar la evidencia original de HIC y su relación con Personas.</small></p></section>
<section class="panel"><h2>2. Aplicación de HIC a instalaciones reales</h2><table><thead><tr><th>Indicador</th><?php foreach($report['weeks'] as $w):?><th>SEM<?=cert_h($w['semana'])?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach (['cuentas'=>'Cuentas únicas','hic_verificable'=>'Cuentas con identidad normalizada mediante HIC','hic_ambigua'=>'Cuentas con HIC ambigua','sin_cadena_hic'=>'Cuentas sin evidencia de cambio HIC aplicado','coach_provisional'=>'Cuentas con coach EVENT| provisional','sin_coach'=>'Cuentas sin coach','sin_vendedor'=>'Cuentas sin vendedor'] as $k=>$title):?><tr><td><?=cert_h($title)?></td><?php foreach($report['weeks'] as $w):?><td class="num"><?=cert_h($w['datos'][$k]??0)?></td><?php endforeach;?></tr><?php endforeach; ?>
<tr><td>Conservación interna Líder → Coach/Vendedor</td><?php foreach($report['weeks'] as $w):?><td class="<?= $w['jerarquia_conserva']?'ok':'warn' ?>"><?= $w['jerarquia_conserva']?'CONSERVA':'DESCUADRE' ?></td><?php endforeach;?></tr>
<tr><td>Fotografía HC consultada</td><?php foreach($report['weeks'] as $w):?><td><?=cert_h($w['foto'])?></td><?php endforeach;?></tr>
<tr><td>Versión efectiva del motor</td><?php foreach($report['weeks'] as $w):?><td><?=cert_h($w['version'])?></td><?php endforeach;?></tr>
</tbody></table>
<?php foreach($report['weeks'] as $w): if (!$w['datos']['otros_estados']) continue;?><p><strong>SEM<?=cert_h($w['semana'])?> — estados no OK:</strong> <?=cert_h(implode('; ',array_map(static fn($k,$v)=>$k.'='.$v,array_keys($w['datos']['otros_estados']),array_values($w['datos']['otros_estados']))))?></p><?php endforeach;?></section>
<section class="panel"><h2>3. Dictamen</h2><p><strong class="warn">CERTIFICACIÓN DE PERSONAS PENDIENTE</strong></p><p>Este control acredita que se consultó HIC real y registra qué cambios aplicó el motor; no demuestra por sí solo la legitimidad de cada cambio ni la concordancia de identidades con Personas. Las relaciones HIC ambiguas, folios provisionalmente vinculados y posibles conflictos documentales deberán revisarse con la autoridad de identidad, sin modificar eventos históricos.</p></section>
<?php endif;?><section class="panel"><small>Uso exclusivo ADMIN. No se muestran folios ni cuentas; no se realizan escrituras. Retirar este auditor del servidor después de la revisión.</small></section>
</main></body></html>
