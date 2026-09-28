<?php
declare(strict_types=1);

/**
 * TalIA WhatsApp
 * Webhook inicial para Meta / WhatsApp Business Platform
 */

date_default_timezone_set('America/Merida');

// =====================================================
// CONFIGURACIÓN
// =====================================================

$VERIFY_TOKEN = 'TALIA_WHATSAPP_2026';

$logDir = __DIR__ . '/logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// =====================================================
// FUNCIÓN PARA GUARDAR LOGS
// =====================================================

function guardarLog(string $archivo, string $contenido): void
{
    global $logDir;

    $ruta = $logDir . '/' . $archivo;

    $registro =
        "==================================================" . PHP_EOL .
        "Fecha: " . date('Y-m-d H:i:s') . PHP_EOL .
        $contenido . PHP_EOL . PHP_EOL;

    file_put_contents(
        $ruta,
        $registro,
        FILE_APPEND | LOCK_EX
    );
}

// =====================================================
// MÉTODO HTTP
// =====================================================

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// =====================================================
// GET
// Verificación de Webhook por Meta
// =====================================================

if ($method === 'GET') {

    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    guardarLog(
        'verification.log',
        "MODE: {$mode}" . PHP_EOL .
        "TOKEN RECIBIDO: {$token}" . PHP_EOL .
        "CHALLENGE: {$challenge}"
    );

    if (
        $mode === 'subscribe' &&
        hash_equals($VERIFY_TOKEN, $token)
    ) {

        http_response_code(200);

        echo $challenge;
        exit;
    }

    http_response_code(403);

    echo 'Verification failed';
    exit;
}

// =====================================================
// POST
// Recepción de eventos de WhatsApp
// =====================================================

if ($method === 'POST') {

    $rawPayload = file_get_contents('php://input');

    if ($rawPayload === false) {

        guardarLog(
            'errors.log',
            'No fue posible leer php://input'
        );

        http_response_code(200);

        echo 'EVENT_RECEIVED';
        exit;
    }

    // Guardamos exactamente lo recibido de Meta
    guardarLog(
        'webhook_raw.log',
        $rawPayload
    );

    // Convertimos JSON a arreglo PHP
    $data = json_decode($rawPayload, true);

    if (json_last_error() !== JSON_ERROR_NONE) {

        guardarLog(
            'errors.log',
            'JSON inválido: ' .
            json_last_error_msg() .
            PHP_EOL .
            $rawPayload
        );

        http_response_code(200);

        echo 'EVENT_RECEIVED';
        exit;
    }

    // Guardamos versión legible
    guardarLog(
        'webhook_parsed.log',
        print_r($data, true)
    );

    // ===============================================
    // TODAVÍA NO PROCESAMOS MENSAJES
    // ===============================================
    //
    // Posteriormente aquí obtendremos:
    //
    // - phone_number_id
    // - número del cliente
    // - message_id
    // - tipo de mensaje
    // - texto
    // - timestamp
    //
    // ===============================================

    http_response_code(200);

    echo 'EVENT_RECEIVED';
    exit;
}

// =====================================================
// OTROS MÉTODOS
// =====================================================

http_response_code(405);

header('Allow: GET, POST');

echo 'Method Not Allowed';