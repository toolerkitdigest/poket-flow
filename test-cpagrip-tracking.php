<?php

declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

$configPath = '/home/u541027683/private/poketflow-cpagrip.php';

if (!file_exists($configPath)) {
    die('CPAGrip configuration not found.');
}

$config = require $configPath;

$ip = isset($_SERVER['REMOTE_ADDR'])
    ? $_SERVER['REMOTE_ADDR']
    : '';

$ua = isset($_SERVER['HTTP_USER_AGENT'])
    ? $_SERVER['HTTP_USER_AGENT']
    : '';

$trackingId = !empty($_SESSION['user_id'])
    ? (string) $_SESSION['user_id']
    : '';

$params = [
    'user_id' => $config['user_id'],
    'key' => $config['private_key'],
    'ip' => $ip,
    'ua' => $ua,
    'limit' => 20,
];

if ($trackingId !== '') {
    $params['tracking_id'] = $trackingId;
}

$url =
    'https://www.cpagrip.com/common/offer_feed_json.php?'
    . http_build_query($params);

$ch = curl_init();

curl_setopt_array(
    $ch,
    [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]
);

$response = curl_exec($ch);

$httpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

curl_close($ch);

$data = json_decode(
    (string) $response,
    true
);

echo '<h2>CPAGrip Tracking Verification</h2>';

echo '<p><strong>PoketFlow User ID:</strong> '
    . htmlspecialchars(
        $trackingId,
        ENT_QUOTES,
        'UTF-8'
    )
    . '</p>';

echo '<p><strong>HTTP Status:</strong> '
    . (int) $httpCode
    . '</p>';

echo '<hr>';

if (isset($data['general']) && is_array($data['general'])) {

    foreach ($data['general'] as $item) {

        if (!is_array($item)) {
            continue;
        }

        foreach ($item as $key => $value) {

            if (
                $key === 'tracking_id_found' ||
                $key === 'tracking_id' ||
                $key === 'country_code' ||
                $key === 'country_detection_status' ||
                $key === 'ua_ismobile'
            ) {

                echo '<p><strong>'
                    . htmlspecialchars(
                        $key,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . ':</strong> '
                    . htmlspecialchars(
                        (string) $value,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '</p>';
            }
        }
    }

} else {

    echo '<p style="color:red;">';
    echo 'CPAGrip did not return the expected general information.';
    echo '</p>';
}
