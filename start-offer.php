<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow Start Offer — OGAds Only
|--------------------------------------------------------------------------
| Validates an approved campaign, records the click, and redirects
| the authenticated member to the OGAds offer.
|
| PHP 7.2 compatible.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';

// --------------------------------------------------
// Require authentication
// --------------------------------------------------

if (!isLoggedIn()) {
    redirect('login.php');
}

// --------------------------------------------------
// Get logged-in user
// --------------------------------------------------

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId < 1) {
    redirect('login.php');
}

$user = getUser($pdo, $userId);

if (!$user) {
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    redirect('login.php');
}

// --------------------------------------------------
// Validate user account
// --------------------------------------------------

$userStatus = strtoupper(
    trim((string) ($user['status'] ?? ''))
);

if ($userStatus !== 'ACTIVE') {
    http_response_code(403);
    exit('Your account is not currently permitted to start offers.');
}

// --------------------------------------------------
// Accept OGAds only
// --------------------------------------------------

$network = strtolower(
    trim((string) ($_GET['network'] ?? 'ogads'))
);

if ($network !== 'ogads') {
    http_response_code(400);
    exit('Invalid offer network.');
}

// --------------------------------------------------
// Validate external OGAds offer ID
// --------------------------------------------------

$offerIdValue = $_GET['offer_id'] ?? '';

if (
    !is_scalar($offerIdValue) ||
    trim((string) $offerIdValue) === '' ||
    !ctype_digit((string) $offerIdValue) ||
    (int) $offerIdValue < 1
) {
    http_response_code(400);
    exit('Invalid offer.');
}

$offerId = (string) $offerIdValue;

// --------------------------------------------------
// Validate HTTPS offer destination
// --------------------------------------------------

function validateOfferDestination(string $url): bool
{
    $url = trim($url);

    if ($url === '') {
        return false;
    }

    $parts = parse_url($url);

    if (
        !is_array($parts) ||
        !isset($parts['scheme'], $parts['host'])
    ) {
        return false;
    }

    if (strtolower((string) $parts['scheme']) !== 'https') {
        return false;
    }

    if (
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        return false;
    }

    return true;
}

// --------------------------------------------------
// Append network tracking parameter
// --------------------------------------------------

function appendTrackingParameter(
    string $url,
    string $parameter,
    string $trackingId
): string {
    $fragment = '';

    $fragmentPosition = strpos($url, '#');

    if ($fragmentPosition !== false) {
        $fragment = substr($url, $fragmentPosition);
        $url = substr($url, 0, $fragmentPosition);
    }

    $separator = strpos($url, '?') !== false
        ? '&'
        : '?';

    return $url
        . $separator
        . rawurlencode($parameter)
        . '='
        . rawurlencode($trackingId)
        . $fragment;
}

// --------------------------------------------------
// Store active offer tracking information
// --------------------------------------------------

function storeActiveOfferTracking(
    string $trackingId,
    int $campaignId
): void {
    $_SESSION['active_offer_tracking_id'] = $trackingId;
    $_SESSION['active_offer_campaign_id'] = $campaignId;
    $_SESSION['active_offer_network'] = 'ogads';
}

// ==================================================
// OGADS WORKFLOW
// ==================================================

// --------------------------------------------------
// Locate selected offer in the current session
// --------------------------------------------------

$sessionOffers = $_SESSION['ogads_offers'] ?? null;

if (!is_array($sessionOffers) || empty($sessionOffers)) {
    http_response_code(404);

    exit(
        'Your offer list has expired. Please return to the Offers page and try again.'
    );
}

$selectedOffer = null;

foreach ($sessionOffers as $offer) {
    if (!is_array($offer)) {
        continue;
    }

    $externalOfferId = trim(
        (string) ($offer['external_offer_id'] ?? '')
    );

    if ($externalOfferId === $offerId) {
        $selectedOffer = $offer;
        break;
    }
}

if ($selectedOffer === null) {
    http_response_code(404);

    exit(
        'This offer is no longer available. Please return to the Offers page and try again.'
    );
}

// --------------------------------------------------
// Validate session offer metadata
// --------------------------------------------------

$sessionOfferUrl = trim(
    (string) ($selectedOffer['network_offer_url'] ?? '')
);

$sessionPayout = isset($selectedOffer['network_payout'])
    ? (float) $selectedOffer['network_payout']
    : 0.0;

if (
    !validateOfferDestination($sessionOfferUrl) ||
    !is_finite($sessionPayout) ||
    $sessionPayout <= 0
) {
    http_response_code(400);
    exit('This offer is currently unavailable.');
}

// --------------------------------------------------
// Apply OGAds safety filters
// --------------------------------------------------

$ogadsOfferForSafety = [
    'offerid' => $offerId,
    'name_short' => $selectedOffer['title'] ?? '',
    'name' => $selectedOffer['title'] ?? '',
    'description' => $selectedOffer['description'] ?? '',
    'adcopy' => $selectedOffer['instructions'] ?? '',
    'link' => $sessionOfferUrl,
];

if (!isOgadsOfferSafe($pdo, $ogadsOfferForSafety)) {
    http_response_code(403);
    exit('This offer is not available.');
}

// --------------------------------------------------
// Get OGAds network ID
// --------------------------------------------------

try {
    $networkId = getOgadsNetworkId($pdo);
} catch (Throwable $e) {
    error_log(
        'PoketFlow OGAds network lookup failed: '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to connect to the offer network. Please try again.');
}

// --------------------------------------------------
// Find the existing database campaign
// --------------------------------------------------

$campaignStmt = $pdo->prepare(
    'SELECT id
     FROM campaigns
     WHERE network_id = ?
       AND external_offer_id = ?
     LIMIT 1'
);

$campaignStmt->execute([
    $networkId,
    $offerId,
]);

$campaignIdValue = $campaignStmt->fetchColumn();

if ($campaignIdValue === false) {
    http_response_code(404);

    exit(
        'This offer has not been approved in PoketFlow. Please choose an approved offer.'
    );
}

$campaignId = (int) $campaignIdValue;

// --------------------------------------------------
// Load authoritative database campaign
// --------------------------------------------------

$campaign = getCampaign($pdo, $campaignId);

if (!$campaign) {
    http_response_code(404);
    exit('This offer is no longer available.');
}

// --------------------------------------------------
// Verify network and external offer identity
// --------------------------------------------------

if (
    (int) ($campaign['network_id'] ?? 0) !== $networkId ||
    trim((string) ($campaign['external_offer_id'] ?? '')) !== $offerId
) {
    http_response_code(403);
    exit('This offer could not be verified.');
}

// --------------------------------------------------
// Verify campaign eligibility
// --------------------------------------------------

if (!canStartCampaign($pdo, $campaign, $user)) {
    http_response_code(403);

    exit(
        'This offer is not currently available for your account.'
    );
}

// --------------------------------------------------
// Validate the authoritative database destination
// --------------------------------------------------

$networkOfferUrl = trim(
    (string) ($campaign['network_offer_url'] ?? '')
);

if (!validateOfferDestination($networkOfferUrl)) {
    error_log(
        'PoketFlow OGAds invalid destination for campaign '
        . $campaignId
    );

    http_response_code(502);
    exit('This offer is temporarily unavailable.');
}

// --------------------------------------------------
// Record the campaign click
// --------------------------------------------------

try {
    $trackingId = createCampaignClick(
        $pdo,
        $campaignId,
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );
} catch (Throwable $e) {
    error_log(
        'PoketFlow OGAds click creation failed: '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to start this offer. Please try again.');
}

// --------------------------------------------------
// Store the tracking details in the session
// --------------------------------------------------

storeActiveOfferTracking(
    $trackingId,
    $campaignId
);

// --------------------------------------------------
// Append OGAds tracking ID
// --------------------------------------------------

/*
 * This retains the existing aff_sub4 parameter.
 * Its compatibility with your OGAds account and postback
 * configuration must be confirmed before live attribution
 * can be considered verified.
 */

$redirectUrl = appendTrackingParameter(
    $networkOfferUrl,
    'aff_sub4',
    $trackingId
);

// --------------------------------------------------
// Redirect to OGAds
// --------------------------------------------------

header('Location: ' . $redirectUrl, true, 302);
exit;
