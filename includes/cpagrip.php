<?php

declare(strict_types=1);

/**
 * PoketFlow CPAGrip Offer Integration
 *
 * PHP 7.2 compatible.
 *
 * Responsibilities:
 * - Load private CPAGrip configuration.
 * - Request the CPAGrip offer feed.
 * - Validate and normalize the returned offers.
 * - Calculate indicative worker rewards and platform margins.
 *
 * Important:
 * - Never use a member ID as an offer-click tracking ID.
 * - Click tracking belongs in start-offer.php.
 * - Feed availability does not establish permission to incentivize
 *   an offer. Eligibility must be verified separately.
 * - Never log the private API key or a URL containing that key.
 */

$configPath = '/home/u541027683/private/poketflow-cpagrip.php';


// --------------------------------------------------
// Load private configuration
// --------------------------------------------------

if (!is_file($configPath) || !is_readable($configPath)) {
    error_log('PoketFlow CPAGrip: Private configuration is unavailable.');
    return [];
}

$config = require $configPath;

if (!is_array($config)) {
    error_log('PoketFlow CPAGrip: Invalid configuration format.');
    return [];
}


// --------------------------------------------------
// Validate required configuration
// --------------------------------------------------

$userId = isset($config['user_id'])
    ? trim((string) $config['user_id'])
    : '';

$privateKey = isset($config['private_key'])
    ? trim((string) $config['private_key'])
    : '';

if ($userId === '' || $privateKey === '') {
    error_log('PoketFlow CPAGrip: Required configuration is missing.');
    return [];
}


// --------------------------------------------------
// Reward and offer-limit configuration
// --------------------------------------------------

$rewardRate = isset($config['reward_rate'])
    ? (float) $config['reward_rate']
    : 0.60;

/*
 * Accept either:
 *   0.60 = 60%
 *   60   = 60%
 *
 * The private configuration should preferably use a decimal
 * between 0 and 1, for example 0.60.
 */

if ($rewardRate > 1 && $rewardRate <= 100) {
    $rewardRate = $rewardRate / 100;
}

if ($rewardRate <= 0 || $rewardRate > 1) {
    error_log('PoketFlow CPAGrip: Invalid reward rate.');
    return [];
}

$limit = isset($config['limit'])
    ? (int) $config['limit']
    : 20;

/*
 * Keep the configured offer limit within reasonable bounds.
 * A limit of 100 remains supported.
 */

$limit = max(1, min(100, $limit));


// --------------------------------------------------
// Visitor information
// --------------------------------------------------

$visitorIp = isset($_SERVER['REMOTE_ADDR'])
    ? trim((string) $_SERVER['REMOTE_ADDR'])
    : '';

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? trim((string) $_SERVER['HTTP_USER_AGENT'])
    : '';


// --------------------------------------------------
// Tracking
// --------------------------------------------------

/*
 * Do not use $_SESSION['user_id'] as a tracking ID.
 *
 * This file retrieves an offer feed, not an individual click.
 * A unique click-tracking ID must be created when a member
 * starts an offer through start-offer.php.
 *
 * Do not send a tracking_id parameter in this feed request
 * unless CPAGrip's documented feed API specifically requires
 * one and its meaning has been verified.
 */


// --------------------------------------------------
// Validate cURL availability
// --------------------------------------------------

if (!function_exists('curl_init')) {
    error_log('PoketFlow CPAGrip: PHP cURL extension is unavailable.');
    return [];
}


// --------------------------------------------------
// Build request
// --------------------------------------------------

$params = [
    'user_id' => $userId,
    'key'     => $privateKey,
    'ip'      => $visitorIp,
    'ua'      => $userAgent,
    'limit'   => $limit,
];

/*
 * Keep the private key out of application logs.
 *
 * CPAGrip's current feed documentation should be consulted
 * before changing its authentication method or parameter names.
 */

$feedUrl = 'https://www.cpagrip.com/common/offer_feed_json.php?'
    . http_build_query($params, '', '&', PHP_QUERY_RFC3986);


// --------------------------------------------------
// Request CPAGrip
// --------------------------------------------------

$ch = curl_init();

if ($ch === false) {
    error_log('PoketFlow CPAGrip: Unable to initialize cURL.');
    return [];
}

curl_setopt_array(
    $ch,
    [
        CURLOPT_URL            => $feedUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,

        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,

        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
        ],
    ]
);

$response = curl_exec($ch);

$curlErrorNumber = curl_errno($ch);

$httpCode = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

curl_close($ch);


// --------------------------------------------------
// Validate HTTP response
// --------------------------------------------------

if ($response === false || $curlErrorNumber !== 0) {
    /*
     * Do not log the full URL: it contains the private key.
     */

    error_log(
        'PoketFlow CPAGrip: Feed request failed. cURL error number: '
        . $curlErrorNumber
    );

    return [];
}

if ($httpCode < 200 || $httpCode >= 300) {
    error_log(
        'PoketFlow CPAGrip: Feed returned HTTP status '
        . $httpCode
    );

    return [];
}


// --------------------------------------------------
// Decode response
// --------------------------------------------------

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    error_log('PoketFlow CPAGrip: Feed returned invalid JSON.');
    return [];
}

/*
 * The feed is expected to contain an "offers" array.
 * Do not silently interpret an unexpected response as a valid
 * empty feed; log a generic diagnostic without exposing secrets.
 */

if (!isset($data['offers']) || !is_array($data['offers'])) {
    error_log('PoketFlow CPAGrip: Response does not contain an offers array.');
    return [];
}


// --------------------------------------------------
// Normalize offers
// --------------------------------------------------

$offers = [];

$seenOfferIds = [];

foreach ($data['offers'] as $offer) {
    if (!is_array($offer)) {
        continue;
    }


    // ----------------------------------------------
    // Basic offer information
    // ----------------------------------------------

    $offerId = isset($offer['offer_id'])
        ? trim((string) $offer['offer_id'])
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
    // Validate required fields
    // ----------------------------------------------

    if (
        $offerId === '' ||
        $title === '' ||
        $offerLink === ''
    ) {
        continue;
    }

    /*
     * Only accept HTTPS offer destinations.
     *
     * This prevents malformed URLs and non-HTTPS destinations
     * from entering the normalized feed.
     */

    $offerUrlParts = parse_url($offerLink);

    if (
        !is_array($offerUrlParts) ||
        !isset($offerUrlParts['scheme'], $offerUrlParts['host']) ||
        strtolower((string) $offerUrlParts['scheme']) !== 'https'
    ) {
        continue;
    }

    /*
     * Avoid adding the same offer more than once if the feed
     * contains duplicate offer IDs.
     */

    if (isset($seenOfferIds[$offerId])) {
        continue;
    }


    // ----------------------------------------------
    // Network payout
    // ----------------------------------------------

    $payout = isset($offer['payout']) && is_numeric($offer['payout'])
        ? (float) $offer['payout']
        : 0.00;

    /*
     * Reject negative or invalid payouts.
     * Zero-payout offers are excluded because this integration
     * calculates a cash reward from the network payout.
     */

    if (!is_finite($payout) || $payout <= 0) {
        continue;
    }


    // ----------------------------------------------
    // Calculate indicative reward and margin
    // ----------------------------------------------

    $reward = round(
        $payout * $rewardRate,
        2
    );

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

    $seenOfferIds[$offerId] = true;
}


// --------------------------------------------------
// Return normalized offers
// --------------------------------------------------

return $offers;
