<?php
declare(strict_types=1);
/** TalIA Ranking - Auditoría v0.1 solo lectura. NO exponer sin autenticación. */
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=utf-8');
session_start();
if (empty($_SESSION['usuario']) || !in_array((string)($_SESSION['rol'] ?? ''), ['admin'], true)) {
    http_response_code(403);
    exit('Acceso restringido al administrador de TalIA.');
}
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/ranking_atribucion_service.php';
function eh($x): string {return htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
$sem = filter_input(INPUT_GET, 'semana', FILTER_VALIDATE_INT);
if (!in_array($sem, [40,41], true)) $sem=40;
$diasRaw = (string)($_GET['dias'] ?? '1,2,3');
$days = array_values(array_unique(array_map('intval', array_filter(explode(',', $diasRaw), static fn($v)=>preg_match('/^[1-7]$/',trim($v))))));
sort($days);
if (!$days) $days=[1,2,3];
$isoMonday = (new DateTimeImmutable())->setISODate(2026,$sem,1);
$desde=$isoMonday->format('Y-m-d');
$hasta=$isoMonday->modify('+6 days')->format('Y-m-d');
$error=null;$result=null;$summary=[];$nonOk=[];$photos=[];
try {
    if (!isset($conexion) || !($conexion instanceof mysqli)) throw new RuntimeException('Conexión MariaDB no disponible');
    $motor=new RankingAtribucionService($conexion);
    $result=$motor->obtenerInstalacionesJerarquia($desde,$hasta,null,null,null,$days);
    $summary=$result['conciliacion_global']['lideres'] ?? [];
    $nonOk=$result['conciliacion_global']['estados_no_ok'] ?? [];
    $photos=$result['fotografias_hc'] ?? [];
} catch (Throwable $e) {
    error_log('[ranking_auditoria] '.$e->getMessage());
    $error='No se pudo completar la auditoría. Revisa el registro de errores PHP del servidor.';
}
$names=RankingAtribucionService::lideresStaging();
$selected=implode(',',$days);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TalIA · Auditoría motor Ranking</title><style>
body{font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:#f4f6fb;color:#1a2535;margin:0;padding:25px}main{max-width:1140px;margin:auto}h1{margin:0 0 3px}p{margin:8px 0 18px}.muted{color:#536174}.box{background:#fff;border:1px solid #e0e5ef;border-radius:13px;padding:18px;margin:15px 0;overflow:auto}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px;border-bottom:1px solid #e7ebf3;text-align:left;white-space:nowrap}th{background:#eef1f9}td.num{text-align:right;font-variant-numeric:tabular-nums}select,button{padding:9px 12px;border-radius:7px;border:1px solid #aeb9ca}button{background:#27346d;color:#fff;cursor:pointer}code{background:#eef1f9;padding:2px 4px} .warn{color:#a43e21}.ok{color:#167348} .small{font-size:12px}</style></head><body><main>
<h1>TalIA · Auditoría de atribución Ranking</h1><p class="muted">Motor v0.1 · Solo lectura · Sin modificaciones a Ranking, HC, HIC ni instalaciones</p>
<div class="box"><form method="get"><label>Semana 2026 <select name="semana"><option value="40" <?= $sem===40?'selected':'' ?>>40</option><option value="41" <?= $sem===41?'selected':'' ?>>41</option></select></label> &nbsp; <label>Días ISO (1=Lun, 7=Dom) <select name="dias"><option value="1,2,3" <?=$selected==='1,2,3'?'selected':''?>>Lunes a miércoles</option><option value="1,2,3,4" <?=$selected==='1,2,3,4'?'selected':''?>>Lunes a jueves</option><option value="1,2,3,4,5,6" <?=$selected==='1,2,3,4,5,6'?'selected':''?>>Lunes a sábado</option><option value="1,2,3,4,5,6,7" <?=$selected==='1,2,3,4,5,6,7'?'selected':''?>>Semana completa</option></select></label> &nbsp;<button type="submit">Auditar</button></form><p class="small muted">Ventana <?=eh($desde)?> — <?=eh($hasta)?>; únicamente días <?=eh($selected)?>. No comparar contra capturas con un filtro distinto.</p></div>
<?php if($error):?><div class="box warn"><?=eh($error)?></div><?php else:?>
<div class="box"><strong>Total de cuentas únicas del conjunto: <?=eh($result['conciliacion_global']['total_cuentas']??0)?></strong><p class="small muted">Es una conciliación del propio motor, NO una certificación contra Ranking ni de identidad HIC. Los grupos SIN_COACH y SIN_VENDEDOR mantienen las cuentas sin asignación.</p></div>
<div class="box"><h2>Resumen por líder</h2><table><thead><tr><th>Distrito</th><th>Líder (clave de reporte)</th><th>Cuentas</th><th>Suma por coach*</th><th>Suma por vendedor*</th><th>Sin coach</th><th>Sin vendedor</th><th>Estado interno</th></tr></thead><tbody>
<?php foreach($names as $lid=>$meta): $r=$summary[$lid]??[]; $total=(int)($r['total']??0);$coachSum=array_sum($r['coaches']??[]);$vendSum=array_sum($r['vendedores']??[]);?><tr><td><?=eh($meta['distrito'])?></td><td><?=eh($meta['nombre'])?></td><td class="num"><?=eh($total)?></td><td class="num"><?=eh($coachSum)?></td><td class="num"><?=eh($vendSum)?></td><td class="num"><?=eh($r['coaches']['SIN_COACH']??0)?></td><td class="num"><?=eh($r['vendedores']['SIN_VENDEDOR']??0)?></td><td class="<?=($total===$coachSum&&$total===$vendSum)?'ok':'warn'?>"><?=($total===$coachSum&&$total===$vendSum)?'CONSERVA':'DESCUADRE'?></td></tr><?php endforeach;?></tbody></table><p class="small muted">*Incluye las categorías no identificadas. Esta igualdad es una propiedad del agrupamiento del motor: no equivale a verificación de atribución con un tercero.</p></div>
<div class="box"><h2>Calidad de atribución</h2><?php if(!$nonOk):?><p class="ok">Sin estados no-OK reportados por el motor para esta selección.</p><?php else:?><table><thead><tr><th>Estado</th><th>Cuentas</th></tr></thead><tbody><?php foreach($nonOk as $code=>$count):?><tr><td><?=eh($code)?></td><td class="num"><?=eh($count)?></td></tr><?php endforeach;?></tbody></table><?php endif;?><p class="small muted">También pueden existir claves provisionales EVENT|... aunque el estado sea OK; no equivalen a identidad certificada. La integración HIC automática todavía está pendiente.</p></div>
<div class="box"><h2>Fotografías HC</h2><table><thead><tr><th>Semana consultada</th><th>Fotografía usada</th></tr></thead><tbody><?php foreach($photos as $key=>$photo):?><tr><td><?=eh($key)?></td><td><?=eh($photo?implode(' / ',$photo):'NO DISPONIBLE')?></td></tr><?php endforeach;?></tbody></table></div>
<?php endif;?><div class="box"><p><strong>Control:</strong> este reporte no muestra cuentas ni folios individuales, no escribe en base de datos y solo está habilitado para rol <code>admin</code>. Retíralo del servidor cuando finalice la auditoría. No lo uses como reemplazo de Ranking.</p></div>
</main></body></html>
