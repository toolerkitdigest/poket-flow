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
// Read OGAds offer ID
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
// READ SESSION OFFER
// ==================================================

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
// Find selected offer
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
// BASIC OFFER VALIDATION
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
//
// Before synchronizing anything, look for an existing
// PoketFlow campaign.
//
// If the campaign exists, its database status is
// authoritative.
//
// We NEVER overwrite an existing campaign's
// status/approval decision just because OGAds is
// currently returning the offer.
//
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
// EXISTING CAMPAIGN
// ==================================================

if ($existingCampaignId !== false) {

    $campaignId = (int) $existingCampaignId;


    // --------------------------------------------------
    // Load actual database campaign
    // --------------------------------------------------

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


    // --------------------------------------------------
    // ADMIN STATUS IS AUTHORITATIVE
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
    // Final campaign safety check
    // --------------------------------------------------

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
// NEW CAMPAIGN
// ==================================================

} else {

    /*
     * This is a safe OGAds offer that has never
     * been stored in the campaigns table.
     *
     * We can create the campaign here so the
     * existing live-offer workflow continues to work.
     */

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


    // --------------------------------------------------
    // Load newly-created campaign
    // --------------------------------------------------

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


    // --------------------------------------------------
    // Final status check
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
    // Final campaign safety check
    // --------------------------------------------------

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
//
// This protects against an admin pausing the campaign
// after the earlier lookup but before the click is
// created.
//
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
// CREATE CAMPAIGN CLICK
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
