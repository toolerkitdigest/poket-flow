<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Setup is not complete.');
}
$config = require $configFile;

session_name('gx_offer_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$trackingId = (string) ($_SESSION['gx_tracking_id'] ?? '');
$approved = false;

if (preg_match('/^[a-f0-9]{32}$/i', $trackingId)) {
    try {
        $d = $config['db'];
        $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $stmt = $pdo->prepare('SELECT status, session_hash FROM funnel_clicks WHERE tracking_id = ? LIMIT 1');
        $stmt->execute([$trackingId]);
        $click = $stmt->fetch();
        $approved = $click
            && $click['status'] === 'approved'
            && hash_equals((string) $click['session_hash'], hash('sha256', session_id()));
    } catch (Throwable $e) {
        error_log('Opera GX success check failed: ' . get_class($e));
    }
}

http_response_code($approved ? 200 : 403);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= $approved ? 'Access Unlocked' : 'Offer Verification Pending' ?> — Opera GX</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main class="page-shell"><section class="notice">
<?php if ($approved): ?>
  <p class="eyebrow">CONVERSION VERIFIED</p><h1>You're all set.</h1>
  <p>The offer provider has confirmed your qualifying completion. Your access is now unlocked.</p>
  <a class="cta" href="https://www.opera.com/gx" rel="nofollow noopener" target="_blank">VISIT OPERA GX <span>↗</span></a>
<?php else: ?>
  <p class="eyebrow">WAITING FOR CONFIRMATION</p><h1>We're checking your completion.</h1>
  <p>This page only unlocks after CPAGrip confirms the qualifying action. If you have just completed the offer, allow a short time for the confirmation to arrive, then refresh this page.</p>
  <a class="cta" href="success.php">CHECK AGAIN <span>↻</span></a>
  <p class="fineprint">If you have not completed the offer, return to the offer page and follow its instructions.</p>
<?php endif; ?>
</section></main></body></html>
