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

$userId = (int) ($_SESSION['user_id'] ?? 0);

$user = getUser(
    $pdo,
    $userId
);


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
// OFFER COLLECTION
// ==================================================
//
// Each network is completely independent.
//
// OGAds:
// includes/networks/ogads/offers.php
//
// CPAGrip:
// includes/networks/cpagrip/offers.php
//
// The main page only combines the results.
// ==================================================

$campaigns = [];

$ogadsError = null;

$cpagripError = null;


// ==================================================
// LOAD OGADS OFFERS
// ==================================================

try {

    $ogadsOffers = getOgadsDisplayOffers(
        $pdo
    );


    if (!is_array($ogadsOffers)) {
        $ogadsOffers = [];
    }


    foreach ($ogadsOffers as $offer) {

        if (!is_array($offer)) {
            continue;
        }

        $campaigns[] = $offer;
    }


    // Store current visitor's OGAds offers.
    $_SESSION['ogads_offers'] = $ogadsOffers;


} catch (Throwable $e) {

    $ogadsError = $e->getMessage();

    $_SESSION['ogads_offers'] = [];
}


// ==================================================
// LOAD CPAGRIP OFFERS
// ==================================================

try {

    $cpagripOffers = getCpagripDisplayOffers(
        $pdo
    );


    if (!is_array($cpagripOffers)) {
        $cpagripOffers = [];
    }


    foreach ($cpagripOffers as $offer) {

        if (!is_array($offer)) {
            continue;
        }

        $campaigns[] = $offer;
    }


    // Store current visitor's CPAGrip offers.
    $_SESSION['cpagrip_offers'] = $cpagripOffers;


} catch (Throwable $e) {

    $cpagripError = $e->getMessage();

    $_SESSION['cpagrip_offers'] = [];
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
    $category = strtolower(
        trim($category)
    );


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
    $category = strtolower(
        trim($category)
    );


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
        (string) (
            $campaign['category'] ?? ''
        )
    );


    if ($category !== '') {
        return $category;
    }


    return match (
        $campaign['source_type'] ?? ''
    ) {

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
            Balance $<?= number_format(
                $availableBalance,
                2
            ) ?>
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
                ⌂
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
                ▦
            </span>

            <span>
                Earn Rewards
            </span>

        </a>


        <a href="history.php">

            <span class="mobile-nav-icon">
                ◷
            </span>

            <span>
                History
            </span>

        </a>


        <a href="referrals.php">

            <span class="mobile-nav-icon">
                ♧
            </span>

            <span>
                Refer & Earn
            </span>

        </a>


        <a href="withdraw.php">

            <span class="mobile-nav-icon">
                ▣
            </span>

            <span>
                Withdraw
            </span>

        </a>


        <a href="logout.php">

            <span class="mobile-nav-icon">
                ↪
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


    <aside class="sidebar">

        <div class="sidebar-nav">

            <a href="dashboard.php">

                ⌂

                <span>
                    Home
                </span>

            </a>


            <a
                class="active"
                href="offers.php"
            >

                ▦

                <span>
                    Offers
                </span>

            </a>


            <a href="history.php">

                ◷

                <span>
                    History
                </span>

            </a>


            <a href="referrals.php">

                ♧

                <span>
                    Refer & Earn
                </span>

            </a>


            <a href="withdraw.php">

                ▣

                <span>
                    Withdraw
                </span>

            </a>


            <a href="logout.php">

                ↪

                <span>
                    Log Out
                </span>

            </a>

        </div>


        <div class="side-balance">

            <small>
                Your Balance
            </small>


            <strong>
                $<?= number_format(
                    $availableBalance,
                    2
                ) ?>
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


                    <?php foreach (
                        $campaigns as $campaign
                    ): ?>


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


                        $description =
                            getShortOfferDescription(
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


                        $network = trim(
                            (string) (
                                $campaign['network']
                                ?? ''
                            )
                        );


                        // ------------------------------------------
                        // Prepare unified Start Offer URL.
                        //
                        // Both OGAds and CPAGrip now use:
                        //
                        // start-offer.php
                        //
                        // The network parameter tells the launch
                        // controller which CPA network to use.
                        // ------------------------------------------

                        $launchNetwork = strtolower(
                            $network
                        );


                        $launchOfferId = (string) (
                            $campaign[
                                'external_offer_id'
                            ] ?? ''
                        );


                        $startOfferUrl =
                            'start-offer.php?network=' .
                            rawurlencode(
                                $launchNetwork
                            ) .
                            '&offer_id=' .
                            rawurlencode(
                                $launchOfferId
                            );

                        ?>


                        <article
                            class="offer-card"
                            data-offer-category="<?= e(
                                strtolower($category)
                            ) ?>"
                        >


                            <div
                                class="offer-image <?= e(
                                    $iconClass
                                ) ?>"
                            >


                                <?php if (
                                    $imageUrl !== ''
                                ): ?>


                                    <img
                                        src="<?= e(
                                            $imageUrl
                                        ) ?>"
                                        alt=""
                                        loading="lazy"
                                    >


                                <?php else: ?>


                                    <div
                                        class="offer-fallback-icon"
                                    >
                                        <?= e($icon) ?>
                                    </div>


                                <?php endif; ?>


                            </div>


                            <div class="offer-body">


                                <h3>
                                    <?= e($title) ?>
                                </h3>


                                <p>
                                    <?= e(
                                        $description
                                    ) ?>
                                </p>


                            </div>


                            <div class="offer-bottom">


                                <strong>
                                    Earn
                                    $<?= number_format(
                                        $reward,
                                        2
                                    ) ?>
                                </strong>


                                <!--
                                    UNIFIED START OFFER BUTTON

                                    OGAds:
                                    start-offer.php?network=ogads&offer_id=...

                                    CPAGrip:
                                    start-offer.php?network=cpagrip&offer_id=...

                                    Both networks therefore pass through
                                    the same secure launch controller.
                                -->

                                <a
                                    href="<?= e(
                                        $startOfferUrl
                                    ) ?>"
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


        // ==================================================
        // OFFER FILTERS
        // ==================================================

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
                                        card.dataset
                                            .offerCategory
                                        || ''
                                    ).toLowerCase();


                                let show = false;


                                if (
                                    filter === 'all'
                                ) {

                                    show = true;

                                }


                                if (
                                    filter === 'app'
                                ) {

                                    show =
                                        category.includes(
                                            'app'
                                        ) ||
                                        category.includes(
                                            'install'
                                        );

                                }


                                if (
                                    filter === 'survey'
                                ) {

                                    show =
                                        category.includes(
                                            'survey'
                                        );

                                }


                                if (
                                    filter === 'other'
                                ) {

                                    show =
                                        !category.includes(
                                            'app'
                                        ) &&
                                        !category.includes(
                                            'install'
                                        ) &&
                                        !category.includes(
                                            'survey'
                                        );

                                }


                                card.style.display =
                                    show
                                        ? ''
                                        : 'none';

                            }
                        );

                    }
                );

            }
        );


        // ==================================================
        // MOBILE MENU
        // ==================================================

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
                        !mobileNavigation.contains(
                            event.target
                        ) &&
                        !menuButton.contains(
                            event.target
                        )
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
