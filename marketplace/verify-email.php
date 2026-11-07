<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';

$verified = false;
$error = '';
$token = strtolower(trim((string) ($_GET['token'] ?? '')));
if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    $error = 'This verification link is invalid or incomplete.';
} else {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT v.id,v.user_id,u.status,u.email_verified
             FROM pf_email_verifications v JOIN users u ON u.id=v.user_id
             WHERE v.token_hash=? AND v.used_at IS NULL AND v.expires_at>NOW()
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([hash('sha256',$token)]);
        $record = $stmt->fetch();
        if (!$record || $record['status'] !== 'PENDING' || (int)$record['email_verified'] !== 0) {
            throw new RuntimeException('This verification link has expired, was already used, or the account is not awaiting verification.');
        }
        $userUpdate = $pdo->prepare(
            "UPDATE users SET email_verified=1,status='ACTIVE' WHERE id=? AND status='PENDING' AND email_verified=0"
        );
        $userUpdate->execute([(int)$record['user_id']]);
        if ($userUpdate->rowCount() !== 1) {
            throw new RuntimeException('The account could not be activated. Please contact support.');
        }
        $pdo->prepare('UPDATE pf_email_verifications SET used_at=NOW() WHERE id=? AND used_at IS NULL')
            ->execute([(int)$record['id']]);
        $pdo->commit();
        $verified = true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Verification is temporarily unavailable. Please try again.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email verification | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head>
<body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a>
<?php if($verified): ?><p class="eyebrow">EMAIL VERIFIED</p><h1>Your account is ready</h1><p class="muted">Your email address has been verified. You can now sign in and use Earn mode and Advertise mode.</p><a class="button primary" href="login.php">Sign in</a>
<?php else: ?><p class="eyebrow">VERIFICATION NEEDED</p><h1>We could not verify this link</h1><div class="alert"><?=pf_e($error)?></div><p class="muted">Request a fresh verification email and try again.</p><a class="button primary" href="resend-verification.php">Resend verification</a><?php endif; ?>
</main></body></html>
