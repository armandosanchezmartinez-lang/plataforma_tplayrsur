<?php
declare(strict_types=1);

/**
 * Helpers de webhook para WhatsApp Coexistence.
 *
 * Fase 1:
 * - Conserva payloads history / state sync / echoes.
 * - Sincroniza contactos a wa_coexistence_contactos.
 * - Inserta smb_message_echoes como mensajes SALIENTES para que
 *   TalIA refleje respuestas hechas desde WhatsApp Business App.
 * - No importa history a wa_conversaciones todavía; se conserva
 *   el payload crudo para una fase posterior controlada.
 */

function coexEventHash(string $field, string $entryId, array $value): string {
    $raw = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    return hash('sha256', $field . '|' . $entryId . '|' . $raw);
}

function coexGuardarEvento(
    mysqli $conexion,
    ?int $idNumero,
    string $wabaId,
    string $phoneNumberId,
    string $field,
    array $value
): void {
    $hash = coexEventHash($field,$wabaId,$value);
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    $sql = "INSERT IGNORE INTO wa_coexistence_eventos
            (id_numero,waba_id,phone_number_id,field,event_hash,payload_json,fecha_evento)
            VALUES (?,NULLIF(?,''),NULLIF(?,''),?,?,?,CURRENT_TIMESTAMP)";
    $stmt = mysqli_prepare($conexion,$sql);
    if (!$stmt) return;

    $idNullable = $idNumero;
    mysqli_stmt_bind_param($stmt,'isssss',$idNullable,$wabaId,$phoneNumberId,$field,$hash,$json);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function coexBuscarNombreContacto(mysqli $conexion, int $idNumero, string $waId): string {
    $sql = "SELECT nombre_completo FROM wa_coexistence_contactos
            WHERE id_numero=? AND wa_id=? LIMIT 1";
    $stmt = mysqli_prepare($conexion,$sql);
    if (!$stmt) return '';
    mysqli_stmt_bind_param($stmt,'is',$idNumero,$waId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt,$nombre);
    $out = mysqli_stmt_fetch($stmt) ? (string)$nombre : '';
    mysqli_stmt_close($stmt);
    return $out;
}

function coexProcesarContactos(mysqli $conexion, int $idNumero, array $value): void {
    $items = $value['state_sync'] ?? [];
    if (!is_array($items)) return;

    foreach ($items as $item) {
        if (!is_array($item) || (string)($item['type'] ?? '') !== 'contact') continue;

        $contact = $item['contact'] ?? [];
        if (!is_array($contact)) continue;

        $waId = preg_replace('/\D+/', '', (string)($contact['phone_number'] ?? $contact['wa_id'] ?? '')) ?? '';
        if ($waId === '') continue;

        $full = trim((string)($contact['full_name'] ?? ''));
        $first = trim((string)($contact['first_name'] ?? ''));
        $action = strtolower(trim((string)($item['action'] ?? 'update')));
        $activo = in_array($action,['delete','deleted','remove','removed'],true) ? 0 : 1;

        $sql = "INSERT INTO wa_coexistence_contactos
                (id_numero,wa_id,nombre_completo,nombre_corto,activo,ultima_accion,fecha_actualizacion)
                VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    nombre_completo=COALESCE(NULLIF(VALUES(nombre_completo),''),nombre_completo),
                    nombre_corto=COALESCE(NULLIF(VALUES(nombre_corto),''),nombre_corto),
                    activo=VALUES(activo),
                    ultima_accion=VALUES(ultima_accion),
                    fecha_actualizacion=CURRENT_TIMESTAMP";
        $stmt = mysqli_prepare($conexion,$sql);
        if (!$stmt) continue;
        mysqli_stmt_bind_param($stmt,'isssis',$idNumero,$waId,$full,$first,$activo,$action);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function coexGuardarEchoSaliente(
    mysqli $conexion,
    array $numero,
    array $mensaje,
    string $displayPhone
): void {
    if (isset($mensaje['message']) && is_array($mensaje['message'])) {
        $mensaje = $mensaje['message'];
    }

    $messageId = trim((string)($mensaje['id'] ?? ''));
    if ($messageId === '') return;

    $cliente = preg_replace(
        '/\D+/',
        '',
        (string)($mensaje['to'] ?? $mensaje['recipient_id'] ?? $mensaje['chat_id'] ?? '')
    ) ?? '';
    if ($cliente === '') return;

    $idNumero = (int)$numero['id'];
    $nombre = coexBuscarNombreContacto($conexion,$idNumero,$cliente);

    // Mensaje originado manualmente desde WhatsApp Business App = intervención humana.
    $idConversacion = obtenerOCrearConversacion(
        $conexion,
        $idNumero,
        $cliente,
        $cliente,
        $nombre,
        'HUMANO'
    );

    $tipoOriginal = (string)($mensaje['type'] ?? 'text');
    $tipo = tipoMensajeBD($tipoOriginal);
    $contenido = obtenerContenidoMensaje($mensaje);
    [$mediaId,$mimeType] = extraerDatosMedia($mensaje,$tipoOriginal);
    $contextMessageId = (string)valorSeguro($mensaje,['context','id'],'');
    $fechaMensaje = convertirTimestampWhatsApp($mensaje['timestamp'] ?? '');
    $payloadJson = json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    $from = preg_replace('/\D+/', '', (string)($numero['telefono'] ?? $displayPhone)) ?? '';
    $to = $cliente;

    $sql = "INSERT INTO wa_mensajes (
                id_conversacion,id_numero,message_id,direccion,wa_from,wa_to,
                tipo,contenido,media_id,mime_type,context_message_id,
                estado_envio,fecha_mensaje,payload_json
            ) VALUES (
                ?,?,?,'SALIENTE',?,?,?,?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),'SENT',NULLIF(?,''),?
            )
            ON DUPLICATE KEY UPDATE message_id=VALUES(message_id)";
    $stmt = mysqli_prepare($conexion,$sql);
    if (!$stmt) return;

    mysqli_stmt_bind_param(
        $stmt,
        'iissssssssss',
        $idConversacion,$idNumero,$messageId,$from,$to,$tipo,$contenido,
        $mediaId,$mimeType,$contextMessageId,$fechaMensaje,$payloadJson
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function procesarEventoCoexistenceTopLevel(array $payload): void {
    $event = trim((string)($payload['event'] ?? ''));
    if (!in_array($event,['history','smb_app_state_sync'],true)) return;

    $data = $payload['data'] ?? [];
    if (!is_array($data)) return;

    $wabaId = trim((string)($data['id'] ?? ''));
    $metadata = $data['metadata'] ?? [];
    $phoneNumberId = is_array($metadata)
        ? trim((string)($metadata['phone_number_id'] ?? ''))
        : '';

    $conexion = obtenerConexionTalIA();
    if (!$conexion) return;

    $numero = $phoneNumberId !== ''
        ? buscarNumeroPorPhoneNumberId($conexion,$phoneNumberId)
        : null;
    $idNumero = $numero ? (int)$numero['id'] : null;

    coexGuardarEvento($conexion,$idNumero,$wabaId,$phoneNumberId,$event,$data);

    if (!$numero) {
        guardarLog(
            'db_events.log',
            "COEX_TOPLEVEL_NUMERO_NO_REGISTRADO\n"
            . "event={$event}\nphone_number_id={$phoneNumberId}\nwaba_id={$wabaId}"
        );
        return;
    }

    if ($event === 'smb_app_state_sync') {
        coexProcesarContactos($conexion,(int)$numero['id'],$data);
        @mysqli_query(
            $conexion,
            "UPDATE wa_meta_conexiones
             SET contactos_sync_estado='COMPLETADO', fecha_actualizacion=CURRENT_TIMESTAMP
             WHERE id_numero=" . (int)$numero['id']
        );
    }

    if ($event === 'history') {
        $terminado = false;
        $history = $data['history'] ?? [];
        if (is_array($history)) {
            foreach ($history as $chunk) {
                if (!is_array($chunk)) continue;
                $progress = strtoupper(trim((string)($chunk['metadata']['progress'] ?? '')));
                if (in_array($progress,['100','100%','COMPLETE','COMPLETED','DONE'],true)) {
                    $terminado = true;
                    break;
                }
            }
        }
        $estado = $terminado ? 'COMPLETADO' : 'RECIBIENDO';
        @mysqli_query(
            $conexion,
            "UPDATE wa_meta_conexiones
             SET historial_sync_estado='" . $estado . "', fecha_actualizacion=CURRENT_TIMESTAMP
             WHERE id_numero=" . (int)$numero['id']
        );
    }

    guardarLog(
        'db_events.log',
        "COEX_TOPLEVEL_RECIBIDO\nevent={$event}\nid_numero=" . (int)$numero['id']
        . "\nphone_number_id={$phoneNumberId}\nwaba_id={$wabaId}"
    );
}

function procesarEventoCoexistence(string $field, array $value, string $entryId): void {
    $permitidos = ['history','smb_app_state_sync','smb_message_echoes','account_update'];
    if (!in_array($field,$permitidos,true)) return;

    $conexion = obtenerConexionTalIA();
    if (!$conexion) return;

    $metadata = $value['metadata'] ?? [];
    $phoneNumberId = is_array($metadata) ? trim((string)($metadata['phone_number_id'] ?? '')) : '';
    $displayPhone = is_array($metadata) ? trim((string)($metadata['display_phone_number'] ?? '')) : '';

    $numero = $phoneNumberId !== '' ? buscarNumeroPorPhoneNumberId($conexion,$phoneNumberId) : null;
    $idNumero = $numero ? (int)$numero['id'] : null;

    coexGuardarEvento($conexion,$idNumero,$entryId,$phoneNumberId,$field,$value);

    if (!$numero) {
        guardarLog(
            'db_events.log',
            "COEX_EVENT_NUMERO_NO_REGISTRADO\nfield={$field}\nphone_number_id={$phoneNumberId}\nwaba_id={$entryId}"
        );
        return;
    }

    if ($field === 'smb_app_state_sync') {
        coexProcesarContactos($conexion,(int)$numero['id'],$value);
        @mysqli_query(
            $conexion,
            "UPDATE wa_meta_conexiones
             SET contactos_sync_estado='COMPLETADO', fecha_actualizacion=CURRENT_TIMESTAMP
             WHERE id_numero=" . (int)$numero['id']
        );
    }

    if ($field === 'smb_message_echoes') {
        $echoes = $value['message_echoes'] ?? $value['messages'] ?? [];
        if (is_array($echoes)) {
            foreach ($echoes as $echo) {
                if (is_array($echo)) {
                    coexGuardarEchoSaliente($conexion,$numero,$echo,$displayPhone);
                }
            }
        }
    }

    if ($field === 'history') {
        // Fase 1: conservamos el payload completo y marcamos recepción.
        // La importación de hasta 180 días se hará en una fase posterior para
        // evitar duplicar conversaciones históricas sin reglas de reconciliación.
        @mysqli_query(
            $conexion,
            "UPDATE wa_meta_conexiones
             SET historial_sync_estado='RECIBIENDO', fecha_actualizacion=CURRENT_TIMESTAMP
             WHERE id_numero=" . (int)$numero['id']
        );
    }

    guardarLog(
        'db_events.log',
        "COEX_EVENT_RECIBIDO\nfield={$field}\nid_numero=" . (int)$numero['id'] .
        "\nphone_number_id={$phoneNumberId}\nwaba_id={$entryId}"
    );
}
