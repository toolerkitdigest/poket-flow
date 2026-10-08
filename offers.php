<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';

// --------------------------------------------------
// Authentication
// --------------------------------------------------

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

// --------------------------------------------------
// User
// --------------------------------------------------

$user = getUser($pdo, $userId);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$availableBalance = getUserBalance($pdo, $userId);

// --------------------------------------------------
// Basic request information
// --------------------------------------------------

$ip = (string) (
    $_SERVER['REMOTE_ADDR']
    ?? '127.0.0.1'
);

$userAgent = (string) (
    $_SERVER['HTTP_USER_AGENT']
    ?? ''
);

$language = (string) (
    $_SERVER['HTTP_ACCEPT_LANGUAGE']
    ?? ''
);

$scheme = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
)
    ? 'https'
    : 'http';

$host = (string) (
    $_SERVER['HTTP_HOST']
    ?? 'poketflow.com'
);

$site = $scheme . '://' . $host;

// --------------------------------------------------
// Campaigns
// --------------------------------------------------

$campaigns = [];

$ogadsError = null;
$cpagripError = null;

// ==================================================
// OGADS OFFERS
// ==================================================

try {
    $networkId = getOgadsNetworkId($pdo);

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

    $sessionOgadsOffers = [];

    foreach ($ogadsOffers as $offer) {

        if (!is_array($offer)) {
            continue;
        }

        // --------------------------------------------------
        // External offer ID
        // --------------------------------------------------

        $externalOfferId = (string) (
            $offer['offer_id']
            ?? $offer['id']
            ?? $offer['campaign_id']
            ?? ''
        );

        if ($externalOfferId === '') {
            continue;
        }

        // --------------------------------------------------
        // Basic offer information
        // --------------------------------------------------

        $title = trim((string) (
            $offer['name']
            ?? $offer['title']
            ?? $offer['offer_name']
            ?? 'Special Offer'
        ));

        $description = trim((string) (
            $offer['description']
            ?? $offer['desc']
            ?? ''
        ));

        $instructions = trim((string) (
            $offer['instructions']
            ?? $offer['conversion']
            ?? ''
        ));

        $category = trim((string) (
            $offer['category']
            ?? $offer['vertical']
            ?? ''
        ));

        $countries = $offer['countries']
            ?? $offer['country']
            ?? [];

        $devices = $offer['devices']
            ?? $offer['device']
            ?? [];

        $networkOfferUrl = trim((string) (
            $offer['url']
            ?? $offer['tracking_url']
            ?? $offer['offer_url']
            ?? ''
        ));

        $image = trim((string) (
            $offer['image']
            ?? $offer['image_url']
            ?? $offer['thumbnail']
            ?? ''
        ));

        // --------------------------------------------------
        // Payout
        // --------------------------------------------------

        $networkPayout = (float) (
            $offer['payout']
            ?? $offer['amount']
            ?? $offer['revenue']
            ?? 0
        );

        if ($networkPayout <= 0) {
            continue;
        }

        // --------------------------------------------------
        // URL validation
        // --------------------------------------------------

        if ($networkOfferUrl === '') {
            continue;
        }

        // --------------------------------------------------
        // Safety filtering
        // --------------------------------------------------

        if (function_exists('isOgadsOfferSafe')) {
            if (!isOgadsOfferSafe($offer)) {
                continue;
            }
        }

        // --------------------------------------------------
        // Check campaign in PoketFlow database
        // --------------------------------------------------

        $campaign = null;

        try {
            $campaignStmt = $pdo->prepare(
                'SELECT *
                 FROM campaigns
                 WHERE network_id = ?
                   AND external_offer_id = ?
                 LIMIT 1'
            );

            $campaignStmt->execute([
                $networkId,
                $externalOfferId,
            ]);

            $campaign = $campaignStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $campaign = null;
        }

        /*
         * If the network offer already exists in our database,
         * only display it when it is ACTIVE and APPROVED.
         */
        if ($campaign) {

            $campaignStatus = strtoupper(
                trim((string) ($campaign['status'] ?? ''))
            );

            $approvalStatus = strtoupper(
                trim((string) ($campaign['approval_status'] ?? ''))
            );

            if (
                $campaignStatus !== 'ACTIVE'
                || $approvalStatus !== 'APPROVED'
            ) {
                continue;
            }

            if (function_exists('isCampaignAllowed')) {
                if (!isCampaignAllowed($campaign)) {
                    continue;
                }
            }
        }

        // --------------------------------------------------
        // Calculate PoketFlow reward
        // --------------------------------------------------

        $reward = $networkPayout;

        if (function_exists('calculateOgadsReward')) {
            $reward = (float) calculateOgadsReward(
                $networkPayout,
                $campaign
            );
        }

        if ($reward <= 0) {
            continue;
        }

        // --------------------------------------------------
        // Normalized campaign
        // --------------------------------------------------

        $normalizedOffer = [
            'network' => 'OGAds',
            'network_slug' => 'ogads',

            'external_offer_id' => $externalOfferId,

            'title' => $title !== ''
                ? $title
                : 'Special Offer',

            'description' => $description,

            'instructions' => $instructions,

            'category' => $category,

            'countries' => $countries,

            'devices' => $devices,

            'network_offer_url' => $networkOfferUrl,

            'image' => $image,

            'network_payout' => $networkPayout,

            'reward' => $reward,
        ];

        $campaigns[] = $normalizedOffer;

        $sessionOgadsOffers[$externalOfferId] = $offer;
    }

    // --------------------------------------------------
    // Store OGAds offers in session
    // --------------------------------------------------

    $_SESSION['ogads_offers'] = $sessionOgadsOffers;

} catch (Throwable $e) {

    $ogadsError = 'OGAds offers are temporarily unavailable.';
}

// ==================================================
// CPAGRIP OFFERS
// ==================================================

try {

    /*
     * cpagrip.php returns the normalized CPAGrip offers array.
     *
     * Do NOT require it at the top of this file as well.
     * Requiring it twice could execute the integration twice.
     */
    $cpagripOffers = require __DIR__ . '/includes/cpagrip.php';

    if (!is_array($cpagripOffers)) {
        $cpagripOffers = [];
    }

    foreach ($cpagripOffers as $offer) {

        if (!is_array($offer)) {
            continue;
        }

        // --------------------------------------------------
        // External offer ID
        // --------------------------------------------------

        $externalOfferId = (string) (
            $offer['external_offer_id']
            ?? $offer['offer_id']
            ?? $offer['id']
            ?? ''
        );

        if ($externalOfferId === '') {
            continue;
        }

        // --------------------------------------------------
        // Basic information
        // --------------------------------------------------

        $title = trim((string) (
            $offer['title']
            ?? $offer['name']
            ?? 'Special Offer'
        ));

        $description = trim((string) (
            $offer['description']
            ?? ''
        ));

        $instructions = trim((string) (
            $offer['instructions']
            ?? ''
        ));

        $category = trim((string) (
            $offer['category']
            ?? ''
        ));

        $networkOfferUrl = trim((string) (
            $offer['network_offer_url']
            ?? $offer['url']
            ?? $offer['tracking_url']
            ?? ''
        ));

        $image = trim((string) (
            $offer['image']
            ?? $offer['image_url']
            ?? $offer['thumbnail']
            ?? ''
        ));

        $networkPayout = (float) (
            $offer['network_payout']
            ?? $offer['payout']
            ?? $offer['amount']
            ?? 0
        );

        $reward = (float) (
            $offer['reward']
            ?? $offer['worker_reward']
            ?? 0
        );

        // --------------------------------------------------
        // Validation
        // --------------------------------------------------

        if ($networkOfferUrl === '') {
            continue;
        }

        if ($reward <= 0) {
            continue;
        }

        // --------------------------------------------------
        // Normalized CPAGrip campaign
        // --------------------------------------------------

        $campaigns[] = [
            'network' => 'CPAGrip',
            'network_slug' => 'cpagrip',

            'external_offer_id' => $externalOfferId,

            'title' => $title !== ''
                ? $title
                : 'Special Offer',

            'description' => $description,

            'instructions' => $instructions,

            'category' => $category,

            'countries' => $offer['countries']
                ?? $offer['country']
                ?? [],

            'devices' => $offer['devices']
                ?? $offer['device']
                ?? [],

            'network_offer_url' => $networkOfferUrl,

            'image' => $image,

            'network_payout' => $networkPayout,

            'reward' => $reward,
        ];
    }

} catch (Throwable $e) {

    $cpagripError = 'CPAGrip offers are temporarily unavailable.';
}

// --------------------------------------------------
// Helper: Short description
// --------------------------------------------------

function getShortOfferDescription(string $description): string
{
    $description = trim($description);

    if ($description === '') {
        return 'Complete this offer to earn rewards on PoketFlow.';
    }

    $description = preg_replace('/\s+/', ' ', $description);

    if ($description === null) {
        return 'Complete this offer to earn rewards on PoketFlow.';
    }

    if (mb_strlen($description) > 130) {
        return mb_substr($description, 0, 127) . '...';
    }

    return $description;
}

// --------------------------------------------------
// Helper: Offer icon
// --------------------------------------------------

function getOfferIcon(string $category): string
{
    $category = strtolower(trim($category));

    if (
        str_contains($category, 'app')
        || str_contains($category, 'install')
        || str_contains($category, 'mobile')
    ) {
        return '◎';
    }

    if (
        str_contains($category, 'survey')
        || str_contains($category, 'question')
    ) {
        return '▤';
    }

    if (
        str_contains($category, 'submit')
        || str_contains($category, 'email')
        || str_contains($category, 'lead')
    ) {
        return '◇';
    }

    return '◆';
}

// --------------------------------------------------
// Helper: Offer icon class
// --------------------------------------------------

function getOfferIconClass(string $category): string
{
    $category = strtolower(trim($category));

    if (
        str_contains($category, 'survey')
        || str_contains($category, 'question')
    ) {
        return 'offer-icon-survey';
    }

    if (
        str_contains($category, 'special')
        || str_contains($category, 'featured')
    ) {
        return 'offer-icon-special';
    }

    return '';
}

// --------------------------------------------------
// Helper: Offer category
// --------------------------------------------------

function getOfferCategory(array $campaign): string
{
    $category = trim((string) (
        $campaign['category']
        ?? ''
    ));

    if ($category !== '') {
        return $category;
    }

    $network = strtolower(
        trim((string) ($campaign['network'] ?? ''))
    );

    if ($network === 'cpagrip') {
        return 'Special Offer';
    }

    return 'Offer';
}

// --------------------------------------------------
// Page title
// --------------------------------------------------

$pageTitle = 'Earn Rewards';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Complete approved offers and earn rewards with PoketFlow."
    >

    <title>
        <?= e($pageTitle) ?> | PoketFlow
    </title>

    <link
        rel="stylesheet"
        href="offers.css"
    >
</head>

<body class="app-page">

<header class="app-header">

    <!-- Brand -->
    <a
        href="dashboard.php"
        class="app-brand"
        aria-label="PoketFlow Dashboard"
    >
        <span class="brand-mark">P</span>

        <span class="brand-text">
            <strong>Poket</strong><span>Flow</span>
        </span>
    </a>


    <!-- Desktop navigation -->
    <nav
        class="app-header-nav"
        aria-label="Main navigation"
    >
        <a href="dashboard.php">
            Dashboard
        </a>

        <a
            href="offers.php"
            class="active"
        >
            Earn Rewards
        </a>

        <a href="history.php">
            History
        </a>

        <a href="referrals.php">
            Referrals
        </a>

        <a href="withdraw.php">
            Withdraw
        </a>
    </nav>


    <!-- Header actions -->
    <div class="header-actions">

        <a
            href="dashboard.php"
            class="balance-button"
            aria-label="Available balance"
        >
            <span class="balance-label">
                Balance
            </span>

            <strong>
                $<?= number_format($availableBalance, 2) ?>
            </strong>
        </a>


        <!-- ONE mobile hamburger button -->
        <button
            type="button"
            class="mobile-menu-button"
            id="mobileMenuButton"
            aria-label="Open navigation menu"
            aria-controls="mobileNavigation"
            aria-expanded="false"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

    </div>

</header>


<!--
    Dedicated mobile navigation.

    The desktop sidebar is hidden on mobile through CSS,
    so this is the ONLY mobile navigation menu.
-->
<nav
    class="mobile-navigation"
    id="mobileNavigation"
    aria-label="Mobile navigation"
>

    <a href="dashboard.php">
        <span>⌂</span>
        Dashboard
    </a>

    <a
        href="offers.php"
        class="active"
    >
        <span>◎</span>
        Earn Rewards
    </a>

    <a href="history.php">
        <span>◷</span>
        History
    </a>

    <a href="referrals.php">
        <span>⇄</span>
        Refer &amp; Earn
    </a>

    <a href="withdraw.php">
        <span>↗</span>
        Withdraw
    </a>

    <a href="logout.php">
        <span>↪</span>
        Log Out
    </a>

</nav>


<main class="app-shell">

    <!--
        Desktop sidebar.

        IMPORTANT:
        This sidebar is hidden on mobile through offers.css.
        Mobile users use the single hamburger menu above.
    -->
    <aside class="sidebar">

        <nav
            class="sidebar-nav"
            aria-label="Sidebar navigation"
        >

            <a href="dashboard.php">
                <span class="sidebar-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a
                href="offers.php"
                class="active"
            >
                <span class="sidebar-icon">◎</span>
                <span>Earn Rewards</span>
            </a>

            <a href="history.php">
                <span class="sidebar-icon">◷</span>
                <span>History</span>
            </a>

            <a href="referrals.php">
                <span class="sidebar-icon">⇄</span>
                <span>Refer &amp; Earn</span>
            </a>

            <a href="withdraw.php">
                <span class="sidebar-icon">↗</span>
                <span>Withdraw</span>
            </a>

            <a href="logout.php">
                <span class="sidebar-icon">↪</span>
                <span>Log Out</span>
            </a>

        </nav>


        <div class="side-balance">

            <span class="side-balance-label">
                Available Balance
            </span>

            <strong>
                $<?= number_format($availableBalance, 2) ?>
            </strong>

            <a href="withdraw.php">
                Withdraw
            </a>

        </div>

    </aside>


    <section class="app-content">

        <!-- Page heading -->
        <div class="page-heading">

            <div>
                <span class="eyebrow">
                    Earn
                </span>

                <h1>
                    Available Offers
                </h1>

                <p>
                    Complete approved offers and earn rewards directly to your PoketFlow balance.
                </p>
            </div>

        </div>


        <!-- Network errors -->
        <?php if ($ogadsError !== null || $cpagripError !== null): ?>

            <div class="info-card offer-status-card">

                <div class="info-card-icon">
                    !
                </div>

                <div>

                    <strong>
                        Some offers may be unavailable
                    </strong>

                    <p>

                        <?php if ($ogadsError !== null): ?>
                            <?= e($ogadsError) ?>
                        <?php endif; ?>

                        <?php if (
                            $ogadsError !== null
                            && $cpagripError !== null
                        ): ?>
                            <br>
                        <?php endif; ?>

                        <?php if ($cpagripError !== null): ?>
                            <?= e($cpagripError) ?>
                        <?php endif; ?>

                    </p>

                </div>

            </div>

        <?php endif; ?>


        <!-- Offer filters -->
        <div class="offer-filters">

            <button
                type="button"
                class="offer-filter active"
                data-filter="all"
            >
                All Offers
            </button>

            <button
                type="button"
                class="offer-filter"
                data-filter="survey"
            >
                Surveys
            </button>

            <button
                type="button"
                class="offer-filter"
                data-filter="app"
            >
                Apps
            </button>

            <button
                type="button"
                class="offer-filter"
                data-filter="submit"
            >
                Sign Up
            </button>

        </div>


        <div class="dashboard-grid">

            <!-- Offers -->
            <div class="offer-grid dashboard-offers">

                <?php if (empty($campaigns)): ?>

                    <div class="empty-state offer-empty-state">

                        <div class="offer-icon">
                            ◆
                        </div>

                        <span class="offer-network">
                            PoketFlow
                        </span>

                        <h3>
                            No offers available
                        </h3>

                        <p>
                            There are currently no eligible offers available for your account.
                            Please check again later.
                        </p>

                        <a
                            href="offers.php"
                            class="button button-primary"
                        >
                            Refresh Offers
                        </a>

                    </div>

                <?php else: ?>

                    <?php foreach ($campaigns as $campaign): ?>

                        <?php

                        $offerTitle = trim((string) (
                            $campaign['title']
                            ?? 'Special Offer'
                        ));

                        $offerDescription = getShortOfferDescription(
                            (string) (
                                $campaign['description']
                                ?? ''
                            )
                        );

                        $offerCategory = getOfferCategory(
                            $campaign
                        );

                        $offerNetwork = trim((string) (
                            $campaign['network']
                            ?? 'PoketFlow'
                        ));

                        $offerImage = trim((string) (
                            $campaign['image']
                            ?? ''
                        ));

                        $offerReward = (float) (
                            $campaign['reward']
                            ?? 0
                        );

                        $externalOfferId = (string) (
                            $campaign['external_offer_id']
                            ?? ''
                        );

                        $networkSlug = strtolower(
                            trim((string) (
                                $campaign['network_slug']
                                ?? $offerNetwork
                            ))
                        );

                        $safeCategory = strtolower(
                            $offerCategory
                        );

                        $icon = getOfferIcon(
                            $offerCategory
                        );

                        $iconClass = getOfferIconClass(
                            $offerCategory
                        );

                        ?>

                        <article
                            class="offer-card"
                            data-category="<?= e($safeCategory) ?>"
                            data-network="<?= e($networkSlug) ?>"
                        >

                            <!-- Offer image -->
                            <div class="offer-image">

                                <?php if ($offerImage !== ''): ?>

                                    <img
                                        src="<?= e($offerImage) ?>"
                                        alt="<?= e($offerTitle) ?>"
                                        loading="lazy"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                    >

                                    <div
                                        class="offer-fallback-icon <?= e($iconClass) ?>"
                                        style="display:none;"
                                        aria-hidden="true"
                                    >
                                        <?= e($icon) ?>
                                    </div>

                                <?php else: ?>

                                    <div
                                        class="offer-fallback-icon <?= e($iconClass) ?>"
                                        aria-hidden="true"
                                    >
                                        <?= e($icon) ?>
                                    </div>

                                <?php endif; ?>

                            </div>


                            <!-- Offer body -->
                            <div class="offer-body">

                                <div class="offer-top">

                                    <span class="offer-network">
                                        <?= e($offerNetwork) ?>
                                    </span>

                                    <span class="offer-category">
                                        <?= e($offerCategory) ?>
                                    </span>

                                </div>


                                <h3 class="offer-title">
                                    <?= e($offerTitle) ?>
                                </h3>


                                <p class="offer-description">
                                    <?= e($offerDescription) ?>
                                </p>


                                <div class="offer-bottom">

                                    <div class="offer-reward">

                                        <span>
                                            Earn
                                        </span>

                                        <strong>
                                            $<?= number_format($offerReward, 2) ?>
                                        </strong>

                                    </div>


                                    <?php if ($networkSlug === 'cpagrip'): ?>

                                        <a
                                            href="<?= e((string) ($campaign['network_offer_url'] ?? '#')) ?>"
                                            class="offer-button"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Start Offer
                                        </a>

                                    <?php else: ?>

                                        <a
                                            href="start-offer.php?offer_id=<?= urlencode($externalOfferId) ?>"
                                            class="offer-button"
                                        >
                                            Start Offer
                                        </a>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>


            <!-- Information panel -->
            <aside class="offer-info-panel">

                <div class="info-card">

                    <div class="info-card-icon">
                        ✓
                    </div>

                    <h3>
                        How it works
                    </h3>

                    <ol class="info-steps">

                        <li>
                            <span>1</span>
                            Choose an available offer.
                        </li>

                        <li>
                            <span>2</span>
                            Complete the required action.
                        </li>

                        <li>
                            <span>3</span>
                            Wait for the network to confirm your conversion.
                        </li>

                        <li>
                            <span>4</span>
                            Your approved reward is added to your PoketFlow balance.
                        </li>

                    </ol>

                </div>


                <div class="info-card tracking-card">

                    <div class="info-card-icon">
                        ⓘ
                    </div>

                    <h3>
                        Important
                    </h3>

                    <p>
                        Complete offers using the same device and information
                        required by the advertiser. Do not use prohibited methods
                        such as bots, automated traffic, or misleading information.
                    </p>

                </div>

            </aside>

        </div>

    </section>

</main>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const mobileMenuButton = document.getElementById('mobileMenuButton');
    const mobileNavigation = document.getElementById('mobileNavigation');

    /*
     * --------------------------------------------------
     * Mobile navigation
     * --------------------------------------------------
     */

    if (mobileMenuButton && mobileNavigation) {

        mobileMenuButton.addEventListener('click', function (event) {

            event.stopPropagation();

            const isOpen = mobileNavigation.classList.toggle('open');

            mobileMenuButton.classList.toggle(
                'active',
                isOpen
            );

            mobileMenuButton.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );

        });


        /*
         * Close mobile navigation when a link is clicked.
         */
        mobileNavigation
            .querySelectorAll('a')
            .forEach(function (link) {

                link.addEventListener('click', function () {

                    mobileNavigation.classList.remove('open');

                    mobileMenuButton.classList.remove('active');

                    mobileMenuButton.setAttribute(
                        'aria-expanded',
                        'false'
                    );

                });

            });


        /*
         * Close when clicking outside.
         */
        document.addEventListener('click', function (event) {

            if (
                !mobileNavigation.contains(event.target)
                && !mobileMenuButton.contains(event.target)
            ) {

                mobileNavigation.classList.remove('open');

                mobileMenuButton.classList.remove('active');

                mobileMenuButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }

        });


        /*
         * Close when switching back to desktop width.
         */
        window.addEventListener('resize', function () {

            if (window.innerWidth > 900) {

                mobileNavigation.classList.remove('open');

                mobileMenuButton.classList.remove('active');

                mobileMenuButton.setAttribute(
                    'aria-expanded',
                    'false'
                );

            }

        });

    }


    /*
     * --------------------------------------------------
     * Offer filters
     * --------------------------------------------------
     */

    const filterButtons = document.querySelectorAll(
        '.offer-filter'
    );

    const offerCards = document.querySelectorAll(
        '.offer-card'
    );


    filterButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            /*
             * Remove active state from all buttons.
             */
            filterButtons.forEach(function (item) {

                item.classList.remove('active');

            });


            /*
             * Activate clicked button.
             */
            button.classList.add('active');


            const filter = (
                button.dataset.filter
                || 'all'
            ).toLowerCase();


            /*
             * Filter offers.
             */
            offerCards.forEach(function (card) {

                if (filter === 'all') {

                    card.style.display = '';

                    return;
                }


                const category = (
                    card.dataset.category
                    || ''
                ).toLowerCase();


                const network = (
                    card.dataset.network
                    || ''
                ).toLowerCase();


                let matches = false;


                if (filter === 'survey') {

                    matches =
                        category.includes('survey')
                        || category.includes('question');

                } else if (filter === 'app') {

                    matches =
                        category.includes('app')
                        || category.includes('install')
                        || category.includes('mobile');

                } else if (filter === 'submit') {

                    matches =
                        category.includes('submit')
                        || category.includes('email')
                        || category.includes('lead')
                        || category.includes('signup')
                        || category.includes('sign up');

                } else {

                    matches =
                        category.includes(filter)
                        || network.includes(filter);

                }


                card.style.display = matches
                    ? ''
                    : 'none';

            });

        });

    });

});
</script>

</body>
</html>
