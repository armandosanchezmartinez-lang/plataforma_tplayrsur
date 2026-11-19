<?php
/**
 * TotalXpedient / TalIA - Importador NPPF v1.0.1 (2026-11-19)
 * Destino: import/import_nppf.php
 * Requiere las 6 tablas y 2 vistas del esquema totalxpedient_nppf_esquema_v1.sql.
 * Corte NACIONAL diferencial: carga manifiesto -> detalles que cambian -> indicadores ->
 * validacion -> publicacion atomica -> depuracion de staging.
 * No interpreta TIPO=REVERSO; conserva exactamente el valor de origen.
 */
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();
$usuarioSesion = (string)($_SESSION['usuario'] ?? $_SESSION['username'] ?? $_SESSION['nombre_usuario'] ?? $_SESSION['user'] ?? '');
$autenticado = $usuarioSesion !== '' || !empty($_SESSION['id_usuario']) || !empty($_SESSION['usuario_id']) || !empty($_SESSION['user_id']);
if (!$autenticado) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Debes iniciar sesión en TotalXpedient antes de utilizar el importador.');
}
if ($usuarioSesion === '') $usuarioSesion = 'Usuario de sesión';
if (empty($_SESSION['csrf_nppf'])) $_SESSION['csrf_nppf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf_nppf'];
session_write_close();
require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';
mysqli_set_charset($conexion, 'utf8mb4');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

const NPPF_BATCH_MAX = 2000;
const NPPF_TOTAL_MAX = 1000000;
const NPPF_INDICATOR_MAX = 10000;

function nppf_out(array $result, int $status=200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function nppf_payload(): array {
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') throw new RuntimeException('Petición vacía.');
    $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('JSON inválido.');
    return $value;
}
function nppf_query(mysqli $db,string $sql): mysqli_result|bool {
    $r=mysqli_query($db,$sql);
    if ($r===false) throw new RuntimeException(mysqli_error($db));
    return $r;
}
function nppf_one(mysqli $db,string $sql): array {
    $r=nppf_query($db,$sql);
    return $r instanceof mysqli_result ? (mysqli_fetch_assoc($r)?:[]) : [];
}
function nppf_stmt(mysqli $db, string $sql): mysqli_stmt {
    $s=mysqli_prepare($db,$sql);
    if (!$s) throw new RuntimeException(mysqli_error($db));
    return $s;
}
function nppf_exec(mysqli_stmt $s): void {
    if (!mysqli_stmt_execute($s)) throw new RuntimeException(mysqli_stmt_error($s));
}
function nppf_load(mysqli $db,int $id): array {
    $s=nppf_stmt($db,'SELECT * FROM nppf_cargas WHERE id=? LIMIT 1');
    mysqli_stmt_bind_param($s,'i',$id); nppf_exec($s);
    $r=mysqli_stmt_get_result($s); $row=mysqli_fetch_assoc($r)?:[]; mysqli_stmt_close($s);
    if (!$row) throw new RuntimeException('No existe esta carga.');
    return $row;
}
function nppf_counter(mysqli $db,string $table,int $id): int {
    $allowed=['nppf_carga_manifest','nppf_carga_detalle','nppf_indicadores_mensuales'];
    if (!in_array($table,$allowed,true)) throw new RuntimeException('Tabla no válida.');
    $s=nppf_stmt($db,"SELECT COUNT(*) AS n FROM `$table` WHERE carga_id=?");
    mysqli_stmt_bind_param($s,'i',$id); nppf_exec($s);
    $r=mysqli_stmt_get_result($s); $v=(int)(mysqli_fetch_assoc($r)['n']??0); mysqli_stmt_close($s);
    return $v;
}
function nppf_nombre_fecha(string $filename): string {
    $filename=basename(str_replace('\\','/',$filename));
    if (preg_match('/^(20\d{2})(\d{2})(\d{2})_NPPF\b/i',$filename,$m)) {
        [$y,$mo,$d]=[(int)$m[1],(int)$m[2],(int)$m[3]];
    } elseif (preg_match('/^(\d{2})(\d{2})(20\d{2})_NPPF\b/i',$filename,$m)) {
        [$y,$mo,$d]=[(int)$m[3],(int)$m[2],(int)$m[1]];
    } else {
        throw new RuntimeException('Nombre inválido. Se requiere AAAAMMDD_NPPF ... .xlsb (o DDMMAAAA_NPPF ...).');
    }
    if (!checkdate($mo,$d,$y)) throw new RuntimeException('La fecha del archivo no es válida.');
    return sprintf('%04d-%02d-%02d',$y,$mo,$d);
}
function nppf_txt(mixed $v): ?string {
    if ($v===null) return null;
    $s=trim((string)$v);
    return $s==='' ? null : $s;
}
function nppf_account_key(string $s): string {
    $digits=preg_replace('/\D/u','',$s)??'';
    return ltrim($digits,'0') ?: ($digits!==''?'0':'');
}
function nppf_hash_business(array $fields): string {
    $business=[];
    foreach ($fields as $i=>$v) if ($i!==5) $business[]=$v;
    return hash('sha256',json_encode($business,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
}
function nppf_check_data(array $fields,array $manifest): void {
    if (count($fields)!==21) throw new RuntimeException('El detalle no tiene los 21 campos esperados.');
    foreach ($fields as $v) if ($v!==null && !is_string($v)) throw new RuntimeException('Campos de detalle deben ser texto o null.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($fields[1]??''))) throw new RuntimeException('FECHA ACTIVACION inválida.');
    if ($fields[1] !== null) {
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$fields[1]);
        if (!$dt || $dt->format('Y-m-d')!==$fields[1]) throw new RuntimeException('Fecha de activación fuera de rango.');
    }
    if (strlen((string)$fields[0])>30 || strlen((string)$fields[0])<1) throw new RuntimeException('Longitud de CUENTA inválida.');
    if (nppf_account_key((string)$fields[0])!==$manifest['cuenta_clave']) throw new RuntimeException('La CUENTA no coincide con el manifiesto.');
    if ((int)$fields[20]!== (int)$manifest['anio_cosecha']) throw new RuntimeException('AÑO COSECHA no coincide con el manifiesto.');
    if (!preg_match('/^\d{1,2}/',(string)$fields[18],$mm) || (int)$mm[0] !== (int)$manifest['mes_cosecha'])
        throw new RuntimeException('NO MES no coincide con el manifiesto.');
    if (nppf_hash_business($fields)!==$manifest['hash_negocio']) throw new RuntimeException('Hash de contenido diferente entre detalle y manifiesto.');
    $limits=[0=>30,2=>40,4=>25,6=>120,7=>120,8=>180,9=>160,10=>100,11=>180,12=>200,13=>255,14=>200,15=>200,16=>120,17=>30,18=>20,19=>20];
    foreach ($limits as $i=>$n) if (mb_strlen((string)($fields[$i]??''),'UTF-8')>$n) throw new RuntimeException("Campo $i excede $n caracteres.");
    if ($fields[3]!==null && (!is_numeric($fields[3]) || abs((float)$fields[3])>999999999999.99)) throw new RuntimeException('MONTO_FACTURA inválido.');
}
function nppf_guard_last(mysqli $db,string $date,int $excludeId=0): void {
    $s=nppf_stmt($db,"SELECT fecha_corte, archivo_origen FROM nppf_cargas WHERE estado='OK' AND id<>? ORDER BY fecha_corte DESC,id DESC LIMIT 1");
    mysqli_stmt_bind_param($s,'i',$excludeId); nppf_exec($s);
    $r=mysqli_stmt_get_result($s); $last=mysqli_fetch_assoc($r)?:[]; mysqli_stmt_close($s);
    if ($last && $date < $last['fecha_corte'])
        throw new RuntimeException('Corte anterior al último aprobado (' . $last['fecha_corte'] . '). No se puede reescribir el estado actual con archivos antiguos.');
}
// ------------------------------------------------------- API -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_GET['action'])) {
    $inTransaction=false; $haveLock=false;
    try {
        $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
        if (!hash_equals($csrf,(string)$token)) throw new RuntimeException('La sesión expiró. Recarga la página.');
        $action=(string)$_GET['action'];
        $p=nppf_payload();
        if ($action==='init') {
            $file=basename(str_replace('\\','/',(string)($p['archivo']??'')));
            if (!preg_match('/\.xlsb$/i',$file)) throw new RuntimeException('El archivo debe ser .xlsb');
            $date=nppf_nombre_fecha($file);
            $hash=(string)($p['hash']??'');
            if (!preg_match('/^[a-f0-9]{64}$/',$hash)) throw new RuntimeException('Hash SHA-256 de archivo inválido.');
            $count=(int)($p['total']??0);
            $empty=(int)($p['vacias']??0);
            $sourceRows=(int)($p['filas']??0);
            $ind=(int)($p['indicadores']??0);
            if ($count<1||$count>NPPF_TOTAL_MAX||$ind<1||$ind>NPPF_INDICATOR_MAX||$sourceRows<$count || $empty<0)
                throw new RuntimeException('Conteos declarados inválidos o la hoja de indicadores está vacía.');
            $s=nppf_stmt($conexion,'SELECT id,estado,fecha_corte,registros_validos,indicador_registros FROM nppf_cargas WHERE hash_archivo=? LIMIT 1');
            mysqli_stmt_bind_param($s,'s',$hash); nppf_exec($s);
            $r=mysqli_stmt_get_result($s); $old=mysqli_fetch_assoc($r)?:[]; mysqli_stmt_close($s);
            if ($old) {
                if ($old['fecha_corte']!==$date || (int)$old['registros_validos']!==$count)
                    throw new RuntimeException('El archivo coincide por hash con una carga de diferente fecha o número de filas. Revisión requerida.');
                if ($old['estado']==='OK') nppf_out(['ok'=>true,'repetido'=>true,'carga_id'=>(int)$old['id'],'mensaje'=>'Archivo ya publicado: no se duplicaron registros.']);
                nppf_guard_last($conexion,$date,(int)$old['id']);
                $cargaId=(int)$old['id'];
                $manifest=nppf_counter($conexion,'nppf_carga_manifest',$cargaId);
                nppf_out(['ok'=>true,'carga_id'=>$cargaId,'reanudando'=>true,'manifest_recibido'=>$manifest,'mensaje'=>'Se encontró carga anterior incompleta. Se continuará desde el último lote confirmado.']);
            }
            nppf_guard_last($conexion,$date);
            // Impedir dos cortes simultáneos en staging y publicaciones cruzadas.
            $active=nppf_one($conexion,"SELECT id,archivo_origen FROM nppf_cargas WHERE estado='PROCESANDO' ORDER BY id DESC LIMIT 1");
            if ($active) throw new RuntimeException('Ya existe la carga '.$active['id'].' en proceso ('.$active['archivo_origen'].'). Finaliza o marca ERROR antes de comenzar otra.');
            $s=nppf_stmt($conexion,"INSERT INTO nppf_cargas(archivo_origen,hash_archivo,fecha_corte,filas_hoja,registros_validos,filas_vacias,usuario,estado,mensaje) VALUES (?,?,?,?,?,? ,?,'PROCESANDO','Preparando manifiesto diferencial')");
            mysqli_stmt_bind_param($s,'sssiiis',$file,$hash,$date,$sourceRows,$count,$empty,$usuarioSesion); nppf_exec($s);
            $cargaId=(int)mysqli_insert_id($conexion); mysqli_stmt_close($s);
            nppf_out(['ok'=>true,'carga_id'=>$cargaId,'manifest_recibido'=>0,'mensaje'=>'Carga '.$cargaId.' creada.']);
        }
        $id=(int)($p['carga_id']??0);
        if ($id<1) throw new RuntimeException('carga_id inválido.');
        $load=nppf_load($conexion,$id);
        if ($action==='status') {
            nppf_out(['ok'=>true,'carga'=>$load,'manifest'=>nppf_counter($conexion,'nppf_carga_manifest',$id),'detalle'=>nppf_counter($conexion,'nppf_carga_detalle',$id),'indicadores'=>nppf_counter($conexion,'nppf_indicadores_mensuales',$id)]);
        }
        if ($action==='cleanup') {
            if ($load['estado']!=='OK') throw new RuntimeException('Solo se limpian datos temporales de cargas OK.');
            $s=nppf_stmt($conexion,'DELETE FROM nppf_carga_manifest WHERE carga_id=? LIMIT 3000');
            mysqli_stmt_bind_param($s,'i',$id); nppf_exec($s);
            $removed=mysqli_stmt_affected_rows($s); mysqli_stmt_close($s);
            nppf_out(['ok'=>true,'eliminados'=>$removed,'faltan'=>nppf_counter($conexion,'nppf_carga_manifest',$id)]);
        }
        if ($action==='mark_error') {
            if ($load['estado']!=='OK') {
                $error=mb_substr((string)($p['mensaje']??'Interrupción del navegador'),0,800);
                $s=nppf_stmt($conexion,"UPDATE nppf_cargas SET estado='ERROR', mensaje=? WHERE id=? AND estado<>'OK'");
                mysqli_stmt_bind_param($s,'si',$error,$id); nppf_exec($s); mysqli_stmt_close($s);
            }
            nppf_out(['ok'=>true]);
        }
        if (!in_array($load['estado'],['ERROR','PROCESANDO'],true)) throw new RuntimeException('La carga ya está finalizada o bloqueada.');
        nppf_guard_last($conexion,$load['fecha_corte'],$id);
        if ($action==='manifest_batch') {
            $rows=$p['rows']??null;
            if (!is_array($rows)||count($rows)<1||count($rows)>NPPF_BATCH_MAX) throw new RuntimeException('Lote de manifiesto inválido.');
            $offset=(int)($p['offset']??-1);
            $done=nppf_counter($conexion,'nppf_carga_manifest',$id);
            if ($offset<$done && $offset+count($rows)<=$done) nppf_out(['ok'=>true,'repetido'=>true,'total'=>$done]);
            if ($offset!==$done || $done+count($rows)>(int)$load['registros_validos']) throw new RuntimeException('Desfase de lote: servidor '.$done.' vs navegador '.$offset.'.');
            nppf_query($conexion,'START TRANSACTION');$inTransaction=true;
            $s=nppf_stmt($conexion,'INSERT INTO nppf_carga_manifest (carga_id,fila_excel,cuenta_clave,anio_cosecha,mes_cosecha,atraso,hash_negocio) VALUES (?,?,?,?,?,?,?)');
            foreach ($rows as $j=>$v) {
                if (!is_array($v)||count($v)!==6) throw new RuntimeException('Manifiesto mal formado.');
                [$pos,$cuenta,$year,$month,$atraso,$hashRow]=$v;
                $pos=(int)$pos; $year=(int)$year; $month=(int)$month;
                if ($pos!==$offset+$j+1 || !preg_match('/^\d{1,30}$/',(string)$cuenta) || $year<2019||$year>2100||$month<1||$month>12 || !preg_match('/^[a-f0-9]{64}$/',(string)$hashRow)) throw new RuntimeException('Llave/manifiesto inválido en la fila '.$pos.'.');
                if ($atraso!==null && (!is_numeric($atraso)||abs((int)$atraso)>100000)) throw new RuntimeException('Atraso inválido en '.$pos);
                $nAtraso=$atraso===null?null:(int)$atraso;
                $key=(string)$cuenta;$h=(string)$hashRow;
                mysqli_stmt_bind_param($s,'iisiiis',$id,$pos,$key,$year,$month,$nAtraso,$h); nppf_exec($s);
            }
            mysqli_stmt_close($s);nppf_query($conexion,'COMMIT');$inTransaction=false;
            nppf_out(['ok'=>true,'total'=>$offset+count($rows)]);
        }
        if ($action==='manifest_finish') {
            $done=nppf_counter($conexion,'nppf_carga_manifest',$id);
            if ($done!==(int)$load['registros_validos']) throw new RuntimeException("Manifiesto parcial ($done / ".$load['registros_validos'].').');
            $s=nppf_stmt($conexion,'SELECT COUNT(*) AS n FROM nppf_carga_manifest m LEFT JOIN nppf_registros r ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha WHERE m.carga_id=? AND (r.id IS NULL OR r.hash_negocio<>m.hash_negocio)');
            mysqli_stmt_bind_param($s,'i',$id);nppf_exec($s);
            $need=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['n']??0);mysqli_stmt_close($s);
            nppf_out(['ok'=>true,'total'=>$done,'detalles_necesarios'=>$need]);
        }
        if ($action==='need_details') {
            if (nppf_counter($conexion,'nppf_carga_manifest',$id)!==(int)$load['registros_validos']) throw new RuntimeException('Manifiesto no finalizado.');
            $after=max(0,(int)($p['after']??0));$limit=min(NPPF_BATCH_MAX,max(1,(int)($p['limit']??1000)));
            $s=nppf_stmt($conexion,'SELECT m.fila_excel FROM nppf_carga_manifest m LEFT JOIN nppf_registros r ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha LEFT JOIN nppf_carga_detalle d ON d.carga_id=m.carga_id AND d.fila_excel=m.fila_excel WHERE m.carga_id=? AND m.fila_excel>? AND (r.id IS NULL OR r.hash_negocio<>m.hash_negocio) AND d.fila_excel IS NULL ORDER BY m.fila_excel LIMIT ?');
            mysqli_stmt_bind_param($s,'iii',$id,$after,$limit);nppf_exec($s);
            $res=mysqli_stmt_get_result($s);$indices=[];
            while ($v=mysqli_fetch_assoc($res))$indices[]=(int)$v['fila_excel'];
            mysqli_stmt_close($s);
            nppf_out(['ok'=>true,'indices'=>$indices]);
        }
        if ($action==='detail_batch') {
            $rows=$p['rows']??null;
            if (!is_array($rows)||count($rows)<1||count($rows)>NPPF_BATCH_MAX) throw new RuntimeException('Lote de detalle inválido.');
            nppf_query($conexion,'START TRANSACTION');$inTransaction=true;
            $get=nppf_stmt($conexion,'SELECT m.cuenta_clave,m.anio_cosecha,m.mes_cosecha,m.hash_negocio, r.id AS existe, r.hash_negocio AS hash_actual FROM nppf_carga_manifest m LEFT JOIN nppf_registros r ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha WHERE m.carga_id=? AND m.fila_excel=?');
            $put=nppf_stmt($conexion,'INSERT INTO nppf_carga_detalle(carga_id,fila_excel,datos_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE datos_json=VALUES(datos_json)');
            foreach ($rows as $one) {
                if (!is_array($one)||count($one)!==2 || !is_array($one[1])) throw new RuntimeException('Detalle mal formado.');
                $pos=(int)$one[0];$vals=$one[1];
                mysqli_stmt_bind_param($get,'ii',$id,$pos);nppf_exec($get);
                $rr=mysqli_stmt_get_result($get);$m=mysqli_fetch_assoc($rr)?:[];mysqli_free_result($rr);
                if (!$m) throw new RuntimeException('No existe manifiesto para fila '.$pos);
                nppf_check_data($vals,$m);
                if ($m['existe']!==null && $m['hash_actual']===$m['hash_negocio']) continue;
                $json=json_encode($vals,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
                mysqli_stmt_bind_param($put,'iis',$id,$pos,$json);nppf_exec($put);
            }
            mysqli_stmt_close($get);mysqli_stmt_close($put);
            nppf_query($conexion,'COMMIT');$inTransaction=false;
            nppf_out(['ok'=>true,'detalle_recibido'=>nppf_counter($conexion,'nppf_carga_detalle',$id)]);
        }
        if ($action==='indicators') {
            $rows=$p['rows']??null;
            if (!is_array($rows)||count($rows)<1||count($rows)>NPPF_INDICATOR_MAX) throw new RuntimeException('Indicadores vacíos o exceden límite.');
            nppf_query($conexion,'START TRANSACTION');$inTransaction=true;
            $del=nppf_stmt($conexion,'DELETE FROM nppf_indicadores_mensuales WHERE carga_id=?');
            mysqli_stmt_bind_param($del,'i',$id);nppf_exec($del);mysqli_stmt_close($del);
            $put=nppf_stmt($conexion,'INSERT INTO nppf_indicadores_mensuales(carga_id,anio,mes,nivel,region,distrito,instalaciones,nppf,porcentaje_fuente) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $v) {
                if (!is_array($v)||count($v)!==8) throw new RuntimeException('Indicador inválido.');
                [$y,$m,$level,$region,$district,$inst,$bad,$pct]=$v;
                $y=(int)$y;$m=(int)$m;$inst=(int)$inst;$bad=(int)$bad;
                $level=(string)$level;$region=(string)$region;$district=(string)$district;
                if ($y<2019||$y>2100||$m<1||$m>12||!in_array($level,['REGION','DISTRITO','NACIONAL'],true)||$inst<0||$bad<0||$inst>10000000||$bad>10000000||$pct===null||!is_numeric($pct)|| (float)$pct<0 || (float)$pct>1.5)throw new RuntimeException('Valores de indicadores inválidos.');
                if ($level==='NACIONAL'){$region='';$district='';}
                if ($level==='REGION')$district='';
                $pvalue=round((float)$pct,9);
                mysqli_stmt_bind_param($put,'iiisssiid',$id,$y,$m,$level,$region,$district,$inst,$bad,$pvalue);nppf_exec($put);
            }
            mysqli_stmt_close($put);
            $ind=nppf_counter($conexion,'nppf_indicadores_mensuales',$id);
            $s=nppf_stmt($conexion,'UPDATE nppf_cargas SET indicador_registros=? WHERE id=?');mysqli_stmt_bind_param($s,'ii',$ind,$id);nppf_exec($s);mysqli_stmt_close($s);
            nppf_query($conexion,'COMMIT');$inTransaction=false;
            nppf_out(['ok'=>true,'indicadores'=>$ind]);
        }
        if ($action==='publish') {
            // GET_LOCK protects the final cross-cut rewrite even across PHP workers.
            $lock=nppf_one($conexion,"SELECT GET_LOCK('totalxpedient_nppf_publicar',10) AS l");
            if ((int)($lock['l']??0)!==1) throw new RuntimeException('Otra publicación NPPF sigue en curso.');
            $haveLock=true;
            nppf_query($conexion,'START TRANSACTION');$inTransaction=true;
            $s=nppf_stmt($conexion,"SELECT id FROM nppf_cargas WHERE id=? AND estado IN ('ERROR','PROCESANDO') FOR UPDATE");
            mysqli_stmt_bind_param($s,'i',$id);nppf_exec($s);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($s))) throw new RuntimeException('Carga ya publicada.');mysqli_stmt_close($s);
            nppf_guard_last($conexion,$load['fecha_corte'],$id);
            $manifest=nppf_counter($conexion,'nppf_carga_manifest',$id);
            if ($manifest!==(int)$load['registros_validos'])throw new RuntimeException('El manifiesto no contiene el total esperado.');
            $ind=nppf_counter($conexion,'nppf_indicadores_mensuales',$id);
            if ($ind<1 || $ind!==(int)$load['indicador_registros'])throw new RuntimeException('Los indicadores no están completos.');
            $s=nppf_stmt($conexion,'SELECT COUNT(*) AS n FROM nppf_carga_manifest m LEFT JOIN nppf_registros r ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha LEFT JOIN nppf_carga_detalle d ON d.carga_id=m.carga_id AND d.fila_excel=m.fila_excel WHERE m.carga_id=? AND (r.id IS NULL OR r.hash_negocio<>m.hash_negocio) AND d.fila_excel IS NULL');
            mysqli_stmt_bind_param($s,'i',$id);nppf_exec($s);$missing=(int)(mysqli_fetch_assoc(mysqli_stmt_get_result($s))['n']??0);mysqli_stmt_close($s);
            if ($missing) throw new RuntimeException('Aún faltan '.$missing.' detalles. No se modifica el estado actual.');
            $date=mysqli_real_escape_string($conexion,$load['fecha_corte']);$iid=(int)$id;
            // Estadística ANTES de actualizar, para distinguir altas, cambios y reapariciones.
            $stats=nppf_one($conexion,"SELECT
                SUM(r.id IS NULL) AS altas,
                SUM(r.id IS NOT NULL AND r.presente_en_ultimo_corte=0) AS reapariciones,
                SUM(r.id IS NOT NULL AND r.hash_negocio<>m.hash_negocio) AS negocio,
                SUM(r.id IS NOT NULL AND r.hash_negocio=m.hash_negocio AND NOT(r.atraso<=>m.atraso)) AS atraso
                FROM nppf_carga_manifest m LEFT JOIN nppf_registros r
                ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha WHERE m.carga_id=$iid");
            $gone=nppf_one($conexion,"SELECT COUNT(*) AS n FROM nppf_registros r LEFT JOIN nppf_carga_manifest m
                ON m.carga_id=$iid AND m.cuenta_clave=r.cuenta_clave AND m.anio_cosecha=r.anio_cosecha AND m.mes_cosecha=r.mes_cosecha
                WHERE r.presente_en_ultimo_corte=1 AND m.carga_id IS NULL");
            // Guardar eventos de CAMBIO y REAPARICION antes de sobrescribir estado anterior.
            nppf_query($conexion,"INSERT INTO nppf_movimientos(carga_id,registro_id,cuenta_clave,anio_cosecha,mes_cosecha,tipo_movimiento,campos_modificados,antes,despues)
                SELECT $iid,r.id,m.cuenta_clave,m.anio_cosecha,m.mes_cosecha,
                    IF(r.presente_en_ultimo_corte=0,'REAPARICION','ACTUALIZACION'),
                    IF(r.hash_negocio<>m.hash_negocio,'Contenido de negocio','Reaparicion sin cambio de negocio'),
                    JSON_OBJECT('tipo',r.tipo,'atraso',r.atraso,'estatus_cta',r.estatus_cta,'distrito',r.distrito,'presente',r.presente_en_ultimo_corte),
                    JSON_OBJECT('tipo',NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.datos_json,'$[4]')),'null'),'atraso',m.atraso)
                FROM nppf_carga_manifest m JOIN nppf_registros r ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha
                LEFT JOIN nppf_carga_detalle d ON d.carga_id=m.carga_id AND d.fila_excel=m.fila_excel
                WHERE m.carga_id=$iid AND (r.presente_en_ultimo_corte=0 OR r.hash_negocio<>m.hash_negocio)");
            nppf_query($conexion,"INSERT INTO nppf_movimientos(carga_id,registro_id,cuenta_clave,anio_cosecha,mes_cosecha,tipo_movimiento,campos_modificados,antes,despues)
                SELECT $iid,r.id,r.cuenta_clave,r.anio_cosecha,r.mes_cosecha,'BAJA','Deja de aparecer en el siguiente corte',
                JSON_OBJECT('tipo',r.tipo,'atraso',r.atraso,'estatus_cta',r.estatus_cta),NULL
                FROM nppf_registros r LEFT JOIN nppf_carga_manifest m ON m.carga_id=$iid AND m.cuenta_clave=r.cuenta_clave AND m.anio_cosecha=r.anio_cosecha AND m.mes_cosecha=r.mes_cosecha
                WHERE r.presente_en_ultimo_corte=1 AND m.carga_id IS NULL");
            // JSON 21 posiciones; valor null -> SQL NULL. No reconstruir el estado a partir del Excel original.
            $cols=['cuenta'=>0,'fecha_activacion'=>1,'estatus_cta'=>2,'monto_factura'=>3,'tipo'=>4,'plaza'=>6,'distrito'=>7,'cluster'=>8,'grupo_distribuidor'=>9,'canal_venta'=>10,'tipo_cluster'=>11,'vendedor'=>12,'desc_plan'=>13,'jefe_plaza'=>14,'jefe_ventas'=>15,'region'=>16,'cosecha'=>17,'no_mes_original'=>18,'nom_mes'=>19];
            $extract=static fn(int $index): string=>"NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.datos_json,'$[$index]')),'null')";
            $set=[];$insertCol=[];$insertVal=[];
            foreach ($cols as $col=>$ix) {$set[]="r.`$col`=".$extract($ix);$insertCol[]="`$col`";$insertVal[]=$extract($ix);}
            $set[]='r.hash_negocio=m.hash_negocio';
            nppf_query($conexion,"UPDATE nppf_registros r JOIN nppf_carga_manifest m
                ON m.carga_id=$iid AND r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha
                JOIN nppf_carga_detalle d ON d.carga_id=m.carga_id AND d.fila_excel=m.fila_excel
                SET ".implode(',',$set)." WHERE r.hash_negocio<>m.hash_negocio");
            // Insertar filas realmente nuevas, con todas sus dimensiones.
            $insertCol=array_merge($insertCol,['cuenta_clave','anio_cosecha','mes_cosecha','atraso','hash_negocio','primer_carga_id','ultima_carga_id','primer_visto','ultimo_visto','presente_en_ultimo_corte']);
            $insertVal=array_merge($insertVal,['m.cuenta_clave','m.anio_cosecha','m.mes_cosecha','m.atraso','m.hash_negocio',(string)$iid,(string)$iid,"'$date'","'$date'",'1']);
            nppf_query($conexion,"INSERT INTO nppf_registros(".implode(',',$insertCol).") SELECT ".implode(',',$insertVal)."
                FROM nppf_carga_manifest m JOIN nppf_carga_detalle d ON d.carga_id=m.carga_id AND d.fila_excel=m.fila_excel
                LEFT JOIN nppf_registros r ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha
                WHERE m.carga_id=$iid AND r.id IS NULL");
            nppf_query($conexion,"INSERT INTO nppf_movimientos(carga_id,registro_id,cuenta_clave,anio_cosecha,mes_cosecha,tipo_movimiento,campos_modificados,antes,despues)
                SELECT $iid,r.id,m.cuenta_clave,m.anio_cosecha,m.mes_cosecha,'ALTA','Primer registro en TotalXpedient',NULL,
                JSON_OBJECT('tipo',r.tipo,'atraso',m.atraso,'fecha_activacion',r.fecha_activacion)
                FROM nppf_carga_manifest m JOIN nppf_registros r
                ON r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha
                WHERE m.carga_id=$iid AND r.primer_carga_id=$iid");
            // Cortes completos actualizan la presencia y el atraso de todas las cuentas existentes.
            nppf_query($conexion,"UPDATE nppf_registros r JOIN nppf_carga_manifest m
                ON m.carga_id=$iid AND r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha
                SET r.atraso=m.atraso,r.presente_en_ultimo_corte=1,r.ultima_carga_id=$iid,r.ultimo_visto='$date',r.baja_carga_id=NULL,r.baja_fecha=NULL");
            nppf_query($conexion,"UPDATE nppf_registros r LEFT JOIN nppf_carga_manifest m
                ON m.carga_id=$iid AND r.cuenta_clave=m.cuenta_clave AND r.anio_cosecha=m.anio_cosecha AND r.mes_cosecha=m.mes_cosecha
                SET r.presente_en_ultimo_corte=0,r.baja_carga_id=$iid,r.baja_fecha='$date'
                WHERE r.presente_en_ultimo_corte=1 AND m.carga_id IS NULL");
            $v=[(int)($stats['altas']??0),(int)($stats['negocio']??0),(int)($stats['atraso']??0),(int)($gone['n']??0),(int)($stats['reapariciones']??0)];
            $message='Corte nacional publicado: '.$manifest.' cuentas; SUR disponible en vw_nppf_sur_actual. REVERSO incluido como NPPF pendiente.';
            $s=nppf_stmt($conexion,"UPDATE nppf_cargas SET estado='OK', altas=?,actualizaciones_negocio=?,actualizaciones_atraso=?,bajas=?,reapariciones=?,mensaje=?,finalizado_en=NOW() WHERE id=?");
            mysqli_stmt_bind_param($s,'iiiiisi',$v[0],$v[1],$v[2],$v[3],$v[4],$message,$id);nppf_exec($s);mysqli_stmt_close($s);
            nppf_query($conexion,'COMMIT');$inTransaction=false;
            nppf_query($conexion,"SELECT RELEASE_LOCK('totalxpedient_nppf_publicar')");$haveLock=false;
            nppf_out(['ok'=>true,'carga_id'=>$id,'total'=>$manifest,'altas'=>$v[0],'cambios_negocio'=>$v[1],'cambios_atraso'=>$v[2],'bajas'=>$v[3],'reapariciones'=>$v[4],'indicadores'=>$ind,'mensaje'=>$message]);
        }
        throw new RuntimeException('Acción desconocida: '.$action);
    } catch (Throwable $e) {
        if ($inTransaction) @mysqli_rollback($conexion);
        if ($haveLock) @mysqli_query($conexion,"SELECT RELEASE_LOCK('totalxpedient_nppf_publicar')");
        nppf_out(['ok'=>false,'mensaje'=>$e->getMessage()],400);
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TalIA · Importador NPPF</title>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-sha256@0.11.1/build/sha256.min.js"></script>
<style>
:root{--green:#197a43;--dark:#173f31;--muted:#667085;--bg:#f2f6f4;--border:#dce7df}*{box-sizing:border-box}body{margin:0;background:var(--bg);font:15px/1.5 'Segoe UI',Arial,sans-serif;color:#173b2c;padding:28px}.card{background:#fff;max-width:920px;margin:auto;border:1px solid var(--border);border-radius:20px;padding:28px 30px;box-shadow:0 10px 30px #163d2620}h1{margin:0;font-size:26px;letter-spacing:-.4px}.subtitle{color:var(--muted);margin:5px 0 15px}.meta{font-size:12px;font-weight:750;letter-spacing:.05em;color:var(--green)}.drop{border:2px dashed #60a67b;background:#f4fcf7;border-radius:14px;padding:26px;cursor:pointer;display:block;text-align:center}.drop strong{font-size:17px;display:block}.drop small{display:block;color:var(--muted);margin:6px}input[type=file]{display:none}button{width:100%;margin:14px 0 0;border:0;background:linear-gradient(110deg,#1f854c,#144d37);padding:14px;border-radius:10px;color:#fff;font-size:15px;font-weight:700;cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}.info{margin-top:16px;color:var(--muted);font-size:13px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:15px}.tile{border:1px solid var(--border);border-radius:10px;padding:12px;background:#fcfffd}.tile b{display:block;font-size:20px}.tile small{color:var(--muted)}.status{display:none;margin-top:18px;padding:12px 14px;border-radius:10px;white-space:pre-wrap}.status.on{display:block}.status.good{background:#e7f7eb;color:#155c31}.status.err{background:#fdf0ee;color:#a42e24}.status.working{background:#eff6fc;color:#22517a}.track{background:#e2e9e6;height:15px;border-radius:10px;overflow:hidden;margin-top:18px;display:none}.bar{height:100%;width:0;background:linear-gradient(100deg,#48ad6a,#177343);transition:width .16s}.progress{color:#576f61;font-size:13px;margin-top:5px}.hint{border-top:1px solid var(--border);padding-top:16px;color:var(--muted);font-size:12px;margin-top:20px}[hidden]{display:none!important}.warn{font-size:13px;margin-top:12px;color:#815315;background:#fff8e8;border-radius:8px;padding:10px}@media(max-width:650px){.summary{grid-template-columns:repeat(2,1fr)}body{padding:10px}.card{padding:18px}}
</style>
</head>
<body>
<main class="card">
<div class="meta">TOTALXPEDIENT / TALIA · NPPF v1.0.1</div>
<h1>Importación diferencial de NPPF</h1>
<p class="subtitle">Corte nacional acumulado · Cuentas pendientes · Indicadores oficiales por cosecha · Importación por lotes recuperable</p>
<label for="file" class="drop"><strong>Selecciona el archivo NPPF (.xlsb)</strong><small id="fileName">Formato AAAAMMDD_NPPF 2019 - 2026.xlsb</small></label>
<input id="file" type="file" accept=".xlsb">
<div class="summary" id="summary" hidden>
<div class="tile"><small>Fecha del corte</small><b id="cutDate">—</b></div>
<div class="tile"><small>Cuentas nacionales</small><b id="records">—</b></div>
<div class="tile"><small>Región SUR</small><b id="sur">—</b></div>
<div class="tile"><small>Indicadores oficiales</small><b id="indicators">—</b></div>
</div>
<p class="warn" id="warning" hidden></p>
<button id="start" disabled>Importar y publicar NPPF</button>
<div class="track" id="track"><div class="bar" id="bar"></div></div>
<div class="progress" id="progress"></div>
<div class="status" id="status"></div>
<div class="hint">La carga conserva el histórico de cuentas, guarda su presencia en cada corte y sincroniza atraso sin generar falsos movimientos. Una cuenta que desaparece se marca como salida del reporte, NO como pago confirmado. <b>TIPO=REVERSO</b> se importa tal como viene, sin descartarlo ni interpretarlo.</div>
</main>
<script>
'use strict';
const CSRF=<?php echo json_encode($csrf); ?>;
const BATCH=1500;
const $=id=>document.getElementById(id);
const MONTHS=['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
const COLS=['CUENTA','FECHA ACTIVACION','ESTATUS_CTA','MONTO_FACTURA','TIPO','ATRASO','PLAZA','DISTRITO','CLUSTER','GRUPODISTRIBUIDOR','CANALVENTA','TIPO_CLUSTER','VENDEDOR','DESC_PLAN','JEFE_PLAZA','JEFE_VENTAS','REGION','COSECHA','NO MES','NOM MES','ANO COSECHA'];
let documentFile=null, records=[], manifest=[], official=[], fileHash='', cutDate='', empty=0, sourceRows=0, countSur=0;
const norm=v=>String(v??'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toUpperCase().replace(/[^A-Z0-9]/g,'');
const trim=v=>v==null?'':String(v).trim();
const txt=v=>trim(v)===''?null:trim(v);
const number=v=>{if(v==null||trim(v)==='')return null;const n=Number(String(v).replace(/,/g,''));return Number.isFinite(n)?n:null};
const key=v=>trim(v).replace(/\D/g,'').replace(/^0+(?=\d)/,'');
const formatDate=v=>{
 if(v==null||v==='')return null;
 if(typeof v==='number'){
  const dt=XLSX.SSF.parse_date_code(v);
  if(!dt||!dt.y||!dt.m||!dt.d)return null;
  return `${String(dt.y).padStart(4,'0')}-${String(dt.m).padStart(2,'0')}-${String(dt.d).padStart(2,'0')}`;
 }
 if(v instanceof Date&&!isNaN(v))return `${v.getFullYear()}-${String(v.getMonth()+1).padStart(2,'0')}-${String(v.getDate()).padStart(2,'0')}`;
 const s=trim(v);
 if(/^\d{4}-\d{2}-\d{2}/.test(s))return s.slice(0,10);
 let m=s.match(/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/);
 return m?`${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`:null;
};
function asFields(row,cols){
 const a=COLS.map((_,i)=>txt(row[cols[i]]));
 a[1]=formatDate(row[cols[1]]);
 const val=number(row[cols[3]]);a[3]=val===null?null:val.toFixed(2);
 const atraso=number(row[cols[5]]);a[5]=atraso===null?null:String(Math.round(atraso));
 const y=number(row[cols[20]]);a[20]=y===null?null:String(Math.round(y));
 const nm=trim(row[cols[18]]);a[18]=nm||null;
 return a;
}
function validateAndHash(f,sourceRow){
 if(!f[0]||!/^\d+$/.test(f[0]))throw Error(`Cuenta inválida en fila Excel ${sourceRow}: ${f[0]}`);
 if(!f[1])throw Error(`FECHA ACTIVACION inválida en fila ${sourceRow}`);
 const y=Number(f[20]), month=Number((f[18]||'').match(/^\d{1,2}/)?.[0]);
 if(!Number.isInteger(y)||y<2019||y>2100||!Number.isInteger(month)||month<1||month>12)throw Error(`Cosecha inválida en fila ${sourceRow}. Año ${f[20]} / NO MES ${f[18]}`);
 if(!f[16])throw Error(`REGIÓN vacía en fila ${sourceRow}`);
 // ATRASO es volátil: se sincroniza aparte sin marcar cambio sustantivo.
 const digest=sha256(JSON.stringify(f.filter((_,i)=>i!==5)));
 return {cuenta:key(f[0]),year:y,month,atraso:f[5]===null?null:Number(f[5]),digest};
}
function msg(t,type='working'){$('status').className='status on '+type;$('status').textContent=t;}
function progress(text,done,total){$('track').style.display='block';$('bar').style.width=(total?Math.min(100,100*done/total):0).toFixed(1)+'%';$('progress').textContent=`${text} · ${done.toLocaleString('es-MX')} / ${total.toLocaleString('es-MX')}`;}
function pulse(){return new Promise(resolve=>setTimeout(resolve,0));}
function dateFromName(file){let m=file.match(/^(20\d{2})(\d{2})(\d{2})_NPPF\b/i);let y,mo,d;
 if(m){[,y,mo,d]=m;}else{m=file.match(/^(\d{2})(\d{2})(20\d{2})_NPPF\b/i);if(!m)throw Error('Nombre de archivo no reconocido. Debe iniciar AAAAMMDD_NPPF ...');[,d,mo,y]=m;}
 const date=`${y}-${mo}-${d}`,parsed=new Date(`${date}T12:00:00Z`);
 if(!Number.isFinite(parsed.getTime())||parsed.toISOString().slice(0,10)!==date)throw Error('Fecha de corte inválida en el archivo.');return date;
}
function buildIndicators(sheet){
 if(!sheet)throw Error('Falta la hoja 1.0 NPPF.');
 // Solo lectura acotada: la hoja contiene formato sobrante (16 mil filas x 283 columnas).
 const grid=XLSX.utils.sheet_to_json(sheet,{header:1,raw:true,defval:null,blankrows:true,range:'A1:AK150'});
 const mark=grid.findIndex(row=>Number(row[4])===2026 && norm(row[34]).includes('INST2026'));
 if(mark<0)throw Error('No se encontró matriz de indicadores 2026 en la hoja 1.0 NPPF.');
 const monthHeaders=grid[mark+1]||[];let months=[];
 for(let j=4;j<=33;j+=3){const mm=MONTHS.indexOf(String(monthHeaders[j]??'').trim().toUpperCase());if(mm>=0)months.push({month:mm+1,col:j});}
 if(months.length===0)throw Error('No se reconocieron los meses en 1.0 NPPF.');
 const result=[];let region='';let foundNation=false;
 for(let i=mark+3;i<grid.length;i++){
  const r=grid[i]||[];
  if(r[1]&&norm(r[1])!=='REGION'){region=trim(r[1]);if(norm(region)==='TOTALGENERAL'){region='';foundNation=true;}}
  let level=null,district='';
  if(r[1]&&norm(r[1])!=='REGION')level=foundNation?'NACIONAL':'REGION';
  else if(r[3] && region){level='DISTRITO';district=trim(r[3]);}
  else continue; // Omitir DIVISION: replica datos REGION y duplicaría totales.
  if(!level)continue;
  for(const def of months){
   const inst=number(r[def.col]),bad=number(r[def.col+1]),pct=number(r[def.col+2]);
   if(inst===null||bad===null||pct===null)continue;
   if(inst<0||bad<0||pct<0)throw Error('Indicadores inválidos en fila '+(i+1));
   result.push([2026,def.month,level,level==='NACIONAL'?'':region,district,Math.round(inst),Math.round(bad),pct]);
  }
  if(foundNation)break;
 }
 if(!result.some(a=>a[2]==='REGION'&&norm(a[3])==='SUR'))throw Error('No se identificó Región SUR en indicadores 2026.');
 return result;
}
async function api(action,p){const res=await fetch(location.pathname+'?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(p)});const t=await res.text();let v;try{v=JSON.parse(t);}catch{throw Error('Error del servidor (respuesta no JSON): '+t.slice(0,350));}if(!res.ok||!v.ok)throw Error(v.mensaje||'Fallo al importar');return v;}
$('file').addEventListener('change',async()=>{
 documentFile=$('file').files?.[0];records=[];manifest=[];official=[];fileHash='';$('start').disabled=true;$('summary').hidden=true;$('warning').hidden=true;$('track').style.display='none';$('progress').textContent='';
 if(!documentFile)return;
 $('fileName').textContent=documentFile.name;
 try{
  if(typeof XLSX==='undefined'||typeof sha256==='undefined')throw Error('No se pudieron cargar los componentes XLSB y SHA-256. Revisa conexión a Internet.');
  if(!/\.xlsb$/i.test(documentFile.name))throw Error('Selecciona un archivo .xlsb');
  cutDate=dateFromName(documentFile.name);
  msg('Leyendo archivo binario y calculando SHA-256…');
  const buf=await documentFile.arrayBuffer();
  const dg=await crypto.subtle.digest('SHA-256',buf);
  fileHash=[...new Uint8Array(dg)].map(b=>b.toString(16).padStart(2,'0')).join('');
  const wb=XLSX.read(buf,{type:'array',cellDates:false,cellText:true,cellNF:true,sheets:['2.0 DDBB NPPF QS','1.0 NPPF']});
  const detailName=wb.SheetNames.find(n=>norm(n)==='2.0DDBBNPPFQS');
  const indicatorName=wb.SheetNames.find(n=>norm(n)==='1.0NPPF');
  if(!detailName || !wb.Sheets[detailName])throw Error('No se encontró la hoja 2.0 DDBB NPPF QS.');
  official=buildIndicators(wb.Sheets[indicatorName]);
  msg('Validando cuentas y construyendo comparador diferencial…');await pulse();
  const raw=XLSX.utils.sheet_to_json(wb.Sheets[detailName],{header:1,defval:null,raw:true,blankrows:true});
   // Detectar fila de encabezados; el origen tiene 21 columnas con orden conocido.
   const headerRow=raw.findIndex((r,i)=>i<10 && norm(r?.[0])==='CUENTA' && norm(r?.[2])==='ESTATUSCTA' && norm(r?.[20])==='ANOCOSECHA');
   if(headerRow<0)throw Error('No se identificaron los 21 encabezados de la hoja de detalle. Revisa el formato del archivo.');
   const head=(raw[headerRow]||[]).map(norm);
  const c=COLS.map(h=>head.indexOf(norm(h)));
   let dateColFallback=false;
   // Algunos XLSB presentan anomalías al exponer B1 al convertirlos a matriz.
   // Solo recuperar B por posición si las otras 20 columnas están EXACTAMENTE alineadas
   // y las primeras filas de B contienen fechas reales de instalación.
   if(c[1]<0 && c.every((ix,j)=>j===1 || ix===j)){
     const probe=raw.slice(headerRow+1,headerRow+51).find(r=>r && trim(r[0])!=='' && r[1]!==null && r[1]!==undefined);
     if(probe && formatDate(probe[1])){
       c[1]=1;
       dateColFallback=true;
     }
   }
  const missing=c.map((ix,i)=>ix<0?COLS[i]:null).filter(Boolean);
   if(missing.length){
     const seen=(raw[headerRow]||[]).slice(0,21).map((v,i)=>`${i+1}:${JSON.stringify(v??null)}`).join(' | ');
     throw Error('Columnas no reconocidas: '+missing.join(', ')+'. Encabezados detectados: '+seen);
   }
   let duplicates=new Set();empty=0;countSur=0;sourceRows=Math.max(0,raw.length-headerRow-1);
   for(let i=headerRow+1;i<raw.length;i++){
   const v=raw[i]||[];
   if(v.every(x=>x===null || trim(x)==='')){empty++;continue;}
   if(trim(v[c[0]])===''){
    // La fuente puede tener filas vacías de formato, pero no registros activos sin cuenta.
    if(v.filter(x=>x!==null&&trim(x)!=='').length>2)throw Error('Fila '+(i+1)+' contiene datos sin CUENTA.');
    empty++;continue;
   }
   const f=asFields(v,c);
   // Conservar ceros a la izquierda cuando el formato de la celda de CUENTA los muestre.
   const cell=wb.Sheets[detailName]['A'+(i+1)];
   if(cell && cell.w && /^\d+$/.test(trim(cell.w)) && cell.w.length>=String(f[0]).length)f[0]=trim(cell.w);
   const x=validateAndHash(f,i+1);
   const uniq=x.cuenta+'|'+x.year+'|'+x.month;
   if(duplicates.has(uniq))throw Error('Cuenta/cosecha duplicada en Excel, fila '+(i+1)+': '+uniq);
   duplicates.add(uniq);
   if(norm(f[16])==='SUR')countSur++;
   const pos=records.length+1;
   records.push(f);
   manifest.push([pos,x.cuenta,x.year,x.month,x.atraso,x.digest]);
   if(pos%20000===0){msg('Preparadas '+pos.toLocaleString('es-MX')+' cuentas…');await pulse();}
  }
  duplicates=null;
  if(records.length<1)throw Error('No hay cuentas que importar.');
  if(records.length>1000000)throw Error('Excede máximo de 1 millón de registros.');
  $('summary').hidden=false;$('cutDate').textContent=cutDate;$('records').textContent=records.length.toLocaleString('es-MX');$('sur').textContent=countSur.toLocaleString('es-MX');$('indicators').textContent=official.length.toLocaleString('es-MX');
  $('warning').hidden=false;$('warning').textContent=`Se ignoraron ${empty.toLocaleString('es-MX')} filas vacías. El importador detecta altas, modificaciones, salidas y reapariciones. REVERSO permanece incluido en NPPF. La fecha del archivo, no la fecha de carga, identifica el corte.${dateColFallback?' Se reconoció FECHA ACTIVACION por posición B, validando fechas de instalación.':''}`;
  msg('Archivo validado y preparado. Puedes comenzar la importación.','good');$('start').disabled=false;
 }catch(e){msg(e.message||String(e),'err');}
});
$('start').addEventListener('click',async()=>{
 if(!documentFile||!manifest.length)return;
 $('start').disabled=true;
 let id=0,stage='Preparación';
 try{
  stage='Iniciar';msg('Iniciando corte nacional…');
  const init=await api('init',{archivo:documentFile.name,hash:fileHash,total:manifest.length,filas:sourceRows,vacias:empty,indicadores:official.length});
  id=init.carga_id;
  if(init.repetido){msg(init.mensaje+' Verificando temporales…','good');while(true){const clean=await api('cleanup',{carga_id:id});if(clean.faltan===0)break;}msg(init.mensaje+' Temporales depurados.','good');return;}
  let done=init.manifest_recibido||0;
  stage='Enviar manifiesto';
  while(done<manifest.length){const end=Math.min(done+BATCH,manifest.length);const out=await api('manifest_batch',{carga_id:id,offset:done,rows:manifest.slice(done,end)});done=out.total;progress('Manifiesto recibido',done,manifest.length);}
  const info=await api('manifest_finish',{carga_id:id});
  stage='Enviar diferencias';msg(`Manifiesto listo. ${info.detalles_necesarios.toLocaleString('es-MX')} detalles necesitan sincronización.`);
  let after=0,scanned=0;
  // Si hubo interrupción previa, need_details omite filas ya recibidas: reanudable.
  while(true){
   const list=await api('need_details',{carga_id:id,after,limit:BATCH});
   if(!list.indices.length)break;
   const arr=list.indices.map(ix=>[ix,records[ix-1]]);
   const res=await api('detail_batch',{carga_id:id,rows:arr});
   after=list.indices[list.indices.length-1];scanned+=list.indices.length;
   progress('Detalles diferenciales enviados',scanned,Math.max(scanned,info.detalles_necesarios));
  }
  stage='Indicadores';msg('Sincronizando los indicadores oficiales de 2026…');
  const up=await api('indicators',{carga_id:id,rows:official});
  stage='Publicación';msg('Validando y publicando el corte completo en MySQL…');
  const result=await api('publish',{carga_id:id});
  progress('Corte publicado',manifest.length,manifest.length);
  msg(`✅ Publicado corte ${cutDate} · ${result.total.toLocaleString('es-MX')} cuentas.\nAltas: ${result.altas}; Cambios de negocio: ${result.cambios_negocio}; Solo atraso: ${result.cambios_atraso}; Salidas: ${result.bajas}; Reapariciones: ${result.reapariciones}.\nIndicadores: ${up.indicadores}.\nLimpiando información temporal…`,'good');
  stage='Limpieza';let totalClean=0;
  while(true){const clean=await api('cleanup',{carga_id:id});totalClean+=clean.eliminados;if(clean.faltan===0)break;if(totalClean%30000===0)await pulse();}
  msg(`✅ NPPF publicado y staging depurado. Corte ${cutDate}.\nAltas: ${result.altas} | Cambios de negocio: ${result.cambios_negocio} | Solo atraso: ${result.cambios_atraso} | Salidas: ${result.bajas} | Reapariciones: ${result.reapariciones}.\nRegión SUR disponible en las vistas vigentes.`,'good');
 }catch(e){
  msg(`Error durante ${stage}: ${e.message||String(e)}\n\nPuedes volver a seleccionar exactamente el mismo archivo y reanudar. Si el error fue durante limpieza, el corte YA está publicado; solo faltó depurar temporales. Una carga fallida antes de publicar NO modifica los datos vigentes.`, 'err');
  if(id && stage!=='Limpieza')try{await api('mark_error',{carga_id:id,mensaje:stage+': '+String(e.message||e)});}catch(_){/* no esconder error original */}
 }finally{$('start').disabled=false;}
});
</script>
</body>
</html>
