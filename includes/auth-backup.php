<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';


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
        'SELECT id, role, name, email, country, status, referral_code
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$_SESSION['user_id']]);

    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}
