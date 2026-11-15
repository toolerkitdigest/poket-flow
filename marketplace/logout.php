<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
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
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign out | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head>
<body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><h1>Sign out?</h1><p class="muted">Are you sure you want to end this PoketFlow session?</p><form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><button class="button primary" type="submit">Yes, sign out</button></form><p class="muted small"><a href="dashboard.php">Stay signed in</a></p></main></body></html>
