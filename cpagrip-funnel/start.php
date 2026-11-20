<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Setup is not complete. Please configure the database first.');
}
$config = require $configFile;

session_name('gx_offer_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function db(array $config): PDO {
    $d = $config['db'];
    $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
    return new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

try {
    $pdo = db($config);
    $trackingId = bin2hex(random_bytes(16));
    $sessionHash = hash('sha256', session_id());

    $stmt = $pdo->prepare('INSERT INTO funnel_clicks (tracking_id, session_hash) VALUES (?, ?)');
    $stmt->execute([$trackingId, $sessionHash]);

    $_SESSION['gx_tracking_id'] = $trackingId;

    $url = (string) $config['offer_url'];
    $parameter = (string) ($config['tracking_parameter'] ?? 'tracking_id');
    if (!preg_match('/^[a-zA-Z0-9_]{1,40}$/', $parameter)) {
        throw new RuntimeException('Invalid tracking parameter configuration.');
    }

    $fragment = '';
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }
    $separator = str_contains($url, '?') ? '&' : '?';
    $redirect = $url . $separator . rawurlencode($parameter) . '=' . rawurlencode($trackingId) . $fragment;

    header('Cache-Control: no-store');
    header('Location: ' . $redirect, true, 302);
    exit;
} catch (Throwable $e) {
    error_log('Opera GX funnel start failed: ' . get_class($e));
    http_response_code(500);
    exit('We could not start the offer right now. Please try again later.');
}
