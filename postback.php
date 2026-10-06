<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * PoketFlow CPAGrip Global Postback
 *
 * Expected POST variables:
 * - offer_id
 * - tracking_id
 * - payout
 *
 * CPAGrip password authentication is intentionally NOT used.
 */

// --------------------------------------------------
// Only accept POST requests
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}


// --------------------------------------------------
// Read incoming CPAGrip values
// --------------------------------------------------

$offerId = trim((string) ($_POST['offer_id'] ?? ''));
$trackingId = trim((string) ($_POST['tracking_id'] ?? ''));
$incomingPayout = $_POST['payout'] ?? null;


// --------------------------------------------------
// Basic validation
// --------------------------------------------------

if ($offerId === '' || $trackingId === '') {
    http_response_code(400);
    exit('Missing required parameters');
}

if ($incomingPayout !== null && !is_numeric($incomingPayout)) {
    http_response_code(400);
    exit('Invalid payout');
}


// --------------------------------------------------
// Find CPAGrip network
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT id, slug, status
    FROM networks
    WHERE slug = 'cpagrip'
    LIMIT 1
");

$stmt->execute();

$network = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$network) {
    http_response_code(500);
    exit('CPAGrip network not configured');
}

if ($network['status'] !== 'ACTIVE') {
    http_response_code(503);
    exit('CPAGrip network inactive');
}

$networkId = (int) $network['id'];


// --------------------------------------------------
// Find the original campaign click
// --------------------------------------------------
//
// IMPORTANT:
// The worker is determined from our own click record.
// We never trust a worker/user ID from CPAGrip.
//

$stmt = $pdo->prepare("
    SELECT
        cc.id AS click_id,
        cc.campaign_id,
        cc.worker_id,
        cc.tracking_id,

        c.network_id,
        c.external_offer_id,
        c.title,
        c.network_payout,
        c.reward_rate,
        c.worker_reward,
        c.platform_margin,
        c.status,
        c.approval_status

    FROM campaign_clicks cc

    INNER JOIN campaigns c
        ON c.id = cc.campaign_id

    WHERE cc.tracking_id = :tracking_id
    LIMIT 1
");

$stmt->execute([
    ':tracking_id' => $trackingId,
]);

$click = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$click) {
    http_response_code(404);
    exit('Tracking ID not found');
}


// --------------------------------------------------
// Verify this is a CPAGrip campaign
// --------------------------------------------------

if ((int) $click['network_id'] !== $networkId) {
    http_response_code(403);
    exit('Invalid network');
}


// --------------------------------------------------
// Verify CPAGrip offer ID matches our campaign
// --------------------------------------------------

if ((string) $click['external_offer_id'] !== $offerId) {
    http_response_code(403);
    exit('Offer mismatch');
}


// --------------------------------------------------
// Verify campaign is still valid
// --------------------------------------------------

if (
    $click['status'] !== 'ACTIVE' ||
    $click['approval_status'] !== 'APPROVED'
) {
    http_response_code(403);
    exit('Campaign not eligible');
}


// --------------------------------------------------
// Determine authoritative payout
// --------------------------------------------------
//
// DO NOT trust the incoming payout for wallet crediting.
//
// Our campaign record contains the payout that was stored
// when the CPAGrip offer was synchronized.
//
// For campaign 92:
// payout = $0.17
// reward rate = 60%
// worker reward = $0.10
// platform margin = $0.07
//

$networkPayout = (float) $click['network_payout'];

if ($networkPayout <= 0) {
    http_response_code(400);
    exit('Campaign payout unavailable');
}


// --------------------------------------------------
// Determine reward rate
// --------------------------------------------------

$rewardRate = (float) $click['reward_rate'];

if ($rewardRate <= 0) {
    $rewardRate = 60.0;
}


// --------------------------------------------------
// Calculate worker reward and platform margin
// --------------------------------------------------

$workerReward = round(
    $networkPayout * ($rewardRate / 100),
    2
);

$platformMargin = round(
    $networkPayout - $workerReward,
    2
);


// --------------------------------------------------
// Create deterministic transaction ID
// --------------------------------------------------

$externalTransactionId =
    'cpagrip:' . $offerId . ':' . $trackingId;


// --------------------------------------------------
// Process conversion atomically
// --------------------------------------------------

try {

    $pdo->beginTransaction();


    // --------------------------------------------------
    // Prevent duplicate conversion
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT id
        FROM conversions
        WHERE network_id = :network_id
          AND external_transaction_id = :external_transaction_id
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        ':network_id' => $networkId,
        ':external_transaction_id' => $externalTransactionId,
    ]);

    $existingConversion = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($existingConversion) {

        $pdo->rollBack();

        // Idempotent response:
        // CPAGrip can safely retry the same postback.
        http_response_code(200);
        exit('OK');
    }


    // --------------------------------------------------
    // Get current worker balance
    // --------------------------------------------------

    $workerId = (int) $click['worker_id'];

    $balanceBefore = getUserBalance(
        $pdo,
        $workerId
    );

    $balanceAfter = round(
        $balanceBefore + $workerReward,
        2
    );


    // --------------------------------------------------
    // Insert conversion
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO conversions (
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
        )
        VALUES (
            :campaign_id,
            :worker_id,
            :network_id,
            :external_transaction_id,
            :network_payout,
            :reward_rate,
            :worker_reward,
            :platform_margin,
            'APPROVED',
            NOW()
        )
    ");

    $stmt->execute([
        ':campaign_id' => (int) $click['campaign_id'],
        ':worker_id' => $workerId,
        ':network_id' => $networkId,
        ':external_transaction_id' => $externalTransactionId,
        ':network_payout' => $networkPayout,
        ':reward_rate' => $rewardRate,
        ':worker_reward' => $workerReward,
        ':platform_margin' => $platformMargin,
    ]);

    $conversionId = (int) $pdo->lastInsertId();


    // --------------------------------------------------
    // Create wallet transaction
    // --------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions (
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
        )
        VALUES (
            :user_id,
            'OFFER_REWARD',
            'CONVERSION',
            :reference_id,
            :amount,
            'USD',
            :balance_before,
            :balance_after,
            'COMPLETED',
            :description
        )
    ");

    $stmt->execute([
        ':user_id' => $workerId,
        ':reference_id' => $conversionId,
        ':amount' => $workerReward,
        ':balance_before' => $balanceBefore,
        ':balance_after' => $balanceAfter,
        ':description' => 'CPAGrip offer reward',
    ]);


    // --------------------------------------------------
    // Commit everything
    // --------------------------------------------------

    $pdo->commit();


    // --------------------------------------------------
    // Success
    // --------------------------------------------------

    http_response_code(200);
    exit('OK');


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'PoketFlow CPAGrip postback error: ' . $e->getMessage()
    );

    http_response_code(500);
    exit('Internal Server Error');
}
