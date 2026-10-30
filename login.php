<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    error_log('[PoketFlow Login] POST request received.');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {

        $stmt = $pdo->prepare(
            'SELECT id, password_hash, status
             FROM users
             WHERE email = ?
             LIMIT 1'
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        error_log(
    '[PoketFlow Login] User lookup: ' .
    ($user ? 'FOUND' : 'NOT FOUND')
);

        if (!$user || !password_verify($password, $user['password_hash'])) {

            $errors[] = 'Invalid email address or password.';

        } elseif ($user['status'] !== 'ACTIVE') {

            $errors[] = 'Your account is not currently active.';

        } else {
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
redirect('dashboard.php');
Replace it temporarily with:
$sessionResult = session_regenerate_id(true);

error_log(
    '[PoketFlow Login] Session regeneration: ' .
    ($sessionResult ? 'SUCCESS' : 'FAILED')
);

$_SESSION['user_id'] = (int) $user['id'];

error_log(
    '[PoketFlow Login] User ID stored in session: ' .
    $_SESSION['user_id']
);

redirect('dashboard.php');
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Log In - PoketFlow</title>

    <link
        rel="stylesheet"
        href="assets/login.css"
    >

</head>

<body>

<header class="site-header">

    <!-- Brand -->

    <a href="index.html" class="brand">

        <span class="brand-mark">P</span>

        <span>
            Poket<span>Flow</span>
        </span>

    </a>


    <!-- Desktop Navigation -->

    <nav class="desktop-nav">

        <a href="index.html">
            Home
        </a>

        <a href="offers.html">
            Earn
        </a>

    </nav>


    <!-- Desktop Actions -->

    <div class="header-actions">

        <a
            href="register.php"
            class="btn btn-ghost"
        >
            Create Account
        </a>

    </div>


    <!-- Mobile Hamburger -->

    <button
        type="button"
        class="mobile-menu-toggle"
        id="mobileMenuToggle"
        aria-label="Open navigation menu"
        aria-expanded="false"
    >

        <span></span>
        <span></span>
        <span></span>

    </button>


    <!-- Mobile Navigation -->

    <nav
        class="mobile-nav"
        id="mobileNav"
        aria-hidden="true"
    >

        <a href="index.html">
            Home
        </a>

        <a href="offers.html">
            Earn
        </a>

        <a
            href="register.php"
            class="mobile-nav-login"
        >
            Create Account
        </a>

    </nav>

</header>


<main class="auth-page">

    <div class="auth-container">

        <div class="auth-card">

            <div class="auth-heading">

                <h1>
                    Welcome Back
                </h1>

                <p>
                    Log in to your PoketFlow account and continue earning.
                </p>

            </div>


            <?php if ($errors): ?>

                <div class="auth-error">

                    <?php foreach ($errors as $error): ?>

                        <p><?= e($error) ?></p>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>


            <form
                method="POST"
                action=""
                class="auth-form"
            >

                <div class="auth-field">

                    <label for="email">
                        Email Address
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        autocomplete="email"
                        required
                    >

                </div>


                <div class="auth-field">

                    <div class="password-label-row">

                        <label for="password">
                            Password
                        </label>

                        <a href="#" class="forgot-password">
                            Forgot password?
                        </a>

                    </div>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn btn-primary btn-large auth-submit"
                >
                    Log In
                    <span>→</span>
                </button>

            </form>


            <div class="auth-footer">

                Don't have an account?

                <a href="register.php">
                    Create one
                </a>

            </div>


            <div class="auth-note">

                By logging in, you agree to use PoketFlow
                responsibly and follow our platform rules.

            </div>

        </div>

    </div>

</main>


<script>

    const mobileMenuToggle =
        document.getElementById('mobileMenuToggle');

    const mobileNav =
        document.getElementById('mobileNav');


    mobileMenuToggle.addEventListener('click', function () {

        const isOpen =
            mobileNav.classList.toggle('open');

        mobileMenuToggle.classList.toggle(
            'active',
            isOpen
        );

        mobileMenuToggle.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );

        mobileMenuToggle.setAttribute(
            'aria-label',
            isOpen
                ? 'Close navigation menu'
                : 'Open navigation menu'
        );

        mobileNav.setAttribute(
            'aria-hidden',
            isOpen ? 'false' : 'true'
        );

    });


    mobileNav.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', function () {

            mobileNav.classList.remove('open');

            mobileMenuToggle.classList.remove('active');

            mobileMenuToggle.setAttribute(
                'aria-expanded',
                'false'
            );

            mobileMenuToggle.setAttribute(
                'aria-label',
                'Open navigation menu'
            );

            mobileNav.setAttribute(
                'aria-hidden',
                'true'
            );

        });

    });

</script>

</body>
</html>
