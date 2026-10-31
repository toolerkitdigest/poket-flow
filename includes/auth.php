
text/x-generic auth.php ( PHP script, ASCII text )
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow Authentication
|--------------------------------------------------------------------------
| Uses MySQL database-backed sessions.
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Load Required Files
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database-session.php';


/*
|--------------------------------------------------------------------------
| Start Database Session
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '86400');

    $cookieParams = session_get_cookie_params();

    $secureCookie = (bool) ($cookieParams['secure'] ?? false);

    if (
        !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) {
        $secureCookie = true;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => ($cookieParams['path'] ?? '/') ?: '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $sessionHandler = new PoketFlowDatabaseSessionHandler($pdo);

    if (!session_set_save_handler($sessionHandler, true)) {
        error_log('[PoketFlow] Database session handler registration failed.');

        http_response_code(500);
        exit('Session service temporarily unavailable.');
    }

    if (!session_start()) {
        error_log('[PoketFlow] Database session start failed.');

        http_response_code(500);
        exit('Session service temporarily unavailable.');
    }
}


/*
|--------------------------------------------------------------------------
| Authentication Helpers
|--------------------------------------------------------------------------
*/

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}


function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}


function currentUser(PDO $pdo): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            id,
            role,
            name,
            email,
            country,
            status,
            referral_code
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([(int) $_SESSION['user_id']]);

    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}
