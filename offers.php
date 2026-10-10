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

$userId = (int) ($_SESSION['user_id'] ?? 0);

$user = getUser($pdo, $userId);

$ogadsError = null;
$campaigns = [];

// ==================================================
// VALIDATE USER SESSION
// ==================================================

if (!$user) {
    $_SESSION = [];

    session_destroy();

    redirect('login.php');
}

// ==================================================
// GET AVAILABLE WALLET BALANCE
// ==================================================

$availableBalance = getUserBalance($pdo, $userId);

// ==================================================
// FETCH VISITOR-SPECIFIC OGADS OFFERS
// ==================================================

try {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    $scheme = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) ? 'https' : 'http';

    $site = $scheme . '://' .
        ($_SERVER['HTTP_HOST'] ?? 'poketflow.com') .
        ($_SERVER['REQUEST_URI'] ?? '/offers.php');

    // --------------------------------------------------
    // Get OGAds network ID
    // --------------------------------------------------

    $networkId = getOgadsNetworkId($pdo);

    // --------------------------------------------------
    // Fetch live visitor-specific inventory
    // --------------------------------------------------

    $ogadsOffers = fetchOgadsOffers(
        $ip,
        $userAgent,
        $language,
        $site,
        0,
        100
    );

    if (!is_array($ogadsOffers)) {
        $ogadsOffers = [];
    }

    // --------------------------------------------------
    // Prepare campaign lookup
    // --------------------------------------------------

    $existingCampaignStmt = $pdo->prepare(
        'SELECT id
         FROM campaigns
         WHERE network_id = ?
           AND external_offer_id = ?
         LIMIT 1'
    );

    // --------------------------------------------------
    // Process OGAds offers
    // --------------------------------------------------

    foreach ($ogadsOffers as $offer) {
        if (!is_array($offer)) {
            continue;
        }

        $externalOfferId = trim(
            (string) ($offer['offerid'] ?? '')
        );

        if (
            $externalOfferId === '' ||
            !ctype_digit($externalOfferId)
        ) {
            continue;
        }

        // ----------------------------------------------
        // Clean offer information
        // ----------------------------------------------

        $title = cleanOgadsText(
            $offer['name_short']
                ?? $offer['name']
                ?? 'OGAds Offer'
        );

        $description = cleanOgadsText(
            $offer['description'] ?? ''
        );

        $instructions = cleanOgadsText(
            $offer['adcopy'] ?? ''
        );

        $category = getOgadsOfferCategory($offer);

        $countries = trim(
            (string) ($offer['country'] ?? '')
        );

        $devices = trim(
            (string) ($offer['device'] ?? '')
        );

        $networkOfferUrl = trim(
            (string) ($offer['link'] ?? '')
        );

        $imageUrl = trim(
            (string) ($offer['picture'] ?? '')
        );

        $networkPayout = round(
            (float) ($offer['payout'] ?? 0),
            2
        );

        // ----------------------------------------------
        // Basic validation
        // ----------------------------------------------

        if (
            $networkPayout <= 0 ||
            $networkOfferUrl === ''
        ) {
            continue;
        }

        // ----------------------------------------------
        // OGAds safety filters
        // ----------------------------------------------

        if (!isOgadsOfferSafe($pdo, $offer)) {
            continue;
        }

        // ==================================================
        // REQUIRE DATABASE REGISTRATION AND APPROVAL
        // ==================================================

        $existingCampaignStmt->execute([
            $networkId,
            $externalOfferId
        ]);

        $campaignId = $existingCampaignStmt->fetchColumn();

        // Only registered offers can be displayed.
        if ($campaignId === false) {
            continue;
        }

        $databaseCampaign = getCampaign(
            $pdo,
            (int) $campaignId
        );

        if (!$databaseCampaign) {
            continue;
        }

        // Require active status, admin approval,
        // and all database-based safety filters.
        if (!isCampaignAllowed($pdo, $databaseCampaign)) {
            continue;
        }

        // Only display offers explicitly approved
        // for incentive/reward traffic.
        if (
            !isset($databaseCampaign['incentive_allowed']) ||
            (int) $databaseCampaign['incentive_allowed'] !== 1
        ) {
            continue;
        }

        // ----------------------------------------------
        // Calculate worker reward
        // ----------------------------------------------

        $rewards = calculateOgadsReward(
            $pdo,
            $networkPayout
        );

        // ----------------------------------------------
        // Build normalized offer
        // ----------------------------------------------

        $campaigns[] = [
            'id' => $externalOfferId,

            'campaign_id' => (int) $campaignId,

            'source_type' => 'CPA_NETWORK',

            'network' => 'ogads',

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
    // Store current visitor's offers
    // --------------------------------------------------

    $_SESSION['ogads_offers'] = $campaigns;

} catch (Throwable $e) {

    // Log technical details privately.
    error_log(
        'PoketFlow OGAds offers error: ' . $e->getMessage()
    );

    // Show a safe message to the visitor.
    $ogadsError = 'Offers could not be refreshed.';

    $_SESSION['ogads_offers'] = [];

    $campaigns = [];
}


// ==================================================
// UI HELPERS
// ==================================================

/**
 * Create a short, clean description for an offer card.
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

        if (is_array($cleaned) && isset($cleaned[0])) {
            $description = trim($cleaned[0]);
        }
    }

    $description = preg_replace(
        '/\s+/u',
        ' ',
        $description
    );

    $description = trim((string) $description);

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
    } elseif (strlen($description) > 125) {
        $description = substr($description, 0, 122) . '...';
    }

    return $description;
}


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
        (string) ($campaign['category'] ?? '')
    );

    if ($category !== '') {
        return $category;
    }

    switch ($campaign['source_type'] ?? '') {
        case 'DIRECT_ADVERTISER':
            return 'Special Offer';

        default:
            return 'Offer';
    }
}


// --------------------------------------------------
// Prepare safe filter category
// --------------------------------------------------

function getOfferFilterCategory(string $category): string
{
    $category = strtolower(trim($category));

    if (
        strpos($category, 'app') !== false ||
        strpos($category, 'install') !== false
    ) {
        return 'app';
    }

    if (strpos($category, 'survey') !== false) {
        return 'survey';
    }

    return 'other';
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
        name="theme-color"
        content="#080d1a"
    >

    <title>Offers — PoketFlow</title>

    <link
        rel="stylesheet"
        href="css/offers.css"
    >

</head>

<body class="app-page">

<!-- ==================================================
     HEADER
     ================================================== -->

<header class="app-header">

    <a class="brand" href="index.php">

        <span class="brand-mark">P</span>

        <span>Poket<span>Flow</span></span>

    </a>


    <!-- Desktop navigation -->

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


    <!-- Header actions -->

    <div class="header-actions">

        <a
            class="btn btn-primary balance-button"
            href="withdraw.php"
        >
            <span class="balance-label">Balance</span>

            <span>
                $<?= number_format($availableBalance, 2) ?>
            </span>
        </a>


        <!-- One mobile hamburger -->

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
            <span>Refer &amp; Earn</span>
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

</div>


<!-- ==================================================
     APPLICATION SHELL
     ================================================== -->

<main class="app-shell">


    <!-- Desktop sidebar -->

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
                <span class="sidebar-icon">▦</span>
                <span>Offers</span>
            </a>

            <a href="history.php">
                <span class="sidebar-icon">◷</span>
                <span>History</span>
            </a>

            <a href="referrals.php">
                <span class="sidebar-icon">♧</span>
                <span>Refer &amp; Earn</span>
            </a>

            <a href="withdraw.php">
                <span class="sidebar-icon">▣</span>
                <span>Withdraw</span>
            </a>

            <a href="logout.php">
                <span class="sidebar-icon">↪</span>
                <span>Log Out</span>
            </a>

        </div>


        <div class="side-balance">

            <small>Your Balance</small>

            <strong>
                $<?= number_format($availableBalance, 2) ?>
            </strong>

            <span>Available to withdraw</span>

            <a href="withdraw.php">
                Withdraw Funds →
            </a>

        </div>

    </aside>


    <!-- ==================================================
         MAIN OFFERS CONTENT
         ================================================== -->

    <section class="app-content">


        <!-- Page heading -->

        <div class="page-title">

            <div class="page-title-copy">

                <span class="kicker">
                    YOUR NEXT REWARD STARTS HERE
                </span>

                <h1>
                    Explore Offers<span class="title-period">.</span>
                </h1>

                <p>
                    Discover opportunities, complete eligible offers,
                    and earn rewards directly into your PoketFlow account.
                </p>

            </div>


            <div class="page-title-decoration" aria-hidden="true">

                <span class="decoration-orbit orbit-one"></span>

                <span class="decoration-orbit orbit-two"></span>

                <span class="decoration-core">P</span>

            </div>

        </div>


        <!-- Error notice -->

        <?php if ($ogadsError !== null): ?>

            <div class="info-card error-notice">

                <div class="notice-icon">!</div>

                <div>

                    <h3>Offers temporarily unavailable</h3>

                    <p>
                        We couldn't refresh the offers right now.
                        Please try again shortly.
                    </p>

                </div>

            </div>

        <?php endif; ?>


        <!-- Offer section heading -->

        <div class="offers-section-heading">

            <div>

                <span class="section-eyebrow">
                    AVAILABLE OPPORTUNITIES
                </span>

                <h2>
                    Find your next reward
                </h2>

                <p>
                    Choose an offer that works for you.
                </p>

            </div>


            <div class="live-indicator">

                <span class="live-dot"></span>

                <span>Offer inventory</span>

            </div>

        </div>


        <!-- Category filters -->

        <div
            class="offer-tabs"
            role="group"
            aria-label="Filter offers by category"
        >

            <button
                type="button"
                class="selected"
                data-filter="all"
                aria-pressed="true"
            >
                <span class="filter-icon">✦</span>
                All Offers
            </button>

            <button
                type="button"
                data-filter="app"
                aria-pressed="false"
            >
                <span class="filter-icon">◎</span>
                App Install
            </button>

            <button
                type="button"
                data-filter="survey"
                aria-pressed="false"
            >
                <span class="filter-icon">▤</span>
                Surveys
            </button>

            <button
                type="button"
                data-filter="other"
                aria-pressed="false"
            >
                <span class="filter-icon">◇</span>
                Other Offers
            </button>

        </div>


        <!-- Offers grid and information panel -->

        <div class="dashboard-grid">


            <!-- Offer cards -->

            <div class="offer-grid dashboard-offers">

                <?php if (empty($campaigns)): ?>

                    <article class="offer-card empty-offer-card">

                        <div class="empty-offer-visual">

                            <div class="empty-orbit empty-orbit-one"></div>

                            <div class="empty-orbit empty-orbit-two"></div>

                            <div class="empty-offer-icon">
                                ◷
                            </div>

                        </div>

                        <div class="offer-body">

                            <span class="offer-network">
                                POKETFLOW REWARDS
                            </span>

                            <h3>
                                No offers available just yet
                            </h3>

                            <p>
                                There are currently no approved offers
                                available for your location. Please check
                                again soon for new earning opportunities.
                            </p>

                        </div>

                        <div class="offer-bottom">

                            <span class="empty-status">
                                <span></span>
                                More opportunities may arrive soon
                            </span>

                        </div>

                    </article>

                <?php else: ?>

                    <?php foreach ($campaigns as $index => $campaign): ?>

                        <?php

                        $category = getOfferCategory($campaign);

                        $filterCategory = getOfferFilterCategory($category);

                        $icon = getOfferIcon($category);

                        $iconClass = getOfferIconClass($category);

                        $title = trim(
                            (string) (
                                $campaign['title'] ?? 'Available Offer'
                            )
                        );

                        $description = getShortOfferDescription(
                            (string) ($campaign['description'] ?? ''),
                            $title
                        );

                        $reward = (float) (
                            $campaign['worker_reward'] ?? 0
                        );

                        $imageUrl = trim(
                            (string) ($campaign['image_url'] ?? '')
                        );

                        $externalOfferId = (string) (
                            $campaign['external_offer_id'] ?? ''
                        );

                        $startUrl = 'start-offer.php?offer_id=' .
                            rawurlencode($externalOfferId);

                        ?>

                        <article
                            class="offer-card"
                            data-offer-category="<?= e($filterCategory) ?>"
                            style="--card-index: <?= (int) min($index, 10) ?>;"
                        >

                            <!-- Offer visual -->

                            <div class="offer-image <?= e($iconClass) ?>">

                                <span class="offer-image-glow"></span>

                                <?php if ($imageUrl !== ''): ?>

                                    <img
                                        src="<?= e($imageUrl) ?>"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        referrerpolicy="no-referrer"
                                    >

                                <?php else: ?>

                                    <div class="offer-fallback-icon">
                                        <?= e($icon) ?>
                                    </div>

                                <?php endif; ?>


                                <span class="offer-type-badge">
                                    <?= e($category) ?>
                                </span>

                            </div>


                            <!-- Offer information -->

                            <div class="offer-body">

                                <span class="offer-network">
                                    OGAds Offer
                                </span>

                                <h3>
                                    <?= e($title) ?>
                                </h3>

                                <p>
                                    <?= e($description) ?>
                                </p>

                            </div>


                            <!-- Reward and action -->

                            <div class="offer-bottom">

                                <div class="offer-reward">

                                    <span>YOUR REWARD</span>

                                    <strong>
                                        $<?= number_format($reward, 2) ?>
                                    </strong>

                                </div>


                                <a
                                    href="<?= e($startUrl) ?>"
                                    class="btn btn-primary offer-start-button"
                                >

                                    <span>Start Offer</span>

                                    <span class="button-arrow">→</span>

                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>


            <!-- Offer information panel -->

            <aside class="info-card offers-info-card">

                <div class="info-card-top">

                    <div class="info-icon">
                        ✦
                    </div>

                    <span class="info-label">
                        YOUR EARNING GUIDE
                    </span>

                </div>


                <h3>
                    Make every offer count.
                </h3>

                <p>
                    Follow the offer instructions carefully and
                    complete each eligible task to give your
                    reward the best chance of being tracked.
                </p>


                <div class="info-divider"></div>


                <div class="info-tip">

                    <span class="tip-icon">01</span>

                    <div>

                        <strong>Choose carefully</strong>

                        <p>
                            Check that the offer suits your device
                            and location.
                        </p>

                    </div>

                </div>


                <div class="info-tip">

                    <span class="tip-icon">02</span>

                    <div>

                        <strong>Follow instructions</strong>

                        <p>
                            Complete the required steps as described
                            by the offer provider.
                        </p>

                    </div>

                </div>


                <div class="info-tip">

                    <span class="tip-icon">03</span>

                    <div>

                        <strong>Track your progress</strong>

                        <p>
                            Check your history for updates to your
                            offer completion status.
                        </p>

                    </div>

                </div>


                <a
                    href="history.php"
                    class="info-history-link"
                >
                    View Offer History
                    <span>→</span>
                </a>


                <div class="info-card-footnote">

                    <span class="footnote-icon">i</span>

                    <span>
                        Reward confirmation times can vary
                        by offer and provider.
                    </span>

                </div>

            </aside>

        </div>


        <!-- Footer note -->

        <div class="offers-footer-note">

            <span class="footer-shield">✓</span>

            <p>
                Only approved offers eligible for PoketFlow rewards
                are displayed here.
            </p>

        </div>

    </section>

</main>


<!-- ==================================================
     PAGE JAVASCRIPT
     ================================================== -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ==================================================
    // OFFER FILTERS
    // ==================================================

    const filterButtons = document.querySelectorAll(
        '.offer-tabs [data-filter]'
    );

    const offerCards = document.querySelectorAll(
        '.dashboard-offers .offer-card[data-offer-category]'
    );


    filterButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const filter = button.dataset.filter || 'all';

            filterButtons.forEach(function (item) {

                const selected = item === button;

                item.classList.toggle('selected', selected);

                item.setAttribute(
                    'aria-pressed',
                    selected ? 'true' : 'false'
                );

            });


            offerCards.forEach(function (card) {

                const category = (
                    card.dataset.offerCategory || 'other'
                ).toLowerCase();

                const show =
                    filter === 'all' ||
                    category === filter;

                card.classList.toggle('offer-hidden', !show);

            });

        });

    });


    // ==================================================
    // MOBILE NAVIGATION
    // ==================================================

    const menuButton = document.getElementById(
        'mobileMenuButton'
    );

    const mobileNavigation = document.getElementById(
        'mobileNavigation'
    );


    if (menuButton && mobileNavigation) {

        function closeMenu() {

            menuButton.classList.remove('open');

            mobileNavigation.classList.remove('open');

            menuButton.setAttribute(
                'aria-expanded',
                'false'
            );

            mobileNavigation.setAttribute(
                'aria-hidden',
                'true'
            );

        }


        menuButton.addEventListener('click', function (event) {

            event.stopPropagation();

            const isOpen =
                !mobileNavigation.classList.contains('open');


            menuButton.classList.toggle('open', isOpen);

            mobileNavigation.classList.toggle('open', isOpen);

            menuButton.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );

            mobileNavigation.setAttribute(
                'aria-hidden',
                isOpen ? 'false' : 'true'
            );

        });


        mobileNavigation.querySelectorAll('a').forEach(
            function (link) {

                link.addEventListener('click', closeMenu);

            }
        );


        document.addEventListener('click', function (event) {

            if (
                mobileNavigation.classList.contains('open') &&
                !mobileNavigation.contains(event.target) &&
                !menuButton.contains(event.target)
            ) {

                closeMenu();

            }

        });


        document.addEventListener('keydown', function (event) {

            if (event.key === 'Escape') {

                closeMenu();

            }

        });


        window.addEventListener('resize', function () {

            if (window.innerWidth > 900) {

                closeMenu();

            }

        });

    }

});
</script>

</body>
</html>
