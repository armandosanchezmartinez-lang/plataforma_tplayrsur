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
$mensaje=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') { $id=(int)($_POST['id']??0); $password=trim($_POST['password']??''); if($id<=0||strlen($password)<6){$error='Selecciona usuario y usa una contraseña mínima de 6 caracteres.';}else{$hash=password_hash($password,PASSWORD_DEFAULT);$stmt=$conn->prepare("UPDATE usuarios SET password=? WHERE id=?");$stmt->bind_param("si",$hash,$id);$mensaje=$stmt->execute()?'Contraseña actualizada correctamente.':'No se pudo actualizar la contraseña.';}}
$usuarios=$conn->query("SELECT id, username, rol, numero_talento_gs, id_posicion FROM usuarios ORDER BY username ASC");
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>ADMIN TotalXpedient | Cambiar Password</title><link rel="stylesheet" href="../xpedient-v2.css"><style>body{font-family:Arial,sans-serif;padding:24px;background:#f5f7fa}.card{background:#fff;padding:20px;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08)}input,select,button{padding:8px;border-radius:8px;border:1px solid #ccc}button{cursor:pointer;background:#111827;color:#fff;border:none}.ok{color:#047857;font-weight:bold}.error{color:#b91c1c;font-weight:bold}</style></head><body><h1>ADMIN TotalXpedient - Cambiar Password</h1><?php if($mensaje):?><p class="ok"><?=admin_escape($mensaje)?></p><?php endif;?><?php if($error):?><p class="error"><?=admin_escape($error)?></p><?php endif;?><div class="card"><form method="POST"><label>Usuario:</label><select name="id" required><option value="">Selecciona...</option><?php while($u=$usuarios->fetch_assoc()):?><option value="<?=(int)$u['id']?>"><?=admin_escape($u['username'])?> | <?=admin_role_label($u['rol'])?> | <?=admin_escape($u['id_posicion'])?></option><?php endwhile;?></select><label>Nueva contraseña:</label><input type="text" name="password" required minlength="6"><button type="submit">Cambiar password</button></form></div></body></html>
