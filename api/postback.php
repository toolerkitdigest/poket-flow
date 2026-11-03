<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/plain; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| OGAds conversion postback
|--------------------------------------------------------------------------
| The affiliate click ID is sent to OGAds as aff_sub4 and returned to
| this endpoint. A database UNIQUE key on (network_id,
| external_transaction_id) is the final duplicate-callback safeguard.
|--------------------------------------------------------------------------
*/

$debugLog = '/home/poketflo/private/poketflow-postback-debug.log';

function pfPostbackDebug(string $message, array $context = []): void
{
    global $debugLog;

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'event' => $message,
        'context' => $context,
    ];

    $json = json_encode(
        $entry,
        JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ($json !== false) {
        error_log($json . PHP_EOL, 3, $debugLog);
    }
}

function pfIsDuplicateKey(PDOException $exception): bool
{
    $errorInfo = $exception->errorInfo ?? [];

    return (string) $exception->getCode() === '23000'
        && isset($errorInfo[1])
        && (int) $errorInfo[1] === 1062;
}

pfPostbackDebug('POSTBACK_REACHED', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'pdo_available' => isset($pdo) && $pdo instanceof PDO,
    'parameters_present' => [
        'offer_id' => isset($_GET['offer_id']),
        'payout' => isset($_GET['payout']),
        'aff_sub4' => isset($_GET['aff_sub4']),
        'datetime' => isset($_GET['datetime']),
    ],
]);

// Accept only scalar callback values.
$offerIdValue = $_GET['offer_id'] ?? '';
$payoutValue = $_GET['payout'] ?? '';
$trackingIdValue = $_GET['aff_sub4'] ?? '';
$conversionDateTimeValue = $_GET['datetime'] ?? '';

if (
    !is_scalar($offerIdValue) ||
    !is_scalar($payoutValue) ||
    !is_scalar($trackingIdValue) ||
    !is_scalar($conversionDateTimeValue)
) {
    http_response_code(400);
    exit('Invalid postback.');
}

$offerId = trim((string) $offerIdValue);
$payoutText = trim((string) $payoutValue);
$trackingId = trim((string) $trackingIdValue);
$conversionDateTime = trim((string) $conversionDateTimeValue);

if (
    $offerId === '' ||
    !ctype_digit($offerId) ||
    (int) $offerId < 1 ||
    $payoutText === '' ||
    !is_numeric($payoutText) ||
    $trackingId === '' ||
    !preg_match('/^[a-f0-9]{32}$/i', $trackingId)
) {
    http_response_code(400);
    exit('Invalid postback.');
}

$payout = (float) $payoutText;

if (
    !is_finite($payout) ||
    $payout <= 0 ||
    $payout > 9999999999.99
) {
    http_response_code(400);
    exit('Invalid payout.');
}

try {
    // Require the configured OGAds network; never create a NULL network ID.
    $networkStmt = $pdo->prepare(
        "SELECT id
         FROM networks
         WHERE slug = 'ogads'
           AND status = 'ACTIVE'
         LIMIT 1"
    );
    $networkStmt->execute();
    $networkIdValue = $networkStmt->fetchColumn();

    if ($networkIdValue === false) {
        pfPostbackDebug('OGADS_NETWORK_UNAVAILABLE');
        http_response_code(503);
        exit('Offer network unavailable.');
    }

    $networkId = (int) $networkIdValue;

    // Resolve the click and verify that the callback offer matches the
    // exact OGAds campaign to which that click was originally sent.
    $clickStmt = $pdo->prepare(
        "SELECT
            cc.id AS click_id,
            cc.campaign_id,
            cc.worker_id,
            cc.tracking_id,
            c.external_offer_id
         FROM campaign_clicks cc
         INNER JOIN campaigns c
            ON c.id = cc.campaign_id
         INNER JOIN networks n
            ON n.id = c.network_id
         WHERE cc.tracking_id = ?
           AND c.network_id = ?
           AND c.source_type = 'CPA_NETWORK'
           AND n.slug = 'ogads'
         LIMIT 1"
    );
    $clickStmt->execute([
        $trackingId,
        $networkId,
    ]);

    $click = $clickStmt->fetch(PDO::FETCH_ASSOC);

    if (!$click) {
        pfPostbackDebug('TRACKING_ID_NOT_FOUND', [
            'network_id' => $networkId,
        ]);
        http_response_code(404);
        exit('Tracking ID not found.');
    }

    if (
        (string) $click['external_offer_id'] !== $offerId
    ) {
        pfPostbackDebug('OFFER_ID_MISMATCH', [
            'network_id' => $networkId,
            'campaign_id' => (int) $click['campaign_id'],
        ]);
        http_response_code(400);
        exit('Offer does not match tracking record.');
    }

    $campaignId = (int) $click['campaign_id'];
    $workerId = (int) $click['worker_id'];

    // Fast path for ordinary retries. The UNIQUE database key below is
    // still required to protect against simultaneous callbacks.
    $existingStmt = $pdo->prepare(
        "SELECT id
         FROM conversions
         WHERE network_id = ?
           AND external_transaction_id = ?
         LIMIT 1"
    );
    $existingStmt->execute([
        $networkId,
        $trackingId,
    ]);

    if ($existingStmt->fetchColumn() !== false) {
        pfPostbackDebug('DUPLICATE_CALLBACK_IGNORED', [
            'network_id' => $networkId,
        ]);
        http_response_code(200);
        exit('OK');
    }

    // PoketFlow reward split: worker 40%, platform 60%.
    $rewardRate = 40.00;
    $workerReward = round($payout * ($rewardRate / 100), 2);
    $platformMargin = round($payout - $workerReward, 2);

    $convertedAt = null;

    if ($conversionDateTime !== '') {
        $timestamp = strtotime($conversionDateTime);

        if ($timestamp !== false) {
            $convertedAt = date('Y-m-d H:i:s', $timestamp);
        }
    }

    if ($convertedAt === null) {
        $convertedAt = date('Y-m-d H:i:s');
    }

    $pdo->beginTransaction();

    // Serialize balance snapshots for simultaneous rewards to this worker.
    $workerStmt = $pdo->prepare(
        'SELECT id FROM users WHERE id = ? FOR UPDATE'
    );
    $workerStmt->execute([$workerId]);

    if ($workerStmt->fetchColumn() === false) {
        throw new RuntimeException('Click owner no longer exists.');
    }

    $conversionStmt = $pdo->prepare(
        "INSERT INTO conversions (
            campaign_id,
            worker_id,
            network_id,
            external_transaction_id,
            network_payout,
            reward_rate,
            worker_reward,
            platform_margin,
            status,
            converted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'APPROVED', ?)"
    );

    $conversionStmt->execute([
        $campaignId,
        $workerId,
        $networkId,
        $trackingId,
        $payout,
        $rewardRate,
        $workerReward,
        $platformMargin,
        $convertedAt,
    ]);

    $conversionId = (int) $pdo->lastInsertId();

    $balanceStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM wallet_transactions
         WHERE user_id = ?
           AND status = 'COMPLETED'"
    );
    $balanceStmt->execute([$workerId]);

    $balanceBefore = (float) $balanceStmt->fetchColumn();
    $balanceAfter = round($balanceBefore + $workerReward, 2);

    $walletStmt = $pdo->prepare(
        "INSERT INTO wallet_transactions (
            user_id,
            type,
            reference_type,
            reference_id,
            amount,
            currency,
            balance_before,
            balance_after,
            status,
            description
        ) VALUES (
            ?,
            'OFFER_REWARD',
            'CONVERSION',
            ?,
            ?,
            'USD',
            ?,
            ?,
            'COMPLETED',
            ?
        )"
    );

    $walletStmt->execute([
        $workerId,
        $conversionId,
        $workerReward,
        $balanceBefore,
        $balanceAfter,
        'Reward for completing OGAds offer #' . $offerId,
    ]);

    $pdo->commit();

    pfPostbackDebug('CONVERSION_CREDITED', [
        'network_id' => $networkId,
        'campaign_id' => $campaignId,
        'conversion_id' => $conversionId,
        'worker_id' => $workerId,
    ]);

    http_response_code(200);
    echo 'OK';
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // A simultaneous callback may pass the fast duplicate check.
    // The unique key rejects the second insert; acknowledge it safely.
    if (pfIsDuplicateKey($e)) {
        pfPostbackDebug('DUPLICATE_CALLBACK_BLOCKED_BY_UNIQUE_KEY');
        http_response_code(200);
        exit('OK');
    }

    pfPostbackDebug('POSTBACK_DATABASE_ERROR', [
        'sqlstate' => (string) $e->getCode(),
    ]);

    error_log('PoketFlow OGAds postback database error.');
    http_response_code(500);
    exit('Postback processing failed.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    pfPostbackDebug('POSTBACK_PROCESSING_ERROR', [
        'error_type' => get_class($e),
    ]);

    error_log('PoketFlow OGAds postback processing error.');
    http_response_code(500);
    exit('Postback processing failed.');
}
