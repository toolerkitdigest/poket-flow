<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, [
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
