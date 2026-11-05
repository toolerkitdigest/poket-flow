<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow OGAds conversion postback
|--------------------------------------------------------------------------
| Uses the existing database bootstrap without starting a worker session.
| The database UNIQUE key on (network_id, external_transaction_id) remains
| the final protection against duplicate rewards.
|--------------------------------------------------------------------------
*/

header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../includes/database.php';

$debugLog = '/home/poketflo/private/poketflow-postback-debug.log';

function pfPostbackDebug(string $event, array $context = []): void
{
    global $debugLog;

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'event' => $event,
        'context' => $context,
    ];

    $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

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

function pfPostbackFail(int $status, string $message): never
{
    http_response_code($status);
    exit($message);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    pfPostbackFail(405, 'Method not allowed.');
}

$offerIdValue = $_GET['offer_id'] ?? '';
$payoutValue = $_GET['payout'] ?? '';
$trackingIdValue = $_GET['aff_sub4'] ?? '';
$dateTimeValue = $_GET['datetime'] ?? '';

if (
    !is_scalar($offerIdValue) ||
    !is_scalar($payoutValue) ||
    !is_scalar($trackingIdValue) ||
    !is_scalar($dateTimeValue)
) {
    pfPostbackFail(400, 'Invalid postback.');
}

$offerId = trim((string) $offerIdValue);
$payoutText = trim((string) $payoutValue);
$trackingId = trim((string) $trackingIdValue);
$conversionDateTime = trim((string) $dateTimeValue);

if (
    !preg_match('/^[1-9][0-9]*$/D', $offerId) ||
    !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $payoutText) ||
    !preg_match('/^[a-f0-9]{32}$/iD', $trackingId)
) {
    pfPostbackFail(400, 'Invalid postback.');
}

$payout = (float) $payoutText;
$payoutCents = (int) round($payout * 100);

if (!is_finite($payout) || $payoutCents < 1 || $payoutCents > 999999999999) {
    pfPostbackFail(400, 'Invalid payout.');
}

pfPostbackDebug('POSTBACK_RECEIVED', [
    'method' => 'GET',
    'offer_id_present' => true,
    'payout_present' => true,
    'tracking_id_present' => true,
]);

try {
    $networkStmt = $pdo->prepare(
        "SELECT id
         FROM networks
         WHERE slug = 'ogads'
         LIMIT 1"
    );
    $networkStmt->execute();
    $networkIdValue = $networkStmt->fetchColumn();

    if ($networkIdValue === false) {
        pfPostbackDebug('OGADS_NETWORK_UNAVAILABLE');
        pfPostbackFail(503, 'Offer network unavailable.');
    }

    $networkId = (int) $networkIdValue;

    // Lock the click and campaign while validating and recording this event.
    // This serializes retries for the same tracking ID before wallet credit.
    $pdo->beginTransaction();

    $clickStmt = $pdo->prepare(
        "SELECT
            cc.id AS click_id,
            cc.campaign_id,
            cc.worker_id,
            COALESCE(cc.snapshot_network_id, c.network_id) AS effective_network_id,
            COALESCE(cc.snapshot_external_offer_id, c.external_offer_id) AS effective_offer_id,
            COALESCE(cc.snapshot_network_payout, c.network_payout) AS effective_network_payout,
            COALESCE(cc.snapshot_reward_rate, c.reward_rate) AS effective_reward_rate,
            COALESCE(cc.snapshot_worker_reward, c.worker_reward) AS effective_worker_reward,
            COALESCE(cc.snapshot_platform_margin, c.platform_margin) AS effective_platform_margin,
            CASE
                WHEN cc.snapshot_network_id IS NULL
                 AND cc.snapshot_external_offer_id IS NULL
                 AND cc.snapshot_network_payout IS NULL
                 AND cc.snapshot_reward_rate IS NULL
                 AND cc.snapshot_worker_reward IS NULL
                 AND cc.snapshot_platform_margin IS NULL THEN 1
                WHEN cc.snapshot_network_id IS NOT NULL
                 AND cc.snapshot_external_offer_id IS NOT NULL
                 AND cc.snapshot_network_payout IS NOT NULL
                 AND cc.snapshot_reward_rate IS NOT NULL
                 AND cc.snapshot_worker_reward IS NOT NULL
                 AND cc.snapshot_platform_margin IS NOT NULL THEN 0
                ELSE 2
            END AS snapshot_state
         FROM campaign_clicks cc
         INNER JOIN campaigns c ON c.id = cc.campaign_id
         INNER JOIN networks n ON n.id = COALESCE(cc.snapshot_network_id, c.network_id)
         WHERE cc.tracking_id = ?
           AND COALESCE(cc.snapshot_network_id, c.network_id) = ?
           AND n.slug = 'ogads'
           AND UPPER(TRIM(c.source_type)) = 'CPA_NETWORK'
         LIMIT 1
         FOR UPDATE"
    );
    $clickStmt->execute([$trackingId, $networkId]);
    $click = $clickStmt->fetch(PDO::FETCH_ASSOC);

    if (!$click) {
        $pdo->rollBack();
        pfPostbackDebug('TRACKING_ID_NOT_FOUND', ['network_id' => $networkId]);
        pfPostbackFail(404, 'Tracking ID not found.');
    }

    if ((int) $click['snapshot_state'] === 2) {
        $pdo->rollBack();
        pfPostbackDebug('CLICK_SNAPSHOT_INCOMPLETE');
        pfPostbackFail(409, 'Tracking record is incomplete.');
    }

    if ((int) $click['snapshot_state'] === 1) {
        pfPostbackDebug('LEGACY_CLICK_WITHOUT_SNAPSHOT');
    }

    if ((string) $click['effective_offer_id'] !== $offerId) {
        $pdo->rollBack();
        pfPostbackDebug('OFFER_ID_MISMATCH', ['network_id' => $networkId]);
        pfPostbackFail(400, 'Offer does not match tracking record.');
    }

    // New clicks use their immutable click-time financial snapshot. Older
    // rows created before the snapshot migration fall back to current campaign
    // values so legitimate in-flight legacy clicks are not discarded.
    $campaignPayoutCents = (int) round((float) $click['effective_network_payout'] * 100);

    if ($campaignPayoutCents < 1 || $payoutCents !== $campaignPayoutCents) {
        $pdo->rollBack();
        pfPostbackDebug('PAYOUT_MISMATCH', ['network_id' => $networkId]);
        pfPostbackFail(400, 'Payout does not match campaign.');
    }

    $rewardRate = (float) $click['effective_reward_rate'];
    $storedWorkerReward = round((float) $click['effective_worker_reward'], 2);
    $storedPlatformMargin = round((float) $click['effective_platform_margin'], 2);
    $expectedWorkerReward = round($payout * ($rewardRate / 100), 2);
    $expectedPlatformMargin = round($payout - $expectedWorkerReward, 2);

    if (
        !is_finite($rewardRate) ||
        $rewardRate < 0 ||
        $rewardRate > 100 ||
        (int) round($storedWorkerReward * 100) !== (int) round($expectedWorkerReward * 100) ||
        (int) round($storedPlatformMargin * 100) !== (int) round($expectedPlatformMargin * 100)
    ) {
        $pdo->rollBack();
        pfPostbackDebug('CAMPAIGN_REWARD_CONFIGURATION_INVALID', [
            'campaign_id' => (int) $click['campaign_id'],
        ]);
        pfPostbackFail(409, 'Campaign reward configuration is invalid.');
    }

    $campaignId = (int) $click['campaign_id'];
    $workerId = (int) $click['worker_id'];

    if ($campaignId < 1 || $workerId < 1) {
        $pdo->rollBack();
        pfPostbackDebug('INVALID_CLICK_OWNER');
        pfPostbackFail(409, 'Invalid tracking record.');
    }

    // Fast duplicate check. The unique database index remains authoritative.
    $existingStmt = $pdo->prepare(
        "SELECT id
         FROM conversions
         WHERE network_id = ?
           AND external_transaction_id = ?
         LIMIT 1"
    );
    $existingStmt->execute([$networkId, $trackingId]);

    if ($existingStmt->fetchColumn() !== false) {
        $pdo->rollBack();
        pfPostbackDebug('DUPLICATE_CALLBACK_IGNORED', ['network_id' => $networkId]);
        http_response_code(200);
        exit('OK');
    }

    // Use the click-time reward amounts after validating their consistency
    // with the click-time payout and reward percentage.
    $workerReward = $storedWorkerReward;
    $platformMargin = $storedPlatformMargin;

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

    // Lock the worker row so simultaneous rewards cannot share a stale
    // balance snapshot.
    $workerStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
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
        number_format($campaignPayoutCents / 100, 2, '.', ''),
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
        ) VALUES (?, 'OFFER_REWARD', 'CONVERSION', ?, ?, 'USD', ?, ?, 'COMPLETED', ?)"
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

    if (pfIsDuplicateKey($e) && isset($networkId, $trackingId)) {
        try {
            // Confirm that this exact conversion already exists. A duplicate
            // from some unrelated unique index must not be acknowledged as a
            // successfully processed callback.
            $duplicateCheck = $pdo->prepare(
                'SELECT id
                 FROM conversions
                 WHERE network_id = ?
                   AND external_transaction_id = ?
                 LIMIT 1'
            );
            $duplicateCheck->execute([$networkId, $trackingId]);

            if ($duplicateCheck->fetchColumn() !== false) {
                pfPostbackDebug('DUPLICATE_CALLBACK_CONFIRMED', [
                    'network_id' => $networkId,
                ]);
                http_response_code(200);
                exit('OK');
            }
        } catch (Throwable $duplicateCheckError) {
            pfPostbackDebug('DUPLICATE_CONFIRMATION_CHECK_FAILED');
        }
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
