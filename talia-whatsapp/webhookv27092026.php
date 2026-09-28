<?php
declare(strict_types=1);

/**
 * TalIA Connect WA
 * Webhook para Meta / WhatsApp Business Platform
 *
 * Versión: 0.2
 *
 * Funciones:
 * 1. GET  -> Verificación del webhook por Meta
 * 2. POST -> Recepción de eventos de WhatsApp
 * 3. Guarda payload crudo y parseado
 * 4. Extrae mensajes entrantes a un log limpio
 * 5. Extrae actualizaciones de estado a un log independiente
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
// FUNCIONES AUXILIARES
// =====================================================

function guardarLog(string $archivo, string $contenido): void
{
    global $logDir;

    $ruta = $logDir . '/' . $archivo;

    $registro =
        "==================================================" . PHP_EOL .
        "Fecha servidor: " . date('Y-m-d H:i:s') . PHP_EOL .
        $contenido . PHP_EOL . PHP_EOL;

    file_put_contents(
        $ruta,
        $registro,
        FILE_APPEND | LOCK_EX
    );
}

function valorSeguro(array $data, array $ruta, mixed $default = ''): mixed
{
    $actual = $data;

    foreach ($ruta as $clave) {
        if (!is_array($actual) || !array_key_exists($clave, $actual)) {
            return $default;
        }

        $actual = $actual[$clave];
    }

    return $actual;
}

function convertirTimestampWhatsApp(string|int|null $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '';
    }

    $timestampInt = (int)$timestamp;

    if ($timestampInt <= 0) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestampInt);
}

function obtenerContenidoMensaje(array $mensaje): string
{
    $tipo = $mensaje['type'] ?? '';

    switch ($tipo) {
        case 'text':
            return (string)valorSeguro($mensaje, ['text', 'body'], '');

        case 'button':
            return (string)valorSeguro($mensaje, ['button', 'text'], '');

        case 'interactive':
            $interactiveType = (string)valorSeguro($mensaje, ['interactive', 'type'], '');

            if ($interactiveType === 'button_reply') {
                $id = (string)valorSeguro($mensaje, ['interactive', 'button_reply', 'id'], '');
                $title = (string)valorSeguro($mensaje, ['interactive', 'button_reply', 'title'], '');
                return trim("button_reply | id={$id} | title={$title}");
            }

            if ($interactiveType === 'list_reply') {
                $id = (string)valorSeguro($mensaje, ['interactive', 'list_reply', 'id'], '');
                $title = (string)valorSeguro($mensaje, ['interactive', 'list_reply', 'title'], '');
                $description = (string)valorSeguro($mensaje, ['interactive', 'list_reply', 'description'], '');
                return trim("list_reply | id={$id} | title={$title} | description={$description}");
            }

            return 'interactive';

        case 'image':
            $id = (string)valorSeguro($mensaje, ['image', 'id'], '');
            $caption = (string)valorSeguro($mensaje, ['image', 'caption'], '');
            return trim("image_id={$id} | caption={$caption}");

        case 'audio':
            $id = (string)valorSeguro($mensaje, ['audio', 'id'], '');
            return "audio_id={$id}";

        case 'video':
            $id = (string)valorSeguro($mensaje, ['video', 'id'], '');
            $caption = (string)valorSeguro($mensaje, ['video', 'caption'], '');
            return trim("video_id={$id} | caption={$caption}");

        case 'document':
            $id = (string)valorSeguro($mensaje, ['document', 'id'], '');
            $filename = (string)valorSeguro($mensaje, ['document', 'filename'], '');
            $caption = (string)valorSeguro($mensaje, ['document', 'caption'], '');
            return trim("document_id={$id} | filename={$filename} | caption={$caption}");

        case 'location':
            $latitude = (string)valorSeguro($mensaje, ['location', 'latitude'], '');
            $longitude = (string)valorSeguro($mensaje, ['location', 'longitude'], '');
            $name = (string)valorSeguro($mensaje, ['location', 'name'], '');
            $address = (string)valorSeguro($mensaje, ['location', 'address'], '');
            return trim("lat={$latitude} | lng={$longitude} | name={$name} | address={$address}");

        case 'contacts':
            return 'contacts';

        case 'reaction':
            $emoji = (string)valorSeguro($mensaje, ['reaction', 'emoji'], '');
            $messageId = (string)valorSeguro($mensaje, ['reaction', 'message_id'], '');
            return trim("reaction={$emoji} | message_id={$messageId}");

        default:
            return '';
    }
}

function procesarEventoWhatsApp(array $data): void
{
    $object = (string)($data['object'] ?? '');

    if ($object !== 'whatsapp_business_account') {
        guardarLog(
            'ignored_events.log',
            "Evento ignorado. object={$object}"
        );
        return;
    }

    $entries = $data['entry'] ?? [];

    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryId = (string)($entry['id'] ?? '');
        $changes = $entry['changes'] ?? [];

        if (!is_array($changes)) {
            continue;
        }

        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $field = (string)($change['field'] ?? '');
            $value = $change['value'] ?? [];

            if ($field !== 'messages' || !is_array($value)) {
                continue;
            }

            $metadata = $value['metadata'] ?? [];
            $displayPhoneNumber = is_array($metadata)
                ? (string)($metadata['display_phone_number'] ?? '')
                : '';

            $phoneNumberId = is_array($metadata)
                ? (string)($metadata['phone_number_id'] ?? '')
                : '';

            $contacts = $value['contacts'] ?? [];
            $contactosPorWaId = [];

            if (is_array($contacts)) {
                foreach ($contacts as $contacto) {
                    if (!is_array($contacto)) {
                        continue;
                    }

                    $waId = (string)($contacto['wa_id'] ?? '');
                    $nombre = (string)valorSeguro($contacto, ['profile', 'name'], '');
                    $userId = (string)($contacto['user_id'] ?? '');

                    if ($waId !== '') {
                        $contactosPorWaId[$waId] = [
                            'nombre' => $nombre,
                            'user_id' => $userId,
                        ];
                    }
                }
            }

            // ---------------------------------------------
            // MENSAJES ENTRANTES
            // ---------------------------------------------

            $messages = $value['messages'] ?? [];

            if (is_array($messages)) {
                foreach ($messages as $mensaje) {
                    if (!is_array($mensaje)) {
                        continue;
                    }

                    $from = (string)($mensaje['from'] ?? '');
                    $messageId = (string)($mensaje['id'] ?? '');
                    $timestamp = $mensaje['timestamp'] ?? '';
                    $tipo = (string)($mensaje['type'] ?? '');
                    $contenido = obtenerContenidoMensaje($mensaje);

                    $nombreCliente = '';
                    $userId = '';

                    if ($from !== '' && isset($contactosPorWaId[$from])) {
                        $nombreCliente = $contactosPorWaId[$from]['nombre'];
                        $userId = $contactosPorWaId[$from]['user_id'];
                    } elseif (!empty($contacts[0]) && is_array($contacts[0])) {
                        $nombreCliente = (string)valorSeguro($contacts[0], ['profile', 'name'], '');
                        $userId = (string)($contacts[0]['user_id'] ?? '');
                    }

                    $timestampLegible = convertirTimestampWhatsApp($timestamp);

                    $registro =
                        "OBJECT: {$object}" . PHP_EOL .
                        "ENTRY ID / WABA ID: {$entryId}" . PHP_EOL .
                        "FIELD: {$field}" . PHP_EOL .
                        "DISPLAY PHONE NUMBER: {$displayPhoneNumber}" . PHP_EOL .
                        "PHONE NUMBER ID: {$phoneNumberId}" . PHP_EOL .
                        "CLIENTE: {$nombreCliente}" . PHP_EOL .
                        "WA ID / FROM: {$from}" . PHP_EOL .
                        "USER ID: {$userId}" . PHP_EOL .
                        "MESSAGE ID: {$messageId}" . PHP_EOL .
                        "TIMESTAMP RAW: {$timestamp}" . PHP_EOL .
                        "TIMESTAMP: {$timestampLegible}" . PHP_EOL .
                        "TIPO: {$tipo}" . PHP_EOL .
                        "CONTENIDO: {$contenido}";

                    guardarLog('messages_clean.log', $registro);
                }
            }

            // ---------------------------------------------
            // ESTADOS DE MENSAJES
            // ---------------------------------------------

            $statuses = $value['statuses'] ?? [];

            if (is_array($statuses)) {
                foreach ($statuses as $status) {
                    if (!is_array($status)) {
                        continue;
                    }

                    $statusId = (string)($status['id'] ?? '');
                    $statusValue = (string)($status['status'] ?? '');
                    $recipientId = (string)($status['recipient_id'] ?? '');
                    $timestamp = $status['timestamp'] ?? '';
                    $timestampLegible = convertirTimestampWhatsApp($timestamp);

                    $errors = $status['errors'] ?? [];
                    $errorText = '';

                    if (is_array($errors) && !empty($errors)) {
                        $errorText = json_encode(
                            $errors,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ) ?: '';
                    }

                    $registro =
                        "OBJECT: {$object}" . PHP_EOL .
                        "ENTRY ID / WABA ID: {$entryId}" . PHP_EOL .
                        "FIELD: {$field}" . PHP_EOL .
                        "DISPLAY PHONE NUMBER: {$displayPhoneNumber}" . PHP_EOL .
                        "PHONE NUMBER ID: {$phoneNumberId}" . PHP_EOL .
                        "MESSAGE ID: {$statusId}" . PHP_EOL .
                        "RECIPIENT ID: {$recipientId}" . PHP_EOL .
                        "STATUS: {$statusValue}" . PHP_EOL .
                        "TIMESTAMP RAW: {$timestamp}" . PHP_EOL .
                        "TIMESTAMP: {$timestampLegible}" . PHP_EOL .
                        "ERRORS: {$errorText}";

                    guardarLog('status_clean.log', $registro);
                }
            }
        }
    }
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

    // No guardamos el token recibido en logs.
    guardarLog(
        'verification.log',
        "MODE: {$mode}" . PHP_EOL .
        "TOKEN COINCIDE: " . (
            $token !== '' && hash_equals($VERIFY_TOKEN, $token)
                ? 'SI'
                : 'NO'
        ) . PHP_EOL .
        "CHALLENGE: {$challenge}"
    );

    if (
        $mode === 'subscribe' &&
        $token !== '' &&
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

    if ($rawPayload === false || $rawPayload === '') {
        guardarLog(
            'errors.log',
            'No fue posible leer php://input o llegó vacío.'
        );

        // Meta espera respuesta rápida.
        http_response_code(200);
        echo 'EVENT_RECEIVED';
        exit;
    }

    // 1. Guardamos exactamente lo recibido de Meta.
    guardarLog(
        'webhook_raw.log',
        $rawPayload
    );

    // 2. Convertimos JSON a arreglo PHP.
    $data = json_decode($rawPayload, true);

    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
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

    // 3. Guardamos versión legible.
    guardarLog(
        'webhook_parsed.log',
        print_r($data, true)
    );

    // 4. Extraemos mensajes y estados a logs limpios.
    try {
        procesarEventoWhatsApp($data);
    } catch (Throwable $e) {
        guardarLog(
            'errors.log',
            "Error procesando evento:" . PHP_EOL .
            $e->getMessage() . PHP_EOL .
            $e->getTraceAsString()
        );
    }

    // Meta necesita un 200 rápido.
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
