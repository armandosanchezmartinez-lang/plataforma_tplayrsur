<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['username']) || !isset($_SESSION['rol'])) {
    header("Location: ../login.php");
    exit;
}
if (strtolower(trim($_SESSION['rol'])) !== 'admin') {
    http_response_code(403);
    echo "<h2>Acceso restringido</h2><p>Este módulo es exclusivo para perfil ADMIN.</p>";
    exit;
}
?>
