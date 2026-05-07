<?php
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

ob_clean();
header('Content-Type: application/json; charset=utf-8');

function jsonOut($data) {
    ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['usuario'])) {
    jsonOut(['ok' => false, 'error' => 'Sesión no válida']);
}

include 'conexion.php';

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    jsonOut(['ok' => false, 'error' => 'Cuerpo de solicitud inválido']);
}

$pwd_actual = trim($body['actual'] ?? '');
$pwd_nueva  = trim($body['nueva']  ?? '');

if ($pwd_actual === '') {
    jsonOut(['ok' => false, 'error' => 'La contraseña actual es requerida']);
}
if (strlen($pwd_nueva) < 8) {
    jsonOut(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres']);
}

$usuario = $_SESSION['usuario'];

$stmt = mysqli_prepare($conexion, "SELECT password FROM usuarios WHERE usuario = ? LIMIT 1");
if (!$stmt) {
    jsonOut(['ok' => false, 'error' => 'Error de BD: ' . mysqli_error($conexion)]);
}
mysqli_stmt_bind_param($stmt, 's', $usuario);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
    jsonOut(['ok' => false, 'error' => 'Usuario no encontrado en la base de datos']);
}

$hash_guardado = $row['password'];
$pwd_correcta  = false;

if (password_verify($pwd_actual, $hash_guardado)) {
    $pwd_correcta = true;
} elseif ($hash_guardado === md5($pwd_actual)) {
    $pwd_correcta = true;
} elseif ($hash_guardado === $pwd_actual) {
    $pwd_correcta = true;
}

if (!$pwd_correcta) {
    jsonOut(['ok' => false, 'error' => 'La contraseña actual es incorrecta']);
}

$nuevo_hash = password_hash($pwd_nueva, PASSWORD_BCRYPT);
$stmt2 = mysqli_prepare($conexion, "UPDATE usuarios SET password = ? WHERE usuario = ?");
if (!$stmt2) {
    jsonOut(['ok' => false, 'error' => 'Error preparando UPDATE: ' . mysqli_error($conexion)]);
}
mysqli_stmt_bind_param($stmt2, 'ss', $nuevo_hash, $usuario);
$actualizado = mysqli_stmt_execute($stmt2);
$filas = mysqli_stmt_affected_rows($stmt2);
mysqli_stmt_close($stmt2);

if ($actualizado) {
    jsonOut(['ok' => true, 'filas' => $filas]);
} else {
    jsonOut(['ok' => false, 'error' => 'No se pudo actualizar: ' . mysqli_error($conexion)]);
}