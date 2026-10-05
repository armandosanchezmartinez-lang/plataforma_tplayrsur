<?php
declare(strict_types=1);

/**
 * TalIA Connect WA - Conversaciones
 * Versión 1.2 - Normalización MX + timestamps locales consistentes
 *
 * Ruta:
 * /public_html/plataforma/talia-whatsapp/conversaciones.php
 *
 * Funciones:
 * - Lista conversaciones reales guardadas por webhook.php
 * - Muestra historial de wa_mensajes
 * - Permite responder por WhatsApp Cloud API cuando existe access token
 * - Registra mensajes SALIENTES en wa_mensajes
 * - El webhook actualiza posteriormente SENT / DELIVERED / READ / FAILED
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('America/Merida');

$rutaConexion = dirname(__DIR__) . '/conexion.php';
if (!is_file($rutaConexion)) {
    http_response_code(500);
    exit('No se encontró el archivo de conexión.');
}

$conexion = null;
require $rutaConexion;

if (!($conexion instanceof mysqli)) {
    http_response_code(500);
    exit('No fue posible iniciar la conexión con la base de datos.');
}
mysqli_set_charset($conexion, 'utf8mb4');

/* =========================================================
 * Helpers
 * ========================================================= */

function h(mixed $valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function tablaExiste(mysqli $conexion, string $tabla): bool {
    $sql = "SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?";
    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 's', $tabla);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    return (int)$total > 0;
}

function guardarLogLocal(string $archivo, string $contenido): void {
    $dir = __DIR__ . '/logs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $bloque  = "============================================================\n";
    $bloque .= "Fecha servidor: " . date('Y-m-d H:i:s') . "\n";
    $bloque .= $contenido . "\n\n";

    @file_put_contents(
        $dir . '/' . $archivo,
        $bloque,
        FILE_APPEND | LOCK_EX
    );
}

function obtenerNumero(mysqli $conexion, int $idNumero): ?array {
    $sql = "SELECT id, numero_talento, id_posicion, nombre_vendedor, distrito,
                   telefono, display_phone_number, phone_number_id, waba_id,
                   bot_activo, modo_respuesta, espera_segundos,
                   horario_inicio, horario_fin, timezone, estado
            FROM wa_numeros
            WHERE id = ?
            LIMIT 1";

    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $idNumero);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($res) ?: null;

    mysqli_stmt_close($stmt);
    return $fila;
}

function obtenerNumeroUsuario(
    mysqli $conexion,
    string $talento,
    string $idPosicion
): ?array {
    $sql = "SELECT id, numero_talento, id_posicion, nombre_vendedor, distrito,
                   telefono, display_phone_number, phone_number_id, waba_id,
                   bot_activo, modo_respuesta, espera_segundos,
                   horario_inicio, horario_fin, timezone, estado
            FROM wa_numeros
            WHERE
                (
                    numero_talento IS NOT NULL
                    AND numero_talento <> ''
                    AND numero_talento = ?
                )
                OR
                (
                    id_posicion IS NOT NULL
                    AND id_posicion <> ''
                    AND id_posicion = ?
                )
            ORDER BY estado = 'ACTIVO' DESC, id DESC
            LIMIT 1";

    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $talento, $idPosicion);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($res) ?: null;

    mysqli_stmt_close($stmt);
    return $fila;
}

function obtenerConversacion(
    mysqli $conexion,
    int $idConversacion,
    int $idNumero
): ?array {
    $sql = "SELECT id, id_numero, wa_id_cliente, telefono_cliente,
                   nombre_cliente, estado, modo_atencion, bot_pausado_hasta,
                   tomada_por_numero_talento, fecha_inicio,
                   ultima_actividad, fecha_cierre
            FROM wa_conversaciones
            WHERE id = ?
              AND id_numero = ?
            LIMIT 1";

    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $idConversacion, $idNumero);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $fila = mysqli_fetch_assoc($res) ?: null;

    mysqli_stmt_close($stmt);
    return $fila;
}

function obtenerAccessTokenWhatsApp(): string {
    /*
     * Opción 1: variable de entorno del servidor.
     * TALIA_WA_ACCESS_TOKEN
     */
    $env = getenv('TALIA_WA_ACCESS_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    /*
     * Opción 2: archivo privado NO versionado:
     * /public_html/plataforma/talia_whatsapp_secrets.php
     *
     * Contenido esperado:
     * <?php
     * return [
     *   'access_token' => 'TOKEN...',
     *   'graph_version' => 'v26.0'
     * ];
     */
    $rutaSecreto = dirname(__DIR__) . '/talia_whatsapp_secrets.php';

    if (is_file($rutaSecreto)) {
        $cfg = require $rutaSecreto;

        if (
            is_array($cfg)
            && isset($cfg['access_token'])
            && is_string($cfg['access_token'])
            && trim($cfg['access_token']) !== ''
        ) {
            return trim($cfg['access_token']);
        }
    }

    return '';
}

function obtenerGraphVersionWhatsApp(): string {
    $env = getenv('TALIA_WA_GRAPH_VERSION');

    if (is_string($env) && preg_match('/^v\d+\.\d+$/', trim($env))) {
        return trim($env);
    }

    $rutaSecreto = dirname(__DIR__) . '/talia_whatsapp_secrets.php';

    if (is_file($rutaSecreto)) {
        $cfg = require $rutaSecreto;

        if (
            is_array($cfg)
            && isset($cfg['graph_version'])
            && is_string($cfg['graph_version'])
            && preg_match('/^v\d+\.\d+$/', trim($cfg['graph_version']))
        ) {
            return trim($cfg['graph_version']);
        }
    }

    return 'v26.0';
}

/**
 * Normaliza el número usado únicamente como destinatario SALIENTE hacia Meta.
 *
 * Importante:
 * - NO modifica wa_conversaciones.wa_id_cliente.
 * - NO modifica mensajes entrantes.
 * - Conserva el WA ID original en la conversación.
 *
 * Caso México observado:
 *   WA ID entrante: 5219993463042
 *   Destino autorizado por Meta: 529993463042
 *
 * Para un identificador mexicano con patrón 521 + 10 dígitos,
 * se elimina únicamente el "1" histórico posterior al código 52.
 */
function normalizarDestinatarioMeta(string $numero): string {
    $numero = preg_replace('/\D+/', '', $numero) ?? '';

    if (preg_match('/^521(\d{10})$/', $numero, $m)) {
        return '52' . $m[1];
    }

    return $numero;
}

function enviarTextoWhatsApp(
    string $phoneNumberId,
    string $destinatario,
    string $mensaje,
    string $accessToken,
    string $graphVersion
): array {
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'http_code' => 0,
            'message_id' => '',
            'error' => 'La extensión cURL no está disponible en PHP.',
            'raw' => ''
        ];
    }

    $url = 'https://graph.facebook.com/'
         . rawurlencode($graphVersion)
         . '/'
         . rawurlencode($phoneNumberId)
         . '/messages';

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $destinatario,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $mensaje
        ]
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'message_id' => '',
            'error' => 'No fue posible construir el payload JSON.',
            'raw' => ''
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $json
    ]);

    $respuesta = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($respuesta === false) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'message_id' => '',
            'error' => $curlError !== '' ? $curlError : 'Error desconocido de cURL.',
            'raw' => ''
        ];
    }

    $data = json_decode($respuesta, true);

    $messageId = '';
    if (
        is_array($data)
        && isset($data['messages'][0]['id'])
        && is_string($data['messages'][0]['id'])
    ) {
        $messageId = $data['messages'][0]['id'];
    }

    if ($httpCode >= 200 && $httpCode < 300 && $messageId !== '') {
        return [
            'ok' => true,
            'http_code' => $httpCode,
            'message_id' => $messageId,
            'error' => '',
            'raw' => $respuesta
        ];
    }

    $error = 'Meta rechazó la solicitud.';

    if (
        is_array($data)
        && isset($data['error']['message'])
        && is_string($data['error']['message'])
    ) {
        $error = $data['error']['message'];
    }

    return [
        'ok' => false,
        'http_code' => $httpCode,
        'message_id' => '',
        'error' => $error,
        'raw' => $respuesta
    ];
}

function registrarMensajeSaliente(
    mysqli $conexion,
    int $idConversacion,
    int $idNumero,
    string $messageId,
    string $waFrom,
    string $waTo,
    string $contenido
): int {
    /*
     * Usamos la hora generada por PHP en America/Merida y no NOW() de MySQL.
     * El servidor de base puede operar en UTC; si usamos NOW(), la lista de
     * conversaciones puede quedar desplazada respecto al timestamp de Meta.
     */
    $ahoraLocal = date('Y-m-d H:i:s');

    $sql = "INSERT INTO wa_mensajes (
                id_conversacion,
                id_numero,
                message_id,
                direccion,
                wa_from,
                wa_to,
                tipo,
                contenido,
                estado_envio,
                fecha_mensaje,
                fecha_recibido_webhook,
                payload_json
            ) VALUES (
                ?,
                ?,
                ?,
                'SALIENTE',
                ?,
                ?,
                'text',
                ?,
                'QUEUED',
                ?,
                ?,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                message_id = VALUES(message_id)";

    $stmt = mysqli_prepare($conexion, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        'iissssss',
        $idConversacion,
        $idNumero,
        $messageId,
        $waFrom,
        $waTo,
        $contenido,
        $ahoraLocal,
        $ahoraLocal
    );

    mysqli_stmt_execute($stmt);

    $insertId = (int)mysqli_insert_id($conexion);
    mysqli_stmt_close($stmt);

    $sqlConv = "UPDATE wa_conversaciones
                SET ultima_actividad = ?
                WHERE id = ?";

    $stmt = mysqli_prepare($conexion, $sqlConv);
    mysqli_stmt_bind_param($stmt, 'si', $ahoraLocal, $idConversacion);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $insertId;
}

function formatearHora(?string $fecha): string {
    if (!$fecha) {
        return '';
    }

    $ts = strtotime($fecha);
    return $ts ? date('H:i', $ts) : '';
}

function formatearFechaLista(?string $fecha): string {
    if (!$fecha) {
        return '';
    }

    $ts = strtotime($fecha);
    if (!$ts) {
        return '';
    }

    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('H:i', $ts);
    }

    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ayer';
    }

    return date('d/m/Y', $ts);
}

function etiquetaEstado(?string $estado): string {
    $estado = strtoupper(trim((string)$estado));

    return match ($estado) {
        'QUEUED' => 'En cola',
        'SENT' => 'Enviado',
        'DELIVERED' => 'Entregado',
        'READ' => 'Leído',
        'FAILED' => 'Falló',
        'RECEIVED' => 'Recibido',
        default => $estado !== '' ? $estado : ''
    };
}

/* =========================================================
 * Sesión / alcance
 * ========================================================= */

$rol = (string)($_SESSION['rol'] ?? 'vendedor');
$talento = (string)($_SESSION['numero_talento_gs'] ?? '');
$idPosicion = (string)($_SESSION['id_posicion'] ?? '');
$usuario = (string)($_SESSION['usuario'] ?? '');

$rolesAdministrativos = ['admin', 'director_regional'];
$esAdmin = in_array($rol, $rolesAdministrativos, true);

if (empty($_SESSION['talia_wa_conv_csrf'])) {
    $_SESSION['talia_wa_conv_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['talia_wa_conv_csrf'];

if (
    !tablaExiste($conexion, 'wa_numeros')
    || !tablaExiste($conexion, 'wa_conversaciones')
    || !tablaExiste($conexion, 'wa_mensajes')
) {
    http_response_code(500);
    exit('Faltan tablas requeridas de TalIA Connect WA.');
}

$numerosDisponibles = [];
$idNumeroSeleccionado = 0;
$numero = null;

if ($esAdmin) {
    $rs = mysqli_query(
        $conexion,
        "SELECT id, nombre_vendedor, display_phone_number, telefono, estado
         FROM wa_numeros
         ORDER BY estado = 'ACTIVO' DESC, nombre_vendedor, id"
    );

    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $numerosDisponibles[] = $row;
        }
    }

    $idNumeroSeleccionado = (int)(
        $_POST['id_numero']
        ?? $_GET['id_numero']
        ?? 0
    );

    if ($idNumeroSeleccionado <= 0 && !empty($numerosDisponibles)) {
        $idNumeroSeleccionado = (int)$numerosDisponibles[0]['id'];
    }

    if ($idNumeroSeleccionado > 0) {
        $numero = obtenerNumero($conexion, $idNumeroSeleccionado);
    }
} else {
    $numero = obtenerNumeroUsuario($conexion, $talento, $idPosicion);

    if ($numero) {
        $idNumeroSeleccionado = (int)$numero['id'];
    }
}

$idConversacionSeleccionada = (int)(
    $_POST['id_conversacion']
    ?? $_GET['c']
    ?? 0
);

$mensajeUi = '';
$tipoMensajeUi = '';

$accessToken = obtenerAccessTokenWhatsApp();
$graphVersion = obtenerGraphVersionWhatsApp();
$envioDisponible = $accessToken !== '';

/* =========================================================
 * Envío
 * ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['enviar_mensaje'])
) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $mensajeUi = 'La sesión de seguridad expiró. Recarga la página.';
        $tipoMensajeUi = 'error';
    } elseif (!$numero || $idNumeroSeleccionado <= 0) {
        $mensajeUi = 'No hay un número de WhatsApp válido seleccionado.';
        $tipoMensajeUi = 'error';
    } elseif (!$envioDisponible) {
        $mensajeUi = 'El envío real está pendiente de configurar el access token de WhatsApp Cloud API.';
        $tipoMensajeUi = 'warning';
    } else {
        $conversacion = obtenerConversacion(
            $conexion,
            $idConversacionSeleccionada,
            $idNumeroSeleccionado
        );

        $texto = trim((string)($_POST['mensaje'] ?? ''));

        if (!$conversacion) {
            $mensajeUi = 'La conversación seleccionada no pertenece al número administrado.';
            $tipoMensajeUi = 'error';
        } elseif ($texto === '') {
            $mensajeUi = 'Escribe un mensaje antes de enviarlo.';
            $tipoMensajeUi = 'error';
        } elseif (mb_strlen($texto) > 4096) {
            $mensajeUi = 'El mensaje excede el máximo permitido de 4,096 caracteres.';
            $tipoMensajeUi = 'error';
        } else {
            $waIdClienteOriginal = preg_replace(
                '/\D+/',
                '',
                (string)$conversacion['wa_id_cliente']
            ) ?? '';

            // Conservamos el WA ID de la conversación y normalizamos
            // únicamente el destinatario que se envía a Meta.
            $destinatario = normalizarDestinatarioMeta($waIdClienteOriginal);

            $phoneNumberId = trim((string)($numero['phone_number_id'] ?? ''));

            if ($destinatario === '' || $phoneNumberId === '') {
                $mensajeUi = 'Falta el identificador del destinatario o del número de WhatsApp.';
                $tipoMensajeUi = 'error';
            } else {
                $resultado = enviarTextoWhatsApp(
                    $phoneNumberId,
                    $destinatario,
                    $texto,
                    $accessToken,
                    $graphVersion
                );

                if ($resultado['ok']) {
                    try {
                        registrarMensajeSaliente(
                            $conexion,
                            $idConversacionSeleccionada,
                            $idNumeroSeleccionado,
                            (string)$resultado['message_id'],
                            (string)($numero['telefono'] ?? ''),
                            $destinatario,
                            $texto
                        );

                        guardarLogLocal(
                            'send_events.log',
                            "MENSAJE_SALIENTE_ACEPTADO\n"
                            . "id_numero=" . $idNumeroSeleccionado . "\n"
                            . "id_conversacion=" . $idConversacionSeleccionada . "\n"
                            . "message_id=" . $resultado['message_id'] . "\n"
                            . "wa_id_original=" . $waIdClienteOriginal . "\n"
                            . "to_meta=" . $destinatario . "\n"
                            . "http_code=" . $resultado['http_code']
                        );

                        $mensajeUi = 'Mensaje enviado a WhatsApp correctamente.';
                        $tipoMensajeUi = 'ok';

                        header(
                            'Location: conversaciones.php?id_numero='
                            . $idNumeroSeleccionado
                            . '&c='
                            . $idConversacionSeleccionada
                            . '&sent=1'
                        );
                        exit;
                    } catch (Throwable $e) {
                        guardarLogLocal(
                            'send_errors.log',
                            "MENSAJE_ENVIADO_PERO_NO_REGISTRADO\n"
                            . "id_numero=" . $idNumeroSeleccionado . "\n"
                            . "id_conversacion=" . $idConversacionSeleccionada . "\n"
                            . "message_id=" . $resultado['message_id'] . "\n"
                            . "error=" . $e->getMessage()
                        );

                        $mensajeUi = 'WhatsApp aceptó el mensaje, pero no fue posible registrarlo en MySQL.';
                        $tipoMensajeUi = 'warning';
                    }
                } else {
                    guardarLogLocal(
                        'send_errors.log',
                        "META_SEND_ERROR\n"
                        . "id_numero=" . $idNumeroSeleccionado . "\n"
                        . "id_conversacion=" . $idConversacionSeleccionada . "\n"
                        . "wa_id_original=" . $waIdClienteOriginal . "\n"
                        . "to_meta=" . $destinatario . "\n"
                        . "http_code=" . $resultado['http_code'] . "\n"
                        . "error=" . $resultado['error']
                    );

                    $mensajeUi = 'Meta no aceptó el mensaje: ' . $resultado['error'];
                    $tipoMensajeUi = 'error';
                }
            }
        }
    }
}

if (isset($_GET['sent']) && $_GET['sent'] === '1') {
    $mensajeUi = 'Mensaje enviado a WhatsApp correctamente.';
    $tipoMensajeUi = 'ok';
}

/* =========================================================
 * Conversaciones
 * ========================================================= */

$conversaciones = [];

if ($numero && $idNumeroSeleccionado > 0) {
    $sql = "SELECT
                c.id,
                c.wa_id_cliente,
                c.telefono_cliente,
                c.nombre_cliente,
                c.estado,
                c.modo_atencion,
                c.ultima_actividad,
                (
                    SELECT m.contenido
                    FROM wa_mensajes m
                    WHERE m.id_conversacion = c.id
                    ORDER BY
                        COALESCE(m.fecha_mensaje, m.fecha_recibido_webhook) DESC,
                        m.id DESC
                    LIMIT 1
                ) AS ultimo_mensaje,
                (
                    SELECT m.direccion
                    FROM wa_mensajes m
                    WHERE m.id_conversacion = c.id
                    ORDER BY
                        COALESCE(m.fecha_mensaje, m.fecha_recibido_webhook) DESC,
                        m.id DESC
                    LIMIT 1
                ) AS ultima_direccion
            FROM wa_conversaciones c
            WHERE c.id_numero = ?
            ORDER BY c.ultima_actividad DESC, c.id DESC
            LIMIT 100";

    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $idNumeroSeleccionado);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $conversaciones[] = $row;
    }

    mysqli_stmt_close($stmt);
}

if ($idConversacionSeleccionada <= 0 && !empty($conversaciones)) {
    $idConversacionSeleccionada = (int)$conversaciones[0]['id'];
}

$conversacionActual = null;
$mensajes = [];

if (
    $numero
    && $idConversacionSeleccionada > 0
) {
    $conversacionActual = obtenerConversacion(
        $conexion,
        $idConversacionSeleccionada,
        $idNumeroSeleccionado
    );

    if ($conversacionActual) {
        $sql = "SELECT
                    id,
                    message_id,
                    direccion,
                    wa_from,
                    wa_to,
                    tipo,
                    contenido,
                    estado_envio,
                    fecha_mensaje,
                    fecha_recibido_webhook,
                    fecha_enviado,
                    fecha_entregado,
                    fecha_leido,
                    error_code,
                    error_message
                FROM wa_mensajes
                WHERE id_conversacion = ?
                ORDER BY
                    COALESCE(fecha_mensaje, fecha_recibido_webhook) ASC,
                    id ASC
                LIMIT 500";

        $stmt = mysqli_prepare($conexion, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            'i',
            $idConversacionSeleccionada
        );
        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($res)) {
            $mensajes[] = $row;
        }

        mysqli_stmt_close($stmt);
    }
}

$displayNumero = $numero['display_phone_number']
    ?? ($numero['telefono'] ?? 'Sin número vinculado');

$estadoNumero = strtoupper((string)($numero['estado'] ?? 'SIN VINCULAR'));

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conversaciones | TalIA Connect WA</title>

<style>
:root{
    --bg:#f4f7fb;
    --card:#ffffff;
    --ink:#142033;
    --muted:#6d7888;
    --brand:#6c3df0;
    --brand2:#875df5;
    --green:#0f8f73;
    --green-dark:#08695a;
    --green-soft:#eaf8f2;
    --line:#e5eaf1;
    --danger:#b42318;
    --warning:#b54708;
    --shadow:0 14px 36px rgba(20,32,51,.08);
}

*{box-sizing:border-box}

html,body{
    margin:0;
    min-height:100%;
    font-family:Inter,Segoe UI,Arial,sans-serif;
    background:var(--bg);
    color:var(--ink);
}

body{
    overflow-x:hidden;
}

a{
    color:inherit;
}

.shell{
    min-height:100vh;
    display:grid;
    grid-template-columns:250px 1fr;
}

.sidebar{
    position:sticky;
    top:0;
    height:100vh;
    background:#111827;
    color:#fff;
    padding:24px 18px;
    display:flex;
    flex-direction:column;
    gap:24px;
}

.brand{
    display:flex;
    gap:12px;
    align-items:center;
}

.logo{
    width:44px;
    height:44px;
    border-radius:14px;
    background:linear-gradient(135deg,var(--brand),#9b74ff);
    display:grid;
    place-items:center;
    font-weight:800;
    font-size:20px;
}

.brand h1{
    font-size:17px;
    margin:0;
}

.brand small{
    color:#aeb8c8;
}

.nav{
    display:grid;
    gap:8px;
}

.nav a{
    padding:12px 14px;
    border-radius:12px;
    text-decoration:none;
    color:#cbd5e1;
}

.nav a.active,
.nav a:hover{
    background:#202a3a;
    color:#fff;
}

.sidebar-foot{
    margin-top:auto;
    color:#94a3b8;
    font-size:12px;
    line-height:1.5;
}

.main{
    min-width:0;
    padding:26px 30px 34px;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    margin-bottom:18px;
}

.topbar h2{
    margin:0 0 6px;
    font-size:28px;
}

.topbar p{
    margin:0;
    color:var(--muted);
}

.user-chip{
    background:#fff;
    border:1px solid var(--line);
    padding:10px 14px;
    border-radius:14px;
    font-size:13px;
}

.admin-select{
    background:#fff;
    border:1px solid var(--line);
    border-radius:18px;
    padding:16px 18px;
    margin-bottom:18px;
    box-shadow:var(--shadow);
}

.field label{
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:7px;
}

select,
textarea{
    width:100%;
    border:1px solid #d7deea;
    border-radius:12px;
    padding:11px 12px;
    font:inherit;
    background:#fff;
    color:var(--ink);
}

.alert{
    padding:13px 15px;
    border-radius:12px;
    margin-bottom:16px;
    font-size:14px;
}

.alert.ok{
    background:#ecfdf3;
    color:#067647;
}

.alert.error{
    background:#fef3f2;
    color:var(--danger);
}

.alert.warning{
    background:#fffaeb;
    color:var(--warning);
}

.workspace{
    min-height:650px;
    height:calc(100vh - 165px);
    display:grid;
    grid-template-columns:minmax(280px,340px) minmax(0,1fr);
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    box-shadow:var(--shadow);
    overflow:hidden;
}

.conversation-list{
    border-right:1px solid var(--line);
    min-width:0;
    display:flex;
    flex-direction:column;
}

.list-head{
    padding:18px 18px 14px;
    border-bottom:1px solid var(--line);
}

.list-head h3{
    margin:0 0 5px;
    font-size:18px;
}

.list-head .sub{
    color:var(--muted);
    font-size:12px;
}

.list-scroll{
    overflow-y:auto;
    flex:1;
}

.conv{
    display:block;
    text-decoration:none;
    padding:15px 17px;
    border-bottom:1px solid #f0f2f5;
    background:#fff;
}

.conv:hover{
    background:#f8fafc;
}

.conv.active{
    background:#f3efff;
    border-left:4px solid var(--brand);
    padding-left:13px;
}

.conv-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.conv-name{
    font-size:14px;
    font-weight:800;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.conv-time{
    color:var(--muted);
    font-size:11px;
    white-space:nowrap;
}

.conv-preview{
    margin-top:5px;
    color:var(--muted);
    font-size:12px;
    line-height:1.35;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.conv-meta{
    margin-top:8px;
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.badge{
    display:inline-flex;
    align-items:center;
    padding:5px 8px;
    border-radius:999px;
    background:#eef2ff;
    color:#4f46e5;
    font-size:10px;
    font-weight:800;
}

.badge.humano{
    background:#eef2ff;
    color:#4f46e5;
}

.badge.bot{
    background:#ecfdf3;
    color:#067647;
}

.chat{
    min-width:0;
    display:flex;
    flex-direction:column;
    background:#eef6f2;
}

.chat-head{
    background:#fff;
    padding:14px 18px;
    border-bottom:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
}

.customer{
    display:flex;
    gap:11px;
    align-items:center;
    min-width:0;
}

.avatar{
    width:42px;
    height:42px;
    border-radius:50%;
    background:linear-gradient(135deg,#d9ccff,#8d6df7);
    color:#fff;
    display:grid;
    place-items:center;
    font-weight:900;
    flex:0 0 auto;
}

.customer h3{
    margin:0;
    font-size:15px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.customer small{
    color:var(--muted);
}

.channel-status{
    text-align:right;
    font-size:11px;
    color:var(--muted);
}

.channel-status strong{
    color:var(--ink);
}

.messages{
    flex:1;
    overflow-y:auto;
    padding:24px 26px;
    background:
        linear-gradient(rgba(238,246,242,.94),rgba(238,246,242,.94)),
        radial-gradient(circle at 20px 20px,#d8e9e0 1px,transparent 1px);
    background-size:auto,28px 28px;
}

.message-row{
    display:flex;
    margin:0 0 12px;
}

.message-row.out{
    justify-content:flex-end;
}

.bubble{
    max-width:min(680px,78%);
    background:#fff;
    border-radius:10px 10px 10px 3px;
    padding:10px 12px 7px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
    font-size:13px;
    line-height:1.45;
    word-break:break-word;
}

.message-row.out .bubble{
    background:#d9fdd3;
    border-radius:10px 10px 3px 10px;
}

.msg-meta{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:6px;
    margin-top:6px;
    color:#7b8794;
    font-size:10px;
}

.status-read{
    color:#0a84ff;
    font-weight:800;
}

.status-failed{
    color:var(--danger);
    font-weight:800;
}

.composer{
    background:#fff;
    border-top:1px solid var(--line);
    padding:14px 16px;
}

.composer form{
    display:flex;
    gap:10px;
    align-items:flex-end;
}

.composer textarea{
    min-height:48px;
    max-height:120px;
    resize:vertical;
}

.send-btn{
    min-width:110px;
    height:48px;
    border:0;
    border-radius:12px;
    background:var(--brand);
    color:#fff;
    font-weight:800;
    cursor:pointer;
}

.send-btn:hover{
    background:#5c30da;
}

.send-btn:disabled{
    opacity:.45;
    cursor:not-allowed;
}

.composer-note{
    margin-top:8px;
    color:var(--muted);
    font-size:11px;
}

.empty{
    height:100%;
    display:grid;
    place-items:center;
    text-align:center;
    color:var(--muted);
    padding:30px;
}

.empty strong{
    display:block;
    color:var(--ink);
    margin-bottom:5px;
}

.token-note{
    margin-top:8px;
    padding:8px 10px;
    border-radius:10px;
    background:#fffaeb;
    color:#b54708;
    font-size:11px;
}

@media(max-width:1050px){
    .shell{
        grid-template-columns:1fr;
    }

    .sidebar{
        display:none;
    }

    .main{
        padding:18px;
    }

    .workspace{
        height:auto;
        min-height:760px;
        grid-template-columns:290px minmax(0,1fr);
    }
}

@media(max-width:760px){
    .workspace{
        display:block;
    }

    .conversation-list{
        max-height:300px;
        border-right:0;
        border-bottom:1px solid var(--line);
    }

    .chat{
        min-height:600px;
    }

    .topbar{
        display:block;
    }

    .user-chip{
        display:none;
    }

    .bubble{
        max-width:88%;
    }
}
</style>
</head>

<body>
<div class="shell">

<aside class="sidebar">
    <div class="brand">
        <div class="logo">T</div>
        <div>
            <h1>TalIA Connect WA</h1>
            <small>Centro de mensajería</small>
        </div>
    </div>

    <nav class="nav">
        <a href="index.php">Configuración</a>
        <a class="active" href="conversaciones.php">Conversaciones</a>
        <a href="management.php">Administración WA</a>
        <a href="privacidad.php" target="_blank" rel="noopener">Privacidad</a>
    </nav>

    <div class="sidebar-foot">
        Región SUR<br>
        Plataforma TalIA
    </div>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h2>Conversaciones</h2>
            <p>Consulta mensajes reales y responde desde TalIA Connect WA.</p>
        </div>

        <div class="user-chip">
            <?= h($usuario) ?><br>
            <strong><?= h($rol) ?></strong>
        </div>
    </div>

    <?php if ($esAdmin && !empty($numerosDisponibles)): ?>
    <form method="get" class="admin-select">
        <div class="field">
            <label for="id_numero">Número administrado</label>

            <select
                id="id_numero"
                name="id_numero"
                onchange="this.form.submit()"
            >
                <?php foreach ($numerosDisponibles as $n): ?>
                <option
                    value="<?= (int)$n['id'] ?>"
                    <?= (int)$n['id'] === $idNumeroSeleccionado ? 'selected' : '' ?>
                >
                    <?= h(
                        ($n['nombre_vendedor'] ?: 'Sin nombre')
                        . ' · '
                        . ($n['display_phone_number'] ?: $n['telefono'])
                        . ' · '
                        . $n['estado']
                    ) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($mensajeUi !== ''): ?>
        <div class="alert <?= h($tipoMensajeUi) ?>">
            <?= h($mensajeUi) ?>
        </div>
    <?php endif; ?>

    <?php if (!$numero): ?>
        <div class="alert warning">
            No encontramos un número de WhatsApp vinculado a este usuario.
        </div>
    <?php endif; ?>

    <section class="workspace">

        <aside class="conversation-list">
            <div class="list-head">
                <h3>Conversaciones</h3>
                <div class="sub">
                    <?= count($conversaciones) ?> conversación(es) ·
                    <?= h($displayNumero) ?>
                </div>
            </div>

            <div class="list-scroll">

                <?php if (empty($conversaciones)): ?>

                    <div class="empty">
                        <div>
                            <strong>Aún no hay conversaciones</strong>
                            Los mensajes que reciba webhook.php aparecerán aquí.
                        </div>
                    </div>

                <?php else: ?>

                    <?php foreach ($conversaciones as $conv): ?>

                        <?php
                        $idConv = (int)$conv['id'];
                        $nombre = trim((string)$conv['nombre_cliente']);

                        if ($nombre === '') {
                            $nombre = (string)(
                                $conv['telefono_cliente']
                                ?: $conv['wa_id_cliente']
                            );
                        }

                        $preview = trim((string)$conv['ultimo_mensaje']);

                        if ($preview === '') {
                            $preview = 'Sin mensajes';
                        }

                        $prefijo = strtoupper(
                            (string)$conv['ultima_direccion']
                        ) === 'SALIENTE'
                            ? 'Tú: '
                            : '';
                        ?>

                        <a
                            class="conv <?= $idConv === $idConversacionSeleccionada ? 'active' : '' ?>"
                            href="conversaciones.php?id_numero=<?= (int)$idNumeroSeleccionado ?>&c=<?= $idConv ?>"
                        >
                            <div class="conv-row">
                                <div class="conv-name">
                                    <?= h($nombre) ?>
                                </div>

                                <div class="conv-time">
                                    <?= h(
                                        formatearFechaLista(
                                            $conv['ultima_actividad']
                                        )
                                    ) ?>
                                </div>
                            </div>

                            <div class="conv-preview">
                                <?= h($prefijo . $preview) ?>
                            </div>

                            <div class="conv-meta">
                                <span class="badge <?= strtolower((string)$conv['modo_atencion']) ?>">
                                    <?= h((string)$conv['modo_atencion']) ?>
                                </span>

                                <span class="badge">
                                    <?= h((string)$conv['estado']) ?>
                                </span>
                            </div>
                        </a>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </aside>

        <div class="chat">

            <?php if (!$conversacionActual): ?>

                <div class="empty">
                    <div>
                        <strong>Selecciona una conversación</strong>
                        Aquí aparecerá el historial real de mensajes.
                    </div>
                </div>

            <?php else: ?>

                <?php
                $nombreCliente = trim(
                    (string)$conversacionActual['nombre_cliente']
                );

                if ($nombreCliente === '') {
                    $nombreCliente = (string)(
                        $conversacionActual['telefono_cliente']
                        ?: $conversacionActual['wa_id_cliente']
                    );
                }

                $inicial = mb_strtoupper(
                    mb_substr($nombreCliente, 0, 1)
                );
                ?>

                <div class="chat-head">

                    <div class="customer">
                        <div class="avatar">
                            <?= h($inicial) ?>
                        </div>

                        <div>
                            <h3><?= h($nombreCliente) ?></h3>
                            <small>
                                <?= h(
                                    (string)(
                                        $conversacionActual['telefono_cliente']
                                        ?: $conversacionActual['wa_id_cliente']
                                    )
                                ) ?>
                            </small>
                        </div>
                    </div>

                    <div class="channel-status">
                        <strong><?= h($displayNumero) ?></strong><br>
                        <?= h($estadoNumero) ?> ·
                        <?= h((string)$conversacionActual['modo_atencion']) ?>
                    </div>

                </div>

                <div class="messages" id="messages">

                    <?php if (empty($mensajes)): ?>

                        <div class="empty">
                            <div>
                                <strong>Sin mensajes</strong>
                                Esta conversación todavía no contiene mensajes.
                            </div>
                        </div>

                    <?php else: ?>

                        <?php foreach ($mensajes as $msg): ?>

                            <?php
                            $saliente = strtoupper(
                                (string)$msg['direccion']
                            ) === 'SALIENTE';

                            $fechaMostrar = $msg['fecha_mensaje']
                                ?: $msg['fecha_recibido_webhook'];

                            $estado = strtoupper(
                                (string)$msg['estado_envio']
                            );

                            $ticks = '';

                            if ($saliente) {
                                if ($estado === 'READ') {
                                    $ticks = '<span class="status-read">✓✓</span>';
                                } elseif (
                                    $estado === 'DELIVERED'
                                    || $estado === 'SENT'
                                ) {
                                    $ticks = '✓✓';
                                } elseif ($estado === 'FAILED') {
                                    $ticks = '<span class="status-failed">!</span>';
                                } else {
                                    $ticks = '✓';
                                }
                            }
                            ?>

                            <div class="message-row <?= $saliente ? 'out' : 'in' ?>">
                                <div class="bubble">

                                    <?php if (
                                        strtolower(
                                            (string)$msg['tipo']
                                        ) === 'text'
                                    ): ?>

                                        <?= nl2br(
                                            h(
                                                (string)$msg['contenido']
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        <strong>
                                            <?= h(
                                                strtoupper(
                                                    (string)$msg['tipo']
                                                )
                                            ) ?>
                                        </strong>

                                        <?php if (
                                            trim(
                                                (string)$msg['contenido']
                                            ) !== ''
                                        ): ?>
                                            <br>
                                            <?= nl2br(
                                                h(
                                                    (string)$msg['contenido']
                                                )
                                            ) ?>
                                        <?php endif; ?>

                                    <?php endif; ?>

                                    <div class="msg-meta">
                                        <span>
                                            <?= h(
                                                formatearHora(
                                                    $fechaMostrar
                                                )
                                            ) ?>
                                        </span>

                                        <?php if ($saliente): ?>
                                            <span>
                                                <?= $ticks ?>
                                            </span>

                                            <?php if ($estado !== ''): ?>
                                            <span>
                                                <?= h(
                                                    etiquetaEstado(
                                                        $estado
                                                    )
                                                ) ?>
                                            </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <div class="composer">

                    <form method="post">
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= h($csrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="id_numero"
                            value="<?= (int)$idNumeroSeleccionado ?>"
                        >

                        <input
                            type="hidden"
                            name="id_conversacion"
                            value="<?= (int)$idConversacionSeleccionada ?>"
                        >

                        <input
                            type="hidden"
                            name="enviar_mensaje"
                            value="1"
                        >

                        <textarea
                            name="mensaje"
                            maxlength="4096"
                            placeholder="Escribe un mensaje..."
                            <?= !$envioDisponible ? 'disabled' : '' ?>
                        ></textarea>

                        <button
                            type="submit"
                            class="send-btn"
                            <?= !$envioDisponible ? 'disabled' : '' ?>
                        >
                            Enviar
                        </button>
                    </form>

                    <?php if (!$envioDisponible): ?>
                    <div class="token-note">
                        Recepción e historial disponibles. Para habilitar
                        el envío real falta configurar el access token
                        de WhatsApp Cloud API en el servidor.
                    </div>
                    <?php else: ?>
                    <div class="composer-note">
                        Envío real por WhatsApp Cloud API ·
                        <?= h($graphVersion) ?>
                    </div>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>
</div>

<script>
(function(){
    const box = document.getElementById('messages');

    if (box) {
        box.scrollTop = box.scrollHeight;
    }

    const textarea = document.querySelector('.composer textarea');

    if (textarea) {
        textarea.addEventListener('keydown', function(e){
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();

                if (this.value.trim() !== '') {
                    this.form.requestSubmit();
                }
            }
        });
    }
})();
</script>

</body>
</html>
