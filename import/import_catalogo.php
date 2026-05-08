<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

$mensaje      = '';
$tipo_mensaje = '';
$stats        = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    $ext     = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        $mensaje      = 'Solo se permiten archivos .xlsx';
        $tipo_mensaje = 'error';
    } else {
        $ruta_temp = '../uploads/' . time() . '_' . $archivo['name'];
        move_uploaded_file($archivo['tmp_name'], $ruta_temp);

        if ($xlsx = SimpleXLSX::parse($ruta_temp)) {

            // Leer hoja "Hoja2" (índice 0) — nombre_plan | PLAY | TIPO
            $sheet_idx = 0;
            $filas = $xlsx->rows($sheet_idx);

            $total     = 0;
            $omitidos  = 0;
            $errores   = 0;
            $detalle_errores = [];

            $plays_validos = ['DOBLE PLAY', 'TRIPLE PLAY'];
            $tipos_validos = ['RESIDENCIAL', 'NEGOCIOS'];

            // Saltar fila 0 (encabezado)
            for ($i = 1; $i < count($filas); $i++) {
                $f = $filas[$i];
                if (empty(array_filter($f))) continue;

                $nombre_plan = isset($f[0]) ? trim($f[0]) : '';
                $play        = isset($f[1]) ? strtoupper(trim($f[1])) : '';
                $tipo        = isset($f[2]) ? strtoupper(trim($f[2])) : '';

                if (!$nombre_plan) { $omitidos++; continue; }

                // Normalizar valores permitidos
                if (!in_array($play, $plays_validos)) $play = 'DOBLE PLAY';
                if (!in_array($tipo, $tipos_validos))  $tipo = 'RESIDENCIAL';

                // INSERT con DUPLICATE KEY UPDATE para re-importaciones
                $stmt = mysqli_prepare($conexion,
                    "INSERT INTO catalogo_paquetes (nombre_plan, play, tipo)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE play = VALUES(play), tipo = VALUES(tipo)"
                );
                mysqli_stmt_bind_param($stmt, 'sss', $nombre_plan, $play, $tipo);

                if (mysqli_stmt_execute($stmt)) {
                    $total++;
                } else {
                    $errores++;
                    $detalle_errores[] = "Fila $i: " . mysqli_stmt_error($stmt);
                }
                mysqli_stmt_close($stmt);
            }

            // Log de importación
            $tipo_log = 'catalogo_paquetes';
            $usuario  = $_SESSION['usuario'] ?? 'sistema';
            $v_archivo = $archivo['name'];
            $log = mysqli_prepare($conexion,
                "INSERT INTO importaciones_log (tipo, archivo, registros_importados, usuario)
                 VALUES (?,?,?,?)"
            );
            mysqli_stmt_bind_param($log, 'ssis', $tipo_log, $v_archivo, $total, $usuario);
            mysqli_stmt_execute($log);
            mysqli_stmt_close($log);

            unlink($ruta_temp);

            $stats        = compact('total','omitidos','errores','detalle_errores');
            $tipo_mensaje = $errores === 0 ? 'exito' : 'warning';
            $mensaje      = $errores === 0
                ? "✅ Importación completada: <strong>$total</strong> planes procesados."
                : "⚠️ Importación con errores: $total procesados, $errores fallidos.";

        } else {
            $mensaje      = 'Error al leer el archivo: ' . SimpleXLSX::parseError();
            $tipo_mensaje = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Importar Catálogo de Paquetes</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --pink:      #ec4899;
    --pink-dark: #be185d;
    --pink-soft: #fce7f3;
    --pink-mid:  #fbcfe8;
    --pink-glow: #ec489930;
    --bg:        #fff0f6;
    --surface:   #ffffff;
    --border:    #f9a8d4;
    --text:      #4a1030;
    --text2:     #9d174d;
    --text3:     #c084a0;
    --green:     #059669;
    --green-bg:  #ecfdf5;
    --green-bdr: #a7f3d0;
    --yellow:    #d97706;
    --yellow-bg: #fffbeb;
    --yellow-bdr:#fde68a;
    --red:       #dc2626;
    --red-bg:    #fef2f2;
    --red-bdr:   #fecaca;
}

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
    position: relative;
}
body::before {
    content: '';
    position: fixed; inset: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 55% 35% at 5%  0%,  #f9a8d440 0%, transparent 60%),
        radial-gradient(ellipse 40% 30% at 95% 100%, #ec489920 0%, transparent 60%);
}

.card {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 20px;
    width: 100%; max-width: 520px;
    padding: 40px 40px 36px;
    box-shadow: 0 0 0 1px #ec489908, 0 20px 60px #ec489918;
    position: relative;
}
.card::before {
    content: '';
    position: absolute; top: 0; left: 24px; right: 24px; height: 2px;
    background: linear-gradient(90deg, transparent, var(--pink), #f472b6, transparent);
    border-radius: 2px;
}

/* Ícono top */
.icon-wrap {
    width: 54px; height: 54px;
    background: var(--pink-soft);
    border: 1.5px solid var(--pink-mid);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 18px;
}

.card-label {
    font-family: 'Syne', sans-serif;
    font-size: 0.62rem; font-weight: 700;
    letter-spacing: 0.2em; text-transform: uppercase;
    color: var(--pink); margin-bottom: 6px;
}
.card-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.5rem; font-weight: 800;
    color: var(--text); line-height: 1.1; margin-bottom: 6px;
}
.card-sub { font-size: 0.8rem; color: var(--text2); margin-bottom: 28px; }

/* Mensajes */
.msg {
    border-radius: 10px; padding: 13px 16px;
    font-size: 0.82rem; line-height: 1.6;
    margin-bottom: 20px; border: 1px solid;
}
.msg.exito   { background: var(--green-bg);  border-color: var(--green-bdr); color: var(--green); }
.msg.error   { background: var(--red-bg);    border-color: var(--red-bdr);   color: var(--red);   }
.msg.warning { background: var(--yellow-bg); border-color: var(--yellow-bdr);color: var(--yellow);}

/* Stats */
.stats {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 10px; margin-bottom: 20px;
}
.stat {
    background: var(--pink-soft);
    border: 1px solid var(--pink-mid);
    border-radius: 10px; padding: 12px;
    text-align: center;
}
.stat-num {
    font-family: 'Syne', sans-serif;
    font-size: 1.6rem; font-weight: 800;
    color: var(--pink); line-height: 1;
}
.stat-lbl { font-size: 0.68rem; color: var(--text2); margin-top: 4px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

/* Errores detalle */
.error-list {
    background: var(--red-bg); border: 1px solid var(--red-bdr);
    border-radius: 8px; padding: 10px 14px;
    font-size: 0.74rem; color: var(--red);
    max-height: 120px; overflow-y: auto;
    margin-bottom: 16px;
    line-height: 1.8;
}

/* Zona upload */
.zona-upload {
    border: 2px dashed var(--pink);
    border-radius: 12px;
    padding: 44px 24px;
    text-align: center;
    color: var(--pink);
    cursor: pointer;
    margin-bottom: 16px;
    transition: background 0.2s, border-color 0.2s;
    position: relative;
}
.zona-upload:hover { background: var(--pink-soft); }
.zona-upload.has-file { background: var(--pink-soft); border-style: solid; }
.zona-icon { font-size: 2rem; margin-bottom: 10px; }
.zona-text { font-size: 0.88rem; font-weight: 500; }
.zona-sub  { font-size: 0.74rem; color: var(--text3); margin-top: 4px; }
.zona-filename {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--pink); color: #fff;
    border-radius: 20px; padding: 4px 14px;
    font-size: 0.78rem; font-weight: 500;
    margin-top: 10px;
}

input[type="file"] { display: none; }

/* Info badge */
.info-badge {
    display: flex; gap: 10px; align-items: flex-start;
    background: var(--pink-soft); border: 1px solid var(--pink-mid);
    border-radius: 10px; padding: 12px 14px;
    font-size: 0.78rem; color: var(--text2);
    margin-bottom: 20px; line-height: 1.6;
}
.info-badge .ico { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

/* Botón */
.btn-submit {
    width: 100%; padding: 13px; border: none; border-radius: 10px;
    background: var(--pink); color: #fff;
    font-family: 'Syne', sans-serif; font-size: 0.9rem; font-weight: 700;
    letter-spacing: 0.03em; cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
}
.btn-submit:hover { background: var(--pink-dark); box-shadow: 0 0 24px var(--pink-glow); }
.btn-submit:active { transform: scale(0.99); }
.btn-submit:disabled { background: #f9a8d4; cursor: not-allowed; }

.btn-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.78rem; color: var(--text3);
    text-decoration: none; margin-top: 16px;
    transition: color 0.2s;
}
.btn-back:hover { color: var(--pink); }

@media (max-width: 480px) {
    .card { padding: 28px 18px 24px; }
    .stats { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>
<div class="card">

    <div class="icon-wrap">📦</div>
    <div class="card-label">Catálogo de productos</div>
    <div class="card-title">Importar paquetes</div>
    <div class="card-sub">Sube el Excel con el catálogo de planes (Doble / Triple Play · Residencial / Negocios)</div>

    <?php if ($mensaje): ?>
    <div class="msg <?= $tipo_mensaje ?>"><?= $mensaje ?></div>
    <?php endif; ?>

    <?php if ($stats): ?>
    <div class="stats">
        <div class="stat">
            <div class="stat-num"><?= $stats['total'] ?></div>
            <div class="stat-lbl">Procesados</div>
        </div>
        <div class="stat">
            <div class="stat-num"><?= $stats['omitidos'] ?></div>
            <div class="stat-lbl">Omitidos</div>
        </div>
        <div class="stat">
            <div class="stat-num"><?= $stats['errores'] ?></div>
            <div class="stat-lbl">Errores</div>
        </div>
    </div>
    <?php if (!empty($stats['detalle_errores'])): ?>
    <div class="error-list">
        <?php foreach ($stats['detalle_errores'] as $e): ?>
        · <?= htmlspecialchars($e) ?><br>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="info-badge">
        <span class="ico">ℹ️</span>
        <span>El archivo debe tener la hoja <strong>Hoja2</strong> con columnas: <strong>nombre_plan · PLAY · TIPO</strong>. Si un plan ya existe, se actualizará automáticamente.</span>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <label for="archivo">
            <div class="zona-upload" id="zona">
                <div class="zona-icon">📂</div>
                <div class="zona-text">Haz clic para seleccionar tu archivo</div>
                <div class="zona-sub">.xlsx — máx. 10 MB</div>
                <div id="nombre-archivo"></div>
            </div>
        </label>
        <input type="file" name="archivo" id="archivo" accept=".xlsx"
               onchange="mostrarNombre(this)">
        <button type="submit" class="btn-submit" id="btn-importar">
            Importar catálogo →
        </button>
    </form>

    <a href="../index.php" class="btn-back">← Volver al Dashboard</a>
</div>

<script>
function mostrarNombre(input) {
    const nombre = input.files[0]?.name || '';
    const zona   = document.getElementById('zona');
    const badge  = document.getElementById('nombre-archivo');
    if (nombre) {
        zona.classList.add('has-file');
        badge.innerHTML = `<span class="zona-filename">✓ ${nombre}</span>`;
    } else {
        zona.classList.remove('has-file');
        badge.innerHTML = '';
    }
}

document.querySelector('form').addEventListener('submit', function() {
    const btn = document.getElementById('btn-importar');
    btn.disabled = true;
    btn.textContent = 'Importando…';
});
</script>
</body>
</html>