<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';


// ==================================================
// PROTECT OFFERS PAGE
// ==================================================

if (!isLoggedIn()) {
    redirect('login.php');
}


// ==================================================
// GET LOGGED-IN USER
// ==================================================

$userId = (int) $_SESSION['user_id'];

$user = getUser(
    $pdo,
    $userId
);

$ogadsError = null;


// ==================================================
// SAFETY CHECK
// ==================================================

if (!$user) {

    $_SESSION = [];

    session_destroy();

    redirect('login.php');
}


// ==================================================
// GET REAL WALLET BALANCE
// ==================================================

$availableBalance = getUserBalance(
    $pdo,
    $userId
);


// ==================================================
// FETCH LIVE VISITOR-SPECIFIC OGADS OFFERS
// ==================================================

$campaigns = [];

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
    // GET OGADS NETWORK ID
    // --------------------------------------------------

    $networkId = getOgadsNetworkId(
        $pdo
    );


    // --------------------------------------------------
    // ASK OGADS FOR THIS VISITOR'S LIVE INVENTORY
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
    // PROCESS VISITOR-SPECIFIC OFFERS
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
        // CLEAN OFFER INFORMATION
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
        // MUST HAVE PAYOUT
        // ----------------------------------------------

        if ($networkPayout <= 0) {
            continue;
        }


        // ----------------------------------------------
        // MUST HAVE PARTICIPATION URL
        // ----------------------------------------------

        if ($networkOfferUrl === '') {
            continue;
        }


        // ----------------------------------------------
        // APPLY POKETFLOW SAFETY FILTERS
        // ----------------------------------------------

        if (!isOgadsOfferSafe(
            $pdo,
            $offer
        )) {
            continue;
        }


        // ==================================================
        // CHECK EXISTING POKETFLOW CAMPAIGN
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
            $networkId,
            $externalOfferId
        ]);

        $existingCampaign = $existingCampaignStmt->fetch();


        // --------------------------------------------------
        // EXISTING CAMPAIGN
        // --------------------------------------------------

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


            // ----------------------------------------------
            // ADMIN CONTROL IS AUTHORITATIVE
            // ----------------------------------------------

            if (
                $existingStatus !== 'ACTIVE' ||
                $existingApproval !== 'APPROVED'
            ) {
                continue;
            }


            // ----------------------------------------------
            // LOAD DATABASE CAMPAIGN
            // ----------------------------------------------

            $databaseCampaign = getCampaign(
                $pdo,
                (int) $existingCampaign['id']
            );


            if (!$databaseCampaign) {
                continue;
            }


            // ----------------------------------------------
            // FINAL DATABASE SAFETY CHECK
            // ----------------------------------------------

            if (!isCampaignAllowed(
                $pdo,
                $databaseCampaign
            )) {
                continue;
            }
        }


        // ----------------------------------------------
        // CALCULATE WORKER REWARD
        // ----------------------------------------------

        $rewards = calculateOgadsReward(
            $pdo,
            $networkPayout
        );


        // ----------------------------------------------
        // BUILD VISITOR-SPECIFIC OFFER
        // ----------------------------------------------

        $campaigns[] = [

            'id' => $externalOfferId,

            'source_type' => 'CPA_NETWORK',

            'network_id' => $networkId,

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
    // STORE CURRENT VISITOR'S ELIGIBLE OFFERS
    // --------------------------------------------------

    $_SESSION['ogads_offers'] = $campaigns;


} catch (Throwable $e) {

    $ogadsError = $e->getMessage();

    $_SESSION['ogads_offers'] = [];

    $campaigns = [];
}


// ==================================================
// UI HELPERS
// ==================================================

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
            $description = trim(
                $cleaned[0]
            );
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
        mb_strlen($description) > 135
    ) {

        $description = mb_substr(
            $description,
            0,
            135
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
// PHP 7.2-SAFE STRING CONTAINS HELPER
// ==================================================

function offerContains(
    string $haystack,
    string $needle
): bool {

    return $needle !== '' &&
        strpos(
            $haystack,
            $needle
        ) !== false;
}


// ==================================================
// DETERMINE OFFER ICON
// ==================================================

function getOfferIcon(
    string $category
): string {

    $category = strtolower(
        trim($category)
    );


    if (
        offerContains($category, 'app') ||
        offerContains($category, 'install')
    ) {
        return 'APP';
    }


    if (
        offerContains($category, 'survey')
    ) {
        return '?';
    }


    if (
        offerContains($category, 'submit')
    ) {
        return '@';
    }


    return '★';
}


// ==================================================
// DETERMINE ICON CLASS
// ==================================================

function getOfferIconClass(
    string $category
): string {

    $category = strtolower(
        trim($category)
    );


    if (
        offerContains($category, 'survey')
    ) {
        return 'orange';
    }


    if (
        offerContains($category, 'special') ||
        offerContains($category, 'featured')
    ) {
        return 'cyan';
    }


    if (
        offerContains($category, 'app') ||
        offerContains($category, 'install')
    ) {
        return 'purple';
    }


    return '';
}


// ==================================================
// FORMAT OFFER CATEGORY
// ==================================================

function getOfferCategory(
    array $campaign
): string {

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


// ==================================================
// COUNT OFFER TYPES
// ==================================================

$totalOffers = count($campaigns);

$appOffers = 0;
$surveyOffers = 0;

foreach ($campaigns as $countCampaign) {

    $countCategory = strtolower(
        getOfferCategory(
            $countCampaign
        )
    );


    if (
        offerContains($countCategory, 'app') ||
        offerContains($countCategory, 'install')
    ) {
        $appOffers++;
    }


    if (
        offerContains($countCategory, 'survey')
    ) {
        $surveyOffers++;
    }
}

?>

<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="description"
        content="Complete available PoketFlow offers and earn rewards."
    >

    <title>Earn Rewards — PoketFlow</title>

    <link
        rel="stylesheet"
        href="assets/poketflow.css"
    >

    <link
        rel="stylesheet"
        href="assets/offers.css"
    >

</head>


<body class="app-page offers-page">


<!-- ==================================================
     HEADER
================================================== -->

<header class="app-header">

    <a
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
            Refer &amp; Earn
        </a>

        <a href="withdraw.php">
            Withdraw
        </a>

    </nav>


    <div class="header-actions">

        <a
            class="balance-button"
            href="withdraw.php"
        >
            <span class="balance-label">
                Balance
            </span>

            <strong>
                $<?= number_format(
                    (float) $availableBalance,
                    2
                ) ?>
            </strong>
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

</header>


<!-- ==================================================
     MOBILE NAVIGATION
================================================== -->

<div
    class="mobile-navigation"
    id="mobileNavigation"
    aria-hidden="true"
>

    <nav>

        <a href="dashboard.php">
            <span class="mobile-nav-icon">
                HOME
            </span>

            <span>
                Home
            </span>
        </a>


        <a
            class="active"
            href="offers.php"
        >
            <span class="mobile-nav-icon">
                EARN
            </span>

            <span>
                Earn Rewards
            </span>
        </a>


        <a href="history.php">
            <span class="mobile-nav-icon">
                HISTORY
            </span>

            <span>
                History
            </span>
        </a>


        <a href="referrals.php">
            <span class="mobile-nav-icon">
                REFER
            </span>

            <span>
                Refer &amp; Earn
            </span>
        </a>


        <a href="withdraw.php">
            <span class="mobile-nav-icon">
                CASH
            </span>

            <span>
                Withdraw
            </span>
        </a>


        <a href="logout.php">
            <span class="mobile-nav-icon">
                EXIT
            </span>

            <span>
                Log Out
            </span>
        </a>

    </nav>

</div>


<!-- ==================================================
     APP LAYOUT
================================================== -->

<main class="app-shell">


    <!-- ==================================================
         SIDEBAR
    ================================================== -->

    <aside class="sidebar">

        <div class="sidebar-nav">

            <a href="dashboard.php">
                <span class="sidebar-icon">⌂</span>
                <span>Home</span>
            </a>


            <a
                class="active"
                href="offers.php"
            >
                <span class="sidebar-icon">+</span>
                <span>Earn Rewards</span>
            </a>


            <a href="history.php">
                <span class="sidebar-icon">↻</span>
                <span>History</span>
            </a>


            <a href="referrals.php">
                <span class="sidebar-icon">♧</span>
                <span>Refer &amp; Earn</span>
            </a>


            <a href="withdraw.php">
                <span class="sidebar-icon">$</span>
                <span>Withdraw</span>
            </a>


            <a href="logout.php">
                <span class="sidebar-icon">↪</span>
                <span>Log Out</span>
            </a>

        </div>


        <div class="side-balance">

            <span class="side-balance-label">
                YOUR BALANCE
            </span>


            <strong>
                $<?= number_format(
                    (float) $availableBalance,
                    2
                ) ?>
            </strong>


            <span class="side-balance-description">
                Available to withdraw
            </span>


            <a href="withdraw.php">
                Withdraw Funds
                <span>→</span>
            </a>

        </div>

    </aside>


    <!-- ==================================================
         MAIN CONTENT
    ================================================== -->

    <section class="app-content">


        <!-- ==================================================
             HERO
        ================================================== -->

        <section class="offers-hero">

            <div class="offers-hero-copy">

                <span class="offers-kicker">
                    EARN REWARDS
                </span>


                <h1>
                    Turn simple tasks<br>
                    into <span>real rewards.</span>
                </h1>


                <p>
                    Choose an offer that interests you,
                    complete the required steps, and
                    earn rewards directly through PoketFlow.
                </p>


                <div class="hero-actions">

                    <a
                        href="#available-offers"
                        class="hero-primary-button"
                    >
                        Explore Offers
                        <span>↓</span>
                    </a>


                    <a
                        href="history.php"
                        class="hero-secondary-button"
                    >
                        View History
                    </a>

                </div>

            </div>


            <!-- HERO BALANCE CARD -->

            <div class="hero-balance-card">

                <div class="hero-balance-top">

                    <span>
                        AVAILABLE BALANCE
                    </span>

                    <div class="balance-circle">
                        $
                    </div>

                </div>


                <strong class="hero-balance-amount">
                    $<?= number_format(
                        (float) $availableBalance,
                        2
                    ) ?>
                </strong>


                <p>
                    Your current PoketFlow balance
                </p>


                <a href="withdraw.php">
                    Withdraw funds
                    <span>→</span>
                </a>

            </div>

        </section>


        <!-- ==================================================
             QUICK STATS
        ================================================== -->

        <section class="offer-stat-grid">

            <div class="offer-stat-card">

                <div class="offer-stat-icon purple">
                    +
                </div>

                <div>

                    <strong>
                        <?= number_format($totalOffers) ?>
                    </strong>

                    <span>
                        Available Offers
                    </span>

                </div>

            </div>


            <div class="offer-stat-card">

                <div class="offer-stat-icon cyan">
                    A
                </div>

                <div>

                    <strong>
                        <?= number_format($appOffers) ?>
                    </strong>

                    <span>
                        App Offers
                    </span>

                </div>

            </div>


            <div class="offer-stat-card">

                <div class="offer-stat-icon orange">
                    ?
                </div>

                <div>

                    <strong>
                        <?= number_format($surveyOffers) ?>
                    </strong>

                    <span>
                        Survey Offers
                    </span>

                </div>

            </div>

        </section>


        <!-- ==================================================
             ERROR MESSAGE
        ================================================== -->

        <?php if ($ogadsError !== null): ?>

            <div class="offers-alert">

                <div class="offers-alert-icon">
                    !
                </div>


                <div>

                    <strong>
                        Offers temporarily unavailable
                    </strong>

                    <p>
                        We could not refresh the offer list
                        right now. Please try again shortly.
                    </p>

                </div>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             AVAILABLE OFFERS
        ================================================== -->

        <section
            class="available-offers-section"
            id="available-offers"
        >

            <div class="section-heading">

                <div>

                    <span class="section-kicker">
                        AVAILABLE NOW
                    </span>


                    <h2>
                        Choose an offer
                    </h2>


                    <p>
                        Offers are selected based on your
                        current location and device.
                    </p>

                </div>


                <div class="offer-count-badge">

                    <span>
                        <?= number_format($totalOffers) ?>
                    </span>

                    offers

                </div>

            </div>


            <!-- ==================================================
                 FILTERS
            ================================================== -->

            <div
                class="offer-tabs"
                role="tablist"
                aria-label="Offer categories"
            >

                <button
                    type="button"
                    class="selected"
                    data-filter="all"
                    role="tab"
                    aria-selected="true"
                >
                    All Offers
                </button>


                <button
                    type="button"
                    data-filter="app"
                    role="tab"
                    aria-selected="false"
                >
                    App Install
                </button>


                <button
                    type="button"
                    data-filter="survey"
                    role="tab"
                    aria-selected="false"
                >
                    Surveys
                </button>


                <button
                    type="button"
                    data-filter="other"
                    role="tab"
                    aria-selected="false"
                >
                    Other Offers
                </button>

            </div>


            <!-- ==================================================
                 OFFER GRID
            ================================================== -->

            <div
                class="offer-grid dashboard-offers"
                id="offerGrid"
            >


                <?php if (empty($campaigns)): ?>


                    <article class="empty-offer-card">

                        <div class="empty-offer-icon">
                            —
                        </div>


                        <div>

                            <span class="section-kicker">
                                NO OFFERS
                            </span>


                            <h3>
                                Nothing available right now
                            </h3>


                            <p>
                                There are currently no eligible
                                offers available for your location.
                                Please check back later.
                            </p>


                            <a
                                href="offers.php"
                                class="empty-refresh-button"
                            >
                                Refresh Offers
                                <span>↻</span>
                            </a>

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


                        $description =
                            getShortOfferDescription(
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


                        $categorySlug = strtolower(
                            $category
                        );

                        ?>


                        <article
                            class="offer-card"
                            data-offer-category="<?= e($categorySlug) ?>"
                        >

                            <!-- OFFER IMAGE -->

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


                                <span class="offer-category-badge">
                                    <?= e($category) ?>
                                </span>

                            </div>


                            <!-- OFFER CONTENT -->

                            <div class="offer-body">

                                <h3>
                                    <?= e($title) ?>
                                </h3>


                                <p>
                                    <?= e($description) ?>
                                </p>

                            </div>


                            <!-- OFFER FOOTER -->

                            <div class="offer-bottom">

                                <div class="offer-reward">

                                    <span>
                                        REWARD
                                    </span>

                                    <strong>
                                        $<?= number_format(
                                            $reward,
                                            2
                                        ) ?>
                                    </strong>

                                </div>


                                <a
                                    href="start-offer.php?offer_id=<?= e(
                                        (string) $campaign['external_offer_id']
                                    ) ?>"
                                    class="offer-start-button"
                                >
                                    Start Offer
                                    <span>→</span>
                                </a>

                            </div>

                        </article>


                    <?php endforeach; ?>


                <?php endif; ?>


            </div>


            <!-- FILTER EMPTY STATE -->

            <div
                class="filter-empty-state"
                id="filterEmptyState"
                hidden
            >

                <div>
                    —
                </div>


                <h3>
                    No offers in this category
                </h3>


                <p>
                    Try another category to see more
                    available offers.
                </p>

            </div>

        </section>


        <!-- ==================================================
             HOW IT WORKS
        ================================================== -->

        <section class="how-it-works">

            <div class="section-heading compact">

                <div>

                    <span class="section-kicker">
                        HOW IT WORKS
                    </span>


                    <h2>
                        Earning is simple
                    </h2>

                </div>

            </div>


            <div class="steps-grid">

                <div class="earning-step">

                    <span class="step-number">
                        01
                    </span>


                    <div class="step-icon">
                        +
                    </div>


                    <h3>
                        Choose an offer
                    </h3>


                    <p>
                        Browse the offers currently available
                        for your location and device.
                    </p>

                </div>


                <div class="earning-step">

                    <span class="step-number">
                        02
                    </span>


                    <div class="step-icon">
                        ✓
                    </div>


                    <h3>
                        Complete the steps
                    </h3>


                    <p>
                        Follow the instructions shown by the
                        offer provider and complete the required
                        action.
                    </p>

                </div>


                <div class="earning-step">

                    <span class="step-number">
                        03
                    </span>


                    <div class="step-icon">
                        $
                    </div>


                    <h3>
                        Receive your reward
                    </h3>


                    <p>
                        Once the completion is confirmed,
                        your eligible reward can be credited
                        to your PoketFlow balance.
                    </p>

                </div>

            </div>

        </section>


        <!-- ==================================================
             TRACKING INFORMATION
        ================================================== -->

        <section class="tracking-card">

            <div class="tracking-icon">
                ↻
            </div>


            <div class="tracking-content">

                <span class="section-kicker">
                    OFFER TRACKING
                </span>


                <h3>
                    Keep an eye on your completions
                </h3>


                <p>
                    Some offer providers may take time to
                    confirm a completed action. Check your
                    Offer History for recent activity and
                    completion status.
                </p>

            </div>


            <a
                href="history.php"
                class="tracking-button"
            >
                View History
                <span>→</span>
            </a>

        </section>


    </section>

</main>


<!-- ==================================================
     OFFERS PAGE JAVASCRIPT
================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /* ==============================================
           OFFER FILTERS
        ============================================== */

        var filterButtons =
            document.querySelectorAll(
                '.offer-tabs button'
            );


        var offerCards =
            document.querySelectorAll(
                '.dashboard-offers .offer-card[data-offer-category]'
            );


        var filterEmptyState =
            document.getElementById(
                'filterEmptyState'
            );


        filterButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        var filter =
                            button.getAttribute(
                                'data-filter'
                            );


                        var visibleCount = 0;


                        filterButtons.forEach(
                            function (item) {

                                item.classList.remove(
                                    'selected'
                                );


                                item.setAttribute(
                                    'aria-selected',
                                    'false'
                                );

                            }
                        );


                        button.classList.add(
                            'selected'
                        );


                        button.setAttribute(
                            'aria-selected',
                            'true'
                        );


                        offerCards.forEach(
                            function (card) {

                                var category =
                                    (
                                        card.getAttribute(
                                            'data-offer-category'
                                        ) || ''
                                    ).toLowerCase();


                                var show = false;


                                if (filter === 'all') {

                                    show = true;

                                }
                                else if (filter === 'app') {

                                    show =
                                        category.indexOf('app') !== -1 ||
                                        category.indexOf('install') !== -1;

                                }
                                else if (filter === 'survey') {

                                    show =
                                        category.indexOf('survey') !== -1;

                                }
                                else if (filter === 'other') {

                                    show =
                                        category.indexOf('app') === -1 &&
                                        category.indexOf('install') === -1 &&
                                        category.indexOf('survey') === -1;

                                }


                                if (show) {

                                    card.removeAttribute(
                                        'hidden'
                                    );

                                    card.style.display = '';

                                    visibleCount++;

                                }
                                else {

                                    card.setAttribute(
                                        'hidden',
                                        'hidden'
                                    );

                                    card.style.display =
                                        'none';

                                }

                            }
                        );


                        if (filterEmptyState) {

                            if (
                                visibleCount === 0 &&
                                offerCards.length > 0
                            ) {

                                filterEmptyState.removeAttribute(
                                    'hidden'
                                );

                            }
                            else {

                                filterEmptyState.setAttribute(
                                    'hidden',
                                    'hidden'
                                );

                            }

                        }

                    }
                );

            }
        );


        /* ==============================================
           MOBILE NAVIGATION
        ============================================== */

        var menuButton =
            document.getElementById(
                'mobileMenuButton'
            );


        var mobileNavigation =
            document.getElementById(
                'mobileNavigation'
            );


        function closeMobileNavigation() {

            if (!menuButton || !mobileNavigation) {
                return;
            }


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


        if (
            menuButton &&
            mobileNavigation
        ) {

            menuButton.addEventListener(
                'click',
                function (event) {

                    event.stopPropagation();


                    var isOpen =
                        menuButton.classList.toggle(
                            'open'
                        );


                    mobileNavigation.classList.toggle(
                        'open',
                        isOpen
                    );


                    menuButton.setAttribute(
                        'aria-expanded',
                        isOpen
                            ? 'true'
                            : 'false'
                    );


                    mobileNavigation.setAttribute(
                        'aria-hidden',
                        isOpen
                            ? 'false'
                            : 'true'
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

                                closeMobileNavigation();

                            }
                        );

                    }
                );


            document.addEventListener(
                'click',
                function (event) {

                    if (
                        !mobileNavigation.contains(
                            event.target
                        ) &&
                        !menuButton.contains(
                            event.target
                        )
                    ) {

                        closeMobileNavigation();

                    }

                }
            );


            window.addEventListener(
                'resize',
                function () {

                    if (
                        window.innerWidth > 900
                    ) {

                        closeMobileNavigation();

                    }

                }
            );

        }


        /* ==============================================
           START OFFER BUTTON LOADING STATE
        ============================================== */

        var startButtons =
            document.querySelectorAll(
                '.offer-start-button'
            );


        startButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        button.classList.add(
                            'loading'
                        );


                        button.setAttribute(
                            'aria-disabled',
                            'true'
                        );


                        var text =
                            button.firstChild;


                        if (text) {

                            text.textContent =
                                'Opening... ';

                        }

                    }
                );

            }
        );


    }
);

</script>


</body>

</html>
