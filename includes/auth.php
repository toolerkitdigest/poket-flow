<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| POKETFLOW AUTHENTICATION
|--------------------------------------------------------------------------
| Handles PHP sessions, database connection and user authentication.
|--------------------------------------------------------------------------
*/

// Private session storage directory.
$sessionPath = '/home/poketflo/private/sessions';

// Configure session storage BEFORE starting the session.
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

// Start the session only if it is not already active.
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!session_start()) {
        error_log('[PoketFlow Auth] Unable to start PHP session.');
    }
}

// Load database and shared functions.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';


/*
|--------------------------------------------------------------------------
| CHECK LOGIN STATUS
|--------------------------------------------------------------------------
*/

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}


/*
|--------------------------------------------------------------------------
| GET CURRENT USER
|--------------------------------------------------------------------------
*/

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
