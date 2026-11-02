<?php
/**
 * ARCHIVO NUEVO: ubicar en /plataforma/conexionTalia.php
 * Nunca publicar las credenciales en el repositorio o dentro del ZIP de la app.
 * Sustituye las variables de entorno o configura las credenciales de WordPress.
 */
$host = getenv('TALIA_WP_DB_HOST') ?: 'localhost';
$user = getenv('TALIA_WP_DB_USER') ?: 'u873376862_2JDrd';
$pass = getenv('TALIA_WP_DB_PASS') ?: 'IeDJm8xmga';
$name = getenv('TALIA_WP_DB_NAME') ?: 'u873376862_Z2Gka';
if ($user === '' || $name === '') {
    throw new RuntimeException('Configura TALIA_WP_DB_USER y TALIA_WP_DB_NAME en conexionTalia.php.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion = new mysqli($host,$user,$pass,$name);
$conexion->set_charset('utf8mb4');

// Prefijo real de tablas en WordPress, por ejemplo: wp_, sitio_, etc.
$taliaWpPrefix = getenv('TALIA_WP_TABLE_PREFIX') ?: 'wp_';

// OPCIONAL: esquema SQL en el MISMO servidor MySQL con tablas de
// jerarquía y catálogo: hc, catalogo_paquetes y catálogo de metas.
// La cuenta MySQL debe tener permisos SELECT sobre ese esquema.
// Si están en otro servidor, crea vistas/sincronización autorizada:
// un nombre de esquema no permite acceder a un host distinto.
$taliaReferenceSchema = getenv('TALIA_HC_DB_NAME') ?: '';
