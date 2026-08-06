<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

header('X-Content-Type-Options: nosniff');

function norm_header($v): string {
    $s = mb_strtoupper(trim((string)$v), 'UTF-8');
    $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
}
function txt($v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}
function entero($v): ?int {
    if ($v === null || $v === '') return null;
    $s = str_replace([',',' '], '', (string)$v);
    return is_numeric($s) ? (int)round((float)$s) : null;
}
function decimal($v): ?float {
    if ($v === null || $v === '') return null;
    $s = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$v));
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}
function fecha_mysql($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $dias = (int)floor((float)$v);
        $dt = new DateTime('1899-12-30');
        $dt->modify('+' . $dias . ' days');
        return $dt->format('Y-m-d');
    }
    $s = trim((string)$v);
    foreach (['Y-m-d','d/m/Y','d-m-Y','m/d/Y'] as $f) {
        $dt = DateTime::createFromFormat('!' . $f, $s);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

// Endpoint JSON: el navegador lee el XLSB con SheetJS y envía solamente la hoja Pronóstico.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'import_json') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $rows = $payload['rows'] ?? null;
        $archivo = basename((string)($payload['archivo'] ?? 'pronostico.xlsb'));
        $hash = preg_replace('/[^a-f0-9]/i', '', (string)($payload['hash'] ?? ''));
        if (!is_array($rows) || count($rows) < 2) throw new RuntimeException('No se recibieron registros válidos de la hoja Pronóstico.');
        if (count($rows) > 250000) throw new RuntimeException('El archivo excede el límite de seguridad de 250,000 filas.');

        $mapEsperado = [
            'CUENTA'=>'cuenta','PLAZA'=>'plaza','DISTRITO'=>'distrito','CLUSTER'=>'cluster','REGION'=>'region',
            'ESTATUS_CTA'=>'estatus_cta','ATRASO'=>'atraso','NSE'=>'nse','FECHA_ACTIVACION'=>'fecha_activacion',
            'FECHA_CANCELACION'=>'fecha_cancelacion','TIPO_CANCELACION'=>'tipo_cancelacion','CICLO'=>'ciclo',
            'FECHA_PRONOSTICO'=>'fecha_pronostico','SEMANA_PRONOSTICO'=>'semana_pronostico','RELOJ'=>'reloj',
            'SALDO_SERV'=>'saldo_serv','SERVICIO_TERCEROS'=>'servicio_terceros','SERVICIOS_TOTALPLAY'=>'servicios_totalplay',
            'NOMBRE_PLAN'=>'nombre_plan','SUB_CANAL'=>'sub_canal','TIPO_PAGO'=>'tipo_pago','FECHA FINAL'=>'fecha_final',
            'SEMANA FINAL'=>'semana_final','RENTA'=>'renta','SUBESTATUS'=>'subestatus'
        ];

        $headers = array_map('norm_header', $rows[0]);
        $idx = [];
        foreach ($headers as $i=>$h) if ($h !== '') $idx[$h] = $i;
        $faltantes = array_values(array_diff(array_keys($mapEsperado), array_keys($idx)));
        if ($faltantes) throw new RuntimeException('Faltan columnas requeridas: ' . implode(', ', $faltantes));

        // Hash repetido = misma extracción; evita duplicar snapshots idénticos.
        if ($hash !== '') {
            $stmtHash = mysqli_prepare($conexion, "SELECT id, estado FROM pronostico_cancelaciones_cargas WHERE hash_archivo=? LIMIT 1");
            mysqli_stmt_bind_param($stmtHash, 's', $hash);
            mysqli_stmt_execute($stmtHash);
            $rh = mysqli_stmt_get_result($stmtHash);
            if ($rowh = mysqli_fetch_assoc($rh)) {
                mysqli_stmt_close($stmtHash);
                echo json_encode(['ok'=>true,'repetido'=>true,'carga_id'=>(int)$rowh['id'],'mensaje'=>'Este archivo ya fue importado anteriormente.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            mysqli_stmt_close($stmtHash);
        }

        $usuario = $_SESSION['usuario'] ?? $_SESSION['username'] ?? 'sistema';
        $recibidos = count($rows) - 1;
        mysqli_begin_transaction($conexion);

        $stmtCarga = mysqli_prepare($conexion, "INSERT INTO pronostico_cancelaciones_cargas (archivo_origen,hash_archivo,hoja_origen,registros_recibidos,usuario,estado) VALUES (?,?, 'Pronóstico', ?, ?, 'PROCESANDO')");
        $hashDb = $hash !== '' ? $hash : null;
        mysqli_stmt_bind_param($stmtCarga, 'ssis', $archivo, $hashDb, $recibidos, $usuario);
        if (!mysqli_stmt_execute($stmtCarga)) throw new RuntimeException(mysqli_stmt_error($stmtCarga));
        $cargaId = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmtCarga);

        $sql = "INSERT INTO pronostico_cancelaciones (
            carga_id,cuenta,plaza,distrito,cluster,region,estatus_cta,atraso,nse,fecha_activacion,
            fecha_cancelacion,tipo_cancelacion,ciclo,fecha_pronostico,semana_pronostico,reloj,saldo_serv,
            servicio_terceros,servicios_totalplay,nombre_plan,sub_canal,tipo_pago,fecha_final,semana_final,renta,subestatus
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = mysqli_prepare($conexion, $sql);
        if (!$stmt) throw new RuntimeException(mysqli_error($conexion));

        $importados = 0; $omitidos = 0; $cuentasVistas = [];
        for ($r=1, $n=count($rows); $r<$n; $r++) {
            $fila = $rows[$r];
            if (!is_array($fila)) { $omitidos++; continue; }
            $g = function($h) use ($fila,$idx) { $i=$idx[$h] ?? null; return $i===null ? null : ($fila[$i] ?? null); };
            $cuenta = txt($g('CUENTA'));
            if ($cuenta === null) { $omitidos++; continue; }
            // El snapshot debe tener una sola fila por cuenta. Si el origen repite, conserva la primera y reporta omitida.
            if (isset($cuentasVistas[$cuenta])) { $omitidos++; continue; }
            $cuentasVistas[$cuenta] = true;

            $plaza=txt($g('PLAZA')); $distrito=txt($g('DISTRITO')); $cluster=txt($g('CLUSTER')); $region=txt($g('REGION'));
            $estatus=txt($g('ESTATUS_CTA')); $atraso=entero($g('ATRASO')); $nse=txt($g('NSE'));
            $fa=fecha_mysql($g('FECHA_ACTIVACION')); $fc=fecha_mysql($g('FECHA_CANCELACION')); $tc=txt($g('TIPO_CANCELACION'));
            $ciclo=entero($g('CICLO')); $fp=fecha_mysql($g('FECHA_PRONOSTICO')); $sp=entero($g('SEMANA_PRONOSTICO')); $reloj=txt($g('RELOJ'));
            $saldo=decimal($g('SALDO_SERV')); $st=decimal($g('SERVICIO_TERCEROS')); $tp=decimal($g('SERVICIOS_TOTALPLAY'));
            $plan=txt($g('NOMBRE_PLAN')); $subcanal=txt($g('SUB_CANAL')); $tipopago=txt($g('TIPO_PAGO'));
            $ff=fecha_mysql($g('FECHA FINAL')); $sf=entero($g('SEMANA FINAL')); $renta=decimal($g('RENTA')); $subestatus=txt($g('SUBESTATUS'));

            mysqli_stmt_bind_param($stmt, 'isssssisisisisiisdddssssids',
                $cargaId,$cuenta,$plaza,$distrito,$cluster,$region,$estatus,$atraso,$nse,$fa,$fc,$tc,$ciclo,$fp,$sp,$reloj,
                $saldo,$st,$tp,$plan,$subcanal,$tipopago,$ff,$sf,$renta,$subestatus
            );
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Fila '.($r+1).': '.mysqli_stmt_error($stmt));
            $importados++;
        }
        mysqli_stmt_close($stmt);

        $stmtFin = mysqli_prepare($conexion, "UPDATE pronostico_cancelaciones_cargas SET registros_importados=?, estado='OK', mensaje=?, finalizado_en=CURRENT_TIMESTAMP WHERE id=?");
        $msg = "Importados: $importados | Omitidos: $omitidos";
        mysqli_stmt_bind_param($stmtFin, 'isi', $importados, $msg, $cargaId);
        mysqli_stmt_execute($stmtFin);
        mysqli_stmt_close($stmtFin);

        mysqli_commit($conexion);
        echo json_encode(['ok'=>true,'carga_id'=>$cargaId,'importados'=>$importados,'omitidos'=>$omitidos,'mensaje'=>$msg], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (isset($conexion)) @mysqli_rollback($conexion);
        http_response_code(400);
        echo json_encode(['ok'=>false,'mensaje'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Importar Pronóstico de Cancelaciones</title>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<style>
*{box-sizing:border-box}body{font-family:Segoe UI,Arial,sans-serif;background:#f4f6fb;margin:0;padding:32px;color:#17213a}.card{max-width:760px;margin:auto;background:#fff;border-radius:18px;padding:28px;box-shadow:0 14px 35px rgba(30,40,80,.10)}h2{margin:0 0 8px}.sub{color:#667085;margin:0 0 22px;line-height:1.5}.drop{border:2px dashed #8b2cff;border-radius:16px;padding:34px;text-align:center;cursor:pointer;background:#fbf8ff}.drop strong{display:block;font-size:1.05rem;margin-bottom:7px}input[type=file]{display:none}button{margin-top:16px;width:100%;border:0;border-radius:12px;padding:13px;background:linear-gradient(135deg,#7A2BFF,#FF006C);color:#fff;font-weight:800;cursor:pointer}button:disabled{opacity:.55;cursor:not-allowed}.msg{margin-top:18px;padding:13px;border-radius:12px;display:none}.ok{display:block;background:#ecfdf3;color:#166534}.err{display:block;background:#fef2f2;color:#991b1b}.info{display:block;background:#eff6ff;color:#1e40af}.small{font-size:.82rem;color:#667085;margin-top:8px}.preview{margin-top:18px;font-size:.9rem;color:#344054}.preview code{background:#f2f4f7;padding:2px 5px;border-radius:5px}</style>
</head>
<body>
<div class="card">
<h2>Importar Pronóstico de Cancelaciones</h2>
<p class="sub">Acepta directamente el archivo <b>.xlsb</b> protegido. El navegador lee la hoja <b>Pronóstico</b> sin modificar el libro y envía los registros a MySQL como un snapshot auditable.</p>
<label for="archivo"><div class="drop" id="drop"><strong>Selecciona el archivo .xlsb</strong><span id="nombre">Pronóstico cancelaciones...</span></div></label>
<input type="file" id="archivo" accept=".xlsb,.xlsx">
<button id="btn" disabled>Importar pronóstico</button>
<div id="preview" class="preview"></div><div id="msg" class="msg"></div>
</div>
<script>
const fileInput=document.getElementById('archivo'), btn=document.getElementById('btn'), msg=document.getElementById('msg'), preview=document.getElementById('preview'), nombre=document.getElementById('nombre');
let file=null, rows=null, hash='';
function show(text,cls){msg.className='msg '+cls;msg.textContent=text}
fileInput.addEventListener('change', async()=>{
  file=fileInput.files[0]; rows=null; btn.disabled=true; preview.textContent='';
  if(!file)return; nombre.textContent=file.name; show('Leyendo libro protegido…','info');
  try{
    const ab=await file.arrayBuffer();
    const digest=await crypto.subtle.digest('SHA-256',ab); hash=[...new Uint8Array(digest)].map(b=>b.toString(16).padStart(2,'0')).join('');
    const wb=XLSX.read(ab,{type:'array',cellDates:false});
    const sheetName=wb.SheetNames.find(n=>n.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase()==='PRONOSTICO');
    if(!sheetName) throw new Error('No se encontró la hoja Pronóstico. Hojas detectadas: '+wb.SheetNames.join(', '));
    rows=XLSX.utils.sheet_to_json(wb.Sheets[sheetName],{header:1,defval:null,raw:true});
    if(rows.length<2) throw new Error('La hoja Pronóstico no contiene datos.');
    preview.innerHTML=`Hoja: <code>${sheetName}</code> · Filas detectadas: <b>${(rows.length-1).toLocaleString()}</b>`;
    show('Archivo validado. Listo para importar.','ok'); btn.disabled=false;
  }catch(e){show(e.message||String(e),'err')}
});
btn.addEventListener('click',async()=>{
  if(!file||!rows)return; btn.disabled=true; show('Importando snapshot a MySQL…','info');
  try{
    const res=await fetch('?action=import_json',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({archivo:file.name,hash,rows})});
    const data=await res.json(); if(!res.ok||!data.ok) throw new Error(data.mensaje||'Error de importación');
    show(data.mensaje+(data.repetido?' (sin duplicar snapshot)':''),'ok');
  }catch(e){show(e.message||String(e),'err')}finally{btn.disabled=false}
});
</script>
</body></html>
