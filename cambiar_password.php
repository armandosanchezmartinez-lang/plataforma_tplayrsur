<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
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

if ($pwd_actual === '') jsonOut(['ok' => false, 'error' => 'La contraseña actual es requerida']);
if (strlen($pwd_nueva) < 8) jsonOut(['ok' => false, 'error' => 'Mínimo 8 caracteres']);

// El valor guardado en sesión al hacer login
$usuario = $_SESSION['usuario'];

// Buscar por columna correcta: username
$stmt = mysqli_prepare($conexion, "SELECT password FROM usuarios WHERE username = ? LIMIT 1");
if (!$stmt) jsonOut(['ok' => false, 'error' => 'Error BD: ' . mysqli_error($conexion)]);
mysqli_stmt_bind_param($stmt, 's', $usuario);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
    jsonOut(['ok' => false, 'error' => "Usuario '$usuario' no encontrado"]);
}

// Verificar contraseña actual — soporta bcrypt, MD5, SHA1 y texto plano
$hash = $row['password'];
$ok   = false;
if (password_verify($pwd_actual, $hash))  $ok = true;
elseif ($hash === md5($pwd_actual))        $ok = true;
elseif ($hash === sha1($pwd_actual))       $ok = true;
elseif ($hash === $pwd_actual)             $ok = true;

if (!$ok) jsonOut(['ok' => false, 'error' => 'La contraseña actual es incorrecta']);

// Guardar nueva contraseña con bcrypt
$nuevo_hash = password_hash($pwd_nueva, PASSWORD_BCRYPT);
$stmt2 = mysqli_prepare($conexion, "UPDATE usuarios SET password = ? WHERE username = ?");
if (!$stmt2) jsonOut(['ok' => false, 'error' => 'Error UPDATE: ' . mysqli_error($conexion)]);
mysqli_stmt_bind_param($stmt2, 'ss', $nuevo_hash, $usuario);
mysqli_stmt_execute($stmt2);
mysqli_stmt_close($stmt2);

jsonOut(['ok' => true]);