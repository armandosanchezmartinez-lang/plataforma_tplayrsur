<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario'])) {
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida']);
    exit();
}

include 'conexion.php';

$body = json_decode(file_get_contents('php://input'), true);
$pwd_actual = $body['actual'] ?? '';
$pwd_nueva  = $body['nueva']  ?? '';

if (strlen($pwd_nueva) < 8) {
    echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres']);
    exit();
}

$usuario = $_SESSION['usuario'];

// Obtener hash actual del usuario
$stmt = mysqli_prepare($conexion, "SELECT password FROM usuarios WHERE usuario = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $usuario);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado']);
    exit();
}

// Verificar contraseña actual (soporta password_hash y MD5 como fallback)
$hash_guardado = $row['password'];
$pwd_correcta  = false;

if (password_verify($pwd_actual, $hash_guardado)) {
    $pwd_correcta = true;
} elseif ($hash_guardado === md5($pwd_actual)) {
    // Fallback por si el sistema usaba MD5
    $pwd_correcta = true;
}

if (!$pwd_correcta) {
    echo json_encode(['ok' => false, 'error' => 'La contraseña actual es incorrecta']);
    exit();
}

// Actualizar con hash seguro
$nuevo_hash = password_hash($pwd_nueva, PASSWORD_BCRYPT);
$stmt2 = mysqli_prepare($conexion, "UPDATE usuarios SET password = ? WHERE usuario = ?");
mysqli_stmt_bind_param($stmt2, 'ss', $nuevo_hash, $usuario);
$actualizado = mysqli_stmt_execute($stmt2);
mysqli_stmt_close($stmt2);

if ($actualizado) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar la contraseña']);
}