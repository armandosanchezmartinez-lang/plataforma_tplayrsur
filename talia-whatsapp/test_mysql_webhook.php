<?php
declare(strict_types=1);

/**
 * TalIA Connect WA
 * Prueba controlada: Webhook -> MySQL
 *
 * IMPORTANTE:
 * Cada vez que abras/recargues este archivo enviará un mensaje nuevo
 * al webhook productivo y, si todo está correcto, creará/actualizará:
 *   - wa_conversaciones
 *   - wa_mensajes
 */

date_default_timezone_set('America/Merida');

// =====================================================
// CONFIGURACIÓN DE PRUEBA
// =====================================================

$webhookUrl = 'https://regionsur.com.mx/plataforma/talia-whatsapp/webhook.php';

// Datos REALES del número de prueba registrado en wa_numeros.
$wabaId = '1632583318412883';
$phoneNumberId = '1364157906780191';
$displayPhoneNumber = '15551482909';

// Cliente ficticio para la prueba.
$clienteNombre = 'Cliente Prueba TalIA';
$clienteWaId = '5219990000001';

// Message ID único en cada ejecución para evitar choque con UNIQUE(message_id).
try {
    $random = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $random = (string)mt_rand(10000000, 99999999);
}

$messageId = 'TEST_TALIA_' . date('Ymd_His') . '_' . $random;
$timestamp = (string)time();
$mensajeTexto = 'Prueba TalIA Connect WA MySQL ' . date('Y-m-d H:i:s');

// =====================================================
// PAYLOAD SIMULADO CON ESTRUCTURA DE META
// =====================================================

$payload = [
    'object' => 'whatsapp_business_account',
    'entry' => [
        [
            'id' => $wabaId,
            'changes' => [
                [
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => $displayPhoneNumber,
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'contacts' => [
                            [
                                'profile' => [
                                    'name' => $clienteNombre,
                                ],
                                'wa_id' => $clienteWaId,
                            ],
                        ],
                        'messages' => [
                            [
                                'from' => $clienteWaId,
                                'id' => $messageId,
                                'timestamp' => $timestamp,
                                'type' => 'text',
                                'text' => [
                                    'body' => $mensajeTexto,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$json = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($json === false) {
    http_response_code(500);
    die('ERROR: No fue posible generar el JSON: ' . json_last_error_msg());
}

// =====================================================
// ENVÍO AL WEBHOOK
// =====================================================

$ch = curl_init($webhookUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json),
        'User-Agent: TalIA-Connect-WA-Test/1.0',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// =====================================================
// SALIDA VISIBLE
// =====================================================

header('Content-Type: text/plain; charset=utf-8');

echo "TalIA Connect WA - Prueba Webhook -> MySQL\n";
echo "==========================================\n\n";

echo "WEBHOOK: {$webhookUrl}\n";
echo "HTTP CODE: {$httpCode}\n";
echo "RESPUESTA: " . ($response !== false ? $response : '(sin respuesta)') . "\n";

if ($curlError !== '') {
    echo "CURL ERROR: {$curlError}\n";
}

echo "\nDATOS ENVIADOS\n";
echo "--------------\n";
echo "WABA ID: {$wabaId}\n";
echo "PHONE NUMBER ID: {$phoneNumberId}\n";
echo "DISPLAY PHONE NUMBER: {$displayPhoneNumber}\n";
echo "CLIENTE: {$clienteNombre}\n";
echo "WA ID CLIENTE: {$clienteWaId}\n";
echo "MESSAGE ID: {$messageId}\n";
echo "TIMESTAMP: {$timestamp}\n";
echo "MENSAJE: {$mensajeTexto}\n";

echo "\nRESULTADO ESPERADO\n";
echo "------------------\n";

if ($httpCode === 200 && trim((string)$response) === 'EVENT_RECEIVED') {
    echo "OK: El webhook recibió el evento.\n";
    echo "Ahora valida MySQL:\n\n";
    echo "SELECT * FROM wa_conversaciones ORDER BY id DESC LIMIT 5;\n";
    echo "SELECT * FROM wa_mensajes ORDER BY id DESC LIMIT 5;\n";
    echo "\nY revisa logs/db_events.log y logs/db_errors.log.\n";
} else {
    echo "ATENCION: La respuesta no fue la esperada.\n";
    echo "Revisa webhook.php y logs/db_errors.log.\n";
}

echo "\nNOTA: cada recarga genera un MESSAGE ID diferente y enviará otra prueba.\n";
