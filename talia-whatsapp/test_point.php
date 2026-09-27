<?php

$url = 'https://regionsur.com.mx/plataforma/talia-whatsapp/webhook.php';

$data = [
    'prueba' => 'TalIA WhatsApp',
    'mensaje' => 'Hola Orión',
    'telefono' => '529990000000'
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = curl_exec($ch);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$error = curl_error($ch);

curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');

echo "HTTP CODE: " . $httpCode . PHP_EOL;
echo "RESPUESTA: " . $response . PHP_EOL;

if ($error) {
    echo "ERROR CURL: " . $error . PHP_EOL;
}