<?php
declare(strict_types=1);

/**
 * TalIA Connect WA
 * Prueba de idempotencia
 *
 * Envía DOS VECES el MISMO payload y el MISMO message_id
 * al webhook productivo.
 *
 * Resultado esperado:
 * - Ambas llamadas HTTP responden 200 / EVENT_RECEIVED
 * - wa_mensajes contiene SOLO 1 fila con ese message_id
 * - db_events.log registra primero MENSAJE_INSERTADO y luego MENSAJE_DUPLICADO
 */

date_default_timezone_set('America/Merida');

$webhookUrl = 'https://regionsur.com.mx/plataforma/talia-whatsapp/webhook.php';

$wabaId = '1632583318412883';
$phoneNumberId = '1364157906780191';
$displayPhoneNumber = '15551482909';

$clienteNombre = 'Cliente Prueba Idempotencia';
$clienteWaId = '5219990000002';

// Un SOLO message_id para las dos llamadas.
try {
    $random = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $random = (string)mt_rand(10000000, 99999999);
}

$messageId = 'TEST_IDEMP_' . date('Ymd_His') . '_' . $random;
$timestamp = (string)time();
$mensajeTexto = 'Prueba idempotencia TalIA Connect WA ' . date('Y-m-d H:i:s');

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
    die('ERROR JSON: ' . json_last_error_msg());
}

function enviarWebhook(string $url, string $json): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
            'User-Agent: TalIA-Connect-WA-Idempotency-Test/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => $response !== false ? $response : '',
        'curl_error' => $curlError,
    ];
}

// Envío #1
$envio1 = enviarWebhook($webhookUrl, $json);

// Pequeña pausa para distinguir eventos en log
usleep(400000);

// Envío #2: EXACTAMENTE EL MISMO JSON
$envio2 = enviarWebhook($webhookUrl, $json);

header('Content-Type: text/plain; charset=utf-8');

echo "TalIA Connect WA - Prueba de Idempotencia\n";
echo "=========================================\n\n";

echo "MESSAGE ID USADO EN AMBOS ENVIOS:\n";
echo $messageId . "\n\n";

echo "CLIENTE:\n";
echo $clienteNombre . " / " . $clienteWaId . "\n\n";

echo "ENVIO #1\n";
echo "--------\n";
echo "HTTP CODE: " . $envio1['http_code'] . "\n";
echo "RESPUESTA: " . $envio1['response'] . "\n";
if ($envio1['curl_error'] !== '') {
    echo "CURL ERROR: " . $envio1['curl_error'] . "\n";
}

echo "\nENVIO #2\n";
echo "--------\n";
echo "HTTP CODE: " . $envio2['http_code'] . "\n";
echo "RESPUESTA: " . $envio2['response'] . "\n";
if ($envio2['curl_error'] !== '') {
    echo "CURL ERROR: " . $envio2['curl_error'] . "\n";
}

echo "\nRESULTADO ESPERADO\n";
echo "------------------\n";
echo "Ambos envios deben responder 200 / EVENT_RECEIVED.\n";
echo "Pero MySQL debe tener UNA SOLA fila para este message_id.\n\n";

echo "VALIDA EN MYSQL:\n";
echo "SELECT COUNT(*) AS total\n";
echo "FROM wa_mensajes\n";
echo "WHERE message_id = '" . $messageId . "';\n\n";

echo "Esperado: total = 1\n\n";

echo "Tambien puedes revisar:\n";
echo "SELECT id, id_conversacion, id_numero, message_id, direccion, wa_from, wa_to, tipo, contenido, estado_envio, fecha_mensaje\n";
echo "FROM wa_mensajes\n";
echo "WHERE message_id = '" . $messageId . "';\n\n";

echo "Y en logs/db_events.log esperamos:\n";
echo "1) MENSAJE_INSERTADO\n";
echo "2) MENSAJE_DUPLICADO\n";
