<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rolSesion = $_SESSION['rol']
    ?? $_SESSION['user_rol']
    ?? $_SESSION['usuario_rol']
    ?? $_SESSION['perfil']
    ?? $_SESSION['role']
    ?? '';

$rolSesion = strtolower(trim($rolSesion));

if ($rolSesion !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>
