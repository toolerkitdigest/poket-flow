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
$pendingBalance = getUserPendingBalance($pdo, $userId);
$totalEarned = getUserTotalEarned($pdo, $userId);

?><!doctype html>

<html lang="en"><head><meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<meta
    name="description"
    content="Your PoketFlow rewards dashboard. Discover offers, track your earnings and manage your rewards."
>

<title>Dashboard — PoketFlow</title>

<link
    rel="stylesheet"
    href="css/dashboard.css?v=3"
>

</head><body class="app-page"><!-- =========================================================
     HEADER
     ========================================================= --><header class="app-header"><div class="header-inner">


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


    <!-- DESKTOP NAVIGATION -->

    <nav
        class="app-header-nav"
        aria-label="Main navigation"
    >

        <a
            class="active"
            href="dashboard.php"
        >
            Home
        </a>

        <a href="offers.php">
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


    <!-- HEADER ACTIONS -->

    <div class="header-actions">


        <!-- BALANCE -->

        <a
            class="balance-button"
            href="withdraw.php"
            aria-label="Available balance"
        >

            <span class="balance-dot"></span>

            <span>
                $<?= number_format($availableBalance, 2) ?>
            </span>

        </a>


        <!-- MOBILE MENU BUTTON -->

        <button
            type="button"
            class="mobile-menu-button"
            aria-label="Open navigation menu"
            aria-expanded="false"
            aria-controls="mobile-navigation"
        >

            <span></span>
            <span></span>
            <span></span>

        </button>

    </div>

</div>

</header><!-- =========================================================
     MOBILE NAVIGATION
     One navigation only — no duplicate sidebar.
     ========================================================= --><div
    class="mobile-navigation"
    id="mobile-navigation"
    aria-hidden="true"
><nav aria-label="Mobile navigation">


    <a
        class="active"
        href="dashboard.php"
    >

        <span class="mobile-nav-icon">
            ⌂
        </span>

        <span>
            Home
        </span>

    </a>


    <a href="offers.php">

        <span class="mobile-nav-icon">
            ▦
        </span>

        <span>
            Earn
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
            Refer &amp; Earn
        </span>

    </a>


    <a href="withdraw.php">

        <span class="mobile-nav-icon">
            ⇩
        </span>

        <span>
            Withdraw
        </span>

    </a>


    <a
        href="logout.php"
        class="mobile-logout"
    >

        <span class="mobile-nav-icon">
            ⇥
        </span>

        <span>
            Log Out
        </span>

    </a>

</nav>

</div><!-- =========================================================
     MAIN DASHBOARD
     ========================================================= --><main class="dashboard-main"><div class="dashboard-container">


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
                <span>→</span>
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

</main><!-- =========================================================
     MOBILE MENU SCRIPT
     ========================================================= --><script>

document.addEventListener('DOMContentLoaded', function () {

    const menuButton =
        document.querySelector('.mobile-menu-button');

    const mobileNavigation =
        document.getElementById('mobile-navigation');


    if (!menuButton || !mobileNavigation) {
        return;
    }


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

        document.body.classList.remove(
            'menu-open'
        );
    }


    function openMenu() {

        menuButton.classList.add('open');

        mobileNavigation.classList.add('open');

        menuButton.setAttribute(
            'aria-expanded',
            'true'
        );

        mobileNavigation.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.classList.add(
            'menu-open'
        );
    }


    menuButton.addEventListener(
        'click',
        function (event) {

            event.stopPropagation();

            if (
                mobileNavigation.classList.contains('open')
            ) {

                closeMenu();

            } else {

                openMenu();

            }

        }
    );


    mobileNavigation
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
                !mobileNavigation.classList.contains('open')
            ) {
                return;
            }

            if (
                mobileNavigation.contains(event.target) ||
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

            if (window.innerWidth > 900) {
                closeMenu();
            }

        }
    );

});

</script></body>
</html>
