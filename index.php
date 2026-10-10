<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>PoketFlow — Turn Your Free Time Into Rewards</title>

    <meta
        name="description"
        content="Discover offers and activities, complete requirements, earn rewards, and cash out when you reach the minimum balance.">

    
    
    
    <link rel="stylesheet" href="css/index.css?v2">
    <link
    rel="stylesheet"
    href="assets/featured-offers.css?v=<?= filemtime(__DIR__ . '/assets/featured-offers.css') ?>">

    


</head>

<body>

<!-- ==================================================
     HEADER
================================================== -->

<header class="site-header">

    <a class="brand" href="index.php">

        <span class="brand-mark">P</span>

        <span>
            Poket<span>Flow</span>
        </span>

    </a>

    <nav class="desktop-nav">

        <a class="active" href="index.php">
            Home
        </a>

        <a href="offers.php">
            Earn
        </a>

        <a href="#how-it-works">
            How It Works
        </a>

        <a href="#featured-offers">
            Rewards
        </a>

        <a href="#faq">
            FAQ
        </a>

    </nav>

    <div class="header-actions">

        <a class="btn btn-ghost" href="login.php">
            Sign In
        </a>

        <a class="btn btn-primary" href="register.php">
            Get Started
            <span>→</span>
        </a>

    </div>

</header>


<!-- ==================================================
     MAIN
================================================== -->

<main>


<!-- ==================================================
     HERO
================================================== -->

<section class="hero">

    <div class="hero-copy">

        <h1>
            Turn Your Free Time Into
            <span>Rewards</span>
        </h1>

        <p>
            Discover available offers, surveys and online activities.
            Complete the requirements, earn rewards, and cash out
            when you reach the minimum balance.
        </p>

        <!-- ==================================================
             FEATURED OFFERS

             Display immediately after the hero description.
             The existing component handles offer retrieval
             and reward calculations.
        ================================================== -->

        <?php require_once __DIR__ . '/includes/featured-offers.php'; ?>

        <div class="hero-actions">

            <a
                class="btn btn-primary btn-large"
                href="register.php"
            >
                Start Earning
                <span>→</span>
            </a>

            <a
                class="btn btn-outline btn-large"
                href="#how-it-works"
            >
                How It Works
            </a>

        </div>

        <div class="hero-note">
            Free to join · No subscription required
        </div>

    </div>

</section>


<!-- ==================================================
     TRUST STRIP
================================================== -->

<section class="trust-strip">

    <div>

        <span class="icon">♙</span>

        <p>

            <b>Free to join</b>

            <small>Create your account in seconds</small>

        </p>

    </div>

    <div>

        <span class="icon">♢</span>

        <p>

            <b>Multiple ways to earn</b>

            <small>Offers, surveys & more</small>

        </p>

    </div>

    <div>

        <span class="icon">✓</span>

        <p>

            <b>Secure & reliable</b>

            <small>Your account stays protected</small>

        </p>

    </div>

    <div>

        <span class="icon">ϟ</span>

        <p>

            <b>Fast rewards</b>

            <small>Cash out when eligible</small>

        </p>

    </div>

</section>


<!-- ==================================================
     HOW IT WORKS
================================================== -->

<section class="section" id="how-it-works">

    <div class="section-heading">

        <div>

            <span class="kicker">
                HOW IT WORKS
            </span>

            <h2>
                It’s Simple to Get Started
            </h2>

        </div>

        <a href="offers.php">
            Explore offers →
        </a>

    </div>

    <div class="steps">

        <div class="step">

            <b>01</b>

            <span>♙</span>

            <h3>Create Account</h3>

            <p>Sign up for free in seconds.</p>

        </div>

        <div class="step">

            <b>02</b>

            <span>◎</span>

            <h3>Find Offers</h3>

            <p>Browse available activities.</p>

        </div>

        <div class="step">

            <b>03</b>

            <span>✓</span>

            <h3>Complete</h3>

            <p>Follow the offer requirements.</p>

        </div>

        <div class="step">

            <b>04</b>

            <span>◈</span>

            <h3>Earn Rewards</h3>

            <p>Your balance updates after approval.</p>

        </div>

        <div class="step">

            <b>05</b>

            <span>▣</span>

            <h3>Cash Out</h3>

            <p>Request rewards when eligible.</p>

        </div>

    </div>

</section>


<!-- ==================================================
     CTA
================================================== -->

<section class="cta-section">

    <div>

        <span class="kicker">
            READY WHEN YOU ARE
        </span>

        <h2>
            Start discovering available rewards.
        </h2>

        <p>
            Create your PoketFlow account and explore
            the earning opportunities available to you.
        </p>

    </div>

    <a
        class="btn btn-primary btn-large"
        href="register.php"
    >
        Get Started →
    </a>

</section>


<!-- ==================================================
     FAQ
================================================== -->

<section class="section faq" id="faq">

    <div class="section-heading">

        <div>

            <span class="kicker">FAQ</span>

            <h2>Common Questions</h2>

        </div>

    </div>

    <details>

        <summary>
            Is PoketFlow free to join?
        </summary>

        <p>
            Yes. Creating an account does not require
            a subscription.
        </p>

    </details>

    <details>

        <summary>
            How do rewards work?
        </summary>

        <p>
            Available offers show their requirements and
            reward information. Follow the listed requirements
            and wait for completion approval where applicable.
        </p>

    </details>

    <details>

        <summary>
            When can I cash out?
        </summary>

        <p>
            Cash-out eligibility depends on your available
            balance, the site's minimum cashout, and any
            applicable payment or verification requirements.
        </p>

    </details>

</section>

</main>


<!-- ==================================================
     FOOTER
================================================== -->

<footer class="footer">

    <div class="footer-brand">

        <a class="brand" href="index.php">

            <span class="brand-mark">P</span>

            <span>
                Poket<span>Flow</span>
            </span>

        </a>

        <p>
            A modern rewards discovery platform.
        </p>

    </div>

    <div class="footer-links">

        <a href="#faq">FAQ</a>

        <a href="#">Terms</a>

        <a href="#">Privacy</a>

        <a href="#">Contact</a>

    </div>

    <small>
        © 2026 PoketFlow. All rights reserved.
    </small>

</footer>

</body>
</html>
