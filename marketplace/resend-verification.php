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
            if (pf_auth_rate_allowed($pdo, 'VERIFY_RESEND', $email, 3, 60)) {
                $stmt = $pdo->prepare("SELECT id,name,email FROM users WHERE email=? AND status='PENDING' AND email_verified=0 LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE pf_email_verifications SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
                        ->execute([(int)$user['id']]);
                    $pdo->prepare(
                        'INSERT INTO pf_email_verifications (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))'
                    )->execute([(int)$user['id'],hash('sha256',$token)]);
                    $pdo->commit();
                    $link = 'https://poketflow.com/verify-email.php?token=' . rawurlencode($token);
                    pf_send_mail($pdo,$email,'Verify your PoketFlow account',
                        "Hello {$user['name']},\n\nUse this link within 24 hours to verify your PoketFlow email address:\n{$link}\n\nPoketFlow Support");
                }
            }
            $notice = 'If that address belongs to an account awaiting verification, a fresh verification link has been sent.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('PoketFlow verification resend failed: ' . $exception->getMessage());
            $notice = 'If that address belongs to an account awaiting verification, a fresh verification link will be sent when the service is available.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resend verification | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head>
<body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><p class="eyebrow">EMAIL VERIFICATION</p><h1>Resend verification link</h1><p class="muted">Enter the email address you used to register.</p>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><label for="email">Email address</label><input id="email" type="email" name="email" maxlength="190" required autocomplete="email"><button class="button primary" type="submit">Send verification email</button></form><p class="muted small"><a href="login.php">Back to sign in</a></p></main></body></html>
