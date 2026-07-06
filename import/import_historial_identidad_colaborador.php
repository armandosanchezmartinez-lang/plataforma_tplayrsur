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

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function limpiar_texto($val) {
    if (!isset($val)) return null;
    $val = trim((string)$val);
    return $val === '' ? null : $val;
}

function normalizar_header($txt) {
    $txt = trim((string)$txt);
    $txt = mb_strtolower($txt, 'UTF-8');
    $txt = str_replace(
        ['á','é','í','ó','ú','ñ','ü'],
        ['a','e','i','o','u','n','u'],
        $txt
    );
    $txt = preg_replace('/[^a-z0-9]+/u', '_', $txt);
    return trim($txt, '_');
}

function fecha_excel($val) {
    if ($val === null || $val === '') return null;

    if (is_numeric($val)) {
        $unix = ((float)$val - 25569) * 86400;
        return gmdate('Y-m-d', $unix);
    }

    $val = trim((string)$val);

    $formatos = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'];
    foreach ($formatos as $fmt) {
        $d = DateTime::createFromFormat($fmt, $val);
        if ($d instanceof DateTime) return $d->format('Y-m-d');
    }

    $d = date_create($val);
    return $d ? date_format($d, 'Y-m-d') : null;
}

function valor_por_alias($fila, $map, $aliases, $default = null) {
    foreach ($aliases as $alias) {
        $key = normalizar_header($alias);
        if (isset($map[$key])) {
            $idx = $map[$key];
            return limpiar_texto($fila[$idx] ?? null);
        }
    }
    return $default;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    $fecha_default = fecha_excel($_POST['fecha_movimiento'] ?? null) ?: date('Y-m-d');
    $motivo_default = limpiar_texto($_POST['motivo_movimiento'] ?? null) ?: 'Migración de Nominera Elektra a Totalplay';
    $tipo_default = limpiar_texto($_POST['tipo_movimiento'] ?? null) ?: 'MIGRACION_NOMINERA';
    $observaciones_default = limpiar_texto($_POST['observaciones'] ?? null) ?: 'Cambio administrativo identificado por comparación de plantillas HC.';
    $usuario = $_SESSION['usuario'] ?? 'test';

    $tipos_validos = [
        'MIGRACION_NOMINERA','PROMOCION','CAMBIO_COACH','CAMBIO_LIDER',
        'CAMBIO_DISTRITO','REINGRESO','CORRECCION','OTRO'
    ];
    if (!in_array($tipo_default, $tipos_validos, true)) $tipo_default = 'OTRO';

    if ($ext !== 'xlsx') {
        $mensaje = "Solo se permiten archivos .xlsx";
        $tipo_mensaje = "error";
    } else {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/uploads/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        $ruta_temp = $uploadDir . time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($archivo['name']));

        if (!move_uploaded_file($archivo['tmp_name'], $ruta_temp)) {
            $mensaje = "No se pudo subir el archivo al servidor.";
            $tipo_mensaje = "error";
        } elseif ($xlsx = SimpleXLSX::parse($ruta_temp)) {
            $filas = $xlsx->rows();
            $total = 0;
            $omitidos = 0;
            $duplicados = 0;
            $errores = 0;
            $datos = [];

            if (count($filas) < 2) {
                $mensaje = "El archivo no contiene datos para importar.";
                $tipo_mensaje = "error";
            } else {
                $headers = $filas[0];
                $map = [];
                foreach ($headers as $idx => $header) {
                    $map[normalizar_header($header)] = $idx;
                }

                for ($i = 1; $i < count($filas); $i++) {
                    $f = $filas[$i];
                    if (empty(array_filter($f, function($x) { return trim((string)$x) !== ''; }))) continue;

                    $numero_talento_anterior = valor_por_alias($f, $map, [
                        'numero_talento_anterior','numero talento anterior','talento anterior',
                        'numero_talento_gs_sem26','numero talento gs sem26','numero_talento_gs_26','numero talento gs_26','numero talento gs 26','talento sem26',
                        'numero_talento_gs anterior'
                    ]);

                    $numero_talento_nuevo = valor_por_alias($f, $map, [
                        'numero_talento_nuevo','numero talento nuevo','talento nuevo',
                        'numero_talento_gs_sem27','numero talento gs sem27','numero_talento_gs_27','numero talento gs_27','numero talento gs 27','talento sem27',
                        'numero_talento_gs nuevo'
                    ]);

                    $id_posicion_anterior = valor_por_alias($f, $map, [
                        'id_posicion_anterior','id posicion anterior','posicion anterior',
                        'id_posicion_sem26','id posicion sem26','id_posiciones_26','id posiciones 26','id posiciones_26','id_posicion_26','id posicion 26','id_posicion anterior'
                    ]);

                    $id_posicion_nueva = valor_por_alias($f, $map, [
                        'id_posicion_nueva','id posicion nueva','id posicion nuevo','posicion nueva',
                        'id_posicion_sem27','id posicion sem27','id_posiciones_27','id posiciones 27','id posiciones_27','id_posicion_27','id posicion 27','id_posicion nuevo'
                    ]);

                    $nombre_colaborador = valor_por_alias($f, $map, [
                        'nombre_colaborador','nombre del colaborador','colaborador','nombre'
                    ]);

                    $distrito = valor_por_alias($f, $map, ['distrito','distrito_27','distrito 27','distrito_sem27','distrito sem27','distrito_26','distrito 26','distrito_sem26','distrito sem26']);

                    $fecha_movimiento = fecha_excel(valor_por_alias($f, $map, [
                        'fecha_movimiento','fecha movimiento','fecha'
                    ])) ?: $fecha_default;

                    $motivo_movimiento = valor_por_alias($f, $map, [
                        'motivo_movimiento','motivo movimiento','motivo'
                    ], $motivo_default) ?: $motivo_default;

                    $tipo_movimiento = valor_por_alias($f, $map, [
                        'tipo_movimiento','tipo movimiento','tipo'
                    ], $tipo_default) ?: $tipo_default;
                    if (!in_array($tipo_movimiento, $tipos_validos, true)) $tipo_movimiento = $tipo_default;

                    $observaciones = valor_por_alias($f, $map, [
                        'observaciones','observacion','comentarios','comentario'
                    ], $observaciones_default) ?: $observaciones_default;

                    if (!$nombre_colaborador) {
                        $omitidos++;
                        continue;
                    }

                    $cambio_talento = ($numero_talento_anterior !== $numero_talento_nuevo);
                    $cambio_posicion = ($id_posicion_anterior !== $id_posicion_nueva);

                    if (!$cambio_talento && !$cambio_posicion) {
                        $omitidos++;
                        continue;
                    }

                    $datos[] = [
                        'numero_talento_anterior' => $numero_talento_anterior,
                        'numero_talento_nuevo' => $numero_talento_nuevo,
                        'id_posicion_anterior' => $id_posicion_anterior,
                        'id_posicion_nueva' => $id_posicion_nueva,
                        'nombre_colaborador' => $nombre_colaborador,
                        'distrito' => $distrito,
                        'fecha_movimiento' => $fecha_movimiento,
                        'motivo_movimiento' => $motivo_movimiento,
                        'tipo_movimiento' => $tipo_movimiento,
                        'observaciones' => $observaciones,
                        'usuario_registro' => $usuario
                    ];
                }

                if (empty($datos)) {
                    $mensaje = "No se encontraron cambios válidos de número talento o id_posición para importar. Encabezados detectados: " . implode(", ", array_keys($map));
                    $tipo_mensaje = "error";
                } else {
                    mysqli_begin_transaction($conexion);

                    try {
                        $check = mysqli_prepare($conexion, "
                            SELECT id
                            FROM historial_identidad_colaborador
                            WHERE COALESCE(numero_talento_anterior,'') = COALESCE(?, '')
                              AND COALESCE(numero_talento_nuevo,'') = COALESCE(?, '')
                              AND COALESCE(id_posicion_anterior,'') = COALESCE(?, '')
                              AND COALESCE(id_posicion_nueva,'') = COALESCE(?, '')
                              AND COALESCE(nombre_colaborador,'') = COALESCE(?, '')
                              AND fecha_movimiento = ?
                            LIMIT 1
                        ");

                        $stmt = mysqli_prepare($conexion, "
                            INSERT INTO historial_identidad_colaborador (
                                numero_talento_anterior,
                                numero_talento_nuevo,
                                id_posicion_anterior,
                                id_posicion_nueva,
                                nombre_colaborador,
                                distrito,
                                fecha_movimiento,
                                motivo_movimiento,
                                tipo_movimiento,
                                observaciones,
                                usuario_registro
                            ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
                        ");

                        foreach ($datos as $d) {
                            mysqli_stmt_bind_param(
                                $check,
                                "ssssss",
                                $d['numero_talento_anterior'],
                                $d['numero_talento_nuevo'],
                                $d['id_posicion_anterior'],
                                $d['id_posicion_nueva'],
                                $d['nombre_colaborador'],
                                $d['fecha_movimiento']
                            );

                            if (!mysqli_stmt_execute($check)) {
                                throw new Exception("Error al validar duplicado: " . mysqli_stmt_error($check));
                            }

                            $res_check = mysqli_stmt_get_result($check);
                            if ($res_check && mysqli_num_rows($res_check) > 0) {
                                $duplicados++;
                                continue;
                            }

                            mysqli_stmt_bind_param(
                                $stmt,
                                "sssssssssss",
                                $d['numero_talento_anterior'],
                                $d['numero_talento_nuevo'],
                                $d['id_posicion_anterior'],
                                $d['id_posicion_nueva'],
                                $d['nombre_colaborador'],
                                $d['distrito'],
                                $d['fecha_movimiento'],
                                $d['motivo_movimiento'],
                                $d['tipo_movimiento'],
                                $d['observaciones'],
                                $d['usuario_registro']
                            );

                            if (mysqli_stmt_execute($stmt)) {
                                $total++;
                            } else {
                                $errores++;
                            }
                        }

                        mysqli_stmt_close($check);
                        mysqli_stmt_close($stmt);

                        if ($errores > 0) {
                            throw new Exception("Se detectaron $errores errores durante la inserción. Se canceló la importación.");
                        }

                        if (mysqli_query($conexion, "SHOW TABLES LIKE 'importaciones_log'") && mysqli_num_rows(mysqli_query($conexion, "SHOW TABLES LIKE 'importaciones_log'")) > 0) {
                            $tipo_log = 'historial_identidad';
                            $v_archivo = $archivo['name'];
                            $log = mysqli_prepare($conexion, "INSERT INTO importaciones_log (tipo, archivo, registros_importados, usuario) VALUES (?,?,?,?)");
                            if ($log) {
                                mysqli_stmt_bind_param($log, "ssis", $tipo_log, $v_archivo, $total, $usuario);
                                mysqli_stmt_execute($log);
                                mysqli_stmt_close($log);
                            }
                        }

                        mysqli_commit($conexion);

                        $mensaje = "✅ Importación exitosa: $total movimientos importados. Duplicados omitidos: $duplicados. Filas sin cambio/omitidas: $omitidos.";
                        $tipo_mensaje = "exito";
                    } catch (Exception $e) {
                        mysqli_rollback($conexion);
                        $mensaje = "❌ Importación cancelada: " . $e->getMessage();
                        $tipo_mensaje = "error";
                    }
                }
            }

            if (file_exists($ruta_temp)) unlink($ruta_temp);
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
    <title>Importar Historial de Identidad</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 55%, #fdf2f8 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .card {
            background: white;
            padding: 40px;
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(79,70,229,0.16);
            width: 100%;
            max-width: 560px;
            border: 1px solid #e0e7ff;
        }

        h2 { color: #312e81; margin-bottom: 8px; }
        p.sub { color: #64748b; font-size: 0.92rem; margin-bottom: 22px; line-height: 1.35; }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        label.field-label {
            display: block;
            color: #334155;
            font-size: .78rem;
            font-weight: 800;
            margin-bottom: 5px;
            letter-spacing: .02em;
        }

        input[type="date"],
        input[type="text"],
        select,
        textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #c7d2fe;
            border-radius: 10px;
            outline: none;
            font-size: .92rem;
            color: #1e293b;
            background: #f8fafc;
        }

        textarea { min-height: 70px; resize: vertical; }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124,58,237,.12);
            background: #fff;
        }

        .zona-upload {
            border: 2px dashed #7c3aed;
            border-radius: 14px;
            padding: 34px;
            text-align: center;
            color: #6d28d9;
            cursor: pointer;
            margin-bottom: 18px;
            transition: all .2s ease;
            background: #faf5ff;
            font-weight: 700;
        }

        .zona-upload:hover {
            background: #f3e8ff;
            transform: translateY(-1px);
        }

        input[type="file"] { display: none; }

        button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #4f46e5, #9333ea);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(79,70,229,.20);
        }

        button:hover {
            filter: brightness(.96);
            transform: translateY(-1px);
        }

        .exito { background: #dcfce7; color: #166534; padding: 14px; border-radius: 10px; margin-bottom: 20px; line-height: 1.35; }
        .error  { background: #fee2e2; color: #991b1b; padding: 14px; border-radius: 10px; margin-bottom: 20px; line-height: 1.35; }

        .back {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: #6d28d9;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .hint {
            margin-top: 12px;
            color: #64748b;
            font-size: .78rem;
            line-height: 1.35;
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
<div class="card">
    <h2>🔁 Importar Historial de Identidad</h2>
    <p class="sub">Carga el Excel de cambios detectados entre plantillas HC para poblar la tabla puente de identidad histórica.</p>

    <?php if ($mensaje): ?>
        <div class="<?= h($tipo_mensaje) ?>"><?= h($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="meta-grid">
            <div>
                <label class="field-label" for="fecha_movimiento">Fecha del movimiento</label>
                <input type="date" name="fecha_movimiento" id="fecha_movimiento" value="<?= h(date('Y-m-d')) ?>">
            </div>

            <div>
                <label class="field-label" for="tipo_movimiento">Tipo de movimiento</label>
                <select name="tipo_movimiento" id="tipo_movimiento">
                    <option value="MIGRACION_NOMINERA" selected>MIGRACION_NOMINERA</option>
                    <option value="PROMOCION">PROMOCION</option>
                    <option value="CAMBIO_COACH">CAMBIO_COACH</option>
                    <option value="CAMBIO_LIDER">CAMBIO_LIDER</option>
                    <option value="CAMBIO_DISTRITO">CAMBIO_DISTRITO</option>
                    <option value="REINGRESO">REINGRESO</option>
                    <option value="CORRECCION">CORRECCION</option>
                    <option value="OTRO">OTRO</option>
                </select>
            </div>

            <div>
                <label class="field-label" for="motivo_movimiento">Motivo del movimiento</label>
                <input type="text" name="motivo_movimiento" id="motivo_movimiento" value="Migración de Nominera Elektra a Totalplay">
            </div>

            <div>
                <label class="field-label" for="observaciones">Observaciones</label>
                <textarea name="observaciones" id="observaciones">Cambio administrativo identificado por comparación de plantillas HC.</textarea>
            </div>
        </div>

        <label for="archivo">
            <div class="zona-upload" id="zona">
                📂 Haz clic para seleccionar archivo .xlsx
                <br><small id="nombre-archivo"></small>
            </div>
        </label>
        <input type="file" name="archivo" id="archivo" accept=".xlsx" onchange="mostrarNombre(this)">

        <button type="submit">Importar movimientos</button>

        <div class="hint">
            Columnas aceptadas: número talento anterior/nuevo, id posición anterior/nueva, nombre colaborador, distrito.
            También acepta variantes como SEM26/SEM27.
        </div>
    </form>

    <a href="../dashboard.php" class="back">← Volver al Dashboard</a>
</div>

<script>
function mostrarNombre(input) {
    const nombre = input.files[0]?.name || '';
    document.getElementById('nombre-archivo').textContent = nombre;
    document.getElementById('zona').style.background = '#f3e8ff';
}
</script>
</body>
</html>
