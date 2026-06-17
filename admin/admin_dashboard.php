<?php
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
$total_usuarios=0; $usuarios_por_rol=[];
$res=$conn->query("SELECT COUNT(*) AS total FROM usuarios"); if($res&&$row=$res->fetch_assoc()){$total_usuarios=(int)$row['total'];}
$res=$conn->query("SELECT rol, COUNT(*) AS total FROM usuarios GROUP BY rol ORDER BY total DESC"); if($res){while($row=$res->fetch_assoc()){$usuarios_por_rol[]=$row;}}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>ADMIN TotalXpedient</title><link rel="stylesheet" href="../xpedient-v2.css"><style>body{font-family:Arial,sans-serif;padding:24px;background:#f5f7fa}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.card{background:#fff;padding:20px;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.08)}.metric{font-size:34px;font-weight:bold}a.btn{display:block;text-decoration:none;color:#fff;background:#111827;padding:12px;border-radius:10px;margin:8px 0;text-align:center}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}th{background:#111827;color:#fff}</style></head><body><h1>ADMIN TotalXpedient</h1><p>Módulo exclusivo para perfil ADMIN.</p><div class="grid"><div class="card"><h2>Total usuarios</h2><div class="metric"><?=$total_usuarios?></div></div><div class="card"><h2>Accesos rápidos</h2><a class="btn" href="admin_usuarios.php">A) Usuarios</a><a class="btn" href="admin_cambiar_password.php">B) Cambiar password</a><a class="btn" href="admin_reset_password.php">C) Reset password</a><a class="btn" href="admin_roles_permisos.php">D) Roles y permisos</a></div></div><div class="card" style="margin-top:20px;"><h2>Usuarios por rol</h2><table><thead><tr><th>Rol</th><th>Total</th></tr></thead><tbody><?php foreach($usuarios_por_rol as $r):?><tr><td><?=admin_role_label($r['rol'])?></td><td><?=(int)$r['total']?></td></tr><?php endforeach;?></tbody></table></div></body></html>
