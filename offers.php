<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';

// --------------------------------------------------
// Protect offers page
// --------------------------------------------------

if (!isLoggedIn()) {
    redirect('login.php');
}

// --------------------------------------------------
// Get logged-in user
// --------------------------------------------------

$userId = (int) ($_SESSION['user_id'] ?? 0);

$user = getUser($pdo, $userId);

$ogadsError = null;
$campaigns = [];

// --------------------------------------------------
// Validate user session
// --------------------------------------------------

if (!$user) {
    $_SESSION = [];
    session_destroy();

    redirect('login.php');
}

// --------------------------------------------------
// Get available wallet balance
// --------------------------------------------------

$availableBalance = getUserBalance($pdo, $userId);

// ==================================================
// FETCH LIVE VISITOR-SPECIFIC OGADS OFFERS
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
    // Fetch visitor-specific OGAds inventory
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
        // Basic offer validation
        // ----------------------------------------------

        if (
            $networkPayout <= 0 ||
            $networkOfferUrl === ''
        ) {
            continue;
        }

        // ----------------------------------------------
        // Apply OGAds safety filters
        // ----------------------------------------------

        if (!isOgadsOfferSafe($pdo, $offer)) {
            continue;
        }

        // ==================================================
        // ADMIN APPROVAL IS REQUIRED
        // ==================================================

        $existingCampaignStmt->execute([
            $networkId,
            $externalOfferId
        ]);

        $campaignId = $existingCampaignStmt->fetchColumn();

        // Do not display offers that have not been
        // registered and approved in PoketFlow.
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

        // Only display campaigns explicitly approved
        // for incentive/reward traffic.
        if (
            !isset($databaseCampaign['incentive_allowed']) ||
            (int) $databaseCampaign['incentive_allowed'] !== 1
        ) {
            continue;
        }

        // ----------------------------------------------
        // Calculate reward from OGAds payout
        // ----------------------------------------------

        $rewards = calculateOgadsReward(
            $pdo,
            $networkPayout
        );

        // ----------------------------------------------
        // Build visitor-specific offer
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
    // Store current visitor's OGAds offers
    // --------------------------------------------------

    $_SESSION['ogads_offers'] = $campaigns;

} catch (Throwable $e) {
    // Log the technical error without exposing internal
    // server or database details to the visitor.
    error_log(
        'PoketFlow OGAds offers error: ' . $e->getMessage()
    );

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

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Offers — PoketFlow</title>

    <link
        rel="stylesheet"
        href="css/offers.css"
    >
</head>

<body class="app-page">

<header class="app-header">
    <a class="brand" href="index.php">
        <span class="brand-mark">P</span>
        <span>Poket<span>Flow</span></span>
    </a>

    <nav class="app-header-nav">
        <a href="dashboard.php">Home</a>
        <a class="active" href="offers.php">Earn</a>
        <a href="history.php">History</a>
        <a href="referrals.php">Refer &amp; Earn</a>
        <a href="withdraw.php">Withdraw</a>
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
</header>

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

        <a class="active" href="offers.php">
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

<main class="app-shell">

    <aside class="sidebar">
        <div class="sidebar-nav">
            <a href="dashboard.php">
                ⌂ <span>Home</span>
            </a>

            <a class="active" href="offers.php">
                ▦ <span>Offers</span>
            </a>

            <a href="history.php">
                ◷ <span>History</span>
            </a>

            <a href="referrals.php">
                ♧ <span>Refer &amp; Earn</span>
            </a>

            <a href="withdraw.php">
                ▣ <span>Withdraw</span>
            </a>

            <a href="logout.php">
                ↪ <span>Log Out</span>
            </a>
        </div>

        <div class="side-balance">
            <small>Your Balance</small>

            <strong>
                $<?= number_format($availableBalance, 2) ?>
            </strong>

            <span>Available to withdraw</span>

            <a href="withdraw.php">Withdraw Funds →</a>
        </div>
    </aside>

    <section class="app-content">

        <div class="page-title">
            <div>
                <span class="kicker">EARN REWARDS</span>

                <h1>Offers</h1>

                <p>
                    Complete available offers to grow your balance.
                    The list refreshes automatically.
                </p>
            </div>
        </div>

        <?php if ($ogadsError !== null): ?>
            <div class="info-card">
                <h3>Offers temporarily unavailable</h3>

                <p>
                    We could not refresh the offer list right now.
                    Please try again shortly.
                </p>
            </div>
        <?php endif; ?>

        <div class="offer-tabs">
            <button type="button" class="selected" data-filter="all">
                All Offers
            </button>

            <button type="button" data-filter="app">
                App Install
            </button>

            <button type="button" data-filter="survey">
                Survey
            </button>

            <button type="button" data-filter="other">
                Other Offers
            </button>
        </div>

        <div class="dashboard-grid">

            <div class="offer-grid dashboard-offers">

                <?php if (empty($campaigns)): ?>

                    <article class="offer-card empty-offer-card">
                        <div>
                            <div class="offer-icon">◷</div>

                            <div class="offer-body">
                                <h3>No offers available right now</h3>

                                <p>
                                    There are currently no approved
                                    offers available for your location.
                                    Please check again later.
                                </p>
                            </div>

                            <div class="offer-bottom">
                                <strong>Check back soon</strong>
                            </div>
                        </div>
                    </article>

                <?php else: ?>

                    <?php foreach ($campaigns as $campaign): ?>

                        <?php
                        $category = getOfferCategory($campaign);
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
                        ?>

                        <article
                            class="offer-card"
                            data-offer-category="<?= e(strtolower($category)) ?>"
                        >

                            <div class="offer-image <?= e($iconClass) ?>">
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
                                <h3><?= e($title) ?></h3>
                                <p><?= e($description) ?></p>
                            </div>

                            <div class="offer-bottom">
                                <strong>
                                    Earn $<?= number_format($reward, 2) ?>
                                </strong>

                                <a
                                    href="start-offer.php?network=ogads&amp;offer_id=<?= rawurlencode($externalOfferId) ?>"
                                    class="btn btn-primary"
                                >
                                    Start →
                                </a>
                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

            <aside class="info-card">
                <div class="info-icon">◷</div>

                <h3>Track your completions</h3>

                <p>
                    Visit Offer History to see your recent
                    activity and completion status.
                </p>

                <a href="history.php">View Offer History →</a>

                <hr>

                <small>
                    Completion tracking can take some time
                    depending on the offer.
                </small>
            </aside>

        </div>

    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------
    // Offer category filters
    // --------------------------------------------------

    const filterButtons = document.querySelectorAll(
        '.offer-tabs button'
    );

    const offerCards = document.querySelectorAll(
        '.dashboard-offers .offer-card[data-offer-category]'
    );

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const filter = button.dataset.filter || 'all';

            filterButtons.forEach(function (item) {
                item.classList.remove('selected');
            });

            button.classList.add('selected');

            offerCards.forEach(function (card) {
                const category = (
                    card.dataset.offerCategory || ''
                ).toLowerCase();

                let show = false;

                if (filter === 'all') {
                    show = true;
                } else if (filter === 'app') {
                    show =
                        category.includes('app') ||
                        category.includes('install');
                } else if (filter === 'survey') {
                    show = category.includes('survey');
                } else if (filter === 'other') {
                    show =
                        !category.includes('app') &&
                        !category.includes('install') &&
                        !category.includes('survey');
                }

                card.style.display = show ? '' : 'none';
            });
        });
    });

    // --------------------------------------------------
    // Mobile navigation
    // --------------------------------------------------

    const menuButton = document.getElementById(
        'mobileMenuButton'
    );

    const mobileNavigation = document.getElementById(
        'mobileNavigation'
    );

    if (menuButton && mobileNavigation) {

        menuButton.addEventListener('click', function () {
            const isOpen = menuButton.classList.toggle('open');

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
                link.addEventListener('click', function () {
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
                });
            }
        );

        document.addEventListener('click', function (event) {
            if (
                !mobileNavigation.contains(event.target) &&
                !menuButton.contains(event.target)
            ) {
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
        });
    }
});
</script>

</body>
</html>
