<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/plain; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| TEMPORARY POSTBACK DIAGNOSTICS
|--------------------------------------------------------------------------
| Writes diagnostic information outside public_html.
| Does not record the visitor's IP, full request URL, or tracking ID.
|--------------------------------------------------------------------------
*/

$debugLog = '/home/u541027683/private/poketflow-postback-debug.log';

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

pfPostbackDebug('POSTBACK_REACHED', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'pdo_available' => isset($pdo) && $pdo instanceof PDO,
    'parameters_present' => [
        'offer_id' => isset($_GET['offer_id']),
        'payout' => isset($_GET['payout']),
        'session_ip' => isset($_GET['session_ip']),
        'aff_sub4' => isset($_GET['aff_sub4']),
        'datetime' => isset($_GET['datetime']),
    ],
]);

// --------------------------------------------------
// Get OGAds postback values
// --------------------------------------------------

$offerId = trim(
    (string) ($_GET['offer_id'] ?? '')
);

$payout = (float) (
    $_GET['payout'] ?? 0
);

$sessionIp = trim(
    (string) ($_GET['session_ip'] ?? '')
);

$trackingId = trim(
    (string) ($_GET['aff_sub4'] ?? '')
);

$conversionDateTime = trim(
    (string) ($_GET['datetime'] ?? '')
);


// --------------------------------------------------
// Validate required values
// --------------------------------------------------

if (
    $offerId === '' ||
    $payout <= 0 ||
    $trackingId === ''
) {

    http_response_code(400);

    exit('Invalid postback.');
}


// --------------------------------------------------
// Find the original campaign click
// --------------------------------------------------

$stmt = $pdo->prepare(
    "
    SELECT
        id,
        campaign_id,
        worker_id,
        tracking_id
    FROM campaign_clicks
    WHERE tracking_id = ?
    LIMIT 1
    "
);

$stmt->execute([
    $trackingId
]);

$click = $stmt->fetch(PDO::FETCH_ASSOC);


// --------------------------------------------------
// Tracking ID must exist
// --------------------------------------------------

if (!$click) {

    http_response_code(404);

    exit('Tracking ID not found.');
}


$clickId = (int) $click['id'];

$campaignId = (int) $click['campaign_id'];

$workerId = (int) $click['worker_id'];


// --------------------------------------------------
// Check for an existing conversion
//
// Prevent duplicate rewards if OGAds sends the
// same conversion more than once.
// --------------------------------------------------

$stmt = $pdo->prepare(
    "
    SELECT
        id,
        status
    FROM conversions
    WHERE external_transaction_id = ?
    LIMIT 1
    "
);

$stmt->execute([
    $trackingId
]);

$existingConversion = $stmt->fetch(PDO::FETCH_ASSOC);


if ($existingConversion) {

    echo 'Conversion already processed.';

    exit;
}


// --------------------------------------------------
// Calculate worker reward
//
// Current PoketFlow model:
// Worker = 40%
// Platform = 60%
// --------------------------------------------------

$rewardRate = 40.00;

$workerReward = round(
    $payout * ($rewardRate / 100),
    2
);

$platformMargin = round(
    $payout - $workerReward,
    2
);


// --------------------------------------------------
// Get OGAds network ID
// --------------------------------------------------

$stmt = $pdo->prepare(
    "
    SELECT id
    FROM networks
    WHERE slug = 'ogads'
    LIMIT 1
    "
);

$stmt->execute();

$network = $stmt->fetch(PDO::FETCH_ASSOC);

$networkId = $network
    ? (int) $network['id']
    : null;


// --------------------------------------------------
// Begin atomic wallet operation
// --------------------------------------------------

try {

    $pdo->beginTransaction();


    // ----------------------------------------------
    // Create conversion
    // ----------------------------------------------

    $stmt = $pdo->prepare(
        "
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
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'APPROVED',
            ?
        )
        "
    );


    $convertedAt = null;

    if ($conversionDateTime !== '') {

        $timestamp = strtotime(
            $conversionDateTime
        );

        if ($timestamp !== false) {

            $convertedAt = date(
                'Y-m-d H:i:s',
                $timestamp
            );
        }
    }


    if ($convertedAt === null) {

        $convertedAt = date(
            'Y-m-d H:i:s'
        );
    }


    $stmt->execute([
        $campaignId,
        $workerId,
        $networkId,
        $trackingId,
        $payout,
        $rewardRate,
        $workerReward,
        $platformMargin,
        $convertedAt
    ]);


    $conversionId = (int) $pdo->lastInsertId();


    // ----------------------------------------------
    // Get current worker balance
    // ----------------------------------------------

    $stmt = $pdo->prepare(
        "
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN status = 'COMPLETED'
                        THEN amount
                        ELSE 0
                    END
                ),
                0
            ) AS balance
        FROM wallet_transactions
        WHERE user_id = ?
        "
    );

    $stmt->execute([
        $workerId
    ]);

    $balanceBefore = (float) (
        $stmt->fetchColumn() ?? 0
    );


    // ----------------------------------------------
    // Calculate new balance
    // ----------------------------------------------

    $balanceAfter = round(
        $balanceBefore + $workerReward,
        2
    );


    // ----------------------------------------------
    // Create wallet transaction
    // ----------------------------------------------

    $stmt = $pdo->prepare(
        "
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
        )
        "
    );


    $description =
        'Reward for completing OGAds offer #' .
        $offerId;


    $stmt->execute([
        $workerId,
        $conversionId,
        $workerReward,
        $balanceBefore,
        $balanceAfter,
        $description
    ]);


    // ----------------------------------------------
    // Commit everything
    // ----------------------------------------------

    $pdo->commit();


    // ----------------------------------------------
    // Success response
    // ----------------------------------------------

    echo 'OK';

    exit;


} catch (Throwable $e) {

    // ----------------------------------------------
    // Roll back if anything failed
    // ----------------------------------------------

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }


    error_log(
        'PoketFlow OGAds postback error: ' .
        $e->getMessage()
    );


    http_response_code(500);

    exit(
        'Postback processing failed.'
    );
}
