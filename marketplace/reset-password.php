<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';

$token = strtolower(trim((string) ($_GET['token'] ?? $_POST['token'] ?? '')));
$validToken = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$error = '';
$notice = '';
$valid = false;

if ($validToken) {
    $check = $pdo->prepare(
        'SELECT id,user_id FROM pf_password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1'
    );
    $check->execute([hash('sha256',$token)]);
    $valid = (bool) $check->fetch();
}
if (!$valid) {
    $error = 'This password-reset link is invalid, expired or already used.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    pf_verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirmation'] ?? '');
    if (strlen($password) < 12 || $password !== $confirm) {
        $error = 'Use a password of at least 12 characters and make sure both entries match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            $error = 'We could not secure your new password. Please try again.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT r.id,r.user_id FROM pf_password_resets r
                     JOIN users u ON u.id=r.user_id
                     WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>NOW() AND u.status='ACTIVE'
                     LIMIT 1 FOR UPDATE"
                );
                $stmt->execute([hash('sha256',$token)]);
                $record = $stmt->fetch();
                if (!$record) {
                    throw new RuntimeException('This password-reset link has expired or was already used.');
                }
                $update = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND status='ACTIVE'");
                $update->execute([$hash,(int)$record['user_id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The account password could not be updated.');
                }
                $pdo->prepare('UPDATE pf_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
                    ->execute([(int)$record['user_id']]);
                $pdo->commit();
                $notice = 'Your password has been updated. Sign in with your new password.';
                $valid = false;
                $error = '';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Password reset failed. Please request a fresh link.';
            }
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head>
<body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><p class="eyebrow">ACCOUNT RECOVERY</p><h1>Choose a new password</h1>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?><p><a href="login.php">Sign in</a></p></div><?php endif;?>
<?php if($valid && !$notice):?><form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="token" value="<?=pf_e($token)?>"><label for="password">New password</label><input id="password" type="password" name="password" minlength="12" required autocomplete="new-password"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"><button class="button primary" type="submit">Update password</button></form><?php endif;?>
</main></body></html>
