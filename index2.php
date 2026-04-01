<?php
ini_set('display_errors', 0);
error_reporting(0);
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}

include 'conexion.php';

// --- CONFIGURACIÓN DE USUARIO Y ROLES ---
$rol            = $_SESSION['rol'] ?? 'vendedor';
$talento_gs     = $_SESSION['numero_talento_gs'] ?? '';
$id_posicion    = $_SESSION['id_posicion'] ?? '';
$nombre_usuario = $_SESSION['usuario'] ?? '';

$puestos_comerciales = "'PROMOVENDEDOR PUNTO DE VENTA','VENDEDOR','VENDEDOR NEGOCIOS','VENDEDOR NEGOCIO'";

// --- FUNCIONES DE JERARQUÍA ---
function getSubordinados($conexion, $id_pos, $semana = null, $anio = null) {
    $ids = [];
    if ($semana && $anio) {
        $stmt = mysqli_prepare($conexion, "SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr = ? AND numero_talento_gs NOT LIKE '%VACANTE%' AND semana = ? AND anio = ?");
        mysqli_stmt_bind_param($stmt, "sii", $id_pos, $semana, $anio);
    } else {
        $stmt = mysqli_prepare($conexion, "SELECT DISTINCT id_posicion FROM hc WHERE posicion_lr = ? AND numero_talento_gs NOT LIKE '%VACANTE%'");
        mysqli_stmt_bind_param($stmt, "s", $id_pos);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $ids[] = $row['id_posicion'];
    mysqli_stmt_close($stmt);
    return $ids;
}

function getTodosSubordinados($conexion, $id_pos, $niveles_restantes, $semana = null, $anio = null) {
    if ($niveles_restantes <= 0) return [];
    $directos = getSubordinados($conexion, $id_pos, $semana, $anio);
    $todos = $directos;
    foreach ($directos as $id) {
        $sub = getTodosSubordinados($conexion, $id, $niveles_restantes - 1, $semana, $anio);
        $todos = array_merge($todos, $sub);
    }
    return array_unique($todos);
}

// --- TIEMPO Y FECHAS ---
$semana_actual = null; $anio_actual = null; $semana_base = null;
$res_sem = mysqli_query($conexion, "SELECT semana, anio FROM hc ORDER BY anio DESC, semana DESC LIMIT 1");
if ($res_sem && $row_sem = mysqli_fetch_assoc($res_sem)) {
    $semana_base   = (int)$row_sem['semana'];
    $anio_actual   = (int)$row_sem['anio'];
    $semana_actual = $semana_base;
}

$niveles = ['admin'=>6,'director_regional'=>5,'director_distrital'=>4,'lider'=>3,'coach'=>2,'vendedor'=>1];
$nivel   = $niveles[$rol] ?? 1;

$stmt_nombre = mysqli_prepare($conexion, "SELECT nombre_colaborador, posicion, distrito FROM hc WHERE id_posicion = ? LIMIT 1");
if ($stmt_nombre) {
    mysqli_stmt_bind_param($stmt_nombre, "s", $id_posicion);
    mysqli_stmt_execute($stmt_nombre);
    $res_nombre = mysqli_stmt_get_result($stmt_nombre);
    if ($row_nombre = mysqli_fetch_assoc($res_nombre)) {
        $nombre_completo  = $row_nombre['nombre_colaborador'] ?? $nombre_usuario;
        $posicion_usuario = $row_nombre['posicion'] ?? '';
        $distrito_usuario = $row_nombre['distrito'] ?? '';
    }
    mysqli_stmt_close($stmt_nombre);
}

// --- FILTRADO POR JERARQUÍA ---
$subordinados_ids = [];
$folio_ids        = [];
if ($rol !== 'admin') {
    $subordinados_ids = getTodosSubordinados($conexion, $id_posicion, $nivel, $semana_actual, $anio_actual);
    $subordinados_ids[] = $id_posicion;
    $subordinados_ids = array_unique(array_values($subordinados_ids));
    if (!empty($subordinados_ids)) {
        $ph_sub = implode(',', array_fill(0, count($subordinados_ids), '?'));
        $stmt_folios = mysqli_prepare($conexion, "SELECT DISTINCT numero_talento_gs FROM hc WHERE id_posicion IN ($ph_sub) AND numero_talento_gs NOT LIKE '%VACANTE%'");
        $tipos_sub = str_repeat('s', count($subordinados_ids));
        mysqli_stmt_bind_param($stmt_folios, $tipos_sub, ...array_values($subordinados_ids));
        mysqli_stmt_execute($stmt_folios);
        $res_folios = mysqli_stmt_get_result($stmt_folios);
        while ($row_f = mysqli_fetch_assoc($res_folios)) $folio_ids[] = $row_f['numero_talento_gs'];
        mysqli_stmt_close($stmt_folios);
    }
}

$mes_actual   = (int)date('n', strtotime('-1 day'));
$anio_query   = (int)date('Y', strtotime('-1 day'));
$distrito_esc = mysqli_real_escape_string($conexion, $distrito_usuario);
$por_distrito = in_array($rol, ['admin', 'director_regional', 'director_distrital']);
$mostrar_meta = $por_distrito;

// --- EXTRACCIÓN DE KPIs (INSTALACIONES, VENTAS, HC) ---
// (Lógica abreviada por espacio, idéntica a tu index1.php original)
// ... consultas de $kpi_inst, $kpi_vent, $kpi_hc_act ...

// --- LÓGICA DE META Y VELOCÍMETRO ---
$ayer_timestamp = strtotime('-1 day');
$dias_transcurridos = (int)date('j', $ayer_timestamp);
$kpi_meta_acum = 0;
$kpi_meta_pct  = 0;

if ($mostrar_meta) {
    $query_meta = ($rol === 'admin') 
        ? "SELECT SUM(meta_diaria) as meta_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1"
        : "SELECT SUM(meta_diaria) as meta_total FROM metas_instalacion WHERE mes_num=$mes_actual AND anio=$anio_query AND dia=1 AND distrito='$distrito_esc'";
    
    $r_meta = mysqli_query($conexion, $query_meta);
    if ($row_meta = mysqli_fetch_assoc($r_meta)) {
        $meta_diaria = (float)($row_meta['meta_total'] ?? 0);
        $kpi_meta_acum = round($meta_diaria * $dias_transcurridos);
        $kpi_meta_pct  = $kpi_meta_acum > 0 ? round(($kpi_inst / $kpi_meta_acum) * 100) : 0;
    }
}

// Cálculo de aguja: tope visual 100% (180 grados), valor real en texto
$porcentaje_visual = min((float)$kpi_meta_pct, 100);
$angulo_aguja = ($porcentaje_visual / 100 * 180) - 90;
$color_pct = ($kpi_meta_pct >= 100) ? 'var(--green)' : (($kpi_meta_pct >= 85) ? '#f59e0b' : 'var(--red)');

$roles_labels = ['admin'=>'Administrador','director_regional'=>'Director Regional','director_distrital'=>'Director Distrital','lider'=>'Líder','coach'=>'Coach','vendedor'=>'Vendedor'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — TOTALXPEDIENT</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        :root { --blue:#2b57a7; --blue2:#3b66b8; --bg:#f4f6fb; --white:#ffffff; --text:#1a2540; --text2:#6b7a99; --border:#e2e8f4; --green:#10b981; --purple:#7c3aed; --red:#ef4444; --sidebar:200px; }
        body { font-family:'Segoe UI',sans-serif; background:var(--bg); display:flex; margin:0; }
        .sidebar { width:var(--sidebar); background:var(--blue); min-height:100vh; position:fixed; padding:28px 0; color:white; display:flex; flex-direction:column; align-items:center; }
        .main { margin-left:var(--sidebar); flex:1; padding:32px; }
        .kpi-grid { display:grid; grid-template-columns: 1fr 1fr; gap:20px; }
        .kpi-card { background:var(--white); border-radius:16px; padding:24px; border:1px solid var(--border); }
        .full { grid-column: span 2; }
        
        /* VELOCÍMETRO ESTILO DASHBOARD */
        .speed-layout { display:flex; align-items:flex-end; justify-content:space-around; margin-top:20px; }
        .speed-container { position:relative; width:220px; height:110px; overflow:hidden; }
        .speed-arc { 
            width:220px; height:220px; border-radius:50%; 
            background: conic-gradient(from -90deg, var(--red) 0deg 153deg, #f59e0b 153deg 180deg, var(--green) 180deg 200deg, transparent 180deg);
        }
        .speed-center { position:absolute; top:22px; left:22px; width:176px; height:176px; background:var(--white); border-radius:50%; }
        .needle { 
            position:absolute; bottom:0; left:50%; width:4px; height:90px; background:var(--text); 
            transform-origin:bottom center; transition: transform 1.5s ease-out; z-index:5; 
        }
        .pct-float { position:absolute; bottom:10px; right:0; font-size:1.2rem; font-weight:800; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div style="font-size:2rem;">📊</div>
        <div style="font-weight:800; font-size:0.7rem; letter-spacing:1px;">TOTALXPEDIENT</div>
    </aside>

    <main class="main">
        <div class="kpi-grid">
            <?php if ($mostrar_meta): ?>
            <div class="kpi-card full">
                <div style="font-weight:700; margin-bottom:15px;">Avance vs Meta — Día <?= $dias_transcurridos ?></div>
                <div class="speed-layout">
                    <div class="speed-container">
                        <div class="speed-arc"></div>
                        <div class="speed-center"></div>
                        <div class="needle" style="transform: translateX(-50%) rotate(<?= $angulo_aguja ?>deg);"></div>
                        <div class="pct-float" style="color:<?= $color_pct ?>;"><?= $kpi_meta_pct ?>%</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:2.8rem; font-weight:800; color:var(--blue2);"><?= number_format($kpi_inst) ?></div>
                        <div style="color:var(--text2); font-size:0.8rem;">Instalaciones Reales</div>
                        <div style="margin-top:10px; font-size:1.2rem; color:#f59e0b; font-weight:700;"><?= number_format($kpi_meta_acum) ?></div>
                        <div style="color:var(--text2); font-size:0.7rem;">Meta Acumulada</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>