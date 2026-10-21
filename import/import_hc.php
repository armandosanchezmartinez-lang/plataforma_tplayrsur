<?php
/**
 * TalIA / TotalXpedient · IMPORTADOR HC + STANDBY · 21-OCT-2026
 * ============================================================================
 * Fuente esperada: hc_plataforma.xlsx generado por read_hc4_FINAL.py
 * Hojas obligatorias:
 *   - Consolidado -> tabla hc
 *   - Standby     -> tabla hc_standby
 *
 * Reglas STANDBY:
 * - tipo_standby: VACACIONES | INCAPACIDAD
 * - FECHA INICIO y FECHA FIN son obligatorias en el archivo de intercambio.
 *   La BD permite fecha_fin NULL solo como colchón técnico excepcional.
 * - Se permiten fechas futuras; NO se aplica la corrección de FECHA DE ALTA.
 * - responsable_rol:
 *     VACACIONES   -> AUTORIZA
 *     INCAPACIDAD  -> GESTOR CH
 * - Identidad del evento (UNIQUE en BD):
 *     numero_talento_gs + tipo_standby + fecha_inicio
 * - No se almacena cantidad de días: se deriva de las fechas cuando sea necesario.
 *
 * Sincronización:
 * - HC conserva la regla existente: reemplazo completo por (anio, semana)
 *   para todos los periodos presentes en Consolidado.
 * - STANDBY se sincroniza por ARCHIVO_ORIGEN. Antes de insertar la hoja
 *   canónica Standby, se eliminan de hc_standby los registros cuyo
 *   archivo_origen pertenece al conjunto de archivos fuente procesados en
 *   Consolidado/Standby. Esto permite corregir o retirar eventos sin dejar
 *   residuos históricos. El INSERT además usa ON DUPLICATE KEY UPDATE.
 * - HC y STANDBY se ejecutan dentro de UNA MISMA transacción.
 */

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

function construir_mapa_headers($encabezados) {
    $mapa = [];
    foreach ($encabezados as $idx => $header) {
        $key = normalizar_header($header);
        if ($key !== '') $mapa[$key] = $idx;
    }
    return $mapa;
}

function validar_headers_requeridos($mapa, $requeridos, $hoja) {
    $faltantes = [];
    foreach ($requeridos as $req) {
        if (!array_key_exists(normalizar_header($req), $mapa)) {
            $faltantes[] = $req;
        }
    }
    if (!empty($faltantes)) {
        throw new Exception(
            "Hoja {$hoja}: faltan columnas obligatorias: " . implode(', ', $faltantes)
        );
    }
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

function fila_vacia($fila) {
    return empty(array_filter($fila, function($v) {
        return $v !== null && trim((string)$v) !== '';
    }));
}

function es_marcador_fecha_vacia($val) {
    if ($val === null) return true;
    $s = trim((string)$val);
    if ($s === '') return true;
    return in_array(mb_strtoupper($s, 'UTF-8'), [
        'N/A','NA','VACANTE','NULL','NONE','-','--'
    ], true);
}

function fecha_mysql($val) {
    if ($val === null || $val === '') return null;

    if (is_numeric($val)) {
        $unix = ((float)$val - 25569) * 86400;
        return gmdate('Y-m-d', (int)$unix);
    }

    $s = trim((string)$val);
    if ($s === '' || in_array(mb_strtoupper($s, 'UTF-8'), [
        'N/A','NA','VACANTE','NULL','NONE','-','--'
    ], true)) {
        return null;
    }

    // El Python v4 entrega YYYY-MM-DD.
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if ($d && $d->format('Y-m-d') === $s) return $s;

    // Fallbacks defensivos.
    $s = preg_replace('#/+#', '/', $s);
    $s = preg_replace('#-+#', '-', $s);
    $formatos = ['d/m/Y','d-m-Y','Y/m/d','d/m/y','d-m-y'];

    foreach ($formatos as $fmt) {
        $d = DateTime::createFromFormat('!'.$fmt, $s);
        if ($d) return $d->format('Y-m-d');
    }

    return null;
}

function fecha_mysql_validada($val, $contexto, $obligatoria = false) {
    if (es_marcador_fecha_vacia($val)) {
        if ($obligatoria) {
            throw new Exception("{$contexto}: fecha obligatoria vacía.");
        }
        return null;
    }

    $fecha = fecha_mysql($val);
    if ($fecha === null) {
        throw new Exception("{$contexto}: fecha no interpretable '" . trim((string)$val) . "'.");
    }
    return $fecha;
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

function tipo_standby_normalizado($val) {
    $val = limpiar_valor($val);
    if ($val === null) return null;
    $val = mb_strtoupper($val, 'UTF-8');
    return preg_replace('/\s+/', ' ', $val);
}

function rol_standby_normalizado($val) {
    $val = limpiar_valor($val);
    if ($val === null) return null;
    $val = mb_strtoupper($val, 'UTF-8');
    $val = str_replace('_', ' ', $val);
    return preg_replace('/\s+/', ' ', trim($val));
}

function indice_hoja_por_nombre($xlsx, $nombre_buscado) {
    $objetivo = normalizar_header($nombre_buscado);
    foreach ($xlsx->sheetNames() as $idx => $nombre) {
        if (normalizar_header($nombre) === $objetivo) return (int)$idx;
    }
    return null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['archivo'])) {

    $archivo = $_FILES['archivo'];
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') {
        $mensaje = "Solo se permiten archivos .xlsx";
        $tipo_mensaje = "error";
    } else {

        $ruta_uploads = $_SERVER['DOCUMENT_ROOT'] . '/plataforma/uploads';
        if (!is_dir($ruta_uploads)) @mkdir($ruta_uploads, 0775, true);

        $ruta_temp = $ruta_uploads . '/' . time() . '_' . basename($archivo['name']);

        if (!move_uploaded_file($archivo['tmp_name'], $ruta_temp)) {
            $mensaje = "No se pudo subir el archivo al servidor.";
            $tipo_mensaje = "error";

        } elseif ($xlsx = SimpleXLSX::parse($ruta_temp)) {

            $transaccion_iniciada = false;

            try {
                // ============================================================
                // 1) LOCALIZAR LAS DOS HOJAS CANÓNICAS
                // ============================================================
                $idx_consolidado = indice_hoja_por_nombre($xlsx, 'Consolidado');
                $idx_standby = indice_hoja_por_nombre($xlsx, 'Standby');

                if ($idx_consolidado === null) {
                    throw new Exception("No se encontró la hoja obligatoria 'Consolidado'.");
                }
                if ($idx_standby === null) {
                    throw new Exception("No se encontró la hoja obligatoria 'Standby'.");
                }

                $filas_hc = $xlsx->rows($idx_consolidado);
                $filas_standby = $xlsx->rows($idx_standby);

                if (empty($filas_hc) || empty($filas_hc[0])) {
                    throw new Exception("La hoja Consolidado no contiene encabezados.");
                }
                if (empty($filas_standby) || empty($filas_standby[0])) {
                    throw new Exception("La hoja Standby no contiene encabezados.");
                }

                // ============================================================
                // 2) MAPEAR Y VALIDAR CONSOLIDADO -> hc
                // ============================================================
                $mapa_hc = construir_mapa_headers($filas_hc[0]);
                $requeridos_hc = [
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
                    'ANO',
                    'STATUS'
                ];
                validar_headers_requeridos($mapa_hc, $requeridos_hc, 'Consolidado');

                $status_validos = ['ACTIVO','VACANTE','INCAPACIDAD','VACACIONES'];
                $datos_hc = [];
                $semanas_detectadas = [];
                $archivos_fuente_detectados = [];

                for ($i = 1; $i < count($filas_hc); $i++) {
                    $f = $filas_hc[$i];
                    if (fila_vacia($f)) continue;

                    $fila_excel = $i + 1;
                    $v_semana = entero_o_null(limpiar_valor(
                        valor_fila_por_header($f, $mapa_hc, 'SEMANA')
                    ));
                    $v_anio = entero_o_null(limpiar_valor(
                        valor_fila_por_header($f, $mapa_hc, 'AÑO')
                    ));

                    if ($v_semana === null || $v_semana < 1 || $v_semana > 53) {
                        throw new Exception("Consolidado fila {$fila_excel}: SEMANA inválida.");
                    }
                    if ($v_anio === null || $v_anio < 2020 || $v_anio > 2100) {
                        throw new Exception("Consolidado fila {$fila_excel}: AÑO inválido.");
                    }

                    $status = status_normalizado(
                        valor_fila_por_header($f, $mapa_hc, 'STATUS')
                    );

                    // A partir de SEM42/2026, STATUS es obligatorio.
                    $status_obligatorio = ($v_anio > 2026) || ($v_anio === 2026 && $v_semana >= 42);
                    if ($status_obligatorio && $status === null) {
                        throw new Exception(
                            "Consolidado fila {$fila_excel}: STATUS obligatorio vacío " .
                            "(SEM{$v_semana}-{$v_anio})."
                        );
                    }
                    if ($status !== null && !in_array($status, $status_validos, true)) {
                        throw new Exception(
                            "Consolidado fila {$fila_excel}: STATUS no permitido '{$status}'. " .
                            "Valores válidos: " . implode(', ', $status_validos)
                        );
                    }

                    $fecha_alta_raw = valor_fila_por_header($f, $mapa_hc, 'FECHA DE ALTA');
                    $fecha_alta = fecha_mysql_validada(
                        $fecha_alta_raw,
                        "Consolidado fila {$fila_excel} / FECHA DE ALTA",
                        false
                    );

                    $archivo_origen = limpiar_valor(
                        valor_fila_por_header($f, $mapa_hc, 'ARCHIVO_ORIGEN')
                    );
                    if ($archivo_origen !== null) {
                        $archivos_fuente_detectados[$archivo_origen] = true;
                    }

                    $key = $v_anio . '-' . $v_semana;
                    $semanas_detectadas[$key] = [
                        'anio' => $v_anio,
                        'semana' => $v_semana
                    ];

                    $datos_hc[] = [
                        'numero_sf' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'NUMERO SF')),
                        'numero_base_comisiones' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'NUMERO BASE DE COMISIONES')),
                        'numero_talento_gs' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'NUMERO TALENTO GS')),
                        'id_posicion' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'ID POSICIONES')),
                        'nombre_colaborador' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'NOMBRE DEL COLABORADOR')),
                        'posicion' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'POSICIÓN (PUESTO)')),
                        'fecha_alta' => $fecha_alta,
                        'distrito' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'DISTRITO')),
                        'lr' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'LR')),
                        'posicion_lr' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'POSICION LR')),
                        'nombre_linea_reporte' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'NOMBRE LINEA DE REPORTE')),
                        'puesto_lr' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'PUESTO LR')),
                        'status' => $status,
                        'archivo_origen_excel' => $archivo_origen,
                        'pestana_origen' => limpiar_valor(valor_fila_por_header($f, $mapa_hc, 'PESTAÑA_ORIGEN')),
                        'semana' => $v_semana,
                        'anio' => $v_anio
                    ];
                }

                if (empty($datos_hc) || empty($semanas_detectadas)) {
                    throw new Exception("No se encontraron filas válidas en Consolidado.");
                }

                // ============================================================
                // 3) MAPEAR Y VALIDAR STANDBY -> hc_standby
                // ============================================================
                $mapa_standby = construir_mapa_headers($filas_standby[0]);
                $requeridos_standby = [
                    'NUMERO TALENTO GS',
                    'NOMBRE COLABORADOR',
                    'PUESTO',
                    'TIPO_STANDBY',
                    'FECHA INICIO',
                    'FECHA FIN',
                    'RESPONSABLE_NOMBRE',
                    'RESPONSABLE_ROL',
                    'SOCIEDAD',
                    'CECO',
                    'AREA',
                    'ARCHIVO_ORIGEN',
                    'PESTANA_ORIGEN',
                    'SEMANA_CARGA',
                    'ANO_CARGA'
                ];
                validar_headers_requeridos($mapa_standby, $requeridos_standby, 'Standby');

                $datos_standby = [];
                $llaves_standby = [];
                $conteo_standby_tipo = ['VACACIONES' => 0, 'INCAPACIDAD' => 0];

                for ($i = 1; $i < count($filas_standby); $i++) {
                    $f = $filas_standby[$i];
                    if (fila_vacia($f)) continue;

                    $fila_excel = $i + 1;
                    $talento = limpiar_valor(
                        valor_fila_por_header($f, $mapa_standby, 'NUMERO TALENTO GS')
                    );
                    if ($talento === null || mb_strtoupper($talento, 'UTF-8') === 'VACANTE') {
                        throw new Exception(
                            "Standby fila {$fila_excel}: NUMERO TALENTO GS obligatorio/inválido."
                        );
                    }

                    $tipo = tipo_standby_normalizado(
                        valor_fila_por_header($f, $mapa_standby, 'TIPO_STANDBY')
                    );
                    if (!in_array($tipo, ['VACACIONES','INCAPACIDAD'], true)) {
                        throw new Exception(
                            "Standby fila {$fila_excel}: TIPO_STANDBY no permitido '" .
                            ($tipo ?? '') . "'."
                        );
                    }

                    // Regla de negocio acordada: ambas fechas son obligatorias.
                    // Las fechas futuras son válidas y se conservan sin corrección.
                    $fecha_inicio = fecha_mysql_validada(
                        valor_fila_por_header($f, $mapa_standby, 'FECHA INICIO'),
                        "Standby fila {$fila_excel} / FECHA INICIO",
                        true
                    );
                    $fecha_fin = fecha_mysql_validada(
                        valor_fila_por_header($f, $mapa_standby, 'FECHA FIN'),
                        "Standby fila {$fila_excel} / FECHA FIN",
                        true
                    );

                    if ($fecha_fin < $fecha_inicio) {
                        throw new Exception(
                            "Standby fila {$fila_excel}: FECHA FIN {$fecha_fin} es anterior " .
                            "a FECHA INICIO {$fecha_inicio}."
                        );
                    }

                    $responsable_nombre = limpiar_valor(
                        valor_fila_por_header($f, $mapa_standby, 'RESPONSABLE_NOMBRE')
                    );
                    $responsable_rol = rol_standby_normalizado(
                        valor_fila_por_header($f, $mapa_standby, 'RESPONSABLE_ROL')
                    );

                    if ($responsable_nombre === null) {
                        throw new Exception(
                            "Standby fila {$fila_excel}: RESPONSABLE_NOMBRE obligatorio vacío."
                        );
                    }

                    $rol_esperado = ($tipo === 'VACACIONES') ? 'AUTORIZA' : 'GESTOR CH';
                    if ($responsable_rol !== $rol_esperado) {
                        throw new Exception(
                            "Standby fila {$fila_excel}: RESPONSABLE_ROL '{$responsable_rol}' " .
                            "no corresponde a {$tipo}; se esperaba '{$rol_esperado}'."
                        );
                    }

                    $semana_carga = entero_o_null(limpiar_valor(
                        valor_fila_por_header($f, $mapa_standby, 'SEMANA_CARGA')
                    ));
                    $anio_carga = entero_o_null(limpiar_valor(
                        valor_fila_por_header($f, $mapa_standby, 'AÑO_CARGA')
                    ));

                    if ($semana_carga === null || $semana_carga < 1 || $semana_carga > 53) {
                        throw new Exception("Standby fila {$fila_excel}: SEMANA_CARGA inválida.");
                    }
                    if ($anio_carga === null || $anio_carga < 2020 || $anio_carga > 2100) {
                        throw new Exception("Standby fila {$fila_excel}: AÑO_CARGA inválido.");
                    }

                    $archivo_origen = limpiar_valor(
                        valor_fila_por_header($f, $mapa_standby, 'ARCHIVO_ORIGEN')
                    );
                    if ($archivo_origen === null) {
                        throw new Exception("Standby fila {$fila_excel}: ARCHIVO_ORIGEN obligatorio vacío.");
                    }
                    $archivos_fuente_detectados[$archivo_origen] = true;

                    $llave_evento = mb_strtoupper($talento, 'UTF-8') . '|' . $tipo . '|' . $fecha_inicio;
                    if (isset($llaves_standby[$llave_evento])) {
                        throw new Exception(
                            "Standby fila {$fila_excel}: evento duplicado para llave " .
                            "{$talento} + {$tipo} + {$fecha_inicio}."
                        );
                    }
                    $llaves_standby[$llave_evento] = true;
                    $conteo_standby_tipo[$tipo]++;

                    $datos_standby[] = [
                        'numero_talento_gs' => $talento,
                        'nombre_colaborador' => limpiar_valor(valor_fila_por_header($f, $mapa_standby, 'NOMBRE COLABORADOR')),
                        'puesto' => limpiar_valor(valor_fila_por_header($f, $mapa_standby, 'PUESTO')),
                        'tipo_standby' => $tipo,
                        'fecha_inicio' => $fecha_inicio,
                        'fecha_fin' => $fecha_fin,
                        'responsable_nombre' => $responsable_nombre,
                        'responsable_rol' => $responsable_rol,
                        'sociedad' => limpiar_valor(valor_fila_por_header($f, $mapa_standby, 'SOCIEDAD')),
                        'ceco' => limpiar_valor(valor_fila_por_header($f, $mapa_standby, 'CECO')),
                        'area' => limpiar_valor(valor_fila_por_header($f, $mapa_standby, 'AREA')),
                        'archivo_origen' => $archivo_origen,
                        'pestana_origen' => limpiar_valor(valor_fila_por_header($f, $mapa_standby, 'PESTAÑA_ORIGEN')),
                        'semana_carga' => $semana_carga,
                        'anio_carga' => $anio_carga
                    ];
                }

                // Standby puede tener 0 eventos en una semana/año; la hoja con encabezados
                // sigue siendo válida. No se exige que $datos_standby tenga filas.

                // ============================================================
                // 4) TRANSACCIÓN ÚNICA: HC + STANDBY
                // ============================================================
                mysqli_begin_transaction($conexion);
                $transaccion_iniciada = true;

                $total_hc = 0;
                $hc_eliminados = 0;
                $total_standby = 0;
                $standby_eliminados = 0;

                // ------------------------------------------------------------
                // 4A) HC: reemplazar por periodo
                // ------------------------------------------------------------
                $delete_hc = mysqli_prepare(
                    $conexion,
                    "DELETE FROM hc WHERE anio = ? AND semana = ?"
                );
                if (!$delete_hc) {
                    throw new Exception("No se pudo preparar DELETE HC: " . mysqli_error($conexion));
                }

                foreach ($semanas_detectadas as $periodo) {
                    mysqli_stmt_bind_param(
                        $delete_hc,
                        "ii",
                        $periodo['anio'],
                        $periodo['semana']
                    );
                    if (!mysqli_stmt_execute($delete_hc)) {
                        throw new Exception("Error al eliminar HC previo: " . mysqli_stmt_error($delete_hc));
                    }
                    $hc_eliminados += mysqli_stmt_affected_rows($delete_hc);
                }
                mysqli_stmt_close($delete_hc);

                $stmt_hc = mysqli_prepare($conexion, "
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
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ");
                if (!$stmt_hc) {
                    throw new Exception("No se pudo preparar INSERT HC: " . mysqli_error($conexion));
                }

                foreach ($datos_hc as $d) {
                    mysqli_stmt_bind_param(
                        $stmt_hc,
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

                    if (!mysqli_stmt_execute($stmt_hc)) {
                        throw new Exception(
                            "Error insertando HC de " .
                            ($d['nombre_colaborador'] ?? 'SIN NOMBRE') .
                            ": " . mysqli_stmt_error($stmt_hc)
                        );
                    }
                    $total_hc++;
                }
                mysqli_stmt_close($stmt_hc);

                // ------------------------------------------------------------
                // 4B) STANDBY: limpiar fuentes procesadas y reinsertar canónico
                // ------------------------------------------------------------
                if (!empty($archivos_fuente_detectados)) {
                    $delete_standby = mysqli_prepare(
                        $conexion,
                        "DELETE FROM hc_standby WHERE archivo_origen = ?"
                    );
                    if (!$delete_standby) {
                        throw new Exception(
                            "No se pudo preparar DELETE STANDBY: " . mysqli_error($conexion)
                        );
                    }

                    foreach (array_keys($archivos_fuente_detectados) as $archivo_fuente) {
                        mysqli_stmt_bind_param($delete_standby, "s", $archivo_fuente);
                        if (!mysqli_stmt_execute($delete_standby)) {
                            throw new Exception(
                                "Error al sincronizar STANDBY de '{$archivo_fuente}': " .
                                mysqli_stmt_error($delete_standby)
                            );
                        }
                        $standby_eliminados += mysqli_stmt_affected_rows($delete_standby);
                    }
                    mysqli_stmt_close($delete_standby);
                }

                $stmt_standby = mysqli_prepare($conexion, "
                    INSERT INTO hc_standby (
                        numero_talento_gs,
                        nombre_colaborador,
                        puesto,
                        tipo_standby,
                        fecha_inicio,
                        fecha_fin,
                        responsable_nombre,
                        responsable_rol,
                        sociedad,
                        ceco,
                        area,
                        archivo_origen,
                        pestana_origen,
                        semana_carga,
                        anio_carga
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        nombre_colaborador = VALUES(nombre_colaborador),
                        puesto = VALUES(puesto),
                        fecha_fin = VALUES(fecha_fin),
                        responsable_nombre = VALUES(responsable_nombre),
                        responsable_rol = VALUES(responsable_rol),
                        sociedad = VALUES(sociedad),
                        ceco = VALUES(ceco),
                        area = VALUES(area),
                        archivo_origen = VALUES(archivo_origen),
                        pestana_origen = VALUES(pestana_origen),
                        semana_carga = VALUES(semana_carga),
                        anio_carga = VALUES(anio_carga),
                        actualizado_en = CURRENT_TIMESTAMP
                ");
                if (!$stmt_standby) {
                    throw new Exception(
                        "No se pudo preparar INSERT STANDBY: " . mysqli_error($conexion)
                    );
                }

                foreach ($datos_standby as $d) {
                    mysqli_stmt_bind_param(
                        $stmt_standby,
                        "sssssssssssssii",
                        $d['numero_talento_gs'],
                        $d['nombre_colaborador'],
                        $d['puesto'],
                        $d['tipo_standby'],
                        $d['fecha_inicio'],
                        $d['fecha_fin'],
                        $d['responsable_nombre'],
                        $d['responsable_rol'],
                        $d['sociedad'],
                        $d['ceco'],
                        $d['area'],
                        $d['archivo_origen'],
                        $d['pestana_origen'],
                        $d['semana_carga'],
                        $d['anio_carga']
                    );

                    if (!mysqli_stmt_execute($stmt_standby)) {
                        throw new Exception(
                            "Error insertando STANDBY de " .
                            ($d['nombre_colaborador'] ?? $d['numero_talento_gs']) .
                            ": " . mysqli_stmt_error($stmt_standby)
                        );
                    }
                    $total_standby++;
                }
                mysqli_stmt_close($stmt_standby);

                // ============================================================
                // 5) LOG EXISTENTE
                // ============================================================
                // Conservamos el contrato histórico del log: tipo='hc' y el conteo
                // corresponde a registros HC. STANDBY se informa en el mensaje UI.
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
                    throw new Exception("No se pudo preparar log: " . mysqli_error($conexion));
                }

                mysqli_stmt_bind_param(
                    $log,
                    "ssis",
                    $tipo_log,
                    $v_archivo,
                    $total_hc,
                    $usuario
                );
                if (!mysqli_stmt_execute($log)) {
                    throw new Exception("Error al guardar log: " . mysqli_stmt_error($log));
                }
                mysqli_stmt_close($log);

                mysqli_commit($conexion);
                $transaccion_iniciada = false;

                if (file_exists($ruta_temp)) unlink($ruta_temp);

                $mensaje =
                    "✅ Importación exitosa. " .
                    "HC: {$total_hc} registros importados; {$hc_eliminados} previos reemplazados; " .
                    count($semanas_detectadas) . " periodos sincronizados. " .
                    "STANDBY: {$total_standby} eventos sincronizados " .
                    "({$conteo_standby_tipo['VACACIONES']} vacaciones / " .
                    "{$conteo_standby_tipo['INCAPACIDAD']} incapacidades); " .
                    "{$standby_eliminados} registros previos reemplazados. " .
                    "Archivos fuente auditados: " . count($archivos_fuente_detectados) . ".";

                $tipo_mensaje = "exito";

            } catch (Throwable $e) {
                if ($transaccion_iniciada) mysqli_rollback($conexion);
                if (file_exists($ruta_temp)) unlink($ruta_temp);
                $mensaje = "❌ Importación cancelada: " . $e->getMessage();
                $tipo_mensaje = "error";
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
<title>Importar HC + Standby</title>
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

    <h2>📥 Importar HC + Standby</h2>
    <p class="sub">
        Importación transaccional por encabezados.<br>
        Sincroniza Consolidado → hc y Standby → hc_standby.
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
