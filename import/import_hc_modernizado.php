<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();
$_SESSION['usuario'] = $_SESSION['usuario'] ?? 'test'; // temporal / fallback

require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

$mensaje = "";
$tipo_mensaje = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        $mensaje = "Solo se permiten archivos .xlsx";
        $tipo_mensaje = "error";
    } else {
        $ruta_temp = '../uploads/' . time() . '_' . basename($archivo['name']);

        if (!move_uploaded_file($archivo['tmp_name'], $ruta_temp)) {
            $mensaje = "No se pudo subir el archivo al servidor.";
            $tipo_mensaje = "error";
        } elseif ($xlsx = SimpleXLSX::parse($ruta_temp)) {
            $filas = $xlsx->rows();
            $total = 0;
            $errores = 0;
            $eliminados = 0;
            $semanas_detectadas = [];
            $datos = [];

            $v = function($val) {
                return isset($val) && $val !== '' ? trim((string)$val) : null;
            };

            $fecha = function($val) {
                if (empty($val)) return null;
                if (is_numeric($val)) {
                    $unix = ((float)$val - 25569) * 86400;
                    return gmdate('Y-m-d', $unix);
                }
                $d = date_create($val);
                return $d ? date_format($d, 'Y-m-d') : null;
            };

            $entero = function($val) {
                if ($val === null || $val === '') return null;
                return intval($val);
            };

            // Columnas esperadas del Excel:
            // 0  NUMERO SF
            // 1  NUMERO BASE DE COMISIONES
            // 2  NUMERO TALENTO GS
            // 3  ID POSICIONES
            // 4  NOMBRE DEL COLABORADOR
            // 5  POSICIÓN (PUESTO)
            // 6  FECHA DE ALTA
            // 7  DISTRITO
            // 8  LR
            // 9  POSICION LR
            // 10 NOMBRE LINEA DE REPORTE
            // 11 PUESTO LR
            // 12 ARCHIVO_ORIGEN
            // 13 PESTAÑA_ORIGEN
            // 14 SEMANA
            // 15 AÑO

            // 1) Leer y validar primero todo el Excel antes de tocar la BD
            for ($i = 1; $i < count($filas); $i++) {
                $f = $filas[$i];
                if (empty(array_filter($f))) continue;

                $v_semana = $entero($v($f[14] ?? null));
                $v_anio   = $entero($v($f[15] ?? null));

                // Si no hay semana/año, no se puede sincronizar de forma segura
                if (empty($v_semana) || empty($v_anio)) {
                    $errores++;
                    continue;
                }

                $key = $v_anio . '-' . $v_semana;
                $semanas_detectadas[$key] = [
                    'anio' => $v_anio,
                    'semana' => $v_semana
                ];

                $datos[] = [
                    'numero_sf'            => $v($f[0] ?? null),
                    'numero_base_comisiones'=> $v($f[1] ?? null),
                    'numero_talento_gs'    => $v($f[2] ?? null),
                    'id_posicion'          => $v($f[3] ?? null),
                    'nombre_colaborador'   => $v($f[4] ?? null),
                    'posicion'             => $v($f[5] ?? null),
                    'fecha_alta'           => $fecha($v($f[6] ?? null)),
                    'distrito'             => $v($f[7] ?? null),
                    'lr'                   => $v($f[8] ?? null),
                    'posicion_lr'          => $v($f[9] ?? null),
                    'nombre_linea_reporte' => $v($f[10] ?? null),
                    'puesto_lr'            => $v($f[11] ?? null),
                    'archivo_origen_excel' => $v($f[12] ?? null),
                    'pestana_origen'       => $v($f[13] ?? null),
                    'semana'               => $v_semana,
                    'anio'                 => $v_anio
                ];
            }

            if (empty($datos) || empty($semanas_detectadas)) {
                if (file_exists($ruta_temp)) unlink($ruta_temp);
                $mensaje = "No se encontraron filas válidas con SEMANA y AÑO para importar.";
                $tipo_mensaje = "error";
            } else {
                mysqli_begin_transaction($conexion);

                try {
                    // 2) Eliminar únicamente las semanas/años contenidos en el Excel
                    $delete = mysqli_prepare($conexion, "DELETE FROM hc WHERE anio = ? AND semana = ?");
                    foreach ($semanas_detectadas as $periodo) {
                        mysqli_stmt_bind_param($delete, "ii", $periodo['anio'], $periodo['semana']);
                        if (!mysqli_stmt_execute($delete)) {
                            throw new Exception("Error al eliminar HC previo: " . mysqli_stmt_error($delete));
                        }
                        $eliminados += mysqli_stmt_affected_rows($delete);
                    }
                    mysqli_stmt_close($delete);

                    // 3) Insertar datos limpios
                    $stmt = mysqli_prepare($conexion, "INSERT INTO hc (
                        numero_sf, numero_base_comisiones, numero_talento_gs,
                        id_posicion, nombre_colaborador, posicion, fecha_alta,
                        distrito, lr, posicion_lr, nombre_linea_reporte, puesto_lr,
                        archivo_origen_excel, pestana_origen, semana, anio,
                        importado_en
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())");

                    foreach ($datos as $d) {
                        mysqli_stmt_bind_param($stmt, "ssssssssssssssii",
                            $d['numero_sf'], $d['numero_base_comisiones'], $d['numero_talento_gs'],
                            $d['id_posicion'], $d['nombre_colaborador'], $d['posicion'], $d['fecha_alta'],
                            $d['distrito'], $d['lr'], $d['posicion_lr'], $d['nombre_linea_reporte'], $d['puesto_lr'],
                            $d['archivo_origen_excel'], $d['pestana_origen'], $d['semana'], $d['anio']
                        );

                        if (mysqli_stmt_execute($stmt)) {
                            $total++;
                        } else {
                            $errores++;
                        }
                    }
                    mysqli_stmt_close($stmt);

                    // Si hubo errores de inserción, deshacer todo para no dejar carga incompleta
                    if ($errores > 0) {
                        throw new Exception("Se detectaron $errores errores durante la inserción. Se canceló la importación.");
                    }

                    // 4) Registrar log
                    $tipo_log = 'hc';
                    $v_archivo = $archivo['name'];
                    $usuario = $_SESSION['usuario'] ?? 'test';

                    $log = mysqli_prepare($conexion, "INSERT INTO importaciones_log (tipo, archivo, registros_importados, usuario) VALUES (?,?,?,?)");
                    mysqli_stmt_bind_param($log, "ssis", $tipo_log, $v_archivo, $total, $usuario);
                    if (!mysqli_stmt_execute($log)) {
                        throw new Exception("Error al guardar log: " . mysqli_stmt_error($log));
                    }
                    mysqli_stmt_close($log);

                    mysqli_commit($conexion);

                    if (file_exists($ruta_temp)) unlink($ruta_temp);

                    $periodos = array_map(function($p) {
                        return $p['anio'] . '-SEM' . $p['semana'];
                    }, array_values($semanas_detectadas));

                    $mensaje = "✅ Importación exitosa: $total registros importados. Registros previos reemplazados: $eliminados. Periodos sincronizados: " . count($semanas_detectadas) . ".";
                    $tipo_mensaje = "exito";
                } catch (Exception $e) {
                    mysqli_rollback($conexion);
                    if (file_exists($ruta_temp)) unlink($ruta_temp);
                    $mensaje = "❌ Importación cancelada: " . $e->getMessage();
                    $tipo_mensaje = "error";
                }
            }
        } else {
            if (file_exists($ruta_temp)) unlink($ruta_temp);
            $mensaje = "Error al leer el archivo Excel: " . SimpleXLSX::parseError();
            $tipo_mensaje = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar HC</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h2 { color: #1a1a2e; margin-bottom: 8px; }
        p.sub { color: #666; font-size: 0.9rem; margin-bottom: 24px; }
        .zona-upload { border: 2px dashed #d97706; border-radius: 10px; padding: 40px; text-align: center; color: #d97706; cursor: pointer; margin-bottom: 20px; transition: background 0.2s; }
        .zona-upload:hover { background: #fffbeb; }
        input[type="file"] { display: none; }
        button { width: 100%; padding: 12px; background: #d97706; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #b45309; }
        .exito { background: #dcfce7; color: #166534; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .error  { background: #fee2e2; color: #991b1b; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .back { display: block; text-align: center; margin-top: 16px; color: #d97706; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="card">
    <h2>📥 Importar HC</h2>
    <p class="sub">Sube tu archivo Excel (.xlsx) con el reporte de Headcount</p>

    <?php if ($mensaje): ?>
        <div class="<?= $tipo_mensaje ?>"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label for="archivo">
            <div class="zona-upload" id="zona">
                📂 Haz clic para seleccionar tu archivo .xlsx
                <br><small id="nombre-archivo"></small>
            </div>
        </label>
        <input type="file" name="archivo" id="archivo" accept=".xlsx" onchange="mostrarNombre(this)">
        <button type="submit">Importar datos</button>
    </form>
    <a href="../dashboard.php" class="back">← Volver al Dashboard</a>
</div>

<script>
function mostrarNombre(input) {
    const nombre = input.files[0]?.name || '';
    document.getElementById('nombre-archivo').textContent = nombre;
    document.getElementById('zona').style.background = '#fffbeb';
}
</script>
</body>
</html>
