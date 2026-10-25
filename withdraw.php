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

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['withdraw_csrf_token'])) {
    $_SESSION['withdraw_csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['withdraw_csrf_token'];

/*
|--------------------------------------------------------------------------
| User
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        id,
        name,
        email,
        country,
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
    exit;
}

/*
|--------------------------------------------------------------------------
| Country
|--------------------------------------------------------------------------
*/

$userCountry = strtoupper(
    trim((string) ($user['country'] ?? ''))
);

$isNigeria = in_array(
    $userCountry,
    ['NG', 'NIGERIA'],
    true
);

/*
|--------------------------------------------------------------------------
| Withdrawal methods
|--------------------------------------------------------------------------
*/

$withdrawalMethods = [];

if ($isNigeria) {
    $withdrawalMethods[] = [
        'id' => 'MONIEPOINT',
        'name' => 'Moniepoint',
        'description' => 'Receive your reward through your Nigerian account.',
        'icon' => '🇳🇬',
        'type' => 'bank',
    ];

    $withdrawalMethods[] = [
        'id' => 'PALMPAY',
        'name' => 'PalmPay',
        'description' => 'Receive your reward through your Nigerian account.',
        'icon' => '🇳🇬',
        'type' => 'bank',
    ];
}

$withdrawalMethods[] = [
    'id' => 'PAYPAL',
    'name' => 'PayPal',
    'description' => 'Receive your reward through your PayPal account.',
    'icon' => '💳',
    'type' => 'paypal',
];

$withdrawalMethods[] = [
    'id' => 'USDT',
    'name' => 'USDT',
    'description' => 'Receive your reward in Tether.',
    'icon' => '₮',
    'type' => 'crypto',
];

$withdrawalMethods[] = [
    'id' => 'USDC',
    'name' => 'USDC',
    'description' => 'Receive your reward in USD Coin.',
    'icon' => '💵',
    'type' => 'crypto',
];

/*
|--------------------------------------------------------------------------
| Supported crypto networks
|--------------------------------------------------------------------------
*/

$cryptoNetworks = [
    'USDT' => [
        'TRC20',
        'ERC20',
    ],

    'USDC' => [
        'ERC20',
        'Polygon',
    ],
];

/*
|--------------------------------------------------------------------------
| Minimum withdrawal
|--------------------------------------------------------------------------
|
| This must be loaded before POST validation.
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

$error = $_SESSION['withdraw_error'] ?? null;
$success = $_SESSION['withdraw_success'] ?? null;

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
        | CSRF validation
        |--------------------------------------------------------------------------
        */

        $postedToken = (string) (
            $_POST['csrf_token'] ?? ''
        );

        if (
            $postedToken === '' ||
            !hash_equals($csrfToken, $postedToken)
        ) {
            throw new RuntimeException(
                'Your session has expired. Please refresh the page and try again.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Selected withdrawal method
        |--------------------------------------------------------------------------
        */

        $method = strtoupper(
            trim(
                (string) (
                    $_POST['withdrawal_method'] ?? ''
                )
            )
        );

        $allowedMethods = [
            'MONIEPOINT',
            'PALMPAY',
            'PAYPAL',
            'USDT',
            'USDC',
        ];

        if (!in_array($method, $allowedMethods, true)) {
            throw new RuntimeException(
                'Please select a valid withdrawal method.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Nigeria-only methods
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $method,
                ['MONIEPOINT', 'PALMPAY'],
                true
            ) &&
            !$isNigeria
        ) {
            throw new RuntimeException(
                'This withdrawal method is only available to users in Nigeria.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Amount validation
        |--------------------------------------------------------------------------
        */

        $amountInput = trim(
            (string) (
                $_POST['withdrawal_amount'] ?? ''
            )
        );

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
                'Withdrawal amount must be greater than zero.'
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
        | Payment details
        |--------------------------------------------------------------------------
        */

        $paymentDetails = [];
        $methodNetwork = null;

        if (
            $method === 'MONIEPOINT' ||
            $method === 'PALMPAY'
        ) {
            $accountName = trim(
                (string) (
                    $_POST['account_name'] ?? ''
                )
            );

            $accountNumber = trim(
                (string) (
                    $_POST['account_number'] ?? ''
                )
            );

            if (
                $accountName === '' ||
                $accountNumber === ''
            ) {
                throw new RuntimeException(
                    'Please provide your account name and account number.'
                );
            }

            if (!preg_match('/^\d{10}$/', $accountNumber)) {
                throw new RuntimeException(
                    'Please enter a valid 10-digit account number.'
                );
            }

            if (
                mb_strlen($accountName) < 2 ||
                mb_strlen($accountName) > 100
            ) {
                throw new RuntimeException(
                    'Please enter a valid account name.'
                );
            }

            $paymentDetails = [
                'account_name' => $accountName,
                'account_number' => $accountNumber,
            ];
        } elseif ($method === 'PAYPAL') {
            $paypalEmail = strtolower(
                trim(
                    (string) (
                        $_POST['paypal_email'] ?? ''
                    )
                )
            );

            if (
                $paypalEmail === '' ||
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
        } elseif (
            $method === 'USDT' ||
            $method === 'USDC'
        ) {
            $submittedNetwork = trim(
                (string) (
                    $_POST['crypto_network'] ?? ''
                )
            );

            $walletAddress = trim(
                (string) (
                    $_POST['wallet_address'] ?? ''
                )
            );

            /*
             * Compare networks without case sensitivity.
             * This allows Polygon to validate correctly.
             */
            $allowedNetworks = $cryptoNetworks[$method] ?? [];

            $matchedNetwork = null;

            foreach ($allowedNetworks as $network) {
                if (
                    strtoupper($submittedNetwork) ===
                    strtoupper($network)
                ) {
                    $matchedNetwork = $network;
                    break;
                }
            }

            if ($matchedNetwork === null) {
                throw new RuntimeException(
                    'Please select a valid cryptocurrency network.'
                );
            }

            $methodNetwork = $matchedNetwork;

            if (
                $walletAddress === '' ||
                mb_strlen($walletAddress) < 20 ||
                mb_strlen($walletAddress) > 255
            ) {
                throw new RuntimeException(
                    'Please enter a valid wallet address.'
                );
            }

            $paymentDetails = [
                'wallet_address' => $walletAddress,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Begin atomic wallet + withdrawal transaction
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        /*
         * Lock the user row.
         */

        $lockUser = $pdo->prepare(
            'SELECT
                id,
                status
             FROM users
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );

        $lockUser->execute([$userId]);

        $lockedUser = $lockUser->fetch();

        if (!$lockedUser) {
            throw new RuntimeException(
                'User account could not be found.'
            );
        }

        if (
            strtoupper(
                trim(
                    (string) (
                        $lockedUser['status'] ?? ''
                    )
                )
            ) !== 'ACTIVE'
        ) {
            throw new RuntimeException(
                'Your account is not currently eligible for withdrawals.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent multiple active withdrawal requests
        |--------------------------------------------------------------------------
        */

        $activeWithdrawalStmt = $pdo->prepare(
            'SELECT id
             FROM withdrawals
             WHERE user_id = ?
               AND status IN ("PENDING", "PROCESSING")
             LIMIT 1
             FOR UPDATE'
        );

        $activeWithdrawalStmt->execute([$userId]);

        $activeWithdrawal = $activeWithdrawalStmt->fetchColumn();

        if ($activeWithdrawal !== false) {
            throw new RuntimeException(
                'You already have a withdrawal request being processed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Recalculate available wallet balance
        |--------------------------------------------------------------------------
        */

        $balanceStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(amount), 0)
             FROM wallet_transactions
             WHERE user_id = ?
               AND status = "COMPLETED"'
        );

        $balanceStmt->execute([$userId]);

        $availableBalance = round(
            (float) $balanceStmt->fetchColumn(),
            2
        );

        if ($availableBalance < $minimumWithdrawal) {
            throw new RuntimeException(
                'You have not reached the minimum withdrawal amount of $' .
                number_format($minimumWithdrawal, 2) . '.'
            );
        }

        if ($amount > $availableBalance) {
            throw new RuntimeException(
                'The withdrawal amount is greater than your available balance.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create withdrawal request
        |--------------------------------------------------------------------------
        */

        $insertWithdrawal = $pdo->prepare(
            'INSERT INTO withdrawals (
                user_id,
                amount,
                method,
                method_network,
                payment_details,
                status
            )
            VALUES (?, ?, ?, ?, ?, "PENDING")'
        );

        $insertWithdrawal->execute([
            $userId,
            $amount,
            $method,
            $methodNetwork,
            json_encode(
                $paymentDetails,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            ),
        ]);

        $withdrawalId = (int) $pdo->lastInsertId();

        /*
        |--------------------------------------------------------------------------
        | Reserve/deduct the requested amount
        |--------------------------------------------------------------------------
        */

        $balanceBefore = $availableBalance;

        $balanceAfter = round(
            $balanceBefore - $amount,
            2
        );

        $insertWalletTransaction = $pdo->prepare(
            'INSERT INTO wallet_transactions (
                user_id,
                type,
                reference_type,
                reference_id,
                amount,
                currency,
                balance_before,
                balance_after,
                status,
                description
            )
            VALUES (
                ?,
                "WITHDRAWAL",
                "withdrawal",
                ?,
                ?,
                "USD",
                ?,
                ?,
                "COMPLETED",
                ?
            )'
        );

        $insertWalletTransaction->execute([
            $userId,
            $withdrawalId,
            -$amount,
            $balanceBefore,
            $balanceAfter,
            'Withdrawal request #' .
                $withdrawalId .
                ' reserved.',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Commit transaction
        |--------------------------------------------------------------------------
        */

        $pdo->commit();

        /*
         * Rotate the CSRF token.
         */

        $_SESSION['withdraw_csrf_token'] = bin2hex(
            random_bytes(32)
        );

        $_SESSION['withdraw_success'] =
            'Withdrawal request #' .
            $withdrawalId .
            ' has been submitted successfully. Your funds have been reserved and the request is now pending review.';

        redirect('withdraw.php');
        exit;
    } catch (JsonException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'PoketFlow withdrawal JSON error: ' .
            $e->getMessage()
        );

        $_SESSION['withdraw_error'] =
            'Unable to prepare your withdrawal request. Please try again.';

        redirect('withdraw.php');
        exit;
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['withdraw_error'] = $e->getMessage();

        redirect('withdraw.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'PoketFlow withdrawal error: ' .
            $e->getMessage()
        );

        $_SESSION['withdraw_error'] =
            'Something went wrong while processing your withdrawal. Please try again later.';

        redirect('withdraw.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Current available balance
|--------------------------------------------------------------------------
*/

$availableBalance = (float) getUserBalance(
    $pdo,
    $userId
);

$minimumWithdrawal = (float) getSetting(
    $pdo,
    'minimum_withdrawal',
    '5.00'
);

if ($minimumWithdrawal <= 0) {
    $minimumWithdrawal = 5.00;
}

$canWithdraw = $availableBalance >= $minimumWithdrawal;

/*
|--------------------------------------------------------------------------
| HTML escaping
|--------------------------------------------------------------------------
|
| Uses the existing e() helper from your application.
|
*/

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
        content="#5A00A8"
    >

    <title>Withdraw — PoketFlow</title>

    <link
        rel="stylesheet"
        href="css/withdraw.css?v=1"
    >
</head>

<body class="withdraw-page">

<header class="pf-header">

    <a class="pf-brand" href="index.php" aria-label="PoketFlow homepage">
        <span class="pf-brand-mark">P</span>

        <span class="pf-brand-name">
            Poket<span>Flow</span>
        </span>
    </a>
<button
    class="pf-menu-toggle"
    id="pf-menu-toggle"
    type="button"
    aria-label="Open navigation menu"
    aria-expanded="false"
    aria-controls="pf-top-nav"
>
    <span></span>
    <span></span>
    <span></span>
</button>

<nav
    class="pf-top-nav"
    id="pf-top-nav"
    aria-label="Main navigation"
>
    <a href="dashboard.php">Home</a>
    <a href="offers.php">Earn</a>
    <a href="history.php">History</a>
    <a href="referrals.php">Refer &amp; Earn</a>

    <a
        class="active"
        href="withdraw.php"
        aria-current="page"
    >
        Withdraw
    </a>
</nav>
    

    

    <a href="logout.php" class="pf-logout">
        Log out
    </a>

</header>

<main class="pf-layout">

    <aside class="pf-sidebar">

        <div class="pf-sidebar-label">
            YOUR WORKSPACE
        </div>

        <nav class="pf-sidebar-nav" aria-label="Dashboard navigation">

            <a href="dashboard.php">
                <span class="pf-nav-icon">⌂</span>
                <span>Home</span>
            </a>

            <a href="offers.php">
                <span class="pf-nav-icon">▦</span>
                <span>Offers</span>
            </a>

            <a href="history.php">
                <span class="pf-nav-icon">◷</span>
                <span>History</span>
            </a>

            <a href="referrals.php">
                <span class="pf-nav-icon">♧</span>
                <span>Refer &amp; Earn</span>
            </a>

            <a class="active" href="withdraw.php" aria-current="page">
                <span class="pf-nav-icon">↗</span>
                <span>Withdraw</span>
            </a>

        </nav>

        <div class="pf-sidebar-promo">

            <div class="pf-promo-icon">✦</div>

            <h3>Your rewards matter.</h3>

            <p>
                Keep your payment details accurate to help
                avoid unnecessary withdrawal delays.
            </p>

        </div>

    </aside>

    <section class="pf-content">

        <div class="pf-page-heading">

            <div class="pf-eyebrow">
                YOUR REWARDS, YOUR CHOICE
            </div>

            <h1>Withdraw funds</h1>

            <p class="pf-lead">
                Choose your preferred payment method and request
                your PoketFlow rewards securely.
            </p>

        </div>

        <?php if ($error): ?>

            <div class="pf-alert pf-alert-error" role="alert">

                <span class="pf-alert-icon">!</span>

                <div>
                    <strong>Unable to submit request</strong>

                    <p><?= e($error) ?></p>
                </div>

            </div>

        <?php endif; ?>

        <?php if ($success): ?>

            <div class="pf-alert pf-alert-success" role="status">

                <span class="pf-alert-icon">✓</span>

                <div>
                    <strong>Withdrawal request submitted</strong>

                    <p><?= e($success) ?></p>
                </div>

            </div>

        <?php endif; ?>

        <!-- Balance summary -->

        <section class="pf-balance-card">

            <div class="pf-balance-content">

                <div class="pf-balance-label">
                    AVAILABLE BALANCE
                </div>

                <div class="pf-balance-amount">
                    $<?= number_format($availableBalance, 2) ?>
                </div>

                <div class="pf-balance-footnote">
                    Minimum withdrawal:
                    <strong>
                        $<?= number_format($minimumWithdrawal, 2) ?>
                    </strong>
                </div>

            </div>

            <div class="pf-balance-decoration" aria-hidden="true">
                $
            </div>

            <div class="pf-balance-glow" aria-hidden="true"></div>

        </section>

        <div class="pf-main-grid">

            <!-- Withdrawal form -->

            <section class="pf-card pf-withdraw-card">

                <div class="pf-card-heading">

                    <div>
                        <h2>Request a withdrawal</h2>

                        <p>
                            Complete the details below to continue.
                        </p>
                    </div>

                    <span class="pf-step-label">
                        STEP 01
                    </span>

                </div>

                <?php if (!$canWithdraw): ?>

                    <div class="pf-alert pf-alert-warning">

                        <span class="pf-alert-icon">i</span>

                        <div>
                            <strong>Minimum balance not reached</strong>

                            <p>
    You need another
    <strong class="pf-money">
        $<?= number_format(
            max(
                0,
                $minimumWithdrawal - $availableBalance
            ),
            2
        ) ?>
    </strong>
    to reach the minimum withdrawal amount.
</p>
 </div>


                <?php endif; ?>

                <form
                    method="POST"
                    action="withdraw.php"
                    class="pf-withdraw-form"
                    id="withdraw-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <!-- Payment method -->

                    <fieldset class="pf-method-fieldset">

                        <legend>
                            Choose payment method
                        </legend>

                        <p class="pf-field-description">
                            Available methods depend on your registered country.
                        </p>

                        <div
                            class="pf-method-grid <?= !$canWithdraw ? 'is-disabled' : '' ?>"
                            id="withdraw-methods"
                        >

                            <?php foreach ($withdrawalMethods as $method): ?>

                                <label class="pf-method-option">

                                    <input
                                        type="radio"
                                        name="withdrawal_method"
                                        value="<?= e($method['id']) ?>"
                                        data-type="<?= e($method['type']) ?>"
                                        required
                                        <?= !$canWithdraw ? 'disabled' : '' ?>
                                    >

                                    <span class="pf-method-box">

                                        <span class="pf-method-top">

                                            <span class="pf-method-icon">
                                                <?= e($method['icon']) ?>
                                            </span>

                                            <span class="pf-method-radio"></span>

                                        </span>

                                        <span class="pf-method-name">
                                            <?= e($method['name']) ?>
                                        </span>

                                        <span class="pf-method-description">
                                            <?= e($method['description']) ?>
                                        </span>

                                    </span>

                                </label>

                            <?php endforeach; ?>

                        </div>

                    </fieldset>

                    <!-- Payment details -->

                    <div
                        id="withdraw-details"
                        class="pf-payment-details"
                        hidden
                    >

                        <div class="pf-section-divider"></div>

                        <div class="pf-section-heading">

                            <div class="pf-section-heading-icon">
                                ◈
                            </div>

                            <div>
                                <h3>Payment details</h3>

                                <p>
                                    Enter the information needed for your selected method.
                                </p>
                            </div>

                        </div>

                        <!-- Bank fields -->

                        <div id="bank-fields" class="pf-detail-group" hidden>

                            <div class="pf-field">

                                <label for="account_name">
                                    Account holder name
                                </label>

                                <input
                                    type="text"
                                    id="account_name"
                                    name="account_name"
                                    minlength="2"
                                    maxlength="100"
                                    autocomplete="name"
                                    placeholder="Enter the name on your account"
                                    disabled
                                >

                            </div>

                            <div class="pf-field">

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
                                    autocomplete="off"
                                    placeholder="Enter your 10-digit account number"
                                    disabled
                                >

                                <small class="pf-field-help">
                                    Enter exactly 10 digits.
                                </small>

                            </div>

                        </div>

                        <!-- PayPal fields -->

                        <div id="paypal-fields" class="pf-detail-group" hidden>

                            <div class="pf-field">

                                <label for="paypal_email">
                                    PayPal email address
                                </label>

                                <input
                                    type="email"
                                    id="paypal_email"
                                    name="paypal_email"
                                    maxlength="190"
                                    autocomplete="email"
                                    placeholder="you@example.com"
                                    disabled
                                >

                                <small class="pf-field-help">
                                    Use the email associated with your PayPal account.
                                </small>

                            </div>

                        </div>

                        <!-- Cryptocurrency fields -->

                        <div id="crypto-fields" class="pf-detail-group" hidden>

                            <div class="pf-field">

                                <label for="crypto_network">
                                    Cryptocurrency network
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

                                <small class="pf-field-help">
                                    The network must match your receiving wallet.
                                </small>

                            </div>

                            <div class="pf-field">

                                <label for="wallet_address">
                                    Wallet address
                                </label>

                                <textarea
                                    id="wallet_address"
                                    name="wallet_address"
                                    rows="3"
                                    minlength="20"
                                    maxlength="255"
                                    autocomplete="off"
                                    placeholder="Paste your receiving wallet address"
                                    disabled
                                ></textarea>

                            </div>

                            <div class="pf-alert pf-alert-warning pf-crypto-warning">

                                <span class="pf-alert-icon">!</span>

                                <div>
                                    <strong>Check your network carefully</strong>

                                    <p>
                                        Cryptocurrency transfers may be irreversible.
                                        Confirm your wallet address and network before submitting.
                                    </p>
                                </div>

                            </div>

                        </div>

                        <!-- Withdrawal amount -->

                        <div class="pf-field pf-amount-field">

                            <label for="withdrawal_amount">
                                Withdrawal amount
                            </label>

                            <div class="pf-amount-input">

                                <span class="pf-currency-prefix">
                                    USD $
                                </span>

                                <input
                                    type="number"
                                    id="withdrawal_amount"
                                    name="withdrawal_amount"
                                    min="<?= e(number_format(
                                        $minimumWithdrawal,
                                        2,
                                        '.',
                                        ''
                                    )) ?>"
                                    max="<?= e(number_format(
                                        $availableBalance,
                                        2,
                                        '.',
                                        ''
                                    )) ?>"
                                    step="0.01"
                                    placeholder="0.00"
                                    required
                                    disabled
                                >

                            </div>

                            <small class="pf-field-help">
                                Minimum $<?= number_format($minimumWithdrawal, 2) ?>.
                                Available $<?= number_format($availableBalance, 2) ?>.
                            </small>

                        </div>

                        <div class="pf-security-note">

                            <span class="pf-security-note-icon">✓</span>

                            <p>
                                Your request will be recorded as pending.
                                The requested amount is reserved when your request
                                is submitted.
                            </p>

                        </div>

                        <button
                            class="pf-submit-button"
                            type="submit"
                            id="submit-withdrawal"
                            disabled
                        >

                            <span>Submit withdrawal request</span>

                            <span class="pf-submit-arrow">→</span>

                        </button>

                        <p class="pf-form-footnote">
                            Review your payment details before submitting.
                        </p>

                    </div>

                </form>

            </section>

            <!-- Withdrawal information -->

            <aside class="pf-info-column">

                <section class="pf-card pf-process-card">

                    <div class="pf-info-icon">✦</div>

                    <h2>How it works</h2>

                    <p class="pf-info-intro">
                        Your withdrawal follows three simple steps.
                    </p>

                    <ol class="pf-process-list">

                        <li>

                            <span class="pf-process-number">01</span>

                            <div>
                                <h3>Submit your request</h3>

                                <p>
                                    Choose a payment method and provide
                                    the correct details.
                                </p>
                            </div>

                        </li>

                        <li>

                            <span class="pf-process-number">02</span>

                            <div>
                                <h3>Request review</h3>

                                <p>
                                    Your withdrawal remains pending
                                    while it awaits processing.
                                </p>
                            </div>

                        </li>

                        <li>

                            <span class="pf-process-number">03</span>

                            <div>
                                <h3>Receive your reward</h3>

                                <p>
                                    Payment is sent after your withdrawal
                                    is approved and processed.
                                </p>
                            </div>

                        </li>

                    </ol>

                </section>

                <section class="pf-card pf-security-card">

                    <div class="pf-security-card-icon">♢</div>

                    <div>
                        <h3>Protect your account</h3>

                        <p>
                            Never share your password or verification codes
                            with anyone claiming to process your withdrawal.
                        </p>
                    </div>

                </section>

                <section class="pf-card pf-help-card">

                    <h3>Need help?</h3>

                    <p>
                        Review your previous transactions or contact
                        PoketFlow support if you need help with a request.
                    </p>

                    <a href="history.php">
                        View transaction history <span>→</span>
                    </a>

                </section>

            </aside>

        </div>

        <footer class="pf-footer">

            <span>
                &copy; <?= date('Y') ?> PoketFlow
            </span>

            <span>
                Your rewards. Your choice.
            </span>

        </footer>

    </section>

</main>
    
<script>
(function () {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | MOBILE NAVIGATION
    |--------------------------------------------------------------------------
    */

    const menuToggle = document.getElementById('pf-menu-toggle');
    const topNav = document.getElementById('pf-top-nav');

    function closeMenu() {
        if (!menuToggle || !topNav) {
            return;
        }

        topNav.classList.remove('is-open');

        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.setAttribute('aria-label', 'Open navigation menu');
    }

    function openMenu() {
        if (!menuToggle || !topNav) {
            return;
        }

        topNav.classList.add('is-open');

        menuToggle.setAttribute('aria-expanded', 'true');
        menuToggle.setAttribute('aria-label', 'Close navigation menu');
    }

    if (menuToggle && topNav) {
        menuToggle.addEventListener('click', function () {
            const isOpen =
                menuToggle.getAttribute('aria-expanded') === 'true';

            if (isOpen) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        /*
         * Close the menu after selecting a navigation link.
         */
        topNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMenu);
        });

        /*
         * Close the menu when tapping outside the header.
         */
        document.addEventListener('click', function (event) {
            if (
                !event.target.closest('.pf-header') &&
                menuToggle.getAttribute('aria-expanded') === 'true'
            ) {
                closeMenu();
            }
        });

        /*
         * Close the menu with the Escape key.
         */
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
                menuToggle.focus();
            }
        });

        /*
         * Reset the mobile menu when switching to desktop width.
         */
        window.addEventListener('resize', function () {
            if (window.innerWidth > 760) {
                closeMenu();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | WITHDRAWAL FORM
    |--------------------------------------------------------------------------
    */

    const form = document.getElementById('withdraw-form');

    if (!form) {
        return;
    }

    const methodInputs = document.querySelectorAll(
        'input[name="withdrawal_method"]'
    );

    const details = document.getElementById('withdraw-details');

    const bankFields = document.getElementById('bank-fields');
    const paypalFields = document.getElementById('paypal-fields');
    const cryptoFields = document.getElementById('crypto-fields');

    const accountName = document.getElementById('account_name');
    const accountNumber = document.getElementById('account_number');

    const paypalEmail = document.getElementById('paypal_email');

    const cryptoNetwork = document.getElementById('crypto_network');
    const walletAddress = document.getElementById('wallet_address');

    const amountInput = document.getElementById('withdrawal_amount');
    const submitButton = document.getElementById('submit-withdrawal');

    const cryptoOptions = {
        USDT: ['TRC20', 'ERC20'],
        USDC: ['ERC20', 'Polygon']
    };

    const detailGroups = [
        bankFields,
        paypalFields,
        cryptoFields
    ];

    /*
     * Show or hide payment fields and disable inactive controls.
     */
    function toggleGroup(group, enabled) {
        group.hidden = !enabled;

        group.querySelectorAll(
            'input, select, textarea'
        ).forEach(function (field) {
            field.disabled = !enabled;
        });
    }

    /*
     * Populate the cryptocurrency network dropdown.
     */
    function populateCryptoNetworks(method) {
        cryptoNetwork.replaceChildren();

        const placeholder = document.createElement('option');

        placeholder.value = '';
        placeholder.textContent = 'Select a network';

        cryptoNetwork.appendChild(placeholder);

        const networks = cryptoOptions[method] || [];

        networks.forEach(function (network) {
            const option = document.createElement('option');

            option.value = network;
            option.textContent = network;

            cryptoNetwork.appendChild(option);
        });
    }

    /*
     * Update fields according to the selected payment method.
     */
    function updatePaymentDetails() {
        let selectedMethod = null;

        methodInputs.forEach(function (input) {
            if (input.checked) {
                selectedMethod = input;
            }
        });

        detailGroups.forEach(function (group) {
            toggleGroup(group, false);
        });

        /*
         * Reset required fields before enabling the selected group.
         */
        accountName.required = false;
        accountNumber.required = false;
        paypalEmail.required = false;
        cryptoNetwork.required = false;
        walletAddress.required = false;

        amountInput.disabled = true;
        submitButton.disabled = true;

        if (!selectedMethod) {
            details.hidden = true;
            return;
        }

        details.hidden = false;

        const method = selectedMethod.value;
        const type = selectedMethod.dataset.type;

        if (type === 'bank') {
            toggleGroup(bankFields, true);

            accountName.required = true;
            accountNumber.required = true;

        } else if (type === 'paypal') {
            toggleGroup(paypalFields, true);

            paypalEmail.required = true;

        } else if (type === 'crypto') {
            populateCryptoNetworks(method);

            toggleGroup(cryptoFields, true);

            cryptoNetwork.required = true;
            walletAddress.required = true;
        }

        amountInput.disabled = false;
        submitButton.disabled = false;
    }

    /*
     * Listen for payment-method changes.
     */
    methodInputs.forEach(function (input) {
        input.addEventListener(
            'change',
            updatePaymentDetails
        );
    });

    /*
     * Prevent accidental double submissions.
     */
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

        const buttonText = submitButton.querySelector(
            'span:first-child'
        );

        if (buttonText) {
            buttonText.textContent = 'Submitting request...';
        }
    });

    /*
     * Initialise the form.
     */
    updatePaymentDetails();

})();
</script>


</body>
</html>
