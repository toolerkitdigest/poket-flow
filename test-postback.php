<?php

$url = 'https://poketflow.com/postback.php';

$data = [
    'offer_id' => '70075',
    'tracking_id' => 'f2e0a828f8d4208cbd031952d84de88d',
    'payout' => '0.17',
];

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo 'cURL Error: ' . curl_error($ch);
} else {
    echo 'HTTP Code: ' . $httpCode . '<br>';
    echo 'Response: ' . htmlspecialchars($response, ENT_QUOTES, 'UTF-8');
}

curl_close($ch);
