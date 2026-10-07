<?php
/**
 * Certificación TalIA Hierarchy v1.0
 * Ejecutar desde navegador autenticado o CLI adaptando conexion.php.
 * No escribe datos. Devuelve PASS/FAIL para reglas estructurales mínimas.
 */
require_once __DIR__ . '/../../../conexion.php';
require_once __DIR__ . '/../lib/TaliaHierarchyResolver.php';
$config = require __DIR__ . '/../config/talia_hierarchy.php';
$resolver = new TaliaHierarchyResolver($conexion, $config);

header('Content-Type: text/plain; charset=UTF-8');
$checks = [];
$checks[] = ['Resolver version', $resolver->version() === '1.0.0'];

// Caso canónico: plaza líder 1739397 debe existir en catálogo y pertenecer a Mercy.
$mercy = array_values(array_filter($config['leaders'], fn($r) => ($r['lider_pos'] ?? null) === '1739397'));
$checks[] = ['Mercy/Jovany estructura 1739397', count($mercy) === 1 && $mercy[0]['lider_hc'] === 'ESTRADA MEDINA MERCY GUADALUPE' && $mercy[0]['lider_instalaciones'] === 'JOVANY DAMIAN PARAMO AVILA'];

// HIC no debe contener un enlace explícito Jovany <-> Mercy por nombre.
$sql = "SELECT COUNT(*) c FROM historial_identidad_colaborador WHERE UPPER(COALESCE(nombre_colaborador,'')) LIKE '%JOVANY%' AND UPPER(COALESCE(nombre_colaborador,'')) LIKE '%MERCY%'";
$r = mysqli_query($conexion, $sql);
$row = $r ? mysqli_fetch_assoc($r) : ['c'=>-1];
$checks[] = ['HIC no mezcla Jovany/Mercy', (int)$row['c'] === 0];

// Duplicados exactos de cuenta+fecha son señal de riesgo para invariantes.
$sql = "SELECT COUNT(*) c FROM (SELECT cuenta,fecha,COUNT(*) n FROM instalaciones WHERE cuenta IS NOT NULL AND cuenta<>'' GROUP BY cuenta,fecha HAVING COUNT(*)>1) x";
$r = mysqli_query($conexion, $sql);
$row = $r ? mysqli_fetch_assoc($r) : ['c'=>-1];
$checks[] = ['Duplicados cuenta+fecha instalaciones (informativo; PASS si 0)', (int)$row['c'] === 0];

$failed = 0;
foreach ($checks as [$name,$ok]) {
    echo ($ok ? 'PASS' : 'FAIL') . " | {$name}\n";
    if (!$ok) $failed++;
}
echo "\nResolver: ".$resolver->version()."\n";
echo "Resultado: ".($failed ? "FAIL ({$failed})" : 'PASS')."\n";
exit($failed ? 1 : 0);
