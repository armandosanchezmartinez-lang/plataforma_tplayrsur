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

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : (int)date('W');
$sem_ant = $semana - 1; if($sem_ant<=0)$sem_ant=52;

$distritos=[];
$sql="SELECT distrito FROM metas_instalacion_semanal WHERE anio=? AND semana=?
UNION SELECT distrito FROM metas_forecast_semanal WHERE anio=? AND semana=?
UNION SELECT distrito FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND distrito IS NOT NULL AND distrito<>''
ORDER BY distrito";
$stmt=mysqli_prepare($conexion,$sql);
mysqli_stmt_bind_param($stmt,"iiiiii",$anio,$semana,$anio,$semana,$anio,$semana);
mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
while($r=mysqli_fetch_assoc($res)) $distritos[]=$r['distrito'];
mysqli_stmt_close($stmt);

$rows=[]; $total_meta=0; $total_fcst=0; $total_real=0; $acc_sum=0; $acc_n=0; $riesgo_n=0; $alertas=[];
foreach($distritos as $d){
    $meta=0; $fcst=0; $real=0; $cerrados=0; $borradores=0;

    $stmt=mysqli_prepare($conexion,"SELECT COALESCE(SUM(meta),0) total FROM metas_instalacion_semanal WHERE anio=? AND semana=? AND distrito=?");
    mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$d); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
    if($r=mysqli_fetch_assoc($res))$meta=(int)$r['total']; mysqli_stmt_close($stmt);

    $stmt=mysqli_prepare($conexion,"SELECT COALESCE(SUM(forecast),0) total FROM metas_forecast_semanal WHERE anio=? AND semana=? AND distrito=?");
    mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$d); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
    if($r=mysqli_fetch_assoc($res))$fcst=(int)$r['total']; mysqli_stmt_close($stmt);

    $stmt=mysqli_prepare($conexion,"SELECT COUNT(cuenta) total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND distrito=? AND origen_prospecto <> '-'");
    mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$d); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
    if($r=mysqli_fetch_assoc($res))$real=(int)$r['total']; mysqli_stmt_close($stmt);

    $stmt=mysqli_prepare($conexion,"SELECT SUM(CASE WHEN c.estatus='CERRADO' THEN 1 ELSE 0 END) cerrados, SUM(CASE WHEN c.estatus='BORRADOR' THEN 1 ELSE 0 END) borradores FROM metas_fcst_compromiso_semanal c INNER JOIN metas_forecast_semanal f ON f.id=c.forecast_id WHERE c.anio=? AND c.semana=? AND f.distrito=?");
    if($stmt){ mysqli_stmt_bind_param($stmt,"iis",$anio,$semana,$d); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($r=mysqli_fetch_assoc($res)){ $cerrados=(int)$r['cerrados']; $borradores=(int)$r['borradores']; } mysqli_stmt_close($stmt); }

    $pm=div_pct($real,$meta); $pf=div_pct($real,$fcst); $ac=accuracy($real,$fcst);
    if($pm!==null && $pm<90){ $riesgo_n++; $alertas[]="🔴 $d está en riesgo vs META con ".fpct($pm)."."; }
    elseif($pm!==null && $pm<100){ $alertas[]="🟠 $d está en alerta vs META con ".fpct($pm)."."; }
    if($ac!==null && $ac<85 && $pf!==null && $pf<90) $alertas[]="⚠️ $d está por debajo de su FCST: ".fpct($pf).".";

    $total_meta+=$meta; $total_fcst+=$fcst; $total_real+=$real; if($ac!==null){$acc_sum+=$ac;$acc_n++;}
    $rows[]=['distrito'=>$d,'meta'=>$meta,'fcst'=>$fcst,'real'=>$real,'pm'=>$pm,'pf'=>$pf,'ac'=>$ac,'cerrados'=>$cerrados,'borradores'=>$borradores,'cm'=>cls($pm),'cf'=>cls($pf),'riesgo'=>riesgo($pm)];
}
usort($rows, fn($a,$b)=>($a['pm']??-1)<=>($b['pm']??-1));
if(!$alertas)$alertas[]="✅ No hay alertas críticas detectadas para la semana actual.";

$region_pm=div_pct($total_real,$total_meta); $region_pf=div_pct($total_real,$total_fcst); $region_acc=$acc_n?($acc_sum/$acc_n):null;

$labels=[]; $serie_meta=[]; $serie_fcst=[]; $serie_real=[];
for($s=1;$s<=$semana;$s++){
    $m=0;$f=0;$r=0;
    $stmt=mysqli_prepare($conexion,"SELECT COALESCE(SUM(meta),0) total FROM metas_instalacion_semanal WHERE anio=? AND semana=?");
    mysqli_stmt_bind_param($stmt,"ii",$anio,$s); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($x=mysqli_fetch_assoc($res))$m=(int)$x['total']; mysqli_stmt_close($stmt);
    $stmt=mysqli_prepare($conexion,"SELECT COALESCE(SUM(forecast),0) total FROM metas_forecast_semanal WHERE anio=? AND semana=?");
    mysqli_stmt_bind_param($stmt,"ii",$anio,$s); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($x=mysqli_fetch_assoc($res))$f=(int)$x['total']; mysqli_stmt_close($stmt);
    $stmt=mysqli_prepare($conexion,"SELECT COUNT(cuenta) total FROM instalaciones WHERE YEAR(fecha)=? AND WEEK(fecha,1)=? AND origen_prospecto <> '-'");
    mysqli_stmt_bind_param($stmt,"ii",$anio,$s); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt); if($x=mysqli_fetch_assoc($res))$r=(int)$x['total']; mysqli_stmt_close($stmt);
    $labels[]='S'.$s; $serie_real[]=$r; $serie_meta[]=$m?round($r/$m*100,1):null; $serie_fcst[]=$f?round($r/$f*100,1):null;
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>METAS-FCST Dashboard - TOTALXPEDIENT</title>
<link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=162">
<style>
:root{--p:#7A2BFF;--b:#2563eb;--card:rgba(255,255,255,.92);--bd:#e2e8f0;--txt:#1a2540;--mut:#6b7a99;--side:200px}*{box-sizing:border-box}body{margin:0;font-family:Poppins,'Segoe UI',sans-serif;background:linear-gradient(180deg,#f7f8ff,#eef5ff);color:var(--txt);display:flex}.sidebar{width:var(--side);background:linear-gradient(180deg,#1b2d5a,#102046);min-height:100vh;position:fixed;padding:24px 0;display:flex;flex-direction:column;align-items:center}.sidebar-logo{width:92px;height:92px;border-radius:24px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;padding:10px}.sidebar-logo img{max-width:100%;max-height:100%;object-fit:contain}.sidebar-brand{color:white;font-weight:800;font-size:.78rem;margin:10px 0 28px}.nav-item{width:100%;display:flex;flex-direction:column;align-items:center;gap:5px;padding:14px 0;color:rgba(255,255,255,.7);text-decoration:none;font-weight:700;font-size:.78rem}.nav-item.active,.nav-item:hover{background:rgba(255,255,255,.11);color:white}.nav-icon{font-size:1.25rem}.main{margin-left:var(--side);width:calc(100% - var(--side));padding:30px}.header{display:flex;justify-content:space-between;gap:20px;margin-bottom:22px}.header h1{margin:0;font-size:1.7rem}.header p{margin:6px 0;color:var(--mut)}.box,.card,.kpi{background:var(--card);border:1px solid var(--bd);border-radius:22px;box-shadow:0 14px 32px rgba(22,28,60,.08)}.box{padding:18px 22px;min-width:260px}.label{font-size:.72rem;text-transform:uppercase;color:var(--mut);font-weight:900;letter-spacing:.7px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}.kpi{padding:20px}.value{font-size:2rem;font-weight:950;color:var(--p);margin-top:8px}.sub{font-size:.78rem;color:var(--mut);font-weight:800}.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:20px}.card{padding:22px;margin-bottom:20px}.full{grid-column:1/-1}.title{display:flex;justify-content:space-between;margin-bottom:16px}.title h2{margin:0;font-size:1.08rem}.title span{font-size:.78rem;color:var(--mut);font-weight:900}.heat{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}.dcard{border:1px solid var(--bd);background:white;border-radius:18px;padding:16px;border-left:8px solid #cbd5e1}.dcard.rojo{border-left-color:#ef4444}.dcard.amarillo{border-left-color:#fbbf24}.dcard.verde{border-left-color:#65a30d}.dcard.azul{border-left-color:#2563eb}.name{font-weight:950;color:#334155}.pct{font-size:1.8rem;font-weight:950}.risk{font-size:.72rem;font-weight:950;color:#64748b}.wrap{overflow-x:auto;border:1px solid var(--bd);border-radius:16px;background:white}table{border-collapse:collapse;width:100%;min-width:920px;font-size:.78rem}th,td{padding:11px 10px;border-bottom:1px solid var(--bd);text-align:center;white-space:nowrap}th{background:#f8fafc;color:#475569;font-size:.68rem;text-transform:uppercase}td:first-child,th:first-child{text-align:left;position:sticky;left:0;background:white;font-weight:900}.pill{border-radius:999px;padding:6px 10px;font-weight:950;font-size:.75rem}.rojo{background:#fee2e2;color:#991b1b}.amarillo{background:#fef3c7;color:#92400e}.verde{background:#dcfce7;color:#166534}.azul{background:#dbeafe;color:#1d4ed8}.gris{background:#e2e8f0;color:#475569}.alerts{display:flex;flex-direction:column;gap:10px}.alert{background:white;border:1px solid var(--bd);border-radius:16px;padding:13px 14px;font-weight:800;color:#334155;font-size:.86rem}.chart{width:100%;height:270px;background:white;border:1px solid var(--bd);border-radius:18px;padding:10px}.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:.78rem;font-weight:900;color:#64748b;margin-top:10px}.dot{width:12px;height:12px;border-radius:999px;display:inline-block;margin-right:6px;vertical-align:middle}.dot.real{background:#00D8FF}.dot.meta{background:#FF00B8}.dot.fcst{background:#7A2BFF}@media(max-width:1100px){.kpis{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr}.header{flex-direction:column}.main{padding:22px}}
</style></head><body>
<aside class="sidebar"><div class="sidebar-logo"><img src="../logotipo_xpedient_blanco.png" alt="TOTALXPEDIENT"></div><div class="sidebar-brand">TOTALXPEDIENT</div>
<a href="../index.php" class="nav-item"><span class="nav-icon">⊞</span>Dashboard</a>
<a href="ranking_productividad.php?anio=<?=h($anio)?>&semana=<?=h($semana)?>" class="nav-item"><span class="nav-icon">🏆</span>Ranking</a>
<a href="hc_detalle.php" class="nav-item"><span class="nav-icon">👥</span>Headcount</a>
<a href="reai.php" class="nav-item"><span class="nav-icon">📋</span>REAI</a>
<a href="metas_fcst.php" class="nav-item"><span class="nav-icon">🎯</span>Captura FCST</a>
<a href="metas_fcst_dashboard.php" class="nav-item active"><span class="nav-icon">🚦</span>Dashboard FCST</a></aside>
<main class="main">
<div class="header"><div><h1>🚦 METAS-FCST Executive Dashboard</h1><p>Tablero ejecutivo regional de cumplimiento, forecast accuracy y alertas comerciales.</p></div><div class="box"><div class="label">Semana actual</div><strong>SEM <?=h($semana)?> · <?=h($anio)?></strong><div class="sub">Semana anterior: SEM <?=h($sem_ant)?></div></div></div>
<section class="kpis"><div class="kpi"><div class="label">Cumplimiento META Región</div><div class="value"><?=h(fpct($region_pm))?></div><div class="sub">Real <?=h(fmt($total_real))?> / Meta <?=h(fmt($total_meta))?></div></div><div class="kpi"><div class="label">Cumplimiento FCST Región</div><div class="value"><?=h(fpct($region_pf))?></div><div class="sub">Real <?=h(fmt($total_real))?> / FCST <?=h(fmt($total_fcst))?></div></div><div class="kpi"><div class="label">Distritos en Riesgo</div><div class="value"><?=h($riesgo_n)?></div><div class="sub">Por debajo de 90% vs meta</div></div><div class="kpi"><div class="label">Forecast Accuracy</div><div class="value"><?=h(fpct($region_acc))?></div><div class="sub">Promedio distrital</div></div></section>
<div class="grid"><section class="card"><div class="title"><h2>Semáforo regional por distrito</h2><span>% cumplimiento vs META</span></div><div class="heat"><?php foreach($rows as $r): ?><div class="dcard <?=h($r['cm'])?>"><div class="name"><?=h($r['distrito'])?></div><div class="pct"><?=h(fpct($r['pm']))?></div><div class="risk"><?=h($r['riesgo'])?></div></div><?php endforeach; ?></div></section>
<section class="card"><div class="title"><h2>Alertas automáticas</h2><span>Riesgos y desviaciones</span></div><div class="alerts"><?php foreach(array_slice($alertas,0,8) as $a): ?><div class="alert"><?=h($a)?></div><?php endforeach; ?></div></section>
<section class="card full"><div class="title"><h2>Tabla ejecutiva de cumplimiento · SEM <?=h($semana)?> <?=h($anio)?></h2><span>Meta · FCST · Real · Accuracy</span></div><div class="wrap"><table><thead><tr><th>Distrito</th><th>Meta</th><th>FCST</th><th>Real</th><th>% Meta</th><th>% FCST</th><th>Accuracy</th><th>Cerrados</th><th>Borradores</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=h($r['distrito'])?></td><td><?=h(fmt($r['meta']))?></td><td><?=h(fmt($r['fcst']))?></td><td><?=h(fmt($r['real']))?></td><td><span class="pill <?=h($r['cm'])?>"><?=h(fpct($r['pm']))?></span></td><td><span class="pill <?=h($r['cf'])?>"><?=h(fpct($r['pf']))?></span></td><td><?=h(fpct($r['ac']))?></td><td><?=h($r['cerrados'])?></td><td><?=h($r['borradores'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card full"><div class="title"><h2>Tendencia regional 2026</h2><span>Barras: instalaciones reales · Líneas: %META y %FCST</span></div><canvas id="chartRegional" class="chart"></canvas><div class="legend"><span><i class="dot real"></i>Instalaciones reales</span><span><i class="dot meta"></i>% META</span><span><i class="dot fcst"></i>% FCST</span></div></section></div>
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
        const grad=ctx.createLinearGradient(0,yy,0,padT+chartH);
        grad.addColorStop(0,'#00D8FF');
        grad.addColorStop(1,'rgba(0,216,255,.28)');
        ctx.fillStyle=grad;
        ctx.fillRect(xx,yy,barW,hh);
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