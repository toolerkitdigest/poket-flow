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

/*
 * These are the exact POST field names shown in the user's CPAGrip
 * Multi Postback documentation: password, payout, offer_id, tracking_id.
 */
$trackingId = trim((string) ($_POST['tracking_id'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$offerId = trim((string) ($_POST['offer_id'] ?? ''));
$payoutRaw = trim((string) ($_POST['payout'] ?? ''));

$expectedPassword = (string) ($config['postback_password'] ?? '');
if ($expectedPassword === '' || str_starts_with($expectedPassword, 'CHANGE_')
    || !hash_equals($expectedPassword, $password)) {
    http_response_code(403);
    exit('Invalid postback credentials.');
}

// Global Postback can call this endpoint for other CPAGrip offers.
// Acknowledge but never unlock this Opera GX funnel for another offer.
if ($offerId !== (string) ($config['expected_offer_id'] ?? '')) {
    http_response_code(200);
    exit('Ignored: offer does not belong to this funnel.');
}

if (!preg_match('/^[a-f0-9]{32}$/i', $trackingId)) {
    http_response_code(400);
    exit('Invalid tracking ID.');
}
if ($payoutRaw === '' || !preg_match('/^\\d{1,9}(?:\\.\\d{1,4})?$/', $payoutRaw)) {
    http_response_code(400);
    exit('Invalid payout.');
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

    // CPAGrip may retry a callback. Repeated callbacks must not change the result.
    if ($click['status'] === 'approved') {
        $pdo->commit();
        http_response_code(200);
        exit('OK');
    }

    // The CPAGrip page documents no unique transaction-id POST field.
    // The offer + unique tracking ID pair is our idempotency key.
    $transactionKey = '39689:' . $trackingId;
    $update = $pdo->prepare(
        "UPDATE funnel_clicks
         SET status = 'approved', network_transaction_id = ?, payout = ?, converted_at = NOW()
         WHERE id = ?"
    );
    $update->execute([$transactionKey, $payoutRaw, $click['id']]);
    $pdo->commit();

    http_response_code(200);
    echo 'OK';
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
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
