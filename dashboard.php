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

$userName = trim($user['name'] ?? 'Member');

$nameParts = explode(' ', $userName);

$firstName = $nameParts[0] ?? 'Member';

$availableBalance = getUserBalance($pdo, $userId);
$pendingBalance = getUserPendingBalance($pdo, $userId);
$totalEarned = getUserTotalEarned($pdo, $userId);

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Dashboard — PoketFlow</title>

    <link
        rel="stylesheet"
        href="css/dashboard.css"
    >

</head>

<body class="app-page">

<header class="app-header">

    <!-- Brand -->
    <a class="brand" href="index.php">

        <span class="brand-mark">P</span>

        <span>
            Poket<span>Flow</span>
        </span>

    </a>


    <!-- Desktop Navigation -->
    <nav class="app-header-nav">

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


    <!-- Header Actions -->
    <div class="header-actions">

        <a
            class="balance-button"
            href="withdraw.php"
        >
            $<?= number_format($availableBalance, 2) ?>
        </a>


        <!-- Mobile Hamburger -->
        <button
            type="button"
            class="mobile-menu-button"
            aria-label="Open menu"
            aria-expanded="false"
            aria-controls="mobile-navigation"
        >

            <span></span>
            <span></span>
            <span></span>

        </button>

    </div>

</header>


<!-- Mobile Navigation -->

<div
    class="mobile-navigation"
    id="mobile-navigation"
    aria-hidden="true"
>

    <nav>

        <a
            class="active"
            href="dashboard.php"
        >
            <span class="mobile-nav-icon">⌂</span>
            <span>Home</span>
        </a>


        <a href="offers.php">

            <span class="mobile-nav-icon">▦</span>

            <span>Earn</span>

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


<!-- Main Application Shell -->

<main class="app-shell">


    <!-- Desktop Sidebar -->

    <aside class="sidebar">

        <div class="sidebar-nav">

            <a
                class="active"
                href="dashboard.php"
            >
                <span class="sidebar-icon">⌂</span>
                <span>Home</span>
            </a>


            <a href="offers.php">

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


        <!-- Sidebar Balance -->

        <div class="side-balance">

            <small>Your Balance</small>

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


    <!-- Dashboard Content -->

    <section class="app-content">


        <!-- Welcome -->

        <div class="welcome">

            <div>

                <span class="kicker">
                    YOUR POKETFLOW DASHBOARD
                </span>

                <h1>
                    Welcome back,
                    <?= e($firstName) ?>
                    👋
                </h1>

                <p>
                    Explore the latest earning opportunities available to you.
                </p>

            </div>


            <a
                class="btn btn-primary"
                href="offers.php"
            >
                Find Offers →
            </a>

        </div>


        <!-- Statistics -->

        <div class="stats-row">


            <div class="stat-card">

                <span>
                    Available Balance
                </span>

                <strong>
                    $<?= number_format($availableBalance, 2) ?>
                </strong>

                <small>
                    Ready when eligible
                </small>

            </div>


            <div class="stat-card">

                <span>
                    Pending
                </span>

                <strong>
                    $<?= number_format($pendingBalance, 2) ?>
                </strong>

                <small>
                    Awaiting approval
                </small>

            </div>


            <div class="stat-card">

                <span>
                    Total Earned
                </span>

                <strong>
                    $<?= number_format($totalEarned, 2) ?>
                </strong>

                <small>
                    Your lifetime rewards
                </small>

            </div>

        </div>


        <!-- Ways To Earn -->

        <div class="content-heading">

            <h2>
                Ways to Earn
            </h2>

            <a href="offers.php">
                View all →
            </a>

        </div>


        <div class="earn-grid">


            <a
                href="offers.php"
                class="earn-card"
            >

                <span>▤</span>

                <h3>
                    Offers
                </h3>

                <p>
                    Browse available opportunities.
                </p>

                <b>
                    Explore →
                </b>

            </a>


            <a
                href="offers.php"
                class="earn-card"
            >

                <span>◎</span>

                <h3>
                    Surveys
                </h3>

                <p>
                    Share your opinions.
                </p>

                <b>
                    Explore →
                </b>

            </a>


            <a
                href="referrals.php"
                class="earn-card"
            >

                <span>♧</span>

                <h3>
                    Refer &amp; Earn
                </h3>

                <p>
                    Invite friends when eligible.
                </p>

                <b>
                    Learn more →
                </b>

            </a>


        </div>

    </section>

</main>


<!-- Mobile Menu JavaScript -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const menuButton = document.querySelector('.mobile-menu-button');

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
    }


    menuButton.addEventListener('click', function (event) {

        event.stopPropagation();

        const isOpen =
            !mobileNavigation.classList.contains('open');


        if (isOpen) {

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

        } else {

            closeMenu();

        }

    });


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
                mobileNavigation.classList.contains('open') &&
                !mobileNavigation.contains(event.target) &&
                !menuButton.contains(event.target)
            ) {

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

</script>

</body>
</html>
