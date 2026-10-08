<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ogads.php';

/*
 * CPAGrip returns an array of normalized offers.
 *
 * Load it exactly once and capture the returned array.
 */
$cpagripOffers = require_once __DIR__ . '/includes/cpagrip.php';


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
// Load user
// --------------------------------------------------

$user = getUser($pdo, $userId);

if (!$user) {
    session_destroy();

    header('Location: login.php');
    exit;
}


// --------------------------------------------------
// User balance
// --------------------------------------------------

$availableBalance = getUserBalance(
    $pdo,
    $userId
);


// --------------------------------------------------
// Visitor information
// --------------------------------------------------

$visitorIp = isset($_SERVER['REMOTE_ADDR'])
    ? trim((string) $_SERVER['REMOTE_ADDR'])
    : '';

$userAgent = isset($_SERVER['HTTP_USER_AGENT'])
    ? trim((string) $_SERVER['HTTP_USER_AGENT'])
    : '';

$language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
    ? trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE'])
    : '';

$scheme = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
)
    ? 'https'
    : 'http';

$host = isset($_SERVER['HTTP_HOST'])
    ? trim((string) $_SERVER['HTTP_HOST'])
    : 'poketflow.com';

$site = $scheme . '://' . $host;


// --------------------------------------------------
// Campaign collection
// --------------------------------------------------

$campaigns = [];


// --------------------------------------------------
// OGAds
// --------------------------------------------------

$networkId = getOgadsNetworkId($pdo);

if ($networkId > 0) {

    $ogadsOffers = fetchOgadsOffers(
        $visitorIp,
        $userAgent,
        $language,
        $site,
        0,
        100
    );

    if (is_array($ogadsOffers)) {

        foreach ($ogadsOffers as $offer) {

            if (!is_array($offer)) {
                continue;
            }


            // --------------------------------------
            // External offer ID
            // --------------------------------------

            $externalOfferId = '';

            if (isset($offer['id'])) {
                $externalOfferId = (string) $offer['id'];
            } elseif (isset($offer['offer_id'])) {
                $externalOfferId = (string) $offer['offer_id'];
            }

            $externalOfferId = trim($externalOfferId);

            if ($externalOfferId === '') {
                continue;
            }


            // --------------------------------------
            // Basic information
            // --------------------------------------

            $title = trim(
                (string) (
                    $offer['name']
                    ?? $offer['title']
                    ?? 'Offer'
                )
            );

            $description = trim(
                (string) (
                    $offer['description']
                    ?? $offer['desc']
                    ?? ''
                )
            );

            $instructions = trim(
                (string) (
                    $offer['instructions']
                    ?? $offer['conversion']
                    ?? ''
                )
            );

            $category = trim(
                (string) (
                    $offer['category']
                    ?? 'Other'
                )
            );

            $countries = trim(
                (string) (
                    $offer['countries']
                    ?? $offer['country']
                    ?? ''
                )
            );

            $devices = trim(
                (string) (
                    $offer['devices']
                    ?? $offer['device']
                    ?? ''
                )
            );


            // --------------------------------------
            // Offer URL
            // --------------------------------------

            $networkOfferUrl = trim(
                (string) (
                    $offer['url']
                    ?? $offer['link']
                    ?? $offer['offer_url']
                    ?? ''
                )
            );

            if ($networkOfferUrl === '') {
                continue;
            }


            // --------------------------------------
            // Image
            // --------------------------------------

            $image = trim(
                (string) (
                    $offer['image']
                    ?? $offer['thumbnail']
                    ?? $offer['image_url']
                    ?? ''
                )
            );


            // --------------------------------------
            // Network payout
            // --------------------------------------

            $networkPayout = (float) (
                $offer['payout']
                ?? $offer['revenue']
                ?? 0
            );

            if ($networkPayout <= 0) {
                continue;
            }


            // --------------------------------------
            // Safety filter
            // --------------------------------------

            if (!isCampaignAllowed(
                $pdo,
                $title . ' ' .
                $description . ' ' .
                $category
            )) {
                continue;
            }


            // --------------------------------------
            // Existing campaign
            // --------------------------------------

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

            $existingCampaign = $campaignStmt->fetch(
                PDO::FETCH_ASSOC
            );


            if ($existingCampaign) {

                $status = strtoupper(
                    trim(
                        (string) (
                            $existingCampaign['status']
                            ?? ''
                        )
                    )
                );

                $approvalStatus = strtoupper(
                    trim(
                        (string) (
                            $existingCampaign['approval_status']
                            ?? ''
                        )
                    )
                );


                if (
                    $status !== 'ACTIVE' ||
                    $approvalStatus !== 'APPROVED'
                ) {
                    continue;
                }


                if (!isCampaignAllowed(
                    $pdo,
                    (string) (
                        ($existingCampaign['title'] ?? '')
                        . ' '
                        . ($existingCampaign['description'] ?? '')
                    )
                )) {
                    continue;
                }
            }


            // --------------------------------------
            // Calculate user reward
            // --------------------------------------

            $reward = calculateOgadsReward(
                $pdo,
                $networkPayout
            );

            if ($reward <= 0) {
                continue;
            }


            // --------------------------------------
            // Add normalized campaign
            // --------------------------------------

            $campaigns[] = [
                'network' => 'OGAds',
                'network_slug' => 'ogads',

                'offer_id' => $externalOfferId,

                'title' => $title,

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
        }
    }


    /*
     * Store the normalized OGAds offers in session.
     *
     * This allows start-offer.php to identify the selected
     * offer without trusting arbitrary user-submitted URLs.
     */
    $_SESSION['ogads_offers'] = [];

    foreach ($campaigns as $campaign) {

        if (
            isset($campaign['network']) &&
            $campaign['network'] === 'OGAds'
        ) {
            $_SESSION['ogads_offers'][
                (string) $campaign['offer_id']
            ] = $campaign;
        }
    }
}


// --------------------------------------------------
// CPAGrip
// --------------------------------------------------

if (!is_array($cpagripOffers)) {
    $cpagripOffers = [];
}

foreach ($cpagripOffers as $offer) {

    if (!is_array($offer)) {
        continue;
    }


    // ----------------------------------------------
    // Offer ID
    // ----------------------------------------------

    $externalOfferId = trim(
        (string) (
            $offer['offer_id']
            ?? ''
        )
    );

    if ($externalOfferId === '') {
        continue;
    }


    // ----------------------------------------------
    // Basic information
    // ----------------------------------------------

    $title = trim(
        (string) (
            $offer['title']
            ?? 'CPAGrip Offer'
        )
    );

    $description = trim(
        (string) (
            $offer['description']
            ?? ''
        )
    );

    $instructions = trim(
        (string) (
            $offer['instructions']
            ?? ''
        )
    );

    $category = trim(
        (string) (
            $offer['category']
            ?? $offer['type']
            ?? 'Other'
        )
    );

    $countries = trim(
        (string) (
            $offer['accepted_countries']
            ?? ''
        )
    );

    $networkOfferUrl = trim(
        (string) (
            $offer['offerlink']
            ?? ''
        )
    );

    $image = trim(
        (string) (
            $offer['image']
            ?? ''
        )
    );


    // ----------------------------------------------
    // Payout
    // ----------------------------------------------

    $networkPayout = (float) (
        $offer['payout']
        ?? 0
    );

    $reward = (float) (
        $offer['reward']
        ?? 0
    );


    if (
        $networkPayout <= 0 ||
        $reward <= 0 ||
        $networkOfferUrl === ''
    ) {
        continue;
    }


    // ----------------------------------------------
    // Safety filter
    // ----------------------------------------------

    if (!isCampaignAllowed(
        $pdo,
        $title . ' ' .
        $description . ' ' .
        $category
    )) {
        continue;
    }


    // ----------------------------------------------
    // CPAGrip network ID
    // ----------------------------------------------

    $cpagripNetworkStmt = $pdo->prepare(
        'SELECT id
         FROM networks
         WHERE slug = ?
         LIMIT 1'
    );

    $cpagripNetworkStmt->execute([
        'cpagrip',
    ]);

    $cpagripNetworkId = (int) (
        $cpagripNetworkStmt->fetchColumn()
        ?: 0
    );


    // ----------------------------------------------
    // Existing CPAGrip campaign
    // ----------------------------------------------

    if ($cpagripNetworkId > 0) {

        $campaignStmt = $pdo->prepare(
            'SELECT *
             FROM campaigns
             WHERE network_id = ?
             AND external_offer_id = ?
             LIMIT 1'
        );

        $campaignStmt->execute([
            $cpagripNetworkId,
            $externalOfferId,
        ]);

        $existingCampaign = $campaignStmt->fetch(
            PDO::FETCH_ASSOC
        );


        if ($existingCampaign) {

            $status = strtoupper(
                trim(
                    (string) (
                        $existingCampaign['status']
                        ?? ''
                    )
                )
            );

            $approvalStatus = strtoupper(
                trim(
                    (string) (
                        $existingCampaign['approval_status']
                        ?? ''
                    )
                )
            );


            if (
                $status !== 'ACTIVE' ||
                $approvalStatus !== 'APPROVED'
            ) {
                continue;
            }


            if (!isCampaignAllowed(
                $pdo,
                (string) (
                    ($existingCampaign['title'] ?? '')
                    . ' '
                    . ($existingCampaign['description'] ?? '')
                )
            )) {
                continue;
            }
        }
    }


    // ----------------------------------------------
    // Add normalized CPAGrip campaign
    // ----------------------------------------------

    $campaigns[] = [
        'network' => 'CPAGrip',
        'network_slug' => 'cpagrip',

        'offer_id' => $externalOfferId,

        'title' => $title,

        'description' => $description,

        'instructions' => $instructions,

        'category' => $category,

        'countries' => $countries,

        'devices' => '',

        'network_offer_url' => $networkOfferUrl,

        'image' => $image,

        'network_payout' => $networkPayout,

        'reward' => $reward,
    ];
}


// --------------------------------------------------
// Helpers
// --------------------------------------------------

function getShortOfferDescription(
    string $description,
    int $length = 140
): string {

    $description = trim(
        strip_tags($description)
    );

    if ($description === '') {
        return 'Complete this offer to earn rewards.';
    }

    if (function_exists('mb_strlen')) {

        if (mb_strlen($description) <= $length) {
            return $description;
        }

        return rtrim(
            mb_substr(
                $description,
                0,
                $length
            )
        ) . '...';
    }

    if (strlen($description) <= $length) {
        return $description;
    }

    return rtrim(
        substr(
            $description,
            0,
            $length
        )
    ) . '...';
}


function getOfferIcon(string $category): string
{
    $category = strtolower(
        trim($category)
    );

    if (
        str_contains($category, 'survey') ||
        str_contains($category, 'research')
    ) {
        return 'fa-clipboard-list';
    }

    if (
        str_contains($category, 'app') ||
        str_contains($category, 'mobile')
    ) {
        return 'fa-mobile-screen-button';
    }

    if (
        str_contains($category, 'game') ||
        str_contains($category, 'gaming')
    ) {
        return 'fa-gamepad';
    }

    if (
        str_contains($category, 'shopping') ||
        str_contains($category, 'retail')
    ) {
        return 'fa-bag-shopping';
    }

    if (
        str_contains($category, 'video') ||
        str_contains($category, 'entertainment')
    ) {
        return 'fa-play';
    }

    if (
        str_contains($category, 'finance') ||
        str_contains($category, 'money')
    ) {
        return 'fa-wallet';
    }

    return 'fa-gift';
}


function getOfferIconClass(string $network): string
{
    return strtolower(
        trim($network)
    ) === 'cpagrip'
        ? 'offer-icon-cpagrip'
        : 'offer-icon-ogads';
}


function getOfferCategory(array $campaign): string
{
    $category = trim(
        (string) (
            $campaign['category']
            ?? ''
        )
    );

    return $category !== ''
        ? $category
        : 'General';
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

    <title>
        <?= e($pageTitle) ?> - PoketFlow
    </title>

    <link
        rel="stylesheet"
        href="css/offers.css"
    >

    

</head>

<body class="app-page">


<header class="app-header">

    <div class="header-left">

        <a
            href="dashboard.php"
            class="brand"
        >

            <span class="brand-mark">
                P
            </span>

            <span class="brand-text">
                Poket<span>Flow</span>
            </span>

        </a>

    </div>


    <nav class="app-header-nav">

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


    <div class="header-actions">

        <div class="header-balance">

            <span class="balance-label">
                Balance
            </span>

            <strong>
                $<?= number_format(
                    (float) $availableBalance,
                    2
                ) ?>
            </strong>

        </div>


        <!-- ONE hamburger button only -->

        <button
            type="button"
            class="mobile-menu-button"
            id="mobileMenuButton"
            aria-label="Open menu"
            aria-controls="mobileNavigation"
            aria-expanded="false"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

    </div>

</header>


<!-- Mobile navigation -->

<nav
    class="mobile-navigation"
    id="mobileNavigation"
    aria-hidden="true"
>

    <a href="dashboard.php">
        <i class="fa-solid fa-house"></i>
        <span>Dashboard</span>
    </a>

    <a
        href="offers.php"
        class="active"
    >
        <i class="fa-solid fa-gift"></i>
        <span>Earn Rewards</span>
    </a>

    <a href="history.php">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>History</span>
    </a>

    <a href="referrals.php">
        <i class="fa-solid fa-users"></i>
        <span>Referrals</span>
    </a>

    <a href="withdraw.php">
        <i class="fa-solid fa-money-bill-transfer"></i>
        <span>Withdraw</span>
    </a>

    <a href="logout.php">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Logout</span>
    </a>

</nav>


<main class="app-shell">


    <!-- Desktop sidebar -->

    <aside class="sidebar">

        <nav class="sidebar-nav">

            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="offers.php"
                class="active"
            >
                <i class="fa-solid fa-gift"></i>
                <span>Earn Rewards</span>
            </a>

            <a href="history.php">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>History</span>
            </a>

            <a href="referrals.php">
                <i class="fa-solid fa-users"></i>
                <span>Referrals</span>
            </a>

            <a href="withdraw.php">
                <i class="fa-solid fa-money-bill-transfer"></i>
                <span>Withdraw</span>
            </a>

            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>

        </nav>


        <div class="sidebar-balance">

            <span>
                Available Balance
            </span>

            <strong>
                $<?= number_format(
                    (float) $availableBalance,
                    2
                ) ?>
            </strong>

            <a href="withdraw.php">
                Withdraw
            </a>

        </div>

    </aside>


    <section class="app-content">


        <div class="page-heading">

            <div>

                <span class="eyebrow">
                    REWARDS
                </span>

                <h1>
                    Earn Rewards
                </h1>

                <p>
                    Complete available offers and earn rewards
                    directly to your PoketFlow balance.
                </p>

            </div>

        </div>


        <!-- Filters -->

        <div class="offer-filters">

            <button
                type="button"
                class="filter-button active"
                data-filter="all"
            >
                All
            </button>

            <button
                type="button"
                class="filter-button"
                data-filter="ogads"
            >
                OGAds
            </button>

            <button
                type="button"
                class="filter-button"
                data-filter="cpagrip"
            >
                CPAGrip
            </button>

        </div>


        <?php if (empty($campaigns)): ?>

            <div class="empty-state">

                <div class="empty-icon">

                    <i class="fa-solid fa-gift"></i>

                </div>

                <h2>
                    No offers available right now
                </h2>

                <p>
                    There are currently no approved offers
                    available for your account. Please check
                    again later.
                </p>

                <span class="offer-network">
                    PoketFlow
                </span>

            </div>

        <?php else: ?>


            <div class="offers-grid">

                <?php foreach ($campaigns as $campaign): ?>

                    <?php

                    $network = (string) (
                        $campaign['network']
                        ?? 'PoketFlow'
                    );

                    $networkSlug = strtolower(
                        (string) (
                            $campaign['network_slug']
                            ?? $network
                        )
                    );

                    $title = (string) (
                        $campaign['title']
                        ?? 'Reward Offer'
                    );

                    $description = getShortOfferDescription(
                        (string) (
                            $campaign['description']
                            ?? ''
                        )
                    );

                    $category = getOfferCategory(
                        $campaign
                    );

                    $image = trim(
                        (string) (
                            $campaign['image']
                            ?? ''
                        )
                    );

                    $reward = (float) (
                        $campaign['reward']
                        ?? 0
                    );

                    $offerId = (string) (
                        $campaign['offer_id']
                        ?? ''
                    );

                    ?>

                    <article
                        class="offer-card"
                        data-network="<?= e($networkSlug) ?>"
                    >


                        <!-- Offer image -->

                        <div class="offer-image">

                            <?php if ($image !== ''): ?>

                                <img
                                    src="<?= e($image) ?>"
                                    alt="<?= e($title) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div
                                    class="offer-fallback-icon <?= e(
                                        getOfferIconClass($network)
                                    ) ?>"
                                >

                                    <i
                                        class="fa-solid <?= e(
                                            getOfferIcon($category)
                                        ) ?>"
                                    ></i>

                                </div>

                            <?php endif; ?>

                        </div>


                        <div class="offer-body">


                            <div class="offer-topline">

                                <span class="offer-network">
                                    <?= e($network) ?>
                                </span>

                                <span class="offer-category">
                                    <?= e($category) ?>
                                </span>

                            </div>


                            <h2 class="offer-title">
                                <?= e($title) ?>
                            </h2>


                            <p class="offer-description">
                                <?= e($description) ?>
                            </p>


                            <div class="offer-footer">

                                <div class="offer-reward">

                                    <span>
                                        Earn
                                    </span>

                                    <strong>
                                        $<?= number_format(
                                            $reward,
                                            2
                                        ) ?>
                                    </strong>

                                </div>


                                <?php if ($networkSlug === 'cpagrip'): ?>

                                    <a
                                        href="<?= e(
                                            (string) (
                                                $campaign['network_offer_url']
                                                ?? '#'
                                            )
                                        ) ?>"
                                        class="start-offer-button"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Start Offer
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>

                                <?php else: ?>

                                    <a
                                        href="start-offer.php?offer_id=<?= urlencode(
                                            $offerId
                                        ) ?>"
                                        class="start-offer-button"
                                    >
                                        Start Offer
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


        <div class="offers-info">

            <div class="offers-info-icon">
                <i class="fa-solid fa-circle-info"></i>
            </div>

            <div>

                <strong>
                    Important
                </strong>

                <p>
                    Complete offers honestly using the required
                    information and device. Rewards may be reviewed
                    by the offer network before being credited.
                </p>

            </div>

        </div>


    </section>

</main>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const menuButton =
        document.getElementById('mobileMenuButton');

    const mobileNavigation =
        document.getElementById('mobileNavigation');

    if (menuButton && mobileNavigation) {

        menuButton.addEventListener('click', function () {

            const isOpen =
                menuButton.classList.contains('active');

            menuButton.classList.toggle(
                'active',
                !isOpen
            );

            mobileNavigation.classList.toggle(
                'active',
                !isOpen
            );

            menuButton.setAttribute(
                'aria-expanded',
                String(!isOpen)
            );

            mobileNavigation.setAttribute(
                'aria-hidden',
                String(isOpen)
            );

        });


        mobileNavigation
            .querySelectorAll('a')
            .forEach(function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        menuButton.classList.remove(
                            'active'
                        );

                        mobileNavigation.classList.remove(
                            'active'
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

            });

    }


    // --------------------------------------------------
    // Offer filters
    // --------------------------------------------------

    const filterButtons =
        document.querySelectorAll(
            '.filter-button'
        );

    const offerCards =
        document.querySelectorAll(
            '.offer-card'
        );


    filterButtons.forEach(function (button) {

        button.addEventListener(
            'click',
            function () {

                const filter =
                    button.getAttribute(
                        'data-filter'
                    );

                filterButtons.forEach(
                    function (item) {

                        item.classList.remove(
                            'active'
                        );

                    }
                );

                button.classList.add(
                    'active'
                );


                offerCards.forEach(
                    function (card) {

                        const network =
                            card.getAttribute(
                                'data-network'
                            );

                        if (
                            filter === 'all' ||
                            network === filter
                        ) {

                            card.style.display =
                                '';

                        } else {

                            card.style.display =
                                'none';

                        }

                    }
                );

            }
        );

    });

});

</script>

</body>
</html>
