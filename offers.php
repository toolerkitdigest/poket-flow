<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/networks/ogads/offers.php';
require_once __DIR__ . '/includes/networks/cpagrip/offers.php';


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
// OGADS
// ==================================================
//
// All OGAds-specific work now happens inside:
//
// includes/networks/ogads/offers.php
//
// offers.php only asks the module for campaigns.
// ==================================================

try {

    $ogadsCampaigns = getOgadsDisplayOffers(
        $pdo
    );


    /*
     * Preserve the existing session behavior.
     *
     * This keeps the current visitor's eligible
     * OGAds campaigns available to other PoketFlow
     * pages such as start-offer.php if required.
     */
    $_SESSION['ogads_offers'] = $ogadsCampaigns;


    $campaigns = array_merge(
        $campaigns,
        $ogadsCampaigns
    );


} catch (Throwable $e) {

    $ogadsError = $e->getMessage();

    $_SESSION['ogads_offers'] = [];
}


// ==================================================
// CPAGRIP
// ==================================================
//
// All CPAGrip-specific work now happens inside:
//
// includes/networks/cpagrip/offers.php
//
// It is completely independent from OGAds.
// ==================================================

try {

    $cpagripCampaigns = getCpagripDisplayOffers(
        $pdo
    );


    $campaigns = array_merge(
        $campaigns,
        $cpagripCampaigns
    );


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
