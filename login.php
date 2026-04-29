<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
include 'conexion.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = trim($_POST['usuario']);
    $pass = trim($_POST['password']);

    $stmt = mysqli_prepare($conexion, "SELECT id, password, rol, numero_talento_gs, id_posicion FROM usuarios WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $user);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $id, $hash, $rol, $talento_gs, $id_posicion);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if ($hash && password_verify($pass, $hash)) {
        session_start();
        $_SESSION['usuario']           = $user;
        $_SESSION['id']                = $id;
        $_SESSION['rol']               = $rol;
        $_SESSION['numero_talento_gs'] = $talento_gs;
        $_SESSION['id_posicion']       = $id_posicion;
        header("Location: index.php");
        exit();
    } else {
        $error = "Número de empleado o contraseña incorrectos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOTALXPEDIENT - Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; height: 100vh; overflow: hidden; }
        .login-section { flex: 1; background-color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 40px; }
        .brand-section { flex: 1; background-color: #2b57a7; display: flex; justify-content: center; align-items: center; }
        .form-container { width: 100%; max-width: 400px; }
        .logo-header { display: flex; align-items: center; margin-bottom: 50px; }
        .logo-header img { height: 60px; margin-right: 15px; }
        .logo-header h1 { color: #2b57a7; font-size: 2.2rem; letter-spacing: 1px; font-weight: 700; }
        .brand-section img { width: 70%; max-width: 450px; }
        label { display: block; margin-bottom: 15px; font-size: 0.9rem; color: #4a66a0; font-weight: 600; }
        input { width: 100%; padding: 15px; margin-bottom: 25px; border: 1px solid #dce0e9; border-radius: 8px; font-size: 1rem; background-color: #fcfcfc; outline: none; }
        button { width: 100%; padding: 15px; background-color: #3b66b8; color: white; border: none; border-radius: 8px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: background 0.3s; margin-top: 10px; }
        button:hover { background-color: #2b57a7; }
        .error { color: #d93025; margin-bottom: 20px; font-size: 0.9rem; text-align: left; font-weight: 600; }
        @media (max-width: 768px) { .brand-section { display: none; } }
    </style>
</head>
<body>
<div class="login-section">
    <div class="form-container">
        <div class="logo-header">
            <img src="logo_carpeta.png" alt="Logo">
            <img src="logotipo_xpedient.png" alt="logotipo_xpedient">
            <!-- <h1>TOTALXPEDIENT</h1>L -->
            
        </div>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <label for="usuario">Número de Empleado</label>
            <input type="text" id="usuario" name="usuario" required>
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required>
            <button type="submit">Ingresar</button>
        </form>
    </div>
</div>
<div class="brand-section">
    <img src="totalplay_blanco.png" alt="totalplay_Logo">
</div>
</body>
</html>