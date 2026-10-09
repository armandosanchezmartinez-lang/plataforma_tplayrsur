<?php
declare(strict_types=1);
if (!class_exists('mysqli')) { class mysqli {} }
require_once __DIR__.'/talia_jerarquia_consulta_service_v02.php';
function check2(bool $ok,string $txt):void { if (!$ok) throw new RuntimeException('FAIL '.$txt); echo "OK: $txt\n"; }
$m = new TaliaJerarquiaConsultaService(new RankingAtribucionService(new mysqli()));
$l1='MERIDA|PAREDES ROCHEL MARIA JOSE';
$l2='MERIDA|ESTRADA MEDINA MERCY GUADALUPE';
$filas=[
 ['cuenta'=>'A','lider_id'=>$l1,'coach_id'=>'CO1','vendedor_id'=>'V1','estado'=>'OK'],
 ['cuenta'=>'B','lider_id'=>$l1,'coach_id'=>'CO1','vendedor_id'=>'V2','estado'=>'OK'],
 ['cuenta'=>'C','lider_id'=>$l2,'coach_id'=>'CO2','vendedor_id'=>'V3','estado'=>'OK'],
 ['cuenta'=>'D','lider_id'=>$l2,'coach_id'=>null,'vendedor_id'=>'V4','estado'=>'SIN_COACH'],
 ['cuenta'=>'E','lider_id'=>$l2,'coach_id'=>'EVENT|X','vendedor_id'=>null,'estado'=>'OK'],
];
foreach(['REGION'=>1,'DISTRITO'=>1,'LIDER'=>2,'COACH'=>4,'VENDEDOR'=>5] as $nivel=>$n){
 $r=$m->agruparFilas($filas,['nivel'=>$nivel]);
 check2($r['conservacion'] && $r['total_instalaciones']===5 && count($r['nodos'])===$n,'Conservación y nodos '.$nivel);
}
$r=$m->agruparFilas($filas,['nivel'=>'LIDER','id'=>$l1]);
check2($r['total_instalaciones']===2,'Filtro líder exacto');
$r=$m->agruparFilas($filas,['nivel'=>'COACH','id_lider'=>$l1,'id_coach'=>'CO1']);
check2($r['total_instalaciones']===2,'Filtro líder + coach');
$r=$m->agruparFilas($filas,['nivel'=>'VENDEDOR','id_lider'=>$l1,'id_coach'=>'CO1','id_vendedor'=>'V1','detalle_cuentas'=>true]);
check2($r['total_instalaciones']===1 && count($r['nodos'][0]['cuentas'])===1,'Filtro triple y detalle opcional');
$r=$m->agruparFilas($filas,['nivel'=>'COACH','id_lider'=>$l2]);
check2(isset($r['estados']['SIN_COACH']) && $r['total_instalaciones']===3,'No se pierde cuenta sin coach');
check2(array_sum($r['alertas'])===2,'Identidades provisionales y conflictos visibles');
$r=$m->agruparFilas($filas,['nivel'=>'REGION']);
check2(!isset($r['nodos'][0]['cuentas']),'No divulgar detalle por defecto');
try { $m->agruparFilas([$filas[0],$filas[0]],['nivel'=>'REGION']); throw new RuntimeException('No detectó duplicado'); }
catch(LogicException $e){check2(true,'Detiene datos duplicados en la entrada');}
try { $m->agruparFilas($filas,['nivel'=>'NO_EXISTE']); throw new RuntimeException('No validó nivel'); }
catch(InvalidArgumentException $e){check2(true,'Rechaza nivel desconocido');}
echo "PRUEBAS v0.2 COMPLETADAS\n";
