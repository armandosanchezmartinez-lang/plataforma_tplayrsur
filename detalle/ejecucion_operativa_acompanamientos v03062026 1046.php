<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

$conexion_path_1 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';
$conexion_path_2 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/conexion.php';
if (file_exists($conexion_path_1)) include $conexion_path_1;
elseif (file_exists($conexion_path_2)) include $conexion_path_2;
else die("No se encontró archivo de conexión.");

$usuario = $_SESSION['usuario'] ?? 'sistema';
$rol = $_SESSION['rol'] ?? 'vendedor';
$id_posicion_sesion = $_SESSION['id_posicion'] ?? '';
$numero_talento_sesion = $_SESSION['numero_talento_gs'] ?? '';
$mensaje = '';
$tipo_mensaje = '';

function h($txt){ return htmlspecialchars((string)$txt, ENT_QUOTES, 'UTF-8'); }
function normalizar_texto($txt){
    $txt = trim((string)$txt);
    $txt = mb_strtoupper($txt, 'UTF-8');
    $txt = str_replace(['Á','É','Í','Ó','Ú','Ü'], ['A','E','I','O','U','U'], $txt);
    return preg_replace('/\s+/', ' ', $txt);
}
function normalizar_distrito_eo($distrito){
    $raw = normalizar_texto($distrito);
    $clean = preg_replace('/\s+/', ' ', str_replace(['/', '-'], ' ', $raw));
    if (strpos($clean, 'COATZA') !== false && strpos($clean, 'MINA') !== false) return 'COATZA / MINA';
    return $raw;
}
function nivel_desde_posicion($posicion){
    $p = normalizar_texto($posicion);
    if ($p === 'DIRECTOR DISTRITAL') return 'DIRECTOR_DISTRITAL';
    if ($p === 'LIDER VENTAS' || $p === 'LIDER PROMOVENDEDOR/PROMOTOR') return 'LIDER_VENTAS';
    if ($p === 'COACH VENTAS' || $p === 'COACH DE VENTAS' || $p === 'COACH PROMOVENDEDOR PUNTO DE VENTA' || $p === 'COACH PROMOTOR PDV') return 'COACH_VENTAS';
    if ($p === 'VENDEDOR' || $p === 'VENDEDOR NEGOCIOS' || $p === 'VENDEDOR NEGOCIO' || $p === 'PROMOVENDEDOR PUNTO DE VENTA') return 'VENDEDOR';
    return 'OTRO';
}
function siguiente_nivel($nivel){
    if ($nivel === 'DIRECTOR_DISTRITAL') return 'LIDER_VENTAS';
    if ($nivel === 'LIDER_VENTAS') return 'COACH_VENTAS';
    if ($nivel === 'COACH_VENTAS') return 'VENDEDOR';
    return null;
}
function etiqueta_nivel($nivel){
    return [
        'DIRECTOR_DISTRITAL'=>'Director Distrital',
        'LIDER_VENTAS'=>'Líder de Venta',
        'COACH_VENTAS'=>'Coach de Venta',
        'VENDEDOR'=>'Vendedor',
        'OTRO'=>'Otro'
    ][$nivel] ?? $nivel;
}
function semana_anterior_calc($semana,$anio){
    $sem = (int)$semana - 1; $an = (int)$anio;
    if ($sem <= 0) { $sem = 52; $an--; }
    return [$sem,$an];
}
function buscar_responsable_sesion($conexion,$anio,$semana,$id_posicion_sesion,$numero_talento_sesion,$usuario,$rol){
    $row = null;
    if ($id_posicion_sesion !== '') {
        $stmt = mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion AS puesto_responsable,distrito FROM hc WHERE id_posicion=? AND anio=? AND semana=? AND nombre_colaborador NOT LIKE '%VACANTE%' LIMIT 1");
        if ($stmt) { mysqli_stmt_bind_param($stmt,"sii",$id_posicion_sesion,$anio,$semana); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt); }
        if (!$row) {
            $stmt = mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion AS puesto_responsable,distrito FROM hc WHERE id_posicion=? AND nombre_colaborador NOT LIKE '%VACANTE%' ORDER BY anio DESC, semana DESC LIMIT 1");
            if ($stmt) { mysqli_stmt_bind_param($stmt,"s",$id_posicion_sesion); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt); }
        }
    }
    if (!$row && $numero_talento_sesion !== '') {
        $stmt = mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion AS puesto_responsable,distrito FROM hc WHERE numero_talento_gs=? AND nombre_colaborador NOT LIKE '%VACANTE%' ORDER BY anio DESC, semana DESC LIMIT 1");
        if ($stmt) { mysqli_stmt_bind_param($stmt,"s",$numero_talento_sesion); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt); }
    }
    return $row ?: ['id_posicion'=>$id_posicion_sesion ?: 'SIN_POSICION','posicion_lr'=>null,'numero_talento_gs'=>$numero_talento_sesion,'nombre_colaborador'=>$usuario,'puesto_responsable'=>strtoupper($rol),'distrito'=>''];
}
function obtener_ejecucion($conexion,$anio,$semana,$id_posicion){
    $stmt = mysqli_prepare($conexion,"SELECT * FROM ejecucion_operativa WHERE anio=? AND semana=? AND id_posicion=? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$id_posicion);
    mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt);
    return $row;
}
function cargar_acciones_plan($conexion,$id_ejecucion){
    $rows=[];
    $sql="SELECT id,accion,descripcion,responsable,fecha_compromiso,prioridad,estatus FROM ejecucion_operativa_acciones WHERE id_ejecucion=? ORDER BY CASE prioridad WHEN 'ALTA' THEN 1 WHEN 'MEDIA' THEN 2 WHEN 'BAJA' THEN 3 ELSE 4 END, fecha_compromiso ASC, id ASC";
    $stmt=mysqli_prepare($conexion,$sql); if(!$stmt) return $rows;
    mysqli_stmt_bind_param($stmt,"i",$id_ejecucion); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
    while($r=mysqli_fetch_assoc($res)) $rows[]=$r; mysqli_stmt_close($stmt); return $rows;
}
function cargar_subordinados_linea($conexion,$anio_hc,$semana_hc,$id_posicion,$nivel_jefe){
    $rows=[]; $nivel_sub=siguiente_nivel($nivel_jefe); if(!$nivel_sub) return $rows;
    $sql="SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE anio=? AND semana=? AND posicion_lr=? AND nombre_colaborador NOT LIKE '%VACANTE%' AND numero_talento_gs NOT LIKE '%VACANTE%' ORDER BY posicion,nombre_colaborador";
    $stmt=mysqli_prepare($conexion,$sql); if(!$stmt) return $rows;
    mysqli_stmt_bind_param($stmt,"iis",$anio_hc,$semana_hc,$id_posicion); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
    while($r=mysqli_fetch_assoc($res)){ if(nivel_desde_posicion($r['posicion'] ?? '') === $nivel_sub) $rows[]=$r; }
    mysqli_stmt_close($stmt); return $rows;
}
function cargar_acompanamientos($conexion,$anio,$semana,$id_posicion_jefe){
    $rows=[];
    $sql="SELECT a.*, ac.accion AS accion_plan FROM ejecucion_operativa_acompanamientos a LEFT JOIN ejecucion_operativa_acciones ac ON ac.id=a.id_accion WHERE a.anio=? AND a.semana=? AND a.id_posicion_jefe=? ORDER BY a.fecha_hora DESC, a.id DESC";
    $stmt=mysqli_prepare($conexion,$sql); if(!$stmt) return $rows;
    mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$id_posicion_jefe); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
    while($r=mysqli_fetch_assoc($res)) $rows[]=$r; mysqli_stmt_close($stmt); return $rows;
}
function tipo_acompanamiento_label($tipo){
    return ['SHADOWING'=>'Shadowing','COACHING_1_1'=>'Coaching 1:1','SEGUIMIENTO'=>'Seguimiento','SUPERVISION_CAMPO'=>'Supervisión campo','OTRO'=>'Otro'][$tipo] ?? $tipo;
}

function guardar_evidencia_acompanamiento($field_name, &$error_msg){
    $error_msg = '';
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name]) || ($_FILES[$field_name]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($_FILES[$field_name]['error'] !== UPLOAD_ERR_OK) {
        $error_msg = 'No se pudo cargar la evidencia. Código de error: ' . (int)$_FILES[$field_name]['error'];
        return false;
    }

    $max_bytes = 12 * 1024 * 1024;
    if ((int)$_FILES[$field_name]['size'] > $max_bytes) {
        $error_msg = 'La evidencia excede el tamaño máximo permitido de 12 MB.';
        return false;
    }

    $original = basename((string)$_FILES[$field_name]['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $permitidas = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx','ppt','pptx','txt'];

    if (!in_array($ext, $permitidas, true)) {
        $error_msg = 'Tipo de archivo no permitido. Usa imagen, PDF, Office o TXT.';
        return false;
    }

    $dir = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/uploads/ejecucion_operativa/acompanamientos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safe_base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($original, PATHINFO_FILENAME));
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe_base . '.' . $ext;
    $destino = $dir . $filename;

    if (!move_uploaded_file($_FILES[$field_name]['tmp_name'], $destino)) {
        $error_msg = 'No se pudo guardar físicamente la evidencia.';
        return false;
    }

    return 'uploads/ejecucion_operativa/acompanamientos/' . $filename;
}

function evidencia_link($ruta){
    $ruta = trim((string)$ruta);
    if ($ruta === '') return '';
    if (preg_match('/^https?:\/\//i', $ruta)) return $ruta;
    return '../' . ltrim($ruta, '/');
}

$anio_hoy=(int)date('Y'); $semana_hoy=(int)date('W');
$anio_actual=isset($_GET['anio'])?(int)$_GET['anio']:$anio_hoy;
$semana_actual=isset($_GET['semana'])?(int)$_GET['semana']:$semana_hoy;
if($semana_actual<1)$semana_actual=1; if($semana_actual>53)$semana_actual=53;
if($anio_actual < $anio_hoy || ($anio_actual===$anio_hoy && $semana_actual < $semana_hoy)) { $anio_hc=$anio_actual; $semana_hc=$semana_actual; }
else { [$semana_hc,$anio_hc]=semana_anterior_calc($semana_hoy,$anio_hoy); }
[$semana_nav_prev,$anio_nav_prev]=semana_anterior_calc($semana_actual,$anio_actual);
$semana_nav_next=$semana_actual+1; $anio_nav_next=$anio_actual; if($semana_nav_next>53){$semana_nav_next=1;$anio_nav_next++;}

$responsable=buscar_responsable_sesion($conexion,$anio_hc,$semana_hc,$id_posicion_sesion,$numero_talento_sesion,$usuario,$rol);
$id_posicion=(string)$responsable['id_posicion'];
$nombre_jefe=$responsable['nombre_colaborador'] ?? $usuario;
$puesto_jefe=$responsable['puesto_responsable'] ?? strtoupper($rol);
$distrito=normalizar_distrito_eo($responsable['distrito'] ?? '');
$nivel_jefe=nivel_desde_posicion($puesto_jefe);
if(!in_array($nivel_jefe,['DIRECTOR_DISTRITAL','LIDER_VENTAS','COACH_VENTAS'],true)) die('Este módulo está habilitado para Director Distrital, Líder de Venta y Coach de Venta.');

$ejecucion=obtener_ejecucion($conexion,$anio_actual,$semana_actual,$id_posicion);
$id_ejecucion=$ejecucion?(int)$ejecucion['id']:0;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if($id_ejecucion<=0){ $mensaje='Primero debes crear el plan operativo de la semana para poder documentar acompañamientos.'; $tipo_mensaje='error'; }
    else{
        $id_accion=(isset($_POST['id_accion']) && $_POST['id_accion']!=='')?(int)$_POST['id_accion']:null;
        $fecha=trim($_POST['fecha'] ?? '') ?: date('Y-m-d');
        $hora=trim($_POST['hora'] ?? '') ?: date('H:i');
        $fecha_hora=$fecha.' '.$hora.':00';
        $tipo_colaborador=trim($_POST['tipo_colaborador'] ?? '');
        $id_posicion_colaborador=trim($_POST['id_posicion_colaborador'] ?? '');
        $numero_talento_colaborador=trim($_POST['numero_talento_colaborador'] ?? '');
        $nombre_colaborador=trim($_POST['nombre_colaborador'] ?? '');
        $tipo_acompanamiento=$_POST['tipo_acompanamiento'] ?? 'SEGUIMIENTO';
        if(!in_array($tipo_acompanamiento,['SHADOWING','COACHING_1_1','SEGUIMIENTO','SUPERVISION_CAMPO','OTRO'],true)) $tipo_acompanamiento='SEGUIMIENTO';
        $hallazgos=trim($_POST['hallazgos_principales'] ?? '');
        $compromisos=trim($_POST['compromisos'] ?? '');
        $error_evidencia = '';
        $evidencia = guardar_evidencia_acompanamiento('evidencia', $error_evidencia);

        if($tipo_colaborador==='' || $nombre_colaborador===''){ $mensaje='Captura al menos el tipo de colaborador y el nombre del colaborador acompañado.'; $tipo_mensaje='error'; }
        elseif($evidencia === false){ $mensaje=$error_evidencia; $tipo_mensaje='error'; }
        else{
            $sql="INSERT INTO ejecucion_operativa_acompanamientos (id_ejecucion,id_accion,anio,semana,id_posicion_jefe,nombre_jefe,nivel_jefe,distrito,fecha_hora,tipo_colaborador,id_posicion_colaborador,numero_talento_colaborador,nombre_colaborador,tipo_acompanamiento,hallazgos_principales,compromisos,evidencia,usuario_captura) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt=mysqli_prepare($conexion,$sql);
            if(!$stmt){ $mensaje='Error preparando guardado: '.mysqli_error($conexion); $tipo_mensaje='error'; }
            else{
                mysqli_stmt_bind_param($stmt,"iiiissssssssssssss",$id_ejecucion,$id_accion,$anio_actual,$semana_actual,$id_posicion,$nombre_jefe,$nivel_jefe,$distrito,$fecha_hora,$tipo_colaborador,$id_posicion_colaborador,$numero_talento_colaborador,$nombre_colaborador,$tipo_acompanamiento,$hallazgos,$compromisos,$evidencia,$usuario);
                if(mysqli_stmt_execute($stmt)){ $mensaje='✅ Acompañamiento guardado correctamente.'; $tipo_mensaje='exito'; }
                else{ $mensaje='Error al guardar acompañamiento: '.mysqli_stmt_error($stmt); $tipo_mensaje='error'; }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$acciones_plan=$id_ejecucion>0?cargar_acciones_plan($conexion,$id_ejecucion):[];
$subordinados=cargar_subordinados_linea($conexion,$anio_hc,$semana_hc,$id_posicion,$nivel_jefe);
$acompanamientos=cargar_acompanamientos($conexion,$anio_actual,$semana_actual,$id_posicion);
$tipos_colaborador=[]; foreach($subordinados as $s) $tipos_colaborador[$s['posicion']]=true;
$total_acomp=count($acompanamientos); $colabs=[]; $por_tipo=[];
foreach($acompanamientos as $a){ $colabs[$a['id_posicion_colaborador'] ?: $a['nombre_colaborador']]=true; $por_tipo[$a['tipo_acompanamiento']]=($por_tipo[$a['tipo_acompanamiento']]??0)+1; }
$tipo_top=$por_tipo?array_keys($por_tipo,max($por_tipo))[0]:'—';
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acompañamientos - TOTALXPEDIENT</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=172">
<style>
:root{--tx-purple:#7A2BFF;--tx-pink:#FF0AC8;--tx-cyan:#00D8FF;--tx-card:rgba(255,255,255,.92);--tx-border:#e2e8f0;--tx-text:#1a2540;--tx-muted:#6b7a99;--tx-green:#10b981;--tx-orange:#f59e0b}*{box-sizing:border-box}body{margin:0;font-family:'Poppins','Segoe UI',sans-serif;background:radial-gradient(circle at 8% 8%,rgba(122,43,255,.10),transparent 28%),radial-gradient(circle at 92% 14%,rgba(0,216,255,.09),transparent 30%),linear-gradient(180deg,#f7f8ff 0%,#eef5ff 100%);color:var(--tx-text);min-height:100vh;display:flex}.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}.page-title h1{margin:0;font-size:1.65rem}.page-title p{margin:6px 0 0;color:var(--tx-muted);font-size:.9rem}.pill-row,.week-nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.pill,.week-nav a,.week-nav span{border:1px solid var(--tx-border);background:var(--tx-card);padding:8px 12px;border-radius:999px;font-size:.78rem;font-weight:900;color:var(--tx-muted);text-decoration:none}.pill.active,.week-nav .current{color:white;background:linear-gradient(135deg,var(--tx-purple),var(--tx-pink));border:none}.card,.kpi-box,.status-card{background:var(--tx-card);border:1px solid var(--tx-border);border-radius:24px;box-shadow:0 14px 32px rgba(22,28,60,.08)}.status-card{padding:18px 20px;min-width:300px}.status-label{font-size:.72rem;text-transform:uppercase;color:var(--tx-muted);font-weight:900;letter-spacing:.7px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{padding:22px;margin-bottom:20px}.card-title{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.card-title h2{margin:0;font-size:1.05rem}.card-title span{color:var(--tx-muted);font-size:.78rem;font-weight:800}.kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}.kpi-box{padding:16px}.kpi-box .label{color:var(--tx-muted);font-weight:900;font-size:.72rem;text-transform:uppercase}.kpi-box .value{margin-top:8px;font-size:1.45rem;font-weight:950;color:var(--tx-purple)}.field{margin-bottom:16px}.field label{display:block;font-size:.74rem;text-transform:uppercase;letter-spacing:.5px;font-weight:900;color:var(--tx-muted);margin-bottom:8px}.field input,.field textarea,.field select{width:100%;border:1.5px solid #dbe4f0;border-radius:16px;padding:12px 13px;font-family:inherit;font-size:.88rem;outline:none;background:white;color:var(--tx-text)}.field textarea{min-height:108px;resize:vertical}.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.btn{border:none;border-radius:14px;padding:12px 18px;font-weight:900;cursor:pointer;font-size:.9rem;font-family:inherit;text-decoration:none}.btn-primary{color:white;background:linear-gradient(135deg,var(--tx-purple),var(--tx-pink));box-shadow:0 12px 28px rgba(122,43,255,.20)}.btn-secondary{background:#e8eef7;color:#1a2540}.alert{border-radius:16px;padding:14px 16px;margin-bottom:18px;font-weight:700}.alert.exito{background:#dcfce7;color:#166534}.alert.error{background:#fee2e2;color:#991b1b}.wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:16px;background:white}table{width:100%;border-collapse:collapse;font-size:.8rem}th,td{padding:12px 10px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:.7rem;font-weight:900}.mini{font-size:.72rem;color:var(--tx-muted);font-weight:700;margin-top:2px}.badge{border-radius:999px;padding:6px 10px;font-size:.7rem;font-weight:950;display:inline-flex}.badge.SHADOWING{background:#dbeafe;color:#1d4ed8}.badge.COACHING_1_1{background:#ede9fe;color:#5b21b6}.badge.SEGUIMIENTO{background:#ffedd5;color:#9a3412}.badge.SUPERVISION_CAMPO{background:#dcfce7;color:#166534}.badge.OTRO{background:#e2e8f0;color:#334155}.empty{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:18px;padding:16px;color:#64748b;font-weight:850}.actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap}@media(max-width:1150px){.grid,.two{grid-template-columns:1fr}.kpi{grid-template-columns:1fr 1fr}.page-header{flex-direction:column}.status-card{width:100%}}
</style></head><body>
<?php $current_page='ejecucion_operativa_acompanamientos'; include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="main">
<div class="page-header"><div class="page-title"><h1>🤝 Acompañamientos</h1><p>Registro de acompañamientos en campo vinculados al plan de ejecución operativa.</p><div class="pill-row"><span class="pill active">ACOMPAÑAMIENTOS</span><span class="pill"><?= h(etiqueta_nivel($nivel_jefe)) ?></span><span class="pill">SEM <?= h($semana_actual) ?> · <?= h($anio_actual) ?></span><span class="pill">HC SEM <?= h($semana_hc) ?> · <?= h($anio_hc) ?></span></div><div class="week-nav"><a href="?anio=<?= h($anio_nav_prev) ?>&semana=<?= h($semana_nav_prev) ?>">← SEM <?= h($semana_nav_prev) ?></a><span class="current">SEM <?= h($semana_actual) ?></span><a href="?anio=<?= h($anio_nav_next) ?>&semana=<?= h($semana_nav_next) ?>">SEM <?= h($semana_nav_next) ?> →</a></div></div><div class="status-card"><div class="status-label">Responsable</div><strong><?= h($nombre_jefe) ?></strong><br><span style="font-size:.78rem;color:var(--tx-muted);font-weight:800;"><?= h($distrito ?: 'N/D') ?> · <?= h($puesto_jefe) ?></span></div></div>
<?php if($mensaje): ?><div class="alert <?= h($tipo_mensaje) ?>"><?= h($mensaje) ?></div><?php endif; ?>
<section class="kpi"><div class="kpi-box"><div class="label">Acompañamientos</div><div class="value"><?= number_format($total_acomp) ?></div></div><div class="kpi-box"><div class="label">Colaboradores acompañados</div><div class="value"><?= number_format(count($colabs)) ?></div></div><div class="kpi-box"><div class="label">Tipo más usado</div><div class="value" style="font-size:1rem;"><?= h($tipo_top==='—'?'—':tipo_acompanamiento_label($tipo_top)) ?></div></div><div class="kpi-box"><div class="label">Acciones del plan</div><div class="value"><?= number_format(count($acciones_plan)) ?></div></div></section>
<div class="grid"><section class="card"><div class="card-title"><h2>Nuevo acompañamiento</h2><span>Documentación en campo</span></div>
<?php if($id_ejecucion<=0): ?><div class="empty">Primero crea el plan operativo de esta semana. Después podrás registrar acompañamientos vinculados a las acciones clave.</div><?php else: ?>
<form method="POST" enctype="multipart/form-data"><div class="two"><div class="field"><label>Fecha</label><input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>"></div><div class="field"><label>Hora</label><input type="time" name="hora" value="<?= h(date('H:i')) ?>"></div></div><div class="field"><label>Acción clave del plan</label><select name="id_accion"><option value="">Sin vincular acción específica</option><?php foreach($acciones_plan as $a): ?><option value="<?= h($a['id']) ?>"><?= h($a['accion']) ?><?= !empty($a['responsable']) ? ' · '.h($a['responsable']) : '' ?></option><?php endforeach; ?></select></div><div class="two"><div class="field"><label>Tipo colaborador / Puesto</label><select name="tipo_colaborador" id="tipoColaborador"><option value="">Selecciona puesto</option><?php foreach(array_keys($tipos_colaborador) as $puesto): ?><option value="<?= h($puesto) ?>"><?= h($puesto) ?></option><?php endforeach; ?><option value="OTRO">OTRO</option></select></div><div class="field"><label>Nombre del colaborador</label><select name="nombre_colaborador" id="nombreColaborador"><option value="">Selecciona colaborador</option><?php foreach($subordinados as $s): ?><option value="<?= h($s['nombre_colaborador']) ?>" data-puesto="<?= h($s['posicion']) ?>" data-idpos="<?= h($s['id_posicion']) ?>" data-talento="<?= h($s['numero_talento_gs']) ?>"><?= h($s['nombre_colaborador']) ?></option><?php endforeach; ?><option value="OTRO">Otro / captura manual</option></select><input type="hidden" name="id_posicion_colaborador" id="idPosColaborador"><input type="hidden" name="numero_talento_colaborador" id="talentoColaborador"></div></div><div class="field" id="nombreManualWrap" style="display:none;"><label>Nombre manual</label><input type="text" id="nombreManual" placeholder="Captura nombre del colaborador"></div><div class="field"><label>Tipo de acompañamiento</label><select name="tipo_acompanamiento"><option value="SHADOWING">Shadowing</option><option value="COACHING_1_1">Coaching 1:1</option><option value="SEGUIMIENTO">Seguimiento</option><option value="SUPERVISION_CAMPO">Supervisión campo</option><option value="OTRO">Otro</option></select></div><div class="field"><label>Hallazgos principales</label><textarea name="hallazgos_principales" placeholder="Ej. Mejora en apertura, oportunidad en manejo de objeciones..."></textarea></div><div class="field"><label>Compromisos</label><textarea name="compromisos" placeholder="Ej. Plan 2x3, reforzar cierre, 20 contactos diarios..."></textarea></div><div class="field"><label>Evidencia</label><input type="file" name="evidencia" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/*,application/pdf"><div class="mini">Adjunta foto, evidencia en PDF u otro archivo soporte. Máximo 12 MB.</div></div><div class="actions"><a href="ejecucion_operativa_captura.php?anio=<?= h($anio_actual) ?>&semana=<?= h($semana_actual) ?>" class="btn btn-secondary">Volver al plan</a><button type="submit" class="btn btn-primary">Guardar acompañamiento</button></div></form><?php endif; ?></section>
<section class="card"><div class="card-title"><h2>Acompañamientos registrados</h2><span>Semana <?= h($semana_actual) ?></span></div><?php if(empty($acompanamientos)): ?><div class="empty">Aún no hay acompañamientos registrados para esta semana.</div><?php else: ?><div class="wrap"><table><thead><tr><th>Fecha / Hora</th><th>Colaborador</th><th>Tipo</th><th>Acción vinculada</th><th>Evidencia</th></tr></thead><tbody><?php foreach($acompanamientos as $a): ?><tr><td><?= h(date('d/m/Y H:i', strtotime($a['fecha_hora']))) ?></td><td><strong><?= h($a['nombre_colaborador']) ?></strong><div class="mini"><?= h($a['tipo_colaborador']) ?></div></td><td><span class="badge <?= h($a['tipo_acompanamiento']) ?>"><?= h(tipo_acompanamiento_label($a['tipo_acompanamiento'])) ?></span></td><td><?= h($a['accion_plan'] ?: 'Sin acción vinculada') ?></td><td><?php if(!empty($a['evidencia'])): ?><a href="<?= h(evidencia_link($a['evidencia'])) ?>" target="_blank" class="btn btn-secondary" style="padding:7px 10px;font-size:.72rem;">📎 Ver</a><?php else: ?>—<?php endif; ?></td></tr><tr><td colspan="5"><strong>Hallazgos:</strong> <?= h($a['hallazgos_principales'] ?: '—') ?><br><strong>Compromisos:</strong> <?= h($a['compromisos'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></div></main>
<script>document.addEventListener('DOMContentLoaded',function(){const tipo=document.getElementById('tipoColaborador'), nombre=document.getElementById('nombreColaborador'), idPos=document.getElementById('idPosColaborador'), talento=document.getElementById('talentoColaborador'), manualWrap=document.getElementById('nombreManualWrap'), manual=document.getElementById('nombreManual');function filtrar(){const puesto=tipo.value;[...nombre.options].forEach(opt=>{if(!opt.value||opt.value==='OTRO'){opt.hidden=false;return;}opt.hidden=puesto&&puesto!=='OTRO'&&opt.dataset.puesto!==puesto;});nombre.value='';idPos.value='';talento.value='';}function seleccionar(){const opt=nombre.options[nombre.selectedIndex];if(!opt)return;if(nombre.value==='OTRO'){manualWrap.style.display='block';manual.setAttribute('name','nombre_colaborador');nombre.removeAttribute('name');idPos.value='';talento.value='';return;}manualWrap.style.display='none';manual.removeAttribute('name');nombre.setAttribute('name','nombre_colaborador');idPos.value=opt.dataset.idpos||'';talento.value=opt.dataset.talento||'';}if(tipo)tipo.addEventListener('change',filtrar);if(nombre)nombre.addEventListener('change',seleccionar);});</script>
</body></html>
