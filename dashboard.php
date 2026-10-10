<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT
        id,
        name,
        email,
        country,
        referral_code,
        status
     FROM users
     WHERE id = ?
     LIMIT 1'
);

$stmt->execute([$userId]);

$user = $stmt->fetch();

if (!$user) {
    $_SESSION = [];
    session_destroy();

    redirect('login.php');
}

$userName = trim((string) ($user['name'] ?? 'Member'));

$nameParts = preg_split('/\s+/', $userName);

$firstName = $nameParts[0] ?? 'Member';

$availableBalance = getUserBalance($pdo, $userId);
$pendingBalance   = getUserPendingBalance($pdo, $userId);
$totalEarned      = getUserTotalEarned($pdo, $userId);

/*
|--------------------------------------------------------------------------
| Dashboard navigation
|--------------------------------------------------------------------------
| One navigation definition is used by the header.
| CSS changes its appearance between desktop and mobile.
*/

$currentPage = basename($_SERVER['PHP_SELF']);

$navigationItems = [
    [
        'label'  => 'Home',
        'href'   => 'dashboard.php',
        'icon'   => '⌂',
        'active' => $currentPage === 'dashboard.php',
    ],
    [
        'label'  => 'Earn',
        'href'   => 'offers.php',
        'icon'   => '▦',
        'active' => $currentPage === 'offers.php',
    ],
    [
        'label'  => 'History',
        'href'   => 'history.php',
        'icon'   => '◷',
        'active' => $currentPage === 'history.php',
    ],
    [
        'label'  => 'Refer & Earn',
        'href'   => 'referrals.php',
        'icon'   => '♧',
        'active' => $currentPage === 'referrals.php',
    ],
    [
        'label'  => 'Withdraw',
        'href'   => 'withdraw.php',
        'icon'   => '⇩',
        'active' => $currentPage === 'withdraw.php',
    ],
];

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >

    <meta
        name="description"
        content="Your PoketFlow rewards dashboard. Discover offers, track your earnings and manage your rewards."
    >

    <title>Dashboard — PoketFlow</title>

    <link
    rel="stylesheet"
    href="css/dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">

    
    

</head>

<body class="app-page">

<!-- =========================================================
     HEADER
     ========================================================= -->

<header class="app-header">

    <div class="header-inner">

        <!-- BRAND -->

        <a
            class="brand"
            href="index.php"
            aria-label="PoketFlow home"
        >

            <span class="brand-mark">
                P
            </span>

            <span class="brand-name">
                Poket<span>Flow</span>
            </span>

        </a>


        <!-- MAIN NAVIGATION -->

        <nav
            class="app-header-nav"
            id="app-navigation"
            aria-label="Main navigation"
        >

            <?php foreach ($navigationItems as $item): ?>

                <a
                    href="<?= e($item['href']) ?>"
                    class="<?= $item['active'] ? 'active' : '' ?>"
                    <?= $item['active'] ? 'aria-current="page"' : '' ?>
                >

                    <span class="nav-mobile-icon">
                        <?= e($item['icon']) ?>
                    </span>

                    <span class="nav-label">
                        <?= e($item['label']) ?>
                    </span>

                </a>

            <?php endforeach; ?>


            <!-- LOGOUT -->

            <a
                href="logout.php"
                class="nav-logout"
            >

                <span class="nav-mobile-icon">
                    ⇥
                </span>

                <span class="nav-label">
                    Log Out
                </span>

            </a>

        </nav>


        <!-- HEADER ACTIONS -->

        <div class="header-actions">

            <!-- BALANCE -->

            <a
                class="balance-button"
                href="withdraw.php"
                aria-label="Available balance"
            >

                <span class="balance-dot"></span>

                <span class="balance-amount">
                    $<?= number_format($availableBalance, 2) ?>
                </span>

            </a>


            <!-- HAMBURGER -->

            <button
                type="button"
                class="mobile-menu-button"
                aria-label="Open navigation menu"
                aria-expanded="false"
                aria-controls="app-navigation"
            >

                <span></span>
                <span></span>
                <span></span>

            </button>

        </div>

    </div>

</header>


<!-- =========================================================
     MAIN DASHBOARD
     ========================================================= -->

<main class="dashboard-main">

    <div class="dashboard-container">


        <!-- =================================================
             WELCOME
             ================================================= -->

        <section class="welcome-section">

            <div class="welcome-copy">

                <span class="kicker">
                    YOUR POKETFLOW DASHBOARD
                </span>

                <h1>
                    Welcome back,
                    <?= e($firstName) ?>
                    <span class="wave">👋</span>
                </h1>

                <p>
                    Explore earning opportunities, track your rewards
                    and manage your PoketFlow account.
                </p>

            </div>


            <a
                class="primary-button"
                href="offers.php"
            >

                <span>
                    Find Offers
                </span>

                <strong>
                    →
                </strong>

            </a>

        </section>


        <!-- =================================================
             BALANCE OVERVIEW
             ================================================= -->

        <section
            class="stats-grid"
            aria-label="Account statistics"
        >


            <!-- AVAILABLE -->

            <article class="stat-card stat-primary">

                <div class="stat-card-top">

                    <span class="stat-icon">
                        $
                    </span>

                    <span class="stat-label">
                        Available Balance
                    </span>

                </div>

                <strong class="stat-value">
                    $<?= number_format($availableBalance, 2) ?>
                </strong>

                <span class="stat-description">
                    Ready to withdraw when eligible
                </span>

            </article>


            <!-- PENDING -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <span class="stat-icon">
                        ◷
                    </span>

                    <span class="stat-label">
                        Pending
                    </span>

                </div>

                <strong class="stat-value">
                    $<?= number_format($pendingBalance, 2) ?>
                </strong>

                <span class="stat-description">
                    Rewards awaiting approval
                </span>

            </article>


            <!-- TOTAL EARNED -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <span class="stat-icon">
                        ↗
                    </span>

                    <span class="stat-label">
                        Total Earned
                    </span>

                </div>

                <strong class="stat-value">
                    $<?= number_format($totalEarned, 2) ?>
                </strong>

                <span class="stat-description">
                    Your lifetime rewards
                </span>

            </article>

        </section>


        <!-- =================================================
             WAYS TO EARN
             ================================================= -->

        <section class="earning-section">

            <div class="section-heading">

                <div>

                    <span class="section-kicker">
                        EARNING OPPORTUNITIES
                    </span>

                    <h2>
                        Ways to Earn
                    </h2>

                </div>

                <a
                    href="offers.php"
                    class="section-link"
                >

                    View all

                    <span>
                        →
                    </span>

                </a>

            </div>


            <div class="earn-grid">


                <!-- OFFERS -->

                <a
                    href="offers.php"
                    class="earn-card"
                >

                    <div class="earn-card-icon">
                        ▤
                    </div>

                    <div class="earn-card-content">

                        <h3>
                            Offers
                        </h3>

                        <p>
                            Browse available opportunities
                            and complete offers to earn rewards.
                        </p>

                    </div>

                    <span class="earn-arrow">
                        →
                    </span>

                </a>


                <!-- SURVEYS -->

                <a
                    href="offers.php"
                    class="earn-card"
                >

                    <div class="earn-card-icon">
                        ◎
                    </div>

                    <div class="earn-card-content">

                        <h3>
                            Surveys
                        </h3>

                        <p>
                            Share your opinions through
                            available survey opportunities.
                        </p>

                    </div>

                    <span class="earn-arrow">
                        →
                    </span>

                </a>


                <!-- REFERRALS -->

                <a
                    href="referrals.php"
                    class="earn-card"
                >

                    <div class="earn-card-icon">
                        ♧
                    </div>

                    <div class="earn-card-content">

                        <h3>
                            Refer &amp; Earn
                        </h3>

                        <p>
                            Invite friends and earn referral
                            rewards when eligible.
                        </p>

                    </div>

                    <span class="earn-arrow">
                        →
                    </span>

                </a>


            </div>

        </section>


        <!-- =================================================
             QUICK ACTION
             ================================================= -->

        <section class="dashboard-cta">

            <div class="dashboard-cta-content">

                <div class="cta-icon">
                    ✦
                </div>

                <div>

                    <span class="section-kicker">
                        KEEP EARNING
                    </span>

                    <h2>
                        New opportunities may be waiting.
                    </h2>

                    <p>
                        Check the available offers and discover
                        new ways to grow your rewards balance.
                    </p>

                </div>

            </div>


            <a
                href="offers.php"
                class="secondary-button"
            >
                Explore Offers →
            </a>

        </section>


    </div>

</main>


<!-- =========================================================
     MOBILE NAVIGATION SCRIPT
     ========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const menuButton =
        document.querySelector('.mobile-menu-button');

    const navigation =
        document.getElementById('app-navigation');

    if (!menuButton || !navigation) {
        return;
    }


    function closeMenu() {

        document.body.classList.remove('menu-open');

        menuButton.classList.remove('open');

        menuButton.setAttribute(
            'aria-expanded',
            'false'
        );

        menuButton.setAttribute(
            'aria-label',
            'Open navigation menu'
        );

        navigation.classList.remove('mobile-open');

    }


    function openMenu() {

        document.body.classList.add('menu-open');

        menuButton.classList.add('open');

        menuButton.setAttribute(
            'aria-expanded',
            'true'
        );

        menuButton.setAttribute(
            'aria-label',
            'Close navigation menu'
        );

        navigation.classList.add('mobile-open');

    }


    menuButton.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();

            if (
                navigation.classList.contains('mobile-open')
            ) {

                closeMenu();

            } else {

                openMenu();

            }

        }
    );


    navigation
        .querySelectorAll('a')
        .forEach(function (link) {

            link.addEventListener(
                'click',
                closeMenu
            );

        });


    document.addEventListener(
        'click',
        function (event) {

            if (
                !navigation.classList.contains('mobile-open')
            ) {
                return;
            }

            if (
                navigation.contains(event.target) ||
                menuButton.contains(event.target)
            ) {
                return;
            }

            closeMenu();

        }
    );


    document.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Escape') {
                closeMenu();
            }

        }
    );


    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth > 800) {
                closeMenu();
            }

        }
    );

});

</script>

</body>
</html>
