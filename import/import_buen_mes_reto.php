<?php
/** Importador XLSX para buen_mes_reto. PHP 7.4+ / mysqli / SimpleXLSX. */
use Shuchkin\SimpleXLSX;

set_time_limit(0);
ini_set('memory_limit', '512M');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Utilizar la sesión real de la plataforma, nunca un usuario de prueba.
if (empty($_SESSION['usuario'])) {
    http_response_code(403);
    exit('Acceso restringido. Inicia sesión en la plataforma.');
}
if (empty($_SESSION['csrf_buen_mes_reto'])) {
    $_SESSION['csrf_buen_mes_reto'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_buen_mes_reto'];
$esPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$mensaje = '';
$tipo = '';
$detalles = [];
$resumen = null;
$maxBytes = 20 * 1024 * 1024;

function bmr_h($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Normaliza notación científica y valida precisión sin convertir texto a float.
// Los importes se envían a MySQL como cadenas decimales exactas.
function bmr_decimal($valor, $maxEnteros = 8, $escala = 7) {
    if ($valor === null || is_bool($valor) || !is_scalar($valor)) {
        throw new InvalidArgumentException('valor numérico vacío o inválido');
    }
    if (is_float($valor)) {
        if (!is_finite($valor)) {
            throw new InvalidArgumentException('valor no finito');
        }
        // JSON obtiene la representación decimal más corta del número de Excel.
        $texto = json_encode($valor, JSON_PRESERVE_ZERO_FRACTION);
    } else {
        $texto = trim((string) $valor);
    }
    if (strlen($texto) > 128 || !preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D', $texto, $m)) {
        throw new InvalidArgumentException('debe ser un número con punto decimal, sin moneda ni separadores de miles');
    }
    $entera = $m[2] ?? '';
    $decimal = ($entera !== '') ? ($m[3] ?? '') : ($m[4] ?? '');
    $exp = (int) ($m[5] ?? 0);
    if (abs($exp) > 128) {
        throw new InvalidArgumentException('exponente fuera de rango');
    }
    $digitos = $entera . $decimal;
    $pos = strlen($entera) + $exp;
    if ($pos <= 0) {
        $entera = '0';
        $decimal = str_repeat('0', -$pos) . $digitos;
    } elseif ($pos >= strlen($digitos)) {
        $entera = $digitos . str_repeat('0', $pos - strlen($digitos));
        $decimal = '';
    } else {
        $entera = substr($digitos, 0, $pos);
        $decimal = substr($digitos, $pos);
    }
    $entera = ltrim($entera, '0');
    $entera = $entera === '' ? '0' : $entera;
    $decimal = rtrim($decimal, '0');
    if (strlen($entera) > $maxEnteros) {
        throw new InvalidArgumentException('supera los ' . $maxEnteros . ' dígitos enteros permitidos');
    }
    if (strlen($decimal) > $escala) {
        throw new InvalidArgumentException('supera los ' . $escala . ' decimales permitidos; no se redondeó');
    }
    $signo = ($m[1] === '-' && ($entera !== '0' || $decimal !== '')) ? '-' : '';
    return $signo . $entera . ($escala ? '.' . str_pad($decimal, $escala, '0') : '');
}

function bmr_cuenta($valor) {
    if ($valor === null || is_bool($valor) || !is_scalar($valor)) {
        throw new InvalidArgumentException('CUENTA vacía o inválida');
    }
    if (is_float($valor)) {
        $valor = bmr_decimal($valor, 15, 0);
    }
    $valor = trim((string) $valor);
    // Cuenta numérica que Excel pueda haber devuelto en notación científica.
    if (preg_match('/^\d+(?:\.\d+)?[eE][+-]?\d+$/D', $valor)) {
        $valor = bmr_decimal($valor, 15, 0);
    }
    if (preg_match('/^\d+\.0+$/D', $valor)) {
        $valor = substr($valor, 0, strpos($valor, '.'));
    }
    if ($valor === '' || !preg_match('/^[^\p{Cc}]{1,15}$/uD', $valor)) {
        throw new InvalidArgumentException('CUENTA debe contener de 1 a 15 caracteres válidos');
    }
    return $valor;
}

function bmr_fila_vacia(array $fila) {
    foreach ($fila as $valor) {
        if ($valor !== null && trim((string) $valor) !== '') {
            return false;
        }
    }
    return true;
}

if ($esPost) {
    $enTransaccion = false;
    $stmt = null;
    $conexion = null;
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sesión del formulario inválida o archivo superior al límite del servidor. Recarga la página e intenta nuevamente.');
        }
        $archivo = $_FILES['archivo'] ?? null;
        if (!is_array($archivo) || is_array($archivo['error'] ?? null)) {
            throw new RuntimeException('Selecciona un archivo Excel.');
        }
        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('El archivo supera el límite de carga permitido por PHP.');
            }
            throw new RuntimeException('No se pudo recibir el archivo (código de carga ' . (int) $error . ').');
        }
        if (strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Solo se permiten archivos .xlsx.');
        }
        if (!is_uploaded_file($archivo['tmp_name']) || $archivo['size'] <= 0 || $archivo['size'] > $maxBytes) {
            throw new RuntimeException('El archivo es inválido, está vacío o supera los 20 MB.');
        }
        require_once $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/SimpleXLSX.php';
        $xlsx = SimpleXLSX::parse($archivo['tmp_name']);
        if (!$xlsx) {
            throw new RuntimeException('No fue posible leer el Excel: ' . SimpleXLSX::parseError());
        }
        $filas = $xlsx->rows(); // Primera hoja, igual que el importador de referencia.
        if (empty($filas)) {
            throw new RuntimeException('La primera hoja del Excel está vacía.');
        }
        // Clave exacta normalizada: rentaSinImpuestos -> rentasinimpuestos.
        $requeridas = ['cuenta' => 'CUENTA', 'rentasinimpuestos' => 'rentaSinImpuestos', 'precio_brm' => 'PRECIO_BRM'];
        $indices = [];
        foreach ($filas[0] as $i => $encabezado) {
            $clave = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $encabezado)));
            if (!isset($requeridas[$clave])) {
                continue;
            }
            if (isset($indices[$clave])) {
                throw new RuntimeException('Encabezado duplicado: ' . $requeridas[$clave] . '.');
            }
            $indices[$clave] = $i;
        }
        $faltantes = array_diff_key($requeridas, $indices);
        if ($faltantes) {
            throw new RuntimeException('Faltan columnas en la primera fila: ' . implode(', ', $faltantes) . '.');
        }
        $registros = [];
        $vacias = 0;
        $invalidas = 0;
        for ($i = 1, $n = count($filas); $i < $n; $i++) {
            $fila = $filas[$i];
            if (bmr_fila_vacia($fila)) {
                $vacias++;
                continue;
            }
            $campo = 'CUENTA';
            try {
                $cuenta = bmr_cuenta($fila[$indices['cuenta']] ?? null);
                $campo = 'rentaSinImpuestos';
                $renta = bmr_decimal($fila[$indices['rentasinimpuestos']] ?? null);
                $campo = 'PRECIO_BRM';
                $precio = bmr_decimal($fila[$indices['precio_brm']] ?? null);
                $registros[] = [$cuenta, $renta, $precio, $i + 1];
            } catch (InvalidArgumentException $e) {
                $invalidas++;
                if (count($detalles) < 20) {
                    $detalles[] = 'Fila ' . ($i + 1) . ' — ' . $campo . ': ' . $e->getMessage();
                }
            }
        }
        unset($filas, $xlsx);
        if ($invalidas) {
            $resumen = ['importadas' => 0, 'vacias' => $vacias, 'invalidas' => $invalidas];
            throw new RuntimeException('Se encontraron ' . $invalidas . ' filas inválidas. No se importó ningún registro. Corrige el Excel y vuelve a cargarlo.');
        }
        if (!$registros) {
            throw new RuntimeException('El archivo no contiene registros para importar.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        require $_SERVER['DOCUMENT_ROOT'] . '/plataforma/includes/conexion.php';
        if (!($conexion instanceof mysqli)) {
            throw new RuntimeException('La conexión mysqli de la plataforma no está disponible.');
        }
        // Exigir un motor transaccional para que un error no deje importaciones parciales.
        $resultado = $conexion->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buen_mes_reto'");
        $tabla = $resultado->fetch_assoc();
        $resultado->free();
        if (!$tabla || strcasecmp((string) $tabla['ENGINE'], 'InnoDB') !== 0) {
            throw new RuntimeException('La tabla buen_mes_reto debe existir y utilizar InnoDB para importar de forma transaccional.');
        }
        $conexion->begin_transaction();
        $enTransaccion = true;
        $stmt = $conexion->prepare('INSERT INTO `buen_mes_reto` (`CUENTA`, `rentaSinImpuestos`, `PRECIO_BRM`) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $cuenta, $renta, $precio);
        $total = 0;
        foreach ($registros as $registro) {
            [$cuenta, $renta, $precio, $filaExcel] = $registro;
            try {
                $stmt->execute();
                if ($conexion->warning_count > 0) {
                    throw new RuntimeException('MySQL emitió una advertencia al insertar los datos.');
                }
            } catch (Throwable $e) {
                error_log('buen_mes_reto, fila Excel ' . $filaExcel . ': ' . $e->getMessage());
                throw new RuntimeException('Error de base de datos en la fila ' . $filaExcel . '. Verifica los tipos de columnas, restricciones y permisos. Se canceló toda la importación.');
            }
            $total++;
        }
        $conexion->commit();
        $enTransaccion = false;
        $resumen = ['importadas' => $total, 'vacias' => $vacias, 'invalidas' => 0];
        $mensaje = 'Importación completada correctamente.';
        $tipo = 'exito';
    } catch (Throwable $e) {
        if ($enTransaccion && $conexion instanceof mysqli) {
            try { $conexion->rollback(); } catch (Throwable $rollbackError) { error_log('Rollback buen_mes_reto: ' . $rollbackError->getMessage()); }
        }
        if ($e instanceof mysqli_sql_exception || !($e instanceof RuntimeException)) {
            error_log('Importador buen_mes_reto: ' . $e->getMessage());
            $mensaje = 'No se pudo completar la importación. Revisa la conexión, la librería XLSX y el registro de errores del servidor.';
        } else {
            $mensaje = $e->getMessage();
        }
        $tipo = 'error';
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
    // Post/Redirect/Get: evita repetir el INSERT al recargar la página.
    $_SESSION['bmr_resultado'] = compact('mensaje', 'tipo', 'detalles', 'resumen');
    header('Location: ' . basename(__FILE__), true, 303);
    exit;
}
if (isset($_SESSION['bmr_resultado'])) {
    $resultado = $_SESSION['bmr_resultado'];
    unset($_SESSION['bmr_resultado']);
    $mensaje = $resultado['mensaje'];
    $tipo = $resultado['tipo'];
    $detalles = $resultado['detalles'];
    $resumen = $resultado['resumen'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importar Buen Mes Reto</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 16px; font-family: 'Segoe UI', sans-serif; color: #1a1a2e; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.1); width: 100%; max-width: 560px; }
        h1 { font-size: 25px; margin: 0 0 8px; }
        .sub { color: #666; font-size: 14px; line-height: 1.55; margin: 0 0 22px; }
        .zona-upload { display: block; border: 2px dashed #059669; border-radius: 10px; padding: 28px 16px; text-align: center; color: #047857; cursor: pointer; margin-bottom: 16px; overflow-wrap: anywhere; }
        .zona-upload:hover, .zona-upload:focus-within, .zona-upload.seleccionado { background: #ecfdf5; }
        .zona-upload strong { display: block; margin-bottom: 7px; }
        .zona-upload small { display: block; margin-top: 8px; color: #4b5563; }
        input[type=file] { max-width: 100%; width: 100%; font: inherit; font-size: 13px; color: #374151; }
        input[type=file]::file-selector-button { margin-right: 10px; border: 0; border-radius: 6px; background: #d1fae5; color: #065f46; padding: 8px; cursor: pointer; }
        button { width: 100%; padding: 13px; background: #059669; color: white; border: none; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { background: #047857; } button:disabled { opacity: .65; cursor: wait; }
        .exito, .error { padding: 14px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; }
        .exito { background: #dcfce7; color: #166534; } .error { background: #fee2e2; color: #991b1b; }
        .resultado { margin: 10px 0 0; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        .resultado div { background: rgba(255,255,255,.6); border-radius: 6px; padding: 10px 5px; text-align: center; font-size: 12px; }
        .resultado strong { display: block; font-size: 21px; }
        .detalles { padding-left: 20px; margin: 10px 0 0; }
        .formato { margin: 0 0 18px; font-size: 13px; color: #4b5563; }
        .formato table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
        .formato td, .formato th { text-align: left; border: 1px solid #e5e7eb; padding: 8px 6px; overflow-wrap: anywhere; }
        .formato th { background: #f9fafb; color: #1f2937; }
        .nota { font-size: 12px; line-height: 1.5; color: #6b7280; margin: 14px 0 0; }
        .back { display: block; text-align: center; margin-top: 20px; color: #047857; text-decoration: none; font-size: 14px; }
        @media(max-width: 440px) { .card { padding: 22px 16px; } .formato th, .formato td { font-size: 11px; padding: 6px 3px; } }
    </style>
</head>
<body>
<main class="card">
    <h1>Importar Buen Mes Reto</h1>
    <p class="sub">Carga el Excel con las cuentas y sus importes para agregar registros a la tabla <strong>buen_mes_reto</strong>.</p>
    <?php if ($mensaje !== ''): ?>
    <div class="<?= bmr_h($tipo) ?>" role="<?= $tipo === 'error' ? 'alert' : 'status' ?>">
        <strong><?= bmr_h($mensaje) ?></strong>
        <?php if ($resumen !== null): ?>
        <div class="resultado">
            <div><strong><?= (int) $resumen['importadas'] ?></strong>Importadas</div>
            <div><strong><?= (int) $resumen['vacias'] ?></strong>Filas vacías</div>
            <div><strong><?= (int) $resumen['invalidas'] ?></strong>Inválidas</div>
        </div>
        <?php endif; ?>
        <?php if ($detalles): ?>
        <ul class="detalles"><?php foreach ($detalles as $detalle): ?><li><?= bmr_h($detalle) ?></li><?php endforeach; ?></ul>
        <?php if ($resumen && $resumen['invalidas'] > count($detalles)): ?><p>Se muestran los primeros 20 errores.</p><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="formato">
        <strong>Formato requerido (primera hoja)</strong>
        <table><thead><tr><th>CUENTA</th><th>rentaSinImpuestos</th><th>PRECIO_BRM</th></tr></thead>
        <tbody><tr><td>143873896</td><td>619.6229361</td><td>124.7070639</td></tr></tbody></table>
    </div>
    <form method="post" enctype="multipart/form-data" id="form-importar">
        <input type="hidden" name="csrf" value="<?= bmr_h($csrf) ?>">
        <label class="zona-upload" id="zona" for="archivo">
            <strong>Selecciona tu archivo Excel (.xlsx)</strong>
            <input type="file" name="archivo" id="archivo" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <small>Hasta 20 MB · Encabezados en la primera fila</small>
        </label>
        <button type="submit" id="btn-importar">Importar datos</button>
    </form>
    <p class="nota">Las filas se agregan a la tabla; no se eliminan ni actualizan cuentas existentes. Los importes deben tener un máximo de siete decimales. Si una cuenta tiene ceros iniciales, guárdala como texto en Excel.</p>
    <a href="../dashboard.php" class="back">← Volver al Dashboard</a>
</main>
<script>
(function () {
    var input = document.getElementById('archivo');
    var zona = document.getElementById('zona');
    var form = document.getElementById('form-importar');
    var btn = document.getElementById('btn-importar');
    input.addEventListener('change', function () {
        zona.classList.toggle('seleccionado', input.files.length > 0);
    });
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.textContent = 'Importando datos…';
    });
})();
</script>
</body>
</html>
