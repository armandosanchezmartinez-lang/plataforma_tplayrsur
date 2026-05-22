<?php
require 'vendor/autoload.php';
require 'conexion.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_FILES['archivo'])) {
    die("No se recibió archivo.");
}

$archivoTmp = $_FILES['archivo']['tmp_name'];
$nombreArchivo = $_FILES['archivo']['name'];

$spreadsheet = IOFactory::load($archivoTmp);
$sheet = $spreadsheet->getSheetByName('Ins. semanas');

if (!$sheet) {
    die("No existe la hoja 'Ins. semanas'.");
}

$highestRow = $sheet->getHighestRow();

$insertados = 0;
$actualizados = 0;
$errores = [];

$sql = "
INSERT INTO metas_instalacion_semanal
(anio, semana, distrito, meta, archivo_origen, usuario_carga)
VALUES
(:anio, :semana, :distrito, :meta, :archivo_origen, :usuario_carga)
ON DUPLICATE KEY UPDATE
    meta = VALUES(meta),
    archivo_origen = VALUES(archivo_origen),
    usuario_carga = VALUES(usuario_carga),
    fecha_carga = CURRENT_TIMESTAMP
";

$stmt = $pdo->prepare($sql);

for ($row = 2; $row <= $highestRow; $row++) {

    $plaza = trim((string)$sheet->getCell("A$row")->getValue());
    $meta = $sheet->getCell("B$row")->getValue();
    $distrito = trim((string)$sheet->getCell("C$row")->getValue());
    $anio = $sheet->getCell("D$row")->getValue();

    if ($plaza === '' || $distrito === '' || $anio === '') {
        continue;
    }

    preg_match('/\d+/', $plaza, $match);
    $semana = isset($match[0]) ? intval($match[0]) : null;

    if (!$semana) {
        $errores[] = "Fila $row: no se pudo identificar la semana.";
        continue;
    }

    $meta = intval($meta);
    $anio = intval($anio);

    try {
        $stmt->execute([
            ':anio' => $anio,
            ':semana' => $semana,
            ':distrito' => $distrito,
            ':meta' => $meta,
            ':archivo_origen' => $nombreArchivo,
            ':usuario_carga' => $_SESSION['usuario'] ?? 'sistema'
        ]);

        if ($stmt->rowCount() === 1) {
            $insertados++;
        } else {
            $actualizados++;
        }

    } catch (Exception $e) {
        $errores[] = "Fila $row: " . $e->getMessage();
    }
}

echo "Importación terminada.<br>";
echo "Insertados: $insertados<br>";
echo "Actualizados: $actualizados<br>";

if (!empty($errores)) {
    echo "<hr><b>Errores:</b><br>";
    foreach ($errores as $error) {
        echo $error . "<br>";
    }
}
?>