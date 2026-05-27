<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

include $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

$mensaje = "";
$tipo_mensaje = "";

function limpiar_valor($val) {
    return isset($val) && $val !== '' ? trim((string)$val) : null;
}

function normalizar_texto($txt) {
    $txt = trim((string)$txt);
    $txt = mb_strtoupper($txt, 'UTF-8');

    $buscar  = ['Á','É','Í','Ó','Ú','Ü'];
    $cambiar = ['A','E','I','O','U','U'];
    $txt = str_replace($buscar, $cambiar, $txt);
    $txt = preg_replace('/\s+/', ' ', $txt);

    return $txt;
}

function normalizar_distrito($txt) {
    $txt = normalizar_texto($txt);
    $clean = str_replace(['/', '-'], ' ', $txt);
    $clean = preg_replace('/\s+/', ' ', $clean);

    if (strpos($clean, 'COATZA') !== false && strpos($clean, 'MINA') !== false) {
        return 'COATZA / MINA';
    }

    return $txt;
}

function distrito_where_hc($col = 'distrito') {
    return "CASE
        WHEN UPPER(TRIM($col)) LIKE '%COATZA%' AND UPPER(TRIM($col)) LIKE '%MINA%'
        THEN 'COATZA / MINA'
        ELSE UPPER(TRIM($col))
    END";
}

function parse_semana($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') return null;

    // Acepta: 21, SEM 21, SEM21, Semana 21
    if (preg_match('/(\d{1,2})/', $valor, $m)) {
        $semana = (int)$m[1];
        return ($semana >= 1 && $semana <= 53) ? $semana : null;
    }

    return null;
}

function parse_entero($valor) {
    if ($valor === null || $valor === '') return 0;
    $valor = str_replace([',', ' ', '$'], '', (string)$valor);
    return (int)round((float)$valor);
}

function buscar_responsable_forecast($conexion, $distrito, $semana, $anio) {
    /*
        Importante:
        - Para identificar el puesto del colaborador usamos hc.posicion.
        - puesto_lr corresponde al puesto del jefe directo, NO al puesto del colaborador.
        - posicion_lr es el id_posicion del jefe directo.
    */

    $sql = "SELECT
                id_posicion,
                posicion_lr,
                numero_talento_gs,
                nombre_colaborador,
                posicion AS puesto_responsable,
                distrito
            FROM hc
            WHERE " . distrito_where_hc('distrito') . " = ?
              AND posicion = 'DIRECTOR DISTRITAL'
              AND semana = ?
              AND anio = ?
              AND nombre_colaborador NOT LIKE '%VACANTE%'
            LIMIT 1";

    $stmt = mysqli_prepare($conexion, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, "sii", $distrito, $semana, $anio);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($row) return $row;

    /*
        Fallback:
        Si no existe HC exactamente para esa semana/año, usamos el último HC disponible
        igual o anterior al periodo solicitado para ese distrito.
    */

    $sql_fallback = "SELECT
                        id_posicion,
                        posicion_lr,
                        numero_talento_gs,
                        nombre_colaborador,
                        posicion AS puesto_responsable,
                        distrito
                    FROM hc
                    WHERE " . distrito_where_hc('distrito') . " = ?
                      AND posicion = 'DIRECTOR DISTRITAL'
                      AND nombre_colaborador NOT LIKE '%VACANTE%'
                      AND (
                            anio < ?
                            OR (anio = ? AND semana <= ?)
                          )
                    ORDER BY anio DESC, semana DESC
                    LIMIT 1";

    $stmt_fb = mysqli_prepare($conexion, $sql_fallback);
    if (!$stmt_fb) return null;

    mysqli_stmt_bind_param($stmt_fb, "siii", $distrito, $anio, $anio, $semana);
    mysqli_stmt_execute($stmt_fb);
    $res_fb = mysqli_stmt_get_result($stmt_fb);
    $row_fb = mysqli_fetch_assoc($res_fb);
    mysqli_stmt_close($stmt_fb);

    return $row_fb ?: null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        $mensaje = "Solo se permiten archivos .xlsx";
        $tipo_mensaje = "error";
    } else {
        $nombre_seguro = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $archivo['name']);
        $ruta_temp = '../uploads/' . time() . '_' . $nombre_seguro;

        if (!move_uploaded_file($archivo['tmp_name'], $ruta_temp)) {
            $mensaje = "No se pudo subir el archivo temporal.";
            $tipo_mensaje = "error";
        } elseif ($xlsx = SimpleXLSX::parse($ruta_temp)) {

            // Hoja #1 del archivo histórico: forecast directores
            $filas = $xlsx->rows(0);

            $procesados = 0;
            $insertados = 0;
            $actualizados = 0;
            $omitidos = 0;
            $sin_responsable = 0;
            $errores = 0;

            /*
                Estructura esperada:
                Columna A: SEMANA
                Columna B: Distrito
                Columna C: FCTS
                Columna D: Observación / marca opcional
                Columna E: AÑO

                Este importador carga forecast de DIRECTORES DISTRITALES.
                Para líderes y coaches se usará después interfaz de captura.
            */

            $sql_insert = "INSERT INTO metas_forecast_semanal (
                                anio,
                                semana,
                                id_posicion,
                                posicion_lr,
                                numero_talento_gs,
                                nombre_responsable,
                                puesto_responsable,
                                distrito,
                                nivel_forecast,
                                forecast,
                                observacion,
                                archivo_origen,
                                usuario_carga
                            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                posicion_lr = VALUES(posicion_lr),
                                numero_talento_gs = VALUES(numero_talento_gs),
                                nombre_responsable = VALUES(nombre_responsable),
                                puesto_responsable = VALUES(puesto_responsable),
                                distrito = VALUES(distrito),
                                nivel_forecast = VALUES(nivel_forecast),
                                forecast = VALUES(forecast),
                                observacion = VALUES(observacion),
                                archivo_origen = VALUES(archivo_origen),
                                usuario_carga = VALUES(usuario_carga),
                                fecha_carga = CURRENT_TIMESTAMP";

            $stmt_insert = mysqli_prepare($conexion, $sql_insert);

            if (!$stmt_insert) {
                $mensaje = "Error preparando SQL: " . mysqli_error($conexion);
                $tipo_mensaje = "error";
            } else {
                for ($i = 1; $i < count($filas); $i++) {
                    $f = $filas[$i];

                    if (empty(array_filter($f))) {
                        continue;
                    }

                    $semana_raw = limpiar_valor($f[0] ?? null);
                    $distrito_raw = limpiar_valor($f[1] ?? null);
                    $forecast_raw = limpiar_valor($f[2] ?? null);
                    $observacion = limpiar_valor($f[3] ?? null);
                    $anio_raw = limpiar_valor($f[4] ?? null);

                    $semana = parse_semana($semana_raw);
                    $distrito = normalizar_distrito($distrito_raw);
                    $forecast = parse_entero($forecast_raw);
                    $anio = (int)$anio_raw;

                    if (!$semana || !$anio || $distrito === '') {
                        $omitidos++;
                        continue;
                    }

                    $responsable = buscar_responsable_forecast($conexion, $distrito, $semana, $anio);

                    if (!$responsable) {
                        $sin_responsable++;
                        continue;
                    }

                    $id_posicion = $responsable['id_posicion'];
                    $posicion_lr = $responsable['posicion_lr'];
                    $numero_talento_gs = $responsable['numero_talento_gs'];
                    $nombre_responsable = $responsable['nombre_colaborador'];
                    $puesto_responsable = $responsable['puesto_responsable'];
                    $distrito_hc = normalizar_distrito($responsable['distrito']);
                    $nivel_forecast = 'DIRECTOR_DISTRITAL';
                    $archivo_origen = $archivo['name'];
                    $usuario = $_SESSION['usuario'];

                    mysqli_stmt_bind_param(
                        $stmt_insert,
                        "iisssssssisss",
                        $anio,
                        $semana,
                        $id_posicion,
                        $posicion_lr,
                        $numero_talento_gs,
                        $nombre_responsable,
                        $puesto_responsable,
                        $distrito_hc,
                        $nivel_forecast,
                        $forecast,
                        $observacion,
                        $archivo_origen,
                        $usuario
                    );

                    if (mysqli_stmt_execute($stmt_insert)) {
                        $afectadas = mysqli_stmt_affected_rows($stmt_insert);
                        $procesados++;

                        if ($afectadas === 1) {
                            $insertados++;
                        } elseif ($afectadas === 2) {
                            $actualizados++;
                        }
                    } else {
                        $errores++;
                    }
                }

                mysqli_stmt_close($stmt_insert);

                $tipo_log = 'metas_forecast_directores';
                $log = mysqli_prepare($conexion, "INSERT INTO importaciones_log (tipo, archivo, registros_importados, usuario) VALUES (?,?,?,?)");

                if ($log) {
                    mysqli_stmt_bind_param($log, "ssis", $tipo_log, $archivo['name'], $procesados, $_SESSION['usuario']);
                    mysqli_stmt_execute($log);
                    mysqli_stmt_close($log);
                }

                $mensaje = "✅ Importación exitosa: $procesados registros procesados. Insertados: $insertados. Actualizados: $actualizados. Omitidos: $omitidos. Sin responsable HC: $sin_responsable. Errores: $errores.";
                $tipo_mensaje = "exito";
            }

            if (file_exists($ruta_temp)) {
                unlink($ruta_temp);
            }

        } else {
            $mensaje = "Error al leer el archivo Excel: " . SimpleXLSX::parseError();
            $tipo_mensaje = "error";

            if (file_exists($ruta_temp)) {
                unlink($ruta_temp);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Forecast Directores</title>
    <link rel="stylesheet" href="../assets/css/xpedient-v2.css?v=162">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background:
                radial-gradient(circle at 8% 8%, rgba(255,10,200,.10), transparent 28%),
                radial-gradient(circle at 92% 14%, rgba(0,216,255,.09), transparent 30%),
                linear-gradient(180deg,#f7f8ff 0%,#eef5ff 100%);
            color: #1a2540;
            padding: 24px;
        }

        .card {
            background: rgba(255,255,255,.86);
            border: 1px solid rgba(255,255,255,.70);
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 18px 45px rgba(22,28,60,.09);
            width: 100%;
            max-width: 640px;
        }

        h2 { margin-bottom: 8px; font-size: 1.6rem; }

        p.sub {
            color: #6b7a99;
            font-size: 0.92rem;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .tag {
            display: inline-block;
            background: linear-gradient(135deg,#7A2BFF 0%,#FF00B8 100%);
            color: white;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: .78rem;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .zona-upload {
            border: 2px dashed #b12cff;
            border-radius: 18px;
            padding: 38px;
            text-align: center;
            color: #7a2bff;
            cursor: pointer;
            margin-bottom: 20px;
            transition: all 0.2s ease;
            background: rgba(255,255,255,.60);
        }

        .zona-upload:hover { background: rgba(177,44,255,.08); }

        input[type="file"] { display: none; }

        button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg,#7A2BFF 0%,#FF00B8 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 25px rgba(122,43,255,.22);
        }

        button:hover { transform: translateY(-1px); }

        .exito {
            background: #dcfce7;
            color: #166534;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            line-height: 1.45;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            line-height: 1.45;
        }

        .back {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #7a2bff;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .nota {
            font-size: .78rem;
            color: #6b7a99;
            line-height: 1.5;
            margin-top: 12px;
        }
    </style>
</head>
<body>
<div class="card">
    <span class="tag">Nivel: Director Distrital</span>
    <h2>📈 Importar Forecast Semanal</h2>
    <p class="sub">
        Sube el archivo histórico de forecast de directores.
        Se leerá la hoja #1 con columnas:
        <strong>SEMANA, Distrito, FCTS, Observación y AÑO</strong>.
    </p>

    <?php if ($mensaje): ?>
        <div class="<?= $tipo_mensaje ?>"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label for="archivo">
            <div class="zona-upload" id="zona">
                📂 Haz clic para seleccionar tu archivo .xlsx
                <br><small id="nombre-archivo"></small>
            </div>
        </label>
        <input type="file" name="archivo" id="archivo" accept=".xlsx" onchange="mostrarNombre(this)">
        <button type="submit">Importar forecast directores</button>
    </form>

    <div class="nota">
        Este importador identifica al responsable usando <strong>hc.posicion = 'DIRECTOR DISTRITAL'</strong>.
        No usa <strong>puesto_lr</strong>, porque ese campo representa el puesto del jefe directo.
        Si ya existe forecast para el mismo Año + Semana + id_posicion, se actualizará. COATZA/MINA se normaliza como COATZA / MINA.
    </div>

    <a href="../index.php" class="back">← Volver al Dashboard</a>
</div>

<script>
function mostrarNombre(input) {
    const nombre = input.files[0]?.name || '';
    document.getElementById('nombre-archivo').textContent = nombre;
    document.getElementById('zona').style.background = 'rgba(177,44,255,.08)';
}
</script>
</body>
</html>
