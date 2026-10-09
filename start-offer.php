<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow Start Offer
|--------------------------------------------------------------------------
| Starts an eligible campaign and creates a click-tracking record.
|
| PHP 7.2 compatible.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';


/*
|--------------------------------------------------------------------------
| Require Authentication
|--------------------------------------------------------------------------
*/

if (!isLoggedIn()) {
    redirect('login.php');
}


/*
|--------------------------------------------------------------------------
| Get Logged-in User
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| Validate User Account
|--------------------------------------------------------------------------
*/

$userStatus = strtoupper(
    trim((string) ($user['status'] ?? ''))
);

if ($userStatus !== 'ACTIVE') {
    http_response_code(403);
    exit('Your account is not currently permitted to start offers.');
}


/*
|--------------------------------------------------------------------------
| Read Network
|--------------------------------------------------------------------------
*/

$network = strtolower(
    trim((string) ($_GET['network'] ?? 'ogads'))
);

if (!in_array($network, ['ogads', 'cpagrip'], true)) {
    http_response_code(400);
    exit('Invalid offer network.');
}


/*
|--------------------------------------------------------------------------
| Read Offer ID
|--------------------------------------------------------------------------
|
| The existing implementation uses numeric campaign offer IDs.
| Keep this validation until the actual network ID format has
| been confirmed.
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| Helper: Validate HTTPS Destination
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| Helper: Append Tracking Parameter
|--------------------------------------------------------------------------
| Preserves existing query parameters and URL fragments.
|
| IMPORTANT:
| The parameter name must match the selected network's documented
| tracking mechanism. This helper does not verify network support.
|--------------------------------------------------------------------------
*/

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

    $separator = (strpos($url, '?') !== false)
        ? '&'
        : '?';

    return $url
        . $separator
        . rawurlencode($parameter)
        . '='
        . rawurlencode($trackingId)
        . $fragment;
}


/*
|--------------------------------------------------------------------------
| Helper: Save Active Offer Tracking Information
|--------------------------------------------------------------------------
*/

function storeActiveOfferTracking(
    string $network,
    string $trackingId,
    int $campaignId
): void {

    $_SESSION['active_offer_tracking_id'] = $trackingId;

    $_SESSION['active_offer_campaign_id'] = $campaignId;

    $_SESSION['active_offer_network'] = $network;
}


/*
|--------------------------------------------------------------------------
| CPAGrip Workflow
|--------------------------------------------------------------------------
*/

if ($network === 'cpagrip') {

    /*
    | Find CPAGrip network.
    */

    $networkStmt = $pdo->prepare(
        'SELECT id, slug, status
         FROM networks
         WHERE slug = ?
         LIMIT 1'
    );

    $networkStmt->execute(['cpagrip']);

    $networkRow = $networkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$networkRow) {
        http_response_code(404);
        exit('Offer network not found.');
    }

    if (
        strtoupper(trim((string) ($networkRow['status'] ?? '')))
        !== 'ACTIVE'
    ) {
        http_response_code(503);
        exit('This offer network is temporarily unavailable.');
    }

    $networkId = (int) $networkRow['id'];


    /*
    | Load the database-backed campaign.
    */

    $campaignStmt = $pdo->prepare(
        'SELECT
            id,
            source_type,
            network_id,
            external_offer_id,
            network_offer_url,
            image_url,
            title,
            description,
            category,
            instructions,
            network_payout,
            reward_rate,
            worker_reward,
            platform_margin,
            countries,
            devices,
            os,
            incentive_allowed,
            status,
            approval_status,
            start_at,
            end_at
         FROM campaigns
         WHERE network_id = ?
           AND external_offer_id = ?
         LIMIT 1'
    );

    $campaignStmt->execute([
        $networkId,
        $offerId,
    ]);

    $campaign = $campaignStmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        http_response_code(404);
        exit('This offer is no longer available.');
    }


    /*
    | Check campaign eligibility.
    */

    if (!canStartCampaign($pdo, $campaign, $user)) {
        http_response_code(403);
        exit('This offer is not currently available for your account.');
    }


    /*
    | Validate network payout.
    */

    $networkPayout = isset($campaign['network_payout'])
        ? (float) $campaign['network_payout']
        : 0.0;

    if (!is_finite($networkPayout) || $networkPayout <= 0) {
        http_response_code(400);
        exit('This offer is currently unavailable.');
    }


    /*
    | Validate destination.
    */

    $networkOfferUrl = trim(
        (string) ($campaign['network_offer_url'] ?? '')
    );

    if (!validateOfferDestination($networkOfferUrl)) {
        error_log(
            'PoketFlow CPAGrip: Invalid offer destination for campaign '
            . (int) $campaign['id']
        );

        http_response_code(502);
        exit('This offer is temporarily unavailable.');
    }


    /*
    | Create unique click record.
    */

    try {
        $trackingId = createCampaignClick(
            $pdo,
            (int) $campaign['id'],
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );
    } catch (Throwable $e) {
        error_log(
            'PoketFlow CPAGrip click error: '
            . $e->getMessage()
        );

        http_response_code(500);
        exit('Unable to start this offer. Please try again.');
    }


    /*
    | Store tracking information.
    */

    storeActiveOfferTracking(
        'cpagrip',
        $trackingId,
        (int) $campaign['id']
    );


    /*
    | Add tracking ID.
    |
    | Confirm the parameter against CPAGrip documentation before
    | relying on this for live conversion attribution.
    */

    $redirectUrl = appendTrackingParameter(
        $networkOfferUrl,
        'tracking_id',
        $trackingId
    );


    /*
    | Redirect.
    */

    header('Location: ' . $redirectUrl, true, 302);
    exit;
}


/*
|--------------------------------------------------------------------------
| OGAds Workflow
|--------------------------------------------------------------------------
*/

/*
| Get offers saved in the member's session by the offers page.
*/

$sessionOffers = $_SESSION['ogads_offers'] ?? null;

if (!is_array($sessionOffers) || empty($sessionOffers)) {
    http_response_code(404);
    exit(
        'Your offer list has expired. Please return to the Offers page and try again.'
    );
}


/*
| Find selected offer.
*/

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


/*
| Extract session offer fields.
*/

$networkOfferUrl = trim(
    (string) ($selectedOffer['network_offer_url'] ?? '')
);

$networkPayout = isset($selectedOffer['network_payout'])
    ? (float) $selectedOffer['network_payout']
    : 0.0;

if (!validateOfferDestination($networkOfferUrl)) {
    http_response_code(502);
    exit('This offer is temporarily unavailable.');
}

if (!is_finite($networkPayout) || $networkPayout <= 0) {
    http_response_code(400);
    exit('This offer is currently unavailable.');
}


/*
| Validate raw OGAds offer using the existing safety filter.
*/

$ogadsOfferForSafety = [
    'offerid' => $offerId,
    'name_short' => $selectedOffer['title'] ?? '',
    'name' => $selectedOffer['title'] ?? '',
    'description' => $selectedOffer['description'] ?? '',
    'adcopy' => $selectedOffer['instructions'] ?? '',
    'link' => $networkOfferUrl,
];

if (!isOgadsOfferSafe($pdo, $ogadsOfferForSafety)) {
    http_response_code(403);
    exit('This offer is not available.');
}


/*
|--------------------------------------------------------------------------
| Get OGAds Network ID
|--------------------------------------------------------------------------
*/

try {
    $networkId = getOgadsNetworkId($pdo);
} catch (Throwable $e) {
    error_log(
        'PoketFlow OGAds network error: '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to connect the offer network. Please try again.');
}


/*
|--------------------------------------------------------------------------
| Find Existing OGAds Campaign
|--------------------------------------------------------------------------
*/

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

$existingCampaignId = $campaignStmt->fetchColumn();

if ($existingCampaignId !== false) {

    /*
    | Existing campaign: load its authoritative database state.
    */

    $campaignId = (int) $existingCampaignId;

    $campaign = getCampaign($pdo, $campaignId);

    if (!$campaign) {
        http_response_code(404);
        exit('Offer not found.');
    }

} else {

    /*
    | Synchronize a new offer without bypassing admin approval.
    |
    | The synchronization function must preserve safe defaults:
    | inactive, pending, and incentive_allowed = 0 until reviewed.
    */

    $ogadsOffer = [
        'offerid' => $offerId,

        'name_short' => $selectedOffer['title'] ?? 'OGAds Offer',

        'name' => $selectedOffer['title'] ?? 'OGAds Offer',

        'description' => $selectedOffer['description'] ?? '',

        'adcopy' => $selectedOffer['instructions'] ?? '',

        'country' => $selectedOffer['countries'] ?? '',

        'device' => $selectedOffer['devices'] ?? '',

        'link' => $networkOfferUrl,

        'picture' => $selectedOffer['image_url'] ?? '',

        'payout' => $networkPayout,
    ];

    try {
        $campaignId = syncOgadsOffer(
            $pdo,
            $networkId,
            $ogadsOffer
        );
    } catch (Throwable $e) {
        error_log(
            'PoketFlow OGAds synchronization error: '
            . $e->getMessage()
        );

        http_response_code(500);
        exit('Unable to prepare this offer. Please try again.');
    }

    if (!$campaignId) {
        http_response_code(502);
        exit('This offer could not be prepared.');
    }

    $campaignId = (int) $campaignId;

    $campaign = getCampaign($pdo, $campaignId);

    if (!$campaign) {
        http_response_code(404);
        exit('Offer not found.');
    }
}


/*
|--------------------------------------------------------------------------
| Check Current Database Eligibility
|--------------------------------------------------------------------------
|
| Do not trust the session copy as the final source of approval,
| reward eligibility, or campaign status.
|--------------------------------------------------------------------------
*/

if (!canStartCampaign($pdo, $campaign, $user)) {
    http_response_code(403);
    exit('This offer is not currently available for your account.');
}


/*
|--------------------------------------------------------------------------
| Verify the Current Database Destination
|--------------------------------------------------------------------------
*/

$databaseOfferUrl = trim(
    (string) ($campaign['network_offer_url'] ?? '')
);

if (!validateOfferDestination($databaseOfferUrl)) {
    http_response_code(502);
    exit('This offer is temporarily unavailable.');
}


/*
| Use the authoritative database destination rather than the
| potentially stale URL retained in the session.
*/

$networkOfferUrl = $databaseOfferUrl;


/*
|--------------------------------------------------------------------------
| Create OGAds Click
|--------------------------------------------------------------------------
*/

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
        'PoketFlow OGAds click error: '
        . $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to start this offer. Please try again.');
}


/*
|--------------------------------------------------------------------------
| Store Active Tracking Information
|--------------------------------------------------------------------------
*/

storeActiveOfferTracking(
    'ogads',
    $trackingId,
    $campaignId
);


/*
|--------------------------------------------------------------------------
| Append OGAds Tracking ID
|--------------------------------------------------------------------------
|
| Preserve the existing aff_sub4 parameter.
| Confirm the configured OGAds tracking format against your
| OGAds account before relying on live attribution.
|--------------------------------------------------------------------------
*/

$redirectUrl = appendTrackingParameter(
    $networkOfferUrl,
    'aff_sub4',
    $trackingId
);


/*
|--------------------------------------------------------------------------
| Redirect To OGAds
|--------------------------------------------------------------------------
*/

header('Location: ' . $redirectUrl, true, 302);
exit;
