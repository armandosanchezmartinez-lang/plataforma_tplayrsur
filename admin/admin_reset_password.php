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
$mensaje=''; $error=''; $temp_password='';
if ($_SERVER['REQUEST_METHOD']==='POST') { $id=(int)($_POST['id']??0); if($id<=0){$error='Selecciona un usuario válido.';}else{$temp_password=admin_generate_temp_password(10);$hash=password_hash($temp_password,PASSWORD_DEFAULT);$stmt=$conn->prepare("UPDATE usuarios SET password=? WHERE id=?");$stmt->bind_param("si",$hash,$id);$mensaje=$stmt->execute()?'Password temporal generado correctamente.':'No se pudo generar password temporal.';}}
$usuarios=$conn->query("SELECT id, username, rol, numero_talento_gs, id_posicion FROM usuarios ORDER BY username ASC");
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>ADMIN TotalXpedient | Reset Password</title><link rel="stylesheet" href="../xpedient-v2.css"><style>body{font-family:Arial,sans-serif;padding:24px;background:#f5f7fa}.card{background:#fff;padding:20px;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08)}input,select,button{padding:8px;border-radius:8px;border:1px solid #ccc}button{cursor:pointer;background:#111827;color:#fff;border:none}.ok{color:#047857;font-weight:bold}.error{color:#b91c1c;font-weight:bold}.temp{font-size:22px;font-weight:bold;background:#fef3c7;padding:12px;border-radius:10px;display:inline-block}</style></head><body><h1>ADMIN TotalXpedient - Reset Password</h1><?php if($mensaje):?><p class="ok"><?=admin_escape($mensaje)?></p><?php endif;?><?php if($error):?><p class="error"><?=admin_escape($error)?></p><?php endif;?><?php if($temp_password):?><p>Password temporal:</p><div class="temp"><?=admin_escape($temp_password)?></div><p><strong>Importante:</strong> copia este password ahora. No se volverá a mostrar.</p><?php endif;?><div class="card"><form method="POST"><label>Usuario:</label><select name="id" required><option value="">Selecciona...</option><?php while($u=$usuarios->fetch_assoc()):?><option value="<?=(int)$u['id']?>"><?=admin_escape($u['username'])?> | <?=admin_role_label($u['rol'])?> | <?=admin_escape($u['id_posicion'])?></option><?php endwhile;?></select><button type="submit">Generar password temporal</button></form></div></body></html>
