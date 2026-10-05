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
$cpagripOffers = [];

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
// FETCH LIVE VISITOR-SPECIFIC OGADS OFFERS
// ==================================================

$campaigns = [];

try {

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    $scheme = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ) ? 'https' : 'http';

    $site = $scheme . '://' . (
        $_SERVER['HTTP_HOST'] ?? 'poketflow.com'
    ) . (
        $_SERVER['REQUEST_URI'] ?? '/offers.php'
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
            (string) ($offer['offerid'] ?? '')
        );


        if ($externalOfferId === '') {
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


        $category = getOgadsOfferCategory(
            $offer
        );


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


        // ==================================================
        // IMPORTANT:
        //
        // Check whether this OGAds offer already has a
        // PoketFlow campaign record.
        //
        // If an administrator paused/rejected it, we MUST
        // respect that decision.
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
        // Existing campaign?
        // --------------------------------------------------

        if ($existingCampaign) {

            $existingStatus = strtoupper(
                trim(
                    (string) (
                        $existingCampaign['status']
                        ?? ''
                    )
                )
            );


            $existingApproval = strtoupper(
                trim(
                    (string) (
                        $existingCampaign['approval_status']
                        ?? ''
                    )
                )
            );


            // ----------------------------------------------
            // ADMIN CONTROL IS AUTHORITATIVE
            //
            // Paused, rejected, pending, completed or
            // expired campaigns must not appear.
            // ----------------------------------------------

            if (
                $existingStatus !== 'ACTIVE' ||
                $existingApproval !== 'APPROVED'
            ) {
                continue;
            }


            // ----------------------------------------------
            // Load the actual database campaign.
            // ----------------------------------------------

            $databaseCampaign = getCampaign(
                $pdo,
                (int) $existingCampaign['id']
            );


            if (!$databaseCampaign) {
                continue;
            }


            // ----------------------------------------------
            // Final database safety check.
            // ----------------------------------------------

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
        // Build visitor-specific offer
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
    // Store current visitor's eligible offers
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


/**
 * Create a short, clean description for the offer card.
 *
 * We intentionally do NOT display raw OGAds metadata.
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


// --------------------------------------------------
// Determine offer icon
// --------------------------------------------------

function getOfferIcon(string $category): string
{
    $category = strtolower(trim($category));

    return match (true) {

        str_contains($category, 'app'),
        str_contains($category, 'install')
            => '◎',

        str_contains($category, 'survey')
            => '▤',

        str_contains($category, 'submit')
            => '◇',

        default
            => '◆',
    };
}


// --------------------------------------------------
// Determine icon class
// --------------------------------------------------

function getOfferIconClass(string $category): string
{
    $category = strtolower(trim($category));

    return match (true) {

        str_contains($category, 'survey')
            => 'orange',

        str_contains($category, 'special'),
        str_contains($category, 'featured')
            => 'cyan',

        default
            => '',
    };
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

    return match ($campaign['source_type'] ?? '') {

        'DIRECT_ADVERTISER'
            => 'Special Offer',

        default
            => 'Offer',
    };
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
        href="assets/poketflow.css"
    >

    <link
        rel="stylesheet"
        href="assets/offers.css"
    >

</head>


<body class="app-page">


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

</div>


<!-- ==================================================
     APP LAYOUT
================================================== -->

<main class="app-shell">


    <aside class="sidebar">

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


        <?php if ($ogadsError !== null): ?>

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

                            <h3>
                                No offers available right now
                            </h3>


                            <p>
                                There are currently no offers
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
                                $campaign['title']
                                ?? 'Available Offer'
                            )
                        );


                        $description = getShortOfferDescription(
                            (string) (
                                $campaign['description']
                                ?? ''
                            ),
                            $title
                        );


                        $reward = (float) (
                            $campaign['worker_reward']
                            ?? 0
                        );


                        $imageUrl = trim(
                            (string) (
                                $campaign['image_url']
                                ?? ''
                            )
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


                                <a
                                    href="start-offer.php?offer_id=<?= e((string) $campaign['external_offer_id']) ?>"
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


</main>


<script>

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
                                        card.dataset.offerCategory
                                        || ''
                                    ).toLowerCase();


                                let show = false;


                                if (filter === 'all') {

                                    show = true;

                                }


                                if (filter === 'app') {

                                    show =
                                        category.includes('app') ||
                                        category.includes('install');

                                }


                                if (filter === 'survey') {

                                    show =
                                        category.includes('survey');

                                }


                                if (filter === 'other') {

                                    show =
                                        !category.includes('app') &&
                                        !category.includes('install') &&
                                        !category.includes('survey');

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
