<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';


// --------------------------------------------------
// Protect dashboard
// --------------------------------------------------

if (!isLoggedIn()) {
    redirect('login.php');
}


// --------------------------------------------------
// Get logged-in user
// --------------------------------------------------

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


// --------------------------------------------------
// Safety check
// --------------------------------------------------

if (!$user) {

    $_SESSION = [];

    session_destroy();

    redirect('login.php');
}


// --------------------------------------------------
// User information
// --------------------------------------------------

$userName = trim($user['name'] ?? 'Member');

$nameParts = explode(' ', $userName);

$firstName = $nameParts[0] ?? 'Member';


/// --------------------------------------------------
// Dashboard earnings
// --------------------------------------------------

$availableBalance = getUserBalance(
    $pdo,
    $userId
);

$pendingBalance = getUserPendingBalance(
    $pdo,
    $userId
);

$totalEarned = getUserTotalEarned(
    $pdo,
    $userId
);
?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>Dashboard — PoketFlow</title>

    <link
        rel="stylesheet"
        href="assets/dashboard.css"
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


    <nav>

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
            Refer & Earn
        </a>

        <a href="withdraw.php">
            Withdraw
        </a>

    </nav>


    <div class="header-actions">

        <a
            class="btn btn-primary"
            href="withdraw.php"
        >
            $<?= number_format($availableBalance, 2) ?>
        </a>

    </div>

</header>


<!-- ==================================================
     DASHBOARD LAYOUT
================================================== -->

<main class="app-shell">


    <!-- ==================================================
         SIDEBAR
    ================================================== -->

    <aside class="sidebar">


        <div class="sidebar-nav">


            <a
                class="active"
                href="dashboard.php"
            >
                ⌂
                <span>
                    Home
                </span>
            </a>


            <a href="offers.php">
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


            <!-- Logout -->

            <a href="logout.php">
                ↪
                <span>
                    Log Out
                </span>
            </a>


        </div>


        <!-- Sidebar Balance -->

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


    <!-- ==================================================
         MAIN CONTENT
    ================================================== -->

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
                    Explore the latest earning opportunities
                    available to you.
                </p>

            </div>


            <a
                class="btn btn-primary"
                href="offers.php"
            >
                Find Offers →
            </a>


        </div>


        <!-- ==================================================
             STATISTICS
        ================================================== -->

        <div class="stats-row">


            <!-- Available Balance -->

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


            <!-- Pending -->

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


            <!-- Total Earned -->

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


        <!-- ==================================================
             WAYS TO EARN
        ================================================== -->

        <div class="content-heading">


            <h2>
                Ways to Earn
            </h2>


            <a href="offers.php">
                View all →
            </a>


        </div>


        <div class="earn-grid">


            <!-- Offers -->

            <a
                href="offers.php"
                class="earn-card"
            >

                <span>
                    ▤
                </span>

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


            <!-- Surveys -->

            <a
                href="offers.php"
                class="earn-card"
            >

                <span>
                    ◎
                </span>

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


            <!-- Referrals -->

            <a
                href="referrals.php"
                class="earn-card"
            >

                <span>
                    ♧
                </span>

                <h3>
                    Refer & Earn
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


</body>

</html>
