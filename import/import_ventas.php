<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
session_start();
$_SESSION['usuario'] = 'test'; // temporal

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
            $mensaje = "No fue posible guardar temporalmente el archivo.";
            $tipo_mensaje = "error";
        } elseif ($xlsx = SimpleXLSX::parse($ruta_temp)) {
            $filas = $xlsx->rows();
            $total = 0;
            $errores = 0;
            $omitidos = 0;

            $v = function($val) {
                return isset($val) && $val !== '' ? trim((string)$val) : null;
            };

            $fecha = function($val) {
                if ($val === null || $val === '' || $val === '-') return null;
                if (is_numeric($val)) {
                    $unix = ($val - 25569) * 86400;
                    return gmdate('Y-m-d', (int)$unix);
                }
                $d = date_create($val);
                return $d ? date_format($d, 'Y-m-d') : null;
            };

            $precio = function($val) {
                if ($val === null || $val === '' || $val === '-') return null;
                return floatval(str_replace(['$', ',', ' '], '', (string)$val));
            };

            /* ============================================================
               TALIA | IMPORTADOR DE VENTAS SALESFORCE

               Cambio 15-nov-2026:
               Salesforce agregó la columna OrdenServicio__c.Name (OS)
               inmediatamente después de Fecha de cierre.

               IMPORTANTE:
               Ya NO se depende de posiciones fijas del Excel.
               Las columnas se localizan por el encabezado Salesforce,
               por lo que el importador acepta:
                 - formato anterior (sin OS), dejando ventas.os = NULL
                 - formato nuevo (con OS), guardando OS-XXXXXXXX
               Así evitamos corrimientos si Salesforce agrega/mueve columnas.
               ============================================================ */

            if (empty($filas) || !isset($filas[0])) {
                $mensaje = "El archivo no contiene encabezados ni registros.";
                $tipo_mensaje = "error";
            } else {
                $encabezados = $filas[0];

                $buscarColumna = function(array $headers, string $fragmento) {
                    foreach ($headers as $idx => $header) {
                        if ($header !== null && stripos((string)$header, $fragmento) !== false) {
                            return $idx;
                        }
                    }
                    return null;
                };

                $cols = [
                    'fecha_cierre'        => $buscarColumna($encabezados, 'Oportunidad__c.CloseDate'),
                    'os'                  => $buscarColumna($encabezados, 'OrdenServicio__c.Name'),
                    'id_cuenta_brm'       => $buscarColumna($encabezados, 'IdCuentaBRM__c'),
                    'canal_venta'         => $buscarColumna($encabezados, 'Canal_de_Venta__c'),
                    'subcanal'            => $buscarColumna($encabezados, 'Oportunidad__c.Subcanal__c'),
                    'jefe_plaza'          => $buscarColumna($encabezados, 'EmpleadoJefePlaza__c.Name'),
                    'jefe_ventas'         => $buscarColumna($encabezados, 'EmpleadoJefeVentas__c.Name'),
                    'distrital'           => $buscarColumna($encabezados, 'EmpleadoDistrital__c.Name'),
                    'nombre_empleado'     => $buscarColumna($encabezados, 'Empleado__c.Name__lookup'),
                    'estatus_mesa'        => $buscarColumna($encabezados, 'EstatusMC__c'),
                    'nombre_plan'         => $buscarColumna($encabezados, 'NombrePlan__c'),
                    'fecha_activacion'    => $buscarColumna($encabezados, 'Fecha_activacion__c'),
                    'precio_pronto'       => $buscarColumna($encabezados, 'Precio_Pronto_Pago__c'),
                    'precio_lista'        => $buscarColumna($encabezados, 'Precio_Lista__c'),
                    'total_cargo'         => $buscarColumna($encabezados, 'PrecioCU_Total__c'),
                    'cluster'             => $buscarColumna($encabezados, 'OrdenServicio__c.Cluster__c'),
                    'distrito'            => $buscarColumna($encabezados, 'Distrito2__c'),
                    'forma_pago'          => $buscarColumna($encabezados, 'forma_pago__c'),
                    'telefono'            => $buscarColumna($encabezados, 'OrdenServicio__c.TelMovil__c'),
                    'plazo'               => $buscarColumna($encabezados, 'Cotizacion__c.Plazo__c'),
                    'num_oportunidad'     => $buscarColumna($encabezados, 'NumeroOportunidad__c'),
                    'folio_empleado'      => $buscarColumna($encabezados, 'FolioEmpleado__c'),
                    'estatus'             => $buscarColumna($encabezados, 'OrdenServicio__c.Estatus__c'),
                    'id_ot_gim'           => $buscarColumna($encabezados, 'OrdenServicio__c.IdOtGIM__c'),
                    'cp'                  => $buscarColumna($encabezados, 'Cot_SitioPlan__c.CP__c'),
                    'calle'               => $buscarColumna($encabezados, 'Sitio__c.Calle__c'),
                    'num_exterior'        => $buscarColumna($encabezados, 'NumeroExteriorEditable__c'),
                    'num_interior'        => $buscarColumna($encabezados, 'NumeroInterior__c'),
                    'colonia'             => $buscarColumna($encabezados, 'Sitio__c.Colonia__c'),
                    'municipio'           => $buscarColumna($encabezados, 'DelegacionMunicipio__c'),
                    'ciudad'              => $buscarColumna($encabezados, 'Sitio__c.Ciudad__c'),
                    'estado'              => $buscarColumna($encabezados, 'Sitio__c.Estado__c'),
                    'codigo_postal'       => $buscarColumna($encabezados, 'CodigoPostal__c'),
                    'region'              => $buscarColumna($encabezados, 'Sitio__c.Region__c'),
                    'zona'                => $buscarColumna($encabezados, 'Sitio__c.Zona__c')
                ];

                // Columnas mínimas para evitar importar un archivo desplazado/incompatible.
                $requeridas = [
                    'fecha_cierre', 'id_cuenta_brm', 'canal_venta', 'nombre_plan',
                    'distrito', 'num_oportunidad', 'folio_empleado', 'region'
                ];

                $faltantes = [];
                foreach ($requeridas as $campo) {
                    if ($cols[$campo] === null) $faltantes[] = $campo;
                }

                if (!empty($faltantes)) {
                    $mensaje = "Estructura de Salesforce no reconocida. Faltan columnas: " . implode(', ', $faltantes);
                    $tipo_mensaje = "error";
                } else {
                    $leer = function(array $fila, $idx) use ($v) {
                        return ($idx !== null && array_key_exists($idx, $fila)) ? $v($fila[$idx]) : null;
                    };

                    $sql = "INSERT IGNORE INTO ventas (
                        fecha_cierre, os, id_cuenta_brm, canal_venta, subcanal,
                        jefe_plaza, jefe_ventas, distrital, nombre_empleado,
                        estatus_mesa_control, nombre_plan, fecha_activacion,
                        precio_pronto_pago, precio_lista, total_cargo_unico,
                        cluster, distrito, forma_pago, telefono_movil, plazo,
                        numero_oportunidad, folio_empleado, estatus, id_ot_gim,
                        cp, calle, numero_exterior, numero_interior, colonia,
                        municipio, ciudad, estado, codigo_postal, region, zona,
                        archivo_origen
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

                    $stmt = mysqli_prepare($conexion, $sql);

                    if (!$stmt) {
                        $mensaje = "Error al preparar INSERT: " . mysqli_error($conexion);
                        $tipo_mensaje = "error";
                    } else {
                        for ($i = 1; $i < count($filas); $i++) {
                            $f = $filas[$i];
                            if (empty(array_filter($f, function($x) { return $x !== null && $x !== ''; }))) continue;

                            $v_fecha_cierre      = $fecha($leer($f, $cols['fecha_cierre']));
                            $v_os                = $leer($f, $cols['os']);
                            $v_id_cuenta_brm     = $leer($f, $cols['id_cuenta_brm']);
                            $v_canal_venta       = $leer($f, $cols['canal_venta']);
                            $v_subcanal          = $leer($f, $cols['subcanal']);
                            $v_jefe_plaza        = $leer($f, $cols['jefe_plaza']);
                            $v_jefe_ventas       = $leer($f, $cols['jefe_ventas']);
                            $v_distrital         = $leer($f, $cols['distrital']);
                            $v_nombre_empleado   = $leer($f, $cols['nombre_empleado']);
                            $v_estatus_mesa      = $leer($f, $cols['estatus_mesa']);
                            $v_nombre_plan       = $leer($f, $cols['nombre_plan']);
                            $v_fecha_activacion  = $fecha($leer($f, $cols['fecha_activacion']));
                            $v_precio_pronto     = $precio($leer($f, $cols['precio_pronto']));
                            $v_precio_lista      = $precio($leer($f, $cols['precio_lista']));
                            $v_total_cargo       = $precio($leer($f, $cols['total_cargo']));
                            $v_cluster           = $leer($f, $cols['cluster']);
                            $v_distrito          = $leer($f, $cols['distrito']);
                            $v_forma_pago        = $leer($f, $cols['forma_pago']);
                            $v_telefono          = $leer($f, $cols['telefono']);
                            $v_plazo             = $leer($f, $cols['plazo']);
                            $v_num_oportunidad   = $leer($f, $cols['num_oportunidad']);
                            $v_folio_empleado    = $leer($f, $cols['folio_empleado']);
                            $v_estatus           = $leer($f, $cols['estatus']);
                            $v_id_ot_gim         = $leer($f, $cols['id_ot_gim']);
                            $v_cp                = $leer($f, $cols['cp']);
                            $v_calle             = $leer($f, $cols['calle']);
                            $v_num_exterior      = $leer($f, $cols['num_exterior']);
                            $v_num_interior      = $leer($f, $cols['num_interior']);
                            $v_colonia           = $leer($f, $cols['colonia']);
                            $v_municipio         = $leer($f, $cols['municipio']);
                            $v_ciudad            = $leer($f, $cols['ciudad']);
                            $v_estado            = $leer($f, $cols['estado']);
                            $v_codigo_postal     = $leer($f, $cols['codigo_postal']);
                            $v_region            = $leer($f, $cols['region']);
                            $v_zona              = $leer($f, $cols['zona']);
                            $v_archivo           = $archivo['name'];

                            // 36 parámetros. Se envían como string; MySQL convierte DECIMAL/DATE según la columna destino.
                            $types = str_repeat('s', 36);
                            mysqli_stmt_bind_param($stmt, $types,
                                $v_fecha_cierre, $v_os, $v_id_cuenta_brm, $v_canal_venta, $v_subcanal,
                                $v_jefe_plaza, $v_jefe_ventas, $v_distrital, $v_nombre_empleado,
                                $v_estatus_mesa, $v_nombre_plan, $v_fecha_activacion,
                                $v_precio_pronto, $v_precio_lista, $v_total_cargo,
                                $v_cluster, $v_distrito, $v_forma_pago, $v_telefono, $v_plazo,
                                $v_num_oportunidad, $v_folio_empleado, $v_estatus, $v_id_ot_gim,
                                $v_cp, $v_calle, $v_num_exterior, $v_num_interior, $v_colonia,
                                $v_municipio, $v_ciudad, $v_estado, $v_codigo_postal, $v_region,
                                $v_zona, $v_archivo
                            );

                            if (mysqli_stmt_execute($stmt)) {
                                if (mysqli_stmt_affected_rows($stmt) > 0) {
                                    $total++;
                                } else {
                                    $omitidos++;
                                }
                            } else {
                                $errores++;
                            }
                        }

                        mysqli_stmt_close($stmt);

                        $tipo_log = 'ventas';
                        $v_archivo = $archivo['name'];
                        $log = mysqli_prepare($conexion, "INSERT INTO importaciones_log (tipo, archivo, registros_importados, usuario) VALUES (?,?,?,?)");
                        if ($log) {
                            mysqli_stmt_bind_param($log, "ssis", $tipo_log, $v_archivo, $total, $_SESSION['usuario']);
                            mysqli_stmt_execute($log);
                            mysqli_stmt_close($log);
                        }

                        $mensaje = "✅ Importación exitosa: $total registros importados. Omitidos/duplicados: $omitidos. Errores: $errores";
                        $tipo_mensaje = "exito";
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
    <title>Importar Ventas</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h2 { color: #1a1a2e; margin-bottom: 8px; }
        p.sub { color: #666; font-size: 0.9rem; margin-bottom: 24px; }
        .zona-upload { border: 2px dashed #4f46e5; border-radius: 10px; padding: 40px; text-align: center; color: #4f46e5; cursor: pointer; margin-bottom: 20px; transition: background 0.2s; }
        .zona-upload:hover { background: #eef2ff; }
        input[type="file"] { display: none; }
        button { width: 100%; padding: 12px; background: #4f46e5; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #4338ca; }
        .exito { background: #dcfce7; color: #166534; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .error  { background: #fee2e2; color: #991b1b; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .back { display: block; text-align: center; margin-top: 16px; color: #4f46e5; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="card">
    <h2>📥 Importar Ventas</h2>
    <p class="sub">Sube tu archivo Excel (.xlsx) con el reporte de ventas Salesforce</p>

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
    document.getElementById('zona').style.background = '#eef2ff';
}
</script>
</body>
</html>
