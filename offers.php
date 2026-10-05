<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';
require_once __DIR__ . '/includes/cpagrip.php';

// --------------------------------------------------
// Protect offers page
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

$ogadsError = null;
$cpagripError = null;


// --------------------------------------------------
// Safety check
// --------------------------------------------------

if (!$user) {

    $_SESSION = [];

    session_destroy();

    redirect('login.php');
}


// --------------------------------------------------
// Get real wallet balance
// --------------------------------------------------

$availableBalance = getUserBalance(
    $pdo,
    $userId
);


// ==================================================
// ALL DISPLAY CAMPAIGNS
// ==================================================

$campaigns = [];


// ==================================================
// FETCH LIVE VISITOR-SPECIFIC OGADS OFFERS
// ==================================================

try {

    $ip = isset($_SERVER['REMOTE_ADDR'])
        ? $_SERVER['REMOTE_ADDR']
        : '';

    $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
        ? $_SERVER['HTTP_USER_AGENT']
        : '';

    $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        ? $_SERVER['HTTP_ACCEPT_LANGUAGE']
        : '';

    $scheme = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) ? 'https' : 'http';

    $site = $scheme . '://' . (
        isset($_SERVER['HTTP_HOST'])
            ? $_SERVER['HTTP_HOST']
            : 'poketflow.com'
    ) . (
        isset($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '/offers.php'
    );


    // --------------------------------------------------
    // Get OGAds network ID
    // --------------------------------------------------

    $networkId = getOgadsNetworkId(
        $pdo
    );


    // --------------------------------------------------
    // Ask OGAds for this visitor's live inventory
    // --------------------------------------------------

    $ogadsOffers = fetchOgadsOffers(
        $ip,
        $userAgent,
        $language,
        $site,
        0,
        100
    );


    // --------------------------------------------------
    // Process visitor-specific offers
    // --------------------------------------------------

    foreach ($ogadsOffers as $offer) {

        $externalOfferId = trim(
            (string) (
                isset($offer['offerid'])
                    ? $offer['offerid']
                    : ''
            )
        );


        if ($externalOfferId === '') {
            continue;
        }


        // ----------------------------------------------
        // Clean offer information
        // ----------------------------------------------

        $title = cleanOgadsText(
            isset($offer['name_short'])
                ? $offer['name_short']
                : (
                    isset($offer['name'])
                        ? $offer['name']
                        : 'OGAds Offer'
                )
        );


        $description = cleanOgadsText(
            isset($offer['description'])
                ? $offer['description']
                : ''
        );


        $instructions = cleanOgadsText(
            isset($offer['adcopy'])
                ? $offer['adcopy']
                : ''
        );


        $category = getOgadsOfferCategory(
            $offer
        );


        $countries = trim(
            (string) (
                isset($offer['country'])
                    ? $offer['country']
                    : ''
            )
        );


        $devices = trim(
            (string) (
                isset($offer['device'])
                    ? $offer['device']
                    : ''
            )
        );


        $networkOfferUrl = trim(
            (string) (
                isset($offer['link'])
                    ? $offer['link']
                    : ''
            )
        );


        $imageUrl = trim(
            (string) (
                isset($offer['picture'])
                    ? $offer['picture']
                    : ''
            )
        );


        $networkPayout = round(
            (float) (
                isset($offer['payout'])
                    ? $offer['payout']
                    : 0
            ),
            2
        );


        // ----------------------------------------------
        // Must have a payout
        // ----------------------------------------------

        if ($networkPayout <= 0) {
            continue;
        }


        // ----------------------------------------------
        // Must have participation URL
        // ----------------------------------------------

        if ($networkOfferUrl === '') {
            continue;
        }


        // ----------------------------------------------
        // Apply PoketFlow safety filters
        // ----------------------------------------------

        if (!isOgadsOfferSafe(
            $pdo,
            $offer
        )) {
            continue;
        }


        // ----------------------------------------------
        // Check existing PoketFlow campaign
        // ----------------------------------------------

        $existingCampaignStmt = $pdo->prepare(
            'SELECT
                id,
                status,
                approval_status
             FROM campaigns
             WHERE network_id = ?
               AND external_offer_id = ?
             LIMIT 1'
        );

        $existingCampaignStmt->execute([
            $networkId,
            $externalOfferId
        ]);

        $existingCampaign = $existingCampaignStmt->fetch();


        if ($existingCampaign) {

            $existingStatus = strtoupper(
                trim(
                    (string) (
                        isset($existingCampaign['status'])
                            ? $existingCampaign['status']
                            : ''
                    )
                )
            );


            $existingApproval = strtoupper(
                trim(
                    (string) (
                        isset($existingCampaign['approval_status'])
                            ? $existingCampaign['approval_status']
                            : ''
                    )
                )
            );


            // ------------------------------------------
            // Admin control is authoritative
            // ------------------------------------------

            if (
                $existingStatus !== 'ACTIVE' ||
                $existingApproval !== 'APPROVED'
            ) {
                continue;
            }


            // ------------------------------------------
            // Load database campaign
            // ------------------------------------------

            $databaseCampaign = getCampaign(
                $pdo,
                (int) $existingCampaign['id']
            );


            if (!$databaseCampaign) {
                continue;
            }


            // ------------------------------------------
            // Final database safety check
            // ------------------------------------------

            if (!isCampaignAllowed(
                $pdo,
                $databaseCampaign
            )) {
                continue;
            }
        }


        // ----------------------------------------------
        // Calculate worker reward
        // ----------------------------------------------

        $rewards = calculateOgadsReward(
            $pdo,
            $networkPayout
        );


        // ----------------------------------------------
        // Build visitor-specific OGAds offer
        // ----------------------------------------------

        $campaigns[] = [

            'id' => $externalOfferId,

            'source_type' => 'CPA_NETWORK',

            'network_id' => $networkId,

            'network' => 'OGAds',

            'external_offer_id' => $externalOfferId,

            'network_offer_url' => $networkOfferUrl,

            'image_url' => $imageUrl,

            'title' => $title,

            'description' => $description,

            'category' => $category,

            'instructions' => $instructions,

            'network_payout' => $networkPayout,

            'reward_rate' => $rewards['reward_rate'],

            'worker_reward' => $rewards['worker_reward'],

            'platform_margin' => $rewards['platform_margin'],

            'countries' => $countries,

            'devices' => $devices,

            'os' => '',

            'incentive_allowed' => 1,

            'status' => 'ACTIVE',

            'approval_status' => 'APPROVED',

        ];
    }


    // --------------------------------------------------
    // Store current visitor's eligible OGAds offers
    // --------------------------------------------------

    $_SESSION['ogads_offers'] = $campaigns;


} catch (Throwable $e) {

    $ogadsError = $e->getMessage();

    $_SESSION['ogads_offers'] = [];
}


// ==================================================
// UI HELPERS
// ==================================================


/**
 * Create a short, clean description for the offer card.
 *
 * We intentionally do NOT display raw network metadata.
 */
function getShortOfferDescription(
    string $description,
    string $title
): string {

    $description = trim($description);

    $technicalMarkers = [
        '/\bConversion\s*:/i',
        '/\bofferwall_description\s*=/i',
        '/\bofferwall_instructions\s*=/i',
        '/\bofferwall_category\s*=/i',
        '/\btracking_type\s*=/i',
        '/\bofferwall_/i',
    ];

    foreach ($technicalMarkers as $pattern) {

        $cleaned = preg_split(
            $pattern,
            $description,
            2
        );

        if (
            is_array($cleaned) &&
            isset($cleaned[0])
        ) {
            $description = trim($cleaned[0]);
        }
    }


    $description = preg_replace(
        '/\s+/u',
        ' ',
        $description
    );

    $description = trim(
        (string) $description
    );


    $description = trim(
        $description,
        " \t\n\r\0\x0B.,;:-"
    );


    if ($description === '') {
        return 'Complete this offer to earn your reward.';
    }


    if (
        function_exists('mb_strlen') &&
        mb_strlen($description) > 125
    ) {

        $description = mb_substr(
            $description,
            0,
            125
        );

        $description = rtrim(
            $description,
            " \t\n\r\0\x0B.,;:-"
        );

        $description .= '...';
    }


    return $description;
}


// ==================================================
// FETCH + SYNCHRONIZE CPAGRIP OFFERS
// ==================================================

try {

    // --------------------------------------------------
    // Load CPAGrip live visitor-specific feed
    // --------------------------------------------------

    $cpagripOffers = require __DIR__ . '/includes/cpagrip.php';

    if (!is_array($cpagripOffers)) {
        $cpagripOffers = [];
    }


    // --------------------------------------------------
    // Get CPAGrip network ID
    //
    // IMPORTANT:
    // We use the slug instead of hard-coding ID 2.
    // --------------------------------------------------

    $cpagripNetworkStmt = $pdo->prepare(
        'SELECT id
         FROM networks
         WHERE slug = ?
           AND status = ?
         LIMIT 1'
    );

    $cpagripNetworkStmt->execute([
        'cpagrip',
        'ACTIVE'
    ]);

    $cpagripNetwork = $cpagripNetworkStmt->fetch();


    if (!$cpagripNetwork) {
        throw new RuntimeException(
            'CPAGrip network is not configured.'
        );
    }


    $cpagripNetworkId = (int) $cpagripNetwork['id'];


    // --------------------------------------------------
    // Process every live CPAGrip offer
    // --------------------------------------------------

    foreach ($cpagripOffers as $offer) {

        if (!is_array($offer)) {
            continue;
        }


        // ----------------------------------------------
        // External CPAGrip offer ID
        // ----------------------------------------------

        $externalOfferId = trim(
            (string) (
                isset($offer['offer_id'])
                    ? $offer['offer_id']
                    : ''
            )
        );


        if ($externalOfferId === '') {
            continue;
        }


        // ----------------------------------------------
        // Offer information
        // ----------------------------------------------

        $title = trim(
            (string) (
                isset($offer['title'])
                    ? $offer['title']
                    : 'CPAGrip Offer'
            )
        );


        $description = trim(
            (string) (
                isset($offer['description'])
                    ? $offer['description']
                    : ''
            )
        );


        $category = trim(
            (string) (
                isset($offer['category'])
                    ? $offer['category']
                    : 'Offer'
            )
        );


        $networkOfferUrl = trim(
            (string) (
                isset($offer['offerlink'])
                    ? $offer['offerlink']
                    : ''
            )
        );


        $imageUrl = trim(
            (string) (
                isset($offer['image'])
                    ? $offer['image']
                    : ''
            )
        );


        $offerType = trim(
            (string) (
                isset($offer['type'])
                    ? $offer['type']
                    : ''
            )
        );


        $countries = trim(
            (string) (
                isset($offer['accepted_countries'])
                    ? $offer['accepted_countries']
                    : ''
            )
        );


        // ----------------------------------------------
        // Validate basic offer information
        // ----------------------------------------------

        if ($title === '') {
            continue;
        }


        if ($networkOfferUrl === '') {
            continue;
        }


        // ----------------------------------------------
        // Network payout
        // ----------------------------------------------

        $networkPayout = round(
            (float) (
                isset($offer['payout'])
                    ? $offer['payout']
                    : 0
            ),
            2
        );


        if ($networkPayout <= 0) {
            continue;
        }


        // ----------------------------------------------
        // PoketFlow reward
        // ----------------------------------------------

        $workerReward = round(
            (float) (
                isset($offer['reward'])
                    ? $offer['reward']
                    : 0
            ),
            2
        );


        $platformMargin = round(
            $networkPayout - $workerReward,
            2
        );


        $rewardRate = $networkPayout > 0
            ? round(
                ($workerReward / $networkPayout) * 100,
                2
            )
            : 0;


        // ==================================================
        // SAFETY CHECK BEFORE DATABASE INSERT
        //
        // isCampaignAllowed() normally requires APPROVED.
        // For a new CPAGrip offer we temporarily present
        // it as approved ONLY for the safety-filter check.
        //
        // It will actually be stored as PENDING.
        // ==================================================

        $safetyCandidate = [

            'source_type' => 'CPA_NETWORK',

            'network_id' => $cpagripNetworkId,

            'external_offer_id' => $externalOfferId,

            'network_offer_url' => $networkOfferUrl,

            'image_url' => $imageUrl,

            'title' => $title,

            'description' => $description,

            'category' => $category,

            'instructions' => '',

            'network_payout' => $networkPayout,

            'reward_rate' => $rewardRate,

            'worker_reward' => $workerReward,

            'platform_margin' => $platformMargin,

            'countries' => $countries,

            'devices' => $offerType,

            'os' => '',

            'incentive_allowed' => 1,

            'status' => 'ACTIVE',

            'approval_status' => 'APPROVED',

        ];


        if (!isCampaignAllowed(
            $pdo,
            $safetyCandidate
        )) {
            continue;
        }


        // ==================================================
        // FIND EXISTING CPAGRIP CAMPAIGN
        // ==================================================

        $existingCampaignStmt = $pdo->prepare(
            'SELECT
                id,
                status,
                approval_status
             FROM campaigns
             WHERE network_id = ?
               AND external_offer_id = ?
             LIMIT 1'
        );

        $existingCampaignStmt->execute([
            $cpagripNetworkId,
            $externalOfferId
        ]);

        $existingCampaign = $existingCampaignStmt->fetch();


        // ==================================================
        // EXISTING CAMPAIGN
        // ==================================================

        if ($existingCampaign) {

            $campaignId = (int) $existingCampaign['id'];


            // ----------------------------------------------
            // Update operational offer information.
            //
            // IMPORTANT:
            // status and approval_status are NOT changed.
            // This protects administrator decisions.
            // ----------------------------------------------

            $updateCampaignStmt = $pdo->prepare(
                'UPDATE campaigns
                 SET
                    network_offer_url = ?,
                    image_url = ?,
                    title = ?,
                    description = ?,
                    category = ?,
                    instructions = ?,
                    network_payout = ?,
                    reward_rate = ?,
                    worker_reward = ?,
                    platform_margin = ?,
                    countries = ?,
                    devices = ?,
                    os = ?,
                    incentive_allowed = ?,
                    updated_at = NOW()
                 WHERE id = ?'
            );

            $updateCampaignStmt->execute([

                $networkOfferUrl,

                $imageUrl,

                $title,

                $description,

                $category,

                '',

                $networkPayout,

                $rewardRate,

                $workerReward,

                $platformMargin,

                $countries,

                $offerType,

                '',

                1,

                $campaignId,

            ]);


            // ----------------------------------------------
            // Reload authoritative DB campaign
            // ----------------------------------------------

            $databaseCampaign = getCampaign(
                $pdo,
                $campaignId
            );


            if (!$databaseCampaign) {
                continue;
            }


            $existingStatus = strtoupper(
                trim(
                    (string) (
                        isset($databaseCampaign['status'])
                            ? $databaseCampaign['status']
                            : ''
                    )
                )
            );


            $existingApproval = strtoupper(
                trim(
                    (string) (
                        isset($databaseCampaign['approval_status'])
                            ? $databaseCampaign['approval_status']
                            : ''
                    )
                )
            );


            // ----------------------------------------------
            // Only approved active campaigns are visible
            // ----------------------------------------------

            if (
                $existingStatus !== 'ACTIVE' ||
                $existingApproval !== 'APPROVED'
            ) {
                continue;
            }


            // ----------------------------------------------
            // Final safety check
            // ----------------------------------------------

            if (!isCampaignAllowed(
                $pdo,
                $databaseCampaign
            )) {
                continue;
            }


            $databaseCampaign['network'] = 'CPAGrip';


            $campaigns[] = $databaseCampaign;


            continue;
        }


        // ==================================================
        // NEW CPAGRIP CAMPAIGN
        // ==================================================
        //
        // New network offers enter as PENDING.
        //
        // They will NOT appear to workers until an admin
        // approves them.
        // ==================================================

        $insertCampaignStmt = $pdo->prepare(
            'INSERT INTO campaigns (
                source_type,
                advertiser_id,
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
                budget,
                spent,
                countries,
                devices,
                os,
                incentive_allowed,
                status,
                approval_status,
                start_at,
                end_at,
                created_at,
                updated_at
             ) VALUES (
                ?,
                NULL,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NULL,
                0,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NULL,
                NULL,
                NOW(),
                NOW()
             )'
        );


        $insertCampaignStmt->execute([

            'CPA_NETWORK',

            $cpagripNetworkId,

            $externalOfferId,

            $networkOfferUrl,

            $imageUrl,

            $title,

            $description,

            $category,

            '',

            $networkPayout,

            $rewardRate,

            $workerReward,

            $platformMargin,

            $countries,

            $offerType,

            '',

            1,

            'ACTIVE',

            'PENDING',

        ]);
    }


} catch (Throwable $e) {

    $cpagripError = $e->getMessage();
}


// ==================================================
// UI HELPERS
// ==================================================


// --------------------------------------------------
// Determine offer icon
// --------------------------------------------------

function getOfferIcon(string $category): string
{
    $category = strtolower(trim($category));

    if (
        strpos($category, 'app') !== false ||
        strpos($category, 'install') !== false
    ) {
        return '◎';
    }

    if (strpos($category, 'survey') !== false) {
        return '▤';
    }

    if (strpos($category, 'submit') !== false) {
        return '◇';
    }

    return '◆';
}


// --------------------------------------------------
// Determine icon class
// --------------------------------------------------

function getOfferIconClass(string $category): string
{
    $category = strtolower(trim($category));

    if (strpos($category, 'survey') !== false) {
        return 'orange';
    }

    if (
        strpos($category, 'special') !== false ||
        strpos($category, 'featured') !== false
    ) {
        return 'cyan';
    }

    return '';
}


// --------------------------------------------------
// Format offer category
// --------------------------------------------------

function getOfferCategory(array $campaign): string
{
    $category = trim(
        (string) (
            isset($campaign['category'])
                ? $campaign['category']
                : ''
        )
    );

    if ($category !== '') {
        return $category;
    }


    if (
        isset($campaign['source_type']) &&
        $campaign['source_type'] === 'DIRECT_ADVERTISER'
    ) {
        return 'Special Offer';
    }


    return 'Offer';
}

?><!doctype html>

<html lang="en"><head><meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Offers — PoketFlow</title>

<link
    rel="stylesheet"
    href="assets/poketflow.css"
>

<link
    rel="stylesheet"
    href="assets/offers.css"
>

</head><body class="app-page"><!-- ==================================================
     HEADER
================================================== --><header class="app-header"><a
    class="brand"
    href="index.php"
>

    <span class="brand-mark">
        P
    </span>

    <span>
        Poket<span>Flow</span>
    </span>

</a>


<nav class="app-header-nav">

    <a href="dashboard.php">
        Home
    </a>

    <a
        class="active"
        href="offers.php"
    >
        Earn
    </a>

    <a href="history.php">
        History
    </a>

    <a href="referrals.php">
        Refer & Earn
    </a>

    <a href="withdraw.php">
        Withdraw
    </a>

</nav>


<div class="header-actions">

    <a
        class="btn btn-primary balance-button"
        href="withdraw.php"
    >
        Balance $<?= number_format($availableBalance, 2) ?>
    </a>


    <button
        type="button"
        class="mobile-menu-button"
        id="mobileMenuButton"
        aria-label="Open navigation menu"
        aria-expanded="false"
        aria-controls="mobileNavigation"
    >
        <span></span>
        <span></span>
        <span></span>
    </button>

</div>

</header><!-- ==================================================
     MOBILE NAVIGATION
================================================== --><div
    class="mobile-navigation"
    id="mobileNavigation"
    aria-hidden="true"
><nav>

    <a href="dashboard.php">
        <span class="mobile-nav-icon">⌂</span>
        <span>Home</span>
    </a>


    <a
        class="active"
        href="offers.php"
    >
        <span class="mobile-nav-icon">▦</span>
        <span>Earn Rewards</span>
    </a>


    <a href="history.php">
        <span class="mobile-nav-icon">◷</span>
        <span>History</span>
    </a>


    <a href="referrals.php">
        <span class="mobile-nav-icon">♧</span>
        <span>Refer & Earn</span>
    </a>


    <a href="withdraw.php">
        <span class="mobile-nav-icon">▣</span>
        <span>Withdraw</span>
    </a>


    <a href="logout.php">
        <span class="mobile-nav-icon">↪</span>
        <span>Log Out</span>
    </a>

</nav>

</div><!-- ==================================================
     APP LAYOUT
================================================== --><main class="app-shell"><aside class="sidebar">

    <div class="sidebar-nav">

        <a href="dashboard.php">
            ⌂
            <span>Home</span>
        </a>


        <a
            class="active"
            href="offers.php"
        >
            ▦
            <span>Offers</span>
        </a>


        <a href="history.php">
            ◷
            <span>History</span>
        </a>


        <a href="referrals.php">
            ♧
            <span>Refer & Earn</span>
        </a>


        <a href="withdraw.php">
            ▣
            <span>Withdraw</span>
        </a>


        <a href="logout.php">
            ↪
            <span>Log Out</span>
        </a>

    </div>


    <div class="side-balance">

        <small>
            Your Balance
        </small>


        <strong>
            $<?= number_format($availableBalance, 2) ?>
        </strong>


        <span>
            Available to withdraw
        </span>


        <a href="withdraw.php">
            Withdraw Funds →
        </a>

    </div>

</aside>


<section class="app-content">


    <div class="page-title">

        <div>

            <span class="kicker">
                EARN REWARDS
            </span>


            <h1>
                Offers
            </h1>


            <p>
                Complete available offers to grow your balance.
                The list refreshes automatically.
            </p>

        </div>

    </div>


    <?php if (
        $ogadsError !== null &&
        $cpagripError !== null
    ): ?>

        <div class="info-card">

            <h3>
                Offers temporarily unavailable
            </h3>

            <p>
                We could not refresh the offer list right now.
                Please try again shortly.
            </p>

        </div>

    <?php endif; ?>


    <div class="offer-tabs">

        <button
            type="button"
            class="selected"
            data-filter="all"
        >
            All Offers
        </button>


        <button
            type="button"
            data-filter="app"
        >
            App Install
        </button>


        <button
            type="button"
            data-filter="survey"
        >
            Survey
        </button>


        <button
            type="button"
            data-filter="other"
        >
            Other Offers
        </button>

    </div>


    <div class="dashboard-grid">


        <div class="offer-grid dashboard-offers">


            <?php if (empty($campaigns)): ?>


                <article class="offer-card empty-offer-card">

                    <div class="offer-icon">
                        ◷
                    </div>


                    <div class="offer-body">

                        <span class="offer-network">
                            PoketFlow
                        </span>


                        <h3>
                            No offers available right now
                        </h3>


                        <p>
                            There are currently no approved offers
                            available for your location.
                            Please check again later.
                        </p>

                    </div>


                    <div class="offer-bottom">

                        <strong>
                            Check back soon
                        </strong>

                    </div>

                </article>


            <?php else: ?>


                <?php foreach ($campaigns as $campaign): ?>

                    <?php

                    $category = getOfferCategory(
                        $campaign
                    );


                    $icon = getOfferIcon(
                        $category
                    );


                    $iconClass = getOfferIconClass(
                        $category
                    );


                    $title = trim(
                        (string) (
                            isset($campaign['title'])
                                ? $campaign['title']
                                : 'Available Offer'
                        )
                    );


                    $description = getShortOfferDescription(
                        (string) (
                            isset($campaign['description'])
                                ? $campaign['description']
                                : ''
                        ),
                        $title
                    );


                    $reward = (float) (
                        isset($campaign['worker_reward'])
                            ? $campaign['worker_reward']
                            : 0
                    );


                    $imageUrl = trim(
                        (string) (
                            isset($campaign['image_url'])
                                ? $campaign['image_url']
                                : ''
                        )
                    );


                    $networkName = trim(
                        (string) (
                            isset($campaign['network'])
                                ? $campaign['network']
                                : 'PoketFlow'
                        )
                    );


                    $networkIdForCard = (int) (
                        isset($campaign['network_id'])
                            ? $campaign['network_id']
                            : 0
                    );

                    ?>


                    <article
                        class="offer-card"
                        data-offer-category="<?= e(strtolower($category)) ?>"
                    >

                        <div
                            class="offer-image <?= e($iconClass) ?>"
                        >

                            <?php if ($imageUrl !== ''): ?>

                                <img
                                    src="<?= e($imageUrl) ?>"
                                    alt=""
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="offer-fallback-icon">
                                    <?= e($icon) ?>
                                </div>

                            <?php endif; ?>

                        </div>


                        <div class="offer-body">

                            <span class="offer-network">
                                <?= e($networkName) ?>
                            </span>


                            <h3>
                                <?= e($title) ?>
                            </h3>


                            <p>
                                <?= e($description) ?>
                            </p>

                        </div>


                        <div class="offer-bottom">

                            <strong>
                                Earn
                                $<?= number_format($reward, 2) ?>
                            </strong>


                            <?php if (
                                $networkIdForCard > 0 &&
                                strtolower($networkName) === 'cpagrip'
                            ): ?>

                                <a
                                    href="start-offer.php?network=cpagrip&offer_id=<?= e((string) $campaign['external_offer_id']) ?>"
                                    class="btn btn-primary"
                                >
                                    Start →
                                </a>

                            <?php else: ?>

                                <a
                                    href="start-offer.php?network=ogads&offer_id=<?= e((string) $campaign['external_offer_id']) ?>"
                                    class="btn btn-primary"
                                >
                                    Start →
                                </a>

                            <?php endif; ?>

                        </div>


                    </article>


                <?php endforeach; ?>


            <?php endif; ?>


        </div>


        <aside class="info-card">

            <div class="info-icon">
                ◷
            </div>


            <h3>
                Track your completions
            </h3>


            <p>
                Visit Offer History to see your recent
                activity and completion status.
            </p>


            <a href="history.php">
                View Offer History →
            </a>


            <hr>


            <small>
                Completion tracking can take some time
                depending on the offer.
            </small>


        </aside>


    </div>


</section>

</main><script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const filterButtons =
            document.querySelectorAll(
                '.offer-tabs button'
            );


        const offerCards =
            document.querySelectorAll(
                '.dashboard-offers .offer-card[data-offer-category]'
            );


        filterButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        const filter =
                            button.dataset.filter;


                        filterButtons.forEach(
                            function (item) {

                                item.classList.remove(
                                    'selected'
                                );

                            }
                        );


                        button.classList.add(
                            'selected'
                        );


                        offerCards.forEach(
                            function (card) {

                                const category =
                                    (
                                        card.dataset.offerCategory ||
                                        ''
                                    ).toLowerCase();


                                let show = false;


                                if (filter === 'all') {

                                    show = true;

                                }


                                if (filter === 'app') {

                                    show =
                                        category.indexOf('app') !== -1 ||
                                        category.indexOf('install') !== -1;

                                }


                                if (filter === 'survey') {

                                    show =
                                        category.indexOf('survey') !== -1;

                                }


                                if (filter === 'other') {

                                    show =
                                        category.indexOf('app') === -1 &&
                                        category.indexOf('install') === -1 &&
                                        category.indexOf('survey') === -1;

                                }


                                card.style.display =
                                    show ? '' : 'none';

                            }
                        );

                    }
                );

            }
        );


        const menuButton =
            document.getElementById(
                'mobileMenuButton'
            );


        const mobileNavigation =
            document.getElementById(
                'mobileNavigation'
            );


        if (
            menuButton &&
            mobileNavigation
        ) {

            menuButton.addEventListener(
                'click',
                function () {

                    const isOpen =
                        menuButton.classList.toggle(
                            'open'
                        );


                    mobileNavigation.classList.toggle(
                        'open',
                        isOpen
                    );


                    menuButton.setAttribute(
                        'aria-expanded',
                        isOpen ? 'true' : 'false'
                    );


                    mobileNavigation.setAttribute(
                        'aria-hidden',
                        isOpen ? 'false' : 'true'
                    );

                }
            );


            mobileNavigation
                .querySelectorAll('a')
                .forEach(
                    function (link) {

                        link.addEventListener(
                            'click',
                            function () {

                                menuButton.classList.remove(
                                    'open'
                                );

                                mobileNavigation.classList.remove(
                                    'open'
                                );

                                menuButton.setAttribute(
                                    'aria-expanded',
                                    'false'
                                );

                                mobileNavigation.setAttribute(
                                    'aria-hidden',
                                    'true'
                                );

                            }
                        );

                    }
                );


            document.addEventListener(
                'click',
                function (event) {

                    if (
                        !mobileNavigation.contains(event.target) &&
                        !menuButton.contains(event.target)
                    ) {

                        menuButton.classList.remove(
                            'open'
                        );

                        mobileNavigation.classList.remove(
                            'open'
                        );

                        menuButton.setAttribute(
                            'aria-expanded',
                            'false'
                        );

                        mobileNavigation.setAttribute(
                            'aria-hidden',
                            'true'
                        );

                    }

                }
            );

        }

    }
);

</script>
</body>
</html>
