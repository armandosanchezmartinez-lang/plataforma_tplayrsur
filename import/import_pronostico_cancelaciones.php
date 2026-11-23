<?php
/**
 * TotalXpedient / TalIA | Pronóstico de Cancelaciones diferencial v3.0 STAGING
 * Carpeta propuesta: /plataforma/import/import_pronostico_cancelaciones_v3.php
 * Requiere 01_esquema_pc3_STAGING.sql. NO modifica vistas/tablas legacy.
 * BASE: inserta una ficha por cuenta en pc3_registros por lotes (sin segunda copia nacional).
 * DELTA: manifiesto -> detalles diferentes -> eventos -> publicación transaccional -> limpieza.
 * IMPORTANTE: no confirma involuntarias oficiales; registra CIERRE_CANDIDATO sujeto a Qlik.
 */
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit','512M');
session_start();
$user=(string)($_SESSION['usuario']??$_SESSION['username']??'');
$role=strtolower(trim((string)($_SESSION['rol']??'')));
if ($user==='' || !in_array($role,['admin','gerente_regional','director_regional'],true)) {
    http_response_code(403); exit('Acceso restringido: iniciar sesión con perfil regional autorizado.');
}
if (empty($_SESSION['csrf_pc3'])) $_SESSION['csrf_pc3']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['csrf_pc3'];
session_write_close();
require_once $_SERVER['DOCUMENT_ROOT'].'/plataforma/includes/conexion.php';
mysqli_set_charset($conexion,'utf8mb4');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

const PC3_BATCH=1500;
const PC3_MAX=1000000;
// XLSB original contiene algunas CUENTA literales con formato 1.xxxxxxx.
// Se preservan como identificadores de texto, no se reinterpretan como numeros.
const PC3_CUENTA_PATTERN='/^(?:[0-9]{1,100}|1\.[0-9]{7})$/D';
const PC3_COLS=['cuenta','plaza','distrito','cluster','region','estatus_cta','atraso','nse',
 'fecha_activacion','fecha_cancelacion','tipo_cancelacion','ciclo','fecha_pronostico',
 'semana_pronostico','reloj','saldo_serv','servicio_terceros','servicios_totalplay',
 'nombre_plan','sub_canal','tipo_pago','fecha_final','semana_final','renta','subestatus'];
const PC3_VOLATILES=[6,14,15,16,17];
const PC3_EVENT_INDICES=[2,4,5,9,10,12,13,21,22,23,24];
function pc3_out(array $v,int $status=200): never {
 http_response_code($status);header('Content-Type: application/json; charset=utf-8');
 echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function pc3_payload(): array {
 $v=json_decode((string)file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
 if(!is_array($v))throw new RuntimeException('JSON inválido.'); return $v;
}
function pc3_sql(mysqli $db,string $sql): mysqli_result|bool {
 $r=mysqli_query($db,$sql);if($r===false)throw new RuntimeException(mysqli_error($db));return $r;
}
function pc3_one(mysqli $db,string $sql):array {
 $r=pc3_sql($db,$sql);return $r instanceof mysqli_result?(mysqli_fetch_assoc($r)?:[]):[];
}
function pc3_stmt(mysqli $db,string $sql):mysqli_stmt {
 $s=mysqli_prepare($db,$sql);if(!$s)throw new RuntimeException(mysqli_error($db));return $s;
}
function pc3_bind(mysqli_stmt $s,string $types,array &$values):void {
 $refs=[];foreach($values as &$v)$refs[]=&$v;unset($v);
 if(!mysqli_stmt_bind_param($s,$types,...$refs))throw new RuntimeException(mysqli_stmt_error($s));
}
function pc3_exec(mysqli_stmt $s):void {
 if(!mysqli_stmt_execute($s))throw new RuntimeException(mysqli_stmt_error($s));
}
function pc3_get(mysqli $db,int $id,bool $lock=false):array {
 $s=pc3_stmt($db,'SELECT * FROM pc3_cargas WHERE id=? LIMIT 1'.($lock?' FOR UPDATE':''));
 $v=[$id];pc3_bind($s,'i',$v);pc3_exec($s);
 $row=mysqli_fetch_assoc(mysqli_stmt_get_result($s))?:[];mysqli_stmt_close($s);
 if(!$row)throw new RuntimeException('Carga desconocida.');return $row;
}
function pc3_count(mysqli $db,string $table,int $id):int {
 if(!in_array($table,['pc3_manifest','pc3_detalle'],true))throw new RuntimeException('Tabla no permitida.');
 $s=pc3_stmt($db,"SELECT COUNT(*) AS n FROM `$table` WHERE carga_id=?");
 $v=[$id];pc3_bind($s,'i',$v);pc3_exec($s);
 $n=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['n']??0);mysqli_stmt_close($s);return $n;
}
function pc3_fecha_archivo(string $name,int $year):string {
 $a=basename(str_replace('\\','/',$name));
 if(!preg_match('/^pron[oó]stico\s+cancelaciones\s+s\d+\s+(\d{1,2})\s+([a-záéíóúü]+).*\.xlsb$/iu',$a,$m))
     throw new RuntimeException('Archivo esperado: Pronóstico cancelaciones S47 17 NOVIEMBRE_.xlsb');
 $months=['ENERO'=>1,'FEBRERO'=>2,'MARZO'=>3,'ABRIL'=>4,'MAYO'=>5,'JUNIO'=>6,'JULIO'=>7,
  'AGOSTO'=>8,'SEPTIEMBRE'=>9,'OCTUBRE'=>10,'NOVIEMBRE'=>11,'DICIEMBRE'=>12];
 $month=strtoupper(strtr($m[2],['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']));
 if(!isset($months[$month]))throw new RuntimeException('Mes inválido en nombre de archivo.');
 $y=$year;$mo=$months[$month];$d=(int)$m[1];
 // El XLSB no incluye el año en el nombre: exigir el año declarado explícitamente.
 if($y<2025||$y>2100)throw new RuntimeException('Año de corte fuera de rango.');
 if(!checkdate($mo,$d,$y))throw new RuntimeException('Fecha de corte inválida.');
 return sprintf('%04d-%02d-%02d',$y,$mo,$d);
}
function pc3_validate(array $fields):void {
 if(count($fields)!==25)throw new RuntimeException('Se requieren exactamente las 25 columnas originales.');
 foreach($fields as $i=>$v){
  if($v!==null&&!is_string($v))throw new RuntimeException("El campo $i debe ser string o null.");
  if($v!==null&&mb_strlen($v,'UTF-8')>320)throw new RuntimeException("Campo $i demasiado largo.");
 }
 if(!is_string($fields[0])||!preg_match(PC3_CUENTA_PATTERN,$fields[0]))throw new RuntimeException('CUENTA debe tener formato dígitos o literal 1.xxxxxxx del XLSB.');
 foreach([8,9,12,21] as $i){
  $v=$fields[$i];if($v===null)continue;
  if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$v,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))
      throw new RuntimeException("Fecha incorrecta en columna $i.");
 }
 foreach([6,11,13,22] as $i) if($fields[$i]!==null&&!preg_match('/^-?\d{1,9}$/D',$fields[$i]))throw new RuntimeException("Entero incorrecto en columna $i.");
 foreach([15,16,17,23] as $i) if($fields[$i]!==null&&!preg_match('/^-?\d{1,12}\.\d{2}$/D',$fields[$i]))throw new RuntimeException("Importe incorrecto en columna $i.");
}
function pc3_hashes(array $fields):array {
 $business=[];foreach($fields as $i=>$v)if(!in_array($i,PC3_VOLATILES,true))$business[]=$v;
 $events=[];foreach(PC3_EVENT_INDICES as $i)$events[]=$fields[$i];
 $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR;
 return [hash('sha256',json_encode($business,$flags)),hash('sha256',json_encode($events,$flags))];
}
function pc3_guard_date(mysqli $db,string $date,int $exclude=0):void {
 $r=pc3_one($db,"SELECT fecha_corte FROM pc3_cargas WHERE estado='OK' AND id<>".(int)$exclude." ORDER BY fecha_corte DESC,id DESC LIMIT 1");
 if($r&&$date<=$r['fecha_corte'])throw new RuntimeException('Corte no posterior al último publicado ('.$r['fecha_corte'].').');
}
function pc3_confirm_manifest(mysqli $db,int $id,int $expected):void {
 $count=pc3_count($db,'pc3_manifest',$id);
 if($count!==$expected)throw new RuntimeException("Manifiesto incompleto: $count de $expected.");
}
function pc3_update_stats(mysqli $db,int $id,array $stats):void {
 $s=pc3_stmt($db,"UPDATE pc3_cargas SET estado='OK',altas=?,cambios=?,cierres_candidatos=?,salidas=?,reapariciones=?,finalizado_en=NOW(),mensaje='Corte diferencial publicado' WHERE id=?");
 $v=[(int)$stats['altas'],(int)$stats['cambios'],(int)$stats['cierres'],(int)$stats['salidas'],(int)$stats['reap'], $id];
 pc3_bind($s,'iiiiii',$v);pc3_exec($s);mysqli_stmt_close($s);
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_GET['action'])){
 $inTransaction=false;$haveLock=false;
 try{
  if(!hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))throw new RuntimeException('CSRF inválido. Recarga la página.');
  $action=(string)$_GET['action'];$p=pc3_payload();
  if($action==='init'){
   $name=basename(str_replace('\\','/',(string)($p['archivo']??'')));
   $year=(int)($p['anio']??0);
   $date=pc3_fecha_archivo($name,$year);
   $hash=strtolower((string)($p['hash']??''));$total=(int)($p['total']??0);
   if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||$total<1||$total>PC3_MAX)throw new RuntimeException('Hash/total inválido.');
   $s=pc3_stmt($conexion,'SELECT * FROM pc3_cargas WHERE hash_archivo=? LIMIT 1');
   $v=[$hash];pc3_bind($s,'s',$v);pc3_exec($s);
   $old=mysqli_fetch_assoc(mysqli_stmt_get_result($s))?:[];mysqli_stmt_close($s);
   if($old){
    if($old['fecha_corte']!==$date||(int)$old['registros_esperados']!==$total)throw new RuntimeException('El hash coincide con otro corte o conteo.');
    if($old['estado']==='OK')pc3_out(['ok'=>true,'repetido'=>true,'carga_id'=>(int)$old['id'],'mensaje'=>'Archivo ya publicado.']);
    pc3_guard_date($conexion,$date,(int)$old['id']);
    $id=(int)$old['id'];
    $done=$old['modo']==='BASE'
      ?(int)(pc3_one($conexion,'SELECT COUNT(*) n FROM pc3_registros WHERE primera_carga_id='.$id)['n']??0)
      :pc3_count($conexion,'pc3_manifest',$id);
    pc3_out(['ok'=>true,'reanudando'=>true,'modo'=>$old['modo'],'carga_id'=>$id,'resume_from'=>$done]);
   }
   pc3_guard_date($conexion,$date);
   $active=pc3_one($conexion,"SELECT id,archivo_origen FROM pc3_cargas WHERE estado IN ('PROCESANDO','ERROR') ORDER BY id DESC LIMIT 1");
   if($active)throw new RuntimeException('Existe otra carga pendiente: #'.$active['id'].' ('.$active['archivo_origen'].'). Termínala antes.');
   $same=pc3_one($conexion,"SELECT id FROM pc3_cargas WHERE fecha_corte='".mysqli_real_escape_string($conexion,$date)."' LIMIT 1");
   if($same)throw new RuntimeException('Ya existe un archivo para esa fecha; no se admite reemplazarlo sin conciliación.');
   $hasOk=pc3_one($conexion,"SELECT COUNT(*) n FROM pc3_cargas WHERE estado='OK'");
   $mode=(int)($hasOk['n']??0)===0?'BASE':'DELTA';
   if($mode==='BASE'){
    $notEmpty=pc3_one($conexion,'SELECT id FROM pc3_registros LIMIT 1');
    if($notEmpty)throw new RuntimeException('La tabla actual no está vacía. Revisar una carga BASE previa antes de iniciar.');
   }
   $s=pc3_stmt($conexion,"INSERT INTO pc3_cargas(archivo_origen,hash_archivo,fecha_corte,registros_esperados,modo,usuario,mensaje) VALUES(?,?,?,?,?,?,'Iniciada')");
   $v=[$name,$hash,$date,$total,$mode,$user];pc3_bind($s,'sssiss',$v);pc3_exec($s);
   $id=(int)mysqli_insert_id($conexion);mysqli_stmt_close($s);
   pc3_out(['ok'=>true,'carga_id'=>$id,'modo'=>$mode,'resume_from'=>0,'mensaje'=>'Corte iniciado en modo '.$mode]);
  }
  $id=(int)($p['carga_id']??0);if($id<1)throw new RuntimeException('ID inválido.');
  $load=pc3_get($conexion,$id);
  if($action==='cleanup'){
   if($load['estado']!=='OK')throw new RuntimeException('Limpieza permitida solo después de publicar.');
   $id=(int)$id;
   pc3_sql($conexion,"DELETE FROM pc3_detalle WHERE carga_id=$id LIMIT 1500");
   $x=mysqli_affected_rows($conexion);
   if($x===0)pc3_sql($conexion,"DELETE FROM pc3_manifest WHERE carga_id=$id LIMIT 1500");
   $remain=pc3_count($conexion,'pc3_detalle',$id)+pc3_count($conexion,'pc3_manifest',$id);
   pc3_out(['ok'=>true,'eliminados'=>$x,'pendientes'=>$remain]);
  }
  if($action==='mark_error'){
   if($load['estado']!=='OK'){
    $msg=mb_substr((string)($p['mensaje']??'Carga interrumpida'),0,600);
    $s=pc3_stmt($conexion,"UPDATE pc3_cargas SET estado='ERROR',mensaje=? WHERE id=? AND estado<>'OK'");
    $v=[$msg,$id];pc3_bind($s,'si',$v);pc3_exec($s);mysqli_stmt_close($s);
   }pc3_out(['ok'=>true]);
  }
  if($load['estado']==='OK')throw new RuntimeException('La carga ya está publicada.');
  pc3_guard_date($conexion,$load['fecha_corte'],$id);
  if($action==='base_batch'){
   if($load['modo']!=='BASE')throw new RuntimeException('No es carga BASE.');
   $offset=(int)($p['offset']??-1);$rows=$p['rows']??null;
   if(!is_array($rows)||count($rows)<1||count($rows)>PC3_BATCH)throw new RuntimeException('Lote BASE inválido.');
   $done=(int)(pc3_one($conexion,'SELECT COUNT(*) n FROM pc3_registros WHERE primera_carga_id='.$id)['n']??0);
   if($offset<$done&&$offset+count($rows)<=$done)pc3_out(['ok'=>true,'total'=>$done,'repetido'=>true]);
   if($offset!==$done||$done+count($rows)>(int)$load['registros_esperados'])throw new RuntimeException('Desfase BASE.');
   $cols=array_map(static fn($s)=>'`'.$s.'`',PC3_COLS);
   $sql='INSERT INTO pc3_registros('.implode(',',$cols).',hash_datos,hash_evento,presente,primera_carga_id,ultima_carga_id) VALUES ('.implode(',',array_fill(0,25,'?')).',UNHEX(?),UNHEX(?),1,?,?)';
   pc3_sql($conexion,'START TRANSACTION');$inTransaction=true;
   $s=pc3_stmt($conexion,$sql);
   foreach($rows as $a){
    if(!is_array($a))throw new RuntimeException('Fila BASE inválida.');pc3_validate($a);
    [$hd,$he]=pc3_hashes($a);$v=array_merge($a,[$hd,$he,$id,$id]);
    pc3_bind($s,str_repeat('s',27).'ii',$v);pc3_exec($s);
   }
   mysqli_stmt_close($s);
   $next=$done+count($rows);
   pc3_sql($conexion,"UPDATE pc3_cargas SET registros_importados=$next WHERE id=$id");
   pc3_sql($conexion,'COMMIT');$inTransaction=false;
   pc3_out(['ok'=>true,'total'=>$next]);
  }
  if($action==='base_finish'){
   if($load['modo']!=='BASE')throw new RuntimeException('Modo incorrecto.');
   $count=(int)(pc3_one($conexion,'SELECT COUNT(*) n FROM pc3_registros WHERE primera_carga_id='.$id)['n']??0);
   if($count!==(int)$load['registros_esperados'])throw new RuntimeException('La base no está completa.');
   $s=pc3_stmt($conexion,"UPDATE pc3_cargas SET estado='OK',registros_importados=?,altas=?,finalizado_en=NOW(),mensaje='Corte inicial publicado' WHERE id=?");
   $v=[$count,$count,$id];pc3_bind($s,'iii',$v);pc3_exec($s);mysqli_stmt_close($s);
   pc3_out(['ok'=>true,'total'=>$count,'mensaje'=>'BASE aprobada sin tocar vistas legadas.']);
  }
  if($load['modo']!=='DELTA')throw new RuntimeException('La acción requiere corte DELTA.');
  if($action==='manifest_batch'){
   $rows=$p['rows']??null;$offset=(int)($p['offset']??-1);
   if(!is_array($rows)||count($rows)<1||count($rows)>PC3_BATCH)throw new RuntimeException('Lote de manifiesto inválido.');
   $done=pc3_count($conexion,'pc3_manifest',$id);
   if($offset<$done&&$offset+count($rows)<=$done)pc3_out(['ok'=>true,'total'=>$done,'repetido'=>true]);
   if($offset!==$done||$done+count($rows)>(int)$load['registros_esperados'])throw new RuntimeException('Desfase manifiesto.');
   pc3_sql($conexion,'START TRANSACTION');$inTransaction=true;
   $s=pc3_stmt($conexion,'INSERT INTO pc3_manifest(carga_id,fila_excel,cuenta,hash_datos,hash_evento,atraso,saldo_serv,servicio_terceros,servicios_totalplay,reloj) VALUES (?,?,?,UNHEX(?),UNHEX(?),?,?,?,?,?)');
   foreach($rows as $j=>$r){
    if(!is_array($r)||count($r)!==9)throw new RuntimeException('Registro de manifiesto incorrecto.');
    [$fila,$cuenta,$hd,$he,$at,$saldo,$terc,$total,$reloj]=$r;
    if((int)$fila!==$offset+$j+1||!preg_match(PC3_CUENTA_PATTERN,(string)$cuenta)||!preg_match('/^[a-f0-9]{64}$/D',(string)$hd)||!preg_match('/^[a-f0-9]{64}$/D',(string)$he))throw new RuntimeException('Fila/hash/cuenta inválido: '.($offset+$j+1));
    $v=[$id,(int)$fila,(string)$cuenta,(string)$hd,(string)$he,$at,$saldo,$terc,$total,$reloj];
    pc3_bind($s,'iissssssss',$v);pc3_exec($s);
   }mysqli_stmt_close($s);
   pc3_sql($conexion,'COMMIT');$inTransaction=false;
   pc3_out(['ok'=>true,'total'=>$done+count($rows)]);
  }
  if($action==='manifest_finish'){
   pc3_confirm_manifest($conexion,$id,(int)$load['registros_esperados']);
   $missing=pc3_one($conexion,"SELECT COUNT(*) n FROM pc3_manifest m LEFT JOIN pc3_registros r ON r.cuenta=m.cuenta WHERE m.carga_id=$id AND (r.id IS NULL OR r.hash_datos<>m.hash_datos)");
   pc3_out(['ok'=>true,'detalles_necesarios'=>(int)($missing['n']??0)]);
  }
  if($action==='need_details'){
   pc3_confirm_manifest($conexion,$id,(int)$load['registros_esperados']);
   $after=max(0,(int)($p['after']??0));$limit=min(PC3_BATCH,max(1,(int)($p['limit']??PC3_BATCH)));
   $s=pc3_stmt($conexion,"SELECT m.fila_excel FROM pc3_manifest m
      LEFT JOIN pc3_registros r ON r.cuenta=m.cuenta
      LEFT JOIN pc3_detalle d ON d.carga_id=m.carga_id AND d.cuenta=m.cuenta
      WHERE m.carga_id=? AND m.fila_excel>? AND (r.id IS NULL OR r.hash_datos<>m.hash_datos) AND d.cuenta IS NULL
      ORDER BY m.fila_excel LIMIT ?");
   $v=[$id,$after,$limit];pc3_bind($s,'iii',$v);pc3_exec($s);
   $res=mysqli_stmt_get_result($s);$inds=[];while($x=mysqli_fetch_assoc($res))$inds[]=(int)$x['fila_excel'];
   mysqli_stmt_close($s);pc3_out(['ok'=>true,'indices'=>$inds]);
  }
  if($action==='detail_batch'){
   $rows=$p['rows']??null;
   if(!is_array($rows)||count($rows)<1||count($rows)>PC3_BATCH)throw new RuntimeException('Lote detalle inválido.');
   pc3_sql($conexion,'START TRANSACTION');$inTransaction=true;
   $get=pc3_stmt($conexion,"SELECT LOWER(HEX(m.hash_datos)) hd,LOWER(HEX(m.hash_evento)) he,m.fila_excel,r.id existe,LOWER(HEX(r.hash_datos)) actual
        FROM pc3_manifest m LEFT JOIN pc3_registros r ON r.cuenta=m.cuenta
        WHERE m.carga_id=? AND m.cuenta=? LIMIT 1");
   $put=pc3_stmt($conexion,'INSERT INTO pc3_detalle(carga_id,cuenta,datos_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE datos_json=VALUES(datos_json)');
   $added=0;
   foreach($rows as $item){
    if(!is_array($item)||count($item)!==2||!is_array($item[1]))throw new RuntimeException('Detalle inválido.');
    [$fila,$fields]=$item;pc3_validate($fields);
    [$hd,$he]=pc3_hashes($fields);$account=(string)$fields[0];
    $v=[$id,$account];pc3_bind($get,'is',$v);pc3_exec($get);
    $m=mysqli_fetch_assoc(mysqli_stmt_get_result($get))?:[];
    if(!$m||(int)$m['fila_excel']!==(int)$fila||$m['hd']!==$hd||$m['he']!==$he)throw new RuntimeException('Detalle no coincide con hash/manifiesto. Fila '.(int)$fila);
    if($m['existe']!==null&&$m['actual']===$hd)continue;
    $json=json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    $v=[$id,$account,$json];pc3_bind($put,'iss',$v);pc3_exec($put);$added++;
   }
   mysqli_stmt_close($get);mysqli_stmt_close($put);
   pc3_sql($conexion,'COMMIT');$inTransaction=false;
   pc3_out(['ok'=>true,'guardados'=>$added]);
  }
  if($action==='publish'){
   if((int)(pc3_one($conexion,"SELECT GET_LOCK('totalxpedient_pc3_publicar',5) l")['l']??0)!==1)throw new RuntimeException('Otra publicación PC3 en curso.');
   $haveLock=true;
   pc3_sql($conexion,'START TRANSACTION');$inTransaction=true;
   $load=pc3_get($conexion,$id,true);
   if(!in_array($load['estado'],['PROCESANDO','ERROR'],true))throw new RuntimeException('Corte ya publicado.');
   pc3_guard_date($conexion,$load['fecha_corte'],$id);
   pc3_confirm_manifest($conexion,$id,(int)$load['registros_esperados']);
   $missing=pc3_one($conexion,"SELECT COUNT(*) n FROM pc3_manifest m
       LEFT JOIN pc3_registros r ON r.cuenta=m.cuenta
       LEFT JOIN pc3_detalle d ON d.carga_id=m.carga_id AND d.cuenta=m.cuenta
       WHERE m.carga_id=$id AND (r.id IS NULL OR r.hash_datos<>m.hash_datos) AND d.cuenta IS NULL");
   if((int)($missing['n']??0)>0)throw new RuntimeException('Faltan '.(int)$missing['n'].' detalles. No se publica.');
   $date=mysqli_real_escape_string($conexion,$load['fecha_corte']);
   $stats=pc3_one($conexion,"SELECT
     COALESCE(SUM(r.id IS NULL),0) altas,
     COALESCE(SUM(r.id IS NOT NULL AND r.presente=0),0) reap,
     COALESCE(SUM(r.id IS NOT NULL AND r.presente=1 AND r.hash_evento<>m.hash_evento),0) cambios
     FROM pc3_manifest m LEFT JOIN pc3_registros r ON r.cuenta=m.cuenta WHERE m.carga_id=$id");
   $sal=pc3_one($conexion,"SELECT COUNT(*) n FROM pc3_registros r LEFT JOIN pc3_manifest m
      ON m.carga_id=$id AND m.cuenta=r.cuenta WHERE r.presente=1 AND m.cuenta IS NULL");
   $stats['salidas']=(int)($sal['n']??0);
   // Primero guardar eventos de modificaciones antes de sobrescribir el estado anterior.
   $js=static fn(int $i):string=>"NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.datos_json,'\$[$i]')),'null')";
   $newStatus=$js(5);$newSub=$js(24);$newDate=$js(21);
   $candidate="(r.presente=1 AND UPPER(TRIM(COALESCE(r.estatus_cta,''))) NOT IN ('CERRADA','CANCELADA INVOLUNTARIA')
               AND UPPER(TRIM(COALESCE($newStatus,'')))='CERRADA'
               AND UPPER(TRIM(COALESCE($newSub,''))) IN ('ORGÁNICO','ORGANICO')
               AND $newDate IS NOT NULL)";
   pc3_sql($conexion,"INSERT INTO pc3_movimientos
    (carga_id,cuenta,fecha_corte,fecha_evento,tipo_movimiento,candidato_involuntaria,distrito,region,
     estatus_anterior,estatus_nuevo,subestatus_nuevo,fecha_final_anterior,fecha_final_nueva,detalle_antes,detalle_despues)
    SELECT $id,m.cuenta,'$date',COALESCE($newDate,r.fecha_final),
     CASE WHEN r.presente=0 THEN 'REAPARICION' WHEN $candidate THEN 'CIERRE_CANDIDATO' ELSE 'CAMBIO' END,
     IF($candidate,1,0),COALESCE(".$js(2).",r.distrito),COALESCE(".$js(4).",r.region),
     r.estatus_cta,COALESCE($newStatus,r.estatus_cta),COALESCE($newSub,r.subestatus),
     r.fecha_final,COALESCE($newDate,r.fecha_final),
     JSON_OBJECT('estatus',r.estatus_cta,'fecha_final',r.fecha_final,'subestatus',r.subestatus,'distrito',r.distrito),
     JSON_OBJECT('estatus',$newStatus,'fecha_final',$newDate,'subestatus',$newSub,'distrito',".$js(2).")
    FROM pc3_manifest m JOIN pc3_registros r ON r.cuenta=m.cuenta
    LEFT JOIN pc3_detalle d ON d.carga_id=m.carga_id AND d.cuenta=m.cuenta
    WHERE m.carga_id=$id AND (r.presente=0 OR r.hash_evento<>m.hash_evento)");
   pc3_sql($conexion,"INSERT INTO pc3_movimientos
    (carga_id,cuenta,fecha_corte,fecha_evento,tipo_movimiento,candidato_involuntaria,distrito,region,
     estatus_anterior,estatus_nuevo,subestatus_nuevo,fecha_final_anterior,fecha_final_nueva,detalle_antes)
    SELECT $id,r.cuenta,'$date',NULL,'SALIDA',0,r.distrito,r.region,
      r.estatus_cta,NULL,NULL,r.fecha_final,NULL,
      JSON_OBJECT('estatus',r.estatus_cta,'fecha_final',r.fecha_final,'subestatus',r.subestatus)
    FROM pc3_registros r LEFT JOIN pc3_manifest m ON m.carga_id=$id AND m.cuenta=r.cuenta
    WHERE r.presente=1 AND m.cuenta IS NULL");
   // Actualizar la ficha COMPLETA solamente para cuentas cuyo hash de negocio cambió.
   $sets=[];foreach(PC3_COLS as $i=>$c)$sets[]='r.`'.$c.'`='.$js($i);
   $sets[]='r.hash_datos=m.hash_datos';$sets[]='r.hash_evento=m.hash_evento';
   $sets[]='r.ultima_carga_id='.$id;$sets[]='r.importado_en=NOW()';
   pc3_sql($conexion,"UPDATE pc3_registros r JOIN pc3_manifest m ON m.cuenta=r.cuenta AND m.carga_id=$id
      JOIN pc3_detalle d ON d.carga_id=m.carga_id AND d.cuenta=m.cuenta
      SET ".implode(',',$sets)." WHERE r.hash_datos<>m.hash_datos");
   // Altas genuinas, una sola fila por CUENTA.
   $fields=array_map(static fn($x)=>'`'.$x.'`',PC3_COLS);
   $vals=[];foreach(PC3_COLS as $i=>$c)$vals[]=$js($i);
   pc3_sql($conexion,"INSERT INTO pc3_registros(".implode(',',$fields).",hash_datos,hash_evento,presente,primera_carga_id,ultima_carga_id)
      SELECT ".implode(',',$vals).",m.hash_datos,m.hash_evento,1,$id,$id
      FROM pc3_manifest m JOIN pc3_detalle d ON d.carga_id=m.carga_id AND d.cuenta=m.cuenta
      LEFT JOIN pc3_registros r ON r.cuenta=m.cuenta WHERE m.carga_id=$id AND r.id IS NULL");
   pc3_sql($conexion,"INSERT INTO pc3_movimientos
    (carga_id,cuenta,fecha_corte,fecha_evento,tipo_movimiento,distrito,region,estatus_nuevo,subestatus_nuevo,fecha_final_nueva,detalle_despues)
     SELECT $id,r.cuenta,'$date',NULL,'ALTA',r.distrito,r.region,r.estatus_cta,r.subestatus,r.fecha_final,
       JSON_OBJECT('estatus',r.estatus_cta,'fecha_final',r.fecha_final,'subestatus',r.subestatus)
     FROM pc3_registros r WHERE r.primera_carga_id=$id");
   // Sin reescribir todas las cuentas estables: solo sincronizar campos volátiles que variaron.
   pc3_sql($conexion,"UPDATE pc3_registros r JOIN pc3_manifest m ON m.cuenta=r.cuenta AND m.carga_id=$id
     SET r.atraso=m.atraso,r.reloj=m.reloj,r.saldo_serv=m.saldo_serv,
         r.servicio_terceros=m.servicio_terceros,r.servicios_totalplay=m.servicios_totalplay
     WHERE NOT (r.atraso<=>m.atraso) OR NOT (r.reloj<=>m.reloj)
       OR NOT (r.saldo_serv<=>m.saldo_serv) OR NOT (r.servicio_terceros<=>m.servicio_terceros)
       OR NOT (r.servicios_totalplay<=>m.servicios_totalplay)");
   pc3_sql($conexion,"UPDATE pc3_registros r JOIN pc3_manifest m ON m.cuenta=r.cuenta AND m.carga_id=$id
     SET r.presente=1,r.ultima_carga_id=$id WHERE r.presente=0");
   pc3_sql($conexion,"UPDATE pc3_registros r LEFT JOIN pc3_manifest m ON m.carga_id=$id AND m.cuenta=r.cuenta
     SET r.presente=0,r.ultima_carga_id=$id WHERE r.presente=1 AND m.cuenta IS NULL");
   $final=pc3_one($conexion,'SELECT COUNT(*) n FROM pc3_registros WHERE presente=1');
   if((int)($final['n']??0)!==(int)$load['registros_esperados'])throw new RuntimeException('Validación cruzada falló: corte final no coincide con XLSB.');
   $s=pc3_stmt($conexion,"UPDATE pc3_cargas SET registros_importados=?,mensaje='Corte validado' WHERE id=?");
   $v=[(int)$load['registros_esperados'],$id];pc3_bind($s,'ii',$v);pc3_exec($s);mysqli_stmt_close($s);
   $cand=pc3_one($conexion,"SELECT COUNT(*) n FROM pc3_movimientos WHERE carga_id=$id AND candidato_involuntaria=1 AND fecha_evento<='$date'");
   $stats['cierres']=(int)($cand['n']??0);
   pc3_update_stats($conexion,$id,$stats);
   pc3_sql($conexion,'COMMIT');$inTransaction=false;
   pc3_sql($conexion,"SELECT RELEASE_LOCK('totalxpedient_pc3_publicar')");$haveLock=false;
   pc3_out(['ok'=>true,'total'=>(int)$load['registros_esperados'],'altas'=>(int)$stats['altas'],
       'cambios'=>(int)$stats['cambios'],'salidas'=>$stats['salidas'],'reapariciones'=>(int)$stats['reap'],
       'cierres_candidatos'=>$stats['cierres'],'mensaje'=>'DELTA publicado en vistas PC3 de prueba.']);
  }
  throw new RuntimeException('Acción no reconocida: '.$action);
 } catch(Throwable $e) {
   if($inTransaction)@mysqli_rollback($conexion);
   if($haveLock)@mysqli_query($conexion,"SELECT RELEASE_LOCK('totalxpedient_pc3_publicar')");
   pc3_out(['ok'=>false,'mensaje'=>$e->getMessage()],400);
 }
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TalIA · Pronóstico diferencial v3.0.4 STAGING</title>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-sha256@0.11.1/build/sha256.min.js"></script>
<style>
:root{--ink:#17253c;--brand:#7731e8;--rose:#f005a4;--bg:#f5f2fa;--border:#dfd8e8;--secondary:#626980}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font:15px/1.5 'Segoe UI',Arial,sans-serif;color:var(--ink);padding:24px}
main{max-width:980px;margin:auto;background:#fff;border:1px solid var(--border);border-radius:18px;padding:30px;box-shadow:0 12px 35px #23104212}
h1{font-size:26px;margin:0 0 5px}.micro{font-size:12px;letter-spacing:.09em;font-weight:800;color:#7533c1}
.sub{color:var(--secondary);margin:7px 0 18px}.warning{padding:12px 14px;background:#fff8e9;border:1px solid #efddba;border-radius:10px;color:#76551c;font-size:13px;margin:12px 0}
.drop{display:block;border:2px dashed #a775ec;background:#fbf8ff;text-align:center;padding:23px;border-radius:13px;cursor:pointer}
.drop strong{display:block}.drop small{color:var(--secondary)}input[type=file]{display:none}button{margin-top:18px;border:0;border-radius:11px;background:linear-gradient(110deg,var(--brand),var(--rose));color:#fff;font-weight:700;width:100%;padding:13px;cursor:pointer}button:disabled{opacity:.48;cursor:default}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0}.tile{background:#faf9fd;border:1px solid var(--border);border-radius:11px;padding:13px}.tile small{color:var(--secondary);display:block}.tile b{font-size:19px}
#notice{white-space:pre-wrap;padding:13px 14px;border-radius:11px;background:#eff5ff;color:#244f83;margin-top:15px;display:none}#notice.show{display:block}#notice.good{background:#eaf8ef;color:#126534}#notice.bad{background:#fff0f0;color:#963636}
.track{height:14px;border-radius:10px;background:#e7e1ef;overflow:hidden;margin-top:16px;display:none}.bar{height:100%;width:0;background:linear-gradient(100deg,#8f41e5,#f005a4);transition:width .12s}.count{color:var(--secondary);font-size:12px;margin-top:5px}
@media(max-width:650px){body{padding:10px}main{padding:18px}.grid{grid-template-columns:1fr}h1{font-size:22px}}
</style></head><body><main>
<div class="micro">TOTALXPEDIENT · TALIA · PC3 STAGING v3.0.4</div>
<h1>Pronóstico de cancelaciones diferencial</h1>
<p class="sub">Archivo nacional XLSB · carga inicial compacta · comparador de cuentas · bitácora de cierres candidatos</p>
<div class="warning"><strong>Modo seguro:</strong> este importador solo escribe tablas <code>pc3_*</code>. Las vistas productivas <code>vw_pronostico_cancelaciones_actual</code> y <code>vw_pronostico_cancelaciones_sur_actual</code> permanecen intactas. Verifica el espacio disponible antes de ejecutar el SQL.</div>
<label for="file" class="drop"><strong>Seleccionar archivo Pronóstico (.xlsb)</strong><small id="fname">Ej.: Pronóstico cancelaciones S47 17 NOVIEMBRE_.xlsb</small></label>
<input type="file" id="file" accept=".xlsb">
<label for="anio" style="display:block;margin-top:10px;font-size:13px;color:#626980">Año del archivo (obligatorio porque el nombre no lo incluye): <input type="number" id="anio" min="2025" max="2100" value="2026" style="width:100px;padding:7px;border:1px solid #dfd8e8;border-radius:8px"></label>
<div class="grid" id="tiles" hidden>
<div class="tile"><small>Fecha de corte</small><b id="cut">—</b></div>
<div class="tile"><small>Nacional</small><b id="national">—</b></div>
<div class="tile"><small>Región SUR</small><b id="sur">—</b></div>
</div>
<button id="run" disabled>Validar e importar corte PC3</button>
<div class="track" id="track"><div class="bar" id="bar"></div></div>
<div id="counter" class="count"></div>
<div id="notice"></div>
<div class="warning">CUENTA: los identificadores no numéricos que el XLSB trae como <code>1.xxxxxxx</code> se conservan literalmente; no se deducen ni corrigen automáticamente. Requieren conciliación con la fuente corporativa.<br><br>Orden de validación: 17 → 20 → 23 de noviembre de 2026. El primer corte no genera cancelaciones históricas; los siguientes identifican diferencias. Una transición a <code>Cerrada</code> es un <strong>candidato</strong>, no un real oficial sin conciliación Qlik.</div>
</main><script>
'use strict';
const CSRF=<?php echo json_encode($csrf,JSON_UNESCAPED_SLASHES);?>;
const BATCH=1500;
// Identificador opaco: preservar el valor literal del XLSB sin quitar puntos ni ceros.
const CUENTA_VALIDA=/^(?:\d{1,100}|1\.\d{7})$/;
const COLS=['CUENTA','PLAZA','DISTRITO','CLUSTER','REGION','ESTATUS_CTA','ATRASO','NSE','FECHA_ACTIVACION',
 'FECHA_CANCELACION','TIPO_CANCELACION','CICLO','FECHA_PRONOSTICO','SEMANA_PRONOSTICO','RELOJ',
 'SALDO_SERV','SERVICIO_TERCEROS','SERVICIOS_TOTALPLAY','NOMBRE_PLAN','SUB_CANAL','TIPO_PAGO','FECHA FINAL','SEMANA FINAL','RENTA','SUBESTATUS'];
const VOL=[6,14,15,16,17], EVENT=[2,4,5,9,10,12,13,21,22,23,24];
const $=id=>document.getElementById(id);
let file=null,raw=[],indices=[],columns=[],headRow=0,hash='',cut='',sur=0,atipicas=0;
const norm=v=>String(v??'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toUpperCase().replace(/[^A-Z0-9]/g,'');
const str=v=>v==null?'':String(v).trim();
const txt=v=>str(v)===''?null:str(v);
function show(t,kind='working'){$('notice').className='show '+(kind==='bad'?'bad':kind==='good'?'good':'');$('notice').textContent=t;}
function progress(label,done,total){$('track').style.display='block';$('bar').style.width=(total?Math.min(100,100*done/total):0).toFixed(1)+'%';$('counter').textContent=label+' · '+done.toLocaleString('es-MX')+' / '+total.toLocaleString('es-MX');}
const pulse=()=>new Promise(r=>setTimeout(r,0));
function formatDate(v){
 if(v==null||str(v)===''||['-','—','N/A','ND','NULL','0','0000-00-00'].includes(str(v).toUpperCase()))return null;
 if(typeof v==='number'){const d=XLSX.SSF.parse_date_code(v);if(!d||!d.y||!d.m||!d.d)throw Error('Fecha Excel inválida: '+v);return `${String(d.y).padStart(4,'0')}-${String(d.m).padStart(2,'0')}-${String(d.d).padStart(2,'0')}`;}
 const s=str(v);if(/^\d{4}-\d{2}-\d{2}$/.test(s))return s;
 const m=s.match(/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/);if(m)return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
 throw Error('Fecha no reconocida: '+s);
}
function intVal(v){if(v==null||str(v)===''||['-','—','N/A','ND','NULL'].includes(str(v).toUpperCase()))return null;const x=Number(str(v).replace(/,/g,''));if(!Number.isFinite(x)||!Number.isInteger(Math.round(x))||Math.abs(x)>999999999)throw Error('Entero inválido: '+v);return String(Math.round(x));}
function money(v){if(v==null||str(v)===''||['-','—','N/A','ND','NULL'].includes(str(v).toUpperCase()))return null;const x=Number(str(v).replace(/,/g,''));if(!Number.isFinite(x)||Math.abs(x)>999999999999.99)throw Error('Importe inválido: '+v);return x.toFixed(2);}
function fieldsAt(position){
 const idx=indices[position-1];if(idx===undefined)throw Error('Posición inexistente '+position);
 const row=raw[idx],f=columns.map(c=>txt(row[c]));
 // Igual al importador legacy: usar el valor RAW, no el texto formateado (cell.w).
 f[0]=str(f[0]);if(!CUENTA_VALIDA.test(f[0]))throw Error('CUENTA inválida en fila '+(idx+1));
 for(const i of [8,9,12,21])f[i]=formatDate(row[columns[i]]);
 for(const i of [6,11,13,22])f[i]=intVal(row[columns[i]]);
 for(const i of [15,16,17,23])f[i]=money(row[columns[i]]);
 return f;
}
function hashes(f){const data=f.filter((_,i)=>!VOL.includes(i)),ev=EVENT.map(i=>f[i]);return [sha256(JSON.stringify(data)),sha256(JSON.stringify(ev))];}
async function api(action,p){
 const resp=await fetch(location.pathname+'?action='+encodeURIComponent(action),{
 method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(p)});
 const t=await resp.text();let d;try{d=JSON.parse(t);}catch{throw Error('Respuesta no JSON: '+t.slice(0,300));}
 if(!resp.ok||!d.ok)throw Error(d.mensaje||'Error de servidor');return d;
}
function dateFromFile(name){const m=name.normalize('NFKD').replace(/[\u0300-\u036f]/g,'').match(/^pronostico\s+cancelaciones\s+s\d+\s+(\d{1,2})\s+([a-z]+).*\.xlsb$/i);
 if(!m)throw Error('Nombre no reconocido.');const names=['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
 const n=names.indexOf(m[2].toUpperCase());if(n<0)throw Error('Mes de corte inválido');
 const y=Number($('anio').value);if(!Number.isInteger(y)||y<2025||y>2100)throw Error('Año de corte inválido');
 return String(y)+'-'+String(n+1).padStart(2,'0')+'-'+m[1].padStart(2,'0');}
// La lectura del XLSB vive en una función separada: al terminar, el navegador
// puede liberar el ArrayBuffer de 30 MB y el objeto SheetJS (millones de celdas).
async function parseXLSB(f){
 show('1/4 · Leyendo archivo XLSB…');
 let buf=await f.arrayBuffer();
 show('2/4 · Calculando SHA-256…');await pulse();
 const digest=await crypto.subtle.digest('SHA-256',buf);
 const sha=[...new Uint8Array(digest)].map(x=>x.toString(16).padStart(2,'0')).join('');
 show('3/4 · Abriendo hoja Pronóstico (esta etapa puede exigir memoria)…');await pulse();
 let book=XLSX.read(buf,{type:'array',cellDates:false,cellText:false,dense:true,sheets:['Pronóstico']});
 buf=null;
 const name=book.SheetNames.find(x=>norm(x)==='PRONOSTICO');
 if(!name||!book.Sheets[name])throw Error('No se encontró la hoja Pronóstico.');
 show('4/4 · Organizando filas…');await pulse();
 const rows=XLSX.utils.sheet_to_json(book.Sheets[name],{header:1,defval:null,raw:true,blankrows:true});
 book.Sheets[name]=null;book=null;
 return {rows,sha};
}
$('anio').addEventListener('change',()=>{ $('run').disabled=true;show('Año modificado: vuelve a elegir el archivo para validar el corte.');});
$('file').addEventListener('change',async()=>{
 file=$('file').files?.[0]||null;raw=[];indices=[];columns=[];hash='';sur=0;atipicas=0;
 $('run').disabled=true;$('tiles').hidden=true;$('track').style.display='none';$('counter').textContent='';
 if(!file)return;$('fname').textContent=file.name;
 try{
 if(typeof XLSX==='undefined'||typeof sha256==='undefined')throw Error('Falta SheetJS o SHA-256 (CDN).');
 if(!/\.xlsb$/i.test(file.name))throw Error('Selecciona .xlsb');
 cut=dateFromFile(file.name);
 const parsed=await parseXLSB(file);raw=parsed.rows;hash=parsed.sha;
 // blankrows:true preserva los números físicos de fila para acceder a A1, A2, etc.
 headRow=raw.findIndex((r,i)=>i<15&&norm(r?.[0])==='CUENTA'&&r.map(norm).includes('ESTATUSCTA'));
 if(headRow<0)throw Error('No se localizaron los encabezados originales.');
 const heads=(raw[headRow]||[]).map(norm);
 columns=COLS.map(x=>heads.indexOf(norm(x)));
 if(columns.some(i=>i<0))throw Error('Columnas faltantes: '+COLS.filter((_,i)=>columns[i]<0).join(', '));
 const seen=new Set();
 for(let i=headRow+1;i<raw.length;i++){
  const row=raw[i]||[];if(row.every(x=>x===null||str(x)===''))continue;
  // Almacenamos el índice de la hoja; la posición lógica del manifiesto es consecutiva.
  indices.push(i);const pos=indices.length;
  const account=str(row[columns[0]]);
  if(!CUENTA_VALIDA.test(account))throw Error('CUENTA inválida en fila '+(i+1)+': '+account);
  if(account.includes('.'))atipicas++;
  if(seen.has(account))throw Error('CUENTA duplicada: '+account);seen.add(account);
  if(norm(row[columns[4]])==='SUR')sur++;
  if(pos%12000===0){show('Reconociendo cuentas: '+pos.toLocaleString('es-MX'));await pulse();}
 }
 seen.clear();if(indices.length<1||indices.length>1000000)throw Error('Número de registros no válido.');
 $('tiles').hidden=false;$('cut').textContent=cut;$('national').textContent=indices.length.toLocaleString('es-MX');$('sur').textContent=sur.toLocaleString('es-MX');
 $('run').disabled=false;show('Archivo validado. '+indices.length.toLocaleString('es-MX')+' cuentas; '+atipicas.toLocaleString('es-MX')+' con identificador atípico literal conservado sin modificación. Clic en importar para iniciar STAGING.','good');
 }catch(e){show(e.message||String(e),'bad');}
});
$('run').addEventListener('click',async()=>{
 if(!file||!indices.length)return;$('run').disabled=true;let id=0,step='Inicio';
 try{
  show('Abriendo corte…');const init=await api('init',{archivo:file.name,hash,total:indices.length,anio:Number($('anio').value)});
  id=init.carga_id;
  if(init.repetido){
   step='Limpieza';let cleared=0;while(true){const r=await api('cleanup',{carga_id:id});cleared+=r.eliminados;if(r.pendientes===0)break;}
   show('Archivo ya publicado. No se duplicó el corte; temporales pendientes depurados.','good');return;
  }
  let done=Number(init.resume_from||0);const total=indices.length;
  if(init.modo==='BASE'){
   step='Carga inicial';
   while(done<total){const end=Math.min(done+BATCH,total);const lines=[];for(let i=done;i<end;i++)lines.push(fieldsAt(i+1));
    const res=await api('base_batch',{carga_id:id,offset:done,rows:lines});done=res.total;progress('Base inicial',done,total);
   }
   step='Aprobación de base';const r=await api('base_finish',{carga_id:id});show('BASE STAGING aprobada: '+r.total.toLocaleString('es-MX')+' cuentas. Sin modificar FCST productivo.','good');
  }else{
   step='Manifiesto';while(done<total){const end=Math.min(done+BATCH,total);
    const lines=[];
    for(let i=done;i<end;i++){
     const f=fieldsAt(i+1), [hd,he]=hashes(f);
     lines.push([i+1,f[0],hd,he,f[6],f[15],f[16],f[17],f[14]]);
    }
    const res=await api('manifest_batch',{carga_id:id,offset:done,rows:lines});done=res.total;progress('Manifiesto nacional',done,total);
   }
   const f=await api('manifest_finish',{carga_id:id});
   show('Manifiesto recibido. Detalles nuevos/modificados: '+f.detalles_necesarios.toLocaleString('es-MX'));
   step='Diferencias';let after=0,doneDetails=0;
   while(true){const x=await api('need_details',{carga_id:id,after,limit:BATCH});if(!x.indices.length)break;
    const pairs=x.indices.map(i=>[i,fieldsAt(i)]);
    const r=await api('detail_batch',{carga_id:id,rows:pairs});
    after=x.indices[x.indices.length-1];doneDetails+=pairs.length;progress('Detalles diferenciales',doneDetails,Math.max(f.detalles_necesarios,doneDetails));
   }
   step='Publicación';show('Verificando integridad y publicando cambios PC3…');
   const r=await api('publish',{carga_id:id});
   show(`Publicado STAGING ${cut}.
Cuentas actuales: ${r.total.toLocaleString('es-MX')}
Altas: ${r.altas} | Cambios: ${r.cambios} | Salidas: ${r.salidas} | Reapariciones: ${r.reapariciones}
Cierres candidatos (NO Qlik confirmado): ${r.cierres_candidatos}.
Depurando temporales…`,'good');
  }
  step='Limpieza';let removed=0;
  while(true){const r=await api('cleanup',{carga_id:id});removed+=r.eliminados;if(r.pendientes===0)break;if(removed%15000===0)await pulse();}
  show(`✅ STAGING listo. Corte ${cut}, ${total.toLocaleString('es-MX')} cuentas. Temporales depurados; vistas productivas intactas.`,'good');
 }catch(e){show('Error durante '+step+': '+(e.message||e)+'\nPuedes reabrir el mismo archivo para reanudar.','bad');
  if(id&&step!=='Limpieza')try{await api('mark_error',{carga_id:id,mensaje:step+': '+String(e.message||e)});}catch(_){/* mantener error original */}
 }finally{$('run').disabled=false;}
});
</script></body></html>
