<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

header('X-Content-Type-Options: nosniff');
mysqli_set_charset($conexion, 'utf8mb4');

const PC_BATCH_MAX = 2500;      // Máximo aceptado por petición al servidor.
const PC_TOTAL_MAX = 1000000;   // Candado de seguridad del snapshot completo.

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function payload_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        throw new RuntimeException('No se recibió información en la petición.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('La petición JSON no es válida.');
    }
    return $data;
}

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

function headers_esperados(): array {
    return [
        'CUENTA','PLAZA','DISTRITO','CLUSTER','REGION','ESTATUS_CTA','ATRASO','NSE',
        'FECHA_ACTIVACION','FECHA_CANCELACION','TIPO_CANCELACION','CICLO','FECHA_PRONOSTICO',
        'SEMANA_PRONOSTICO','RELOJ','SALDO_SERV','SERVICIO_TERCEROS','SERVICIOS_TOTALPLAY',
        'NOMBRE_PLAN','SUB_CANAL','TIPO_PAGO','FECHA FINAL','SEMANA FINAL','RENTA','SUBESTATUS'
    ];
}

function construir_indices(array $headers): array {
    $normalizados = array_map('norm_header', $headers);
    $idx = [];
    foreach ($normalizados as $i => $h) {
        if ($h !== '') $idx[$h] = $i;
    }
    $faltantes = array_values(array_diff(headers_esperados(), array_keys($idx)));
    if ($faltantes) {
        throw new RuntimeException('Faltan columnas requeridas: ' . implode(', ', $faltantes));
    }
    return $idx;
}

function carga_por_id(mysqli $conexion, int $cargaId): ?array {
    $stmt = mysqli_prepare($conexion, "SELECT id, archivo_origen, hash_archivo, registros_recibidos, registros_importados, estado FROM pronostico_cancelaciones_cargas WHERE id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException(mysqli_error($conexion));
    mysqli_stmt_bind_param($stmt, 'i', $cargaId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

// API por lotes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    $action = (string)$_GET['action'];

    try {
        if ($action === 'init') {
            $p = payload_json();
            $archivo = basename((string)($p['archivo'] ?? 'pronostico.xlsb'));
            $hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)($p['hash'] ?? '')));
            $headers = $p['headers'] ?? null;
            $total = (int)($p['total'] ?? 0);

            if (!is_array($headers)) throw new RuntimeException('No se recibieron los encabezados de la hoja Pronóstico.');
            construir_indices($headers);
            if ($total < 1) throw new RuntimeException('El archivo no contiene registros para importar.');
            if ($total > PC_TOTAL_MAX) throw new RuntimeException('El archivo excede el límite de seguridad de ' . number_format(PC_TOTAL_MAX) . ' registros.');
            if ($hash !== '' && strlen($hash) !== 64) throw new RuntimeException('El hash SHA-256 del archivo no es válido.');

            // Si ya existe el mismo archivo, reutiliza la carga para permitir reanudación segura.
            if ($hash !== '') {
                $stmt = mysqli_prepare($conexion, "SELECT id, estado, registros_recibidos, registros_importados FROM pronostico_cancelaciones_cargas WHERE hash_archivo=? LIMIT 1");
                if (!$stmt) throw new RuntimeException(mysqli_error($conexion));
                mysqli_stmt_bind_param($stmt, 's', $hash);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $existente = mysqli_fetch_assoc($res) ?: null;
                mysqli_stmt_close($stmt);

                if ($existente) {
                    $cargaId = (int)$existente['id'];
                    if ($existente['estado'] === 'OK') {
                        json_out([
                            'ok' => true,
                            'repetido' => true,
                            'carga_id' => $cargaId,
                            'resume_from' => (int)$existente['registros_importados'],
                            'mensaje' => 'Este archivo ya fue importado correctamente.'
                        ]);
                    }

                    $resume = (int)$existente['registros_importados'];
                    if ($resume > $total) {
                        throw new RuntimeException('La carga previa tiene más registros de los que contiene el archivo actual.');
                    }

                    $stmtUp = mysqli_prepare($conexion, "UPDATE pronostico_cancelaciones_cargas SET archivo_origen=?, registros_recibidos=?, estado='PROCESANDO', mensaje='Reanudando carga por lotes', finalizado_en=NULL WHERE id=?");
                    if (!$stmtUp) throw new RuntimeException(mysqli_error($conexion));
                    mysqli_stmt_bind_param($stmtUp, 'sii', $archivo, $total, $cargaId);
                    mysqli_stmt_execute($stmtUp);
                    mysqli_stmt_close($stmtUp);

                    json_out([
                        'ok' => true,
                        'repetido' => false,
                        'reanudando' => true,
                        'carga_id' => $cargaId,
                        'resume_from' => $resume,
                        'mensaje' => 'Carga previa encontrada. Se reanudará desde el registro ' . number_format($resume + 1) . '.'
                    ]);
                }
            }

            $usuario = $_SESSION['usuario'] ?? $_SESSION['username'] ?? 'sistema';
            $hashDb = $hash !== '' ? $hash : null;
            $stmtCarga = mysqli_prepare($conexion, "INSERT INTO pronostico_cancelaciones_cargas (archivo_origen,hash_archivo,hoja_origen,registros_recibidos,registros_importados,usuario,estado,mensaje) VALUES (?,?, 'Pronóstico', ?, 0, ?, 'PROCESANDO', 'Carga iniciada por lotes')");
            if (!$stmtCarga) throw new RuntimeException(mysqli_error($conexion));
            mysqli_stmt_bind_param($stmtCarga, 'ssis', $archivo, $hashDb, $total, $usuario);
            if (!mysqli_stmt_execute($stmtCarga)) throw new RuntimeException(mysqli_stmt_error($stmtCarga));
            $cargaId = mysqli_insert_id($conexion);
            mysqli_stmt_close($stmtCarga);

            json_out([
                'ok' => true,
                'carga_id' => $cargaId,
                'resume_from' => 0,
                'mensaje' => 'Carga creada correctamente.'
            ]);
        }

        if ($action === 'batch') {
            $p = payload_json();
            $cargaId = (int)($p['carga_id'] ?? 0);
            $offset = (int)($p['offset'] ?? -1);
            $headers = $p['headers'] ?? null;
            $rows = $p['rows'] ?? null;

            if ($cargaId < 1) throw new RuntimeException('carga_id inválido.');
            if ($offset < 0) throw new RuntimeException('offset inválido.');
            if (!is_array($headers)) throw new RuntimeException('No se recibieron encabezados.');
            if (!is_array($rows) || count($rows) < 1) throw new RuntimeException('El lote está vacío.');
            if (count($rows) > PC_BATCH_MAX) throw new RuntimeException('El lote excede el máximo permitido de ' . PC_BATCH_MAX . ' registros.');

            $idx = construir_indices($headers);
            $carga = carga_por_id($conexion, $cargaId);
            if (!$carga) throw new RuntimeException('La carga solicitada no existe.');
            if ($carga['estado'] === 'OK') {
                json_out(['ok'=>true,'finalizada'=>true,'importados_total'=>(int)$carga['registros_importados'],'mensaje'=>'La carga ya estaba finalizada.']);
            }
            if ($carga['estado'] !== 'PROCESANDO' && $carga['estado'] !== 'ERROR') {
                throw new RuntimeException('La carga no se encuentra en un estado válido para continuar.');
            }

            $actual = (int)$carga['registros_importados'];
            $cantidadLote = count($rows);

            // Respuesta idempotente si el navegador reintenta un lote que ya fue confirmado.
            if ($offset < $actual && ($offset + $cantidadLote) <= $actual) {
                json_out([
                    'ok' => true,
                    'repetido_lote' => true,
                    'importados_lote' => 0,
                    'importados_total' => $actual,
                    'mensaje' => 'El lote ya había sido importado.'
                ]);
            }
            if ($offset !== $actual) {
                throw new RuntimeException("Desfase de carga. El navegador intenta continuar desde $offset pero el servidor tiene $actual registros confirmados.");
            }

            $totalEsperado = (int)$carga['registros_recibidos'];
            if (($offset + $cantidadLote) > $totalEsperado) {
                throw new RuntimeException('El lote rebasa el total de registros declarado para el snapshot.');
            }

            $sql = "INSERT INTO pronostico_cancelaciones (
                carga_id,cuenta,plaza,distrito,cluster,region,estatus_cta,atraso,nse,fecha_activacion,
                fecha_cancelacion,tipo_cancelacion,ciclo,fecha_pronostico,semana_pronostico,reloj,saldo_serv,
                servicio_terceros,servicios_totalplay,nombre_plan,sub_canal,tipo_pago,fecha_final,semana_final,renta,subestatus
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            mysqli_begin_transaction($conexion);
            $stmt = mysqli_prepare($conexion, $sql);
            if (!$stmt) throw new RuntimeException(mysqli_error($conexion));

            foreach ($rows as $pos => $fila) {
                if (!is_array($fila)) {
                    throw new RuntimeException('Registro inválido en el lote, posición ' . ($pos + 1) . '.');
                }

                $g = function(string $h) use ($fila, $idx) {
                    $i = $idx[$h] ?? null;
                    return $i === null ? null : ($fila[$i] ?? null);
                };

                $cuenta = txt($g('CUENTA'));
                if ($cuenta === null) {
                    throw new RuntimeException('Registro sin CUENTA en la posición global ' . ($offset + $pos + 1) . '.');
                }

                $plaza=txt($g('PLAZA')); $distrito=txt($g('DISTRITO')); $cluster=txt($g('CLUSTER')); $region=txt($g('REGION'));
                $estatus=txt($g('ESTATUS_CTA')); $atraso=entero($g('ATRASO')); $nse=txt($g('NSE'));
                $fa=fecha_mysql($g('FECHA_ACTIVACION')); $fc=fecha_mysql($g('FECHA_CANCELACION')); $tc=txt($g('TIPO_CANCELACION'));
                $ciclo=entero($g('CICLO')); $fp=fecha_mysql($g('FECHA_PRONOSTICO')); $sp=entero($g('SEMANA_PRONOSTICO')); $reloj=txt($g('RELOJ'));
                $saldo=decimal($g('SALDO_SERV')); $st=decimal($g('SERVICIO_TERCEROS')); $tp=decimal($g('SERVICIOS_TOTALPLAY'));
                $plan=txt($g('NOMBRE_PLAN')); $subcanal=txt($g('SUB_CANAL')); $tipopago=txt($g('TIPO_PAGO'));
                $ff=fecha_mysql($g('FECHA FINAL')); $sf=entero($g('SEMANA FINAL')); $renta=decimal($g('RENTA')); $subestatus=txt($g('SUBESTATUS'));

                // 26 valores / 26 tipos. Se mantiene una fila por cuenta dentro de cada carga.
                mysqli_stmt_bind_param(
                    $stmt,
                    'issssssissssisisdddssssids',
                    $cargaId,$cuenta,$plaza,$distrito,$cluster,$region,$estatus,$atraso,$nse,$fa,$fc,$tc,$ciclo,$fp,$sp,$reloj,
                    $saldo,$st,$tp,$plan,$subcanal,$tipopago,$ff,$sf,$renta,$subestatus
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException('Registro global ' . ($offset + $pos + 1) . ': ' . mysqli_stmt_error($stmt));
                }
            }
            mysqli_stmt_close($stmt);

            $nuevoTotal = $offset + $cantidadLote;
            $msg = 'Importando por lotes: ' . number_format($nuevoTotal) . ' de ' . number_format($totalEsperado);
            $stmtUp = mysqli_prepare($conexion, "UPDATE pronostico_cancelaciones_cargas SET registros_importados=?, estado='PROCESANDO', mensaje=? WHERE id=?");
            if (!$stmtUp) throw new RuntimeException(mysqli_error($conexion));
            mysqli_stmt_bind_param($stmtUp, 'isi', $nuevoTotal, $msg, $cargaId);
            if (!mysqli_stmt_execute($stmtUp)) throw new RuntimeException(mysqli_stmt_error($stmtUp));
            mysqli_stmt_close($stmtUp);

            mysqli_commit($conexion);
            json_out([
                'ok' => true,
                'importados_lote' => $cantidadLote,
                'importados_total' => $nuevoTotal,
                'total_esperado' => $totalEsperado,
                'mensaje' => $msg
            ]);
        }

        if ($action === 'finish') {
            $p = payload_json();
            $cargaId = (int)($p['carga_id'] ?? 0);
            if ($cargaId < 1) throw new RuntimeException('carga_id inválido.');

            $carga = carga_por_id($conexion, $cargaId);
            if (!$carga) throw new RuntimeException('La carga solicitada no existe.');
            if ($carga['estado'] === 'OK') {
                json_out(['ok'=>true,'carga_id'=>$cargaId,'importados'=>(int)$carga['registros_importados'],'mensaje'=>'La carga ya estaba finalizada correctamente.']);
            }

            $esperados = (int)$carga['registros_recibidos'];
            $registrados = (int)$carga['registros_importados'];
            if ($registrados !== $esperados) {
                throw new RuntimeException("No es posible finalizar: se esperaban $esperados registros y solo hay $registrados lotes confirmados.");
            }

            $stmtCnt = mysqli_prepare($conexion, "SELECT COUNT(*) AS total FROM pronostico_cancelaciones WHERE carga_id=?");
            if (!$stmtCnt) throw new RuntimeException(mysqli_error($conexion));
            mysqli_stmt_bind_param($stmtCnt, 'i', $cargaId);
            mysqli_stmt_execute($stmtCnt);
            $resCnt = mysqli_stmt_get_result($stmtCnt);
            $rowCnt = mysqli_fetch_assoc($resCnt);
            mysqli_stmt_close($stmtCnt);
            $fisicos = (int)($rowCnt['total'] ?? 0);

            if ($fisicos !== $esperados) {
                throw new RuntimeException("Validación final fallida: MySQL contiene $fisicos registros, pero el snapshot esperaba $esperados.");
            }

            $msg = 'Importación completa: ' . number_format($fisicos) . ' registros.';
            $stmtFin = mysqli_prepare($conexion, "UPDATE pronostico_cancelaciones_cargas SET registros_importados=?, estado='OK', mensaje=?, finalizado_en=CURRENT_TIMESTAMP WHERE id=?");
            if (!$stmtFin) throw new RuntimeException(mysqli_error($conexion));
            mysqli_stmt_bind_param($stmtFin, 'isi', $fisicos, $msg, $cargaId);
            mysqli_stmt_execute($stmtFin);
            mysqli_stmt_close($stmtFin);

            json_out(['ok'=>true,'carga_id'=>$cargaId,'importados'=>$fisicos,'mensaje'=>$msg]);
        }

        if ($action === 'mark_error') {
            $p = payload_json();
            $cargaId = (int)($p['carga_id'] ?? 0);
            $mensaje = mb_substr((string)($p['mensaje'] ?? 'Carga interrumpida'), 0, 900, 'UTF-8');
            if ($cargaId > 0) {
                $stmt = mysqli_prepare($conexion, "UPDATE pronostico_cancelaciones_cargas SET estado='ERROR', mensaje=? WHERE id=? AND estado<>'OK'");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'si', $mensaje, $cargaId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
            json_out(['ok'=>true]);
        }

        throw new RuntimeException('Acción no reconocida.');

    } catch (Throwable $e) {
        if (isset($conexion)) @mysqli_rollback($conexion);
        json_out(['ok'=>false,'mensaje'=>$e->getMessage()], 400);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Importar Pronóstico de Cancelaciones</title>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<style>
*{box-sizing:border-box}body{font-family:Segoe UI,Arial,sans-serif;background:#f4f6fb;margin:0;padding:32px;color:#17213a}.card{max-width:760px;margin:auto;background:#fff;border-radius:18px;padding:28px;box-shadow:0 14px 35px rgba(30,40,80,.10)}h2{margin:0 0 8px}.sub{color:#667085;margin:0 0 22px;line-height:1.5}.drop{border:2px dashed #8b2cff;border-radius:16px;padding:34px;text-align:center;cursor:pointer;background:#fbf8ff}.drop strong{display:block;font-size:1.05rem;margin-bottom:7px}input[type=file]{display:none}button{margin-top:16px;width:100%;border:0;border-radius:12px;padding:13px;background:linear-gradient(135deg,#7A2BFF,#FF006C);color:#fff;font-weight:800;cursor:pointer}button:disabled{opacity:.55;cursor:not-allowed}.msg{margin-top:18px;padding:13px;border-radius:12px;display:none}.ok{display:block;background:#ecfdf3;color:#166534}.err{display:block;background:#fef2f2;color:#991b1b}.info{display:block;background:#eff6ff;color:#1e40af}.preview{margin-top:18px;font-size:.9rem;color:#344054}.preview code{background:#f2f4f7;padding:2px 5px;border-radius:5px}.progress-wrap{display:none;margin-top:18px}.progress-track{height:16px;background:#eceff5;border-radius:999px;overflow:hidden}.progress-bar{height:100%;width:0;background:linear-gradient(90deg,#7A2BFF,#FF006C);transition:width .2s ease}.progress-meta{display:flex;justify-content:space-between;gap:16px;margin-top:8px;color:#667085;font-size:.86rem}.progress-wrap.on{display:block}.hint{margin-top:12px;color:#667085;font-size:.8rem;line-height:1.45}
</style>
</head>
<body>
<div class="card">
<h2>Importar Pronóstico de Cancelaciones</h2>
<p class="sub">Acepta directamente el archivo <b>.xlsb</b> protegido. El navegador lee la hoja <b>Pronóstico</b> y la envía a MySQL en lotes seguros, conservando el snapshot completo y auditable.</p>
<label for="archivo"><div class="drop" id="drop"><strong>Selecciona el archivo .xlsb</strong><span id="nombre">Pronóstico cancelaciones...</span></div></label>
<input type="file" id="archivo" accept=".xlsb,.xlsx">
<button id="btn" disabled>Importar pronóstico</button>
<div id="preview" class="preview"></div>
<div id="progress" class="progress-wrap">
  <div class="progress-track"><div id="bar" class="progress-bar"></div></div>
  <div class="progress-meta"><span id="progressText">0 / 0</span><strong id="progressPct">0%</strong></div>
</div>
<div id="msg" class="msg"></div>
<div class="hint">Carga por lotes de 2,000 registros. Si la conexión se interrumpe, vuelve a seleccionar el mismo archivo: TalIA reanudará desde el último lote confirmado.</div>
</div>
<script>
const BATCH_SIZE = 2000;
const fileInput=document.getElementById('archivo'), btn=document.getElementById('btn'), msg=document.getElementById('msg'), preview=document.getElementById('preview'), nombre=document.getElementById('nombre');
const progress=document.getElementById('progress'), bar=document.getElementById('bar'), progressText=document.getElementById('progressText'), progressPct=document.getElementById('progressPct');
let file=null, rows=null, hash='';

function show(text,cls){msg.className='msg '+cls;msg.textContent=text}
function setProgress(done,total){
  progress.classList.add('on');
  const pct=total>0?Math.min(100,(done/total)*100):0;
  bar.style.width=pct.toFixed(2)+'%';
  progressText.textContent=done.toLocaleString()+' / '+total.toLocaleString()+' registros';
  progressPct.textContent=pct.toFixed(1)+'%';
}
async function api(action,payload){
  const res=await fetch('?action='+encodeURIComponent(action),{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload)
  });
  const text=await res.text();
  let data;
  try{ data=JSON.parse(text); }
  catch(e){ throw new Error('Respuesta no válida del servidor: '+text.slice(0,400)); }
  if(!res.ok||!data.ok) throw new Error(data.mensaje||'Error de importación');
  return data;
}

fileInput.addEventListener('change', async()=>{
  file=fileInput.files[0]; rows=null; hash=''; btn.disabled=true; preview.textContent=''; progress.classList.remove('on');
  if(!file)return;
  nombre.textContent=file.name;
  show('Leyendo libro protegido…','info');
  try{
    const ab=await file.arrayBuffer();
    const digest=await crypto.subtle.digest('SHA-256',ab);
    hash=[...new Uint8Array(digest)].map(b=>b.toString(16).padStart(2,'0')).join('');
    const wb=XLSX.read(ab,{type:'array',cellDates:false});
    const sheetName=wb.SheetNames.find(n=>n.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase()==='PRONOSTICO');
    if(!sheetName) throw new Error('No se encontró la hoja Pronóstico. Hojas detectadas: '+wb.SheetNames.join(', '));
    rows=XLSX.utils.sheet_to_json(wb.Sheets[sheetName],{header:1,defval:null,raw:true});
    if(rows.length<2) throw new Error('La hoja Pronóstico no contiene datos.');
    const total=rows.length-1;
    preview.innerHTML=`Hoja: <code>${sheetName}</code> · Filas detectadas: <b>${total.toLocaleString()}</b> · Lotes estimados: <b>${Math.ceil(total/BATCH_SIZE).toLocaleString()}</b>`;
    show('Archivo validado. Listo para importar por lotes.','ok');
    setProgress(0,total);
    btn.disabled=false;
  }catch(e){show(e.message||String(e),'err')}
});

btn.addEventListener('click',async()=>{
  if(!file||!rows)return;
  btn.disabled=true;
  const total=rows.length-1;
  let cargaId=0;
  try{
    show('Iniciando snapshot en MySQL…','info');
    const init=await api('init',{archivo:file.name,hash,headers:rows[0],total});
    cargaId=Number(init.carga_id||0);

    if(init.repetido){
      setProgress(total,total);
      show(init.mensaje+' No se duplicó el snapshot.','ok');
      return;
    }

    let offset=Number(init.resume_from||0);
    if(offset<0||offset>total) throw new Error('El servidor devolvió un punto de reanudación inválido.');
    setProgress(offset,total);
    if(offset>0) show(`Reanudando carga desde ${offset.toLocaleString()} de ${total.toLocaleString()} registros…`,'info');

    while(offset<total){
      const end=Math.min(offset+BATCH_SIZE,total);
      const chunk=rows.slice(offset+1,end+1); // +1 porque rows[0] contiene encabezados.
      const batch=await api('batch',{carga_id:cargaId,offset,headers:rows[0],rows:chunk});
      offset=Number(batch.importados_total);
      setProgress(offset,total);
      show(`Importando lote ${Math.ceil(offset/BATCH_SIZE).toLocaleString()} de ${Math.ceil(total/BATCH_SIZE).toLocaleString()}…`,'info');
    }

    show('Validando integridad final del snapshot…','info');
    const fin=await api('finish',{carga_id:cargaId});
    setProgress(total,total);
    show(fin.mensaje+' Carga ID: '+cargaId+'.','ok');
  }catch(e){
    const mensaje=e.message||String(e);
    show(mensaje+' Puedes volver a seleccionar el mismo archivo para reanudar.','err');
    if(cargaId>0){
      try{await api('mark_error',{carga_id:cargaId,mensaje});}catch(_e){}
    }
  }finally{
    btn.disabled=false;
  }
});
</script>
</body>
</html>
