<?php
declare(strict_types=1);

$configPath = getenv('POKETFLOW_CONFIG');
if (!$configPath || !is_file($configPath)) {
    throw new RuntimeException('Test database configuration is missing.');
}
$config = require $configPath;
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['dbname'],
        $config['charset'] ?? 'utf8mb4'
    ),
    $config['username'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
require_once dirname(__DIR__) . '/marketplace/bootstrap/payments.php';

$reference = 'PF-PAYPAL-IDEMPOTENCY-' . strtoupper(bin2hex(random_bytes(5)));
$providerOrderId = 'PAYPAL-ORDER-' . strtoupper(bin2hex(random_bytes(5)));
$insert = $pdo->prepare(
    "INSERT INTO pf_payment_orders
        (advertiser_id,provider,provider_reference,amount,currency,status,provider_order_id)
     VALUES (1,'PAYPAL',?,7.50,'USD','PENDING',?)"
);
$insert->execute([$reference, $providerOrderId]);
$orderId = (int) $pdo->lastInsertId();

if (!pf_credit_payment_order($pdo, $orderId, 'PAYPAL-CAPTURE-ID-TEST')) {
    throw new RuntimeException('First verified payment credit did not complete.');
}
if (!pf_credit_payment_order($pdo, $orderId, 'PAYPAL-CAPTURE-ID-TEST')) {
    throw new RuntimeException('Repeated payment credit was not idempotent.');
}

$stmt = $pdo->prepare(
    'SELECT status,provider_order_id,provider_transaction_id
     FROM pf_payment_orders WHERE id=?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (
    !$order ||
    $order['status'] !== 'PAID' ||
    $order['provider_order_id'] !== $providerOrderId ||
    $order['provider_transaction_id'] !== 'PAYPAL-CAPTURE-ID-TEST'
) {
    throw new RuntimeException('PayPal order ID and capture ID were not preserved separately.');
}

$ledger = $pdo->prepare(
    "SELECT COUNT(*) FROM pf_wallet_ledger
     WHERE reference_type='PAYMENT_ORDER' AND reference_id=?"
);
$ledger->execute([$orderId]);
if ((int) $ledger->fetchColumn() !== 1) {
    throw new RuntimeException('Repeated PayPal confirmation created duplicate wallet credits.');
}

$emptyReference = 'PF-PAYPAL-EMPTY-' . strtoupper(bin2hex(random_bytes(5)));
$insert->execute([$emptyReference, 'PAYPAL-EMPTY-' . strtoupper(bin2hex(random_bytes(5)))]);
$emptyOrderId = (int) $pdo->lastInsertId();
$emptyRejected = false;
try {
    pf_credit_payment_order($pdo, $emptyOrderId, '');
} catch (RuntimeException $exception) {
    $emptyRejected = true;
}
if (!$emptyRejected) {
    throw new RuntimeException('An empty provider transaction ID was accepted.');
}

$pending = $pdo->prepare("SELECT status FROM pf_payment_orders WHERE id=?");
$pending->execute([$emptyOrderId]);
if ($pending->fetchColumn() !== 'PENDING') {
    throw new RuntimeException('Rejected empty transaction changed payment order state.');
}

echo "PayPal order/capture separation and duplicate-credit idempotency checks passed.\n";
