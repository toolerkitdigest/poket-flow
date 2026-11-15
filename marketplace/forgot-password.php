<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';

$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            if (pf_auth_rate_allowed($pdo, 'PASSWORD_RESET', $email, 3, 60)) {
                $stmt = $pdo->prepare("SELECT id,name,email FROM users WHERE LOWER(email)=? AND status='ACTIVE' LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE pf_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
                        ->execute([(int)$user['id']]);
                    $pdo->prepare(
                        'INSERT INTO pf_password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))'
                    )->execute([(int)$user['id'],hash('sha256',$token)]);
                    $pdo->commit();
                    $link = 'https://poketflow.com/reset-password.php?token=' . rawurlencode($token);
                    pf_send_mail($pdo,$email,'Reset your PoketFlow password',
                        "Hello {$user['name']},\n\nA password reset was requested for your PoketFlow account. Use this link within one hour:\n{$link}\n\nIf you did not request this, ignore this email. Your current password remains unchanged.\n\nPoketFlow Support");
                }
            }
            $notice = 'If an active account matches that address, the password-reset request has been processed. If email delivery is available, check your inbox shortly.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('PoketFlow password reset request failed: ' . $exception->getMessage());
            $notice = 'If an active account matches that address, the password-reset request will be processed when the service is available.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot password | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head>
<body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><p class="eyebrow">ACCOUNT RECOVERY</p><h1>Forgot your password?</h1><p class="muted">Enter your account email. We will send a one-time reset link if the account is active.</p>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><label for="email">Email address</label><input id="email" type="email" name="email" maxlength="190" required autocomplete="email"><button class="button primary" type="submit">Send reset link</button></form><p class="muted small"><a href="login.php">Back to sign in</a></p></main></body></html>
