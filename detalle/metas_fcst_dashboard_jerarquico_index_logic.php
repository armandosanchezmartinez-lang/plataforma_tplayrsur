<?php
set_time_limit(0);
session_start();
if (!isset($_SESSION['usuario'])) { header("Location: ../login.php"); exit(); }

$p1 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';
$p2 = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/conexion.php';
if (file_exists($p1)) include $p1; elseif (file_exists($p2)) include $p2; else die("No se encontró conexión.");

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function div_pct($a,$b){ return ($b && $b>0) ? ($a/$b*100) : null; }
function fmt($n){ return number_format((float)$n,0); }
function fpct($p){ return $p===null ? '—' : number_format((float)$p,0).'%'; }
function cls($p){ if($p===null||$p<=0)return'gris'; if($p<90)return'rojo'; if($p<100)return'amarillo'; if($p<120)return'verde'; return'azul'; }
function riesgo($p){ if($p===null||$p<=0)return'SIN DATO'; if($p<90)return'RIESGO'; if($p<100)return'ALERTA'; if($p<120)return'ESTABLE'; return'SOBRE META'; }
function accuracy($real,$fcst){ if($real<=0||$fcst<=0)return null; return min($real,$fcst)/max($real,$fcst)*100; }
function normalizar_basico($txt){
    $txt = strtoupper(trim((string)$txt));
    $txt = str_replace(['Á','É','Í','Ó','Ú','Ü'], ['A','E','I','O','U','U'], $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    return $txt;
}
function norm_dist($d){
    $raw = normalizar_basico($d);
    $clean = str_replace(['/', '-'], ' ', $raw);
    $clean = preg_replace('/\s+/', ' ', $clean);
    if(strpos($clean, 'COATZA') !== false && strpos($clean, 'MINA') !== false) return 'COATZA / MINA';
    return $raw;
}
function dist_norm_sql($col='distrito'){
    return "CASE
        WHEN UPPER(TRIM($col)) LIKE '%COATZA%' AND UPPER(TRIM($col)) LIKE '%MINA%'
        THEN 'COATZA / MINA'
        ELSE UPPER(TRIM($col))
    END";
}
function norm_rol($r){ return str_replace([' ','-'], '_', strtolower(trim((string)$r))); }
function norm_pos($p){ return normalizar_basico($p); }
function in_sql($arr){ return "'" . implode("','", array_map('addslashes', $arr)) . "'"; }

function get_subordinados_directos($conexion, $id_pos, $semana=null, $anio=null){
    $ids=[];
    if($id_pos==='') return [];
    if($semana && $anio){
        $stmt=mysqli_prepare($conexion,"SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr=? AND numero_talento_gs NOT LIKE '%VACANTE%' AND semana=? AND anio=?");
        if($stmt){ mysqli_stmt_bind_param($stmt,"sii",$id_pos,$semana,$anio); }
    } else {
        $stmt=mysqli_prepare($conexion,"SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr=? AND numero_talento_gs NOT LIKE '%VACANTE%'");
        if($stmt){ mysqli_stmt_bind_param($stmt,"s",$id_pos); }
    }
    if($stmt){ mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); while($r=mysqli_fetch_assoc($res)) $ids[]=$r['id_posicion']; mysqli_stmt_close($stmt); }
    return array_values(array_unique($ids));
}

function cargar_hc_usuario($conexion, $id_posicion, $talento, $semana, $anio){
    $row=null;
    if($id_posicion!==''){
        $stmt=mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE id_posicion=? AND semana=? AND anio=? LIMIT 1");
        if($stmt){ mysqli_stmt_bind_param($stmt,"sii",$id_posicion,$semana,$anio); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt); }
        if(!$row){
            $stmt=mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE id_posicion=? ORDER BY anio DESC, semana DESC LIMIT 1");
            if($stmt){ mysqli_stmt_bind_param($stmt,"s",$id_posicion); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt); }
        }
    }
    if(!$row && $talento!==''){
        $stmt=mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE numero_talento_gs=? ORDER BY anio DESC, semana DESC LIMIT 1");
        if($stmt){ mysqli_stmt_bind_param($stmt,"s",$talento); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); $row=mysqli_fetch_assoc($res); mysqli_stmt_close($stmt); }
    }
    return $row ?: [];
}

function scope_config($rol, $posicion){
    $rol=norm_rol($rol); $pos=norm_pos($posicion);
    if(in_array($rol,['admin','administrador','director_regional']) || $pos==='DIRECTOR REGIONAL'){
        return ['nivel'=>'REGIONAL','target'=>'DIRECTOR_DISTRITAL','titulo'=>'Directores distritales','label'=>'Distrito','puestos'=>['DIRECTOR DISTRITAL']];
    }
    if($rol==='director_distrital' || $pos==='DIRECTOR DISTRITAL'){
        return ['nivel'=>'DISTRITAL','target'=>'LIDER','titulo'=>'Líderes de venta','label'=>'Líder','puestos'=>['LIDER VENTAS','LIDER PROMOVENDEDOR/PROMOTOR']];
    }
    if(in_array($rol,['lider','lider_ventas','lider_venta']) || in_array($pos,['LIDER VENTAS','LIDER PROMOVENDEDOR/PROMOTOR'])){
        return ['nivel'=>'LIDER','target'=>'COACH','titulo'=>'Coaches de venta','label'=>'Coach','puestos'=>['COACH VENTAS','COACH DE VENTAS','COACH PROMOVENDEDOR PUNTO DE VENTA','COACH PROMOTOR PDV']];
    }
    return ['nivel'=>'COACH','target'=>'COACH','titulo'=>'Mi forecast','label'=>'Responsable','puestos'=>['COACH VENTAS','COACH DE VENTAS','COACH PROMOVENDEDOR PUNTO DE VENTA','COACH PROMOTOR PDV']];
}

function cargar_grupos($conexion, $anio, $semana, $scope, $usuario_hc){
    $grupos=[]; $puestos=in_sql($scope['puestos']); $id_jefe=$usuario_hc['id_posicion'] ?? '';
    if($scope['nivel']==='REGIONAL'){
        if($id_jefe!=='' && norm_pos($usuario_hc['posicion'] ?? '')==='DIRECTOR REGIONAL'){
            $stmt=mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE semana=? AND anio=? AND posicion IN ($puestos) AND posicion_lr=? AND numero_talento_gs NOT LIKE '%VACANTE%' ORDER BY distrito,nombre_colaborador");
            if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$semana,$anio,$id_jefe); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); while($r=mysqli_fetch_assoc($res))$grupos[]=$r; mysqli_stmt_close($stmt); }
        }
        if(!$grupos){
            $stmt=mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE semana=? AND anio=? AND posicion IN ($puestos) AND numero_talento_gs NOT LIKE '%VACANTE%' ORDER BY distrito,nombre_colaborador");
            if($stmt){ mysqli_stmt_bind_param($stmt,"ii",$semana,$anio); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); while($r=mysqli_fetch_assoc($res))$grupos[]=$r; mysqli_stmt_close($stmt); }
        }
    } elseif($scope['nivel']==='COACH'){
        if(!empty($usuario_hc)) $grupos[]=$usuario_hc;
    } else {
        if($id_jefe!==''){
            $stmt=mysqli_prepare($conexion,"SELECT id_posicion,posicion_lr,numero_talento_gs,nombre_colaborador,posicion,distrito FROM hc WHERE semana=? AND anio=? AND posicion IN ($puestos) AND posicion_lr=? AND numero_talento_gs NOT LIKE '%VACANTE%' ORDER BY nombre_colaborador");
            if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$semana,$anio,$id_jefe); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); while($r=mysqli_fetch_assoc($res))$grupos[]=$r; mysqli_stmt_close($stmt); }
        }
    }
    // Fallback sin semana exacta, útil si el HC de la semana seleccionada todavía no existe.
    if(!$grupos){
        if($scope['nivel']==='REGIONAL'){
            $res=mysqli_query($conexion,"SELECT h.* FROM hc h INNER JOIN (SELECT id_posicion, MAX(CONCAT(anio,LPAD(semana,2,'0'))) periodo FROM hc WHERE posicion IN ($puestos) AND numero_talento_gs NOT LIKE '%VACANTE%' GROUP BY id_posicion) u ON h.id_posicion=u.id_posicion AND CONCAT(h.anio,LPAD(h.semana,2,'0'))=u.periodo WHERE h.posicion IN ($puestos) ORDER BY h.distrito,h.nombre_colaborador");
            if($res){ while($r=mysqli_fetch_assoc($res))$grupos[]=$r; }
        } elseif($scope['nivel']!=='COACH' && $id_jefe!==''){
            $stmt=mysqli_prepare($conexion,"SELECT h.* FROM hc h INNER JOIN (SELECT id_posicion, MAX(CONCAT(anio,LPAD(semana,2,'0'))) periodo FROM hc WHERE posicion IN ($puestos) AND posicion_lr=? AND numero_talento_gs NOT LIKE '%VACANTE%' GROUP BY id_posicion) u ON h.id_posicion=u.id_posicion AND CONCAT(h.anio,LPAD(h.semana,2,'0'))=u.periodo WHERE h.posicion IN ($puestos) AND h.posicion_lr=? ORDER BY h.nombre_colaborador");
            if($stmt){ mysqli_stmt_bind_param($stmt,"ss",$id_jefe,$id_jefe); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); while($r=mysqli_fetch_assoc($res))$grupos[]=$r; mysqli_stmt_close($stmt); }
        }
    }
    $seen=[]; $out=[];
    foreach($grupos as $g){
        $k=(string)($g['id_posicion'] ?? ''); if($k==='' || isset($seen[$k])) continue; $seen[$k]=1;
        $g['distrito']=norm_dist($g['distrito'] ?? '');
        $out[]=$g;
    }
    return $out;
}

function real_por_grupo($conexion,$anio,$semana,$scope,$g){
    $real=0; $target=$scope['target']; $dist=norm_dist($g['distrito'] ?? ''); $tal=(string)($g['numero_talento_gs'] ?? ''); $nom=(string)($g['nombre_colaborador'] ?? '');
    if($target==='DIRECTOR_DISTRITAL'){
        $stmt=mysqli_prepare($conexion,"SELECT COUNT(cuenta) total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND CASE WHEN UPPER(TRIM(distrito)) LIKE '%COATZA%' AND UPPER(TRIM(distrito)) LIKE '%MINA%' THEN 'COATZA / MINA' ELSE UPPER(TRIM(distrito)) END=? AND origen_prospecto <> '-'");
        if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$dist); }
    } elseif($target==='LIDER'){
        $stmt=mysqli_prepare($conexion,"SELECT COUNT(cuenta) total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND (folio_lider=? OR UPPER(TRIM(lider))=UPPER(TRIM(?))) AND origen_prospecto <> '-'");
        if($stmt){ mysqli_stmt_bind_param($stmt,"iiss",$anio,$semana,$tal,$nom); }
    } else {
        $stmt=mysqli_prepare($conexion,"SELECT COUNT(cuenta) total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND (folio_coach=? OR UPPER(TRIM(coach))=UPPER(TRIM(?))) AND origen_prospecto <> '-'");
        if($stmt){ mysqli_stmt_bind_param($stmt,"iiss",$anio,$semana,$tal,$nom); }
    }
    if($stmt){ mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($r=mysqli_fetch_assoc($res))$real=(int)$r['total']; mysqli_stmt_close($stmt); }
    return $real;
}
function forecast_por_id($conexion,$anio,$semana,$id_pos){
    $v=0; $stmt=mysqli_prepare($conexion,"SELECT COALESCE(SUM(forecast),0) total FROM metas_forecast_semanal WHERE anio=? AND semana=? AND id_posicion=?");
    if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$id_pos); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($r=mysqli_fetch_assoc($res))$v=(int)$r['total']; mysqli_stmt_close($stmt); }
    return $v;
}
function meta_por_grupo($conexion,$anio,$semana,$scope,$g,$fcst){
    if($scope['target']!=='DIRECTOR_DISTRITAL') return $fcst; // En niveles Líder/Coach, mientras no exista meta individual, el compromiso base es el FCST.
    $dist=norm_dist($g['distrito'] ?? ''); $v=0;
    $stmt=mysqli_prepare($conexion,"SELECT COALESCE(SUM(meta),0) total FROM metas_instalacion_semanal WHERE anio=? AND semana=? AND CASE WHEN UPPER(TRIM(distrito)) LIKE '%COATZA%' AND UPPER(TRIM(distrito)) LIKE '%MINA%' THEN 'COATZA / MINA' ELSE UPPER(TRIM(distrito)) END=?");
    if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$dist); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($r=mysqli_fetch_assoc($res))$v=(int)$r['total']; mysqli_stmt_close($stmt); }
    return $v;
}
function compromiso_por_id($conexion,$anio,$semana,$id_pos){
    $out=['cerrados'=>0,'borradores'=>0];
    $stmt=mysqli_prepare($conexion,"SELECT SUM(CASE WHEN estatus='CERRADO' THEN 1 ELSE 0 END) cerrados, SUM(CASE WHEN estatus='BORRADOR' THEN 1 ELSE 0 END) borradores FROM metas_fcst_compromiso_semanal WHERE anio=? AND semana=? AND id_posicion=?");
    if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$id_pos); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($r=mysqli_fetch_assoc($res)){ $out['cerrados']=(int)$r['cerrados']; $out['borradores']=(int)$r['borradores']; } mysqli_stmt_close($stmt); }
    return $out;
}

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : (int)date('W');
$sem_ant = $semana - 1; if($sem_ant<=0)$sem_ant=52;

$anio_actual_cal = (int)date('Y');
$semana_actual_cal = (int)date('W');
$semana_prev = $semana - 1; $anio_prev = $anio; if ($semana_prev <= 0) { $semana_prev = 52; $anio_prev--; }
$semana_next = $semana + 1; $anio_next = $anio; if ($semana_next > 52) { $semana_next = 1; $anio_next++; }

$rol = $_SESSION['rol'] ?? 'vendedor';
$id_posicion = $_SESSION['id_posicion'] ?? '';
$talento_gs = $_SESSION['numero_talento_gs'] ?? '';
$usuario_hc = cargar_hc_usuario($conexion,$id_posicion,$talento_gs,$semana,$anio);
$scope = scope_config($rol, $usuario_hc['posicion'] ?? '');
$grupos = cargar_grupos($conexion,$anio,$semana,$scope,$usuario_hc);

$rows=[]; $total_meta=0; $total_fcst=0; $total_real=0; $acc_sum=0; $acc_n=0; $riesgo_n=0; $alertas=[];
foreach($grupos as $g){
    $idg=(string)$g['id_posicion'];
    $fcst=forecast_por_id($conexion,$anio,$semana,$idg);
    $meta=meta_por_grupo($conexion,$anio,$semana,$scope,$g,$fcst);
    $real=real_por_grupo($conexion,$anio,$semana,$scope,$g);
    $comp=compromiso_por_id($conexion,$anio,$semana,$idg);
    $pm=div_pct($real,$meta); $pf=div_pct($real,$fcst); $ac=accuracy($real,$fcst);
    $label = ($scope['target']==='DIRECTOR_DISTRITAL') ? norm_dist($g['distrito'] ?? '') : ($g['nombre_colaborador'] ?? 'SIN NOMBRE');
    if($pm!==null && $pm<90){ $riesgo_n++; $alertas[]="🔴 $label está en riesgo vs META con ".fpct($pm)."."; }
    elseif($pm!==null && $pm<100){ $alertas[]="🟠 $label está en alerta vs META con ".fpct($pm)."."; }
    if($ac!==null && $ac<85 && $pf!==null && $pf<90) $alertas[]="⚠️ $label está por debajo de su FCST: ".fpct($pf).".";
    $total_meta+=$meta; $total_fcst+=$fcst; $total_real+=$real; if($ac!==null){$acc_sum+=$ac;$acc_n++;}
    $rows[]=['id_posicion'=>$idg,'distrito'=>$label,'meta'=>$meta,'fcst'=>$fcst,'real'=>$real,'pm'=>$pm,'pf'=>$pf,'ac'=>$ac,'cerrados'=>$comp['cerrados'],'borradores'=>$comp['borradores'],'cm'=>cls($pm),'cf'=>cls($pf),'riesgo'=>riesgo($pm)];
}
usort($rows, fn($a,$b)=>($a['pm']??-1)<=>($b['pm']??-1));
if(!$alertas)$alertas[]="✅ No hay alertas críticas detectadas para la semana analizada.";
$region_pm=div_pct($total_real,$total_meta); $region_pf=div_pct($total_real,$total_fcst); $region_acc=$acc_n?($acc_sum/$acc_n):null;

$labels=[]; $serie_meta=[]; $serie_fcst=[]; $serie_real=[]; $detalle_semanal=[];
foreach($grupos as $g){
    $label = ($scope['target']==='DIRECTOR_DISTRITAL') ? norm_dist($g['distrito'] ?? '') : ($g['nombre_colaborador'] ?? 'SIN NOMBRE');
    $detalle_semanal[$label]=[];
}
for($s=1;$s<=$semana;$s++){
    $tm=0;$tf=0;$tr=0;
    foreach($grupos as $g){
        $idg=(string)$g['id_posicion'];
        $fcst=forecast_por_id($conexion,$anio,$s,$idg);
        $meta=meta_por_grupo($conexion,$anio,$s,$scope,$g,$fcst);
        $real=real_por_grupo($conexion,$anio,$s,$scope,$g);
        $label = ($scope['target']==='DIRECTOR_DISTRITAL') ? norm_dist($g['distrito'] ?? '') : ($g['nombre_colaborador'] ?? 'SIN NOMBRE');
        $detalle_semanal[$label][$s]=['meta'=>$meta,'fcst'=>$fcst,'real'=>$real,'pm'=>div_pct($real,$meta),'pf'=>div_pct($real,$fcst)];
        $tm+=$meta; $tf+=$fcst; $tr+=$real;
    }
    $labels[]='S'.$s; $serie_real[]=$tr; $serie_meta[]=$tm?round($tr/$tm*100,1):null; $serie_fcst[]=$tf?round($tr/$tf*100,1):null;
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>METAS-FCST Dashboard - TOTALXPEDIENT</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=163">
<style>
:root{--p:#7A2BFF;--b:#2563eb;--card:rgba(255,255,255,.92);--bd:#e2e8f0;--txt:#1a2540;--mut:#6b7a99}*{box-sizing:border-box}body{margin:0;font-family:Poppins,'Segoe UI',sans-serif;background:linear-gradient(180deg,#f7f8ff,#eef5ff);color:var(--txt);display:flex}.header{display:flex;justify-content:space-between;gap:20px;margin-bottom:22px}.header h1{margin:0;font-size:1.7rem}.header p{margin:6px 0;color:var(--mut)}.box,.card,.kpi{background:var(--card);border:1px solid var(--bd);border-radius:22px;box-shadow:0 14px 32px rgba(22,28,60,.08)}.box{padding:18px 22px;min-width:260px}.label{font-size:.72rem;text-transform:uppercase;color:var(--mut);font-weight:900;letter-spacing:.7px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}.kpi{padding:20px}.value{font-size:2rem;font-weight:950;color:var(--p);margin-top:8px}.sub{font-size:.78rem;color:var(--mut);font-weight:800}.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:20px}.card{padding:22px;margin-bottom:20px}.full{grid-column:1/-1}.title{display:flex;justify-content:space-between;margin-bottom:16px}.title h2{margin:0;font-size:1.08rem}.title span{font-size:.78rem;color:var(--mut);font-weight:900}.heat{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}.dcard{border:1px solid var(--bd);background:white;border-radius:18px;padding:16px;border-left:8px solid #cbd5e1}.dcard.rojo{border-left-color:#ef4444}.dcard.amarillo{border-left-color:#fbbf24}.dcard.verde{border-left-color:#65a30d}.dcard.azul{border-left-color:#2563eb}.name{font-weight:950;color:#334155}.pct{font-size:1.8rem;font-weight:950}.risk{font-size:.72rem;font-weight:950;color:#64748b}.wrap{overflow-x:auto;border:1px solid var(--bd);border-radius:16px;background:white}table{border-collapse:collapse;width:100%;min-width:920px;font-size:.78rem}th,td{padding:11px 10px;border-bottom:1px solid var(--bd);text-align:center;white-space:nowrap}th{background:#f8fafc;color:#475569;font-size:.68rem;text-transform:uppercase}td:first-child,th:first-child{text-align:left;position:sticky;left:0;background:white;font-weight:900}.pill{border-radius:999px;padding:6px 10px;font-weight:950;font-size:.75rem}.rojo{background:#fee2e2;color:#991b1b}.amarillo{background:#fef3c7;color:#92400e}.verde{background:#dcfce7;color:#166534}.azul{background:#dbeafe;color:#1d4ed8}.gris{background:#e2e8f0;color:#475569}.alerts{display:flex;flex-direction:column;gap:10px}.alert{background:white;border:1px solid var(--bd);border-radius:16px;padding:13px 14px;font-weight:800;color:#334155;font-size:.86rem}.chart{width:100%;height:270px;background:white;border:1px solid var(--bd);border-radius:18px;padding:10px}.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:.78rem;font-weight:900;color:#64748b;margin-top:10px}.dot{width:12px;height:12px;border-radius:999px;display:inline-block;margin-right:6px;vertical-align:middle}.dot.real{background:#FF00B8}.dot.meta{background:#FF00B8}.dot.fcst{background:#7A2BFF}.week-nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.week-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:8px 12px;background:#fff;border:1px solid var(--bd);color:#1a2540;text-decoration:none;font-size:.78rem;font-weight:900;box-shadow:0 8px 18px rgba(22,28,60,.06)}.week-btn:hover{background:linear-gradient(135deg,rgba(122,43,255,.10),rgba(255,0,184,.08));border-color:#c4b5fd}.week-btn.active{color:#fff;background:linear-gradient(135deg,#7A2BFF,#FF00B8);border-color:transparent}.detalle-wrap{overflow-x:auto;border:1px solid var(--bd);border-radius:16px;background:white}.detalle-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:.72rem}.detalle-table th,.detalle-table td{padding:9px 8px;border-bottom:1px solid var(--bd);border-right:1px solid var(--bd);text-align:center;white-space:nowrap}.detalle-table th{background:#f8fafc;color:#475569;font-weight:950;text-transform:uppercase}.detalle-table .district-sticky{position:sticky;left:0;background:white;z-index:3;text-align:left;font-weight:950;min-width:160px}.detalle-table thead .district-sticky{background:#f8fafc;z-index:4}.detalle-table .week-group{background:linear-gradient(135deg,rgba(122,43,255,.10),rgba(255,0,184,.08));color:#1a2540;border-top:1px solid var(--bd)}.detalle-table .pct-cell{font-weight:900}.detalle-note{font-size:.78rem;color:var(--mut);font-weight:800;margin-top:10px}.total-row{font-weight:950;background:#f8fafc}@media(max-width:1100px){.kpis{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr}.header{flex-direction:column}}
</style></head><body>
<?php
$current_page = 'fcst_dashboard';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main">
<div class="header"><div><h1>🎯 METAS-FCST Executive Dashboard</h1><p>Tablero jerárquico de cumplimiento, forecast accuracy y alertas comerciales.</p></div><div class="box"><div class="label">Semana analizada</div><strong>SEM <?=h($semana)?> · <?=h($anio)?></strong><div class="sub">Semana anterior: SEM <?=h($sem_ant)?><br>Vista: <?=h($scope['titulo'])?></div><div class="week-nav"><a class="week-btn" href="?anio=<?=h($anio_prev)?>&semana=<?=h($semana_prev)?>">← SEM <?=h($semana_prev)?></a><a class="week-btn active" href="?anio=<?=h($anio)?>&semana=<?=h($semana)?>">SEM <?=h($semana)?></a><a class="week-btn" href="?anio=<?=h($anio_next)?>&semana=<?=h($semana_next)?>">SEM <?=h($semana_next)?> →</a></div></div></div>
<section class="kpis"><div class="kpi"><div class="label">Cumplimiento META Región</div><div class="value"><?=h(fpct($region_pm))?></div><div class="sub">Real <?=h(fmt($total_real))?> / Meta <?=h(fmt($total_meta))?></div></div><div class="kpi"><div class="label">Cumplimiento FCST Región</div><div class="value"><?=h(fpct($region_pf))?></div><div class="sub">Real <?=h(fmt($total_real))?> / FCST <?=h(fmt($total_fcst))?></div></div><div class="kpi"><div class="label">Distritos en Riesgo</div><div class="value"><?=h($riesgo_n)?></div><div class="sub">Por debajo de 90% vs meta</div></div><div class="kpi"><div class="label">Forecast Accuracy</div><div class="value"><?=h(fpct($region_acc))?></div><div class="sub">Promedio distrital</div></div></section>
<div class="grid"><section class="card"><div class="title"><h2>Semáforo jerárquico · <?=h($scope['titulo'])?></h2><span>% cumplimiento vs META</span></div><div class="heat"><?php foreach($rows as $r): ?><div class="dcard <?=h($r['cm'])?>"><div class="name"><?=h($r['distrito'])?></div><div class="pct"><?=h(fpct($r['pm']))?></div><div class="risk"><?=h($r['riesgo'])?></div></div><?php endforeach; ?></div></section>
<section class="card"><div class="title"><h2>Alertas automáticas</h2><span>Riesgos y desviaciones</span></div><div class="alerts"><?php foreach(array_slice($alertas,0,8) as $a): ?><div class="alert"><?=h($a)?></div><?php endforeach; ?></div></section>
<section class="card full"><div class="title"><h2>Tabla ejecutiva jerárquica · SEM <?=h($semana)?> <?=h($anio)?></h2><span>Meta · FCST · Real · Accuracy</span></div><div class="wrap"><table><thead><tr><th><?=h($scope['label'])?></th><th>Meta</th><th>FCST</th><th>Real</th><th>% Meta</th><th>% FCST</th><th>Accuracy</th><th>Cerrados</th><th>Borradores</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=h($r['distrito'])?></td><td><?=h(fmt($r['meta']))?></td><td><?=h(fmt($r['fcst']))?></td><td><?=h(fmt($r['real']))?></td><td><span class="pill <?=h($r['cm'])?>"><?=h(fpct($r['pm']))?></span></td><td><span class="pill <?=h($r['cf'])?>"><?=h(fpct($r['pf']))?></span></td><td><?=h(fpct($r['ac']))?></td><td><?=h($r['cerrados'])?></td><td><?=h($r['borradores'])?></td></tr><?php endforeach; ?>
<tr class="total-row">
<td>TOTAL</td>
<td><?=h(fmt($total_meta))?></td>
<td><?=h(fmt($total_fcst))?></td>
<td><?=h(fmt($total_real))?></td>
<td><span class="pill <?=h(cls($region_pm))?>"><?=h(fpct($region_pm))?></span></td>
<td><span class="pill <?=h(cls($region_pf))?>"><?=h(fpct($region_pf))?></span></td>
<td><?=h(fpct($region_acc))?></td>
<td><?=h(array_sum(array_column($rows,'cerrados')))?></td>
<td><?=h(array_sum(array_column($rows,'borradores')))?></td>
</tr>
</tbody></table></div></section>
<section class="card full"><div class="title"><h2>Tendencia jerárquica 2026</h2><span>Instalaciones reales SEM <?=h($semana)?>: <?=h(fmt($total_real))?> · Barras: real · Líneas: %META y %FCST</span></div><canvas id="chartRegional" class="chart"></canvas><div class="legend"><span><i class="dot real"></i>Instalaciones reales</span><span><i class="dot meta"></i>% META</span><span><i class="dot fcst"></i>% FCST</span></div></section>

<section class="card full">
<div class="title"><h2>Detalle semanal jerárquico · 2026</h2><span>SEM 1 a SEM <?=h($semana)?> · Meta · FCST · Real · %META · %FCST</span></div>
<div class="detalle-wrap">
<table class="detalle-table">
<thead>
<tr><th class="district-sticky" rowspan="2"><?=h($scope['label'])?></th><?php for($s=1;$s<=$semana;$s++): ?><th class="week-group" colspan="5">SEM <?=h($s)?></th><?php endfor; ?></tr>
<tr><?php for($s=1;$s<=$semana;$s++): ?><th>Meta</th><th>FCST</th><th>Real</th><th>% Meta</th><th>% FCST</th><?php endfor; ?></tr>
</thead>
<tbody>
<?php foreach($detalle_semanal as $dist=>$semanas): ?>
<tr><td class="district-sticky"><?=h($dist)?></td><?php for($s=1;$s<=$semana;$s++): $dsem=$semanas[$s]??['meta'=>0,'fcst'=>0,'real'=>0,'pm'=>null,'pf'=>null]; ?><td><?=h(fmt($dsem['meta']))?></td><td><?=h(fmt($dsem['fcst']))?></td><td><?=h(fmt($dsem['real']))?></td><td class="pct-cell"><span class="pill <?=h(cls($dsem['pm']))?>"><?=h(fpct($dsem['pm']))?></span></td><td class="pct-cell"><span class="pill <?=h(cls($dsem['pf']))?>"><?=h(fpct($dsem['pf']))?></span></td><?php endfor; ?></tr>
<?php endforeach; ?>
<tr class="total-row"><td class="district-sticky">TOTAL</td><?php for($s=1;$s<=$semana;$s++): $tm=0;$tf=0;$tr=0; foreach($detalle_semanal as $dist=>$semanas){$tm+=(int)($semanas[$s]['meta']??0);$tf+=(int)($semanas[$s]['fcst']??0);$tr+=(int)($semanas[$s]['real']??0);} $tpm=div_pct($tr,$tm); $tpf=div_pct($tr,$tf); ?><td><?=h(fmt($tm))?></td><td><?=h(fmt($tf))?></td><td><?=h(fmt($tr))?></td><td class="pct-cell"><span class="pill <?=h(cls($tpm))?>"><?=h(fpct($tpm))?></span></td><td class="pct-cell"><span class="pill <?=h(cls($tpf))?>"><?=h(fpct($tpf))?></span></td><?php endfor; ?></tr>
</tbody>
</table>
</div>
<div class="detalle-note">Optimizado con consultas agregadas por distrito y semana. La tabla se desplaza horizontalmente.</div>
</section></div>
</main>
<script>
const labels=<?=json_encode($labels)?>;
const serieReal=<?=json_encode($serie_real,JSON_NUMERIC_CHECK)?>;
const serieMeta=<?=json_encode($serie_meta,JSON_NUMERIC_CHECK)?>;
const serieFcst=<?=json_encode($serie_fcst,JSON_NUMERIC_CHECK)?>;

(function(){
    const c=document.getElementById('chartRegional');
    if(!c)return;

    const ctx=c.getContext('2d');
    const dpr=window.devicePixelRatio||1;
    const r=c.getBoundingClientRect();

    c.width=r.width*dpr;
    c.height=r.height*dpr;
    ctx.scale(dpr,dpr);

    const w=r.width,h=r.height,padL=48,padR=52,padT=22,padB=34;
    const chartW=w-padL-padR;
    const chartH=h-padT-padB;

    const maxReal=Math.max(10,...serieReal.filter(v=>v!==null&&!isNaN(v)));
    const pctVals=serieMeta.concat(serieFcst).filter(v=>v!==null&&!isNaN(v));
    const maxPct=Math.max(140,...pctVals);
    const minPct=0;

    function x(i,total){return total<=1?padL:padL+i*chartW/(total-1);}
    function yPct(v){return v===null||isNaN(v)?null:padT+chartH-((v-minPct)/(maxPct-minPct))*chartH;}
    function yReal(v){return padT+chartH-(v/maxReal)*chartH;}

    ctx.clearRect(0,0,w,h);
    ctx.font='11px Segoe UI';
    ctx.lineWidth=1;

    [0,50,90,100,120,140].forEach(v=>{
        const yy=yPct(v);
        ctx.strokeStyle='#e2e8f0';
        ctx.beginPath();ctx.moveTo(padL,yy);ctx.lineTo(w-padR,yy);ctx.stroke();
        ctx.fillStyle='#64748b';ctx.fillText(v+'%',5,yy+4);
    });

    [0, Math.round(maxReal/2), maxReal].forEach(v=>{
        const yy=yReal(v);
        ctx.fillStyle='#0f766e';
        ctx.fillText(String(v), w-padR+10, yy+4);
    });

    const total=labels.length;
    const barW=Math.max(8, Math.min(22, chartW/(Math.max(total,1)*1.8)));

    serieReal.forEach((v,i)=>{
        if(v===null||isNaN(v))return;
        const xx=x(i,total)-barW/2;
        const yy=yReal(v);
        const hh=padT+chartH-yy;
        ctx.fillStyle='#FF00B8';
        ctx.fillRect(xx,yy,barW,hh);

        ctx.fillStyle='#334155';
        ctx.font='10px Segoe UI';
        ctx.textAlign='center';
        ctx.fillText(String(v), xx + barW/2, yy - 6);
        ctx.textAlign='left';
    });

    function line(series,color){
        ctx.strokeStyle=color;
        ctx.lineWidth=3;
        ctx.beginPath();
        let st=false;
        series.forEach((v,i)=>{
            const yy=yPct(v);
            if(yy===null)return;
            const xx=x(i,total);
            if(!st){ctx.moveTo(xx,yy);st=true}else ctx.lineTo(xx,yy);
        });
        ctx.stroke();

        series.forEach((v,i)=>{
            const yy=yPct(v);
            if(yy===null)return;
            const xx=x(i,total);
            ctx.beginPath();
            ctx.arc(xx,yy,3.6,0,Math.PI*2);
            ctx.fillStyle=color;
            ctx.fill();
            ctx.strokeStyle='white';
            ctx.lineWidth=1.4;
            ctx.stroke();
        });
    }

    line(serieMeta,'#FF00B8');
    line(serieFcst,'#7A2BFF');

    ctx.fillStyle='#64748b';
    ctx.font='10px Segoe UI';
    labels.forEach((lb,i)=>{
        if(i%2!==0 && labels.length>14)return;
        ctx.fillText(lb, x(i,total)-8, h-10);
    });

    ctx.fillStyle='#64748b';
    ctx.fillText('% cumplimiento', 5, 12);
    ctx.fillText('Inst.', w-45, 12);
})();
</script></body></html>