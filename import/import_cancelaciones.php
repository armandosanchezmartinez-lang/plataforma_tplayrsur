<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

$mensaje = '';
$tipo_mensaje = '';

function valorTexto($valor): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }

    $texto = trim((string)$valor);
    return $texto === '' ? null : $texto;
}

function valorEntero($valor): ?int
{
    $texto = valorTexto($valor);
    if ($texto === null) {
        return null;
    }

    $texto = str_replace([',', ' '], '', $texto);
    return is_numeric($texto) ? (int)round((float)$texto) : null;
}

function valorFecha($valor): ?string
{
    if ($valor === null || $valor === '') {
        return null;
    }

    // Excel serial date. Excel usa como origen práctico 1899-12-30.
    if (is_numeric($valor)) {
        try {
            $dias = (int)floor((float)$valor);
            $fecha = new DateTime('1899-12-30');
            $fecha->modify('+' . $dias . ' days');
            return $fecha->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    $formatos = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y'];
    $texto = trim((string)$valor);

    foreach ($formatos as $formato) {
        $fecha = DateTime::createFromFormat('!' . $formato, $texto);
        if ($fecha instanceof DateTime) {
            return $fecha->format('Y-m-d');
        }
    }

    $timestamp = strtotime($texto);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
}

function encabezadoNormalizado($valor): string
{
    $texto = mb_strtoupper(trim((string)$valor), 'UTF-8');
    $texto = strtr($texto, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'
    ]);
    return preg_replace('/\s+/', ' ', $texto) ?? $texto;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    $extension = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));

    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $mensaje = 'No fue posible recibir el archivo. Código de carga: ' . (int)($archivo['error'] ?? -1);
        $tipo_mensaje = 'error';
    } elseif ($extension !== 'xlsx') {
        $mensaje = 'Solo se permiten archivos .xlsx';
        $tipo_mensaje = 'error';
    } else {
        $directorioUploads = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/uploads';

        if (!is_dir($directorioUploads) && !mkdir($directorioUploads, 0755, true)) {
            $mensaje = 'No fue posible crear la carpeta de cargas.';
            $tipo_mensaje = 'error';
        } else {
            $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($archivo['name']));
            $rutaTemp = $directorioUploads . '/' . time() . '_' . $nombreSeguro;

            if (!move_uploaded_file($archivo['tmp_name'], $rutaTemp)) {
                $mensaje = 'No fue posible guardar temporalmente el archivo.';
                $tipo_mensaje = 'error';
            } elseif (!($xlsx = SimpleXLSX::parse($rutaTemp))) {
                $mensaje = 'Error al leer el archivo Excel: ' . SimpleXLSX::parseError();
                $tipo_mensaje = 'error';
                @unlink($rutaTemp);
            } else {
                $filas = $xlsx->rows();

                // El reporte Qlik contiene una columna vacía entre cada campo.
                $columnas = [
                    'cuenta'             => 0,
                    'ciclo'              => 2,
                    'fecha_activacion'   => 4,
                    'fecha_cancelacion'  => 6,
                    'semana_cancelacion' => 8,
                    'meses_activo'       => 10,
                    'tipo_cancelacion'   => 12,
                    'segmentacion'       => 14,
                    'canal_venta'        => 16,
                    'plaza'              => 18,
                    'cluster'            => 20,
                    'distrito'           => 22,
                    'plan_dsc'           => 24,
                    'plan'               => 26,
                    'familia'            => 28,
                    'tipo'               => 30,
                ];

                $encabezadosEsperados = [
                    0 => 'CUENTA',
                    2 => 'CICLO',
                    4 => 'ACTIVACION',
                    6 => 'CANCELACION',
                    8 => 'SEMANA CANCELACION',
                    10 => 'MESES ACTIVO',
                    12 => 'TIPO CANCELACION',
                    14 => 'SEGMENTACION',
                    16 => 'CANALVENTA',
                    18 => 'PLAZA',
                    20 => 'CLUSTER',
                    22 => 'DISTRITO',
                    24 => 'PLANDSC',
                    26 => 'PLAN',
                    28 => 'FAMILIA',
                    30 => 'TIPO',
                ];

                $encabezadosValidos = isset($filas[0]);
                $faltantes = [];

                if ($encabezadosValidos) {
                    foreach ($encabezadosEsperados as $indice => $esperado) {
                        $recibido = encabezadoNormalizado($filas[0][$indice] ?? '');
                        if ($recibido !== $esperado) {
                            $faltantes[] = $esperado . ' (columna ' . ($indice + 1) . ')';
                        }
                    }
                }

                if (!$encabezadosValidos || $faltantes) {
                    $mensaje = 'La estructura del archivo no coincide con el reporte Qlik de cancelaciones.';
                    if ($faltantes) {
                        $mensaje .= ' Revisa: ' . implode(', ', $faltantes) . '.';
                    }
                    $tipo_mensaje = 'error';
                    @unlink($rutaTemp);
                } else {
                    $sql = "INSERT INTO cancelaciones (
                                cuenta, ciclo, fecha_activacion, fecha_cancelacion,
                                semana_cancelacion, meses_activo, tipo_cancelacion,
                                segmentacion, canal_venta, plaza, cluster, distrito,
                                plan_dsc, plan, familia, tipo, archivo_origen
                            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                ciclo = VALUES(ciclo),
                                fecha_activacion = VALUES(fecha_activacion),
                                semana_cancelacion = VALUES(semana_cancelacion),
                                meses_activo = VALUES(meses_activo),
                                tipo_cancelacion = VALUES(tipo_cancelacion),
                                segmentacion = VALUES(segmentacion),
                                canal_venta = VALUES(canal_venta),
                                plaza = VALUES(plaza),
                                cluster = VALUES(cluster),
                                distrito = VALUES(distrito),
                                plan_dsc = VALUES(plan_dsc),
                                plan = VALUES(plan),
                                familia = VALUES(familia),
                                tipo = VALUES(tipo),
                                archivo_origen = VALUES(archivo_origen),
                                actualizado_en = CURRENT_TIMESTAMP";

                    $stmt = mysqli_prepare($conexion, $sql);

                    if (!$stmt) {
                        $mensaje = 'No fue posible preparar la importación: ' . mysqli_error($conexion);
                        $tipo_mensaje = 'error';
                        @unlink($rutaTemp);
                    } else {
                        $insertados = 0;
                        $actualizados = 0;
                        $sinCambios = 0;
                        $errores = 0;
                        $filasOmitidas = 0;
                        $detalleErrores = [];
                        $archivoOrigen = basename($archivo['name']);

                        mysqli_begin_transaction($conexion);

                        for ($i = 1, $n = count($filas); $i < $n; $i++) {
                            $fila = $filas[$i];

                            if (empty(array_filter($fila, static fn($valor) => $valor !== null && $valor !== ''))) {
                                continue;
                            }

                            $cuenta = valorTexto($fila[$columnas['cuenta']] ?? null);
                            $ciclo = valorEntero($fila[$columnas['ciclo']] ?? null);
                            $fechaActivacion = valorFecha($fila[$columnas['fecha_activacion']] ?? null);
                            $fechaCancelacion = valorFecha($fila[$columnas['fecha_cancelacion']] ?? null);
                            $semanaCancelacion = valorEntero($fila[$columnas['semana_cancelacion']] ?? null);
                            $mesesActivo = valorEntero($fila[$columnas['meses_activo']] ?? null);
                            $tipoCancelacion = valorTexto($fila[$columnas['tipo_cancelacion']] ?? null);
                            $segmentacion = valorTexto($fila[$columnas['segmentacion']] ?? null);
                            $canalVenta = valorTexto($fila[$columnas['canal_venta']] ?? null);
                            $plaza = valorTexto($fila[$columnas['plaza']] ?? null);
                            $cluster = valorTexto($fila[$columnas['cluster']] ?? null);
                            $distrito = valorTexto($fila[$columnas['distrito']] ?? null);
                            $planDsc = valorTexto($fila[$columnas['plan_dsc']] ?? null);
                            $plan = valorTexto($fila[$columnas['plan']] ?? null);
                            $familia = valorTexto($fila[$columnas['familia']] ?? null);
                            $tipo = valorTexto($fila[$columnas['tipo']] ?? null);

                            if ($cuenta === null || $fechaCancelacion === null) {
                                $filasOmitidas++;
                                if (count($detalleErrores) < 10) {
                                    $detalleErrores[] = 'Fila ' . ($i + 1) . ': falta Cuenta o fecha de Cancelación válida.';
                                }
                                continue;
                            }

                            mysqli_stmt_bind_param(
                                $stmt,
                                'sissiisssssssssss',
                                $cuenta,
                                $ciclo,
                                $fechaActivacion,
                                $fechaCancelacion,
                                $semanaCancelacion,
                                $mesesActivo,
                                $tipoCancelacion,
                                $segmentacion,
                                $canalVenta,
                                $plaza,
                                $cluster,
                                $distrito,
                                $planDsc,
                                $plan,
                                $familia,
                                $tipo,
                                $archivoOrigen
                            );

                            if (!mysqli_stmt_execute($stmt)) {
                                $errores++;
                                if (count($detalleErrores) < 10) {
                                    $detalleErrores[] = 'Fila ' . ($i + 1) . ': ' . mysqli_stmt_error($stmt);
                                }
                                continue;
                            }

                            $afectadas = mysqli_stmt_affected_rows($stmt);
                            if ($afectadas === 1) {
                                $insertados++;
                            } elseif ($afectadas === 2) {
                                $actualizados++;
                            } else {
                                $sinCambios++;
                            }
                        }

                        if ($errores > 0) {
                            mysqli_rollback($conexion);
                            $mensaje = 'La importación fue cancelada para evitar una carga parcial. Errores: ' . $errores;
                            if ($detalleErrores) {
                                $mensaje .= ' ' . implode(' | ', $detalleErrores);
                            }
                            $tipo_mensaje = 'error';
                        } else {
                            mysqli_commit($conexion);

                            $usuario = $_SESSION['usuario']
                                ?? $_SESSION['username']
                                ?? 'sistema';
                            $tipoLog = 'cancelaciones';
                            $totalProcesados = $insertados + $actualizados + $sinCambios;

                            // El log no debe deshacer una importación exitosa si la tabla de log presenta algún problema.
                            $log = mysqli_prepare(
                                $conexion,
                                'INSERT INTO importaciones_log (tipo, archivo, registros_importados, usuario) VALUES (?,?,?,?)'
                            );
                            if ($log) {
                                mysqli_stmt_bind_param($log, 'ssis', $tipoLog, $archivoOrigen, $totalProcesados, $usuario);
                                mysqli_stmt_execute($log);
                                mysqli_stmt_close($log);
                            }

                            $mensaje = 'Importación exitosa. Nuevos: ' . $insertados
                                . ' | Actualizados: ' . $actualizados
                                . ' | Sin cambios: ' . $sinCambios
                                . ' | Omitidos: ' . $filasOmitidas;
                            if ($detalleErrores) {
                                $mensaje .= ' Observaciones: ' . implode(' | ', $detalleErrores);
                            }
                            $tipo_mensaje = 'exito';
                        }

                        mysqli_stmt_close($stmt);
                        @unlink($rutaTemp);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Cancelaciones</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 24px;
        }
        .card {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.1);
            width: 100%;
            max-width: 560px;
        }
        h2 { color: #1a1a2e; margin-bottom: 8px; }
        p.sub { color: #666; font-size: .92rem; margin-bottom: 24px; line-height: 1.5; }
        .zona-upload {
            border: 2px dashed #dc2626;
            border-radius: 10px;
            padding: 40px 20px;
            text-align: center;
            color: #dc2626;
            cursor: pointer;
            margin-bottom: 20px;
            transition: background .2s;
        }
        .zona-upload:hover { background: #fef2f2; }
        input[type="file"] { display: none; }
        button {
            width: 100%;
            padding: 12px;
            background: #dc2626;
            color: #fff;
            border: 0;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #b91c1c; }
        .exito, .error {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .exito { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        .back {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #dc2626;
            text-decoration: none;
            font-size: .9rem;
        }
        small { display: inline-block; margin-top: 8px; color: #7f1d1d; }
    </style>
</head>
<body>
<div class="card">
    <h2>Importar Cancelaciones</h2>
    <p class="sub">Sube el archivo Qlik en formato .xlsx. El importador valida la estructura y actualiza registros ya existentes por Cuenta + fecha de Cancelación.</p>

    <?php if ($mensaje !== ''): ?>
        <div class="<?= htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label for="archivo">
            <div class="zona-upload" id="zona">
                Haz clic para seleccionar el archivo .xlsx
                <br><small id="nombre-archivo"></small>
            </div>
        </label>
        <input type="file" name="archivo" id="archivo" accept=".xlsx" required onchange="mostrarNombre(this)">
        <button type="submit">Importar cancelaciones</button>
    </form>

    <a href="../dashboard.php" class="back">← Volver al Dashboard</a>
</div>

<script>
function mostrarNombre(input) {
    const nombre = input.files[0]?.name || '';
    document.getElementById('nombre-archivo').textContent = nombre;
    document.getElementById('zona').style.background = nombre ? '#fef2f2' : '';
}
</script>
</body>
</html>
