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

function buscarColumna(array $encabezados, string $esperado): ?int
{
    $esperado = encabezadoNormalizado($esperado);

    foreach ($encabezados as $indice => $encabezado) {
        if (encabezadoNormalizado($encabezado) === $esperado) {
            return (int)$indice;
        }
    }

    return null;
}

function semanaIsoDesdeFecha(string $fecha): array
{
    $dt = new DateTime($fecha);
    return [
        'anio_iso' => (int)$dt->format('o'),
        'semana'   => (int)$dt->format('W'),
    ];
}

function rangoSemanaIso(int $anioIso, int $semana): array
{
    $inicio = new DateTime();
    $inicio->setISODate($anioIso, $semana, 1); // lunes

    $finExclusivo = clone $inicio;
    $finExclusivo->modify('+7 days');

    return [
        'inicio' => $inicio->format('Y-m-d'),
        'fin_exclusivo' => $finExclusivo->format('Y-m-d'),
        'fin_visible' => (clone $finExclusivo)->modify('-1 day')->format('Y-m-d'),
    ];
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

                /*
                 * ============================================================
                 * TALIA | IMPORTADOR INTELIGENTE DE CANCELACIONES QLIK
                 * ============================================================
                 *
                 * REGLA DE NEGOCIO:
                 * El archivo semanal de Qlik es el SNAPSHOT / FUENTE DE VERDAD
                 * de la semana contenida en el archivo.
                 *
                 * Por lo tanto el importador:
                 *   1) valida estructura y fechas;
                 *   2) consolida Cuenta + Fecha Cancelación (una cancelación);
                 *   3) actualiza registros que cambiaron;
                 *   4) inserta cancelaciones nuevas;
                 *   5) elimina de TalIA las cancelaciones que ya NO aparecen
                 *      en el snapshot actualizado de Qlik;
                 *   6) ejecuta todo dentro de una transacción.
                 *
                 * Así YA NO es necesario borrar manualmente la semana antes
                 * de volver a importar cada lunes.
                 *
                 * IMPORTANTE:
                 * - Cargar únicamente el archivo COMPLETO de una sola semana.
                 * - Si Qlik repite Cuenta + Fecha por diferencias de Plan,
                 *   se conserva la última aparición del archivo, igual que el
                 *   comportamiento histórico del importador con UPDATE.
                 * ============================================================
                 */

                if (empty($filas) || !isset($filas[0])) {
                    $mensaje = 'El archivo no contiene encabezados ni registros.';
                    $tipo_mensaje = 'error';
                    @unlink($rutaTemp);
                } else {
                    $encabezados = $filas[0];

                    // Ya no dependemos de posiciones fijas ni de columnas vacías intercaladas.
                    $columnas = [
                        'cuenta'             => buscarColumna($encabezados, 'CUENTA'),
                        'ciclo'              => buscarColumna($encabezados, 'CICLO'),
                        'fecha_activacion'   => buscarColumna($encabezados, 'ACTIVACION'),
                        'fecha_cancelacion'  => buscarColumna($encabezados, 'CANCELACION'),
                        'semana_cancelacion' => buscarColumna($encabezados, 'SEMANA CANCELACION'),
                        'meses_activo'       => buscarColumna($encabezados, 'MESES ACTIVO'),
                        'tipo_cancelacion'   => buscarColumna($encabezados, 'TIPO CANCELACION'),
                        'segmentacion'       => buscarColumna($encabezados, 'SEGMENTACION'),
                        'canal_venta'        => buscarColumna($encabezados, 'CANALVENTA'),
                        'plaza'              => buscarColumna($encabezados, 'PLAZA'),
                        'cluster'            => buscarColumna($encabezados, 'CLUSTER'),
                        'distrito'           => buscarColumna($encabezados, 'DISTRITO'),
                        'plan_dsc'           => buscarColumna($encabezados, 'PLANDSC'),
                        'plan'               => buscarColumna($encabezados, 'PLAN'),
                        'familia'            => buscarColumna($encabezados, 'FAMILIA'),
                        'tipo'               => buscarColumna($encabezados, 'TIPO'),
                    ];

                    $faltantes = [];
                    foreach ($columnas as $campo => $indice) {
                        if ($indice === null) {
                            $faltantes[] = $campo;
                        }
                    }

                    if ($faltantes) {
                        $mensaje = 'La estructura del archivo no coincide con el reporte Qlik de cancelaciones. '
                            . 'Faltan columnas: ' . implode(', ', $faltantes) . '.';
                        $tipo_mensaje = 'error';
                        @unlink($rutaTemp);
                    } else {
                        $archivoOrigen = basename($archivo['name']);
                        $filasValidas = 0;
                        $filasOmitidas = 0;
                        $detalleErrores = [];
                        $semanasDetectadas = [];
                        $aniosIsoDetectados = [];

                        /*
                         * Tabla staging temporal:
                         * PRIMARY KEY Cuenta + Fecha Cancelación consolida
                         * cualquier duplicado que venga en el propio Qlik.
                         */
                        mysqli_query($conexion, 'DROP TEMPORARY TABLE IF EXISTS tmp_cancelaciones_sync');

                        $sqlTmp = "
                            CREATE TEMPORARY TABLE tmp_cancelaciones_sync (
                                cuenta VARCHAR(100) NOT NULL,
                                ciclo SMALLINT UNSIGNED NULL,
                                fecha_activacion DATE NULL,
                                fecha_cancelacion DATE NOT NULL,
                                semana_cancelacion SMALLINT UNSIGNED NULL,
                                meses_activo SMALLINT UNSIGNED NULL,
                                tipo_cancelacion VARCHAR(150) NULL,
                                segmentacion VARCHAR(100) NULL,
                                canal_venta VARCHAR(150) NULL,
                                plaza VARCHAR(100) NULL,
                                cluster VARCHAR(150) NULL,
                                distrito VARCHAR(100) NULL,
                                plan_dsc VARCHAR(255) NULL,
                                plan VARCHAR(255) NULL,
                                familia VARCHAR(255) NULL,
                                tipo VARCHAR(100) NULL,
                                archivo_origen VARCHAR(255) NULL,
                                PRIMARY KEY (cuenta, fecha_cancelacion),
                                KEY idx_tmp_semana (semana_cancelacion),
                                KEY idx_tmp_fecha (fecha_cancelacion)
                            ) ENGINE=InnoDB
                        ";

                        if (!mysqli_query($conexion, $sqlTmp)) {
                            $mensaje = 'No fue posible crear el staging de sincronización: ' . mysqli_error($conexion);
                            $tipo_mensaje = 'error';
                            @unlink($rutaTemp);
                        } else {
                            $sqlStage = "
                                INSERT INTO tmp_cancelaciones_sync (
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
                                    archivo_origen = VALUES(archivo_origen)
                            ";

                            $stage = mysqli_prepare($conexion, $sqlStage);

                            if (!$stage) {
                                $mensaje = 'No fue posible preparar el staging: ' . mysqli_error($conexion);
                                $tipo_mensaje = 'error';
                                @unlink($rutaTemp);
                            } else {
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

                                    if ($cuenta === null || $fechaCancelacion === null || $semanaCancelacion === null) {
                                        $filasOmitidas++;
                                        if (count($detalleErrores) < 10) {
                                            $detalleErrores[] = 'Fila ' . ($i + 1)
                                                . ': falta Cuenta, Fecha Cancelación o Semana Cancelación válida.';
                                        }
                                        continue;
                                    }

                                    $iso = semanaIsoDesdeFecha($fechaCancelacion);
                                    if ($iso['semana'] !== $semanaCancelacion) {
                                        $filasOmitidas++;
                                        if (count($detalleErrores) < 10) {
                                            $detalleErrores[] = 'Fila ' . ($i + 1)
                                                . ': la fecha ' . $fechaCancelacion
                                                . ' pertenece a S' . $iso['semana']
                                                . ', pero Qlik reporta S' . $semanaCancelacion . '.';
                                        }
                                        continue;
                                    }

                                    $semanasDetectadas[$semanaCancelacion] = true;
                                    $aniosIsoDetectados[$iso['anio_iso']] = true;

                                    mysqli_stmt_bind_param(
                                        $stage,
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

                                    if (!mysqli_stmt_execute($stage)) {
                                        $filasOmitidas++;
                                        if (count($detalleErrores) < 10) {
                                            $detalleErrores[] = 'Fila ' . ($i + 1) . ': ' . mysqli_stmt_error($stage);
                                        }
                                        continue;
                                    }

                                    $filasValidas++;
                                }

                                mysqli_stmt_close($stage);

                                // La sincronización automática requiere un archivo completo de UNA sola semana.
                                if ($filasValidas === 0) {
                                    $mensaje = 'No se encontraron cancelaciones válidas para sincronizar.';
                                    $tipo_mensaje = 'error';
                                    if ($detalleErrores) {
                                        $mensaje .= ' ' . implode(' | ', $detalleErrores);
                                    }
                                    @unlink($rutaTemp);
                                } elseif (count($semanasDetectadas) !== 1 || count($aniosIsoDetectados) !== 1) {
                                    $mensaje = 'Por seguridad, el importador inteligente sólo acepta un archivo completo '
                                        . 'de una sola semana. Semanas detectadas: '
                                        . implode(', ', array_keys($semanasDetectadas)) . '.';
                                    $tipo_mensaje = 'error';
                                    @unlink($rutaTemp);
                                } elseif ($filasOmitidas > 0) {
                                    /*
                                     * Si hay filas inválidas NO sincronizamos.
                                     * Un archivo incompleto podría borrar cancelaciones válidas de la BD.
                                     */
                                    $mensaje = 'Sincronización cancelada por seguridad: hay ' . $filasOmitidas
                                        . ' fila(s) inválida(s). Corrige el archivo antes de sincronizar.';
                                    if ($detalleErrores) {
                                        $mensaje .= ' ' . implode(' | ', $detalleErrores);
                                    }
                                    $tipo_mensaje = 'error';
                                    @unlink($rutaTemp);
                                } else {
                                    $semanaArchivo = (int)array_key_first($semanasDetectadas);
                                    $anioIso = (int)array_key_first($aniosIsoDetectados);
                                    $rango = rangoSemanaIso($anioIso, $semanaArchivo);

                                    $resSnapshot = mysqli_query(
                                        $conexion,
                                        'SELECT COUNT(*) AS total FROM tmp_cancelaciones_sync'
                                    );
                                    $snapshotUnico = (int)(mysqli_fetch_assoc($resSnapshot)['total'] ?? 0);
                                    $duplicadosQlik = $filasValidas - $snapshotUnico;

                                    /*
                                     * Seguridad adicional: todas las filas staging deben estar dentro
                                     * del lunes-domingo correspondiente a la semana detectada.
                                     */
                                    $stmtFuera = mysqli_prepare(
                                        $conexion,
                                        'SELECT COUNT(*) AS total
                                         FROM tmp_cancelaciones_sync
                                         WHERE fecha_cancelacion < ?
                                            OR fecha_cancelacion >= ?'
                                    );
                                    mysqli_stmt_bind_param(
                                        $stmtFuera,
                                        'ss',
                                        $rango['inicio'],
                                        $rango['fin_exclusivo']
                                    );
                                    mysqli_stmt_execute($stmtFuera);
                                    $resFuera = mysqli_stmt_get_result($stmtFuera);
                                    $fueraRango = (int)(mysqli_fetch_assoc($resFuera)['total'] ?? 0);
                                    mysqli_stmt_close($stmtFuera);

                                    if ($fueraRango > 0 || $snapshotUnico === 0) {
                                        $mensaje = 'Sincronización cancelada: el archivo contiene fechas fuera del rango '
                                            . 'esperado para S' . $semanaArchivo . ' (' . $rango['inicio']
                                            . ' a ' . $rango['fin_visible'] . ').';
                                        $tipo_mensaje = 'error';
                                        @unlink($rutaTemp);
                                    } else {
                                        mysqli_begin_transaction($conexion);

                                        try {
                                            // Conteo previo de TalIA para la semana objetivo.
                                            $stmtPrevio = mysqli_prepare(
                                                $conexion,
                                                'SELECT COUNT(*) AS total
                                                 FROM cancelaciones
                                                 WHERE fecha_cancelacion >= ?
                                                   AND fecha_cancelacion < ?'
                                            );
                                            mysqli_stmt_bind_param(
                                                $stmtPrevio,
                                                'ss',
                                                $rango['inicio'],
                                                $rango['fin_exclusivo']
                                            );
                                            mysqli_stmt_execute($stmtPrevio);
                                            $resPrevio = mysqli_stmt_get_result($stmtPrevio);
                                            $totalAntes = (int)(mysqli_fetch_assoc($resPrevio)['total'] ?? 0);
                                            mysqli_stmt_close($stmtPrevio);

                                            // Cuántas cancelaciones del snapshot ya existen por Cuenta + Fecha.
                                            $resCoinciden = mysqli_query(
                                                $conexion,
                                                "SELECT COUNT(*) AS total
                                                 FROM tmp_cancelaciones_sync t
                                                 INNER JOIN cancelaciones c
                                                   ON c.cuenta = t.cuenta
                                                  AND c.fecha_cancelacion = t.fecha_cancelacion"
                                            );
                                            if (!$resCoinciden) {
                                                throw new RuntimeException(mysqli_error($conexion));
                                            }
                                            $coinciden = (int)(mysqli_fetch_assoc($resCoinciden)['total'] ?? 0);

                                            /*
                                             * 1) ACTUALIZAR coincidencias.
                                             * Afectadas = filas realmente modificadas (sin CLIENT_FOUND_ROWS).
                                             */
                                            $sqlUpdate = "
                                                UPDATE cancelaciones c
                                                INNER JOIN tmp_cancelaciones_sync t
                                                   ON c.cuenta = t.cuenta
                                                  AND c.fecha_cancelacion = t.fecha_cancelacion
                                                SET
                                                    c.ciclo = t.ciclo,
                                                    c.fecha_activacion = t.fecha_activacion,
                                                    c.semana_cancelacion = t.semana_cancelacion,
                                                    c.meses_activo = t.meses_activo,
                                                    c.tipo_cancelacion = t.tipo_cancelacion,
                                                    c.segmentacion = t.segmentacion,
                                                    c.canal_venta = t.canal_venta,
                                                    c.plaza = t.plaza,
                                                    c.cluster = t.cluster,
                                                    c.distrito = t.distrito,
                                                    c.plan_dsc = t.plan_dsc,
                                                    c.plan = t.plan,
                                                    c.familia = t.familia,
                                                    c.tipo = t.tipo,
                                                    c.archivo_origen = t.archivo_origen,
                                                    c.actualizado_en = CURRENT_TIMESTAMP
                                            ";
                                            if (!mysqli_query($conexion, $sqlUpdate)) {
                                                throw new RuntimeException(mysqli_error($conexion));
                                            }
                                            $actualizados = mysqli_affected_rows($conexion);
                                            $sinCambios = max(0, $coinciden - $actualizados);

                                            /*
                                             * 2) INSERTAR cancelaciones nuevas.
                                             */
                                            $sqlInsert = "
                                                INSERT INTO cancelaciones (
                                                    cuenta, ciclo, fecha_activacion, fecha_cancelacion,
                                                    semana_cancelacion, meses_activo, tipo_cancelacion,
                                                    segmentacion, canal_venta, plaza, cluster, distrito,
                                                    plan_dsc, plan, familia, tipo, archivo_origen
                                                )
                                                SELECT
                                                    t.cuenta, t.ciclo, t.fecha_activacion, t.fecha_cancelacion,
                                                    t.semana_cancelacion, t.meses_activo, t.tipo_cancelacion,
                                                    t.segmentacion, t.canal_venta, t.plaza, t.cluster, t.distrito,
                                                    t.plan_dsc, t.plan, t.familia, t.tipo, t.archivo_origen
                                                FROM tmp_cancelaciones_sync t
                                                LEFT JOIN cancelaciones c
                                                  ON c.cuenta = t.cuenta
                                                 AND c.fecha_cancelacion = t.fecha_cancelacion
                                                WHERE c.id IS NULL
                                            ";
                                            if (!mysqli_query($conexion, $sqlInsert)) {
                                                throw new RuntimeException(mysqli_error($conexion));
                                            }
                                            $insertados = mysqli_affected_rows($conexion);

                                            /*
                                             * 3) ELIMINAR registros de la semana que ya NO vienen en Qlik.
                                             * Ésta es la parte que sustituye el DELETE manual de cada lunes.
                                             */
                                            $stmtDelete = mysqli_prepare(
                                                $conexion,
                                                "DELETE c
                                                 FROM cancelaciones c
                                                 LEFT JOIN tmp_cancelaciones_sync t
                                                   ON t.cuenta = c.cuenta
                                                  AND t.fecha_cancelacion = c.fecha_cancelacion
                                                 WHERE c.fecha_cancelacion >= ?
                                                   AND c.fecha_cancelacion < ?
                                                   AND t.cuenta IS NULL"
                                            );
                                            if (!$stmtDelete) {
                                                throw new RuntimeException(mysqli_error($conexion));
                                            }
                                            mysqli_stmt_bind_param(
                                                $stmtDelete,
                                                'ss',
                                                $rango['inicio'],
                                                $rango['fin_exclusivo']
                                            );
                                            if (!mysqli_stmt_execute($stmtDelete)) {
                                                throw new RuntimeException(mysqli_stmt_error($stmtDelete));
                                            }
                                            $eliminados = mysqli_stmt_affected_rows($stmtDelete);
                                            mysqli_stmt_close($stmtDelete);

                                            // Validación final: TalIA debe quedar 1:1 con el snapshot único Qlik.
                                            $stmtFinal = mysqli_prepare(
                                                $conexion,
                                                'SELECT COUNT(*) AS total
                                                 FROM cancelaciones
                                                 WHERE fecha_cancelacion >= ?
                                                   AND fecha_cancelacion < ?'
                                            );
                                            mysqli_stmt_bind_param(
                                                $stmtFinal,
                                                'ss',
                                                $rango['inicio'],
                                                $rango['fin_exclusivo']
                                            );
                                            mysqli_stmt_execute($stmtFinal);
                                            $resFinal = mysqli_stmt_get_result($stmtFinal);
                                            $totalFinal = (int)(mysqli_fetch_assoc($resFinal)['total'] ?? 0);
                                            mysqli_stmt_close($stmtFinal);

                                            if ($totalFinal !== $snapshotUnico) {
                                                throw new RuntimeException(
                                                    'Validación final no coincide: Qlik único=' . $snapshotUnico
                                                    . ', TalIA=' . $totalFinal . '.'
                                                );
                                            }

                                            mysqli_commit($conexion);

                                            $usuario = $_SESSION['usuario']
                                                ?? $_SESSION['username']
                                                ?? 'sistema';
                                            $tipoLog = 'cancelaciones';
                                            $log = mysqli_prepare(
                                                $conexion,
                                                'INSERT INTO importaciones_log
                                                 (tipo, archivo, registros_importados, usuario)
                                                 VALUES (?,?,?,?)'
                                            );
                                            if ($log) {
                                                mysqli_stmt_bind_param(
                                                    $log,
                                                    'ssis',
                                                    $tipoLog,
                                                    $archivoOrigen,
                                                    $totalFinal,
                                                    $usuario
                                                );
                                                mysqli_stmt_execute($log);
                                                mysqli_stmt_close($log);
                                            }

                                            $mensaje =
                                                'Sincronización S' . $semanaArchivo . ' exitosa. '
                                                . 'Qlik: ' . $filasValidas . ' filas'
                                                . ' | Cancelaciones únicas: ' . $snapshotUnico
                                                . ' | Duplicados Qlik consolidados: ' . $duplicadosQlik
                                                . ' | TalIA antes: ' . $totalAntes
                                                . ' | Nuevas: ' . $insertados
                                                . ' | Actualizadas: ' . $actualizados
                                                . ' | Sin cambios: ' . $sinCambios
                                                . ' | Eliminadas porque ya no están en Qlik: ' . $eliminados
                                                . ' | TalIA final: ' . $totalFinal . '.';

                                            $tipo_mensaje = 'exito';
                                        } catch (Throwable $e) {
                                            mysqli_rollback($conexion);
                                            $mensaje = 'Sincronización cancelada y revertida. '
                                                . 'La semana anterior quedó intacta. Error: ' . $e->getMessage();
                                            $tipo_mensaje = 'error';
                                        }

                                        @unlink($rutaTemp);
                                    }
                                }
                            }
                        }
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
            max-width: 620px;
        }
        h2 { color: #1a1a2e; margin-bottom: 8px; }
        p.sub { color: #666; font-size: .92rem; margin-bottom: 14px; line-height: 1.5; }
        .aviso {
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: .88rem;
            line-height: 1.45;
        }
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
    <p class="sub">
        Sincroniza TalIA contra el archivo semanal completo de Qlik.
        Las altas, cambios y bajas de la semana se aplican automáticamente.
    </p>

    <div class="aviso">
        <strong>Importante:</strong> carga únicamente el archivo COMPLETO de una sola semana.
        Ya no necesitas borrar manualmente las cancelaciones antes de importar.
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="<?= htmlspecialchars($tipo_mensaje, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label for="archivo">
            <div class="zona-upload" id="zona">
                Haz clic para seleccionar el archivo Qlik .xlsx
                <br><small id="nombre-archivo"></small>
            </div>
        </label>
        <input type="file" name="archivo" id="archivo" accept=".xlsx" required onchange="mostrarNombre(this)">
        <button type="submit">Sincronizar cancelaciones</button>
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
