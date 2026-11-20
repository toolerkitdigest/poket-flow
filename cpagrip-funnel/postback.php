<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Not configured.');
}
$config = require $configFile;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('POST required.');
}

$trackingId = trim((string) ($_POST['tracking_id'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$payoutRaw = trim((string) ($_POST['payout'] ?? '0'));
$transactionId = trim((string) ($_POST['transaction_id'] ?? ''));

if (!hash_equals((string) $config['postback_password'], $password)) {
    http_response_code(403);
    exit('Invalid postback credentials.');
}
if (!preg_match('/^[a-f0-9]{32}$/i', $trackingId)) {
    http_response_code(400);
    exit('Invalid tracking ID.');
}
if ($payoutRaw !== '' && !preg_match('/^\d{1,9}(?:\.\d{1,4})?$/', $payoutRaw)) {
    http_response_code(400);
    exit('Invalid payout.');
}
if ($transactionId === '') {
    $transactionId = 'click-' . $trackingId;
}
if (strlen($transactionId) > 191) {
    http_response_code(400);
    exit('Invalid transaction ID.');
}

try {
    $d = $config['db'];
    $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id, status FROM funnel_clicks WHERE tracking_id = ? FOR UPDATE');
    $find->execute([$trackingId]);
    $click = $find->fetch();

    if (!$click) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Unknown tracking ID.');
    }
    if ($click['status'] === 'approved') {
        $pdo->commit();
        http_response_code(200);
        exit('OK');
    }

    $update = $pdo->prepare(
        "UPDATE funnel_clicks
         SET status = 'approved', network_transaction_id = ?, payout = ?, converted_at = NOW()
         WHERE id = ?"
    );
    $update->execute([$transactionId, $payoutRaw === '' ? null : $payoutRaw, $click['id']]);
    $pdo->commit();

    http_response_code(200);
    echo 'OK';
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // A duplicate provider transaction can be retried safely.
    if ((string) $e->getCode() === '23000') {
        http_response_code(200);
        exit('OK');
    }
    error_log('Opera GX postback database error: ' . $e->getCode());
    http_response_code(500);
    exit('Processing error.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Opera GX postback error: ' . get_class($e));
    http_response_code(500);
    exit('Processing error.');
}
