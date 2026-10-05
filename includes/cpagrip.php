<?php

declare(strict_types=1);

/**
 * PoketFlow CPAGrip Offer Integration
 *
 * PHP 7.2 compatible.
 */

$configPath = '/home/u541027683/private/poketflow-cpagrip.php';

if (!file_exists($configPath)) {
    return [];
}

$config = require $configPath;

if (!is_array($config)) {
    return [];
}


// --------------------------------------------------
// CPAGrip configuration
// --------------------------------------------------

$userId = (string) $config['user_id'];

$privateKey = (string) $config['private_key'];

$rewardRate = isset($config['reward_rate'])
    ? (float) $config['reward_rate']
    : 0.60;

$limit = isset($config['limit'])
    ? (int) $config['limit']
    : 20;


// --------------------------------------------------
// Visitor information
// --------------------------------------------------

$visitorIp = isset($_SERVER['REMOTE_ADDR'])
    ? $_SERVER['REMOTE_ADDR']
    : '';

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? $_SERVER['HTTP_USER_AGENT']
    : '';


// --------------------------------------------------
// PoketFlow tracking ID
// --------------------------------------------------

$trackingId = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    $trackingId = (string) $_SESSION['user_id'];
}


// --------------------------------------------------
// Build request
// --------------------------------------------------

$params = [
    'user_id' => $userId,
    'key' => $privateKey,
    'ip' => $visitorIp,
    'ua' => $userAgent,
    'limit' => $limit,
];


// Pass logged-in PoketFlow user ID to CPAGrip.
if ($trackingId !== '') {
    $params['tracking_id'] = $trackingId;
}


$feedUrl =
    'https://www.cpagrip.com/common/offer_feed_json.php?'
    . http_build_query($params);


// --------------------------------------------------
// Request CPAGrip
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

curl_close($ch);


// --------------------------------------------------
// Validate response
// --------------------------------------------------

if ($response === false) {
    return [];
}

if ($httpCode < 200 || $httpCode >= 300) {
    return [];
}


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
// Prepare normalized offers
// --------------------------------------------------

$offers = [];

foreach ($data['offers'] as $offer) {

    if (!is_array($offer)) {
        continue;
    }


    // ----------------------------------------------
    // Basic information
    // ----------------------------------------------

    $offerId = isset($offer['offer_id'])
        ? (string) $offer['offer_id']
        : '';

    $title = isset($offer['title'])
        ? trim((string) $offer['title'])
        : '';

    $description = isset($offer['description'])
        ? trim((string) $offer['description'])
        : '';

    $offerLink = isset($offer['offerlink'])
        ? trim((string) $offer['offerlink'])
        : '';

    $image = isset($offer['offerphoto'])
        ? trim((string) $offer['offerphoto'])
        : '';

    $type = isset($offer['type'])
        ? trim((string) $offer['type'])
        : '';

    $category = isset($offer['category'])
        ? trim((string) $offer['category'])
        : '';

    $countries = isset($offer['accepted_countries'])
        ? trim((string) $offer['accepted_countries'])
        : '';


    // ----------------------------------------------
    // Required fields
    // ----------------------------------------------

    if (
        $offerId === '' ||
        $title === '' ||
        $offerLink === ''
    ) {
        continue;
    }


    // ----------------------------------------------
    // Network payout
    // ----------------------------------------------

    $payout = isset($offer['payout'])
        ? (float) $offer['payout']
        : 0.00;


    // ----------------------------------------------
    // PoketFlow reward
    // ----------------------------------------------

    $reward = round(
        $payout * $rewardRate,
        2
    );


    // ----------------------------------------------
    // Platform margin
    // ----------------------------------------------

    $margin = round(
        $payout - $reward,
        2
    );


    // ----------------------------------------------
    // Normalized offer
    // ----------------------------------------------

    $offers[] = [
        'network' => 'CPAGrip',

        'offer_id' => $offerId,

        'title' => $title,

        'description' => $description,

        'offerlink' => $offerLink,

        'image' => $image,

        'type' => $type,

        'category' => $category,

        'accepted_countries' => $countries,

        'payout' => $payout,

        'reward' => $reward,

        'margin' => $margin,
    ];
}

return $offers;
