<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function pf_e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pf_csrf_token(): string
{
    if (empty($_SESSION['pf_csrf'])) {
        $_SESSION['pf_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pf_csrf'];
}

function pf_verify_csrf(): void
{
    $sent = (string) ($_POST['_token'] ?? '');
    $known = (string) ($_SESSION['pf_csrf'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh and try again.');
    }
}

function pf_user(PDO $pdo): ?array
{
    if (empty($_SESSION['pf_user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, email, country, status, role
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['pf_user_id']]);
    $user = $stmt->fetch();

    if (!$user || strtoupper((string) $user['status']) !== 'ACTIVE') {
        unset($_SESSION['pf_user_id']);
        return null;
    }
    return $user;
}

function pf_require_login(PDO $pdo): array
{
    $user = pf_user($pdo);
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}
