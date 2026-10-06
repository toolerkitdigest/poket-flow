<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';


// --------------------------------------------------
// Protect page
// --------------------------------------------------

if (!isLoggedIn()) {
    redirect('login.php');
}


// --------------------------------------------------
// Get logged-in user
// --------------------------------------------------

$userId = (int) $_SESSION['user_id'];

$user = getUser(
    $pdo,
    $userId
);


if (!$user) {

    $_SESSION = [];

    session_destroy();

    redirect('login.php');
}


// --------------------------------------------------
// Read network
// --------------------------------------------------

$network = strtolower(
    trim(
        (string) (
            $_GET['network']
            ?? 'ogads'
        )
    )
);


// --------------------------------------------------
// Read offer ID
// --------------------------------------------------

$offerId = filter_input(
    INPUT_GET,
    'offer_id',
    FILTER_VALIDATE_INT
);


if (!$offerId || $offerId < 1) {

    http_response_code(400);

    exit(
        'Invalid offer.'
    );
}


$offerId = (string) $offerId;


// ==================================================
// NETWORK ROUTING
// ==================================================

if (
    $network !== 'ogads' &&
    $network !== 'cpagrip'
) {

    http_response_code(400);

    exit(
        'Invalid offer network.'
    );
}


// ==================================================
// CPAGRIP
// ==================================================

if ($network === 'cpagrip') {

    // --------------------------------------------------
    // Find CPAGrip network
    // --------------------------------------------------

    $networkStmt = $pdo->prepare(
        'SELECT
            id,
            slug,
            status
         FROM networks
         WHERE slug = ?
         LIMIT 1'
    );


    $networkStmt->execute([
        'cpagrip'
    ]);


    $networkRow = $networkStmt->fetch();


    if (!$networkRow) {

        http_response_code(404);

        exit(
            'Offer network not found.'
        );
    }


    $networkStatus = strtoupper(
        trim(
            (string) (
                $networkRow['status']
                ?? ''
            )
        )
    );


    if ($networkStatus !== 'ACTIVE') {

        http_response_code(503);

        exit(
            'This offer network is temporarily unavailable.'
        );
    }


    $networkId = (int) $networkRow['id'];


    // --------------------------------------------------
    // Load campaign from database
    // --------------------------------------------------
    //
    // CPAGrip campaigns are synchronized into the
    // campaigns table by offers.php.
    //
    // The database is authoritative here.
    //
    // --------------------------------------------------

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
            worker_reward,
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
        $offerId
    ]);


    $campaign = $campaignStmt->fetch();


    if (!$campaign) {

        http_response_code(404);

        exit(
            'This offer is no longer available.'
        );
    }


    // --------------------------------------------------
    // Admin approval is authoritative
    // --------------------------------------------------

    $status = strtoupper(
        trim(
            (string) (
                $campaign['status']
                ?? ''
            )
        )
    );


    $approvalStatus = strtoupper(
        trim(
            (string) (
                $campaign['approval_status']
                ?? ''
            )
        )
    );


    if (
        $status !== 'ACTIVE' ||
        $approvalStatus !== 'APPROVED'
    ) {

        http_response_code(403);

        exit(
            'This offer is not currently available.'
        );
    }


    // --------------------------------------------------
    // Final safety / eligibility check
    // --------------------------------------------------

    if (!canStartCampaign(
        $pdo,
        $campaign,
        $user
    )) {

        http_response_code(403);

        exit(
            'This offer is not available for your account.'
        );
    }


    // --------------------------------------------------
    // Get destination URL
    // --------------------------------------------------

    $networkOfferUrl = trim(
        (string) (
            $campaign['network_offer_url']
            ?? ''
        )
    );


    if ($networkOfferUrl === '') {

        http_response_code(502);

        exit(
            'This offer is temporarily unavailable.'
        );
    }


    // --------------------------------------------------
    // Create campaign click
    // --------------------------------------------------

    try {

        $trackingId = createCampaignClick(
            $pdo,
            (int) $campaign['id'],
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

    } catch (Throwable $e) {

        http_response_code(500);

        exit(
            'Unable to start this offer. Please try again.'
        );
    }

    
$separator = 
    (strpos($campaign['network_offer_url'], '?') !== false)
    ? '&'
    : '?';

$redirectUrl = 
    $campaign['network_offer_url']
    . $separator
    . 'tracking_id='
    . rawurlencode($trackingId);


$_SESSION['active_campaign_tracking_id'] = $trackingId;
$_SESSION['active_campaign_id'] = (int) $campaign['id'];
$_SESSION['active_campaign_network'] = 'cpagrip';

header('Location: ' . $redirectUrl);
exit;

    // --------------------------------------------------
    // Store active tracking information
    // --------------------------------------------------

    $_SESSION['active_offer_tracking_id'] =
        $trackingId;


    $_SESSION['active_offer_campaign_id'] =
        (int) $campaign['id'];


    $_SESSION['active_offer_network'] =
        'cpagrip';


    // --------------------------------------------------
    // CPAGrip redirect
    // --------------------------------------------------
    //
    // IMPORTANT:
    //
    // We intentionally do NOT append tracking_id
    // to the CPAGrip URL yet.
    //
    // We will connect the CPAGrip postback/sub-ID
    // mechanism separately after confirming the exact
    // CPAGrip tracking method.
    //
    // --------------------------------------------------

    header(
        'Location: ' . $networkOfferUrl,
        true,
        302
    );

    exit;
}


// ==================================================
// OGADS
// ==================================================
//
// Existing OGAds workflow remains here.
//
// ==================================================


// --------------------------------------------------
// Read session offer
// --------------------------------------------------

$sessionOffers = $_SESSION['ogads_offers'] ?? null;


if (
    !is_array($sessionOffers) ||
    empty($sessionOffers)
) {

    http_response_code(404);

    exit(
        'Your offer list has expired. Please return to the Offers page and try again.'
    );
}


// --------------------------------------------------
// Find selected OGAds offer
// --------------------------------------------------

$selectedOffer = null;


foreach ($sessionOffers as $offer) {

    $externalOfferId = trim(
        (string) (
            $offer['external_offer_id']
            ?? ''
        )
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


// ==================================================
// BASIC OGADS OFFER VALIDATION
// ==================================================

$networkOfferUrl = trim(
    (string) (
        $selectedOffer['network_offer_url']
        ?? ''
    )
);


$networkPayout = (float) (
    $selectedOffer['network_payout']
    ?? 0
);


if ($networkOfferUrl === '') {

    http_response_code(502);

    exit(
        'This offer is temporarily unavailable.'
    );
}


if ($networkPayout <= 0) {

    http_response_code(400);

    exit(
        'This offer is currently unavailable.'
    );
}


// ==================================================
// RAW OGADS SAFETY CHECK
// ==================================================

$ogadsOfferForSafety = [

    'offerid' => $offerId,

    'name_short' => (
        $selectedOffer['title']
        ?? ''
    ),

    'name' => (
        $selectedOffer['title']
        ?? ''
    ),

    'description' => (
        $selectedOffer['description']
        ?? ''
    ),

    'adcopy' => (
        $selectedOffer['instructions']
        ?? ''
    ),

    'link' => $networkOfferUrl,

];


if (!isOgadsOfferSafe(
    $pdo,
    $ogadsOfferForSafety
)) {

    http_response_code(403);

    exit(
        'This offer is not available.'
    );
}


// ==================================================
// GET OGADS NETWORK
// ==================================================

try {

    $networkId = getOgadsNetworkId(
        $pdo
    );

} catch (Throwable $e) {

    http_response_code(500);

    exit(
        'Unable to connect the offer network. Please try again.'
    );
}


// ==================================================
// IMPORTANT ADMIN STATUS CHECK
// ==================================================

$campaignStmt = $pdo->prepare(
    'SELECT id
     FROM campaigns
     WHERE network_id = ?
       AND external_offer_id = ?
     LIMIT 1'
);


$campaignStmt->execute([
    $networkId,
    $offerId
]);


$existingCampaignId = $campaignStmt->fetchColumn();


// ==================================================
// EXISTING OGADS CAMPAIGN
// ==================================================

if ($existingCampaignId !== false) {

    $campaignId = (int) $existingCampaignId;


    $campaign = getCampaign(
        $pdo,
        $campaignId
    );


    if (!$campaign) {

        http_response_code(404);

        exit(
            'Offer not found.'
        );
    }


    $status = strtoupper(
        trim(
            (string) (
                $campaign['status']
                ?? ''
            )
        )
    );


    $approvalStatus = strtoupper(
        trim(
            (string) (
                $campaign['approval_status']
                ?? ''
            )
        )
    );


    if (
        $status !== 'ACTIVE' ||
        $approvalStatus !== 'APPROVED'
    ) {

        http_response_code(403);

        exit(
            'This offer is not currently available.'
        );
    }


    if (!isCampaignAllowed(
        $pdo,
        $campaign
    )) {

        http_response_code(403);

        exit(
            'This offer is not available.'
        );
    }


// ==================================================
// NEW OGADS CAMPAIGN
// ==================================================

} else {

    $ogadsOffer = [

        'offerid' => $offerId,

        'name_short' => (
            $selectedOffer['title']
            ?? 'OGAds Offer'
        ),

        'name' => (
            $selectedOffer['title']
            ?? 'OGAds Offer'
        ),

        'description' => (
            $selectedOffer['description']
            ?? ''
        ),

        'adcopy' => (
            $selectedOffer['instructions']
            ?? ''
        ),

        'country' => (
            $selectedOffer['countries']
            ?? ''
        ),

        'device' => (
            $selectedOffer['devices']
            ?? ''
        ),

        'link' => $networkOfferUrl,

        'picture' => (
            $selectedOffer['image_url']
            ?? ''
        ),

        'payout' => $networkPayout,

    ];


    try {

        $campaignId = syncOgadsOffer(
            $pdo,
            $networkId,
            $ogadsOffer
        );

    } catch (Throwable $e) {

        http_response_code(500);

        exit(
            'Unable to prepare this offer. Please try again.'
        );
    }


    if (!$campaignId) {

        http_response_code(502);

        exit(
            'This offer could not be prepared.'
        );
    }


    $campaign = getCampaign(
        $pdo,
        $campaignId
    );


    if (!$campaign) {

        http_response_code(404);

        exit(
            'Offer not found.'
        );
    }


    $status = strtoupper(
        trim(
            (string) (
                $campaign['status']
                ?? ''
            )
        )
    );


    $approvalStatus = strtoupper(
        trim(
            (string) (
                $campaign['approval_status']
                ?? ''
            )
        )
    );


    if (
        $status !== 'ACTIVE' ||
        $approvalStatus !== 'APPROVED'
    ) {

        http_response_code(403);

        exit(
            'This offer is not currently available.'
        );
    }


    if (!isCampaignAllowed(
        $pdo,
        $campaign
    )) {

        http_response_code(403);

        exit(
            'This offer is not available.'
        );
    }
}


// ==================================================
// FINAL FRESH DATABASE CHECK
// ==================================================

$freshCampaignStmt = $pdo->prepare(
    'SELECT
        id,
        status,
        approval_status
     FROM campaigns
     WHERE id = ?
     LIMIT 1'
);


$freshCampaignStmt->execute([
    $campaignId
]);


$freshCampaign = $freshCampaignStmt->fetch();


if (!$freshCampaign) {

    http_response_code(404);

    exit(
        'Offer not found.'
    );
}


$freshStatus = strtoupper(
    trim(
        (string) (
            $freshCampaign['status']
            ?? ''
        )
    )
);


$freshApprovalStatus = strtoupper(
    trim(
        (string) (
            $freshCampaign['approval_status']
            ?? ''
        )
    )
);


if (
    $freshStatus !== 'ACTIVE' ||
    $freshApprovalStatus !== 'APPROVED'
) {

    http_response_code(403);

    exit(
        'This offer is no longer available.'
    );
}


// ==================================================
// CREATE OGADS CAMPAIGN CLICK
// ==================================================

try {

    $trackingId = createCampaignClick(
        $pdo,
        $campaignId,
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );

} catch (Throwable $e) {

    http_response_code(500);

    exit(
        'Unable to start this offer. Please try again.'
    );
}


// ==================================================
// STORE ACTIVE TRACKING INFORMATION
// ==================================================

$_SESSION['active_offer_tracking_id'] =
    $trackingId;


$_SESSION['active_offer_campaign_id'] =
    $campaignId;


$_SESSION['active_offer_network'] =
    'ogads';


// ==================================================
// ADD OGADS TRACKING ID
// ==================================================

$separator = (
    strpos($networkOfferUrl, '?') !== false
)
    ? '&'
    : '?';


$networkOfferUrl .=
    $separator .
    'aff_sub4=' .
    rawurlencode($trackingId);


// ==================================================
// REDIRECT TO OGADS
// ==================================================

header(
    'Location: ' . $networkOfferUrl,
    true,
    302
);

exit;
