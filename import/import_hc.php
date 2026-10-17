<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

$_SESSION['usuario'] = $_SESSION['usuario'] ?? 'test';

require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

$mensaje = "";
$tipo_mensaje = "";

function normalizar_header($v) {
    $v = trim((string)$v);
    $v = mb_strtoupper($v, 'UTF-8');
    $v = str_replace(
        ['Á','É','Í','Ó','Ú','Ü','Ñ'],
        ['A','E','I','O','U','U','N'],
        $v
    );
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function valor_fila_por_header($fila, $mapa, $header) {
    $key = normalizar_header($header);
    if (!array_key_exists($key, $mapa)) return null;

    $idx = $mapa[$key];
    return $fila[$idx] ?? null;
}

function limpiar_valor($val) {
    if ($val === null) return null;
    $val = trim((string)$val);
    return $val === '' ? null : $val;
}

function fecha_mysql($val) {
    if ($val === null || $val === '') return null;

    if (is_numeric($val)) {
        $unix = ((float)$val - 25569) * 86400;
        return gmdate('Y-m-d', $unix);
    }

    $s = trim((string)$val);

    if ($s === '' || in_array(strtoupper($s), ['N/A','NA','VACANTE','NULL','NONE','-','--'], true)) {
        return null;
    }

    // El Python FINAL v2 ya entrega YYYY-MM-DD.
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if ($d && $d->format('Y-m-d') === $s) {
        return $s;
    }

    // Fallbacks defensivos.
    $s = preg_replace('#/+#', '/', $s);
    $s = preg_replace('#-+#', '-', $s);

    $formatos = ['d/m/Y','d-m-Y','Y/m/d','d/m/y','d-m-y'];

    foreach ($formatos as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d) {
            return $d->format('Y-m-d');
        }
    }

    return null;
}

function entero_o_null($val) {
    if ($val === null || $val === '') return null;
    return (int)$val;
}

function status_normalizado($val) {
    $val = limpiar_valor($val);
    if ($val === null) return null;

    return mb_strtoupper($val, 'UTF-8');
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['archivo'])) {

    $archivo = $_FILES['archivo'];
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        $mensaje = "Solo se permiten archivos .xlsx";
        $tipo_mensaje = "error";
    } else {

        $ruta_uploads = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/uploads';

        if (!is_dir($ruta_uploads)) {
            @mkdir($ruta_uploads, 0775, true);
        }

        $ruta_temp = $ruta_uploads . '/' . time() . '_' . basename($archivo['name']);

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

            if (empty($filas) || empty($filas[0])) {
                if (file_exists($ruta_temp)) unlink($ruta_temp);
                $mensaje = "El Excel no contiene encabezados.";
                $tipo_mensaje = "error";
            } else {

                // ============================================================
                // 1) MAPEO POR ENCABEZADOS, NO POR POSICIÓN
                // ============================================================

                $encabezados = $filas[0];
                $mapa = [];

                foreach ($encabezados as $idx => $header) {
                    $key = normalizar_header($header);
                    if ($key !== '') {
                        $mapa[$key] = $idx;
                    }
                }

                $requeridos = [
                    'NUMERO SF',
                    'NUMERO BASE DE COMISIONES',
                    'NUMERO TALENTO GS',
                    'ID POSICIONES',
                    'NOMBRE DEL COLABORADOR',
                    'POSICION (PUESTO)',
                    'FECHA DE ALTA',
                    'DISTRITO',
                    'LR',
                    'POSICION LR',
                    'NOMBRE LINEA DE REPORTE',
                    'PUESTO LR',
                    'ARCHIVO_ORIGEN',
                    'PESTANA_ORIGEN',
                    'SEMANA',
                    'ANO'
                ];

                $faltantes = [];

                foreach ($requeridos as $req) {
                    if (!array_key_exists(normalizar_header($req), $mapa)) {
                        $faltantes[] = $req;
                    }
                }

                if (!empty($faltantes)) {
                    if (file_exists($ruta_temp)) unlink($ruta_temp);
                    $mensaje = "Faltan columnas obligatorias: " . implode(', ', $faltantes);
                    $tipo_mensaje = "error";
                } else {

                    $status_validos = ['ACTIVO','VACANTE','INCAPACIDAD','VACACIONES'];

                    // ============================================================
                    // 2) LEER Y VALIDAR TODO ANTES DE TOCAR LA BD
                    // ============================================================

                    for ($i = 1; $i < count($filas); $i++) {

                        $f = $filas[$i];

                        if (empty(array_filter($f, function($v) {
                            return $v !== null && trim((string)$v) !== '';
                        }))) {
                            continue;
                        }

                        $v_semana = entero_o_null(
                            limpiar_valor(valor_fila_por_header($f, $mapa, 'SEMANA'))
                        );

                        $v_anio = entero_o_null(
                            limpiar_valor(valor_fila_por_header($f, $mapa, 'AÑO'))
                        );

                        if (empty($v_semana) || empty($v_anio)) {
                            $errores++;
                            continue;
                        }

                        $status = status_normalizado(
                            valor_fila_por_header($f, $mapa, 'STATUS')
                        );

                        // A partir de SEM42/2026, STATUS es obligatorio.
                        $status_obligatorio = ($v_anio > 2026) || ($v_anio === 2026 && $v_semana >= 42);

                        if ($status_obligatorio && $status === null) {
                            throw new Exception(
                                "STATUS obligatorio vacío en fila Excel " . ($i + 1) .
                                " (SEM{$v_semana}-{$v_anio})."
                            );
                        }

                        if ($status !== null && !in_array($status, $status_validos, true)) {
                            throw new Exception(
                                "STATUS no permitido '{$status}' en fila Excel " . ($i + 1) .
                                ". Valores válidos: " . implode(', ', $status_validos)
                            );
                        }

                        $key = $v_anio . '-' . $v_semana;

                        $semanas_detectadas[$key] = [
                            'anio' => $v_anio,
                            'semana' => $v_semana
                        ];

                        $datos[] = [
                            'numero_sf' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'NUMERO SF')
                            ),
                            'numero_base_comisiones' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'NUMERO BASE DE COMISIONES')
                            ),
                            'numero_talento_gs' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'NUMERO TALENTO GS')
                            ),
                            'id_posicion' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'ID POSICIONES')
                            ),
                            'nombre_colaborador' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'NOMBRE DEL COLABORADOR')
                            ),
                            'posicion' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'POSICIÓN (PUESTO)')
                            ),
                            'fecha_alta' => fecha_mysql(
                                valor_fila_por_header($f, $mapa, 'FECHA DE ALTA')
                            ),
                            'distrito' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'DISTRITO')
                            ),
                            'lr' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'LR')
                            ),
                            'posicion_lr' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'POSICION LR')
                            ),
                            'nombre_linea_reporte' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'NOMBRE LINEA DE REPORTE')
                            ),
                            'puesto_lr' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'PUESTO LR')
                            ),
                            'status' => $status,
                            'archivo_origen_excel' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'ARCHIVO_ORIGEN')
                            ),
                            'pestana_origen' => limpiar_valor(
                                valor_fila_por_header($f, $mapa, 'PESTAÑA_ORIGEN')
                            ),
                            'semana' => $v_semana,
                            'anio' => $v_anio
                        ];
                    }

                    if ($errores > 0) {
                        if (file_exists($ruta_temp)) unlink($ruta_temp);
                        $mensaje = "Se detectaron $errores filas sin SEMANA/AÑO. La importación fue cancelada.";
                        $tipo_mensaje = "error";

                    } elseif (empty($datos) || empty($semanas_detectadas)) {
                        if (file_exists($ruta_temp)) unlink($ruta_temp);
                        $mensaje = "No se encontraron filas válidas para importar.";
                        $tipo_mensaje = "error";

                    } else {

                        mysqli_begin_transaction($conexion);

                        try {

                            // ============================================================
                            // 3) REEMPLAZAR COMPLETAMENTE LOS PERIODOS PRESENTES EN EL EXCEL
                            // ============================================================

                            $delete = mysqli_prepare(
                                $conexion,
                                "DELETE FROM hc WHERE anio = ? AND semana = ?"
                            );

                            if (!$delete) {
                                throw new Exception(
                                    "No se pudo preparar DELETE: " . mysqli_error($conexion)
                                );
                            }

                            foreach ($semanas_detectadas as $periodo) {

                                mysqli_stmt_bind_param(
                                    $delete,
                                    "ii",
                                    $periodo['anio'],
                                    $periodo['semana']
                                );

                                if (!mysqli_stmt_execute($delete)) {
                                    throw new Exception(
                                        "Error al eliminar HC previo: " .
                                        mysqli_stmt_error($delete)
                                    );
                                }

                                $eliminados += mysqli_stmt_affected_rows($delete);
                            }

                            mysqli_stmt_close($delete);

                            // ============================================================
                            // 4) INSERT EXPLÍCITO POR NOMBRE DE COLUMNA
                            // ============================================================

                            $stmt = mysqli_prepare($conexion, "
                                INSERT INTO hc (
                                    numero_sf,
                                    numero_base_comisiones,
                                    numero_talento_gs,
                                    id_posicion,
                                    nombre_colaborador,
                                    posicion,
                                    fecha_alta,
                                    distrito,
                                    lr,
                                    posicion_lr,
                                    nombre_linea_reporte,
                                    puesto_lr,
                                    status,
                                    archivo_origen_excel,
                                    pestana_origen,
                                    semana,
                                    anio,
                                    importado_en
                                ) VALUES (
                                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()
                                )
                            ");

                            if (!$stmt) {
                                throw new Exception(
                                    "No se pudo preparar INSERT: " . mysqli_error($conexion)
                                );
                            }

                            foreach ($datos as $d) {

                                mysqli_stmt_bind_param(
                                    $stmt,
                                    "sssssssssssssssii",
                                    $d['numero_sf'],
                                    $d['numero_base_comisiones'],
                                    $d['numero_talento_gs'],
                                    $d['id_posicion'],
                                    $d['nombre_colaborador'],
                                    $d['posicion'],
                                    $d['fecha_alta'],
                                    $d['distrito'],
                                    $d['lr'],
                                    $d['posicion_lr'],
                                    $d['nombre_linea_reporte'],
                                    $d['puesto_lr'],
                                    $d['status'],
                                    $d['archivo_origen_excel'],
                                    $d['pestana_origen'],
                                    $d['semana'],
                                    $d['anio']
                                );

                                if (!mysqli_stmt_execute($stmt)) {
                                    throw new Exception(
                                        "Error insertando fila de " .
                                        ($d['nombre_colaborador'] ?? 'SIN NOMBRE') .
                                        ": " . mysqli_stmt_error($stmt)
                                    );
                                }

                                $total++;
                            }

                            mysqli_stmt_close($stmt);

                            // ============================================================
                            // 5) LOG
                            // ============================================================

                            $tipo_log = 'hc';
                            $v_archivo = $archivo['name'];
                            $usuario = $_SESSION['usuario'] ?? 'test';

                            $log = mysqli_prepare(
                                $conexion,
                                "INSERT INTO importaciones_log
                                (tipo, archivo, registros_importados, usuario)
                                VALUES (?,?,?,?)"
                            );

                            if (!$log) {
                                throw new Exception(
                                    "No se pudo preparar log: " . mysqli_error($conexion)
                                );
                            }

                            mysqli_stmt_bind_param(
                                $log,
                                "ssis",
                                $tipo_log,
                                $v_archivo,
                                $total,
                                $usuario
                            );

                            if (!mysqli_stmt_execute($log)) {
                                throw new Exception(
                                    "Error al guardar log: " . mysqli_stmt_error($log)
                                );
                            }

                            mysqli_stmt_close($log);

                            mysqli_commit($conexion);

                            if (file_exists($ruta_temp)) unlink($ruta_temp);

                            $mensaje =
                                "✅ Importación exitosa: {$total} registros importados. " .
                                "Registros previos reemplazados: {$eliminados}. " .
                                "Periodos sincronizados: " . count($semanas_detectadas) . ".";

                            $tipo_mensaje = "exito";

                        } catch (Throwable $e) {

                            mysqli_rollback($conexion);

                            if (file_exists($ruta_temp)) unlink($ruta_temp);

                            $mensaje = "❌ Importación cancelada: " . $e->getMessage();
                            $tipo_mensaje = "error";
                        }
                    }
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
* { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family:'Segoe UI',sans-serif;
    background:#f0f2f5;
    display:flex;
    justify-content:center;
    align-items:center;
    min-height:100vh;
}
.card {
    background:#fff;
    padding:40px;
    border-radius:12px;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    width:100%;
    max-width:520px;
}
h2 { color:#1a1a2e; margin-bottom:8px; }
p.sub { color:#666; font-size:.9rem; margin-bottom:24px; }
.zona-upload {
    border:2px dashed #d97706;
    border-radius:10px;
    padding:40px;
    text-align:center;
    color:#d97706;
    cursor:pointer;
    margin-bottom:20px;
}
input[type="file"] { display:none; }
button {
    width:100%;
    padding:12px;
    background:#d97706;
    color:#fff;
    border:none;
    border-radius:8px;
    font-size:1rem;
    font-weight:600;
    cursor:pointer;
}
button:disabled { opacity:.65; cursor:not-allowed; }
.exito {
    background:#dcfce7;
    color:#166534;
    padding:14px;
    border-radius:8px;
    margin-bottom:20px;
}
.error {
    background:#fee2e2;
    color:#991b1b;
    padding:14px;
    border-radius:8px;
    margin-bottom:20px;
}
.back {
    display:block;
    text-align:center;
    margin-top:16px;
    color:#d97706;
    text-decoration:none;
    font-size:.9rem;
}
</style>
</head>
<body>
<div class="card">

    <h2>📥 Importar HC</h2>
    <p class="sub">
        Importación transaccional por encabezados.
        STATUS obligatorio a partir de SEM42-2026.
    </p>

    <?php if ($mensaje): ?>
        <div class="<?= htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="formImport">

        <label for="archivo">
            <div class="zona-upload" id="zona">
                📂 Haz clic para seleccionar tu archivo .xlsx
                <br>
                <small id="nombre-archivo"></small>
            </div>
        </label>

        <input
            type="file"
            name="archivo"
            id="archivo"
            accept=".xlsx"
            onchange="mostrarNombre(this)"
            required
        >

        <button type="submit" id="btnImportar">
            Importar datos
        </button>

    </form>

    <a href="../dashboard.php" class="back">← Volver al Dashboard</a>

</div>

<script>
function mostrarNombre(input) {
    const nombre = input.files[0]?.name || '';
    document.getElementById('nombre-archivo').textContent = nombre;
    document.getElementById('zona').style.background = '#fffbeb';
}

document.getElementById('formImport').addEventListener('submit', function() {
    const btn = document.getElementById('btnImportar');
    btn.disabled = true;
    btn.textContent = 'Importando... no cierres esta ventana';
});
</script>

</body>
</html>
