<?php
require_once __DIR__ . '/admin_guard.php';

require_once __DIR__ . '/../conexion.php';

if (!isset($conn)) {
    if (isset($conexion)) {
        $conn = $conexion;
    } elseif (isset($mysqli)) {
        $conn = $mysqli;
    } elseif (isset($link)) {
        $conn = $link;
    } else {
        die("Error: no se encontró variable de conexión MySQL.");
    }
}

require_once __DIR__ . '/admin_helpers.php';
$mensaje = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'crear') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rol = strtolower(trim($_POST['rol'] ?? 'vendedor'));
        $numero_talento_gs = trim($_POST['numero_talento_gs'] ?? '');
        $id_posicion = trim($_POST['id_posicion'] ?? '');
        if ($username === '' || $password === '' || !in_array($rol, admin_allowed_roles(), true)) {
            $error = 'Completa usuario, contraseña y rol válido.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO usuarios (username, password, email, rol, numero_talento_gs, id_posicion) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $username, $hash, $email, $rol, $numero_talento_gs, $id_posicion);
            $mensaje = $stmt->execute() ? 'Usuario creado correctamente.' : 'No se pudo crear el usuario. Verifica si el username ya existe.';
        }
    }
    if ($accion === 'actualizar') {
        $id = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $rol = strtolower(trim($_POST['rol'] ?? 'vendedor'));
        $numero_talento_gs = trim($_POST['numero_talento_gs'] ?? '');
        $id_posicion = trim($_POST['id_posicion'] ?? '');
        if ($id <= 0 || !in_array($rol, admin_allowed_roles(), true)) {
            $error = 'Usuario o rol inválido.';
        } else {
            $stmt = $conn->prepare("UPDATE usuarios SET email = ?, rol = ?, numero_talento_gs = ?, id_posicion = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $email, $rol, $numero_talento_gs, $id_posicion, $id);
            $stmt->execute();
            $mensaje = 'Usuario actualizado correctamente.';
        }
    }
    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { $error = 'Usuario inválido.'; }
        else { $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?"); $stmt->bind_param("i", $id); $stmt->execute(); $mensaje = 'Usuario eliminado correctamente.'; }
    }
}
$result = $conn->query("SELECT id, username, email, created_at, rol, numero_talento_gs, id_posicion FROM usuarios ORDER BY id ASC");
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>ADMIN TotalXpedient | Usuarios</title><link rel="stylesheet" href="../xpedient-v2.css"><style>body{font-family:Arial,sans-serif;padding:24px;background:#f5f7fa}.card{background:#fff;padding:20px;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:20px;overflow-x:auto}table{width:100%;border-collapse:collapse;background:#fff;font-size:13px}th,td{padding:9px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:middle}th{background:#111827;color:#fff}input,select,button{padding:8px;border-radius:8px;border:1px solid #ccc;max-width:170px}button{cursor:pointer;background:#111827;color:#fff;border:none}.danger{background:#b91c1c}.ok{color:#047857;font-weight:bold}.error{color:#b91c1c;font-weight:bold}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}</style></head><body>
<h1>ADMIN TotalXpedient - Usuarios</h1>
<?php if ($mensaje): ?><p class="ok"><?= admin_escape($mensaje) ?></p><?php endif; ?><?php if ($error): ?><p class="error"><?= admin_escape($error) ?></p><?php endif; ?>
<div class="card"><h2>Crear usuario</h2><form method="POST" class="form-grid"><input type="hidden" name="accion" value="crear"><input type="text" name="username" placeholder="Username" required><input type="text" name="password" placeholder="Password temporal" required><input type="email" name="email" placeholder="Email"><input type="text" name="numero_talento_gs" placeholder="Número talento GS"><input type="text" name="id_posicion" placeholder="ID posición"><select name="rol" required><?php foreach (admin_allowed_roles() as $rol): ?><option value="<?= $rol ?>"><?= admin_role_label($rol) ?></option><?php endforeach; ?></select><button type="submit">Crear usuario</button></form></div>
<div class="card"><h2>Usuarios existentes</h2><table><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Rol</th><th>Talento GS</th><th>ID Posición</th><th>Creado</th><th>Guardar</th><th>Eliminar</th></tr></thead><tbody><?php while ($row = $result->fetch_assoc()): ?><tr><form method="POST"><input type="hidden" name="accion" value="actualizar"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><td><?= (int)$row['id'] ?></td><td><?= admin_escape($row['username']) ?></td><td><input type="email" name="email" value="<?= admin_escape($row['email']) ?>"></td><td><select name="rol"><?php foreach (admin_allowed_roles() as $rol): ?><option value="<?= $rol ?>" <?= strtolower((string)$row['rol']) === $rol ? 'selected' : '' ?>><?= admin_role_label($rol) ?></option><?php endforeach; ?></select></td><td><input type="text" name="numero_talento_gs" value="<?= admin_escape($row['numero_talento_gs']) ?>"></td><td><input type="text" name="id_posicion" value="<?= admin_escape($row['id_posicion']) ?>"></td><td><?= admin_escape($row['created_at']) ?></td><td><button type="submit">Guardar</button></td></form><td><form method="POST" onsubmit="return confirm('¿Eliminar este usuario?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="danger" type="submit">Eliminar</button></form></td></tr><?php endwhile; ?></tbody></table></div></body></html>
