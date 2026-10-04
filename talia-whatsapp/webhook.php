<?php
declare(strict_types=1);

/**
 * TalIA Connect WA
 * Webhook para Meta / WhatsApp Business Platform
 *
 * Versión: 0.3.2
 *
 * Funciones:
 * 1. GET  -> Verificación del webhook por Meta
 * 2. POST -> Recepción de eventos de WhatsApp
 * 3. Guarda payload crudo y parseado
 * 4. Extrae mensajes entrantes a un log limpio
 * 5. Extrae actualizaciones de estado a un log independiente
 * 6. Persiste mensajes en wa_conversaciones / wa_mensajes
 * 7. Actualiza estados SENT / DELIVERED / READ / FAILED en MySQL
 * 8. Asigna modo de atención dinámico según wa_numeros.bot_activo
 * 9. Unifica timestamps operativos en America/Merida sin depender de la zona horaria MySQL
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

// =====================================================
// MYSQL / TALIA
// =====================================================

function obtenerConexionTalIA(): ?mysqli
{
    static $conexionCache = null;
    static $intentado = false;

    if ($intentado) {
        return $conexionCache;
    }

    $intentado = true;
    $rutaConexion = dirname(__DIR__) . '/conexion.php';

    if (!is_file($rutaConexion)) {
        guardarLog(
            'db_errors.log',
            "No existe conexion.php en la ruta esperada: {$rutaConexion}"
        );
        return null;
    }

    $conexion = null;

    try {
        require $rutaConexion;
    } catch (Throwable $e) {
        guardarLog(
            'db_errors.log',
            "No fue posible cargar conexion.php:" . PHP_EOL . $e->getMessage()
        );
        return null;
    }

    if (!($conexion instanceof mysqli)) {
        guardarLog(
            'db_errors.log',
            'conexion.php no dejó disponible una instancia mysqli en $conexion.'
        );
        return null;
    }

    if (!mysqli_set_charset($conexion, 'utf8mb4')) {
        guardarLog(
            'db_errors.log',
            'No fue posible establecer utf8mb4: ' . mysqli_error($conexion)
        );
    }

    $conexionCache = $conexion;
    return $conexionCache;
}

function tipoMensajeBD(string $tipo): string
{
    $permitidos = [
        'text',
        'image',
        'audio',
        'video',
        'document',
        'location',
        'contacts',
        'interactive',
        'button',
        'reaction',
    ];

    return in_array($tipo, $permitidos, true) ? $tipo : 'unknown';
}

function buscarNumeroPorPhoneNumberId(mysqli $conexion, string $phoneNumberId): ?array
{
    $sql = "
        SELECT
            id,
            telefono,
            display_phone_number,
            phone_number_id,
            waba_id,
            bot_activo,
            modo_respuesta,
            estado
        FROM wa_numeros
        WHERE phone_number_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException('Error preparando búsqueda de wa_numeros: ' . mysqli_error($conexion));
    }

    mysqli_stmt_bind_param($stmt, 's', $phoneNumberId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result(
        $stmt,
        $id,
        $telefono,
        $displayPhoneNumber,
        $phoneNumberIdDb,
        $wabaId,
        $botActivo,
        $modoRespuesta,
        $estado
    );

    $fila = null;

    if (mysqli_stmt_fetch($stmt)) {
        $fila = [
            'id' => (int)$id,
            'telefono' => (string)$telefono,
            'display_phone_number' => (string)$displayPhoneNumber,
            'phone_number_id' => (string)$phoneNumberIdDb,
            'waba_id' => (string)$wabaId,
            'bot_activo' => (int)$botActivo,
            'modo_respuesta' => (string)$modoRespuesta,
            'estado' => (string)$estado,
        ];
    }

    mysqli_stmt_close($stmt);
    return $fila;
}


function resolverModoAtencionNumero(array $numero): string
{
    // Regla base TalIA Connect WA:
    // bot_activo = 1 -> BOT
    // bot_activo = 0 -> HUMANO
    return ((int)($numero['bot_activo'] ?? 0) === 1)
        ? 'BOT'
        : 'HUMANO';
}

function obtenerOCrearConversacion(
    mysqli $conexion,
    int $idNumero,
    string $waIdCliente,
    string $telefonoCliente,
    string $nombreCliente,
    string $modoAtencion
): int {
    /*
     * La aplicación trabaja en America/Merida. Generamos el timestamp en PHP
     * para no depender de la zona horaria configurada en MariaDB/Hostinger.
     */
    $ahoraLocal = date('Y-m-d H:i:s');

    $sql = "
        INSERT INTO wa_conversaciones (
            id_numero,
            wa_id_cliente,
            telefono_cliente,
            nombre_cliente,
            estado,
            modo_atencion,
            fecha_inicio,
            ultima_actividad
        ) VALUES (
            ?, ?, ?, ?, 'ABIERTA', ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            telefono_cliente = COALESCE(NULLIF(VALUES(telefono_cliente), ''), telefono_cliente),
            nombre_cliente = COALESCE(NULLIF(VALUES(nombre_cliente), ''), nombre_cliente),
            estado = 'ABIERTA',
            modo_atencion = CASE
                WHEN tomada_por_numero_talento IS NOT NULL
                  OR (
                      bot_pausado_hasta IS NOT NULL
                      AND bot_pausado_hasta > ?
                  )
                THEN modo_atencion
                ELSE VALUES(modo_atencion)
            END,
            fecha_cierre = NULL,
            ultima_actividad = VALUES(ultima_actividad)
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException('Error preparando wa_conversaciones: ' . mysqli_error($conexion));
    }

    mysqli_stmt_bind_param(
        $stmt,
        'isssssss',
        $idNumero,
        $waIdCliente,
        $telefonoCliente,
        $nombreCliente,
        $modoAtencion,
        $ahoraLocal,
        $ahoraLocal,
        $ahoraLocal
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $idConversacion = (int)mysqli_insert_id($conexion);

    if ($idConversacion <= 0) {
        $sqlSelect = "
            SELECT id
            FROM wa_conversaciones
            WHERE id_numero = ?
              AND wa_id_cliente = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conexion, $sqlSelect);

        if (!$stmt) {
            throw new RuntimeException('Error recuperando wa_conversaciones: ' . mysqli_error($conexion));
        }

        mysqli_stmt_bind_param($stmt, 'is', $idNumero, $waIdCliente);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $idConversacionDb);

        if (mysqli_stmt_fetch($stmt)) {
            $idConversacion = (int)$idConversacionDb;
        }

        mysqli_stmt_close($stmt);
    }

    if ($idConversacion <= 0) {
        throw new RuntimeException('No fue posible obtener id de conversación.');
    }

    return $idConversacion;
}

function extraerDatosMedia(array $mensaje, string $tipo): array
{
    $mediaId = '';
    $mimeType = '';

    if (in_array($tipo, ['image', 'audio', 'video', 'document'], true)) {
        $mediaId = (string)valorSeguro($mensaje, [$tipo, 'id'], '');
        $mimeType = (string)valorSeguro($mensaje, [$tipo, 'mime_type'], '');
    }

    return [$mediaId, $mimeType];
}

function guardarMensajeEntranteBD(
    mysqli $conexion,
    int $idConversacion,
    int $idNumero,
    string $messageId,
    string $from,
    string $to,
    string $tipo,
    string $contenido,
    string $mediaId,
    string $mimeType,
    string $contextMessageId,
    string $fechaMensaje,
    string $payloadJson
): string {
    $fechaRecepcionLocal = date('Y-m-d H:i:s');

    $sql = "
        INSERT INTO wa_mensajes (
            id_conversacion,
            id_numero,
            message_id,
            direccion,
            wa_from,
            wa_to,
            tipo,
            contenido,
            media_id,
            mime_type,
            context_message_id,
            estado_envio,
            fecha_mensaje,
            fecha_recibido_webhook,
            payload_json
        ) VALUES (
            ?, ?, ?, 'ENTRANTE', ?, ?, ?, ?,
            NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
            'RECEIVED', NULLIF(?, ''), ?, ?
        )
        ON DUPLICATE KEY UPDATE
            message_id = VALUES(message_id)
    ";

    $stmt = mysqli_prepare($conexion, $sql);

    if (!$stmt) {
        throw new RuntimeException('Error preparando wa_mensajes: ' . mysqli_error($conexion));
    }

    mysqli_stmt_bind_param(
        $stmt,
        'iisssssssssss',
        $idConversacion,
        $idNumero,
        $messageId,
        $from,
        $to,
        $tipo,
        $contenido,
        $mediaId,
        $mimeType,
        $contextMessageId,
        $fechaMensaje,
        $fechaRecepcionLocal,
        $payloadJson
    );

    mysqli_stmt_execute($stmt);
    $afectadas = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return $afectadas === 1 ? 'INSERTADO' : 'DUPLICADO';
}

function persistirMensajeEntrante(
    string $phoneNumberId,
    string $displayPhoneNumber,
    string $from,
    string $nombreCliente,
    string $messageId,
    string $tipoOriginal,
    string $contenido,
    string $fechaMensaje,
    array $mensaje
): void {
    if ($phoneNumberId === '' || $from === '' || $messageId === '') {
        guardarLog(
            'db_events.log',
            "MENSAJE_NO_PERSISTIDO | Datos incompletos" . PHP_EOL .
            "phone_number_id={$phoneNumberId}" . PHP_EOL .
            "from={$from}" . PHP_EOL .
            "message_id={$messageId}"
        );
        return;
    }

    $conexion = obtenerConexionTalIA();

    if (!$conexion) {
        return;
    }

    $numero = buscarNumeroPorPhoneNumberId($conexion, $phoneNumberId);

    if (!$numero) {
        guardarLog(
            'db_events.log',
            "NUMERO_NO_REGISTRADO" . PHP_EOL .
            "phone_number_id={$phoneNumberId}" . PHP_EOL .
            "display_phone_number={$displayPhoneNumber}" . PHP_EOL .
            "message_id={$messageId}"
        );
        return;
    }

    $idNumero = (int)$numero['id'];
    $telefonoDestino = (string)($numero['telefono'] ?? '');

    if ($telefonoDestino === '') {
        $telefonoDestino = $displayPhoneNumber;
    }

    $modoAtencion = resolverModoAtencionNumero($numero);

    $idConversacion = obtenerOCrearConversacion(
        $conexion,
        $idNumero,
        $from,
        $from,
        $nombreCliente,
        $modoAtencion
    );

    $tipo = tipoMensajeBD($tipoOriginal);
    [$mediaId, $mimeType] = extraerDatosMedia($mensaje, $tipoOriginal);
    $contextMessageId = (string)valorSeguro($mensaje, ['context', 'id'], '');

    $payloadJson = json_encode(
        $mensaje,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '';

    $resultado = guardarMensajeEntranteBD(
        $conexion,
        $idConversacion,
        $idNumero,
        $messageId,
        $from,
        $telefonoDestino,
        $tipo,
        $contenido,
        $mediaId,
        $mimeType,
        $contextMessageId,
        $fechaMensaje,
        $payloadJson
    );

    guardarLog(
        'db_events.log',
        "MENSAJE_{$resultado}" . PHP_EOL .
        "id_numero={$idNumero}" . PHP_EOL .
        "id_conversacion={$idConversacion}" . PHP_EOL .
        "message_id={$messageId}" . PHP_EOL .
        "from={$from}" . PHP_EOL .
        "tipo={$tipo}" . PHP_EOL .
        "bot_activo=" . (int)($numero['bot_activo'] ?? 0) . PHP_EOL .
        "modo_respuesta=" . (string)($numero['modo_respuesta'] ?? '') . PHP_EOL .
        "modo_atencion={$modoAtencion}"
    );
}

function normalizarEstadoMeta(string $estado): string
{
    return match (strtolower(trim($estado))) {
        'sent' => 'SENT',
        'delivered' => 'DELIVERED',
        'read' => 'READ',
        'failed' => 'FAILED',
        default => '',
    };
}

function persistirEstadoMensaje(array $status): void
{
    $messageId = (string)($status['id'] ?? '');
    $estadoMeta = (string)($status['status'] ?? '');
    $estado = normalizarEstadoMeta($estadoMeta);
    $fechaEstado = convertirTimestampWhatsApp($status['timestamp'] ?? '');

    if ($messageId === '' || $estado === '') {
        return;
    }

    $errors = $status['errors'] ?? [];
    $errorCode = '';
    $errorMessage = '';

    if (is_array($errors) && !empty($errors) && is_array($errors[0] ?? null)) {
        $errorCode = (string)($errors[0]['code'] ?? '');
        $errorMessage = (string)(
            $errors[0]['message'] ??
            $errors[0]['title'] ??
            $errors[0]['error_data']['details'] ??
            ''
        );
    }

    $conexion = obtenerConexionTalIA();

    if (!$conexion) {
        return;
    }

    $campoFecha = match ($estado) {
        'SENT' => 'fecha_enviado',
        'DELIVERED' => 'fecha_entregado',
        'READ' => 'fecha_leido',
        default => '',
    };

    if ($campoFecha !== '') {
        $sql = "
            UPDATE wa_mensajes
            SET estado_envio = ?,
                {$campoFecha} = COALESCE(NULLIF(?, ''), {$campoFecha}),
                error_code = NULLIF(?, ''),
                error_message = NULLIF(?, '')
            WHERE message_id = ?
        ";

        $stmt = mysqli_prepare($conexion, $sql);

        if (!$stmt) {
            throw new RuntimeException('Error preparando actualización de estado: ' . mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmt,
            'sssss',
            $estado,
            $fechaEstado,
            $errorCode,
            $errorMessage,
            $messageId
        );
    } else {
        $sql = "
            UPDATE wa_mensajes
            SET estado_envio = ?,
                error_code = NULLIF(?, ''),
                error_message = NULLIF(?, '')
            WHERE message_id = ?
        ";

        $stmt = mysqli_prepare($conexion, $sql);

        if (!$stmt) {
            throw new RuntimeException('Error preparando actualización de estado FAILED: ' . mysqli_error($conexion));
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ssss',
            $estado,
            $errorCode,
            $errorMessage,
            $messageId
        );
    }

    mysqli_stmt_execute($stmt);
    $afectadas = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    guardarLog(
        'db_events.log',
        ($afectadas > 0 ? 'STATUS_ACTUALIZADO' : 'STATUS_SIN_MENSAJE') . PHP_EOL .
        "message_id={$messageId}" . PHP_EOL .
        "status={$estado}" . PHP_EOL .
        "timestamp={$fechaEstado}"
    );
}

// =====================================================
// PROCESAMIENTO DEL EVENTO
// =====================================================

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

                    try {
                        persistirMensajeEntrante(
                            $phoneNumberId,
                            $displayPhoneNumber,
                            $from,
                            $nombreCliente,
                            $messageId,
                            $tipo,
                            $contenido,
                            $timestampLegible,
                            $mensaje
                        );
                    } catch (Throwable $e) {
                        guardarLog(
                            'db_errors.log',
                            "Error persistiendo mensaje:" . PHP_EOL .
                            "message_id={$messageId}" . PHP_EOL .
                            $e->getMessage()
                        );
                    }
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

                    try {
                        persistirEstadoMensaje($status);
                    } catch (Throwable $e) {
                        guardarLog(
                            'db_errors.log',
                            "Error actualizando estado:" . PHP_EOL .
                            "message_id={$statusId}" . PHP_EOL .
                            $e->getMessage()
                        );
                    }
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

    // 4. Extraemos mensajes y estados, y persistimos en MySQL.
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
