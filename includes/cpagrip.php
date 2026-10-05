<?php

declare(strict_types=1);

/**
 * PoketFlow - CPAGrip Offer Feed
 *
 * PHP 7.2 compatible.
 *
 * IMPORTANT:
 * CPAGrip credentials are loaded from a private file
 * outside the public/GitHub project.
 */

// --------------------------------------------------
// Load CPAGrip private configuration
// --------------------------------------------------

// CHANGE THIS to the exact private path used on your server.
$cpagripConfigPath = '/home/YOUR_ACCOUNT/private/poketflow-cpagrip.php';

if (!file_exists($cpagripConfigPath)) {
    throw new RuntimeException(
        'CPAGrip configuration file was not found.'
    );
}

$cpagripConfig = require $cpagripConfigPath;


// --------------------------------------------------
// CPAGrip settings
// --------------------------------------------------

$cpagripUserId = $cpagripConfig['user_id'];
$cpagripPrivateKey = $cpagripConfig['private_key'];

$cpagripTrackingDomain = !empty(
    $cpagripConfig['tracking_domain']
)
    ? $cpagripConfig['tracking_domain']
    : 'www.cpagrip.com';

$cpagripRewardRate = isset(
    $cpagripConfig['reward_rate']
)
    ? (float) $cpagripConfig['reward_rate']
    : 0.60;

$cpagripLimit = isset(
    $cpagripConfig['limit']
)
    ? (int) $cpagripConfig['limit']
    : 20;


// --------------------------------------------------
// Get visitor information
// --------------------------------------------------

$visitorIp = isset($_SERVER['REMOTE_ADDR'])
    ? $_SERVER['REMOTE_ADDR']
    : '';

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? $_SERVER['HTTP_USER_AGENT']
    : '';


// --------------------------------------------------
// Get logged-in PoketFlow user
// --------------------------------------------------

$trackingId = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    $trackingId = (string) $_SESSION['user_id'];
}


// --------------------------------------------------
// Build CPAGrip JSON feed URL
// --------------------------------------------------

$query = [
    'user_id' => $cpagripUserId,
    'key' => $cpagripPrivateKey,
    'ip' => $visitorIp,
    'ua' => $userAgent,
    'limit' => $cpagripLimit,
];

if ($trackingId !== '') {
    $query['tracking_id'] = $trackingId;
}

$feedUrl =
    'https://www.cpagrip.com/common/offer_feed_json.php?'
    . http_build_query($query);


// --------------------------------------------------
// Request JSON feed
// --------------------------------------------------

$ch = curl_init();

curl_setopt_array(
    $ch,
    [
        CURLOPT_URL => $feedUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]
);

$response = curl_exec($ch);

$httpCode = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

$curlError = curl_error($ch);

curl_close($ch);


// --------------------------------------------------
// Handle request failure
// --------------------------------------------------

if ($response === false || $curlError !== '') {
    return [];
}

if ($httpCode < 200 || $httpCode >= 300) {
    return [];
}


// --------------------------------------------------
// Decode JSON
// --------------------------------------------------

$data = json_decode(
    $response,
    true
);

if (!is_array($data)) {
    return [];
}

if (
    !isset($data['offers']) ||
    !is_array($data['offers'])
) {
    return [];
}


// --------------------------------------------------
// Prepare offers
// --------------------------------------------------

$offers = [];

foreach ($data['offers'] as $offer) {

    if (!is_array($offer)) {
        continue;
    }

    // ----------------------------------------------
    // Basic fields
    // ----------------------------------------------

    $title = isset($offer['title'])
        ? trim((string) $offer['title'])
        : '';

    $description = isset($offer['description'])
        ? trim((string) $offer['description'])
        : '';

    $offerLink = isset($offer['offerlink'])
        ? trim((string) $offer['offerlink'])
        : '';

    if ($title === '' || $offerLink === '') {
        continue;
    }


    // ----------------------------------------------
    // Payout
    // ----------------------------------------------

    $payout = 0.0;

    if (isset($offer['payout'])) {
        $payout = (float) $offer['payout'];
    }


    // ----------------------------------------------
    // PoketFlow reward
    // ----------------------------------------------

    $reward = $payout * $cpagripRewardRate;

    $reward = round($reward, 2);


    // ----------------------------------------------
    // Replace tracking domain if configured
    // ----------------------------------------------

    if (
        $cpagripTrackingDomain !== '' &&
        strpos($offerLink, 'www.cpagrip.com') !== false
    ) {
        $offerLink = str_replace(
            'www.cpagrip.com',
            $cpagripTrackingDomain,
            $offerLink
        );
    }


    // ----------------------------------------------
    // Build normalized offer
    // ----------------------------------------------

    $offers[] = [
        'network' => 'CPAGrip',

        'network_offer_id' => isset($offer['id'])
            ? (string) $offer['id']
            : '',

        'title' => $title,

        'description' => $description,

        'offerlink' => $offerLink,

        'payout' => $payout,

        'reward' => $reward,

        'raw' => $offer,
    ];
}

return $offers;
