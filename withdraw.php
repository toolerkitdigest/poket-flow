<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (!isLoggedIn()) {
    redirect('login.php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    redirect('login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Security token
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['withdraw_csrf_token']) ||
    !is_string($_SESSION['withdraw_csrf_token'])
) {
    $_SESSION['withdraw_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['withdraw_csrf_token'];

/*
|--------------------------------------------------------------------------
| Load user
|--------------------------------------------------------------------------
*/

$userStatement = $pdo->prepare(
    'SELECT id, name, email, country, status
     FROM users
     WHERE id = ?
     LIMIT 1'
);

$userStatement->execute([$userId]);

$user = $userStatement->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParams = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    session_destroy();

    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Country and withdrawal methods
|--------------------------------------------------------------------------
*/

$userCountry = strtoupper(trim((string) ($user['country'] ?? '')));

$isNigeria = in_array(
    $userCountry,
    ['NG', 'NIGERIA'],
    true
);

$withdrawalMethods = [
    'PAYPAL' => [
        'name' => 'PayPal',
        'description' => 'Receive your withdrawal through PayPal.',
        'icon' => 'P',
        'type' => 'paypal',
    ],

    'USDT' => [
        'name' => 'USDT',
        'description' => 'Withdraw using a supported USDT network.',
        'icon' => '₮',
        'type' => 'crypto',
    ],

    'USDC' => [
        'name' => 'USDC',
        'description' => 'Withdraw using a supported USDC network.',
        'icon' => '$',
        'type' => 'crypto',
    ],
];

if ($isNigeria) {
    $withdrawalMethods = [
        'MONIEPOINT' => [
            'name' => 'Moniepoint',
            'description' => 'Receive your withdrawal into your Moniepoint account.',
            'icon' => 'M',
            'type' => 'bank',
        ],

        'PALMPAY' => [
            'name' => 'PalmPay',
            'description' => 'Receive your withdrawal into your PalmPay account.',
            'icon' => 'P',
            'type' => 'bank',
        ],
    ] + $withdrawalMethods;
}

/*
|--------------------------------------------------------------------------
| Supported cryptocurrency networks
|--------------------------------------------------------------------------
*/

$cryptoNetworks = [
    'USDT' => [
        'TRC20',
        'ERC20',
    ],

    'USDC' => [
        'ERC20',
        'POLYGON',
    ],
];

/*
|--------------------------------------------------------------------------
| Minimum withdrawal
|--------------------------------------------------------------------------
|
| Load this BEFORE handling the submitted form.
|
*/

$minimumWithdrawal = (float) getSetting(
    $pdo,
    'minimum_withdrawal',
    '5.00'
);

if ($minimumWithdrawal <= 0) {
    $minimumWithdrawal = 5.00;
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

$error = $_SESSION['withdraw_error'] ?? '';
$success = $_SESSION['withdraw_success'] ?? '';

unset(
    $_SESSION['withdraw_error'],
    $_SESSION['withdraw_success']
);

/*
|--------------------------------------------------------------------------
| Handle withdrawal submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        /*
        |--------------------------------------------------------------------------
        | Verify CSRF token
        |--------------------------------------------------------------------------
        */

        $submittedToken = (string) ($_POST['csrf_token'] ?? '');

        if (
            $submittedToken === '' ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new RuntimeException(
                'Your session has expired. Please try again.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate withdrawal method
        |--------------------------------------------------------------------------
        */

        $method = strtoupper(
            trim((string) ($_POST['method'] ?? ''))
        );

        if (!isset($withdrawalMethods[$method])) {
            throw new RuntimeException(
                'Please select a valid withdrawal method.'
            );
        }

        if (
            !$isNigeria &&
            in_array($method, ['MONIEPOINT', 'PALMPAY'], true)
        ) {
            throw new RuntimeException(
                'This withdrawal method is not available for your country.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate amount
        |--------------------------------------------------------------------------
        */

        $amountInput = trim((string) ($_POST['amount'] ?? ''));

        if (
            $amountInput === '' ||
            !is_numeric($amountInput)
        ) {
            throw new RuntimeException(
                'Please enter a valid withdrawal amount.'
            );
        }

        $amount = round((float) $amountInput, 2);

        if (!is_finite($amount) || $amount <= 0) {
            throw new RuntimeException(
                'The withdrawal amount must be greater than zero.'
            );
        }

        if ($amount < $minimumWithdrawal) {
            throw new RuntimeException(
                'The minimum withdrawal amount is $' .
                number_format($minimumWithdrawal, 2) . '.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate payment details
        |--------------------------------------------------------------------------
        */

        $methodNetwork = null;
        $paymentDetails = [];

        if (in_array($method, ['MONIEPOINT', 'PALMPAY'], true)) {
            $accountName = trim(
                (string) ($_POST['account_name'] ?? '')
            );

            $accountNumber = trim(
                (string) ($_POST['account_number'] ?? '')
            );

            if (
                mb_strlen($accountName) < 2 ||
                mb_strlen($accountName) > 100
            ) {
                throw new RuntimeException(
                    'Enter an account name between 2 and 100 characters.'
                );
            }

            if (!preg_match('/^[0-9]{10}$/', $accountNumber)) {
                throw new RuntimeException(
                    'Enter a valid 10-digit account number.'
                );
            }

            $paymentDetails = [
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'provider' => $method,
            ];
        } elseif ($method === 'PAYPAL') {
            $paypalEmail = trim(
                (string) ($_POST['paypal_email'] ?? '')
            );

            if (
                !filter_var(
                    $paypalEmail,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                throw new RuntimeException(
                    'Please enter a valid PayPal email address.'
                );
            }

            $paymentDetails = [
                'paypal_email' => $paypalEmail,
            ];
        } elseif (in_array($method, ['USDT', 'USDC'], true)) {
            $submittedNetwork = strtoupper(
                trim((string) ($_POST['crypto_network'] ?? ''))
            );

            /*
             * Network names are normalized to uppercase.
             * This also fixes the USDC Polygon capitalization issue.
             */
            $allowedNetworks = array_map(
                'strtoupper',
                $cryptoNetworks[$method]
            );

            if (!in_array($submittedNetwork, $allowedNetworks, true)) {
                throw new RuntimeException(
                    'Please select a supported cryptocurrency network.'
                );
            }

            $walletAddress = trim(
                (string) ($_POST['wallet_address'] ?? '')
            );

            $walletLength = strlen($walletAddress);

            if ($walletLength < 20 || $walletLength > 255) {
                throw new RuntimeException(
                    'Please enter a valid wallet address.'
                );
            }

            $methodNetwork = $submittedNetwork;

            $paymentDetails = [
                'currency' => $method,
                'network' => $submittedNetwork,
                'wallet_address' => $walletAddress,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare payment details
        |--------------------------------------------------------------------------
        */

        $paymentDetailsJson = json_encode(
            $paymentDetails,
            JSON_THROW_ON_ERROR
        );

        /*
        |--------------------------------------------------------------------------
        | Begin database transaction
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        /*
         * Lock the user's row to reduce the risk of simultaneous
         * withdrawal requests spending the same balance.
         */
        $lockUser = $pdo->prepare(
            'SELECT id, status
             FROM users
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );

        $lockUser->execute([$userId]);

        $lockedUser = $lockUser->fetch(PDO::FETCH_ASSOC);

        if (!$lockedUser) {
            throw new RuntimeException(
                'Your account could not be found.'
            );
        }

        if (
            strtoupper(trim((string) $lockedUser['status'])) !==
            'ACTIVE'
        ) {
            throw new RuntimeException(
                'Your account is not active. Please contact support.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent multiple outstanding withdrawals
        |--------------------------------------------------------------------------
        */

        $pendingStatement = $pdo->prepare(
            'SELECT id
             FROM withdrawals
             WHERE user_id = ?
               AND UPPER(status) IN ("PENDING", "PROCESSING")
             LIMIT 1'
        );

        $pendingStatement->execute([$userId]);

        if ($pendingStatement->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                'You already have a pending withdrawal. Please wait until it has been processed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate available balance
        |--------------------------------------------------------------------------
        |
        | Only completed wallet transactions affect this balance.
        | Withdrawals are recorded as negative transactions.
        |
        */

        $balanceStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM wallet_transactions
             WHERE user_id = ?
               AND status = "COMPLETED"'
        );

        $balanceStatement->execute([$userId]);

        $availableBalance = round(
            (float) $balanceStatement->fetchColumn(),
            2
        );

        if ($availableBalance < $minimumWithdrawal) {
            throw new RuntimeException(
                'Your available balance is below the minimum withdrawal amount of $' .
                number_format($minimumWithdrawal, 2) . '.'
            );
        }

        if ($amount > $availableBalance) {
            throw new RuntimeException(
                'Your withdrawal amount exceeds your available balance of $' .
                number_format($availableBalance, 2) . '.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create withdrawal request
        |--------------------------------------------------------------------------
        */

        $withdrawalStatement = $pdo->prepare(
            'INSERT INTO withdrawals
                (
                    user_id,
                    amount,
                    method,
                    method_network,
                    payment_details,
                    status
                )
             VALUES (?, ?, ?, ?, ?, "PENDING")'
        );

        $withdrawalStatement->execute([
            $userId,
            $amount,
            $method,
            $methodNetwork,
            $paymentDetailsJson,
        ]);

        $withdrawalId = (int) $pdo->lastInsertId();

        /*
        |--------------------------------------------------------------------------
        | Record the balance deduction
        |--------------------------------------------------------------------------
        |
        | The request remains PENDING in withdrawals.
        | This completed negative ledger entry reserves the funds
        | and prevents the user from spending them again.
        |
        */

        $balanceAfter = round(
            $availableBalance - $amount,
            2
        );

        $transactionReference = 'WD-' . $withdrawalId;

        $walletStatement = $pdo->prepare(
            'INSERT INTO wallet_transactions
                (
                    user_id,
                    amount,
                    type,
                    reference_type,
                    reference_id,
                    currency,
                    balance_before,
                    balance_after,
                    status
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $walletStatement->execute([
            $userId,
            -$amount,
            'WITHDRAWAL',
            'withdrawal',
            $withdrawalId,
            'USD',
            $availableBalance,
            $balanceAfter,
            'COMPLETED',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $pdo->commit();

        /*
         * Rotate the CSRF token after successful submission.
         */
        $_SESSION['withdraw_csrf_token'] = bin2hex(
            random_bytes(32)
        );

        $_SESSION['withdraw_success'] =
            'Your withdrawal request for $' .
            number_format($amount, 2) .
            ' has been submitted successfully. Reference: ' .
            $transactionReference . '.';

        header('Location: withdraw.php');
        exit;
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['withdraw_error'] = $e->getMessage();

        header('Location: withdraw.php');
        exit;
    } catch (JsonException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'PoketFlow withdrawal JSON error: ' . $e->getMessage()
        );

        $_SESSION['withdraw_error'] =
            'We could not process your payment details. Please try again.';

        header('Location: withdraw.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'PoketFlow withdrawal error: ' . $e->getMessage()
        );

        $_SESSION['withdraw_error'] =
            'Something went wrong while submitting your withdrawal. Please try again later.';

        header('Location: withdraw.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Current balance for page display
|--------------------------------------------------------------------------
*/

$availableBalance = round(
    (float) getUserBalance($pdo, $userId),
    2
);

$canWithdraw = $availableBalance >= $minimumWithdrawal;

/*
|--------------------------------------------------------------------------
| Output escaping helper
|--------------------------------------------------------------------------
*/

if (!function_exists('withdrawEscape')) {
    function withdrawEscape(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
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

    <meta
        name="theme-color"
        content="#080d1a"
    >

    <title>Withdraw — PoketFlow</title>

    <link
        rel="stylesheet"
        href="css/withdraw.css?v=2"
    >
</head>

<body class="withdraw-page">

<header class="withdraw-header">

    <a
        href="index.php"
        class="withdraw-brand"
        aria-label="PoketFlow homepage"
    >
        <span class="withdraw-brand-mark">P</span>

        <span class="withdraw-brand-text">
            Poket<span>Flow</span>
        </span>
    </a>

    <nav
        class="withdraw-top-nav"
        aria-label="Main navigation"
    >
        <a href="dashboard.php">Dashboard</a>
        <a href="offers.php">Offers</a>
        <a href="history.php">History</a>
        <a href="referrals.php">Referrals</a>
        <a
            href="withdraw.php"
            class="active"
            aria-current="page"
        >
            Withdraw
        </a>
    </nav>

    <a
        href="logout.php"
        class="withdraw-logout"
    >
        Log out
    </a>

</header>

<main class="withdraw-layout">

    <aside class="withdraw-sidebar">

        <div class="withdraw-sidebar-label">
            WORKSPACE
        </div>

        <nav
            class="withdraw-sidebar-nav"
            aria-label="Dashboard navigation"
        >
            <a href="dashboard.php">
                <span class="withdraw-nav-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a href="offers.php">
                <span class="withdraw-nav-icon">◈</span>
                <span>Available Offers</span>
            </a>

            <a href="history.php">
                <span class="withdraw-nav-icon">↻</span>
                <span>Transaction History</span>
            </a>

            <a href="referrals.php">
                <span class="withdraw-nav-icon">♧</span>
                <span>Referrals</span>
            </a>

            <a
                href="withdraw.php"
                class="active"
                aria-current="page"
            >
                <span class="withdraw-nav-icon">↗</span>
                <span>Withdraw Funds</span>
            </a>
        </nav>

        <div class="withdraw-sidebar-card">

            <div class="withdraw-sidebar-card-icon">
                ✓
            </div>

            <h3>Secure withdrawals</h3>

            <p>
                Always double-check your payment details before
                submitting a request.
            </p>

        </div>

    </aside>

    <section class="withdraw-content">

        <div class="withdraw-heading">

            <div>
                <div class="withdraw-kicker">
                    YOUR MONEY, YOUR CONTROL
                </div>

                <h1>Withdraw funds</h1>

                <p class="withdraw-lead">
                    Request a withdrawal using one of the payment
                    methods available for your account.
                </p>
            </div>

        </div>

        <?php if ($error !== ''): ?>

            <div
                class="withdraw-message withdraw-message-error"
                role="alert"
            >
                <span class="withdraw-message-icon">!</span>

                <div>
                    <strong>Withdrawal not submitted</strong>

                    <p>
                        <?= withdrawEscape($error) ?>
                    </p>
                </div>
            </div>

        <?php endif; ?>

        <?php if ($success !== ''): ?>

            <div
                class="withdraw-message withdraw-message-success"
                role="status"
            >
                <span class="withdraw-message-icon">✓</span>

                <div>
                    <strong>Request submitted</strong>

                    <p>
                        <?= withdrawEscape($success) ?>
                    </p>
                </div>
            </div>

        <?php endif; ?>

        <!-- Available balance -->

        <section class="withdraw-balance-card">

            <div class="withdraw-balance-main">

                <div class="withdraw-balance-label">
                    AVAILABLE BALANCE
                </div>

                <div class="withdraw-balance-amount">
                    $<?= number_format($availableBalance, 2) ?>
                </div>

                <p class="withdraw-balance-description">
                    Minimum withdrawal:
                    <strong>
                        $<?= number_format($minimumWithdrawal, 2) ?>
                    </strong>
                </p>

            </div>

            <div class="withdraw-balance-icon" aria-hidden="true">
                $
            </div>

        </section>

        <div class="withdraw-columns">

            <!-- Withdrawal form -->

            <section class="withdraw-form-card">

                <div class="withdraw-card-heading">

                    <div>
                        <h2>Request a withdrawal</h2>

                        <p>
                            Complete the form below to continue.
                        </p>
                    </div>

                    <span class="withdraw-step-badge">
                        STEP 1
                    </span>

                </div>

                <?php if (!$canWithdraw): ?>

                    <div class="withdraw-message withdraw-message-warning">

                        <span class="withdraw-message-icon">i</span>

                        <div>
                            <strong>Not enough available funds</strong>

                            <p>
                                You need at least
                                $<?= number_format($minimumWithdrawal, 2) ?>
                                to request a withdrawal.
                            </p>
                        </div>

                    </div>

                <?php endif; ?>

                <form
                    method="POST"
                    action="withdraw.php"
                    class="withdraw-form"
                    id="withdrawForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= withdrawEscape($csrfToken) ?>"
                    >

                    <div class="withdraw-field">

                        <label for="amount">
                            Withdrawal amount
                        </label>

                        <div class="withdraw-amount-input">

                            <span class="withdraw-currency">
                                USD $
                            </span>

                            <input
                                type="number"
                                id="amount"
                                name="amount"
                                min="<?= withdrawEscape(number_format($minimumWithdrawal, 2, '.', '')) ?>"
                                max="<?= withdrawEscape(number_format($availableBalance, 2, '.', '')) ?>"
                                step="0.01"
                                placeholder="0.00"
                                required
                                <?= !$canWithdraw ? 'disabled' : '' ?>
                            >

                        </div>

                        <div class="withdraw-field-help">
                            Minimum $<?= number_format($minimumWithdrawal, 2) ?>.
                            Maximum $<?= number_format($availableBalance, 2) ?>.
                        </div>

                    </div>

                    <div class="withdraw-field">

                        <label for="method">
                            Payment method
                        </label>

                        <select
                            id="method"
                            name="method"
                            required
                            <?= !$canWithdraw ? 'disabled' : '' ?>
                        >

                            <option value="">
                                Select a payment method
                            </option>

                            <?php foreach ($withdrawalMethods as $methodCode => $methodInfo): ?>

                                <option
                                    value="<?= withdrawEscape($methodCode) ?>"
                                    data-type="<?= withdrawEscape($methodInfo['type']) ?>"
                                >
                                    <?= withdrawEscape($methodInfo['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Bank payment details -->

                    <div
                        class="withdraw-payment-fields withdraw-hidden"
                        id="bankFields"
                        hidden
                    >

                        <div class="withdraw-details-heading">
                            <span class="withdraw-details-icon">▤</span>

                            <div>
                                <h3>Bank account details</h3>

                                <p>
                                    Enter the account that should receive your funds.
                                </p>
                            </div>
                        </div>

                        <div class="withdraw-field">

                            <label for="account_name">
                                Account holder name
                            </label>

                            <input
                                type="text"
                                id="account_name"
                                name="account_name"
                                minlength="2"
                                maxlength="100"
                                placeholder="Full name on the account"
                                autocomplete="name"
                                disabled
                            >

                        </div>

                        <div class="withdraw-field">

                            <label for="account_number">
                                Account number
                            </label>

                            <input
                                type="text"
                                id="account_number"
                                name="account_number"
                                inputmode="numeric"
                                pattern="[0-9]{10}"
                                minlength="10"
                                maxlength="10"
                                placeholder="Enter 10-digit account number"
                                autocomplete="off"
                                disabled
                            >

                            <div class="withdraw-field-help">
                                Enter exactly 10 digits.
                            </div>

                        </div>

                    </div>

                    <!-- PayPal payment details -->

                    <div
                        class="withdraw-payment-fields withdraw-hidden"
                        id="paypalFields"
                        hidden
                    >

                        <div class="withdraw-details-heading">
                            <span class="withdraw-details-icon">P</span>

                            <div>
                                <h3>PayPal details</h3>

                                <p>
                                    Enter the email address associated with your PayPal account.
                                </p>
                            </div>
                        </div>

                        <div class="withdraw-field">

                            <label for="paypal_email">
                                PayPal email address
                            </label>

                            <input
                                type="email"
                                id="paypal_email"
                                name="paypal_email"
                                maxlength="190"
                                placeholder="you@example.com"
                                autocomplete="email"
                                disabled
                            >

                        </div>

                    </div>

                    <!-- Cryptocurrency payment details -->

                    <div
                        class="withdraw-payment-fields withdraw-hidden"
                        id="cryptoFields"
                        hidden
                    >

                        <div class="withdraw-details-heading">
                            <span class="withdraw-details-icon">₮</span>

                            <div>
                                <h3>Crypto wallet details</h3>

                                <p>
                                    Select the correct network for your wallet.
                                </p>
                            </div>
                        </div>

                        <div class="withdraw-field">

                            <label for="crypto_network">
                                Network
                            </label>

                            <select
                                id="crypto_network"
                                name="crypto_network"
                                disabled
                            >
                                <option value="">
                                    Select a network
                                </option>
                            </select>

                            <div class="withdraw-field-help">
                                Your selected network must match the receiving wallet.
                            </div>

                        </div>

                        <div class="withdraw-field">

                            <label for="wallet_address">
                                Wallet address
                            </label>

                            <textarea
                                id="wallet_address"
                                name="wallet_address"
                                rows="3"
                                minlength="20"
                                maxlength="255"
                                placeholder="Enter your receiving wallet address"
                                autocomplete="off"
                                disabled
                            ></textarea>

                        </div>

                    </div>

                    <div class="withdraw-notice">

                        <span class="withdraw-notice-icon">i</span>

                        <p>
                            Check your payment information carefully.
                            Incorrect payment details may delay or prevent
                            successful delivery of your withdrawal.
                        </p>

                    </div>

                    <button
                        type="submit"
                        class="withdraw-submit"
                        id="withdrawSubmit"
                        <?= !$canWithdraw ? 'disabled' : '' ?>
                    >

                        <span>Submit withdrawal request</span>

                        <span class="withdraw-submit-arrow">→</span>

                    </button>

                    <p class="withdraw-form-footnote">
                        Your request will be marked as pending until
                        it is processed.
                    </p>

                </form>

            </section>

            <!-- Withdrawal information -->

            <aside class="withdraw-info-column">

                <section class="withdraw-info-card">

                    <div class="withdraw-info-icon">
                        ✓
                    </div>

                    <h2>How withdrawals work</h2>

                    <ol class="withdraw-steps">

                        <li>
                            <span class="withdraw-step-number">1</span>

                            <div>
                                <strong>Submit your request</strong>

                                <p>
                                    Choose a payment method and provide
                                    accurate payment details.
                                </p>
                            </div>
                        </li>

                        <li>
                            <span class="withdraw-step-number">2</span>

                            <div>
                                <strong>Request review</strong>

                                <p>
                                    Your withdrawal appears as pending
                                    while it awaits processing.
                                </p>
                            </div>
                        </li>

                        <li>
                            <span class="withdraw-step-number">3</span>

                            <div>
                                <strong>Receive your funds</strong>

                                <p>
                                    Once approved and processed, your
                                    payment is sent using the selected method.
                                </p>
                            </div>
                        </li>

                    </ol>

                </section>

                <section class="withdraw-security-card">

                    <div class="withdraw-security-icon">
                        ◈
                    </div>

                    <div>
                        <h3>Keep your funds secure</h3>

                        <p>
                            Never share your account password or
                            verification codes with anyone claiming
                            to process your withdrawal.
                        </p>
                    </div>

                </section>

                <section class="withdraw-support-card">

                    <h3>Need help?</h3>

                    <p>
                        If you have questions about a withdrawal,
                        check your transaction history or contact
                        the PoketFlow support team.
                    </p>

                    <a href="history.php">
                        View transaction history →
                    </a>

                </section>

            </aside>

        </div>

        <footer class="withdraw-footer">
            <span>© <?= date('Y') ?> PoketFlow</span>
            <span>Rewards made simple.</span>
        </footer>

    </section>

</main>

<script>
(function () {
    'use strict';

    const methodSelect = document.getElementById('method');
    const form = document.getElementById('withdrawForm');
    const submitButton = document.getElementById('withdrawSubmit');

    const bankFields = document.getElementById('bankFields');
    const paypalFields = document.getElementById('paypalFields');
    const cryptoFields = document.getElementById('cryptoFields');

    const accountName = document.getElementById('account_name');
    const accountNumber = document.getElementById('account_number');
    const paypalEmail = document.getElementById('paypal_email');
    const cryptoNetwork = document.getElementById('crypto_network');
    const walletAddress = document.getElementById('wallet_address');

    const paymentGroups = [
        bankFields,
        paypalFields,
        cryptoFields
    ];

    /*
     * Supported networks must match the server-side allowlist.
     */
    const cryptoNetworkOptions = {
        USDT: ['TRC20', 'ERC20'],
        USDC: ['ERC20', 'POLYGON']
    };

    function setGroupEnabled(group, enabled) {
        if (!group) {
            return;
        }

        group.hidden = !enabled;
        group.classList.toggle('withdraw-hidden', !enabled);

        group.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.disabled = !enabled;

            if (field.dataset.wasRequired === 'true') {
                field.required = enabled;
            }
        });
    }

    function resetNetworkOptions() {
        cryptoNetwork.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select a network';

        cryptoNetwork.appendChild(placeholder);
    }

    function populateNetworkOptions(method) {
        resetNetworkOptions();

        const networks = cryptoNetworkOptions[method] || [];

        networks.forEach(function (network) {
            const option = document.createElement('option');

            option.value = network;
            option.textContent = network;

            cryptoNetwork.appendChild(option);
        });
    }

    function updatePaymentFields() {
        const selectedOption =
            methodSelect.options[methodSelect.selectedIndex];

        const selectedMethod = methodSelect.value;

        const selectedType = selectedOption
            ? selectedOption.dataset.type
            : '';

        paymentGroups.forEach(function (group) {
            setGroupEnabled(group, false);
        });

        if (selectedType === 'bank') {
            setGroupEnabled(bankFields, true);
        } else if (selectedType === 'paypal') {
            setGroupEnabled(paypalFields, true);
        } else if (selectedType === 'crypto') {
            populateNetworkOptions(selectedMethod);
            setGroupEnabled(cryptoFields, true);
        }
    }

    /*
     * Define required fields for each payment method.
     * Disabled hidden inputs will not block form submission.
     */
    accountName.required = true;
    accountName.dataset.wasRequired = 'true';

    accountNumber.required = true;
    accountNumber.dataset.wasRequired = 'true';

    paypalEmail.required = true;
    paypalEmail.dataset.wasRequired = 'true';

    cryptoNetwork.required = true;
    cryptoNetwork.dataset.wasRequired = 'true';

    walletAddress.required = true;
    walletAddress.dataset.wasRequired = 'true';

    methodSelect.addEventListener('change', updatePaymentFields);

    form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        if (submitButton.disabled) {
            event.preventDefault();
            return;
        }

        submitButton.disabled = true;
        submitButton.classList.add('is-submitting');

        submitButton.querySelector('span:first-child').textContent =
            'Submitting request...';
    });

    updatePaymentFields();
})();
</script>

</body>
</html>
