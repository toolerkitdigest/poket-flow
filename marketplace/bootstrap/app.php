<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/blog-sync.php';

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

/**
 * Require one of the two independent capabilities on a unified member account.
 * Legacy advertiser suspensions/rejections remain enforced without blocking
 * the same member from using worker mode.
 */
function pf_require_mode(PDO $pdo, string $mode): array
{
    $user = pf_require_login($pdo);
    $column = match ($mode) {
        'worker' => 'can_work',
        'advertiser' => 'can_advertise',
        default => throw new InvalidArgumentException('Unknown account mode.'),
    };

    $stmt = $pdo->prepare("SELECT {$column} FROM pf_account_modes WHERE user_id=? LIMIT 1");
    $stmt->execute([(int)$user['id']]);
    $allowed = $stmt->fetchColumn();
    if ($allowed === false || (int)$allowed !== 1) {
        http_response_code(403);
        exit('This account mode is not enabled for your member account.');
    }

    if ($mode === 'advertiser' && !pf_legacy_advertiser_access_allowed($pdo, (int) $user['id'])) {
        http_response_code(403);
        exit('Advertiser access is restricted. Contact PoketFlow support if you believe this is an error.');
    }

    return $user;
}

function pf_require_worker(PDO $pdo): array
{
    return pf_require_mode($pdo, 'worker');
}

function pf_require_advertiser(PDO $pdo): array
{
    return pf_require_mode($pdo, 'advertiser');
}

function pf_require_admin(PDO $pdo): array
{
    $user = pf_require_login($pdo);
    if (strtoupper((string) ($user['role'] ?? '')) !== 'ADMIN') {
        http_response_code(403);
        exit('Administrator access required.');
    }
    return $user;
}
