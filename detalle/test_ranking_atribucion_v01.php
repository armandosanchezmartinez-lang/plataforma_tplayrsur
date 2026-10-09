<?php
declare(strict_types=1);
if (!class_exists('mysqli')) { class mysqli {} }
require_once __DIR__.'/ranking_atribucion_service.php';
function check(bool $cond,string $message):void {if(!$cond)throw new RuntimeException('FALLÓ: '.$message); echo "OK: $message\n";}
$db = new mysqli(); // No se conecta ni consulta la BD durante estas pruebas.
$service = new RankingAtribucionService($db);
$l='MERIDA|ESTRADA MEDINA MERCY GUADALUPE';
$m='MERIDA|PAREDES ROCHEL MARIA JOSE';
$hc=['fotos'=>['2026-40'=>[2026,40],'2026-41'=>[2026,40]],'datos'=>['2026-40'=>[
 ['distrito'=>'MERIDA','nombre_colaborador'=>'RENDON ORTIZ JONATHAN','id_posicion'=>'10024990','posicion_lr'=>'1739397','numero_talento_gs'=>'0','nombre_linea_reporte'=>'ESTRADA MEDINA MERCY GUADALUPE'],
 ['distrito'=>'MERIDA','nombre_colaborador'=>'MATU YAH ERIK YAEL','id_posicion'=>'990','posicion_lr'=>'1','numero_talento_gs'=>'0','nombre_linea_reporte'=>'PAREDES ROCHEL MARIA JOSE'],
 ['distrito'=>'MERIDA','nombre_colaborador'=>'VENTA TEST','id_posicion'=>'999','posicion_lr'=>'10024990','numero_talento_gs'=>'17120370','nombre_linea_reporte'=>'RENDON ORTIZ JONATHAN']
]]];
$eventos=[
 ['cuenta'=>'A','fecha'=>'2026-09-28','lider_id'=>$l,'coach'=>'RENDON ORTIZ JONATHAN','folio_empleado'=>'17120370'],
 ['cuenta'=>'A','fecha'=>'2026-09-28','lider_id'=>$l,'coach'=>'RENDON ORTIZ JONATHAN','folio_empleado'=>'17120370'],
 ['cuenta'=>'B','fecha'=>'2026-09-30','lider_id'=>$m,'coach'=>'ERIK YAEL MATU YAH','folio_empleado'=>'17102672'],
 ['cuenta'=>'C','fecha'=>'2026-10-05','lider_id'=>$l,'coach'=>'','folio_empleado'=>'17120370'],
 ['cuenta'=>'D','fecha'=>'2026-10-05','lider_id'=>$l,'coach'=>'','folio_empleado'=>'NO-EN-HC'],
 ['cuenta'=>'E','fecha'=>'2026-10-05','lider_id'=>$l,'coach'=>'CANO CANCHE FRANCISCO AUGUSTO','folio_empleado'=>'x'],
 ['cuenta'=>'E','fecha'=>'2026-10-05','lider_id'=>$l,'coach'=>'RENDON ORTIZ JONATHAN','folio_empleado'=>'x']
];
$rows=$service->resolverFilas($eventos,$hc);

check(count($rows)===5,'Una cuenta A duplicada en origen queda una sola vez');
$a=array_column($rows,null,'cuenta');
check($a['A']['coach_id']==='10024990','Coincidencia exacta de coach HC por identidad');
check($a['B']['coach_id']==='990','Nombre del evento con orden invertido resuelve el mismo coach');
check($a['C']['fuente']==='HC_HIC' && $a['C']['coach_id']==='10024990','HC fallback solo cuando coach de evento está vacío');
check($a['D']['estado']==='SIN_COACH','Cuenta real no desaparece si el coach falta');
check($a['E']['estado']==='CONFLICTO_COACH_EVENTO','Dos coaches de evento generan conflicto, nunca asignación arbitraria');
$rep=RankingAtribucionService::conciliar($rows);
check($rep['total_cuentas']===5,'Total global único');
foreach($rep['lideres'] as $lid=>$info)check($info['coincide_coaches'] && $info['coincide_vendedores'],'Conservación jerárquica '.$lid);
check(count(RankingAtribucionService::filtrar($rows,$l,'10024990'))===2,'Filtro Líder + Coach reutiliza mismo universo');
check(count(RankingAtribucionService::filtrar($rows,$l,'10024990','17120370'))===2,'Filtro Líder + Coach + Vendedor');
echo "PRUEBAS COMPLETADAS\n";
