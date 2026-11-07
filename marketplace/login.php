<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';

if (pf_user($pdo)) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            if (!pf_auth_rate_allowed($pdo, 'LOGIN', $email, 10, 15)) {
                $error = 'Too many sign-in attempts. Please wait 15 minutes before trying again.';
            } else {
                $stmt = $pdo->prepare('SELECT id, password_hash, status, session_version FROM users WHERE LOWER(email) = ? LIMIT 1');
                $stmt->execute([$email]);
                $record = $stmt->fetch();

                if (!$record || !password_verify($password, (string) $record['password_hash'])) {
                    $error = 'Email address or password is incorrect.';
                } elseif (strtoupper((string) $record['status']) !== 'ACTIVE') {
                    $error = 'This account is not currently active. Please contact support.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['pf_user_id'] = (int) $record['id'];
                    $_SESSION['pf_session_version'] = (int) $record['session_version'];
                    $_SESSION['pf_csrf'] = bin2hex(random_bytes(32));
                    header('Location: dashboard.php');
                    exit;
                }
            }
        } catch (Throwable $exception) {
            error_log('PoketFlow sign-in security check failed: ' . $exception->getMessage());
            $error = 'Sign-in is temporarily unavailable. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in | PoketFlow</title>
<link rel="stylesheet" href="assets/marketplace.css">
</head>
<body class="auth-shell">
<main class="auth-card">
<a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a>
<p class="eyebrow">ONE ACCOUNT. TWO WAYS TO GROW.</p>
<h1>Welcome back</h1>
<p class="muted">Sign in to earn rewards or manage your advertising campaigns.</p>
<?php if ($error !== ''): ?><div class="alert"><?= pf_e($error) ?></div><?php endif; ?>
<form method="post" action="">
<input type="hidden" name="_token" value="<?= pf_e(pf_csrf_token()) ?>">
<label for="email">Email address</label>
<input id="email" name="email" type="email" autocomplete="username" required value="<?= pf_e((string)($_POST['email'] ?? '')) ?>">
<label for="password">Password</label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
<button class="button primary" type="submit">Sign in</button>
</form>
<p class="muted small"><a href="forgot-password.php">Forgot your password?</a></p>
<p class="muted small">New to PoketFlow? <a href="register.php">Create an account</a>.</p>
<p class="muted small">Your existing PoketFlow account and password are used. No new account is created.</p>
</main>
</body>
</html>
