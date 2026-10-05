<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo '<h2>PoketFlow CPAGrip Feed Test</h2>';

$configPath = '/home/u541027683/private/poketflow-cpagrip.php';

if (!file_exists($configPath)) {
    die('CPAGrip configuration file not found.');
}

$config = require $configPath;

if (!is_array($config)) {
    die('Invalid CPAGrip configuration.');
}

$userId = $config['user_id'];
$privateKey = $config['private_key'];

$ip = isset($_SERVER['REMOTE_ADDR'])
    ? $_SERVER['REMOTE_ADDR']
    : '';

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? $_SERVER['HTTP_USER_AGENT']
    : '';

$params = [
    'user_id' => $userId,
    'key' => $privateKey,
    'ip' => $ip,
    'ua' => $userAgent,
    'limit' => 20,
];

$url = 'https://www.cpagrip.com/common/offer_feed_json.php?'
    . http_build_query($params);

echo '<p>Requesting CPAGrip JSON feed...</p>';

$ch = curl_init();

curl_setopt_array(
    $ch,
    [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]
);

$response = curl_exec($ch);

$httpCode = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

$curlError = curl_error($ch);

curl_close($ch);

echo '<p>HTTP Status: <strong>'
    . htmlspecialchars((string) $httpCode, ENT_QUOTES, 'UTF-8')
    . '</strong></p>';

if ($response === false) {
    echo '<p style="color:red;">cURL request failed.</p>';

    echo '<pre>'
        . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8')
        . '</pre>';

    exit;
}

if ($curlError !== '') {
    echo '<p style="color:red;">cURL error:</p>';

    echo '<pre>'
        . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8')
        . '</pre>';

    exit;
}

echo '<p style="color:green;">CPAGrip responded.</p>';

echo '<h3>Raw CPAGrip Response</h3>';

echo '<pre style="
    white-space: pre-wrap;
    background: #111827;
    color: #f8fafc;
    padding: 20px;
    border-radius: 8px;
    overflow-x: auto;
">';

echo htmlspecialchars(
    $response,
    ENT_QUOTES,
    'UTF-8'
);

echo '</pre>';
