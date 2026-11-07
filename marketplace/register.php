<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';

if (pf_user($pdo)) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $country = trim((string) ($_POST['country'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirmation'] ?? '');

    $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    $countryLength = function_exists('mb_strlen') ? mb_strlen($country) : strlen($country);

    if ($nameLength < 2 || $nameLength > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $countryLength > 100 || strlen($password) < 12 || $password !== $confirm) {
        $error = 'Check your details. Use a valid email, a name, and a password of at least 12 characters.';
    } else {
        try {
            if (!pf_auth_rate_allowed($pdo, 'REGISTER', $email, 5, 60)) {
                throw new RuntimeException('Too many registration attempts. Please wait before trying again.');
            }

            $columns = $pdo->query('SHOW FULL COLUMNS FROM users')->fetchAll();
            $columnMap = [];
            foreach ($columns as $column) {
                $columnMap[$column['Field']] = $column;
            }
            foreach (['id','role','name','email','email_verified','password_hash','country','status','referral_code'] as $required) {
                if (!isset($columnMap[$required])) {
                    throw new RuntimeException('Registration is temporarily unavailable while account compatibility is checked.');
                }
            }
            if (!str_contains((string) $columnMap['status']['Type'], "'PENDING'")
                || !str_contains((string) $columnMap['role']['Type'], "'WORKER'")) {
                throw new RuntimeException('Registration is temporarily unavailable. Please contact support.');
            }

            $token = bin2hex(random_bytes(32));
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('We could not secure your password. Please try again.');
            }

            $pdo->beginTransaction();
            try {
                $referralCode = '';
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $referralCode = 'PF' . strtoupper(bin2hex(random_bytes(5)));
                    $check = $pdo->prepare('SELECT 1 FROM users WHERE referral_code=? LIMIT 1');
                    $check->execute([$referralCode]);
                    if (!$check->fetchColumn()) break;
                    $referralCode = '';
                }
                if ($referralCode === '') {
                    throw new RuntimeException('Could not generate a unique account code. Please try again.');
                }

                $insert = $pdo->prepare(
                    "INSERT INTO users (role,name,email,email_verified,password_hash,country,status,referral_code)
                     VALUES ('WORKER',?,?,0,?,?,'PENDING',?)"
                );
                $insert->execute([$name,$email,$passwordHash,$country !== '' ? $country : null,$referralCode]);
                $userId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO pf_account_modes (user_id,can_work,can_advertise) VALUES (?,1,1)'
                )->execute([$userId]);
                $pdo->prepare("INSERT INTO pf_wallets (user_id,wallet_type,currency) VALUES (?,'WORKER','USD'),(?,'ADVERTISER','USD')")
                    ->execute([$userId,$userId]);
                $pdo->prepare(
                    'INSERT INTO pf_email_verifications (user_id,token_hash,expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
                )->execute([$userId,hash('sha256',$token)]);

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }

            $link = 'https://poketflow.com/verify-email.php?token=' . rawurlencode($token);
            $sent = pf_send_mail(
                $pdo,
                $email,
                'Verify your PoketFlow account',
                "Hello {$name},\n\nWelcome to PoketFlow. Verify your email address within 24 hours using this link:\n{$link}\n\nIf you did not create this account, you can ignore this email.\n\nPoketFlow Support"
            );
            $notice = $sent
                ? 'Your account has been created. Check your email and verify your address before signing in.'
                : 'Your account is saved but the verification email could not be sent. Use the resend-verification page or contact support.';
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'We could not create an account with those details. If you already have an account, sign in or reset your password.';
            } else {
                error_log('PoketFlow registration failed: ' . $exception->getMessage());
                $error = 'Registration is temporarily unavailable. Please try again later.';
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Registration is temporarily unavailable. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create account | PoketFlow</title>
<link rel="stylesheet" href="assets/marketplace.css">
</head>
<body class="auth-shell">
<main class="auth-card">
<a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a>
<p class="eyebrow">ONE ACCOUNT · TWO OPPORTUNITIES</p>
<h1>Create your account</h1>
<p class="muted">One PoketFlow login lets you earn from tasks and advertise to the community.</p>
<?php if ($error !== ''): ?><div class="alert"><?=pf_e($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="panel"><?=pf_e($notice)?><p><a href="resend-verification.php">Resend verification email</a></p></div><?php endif; ?>
<?php if ($notice === ''): ?>
<form method="post" action="">
<input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>">
<label for="name">Full name</label>
<input id="name" name="name" maxlength="100" required autocomplete="name" value="<?=pf_e((string)($_POST['name']??''))?>">
<label for="email">Email address</label>
<input id="email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?=pf_e((string)($_POST['email']??''))?>">
<label for="country">Country (optional)</label>
<input id="country" name="country" maxlength="100" autocomplete="country-name" value="<?=pf_e((string)($_POST['country']??''))?>">
<label for="password">Password</label>
<input id="password" name="password" type="password" minlength="12" required autocomplete="new-password">
<label for="password_confirmation">Confirm password</label>
<input id="password_confirmation" name="password_confirmation" type="password" minlength="12" required autocomplete="new-password">
<button class="button primary" type="submit">Create account</button>
</form>
<?php endif; ?>
<p class="muted small">Already a member? <a href="login.php">Sign in</a></p>
</main>
</body>
</html>
