<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/blog-sync.php';
require_once __DIR__ . '/site-ads.php';
require_once __DIR__ . '/payments.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_trans_sid','0');
    session_set_cookie_params([
        'lifetime'=>0,'path'=>'/',
        'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
        'httponly'=>true,'samesite'=>'Lax',
    ]);
    session_start();
}

function pf_e(?string $value): string
{
    return htmlspecialchars($value ?? '',ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
}
function pf_csrf_token(): string
{
    if (empty($_SESSION['pf_csrf'])) $_SESSION['pf_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['pf_csrf'];
}
function pf_verify_csrf(): void
{
    $sent=(string)($_POST['_token']??''); $known=(string)($_SESSION['pf_csrf']??'');
    if ($sent==='' || $known==='' || !hash_equals($known,$sent)) {
        http_response_code(419); exit('Your session expired. Please refresh and try again.');
    }
}
function pf_user(PDO $pdo): ?array
{
    if (empty($_SESSION['pf_user_id'])) return null;
    $stmt=$pdo->prepare('SELECT id,name,email,country,status,role,session_version FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['pf_user_id']]); $user=$stmt->fetch();
    if (!$user || strtoupper((string)$user['status'])!=='ACTIVE') {
        unset($_SESSION['pf_user_id'],$_SESSION['pf_session_version']); return null;
    }
    $version=(int)($_SESSION['pf_session_version']??1);
    if ($version!==(int)$user['session_version']) {
        unset($_SESSION['pf_user_id'],$_SESSION['pf_session_version']); return null;
    }
    return $user;
}
function pf_require_login(PDO $pdo): array
{
    $user=pf_user($pdo);
    if (!$user) { header('Location: login.php'); exit; }
    return $user;
}
function pf_require_mode(PDO $pdo,string $mode): array
{
    $user=pf_require_login($pdo);
    if ($mode!=='advertiser') {
        http_response_code(410);
        exit('Worker tasks, submissions and reward features are no longer offered by PoketFlow.');
    }
    $stmt=$pdo->prepare('SELECT can_advertise FROM pf_account_modes WHERE user_id=? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    if ((int)$stmt->fetchColumn()!==1) {
        http_response_code(403); exit('Advertiser access is not enabled for this account.');
    }
    if (!pf_legacy_advertiser_access_allowed($pdo,(int)$user['id'])) {
        http_response_code(403); exit('Advertiser access is restricted. Contact PoketFlow support.');
    }
    $profile=$pdo->prepare('SELECT status FROM pf_advertiser_profiles WHERE user_id=? LIMIT 1');
    $profile->execute([(int)$user['id']]);
    $status=strtoupper((string)($profile->fetchColumn()?:''));
    if ($status!=='ACTIVE') {
        http_response_code(403);
        exit($status==='PENDING'
            ? 'Your advertiser profile is awaiting administrator approval.'
            : 'Advertiser access is not currently active. Contact PoketFlow support.');
    }
    return $user;
}
function pf_require_worker(PDO $pdo): array
{
    http_response_code(410);
    exit('Worker tasks, submissions and reward features are no longer offered by PoketFlow.');
}
function pf_require_advertiser(PDO $pdo): array { return pf_require_mode($pdo,'advertiser'); }
function pf_require_admin(PDO $pdo): array
{
    $user=pf_require_login($pdo);
    if (strtoupper((string)($user['role']??''))!=='ADMIN') { http_response_code(403); exit('Administrator access required.'); }
    return $user;
}
