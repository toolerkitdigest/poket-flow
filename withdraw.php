<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['withdraw_csrf_token'])) {
    $_SESSION['withdraw_csrf_token'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['withdraw_csrf_token'];

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
    [
        'NG',
        'NIGERIA',
    ],
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
        'description' =>
            'Receive your reward through your Nigerian account.',
        'icon' => '🇳🇬',
        'type' => 'bank',
    ];

    $withdrawalMethods[] = [
        'id' => 'PALMPAY',
        'name' => 'PalmPay',
        'description' =>
            'Receive your reward through your Nigerian account.',
        'icon' => '🇳🇬',
        'type' => 'bank',
    ];
}

$withdrawalMethods[] = [
    'id' => 'PAYPAL',
    'name' => 'PayPal',
    'description' =>
        'Receive your reward through your PayPal account.',
    'icon' => '💳',
    'type' => 'paypal',
];

$withdrawalMethods[] = [
    'id' => 'USDT',
    'name' => 'USDT',
    'description' =>
        'Receive your reward in Tether.',
    'icon' => '₮',
    'type' => 'crypto',
];

$withdrawalMethods[] = [
    'id' => 'USDC',
    'name' => 'USDC',
    'description' =>
        'Receive your reward in USD Coin.',
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
         * CSRF validation.
         */
        $postedToken = (string) (
            $_POST['csrf_token'] ?? ''
        );

        if (
            $postedToken === '' ||
            !hash_equals(
                $csrfToken,
                $postedToken
            )
        ) {
            throw new RuntimeException(
                'Your session has expired. Please refresh the page and try again.'
            );
        }

        /*
         * Selected withdrawal method.
         */
        $method = strtoupper(
            trim(
                (string) (
                    $_POST['withdrawal_method']
                    ?? ''
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

        if (
            !in_array(
                $method,
                $allowedMethods,
                true
            )
        ) {
            throw new RuntimeException(
                'Please select a valid withdrawal method.'
            );
        }

        /*
         * Nigeria-only methods.
         */
        if (
            in_array(
                $method,
                [
                    'MONIEPOINT',
                    'PALMPAY',
                ],
                true
            ) &&
            !$isNigeria
        ) {
            throw new RuntimeException(
                'This withdrawal method is only available to users in Nigeria.'
            );
        }

        /*
         * Amount validation.
         */
        $amountInput = trim(
            (string) (
                $_POST['withdrawal_amount']
                ?? ''
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

        $amount = round(
            (float) $amountInput,
            2
        );

        if ($amount <= 0) {
            throw new RuntimeException(
                'Withdrawal amount must be greater than zero.'
            );
        }

        /*
         * Payment details.
         */
        $paymentDetails = [];
        $methodNetwork = null;

        if (
            $method === 'MONIEPOINT' ||
            $method === 'PALMPAY'
        ) {

            $accountName = trim(
                (string) (
                    $_POST['account_name']
                    ?? ''
                )
            );

            $accountNumber = trim(
                (string) (
                    $_POST['account_number']
                    ?? ''
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

            /*
             * Nigerian account numbers are normally
             * numeric and 10 digits.
             */
            if (
                !preg_match(
                    '/^\d{10}$/',
                    $accountNumber
                )
            ) {
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
                'account_name' =>
                    $accountName,

                'account_number' =>
                    $accountNumber,
            ];
        }

        elseif ($method === 'PAYPAL') {

            $paypalEmail = strtolower(
                trim(
                    (string) (
                        $_POST['paypal_email']
                        ?? ''
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
                'paypal_email' =>
                    $paypalEmail,
            ];
        }

        elseif (
            $method === 'USDT' ||
            $method === 'USDC'
        ) {

            $methodNetwork = strtoupper(
                trim(
                    (string) (
                        $_POST['crypto_network']
                        ?? ''
                    )
                )
            );

            $walletAddress = trim(
                (string) (
                    $_POST['wallet_address']
                    ?? ''
                )
            );

            $allowedNetworks =
                $cryptoNetworks[$method]
                ?? [];

            if (
                !in_array(
                    $methodNetwork,
                    $allowedNetworks,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Please select a valid cryptocurrency network.'
                );
            }

            if (
                $walletAddress === '' ||
                mb_strlen($walletAddress) < 20 ||
                mb_strlen($walletAddress) > 255
            ) {
                throw new RuntimeException(
                    'Please enter a valid wallet address.'
                );
            }

            /*
             * Store the address as text.
             *
             * We deliberately do not attempt to guess
             * whether an address belongs to a particular
             * blockchain here. Final payment verification
             * belongs to the admin/payment process.
             */
            $paymentDetails = [
                'wallet_address' =>
                    $walletAddress,
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
         *
         * This prevents two simultaneous withdrawal
         * requests from both spending the same balance.
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

        $lockUser->execute([
            $userId,
        ]);

        $lockedUser = $lockUser->fetch();

        if (!$lockedUser) {
            throw new RuntimeException(
                'User account could not be found.'
            );
        }

        if (
            strtoupper(
                (string) (
                    $lockedUser['status']
                    ?? ''
                )
            ) !== 'ACTIVE'
        ) {
            throw new RuntimeException(
                'Your account is not currently eligible for withdrawals.'
            );
        }

        /*
         * Prevent multiple active withdrawal requests.
         *
         * The money from an existing withdrawal is already
         * reserved, so allowing another pending request
         * would make the user experience confusing.
         */
        $activeWithdrawalStmt =
            $pdo->prepare(
                'SELECT id
                 FROM withdrawals
                 WHERE user_id = ?
                   AND status IN (
                       "PENDING",
                       "PROCESSING"
                   )
                 LIMIT 1
                 FOR UPDATE'
            );

        $activeWithdrawalStmt->execute([
            $userId,
        ]);

        $activeWithdrawal =
            $activeWithdrawalStmt->fetchColumn();

        if ($activeWithdrawal !== false) {
            throw new RuntimeException(
                'You already have a withdrawal request being processed.'
            );
        }

        /*
         * Recalculate available wallet balance
         * while the user row is locked.
         */
        $balanceStmt = $pdo->prepare(
            'SELECT
                COALESCE(
                    SUM(amount),
                    0
                )
             FROM wallet_transactions
             WHERE user_id = ?
               AND status = "COMPLETED"'
        );

        $balanceStmt->execute([
            $userId,
        ]);

        $availableBalance = round(
            (float) $balanceStmt->fetchColumn(),
            2
        );

        if (
            $availableBalance <
            $minimumWithdrawal
        ) {
            throw new RuntimeException(
                'You have not reached the minimum withdrawal amount.'
            );
        }

        if ($amount > $availableBalance) {
            throw new RuntimeException(
                'The withdrawal amount is greater than your available balance.'
            );
        }

        /*
         * Create the withdrawal request first.
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
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                "PENDING"
            )'
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

        $withdrawalId =
            (int) $pdo->lastInsertId();

        /*
         * Reserve/deduct the requested amount from
         * the user's available wallet.
         *
         * This is a COMPLETED wallet transaction because
         * the money has now been reserved.
         */
        $balanceBefore =
            $availableBalance;

        $balanceAfter =
            round(
                $balanceBefore - $amount,
                2
            );

        $insertWalletTransaction =
            $pdo->prepare(
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
         * Everything succeeded.
         */
        $pdo->commit();

        /*
         * Rotate the CSRF token after a successful
         * financial operation.
         */
        $_SESSION['withdraw_csrf_token'] =
            bin2hex(random_bytes(32));

        $_SESSION['withdraw_success'] =
            'Withdrawal request #' .
            $withdrawalId .
            ' has been submitted successfully. Your funds have been reserved and the request is now pending review.';

        redirect('withdraw.php');

    } catch (JsonException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['withdraw_error'] =
            'Unable to prepare your withdrawal request. Please try again.';

        redirect('withdraw.php');

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['withdraw_error'] =
            $e->getMessage();

        redirect('withdraw.php');
    }
}

/*
|--------------------------------------------------------------------------
| Current available balance
|--------------------------------------------------------------------------
*/

$availableBalance = getUserBalance(
    $pdo,
    $userId
);

$minimumWithdrawal = (float) getSetting(
    $pdo,
    'minimum_withdrawal',
    '5.00'
);

$canWithdraw =
    $availableBalance >=
    $minimumWithdrawal;

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>Withdraw — PoketFlow</title>

    <link
        rel="stylesheet"
        href="href="/css/withdraw.css">
    

    <style>

        .withdraw-methods {
            display: grid;
            gap: 12px;
            margin-top: 20px;
        }

        .withdraw-method {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 14px;
            background: rgba(255,255,255,.025);
            cursor: pointer;
            transition: .2s ease;
        }

        .withdraw-method:hover {
            background: rgba(255,255,255,.05);
            border-color: rgba(99,102,241,.45);
        }

        .withdraw-method input {
            accent-color: #6366f1;
        }

        .withdraw-method-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: rgba(99,102,241,.12);
            font-size: 20px;
            flex-shrink: 0;
        }

        .withdraw-method-info {
            flex: 1;
        }

        .withdraw-method-name {
            display: block;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .withdraw-method-description {
            display: block;
            color: var(--text-muted, #718096);
            font-size: 13px;
            line-height: 1.5;
        }

        .withdraw-form {
            margin-top: 28px;
            padding: 24px;
            border-radius: 18px;
            background: var(--surface, #111827);
            border: 1px solid rgba(255,255,255,.07);
        }

        .withdraw-form h2 {
            margin-top: 0;
            margin-bottom: 20px;
        }

        .withdraw-field {
            margin-bottom: 18px;
        }

        .withdraw-field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .withdraw-field input,
        .withdraw-field select {
            width: 100%;
            box-sizing: border-box;
            padding: 13px 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,.1);
            background: #0c1324;
            color: #f8fafc;
        }

        .withdraw-field small {
            display: block;
            margin-top: 7px;
            color: #718096;
            line-height: 1.5;
        }

        .withdraw-notice {
            padding: 14px 16px;
            margin-top: 20px;
            margin-bottom: 20px;
            border-radius: 12px;
            background: rgba(34,211,238,.07);
            border: 1px solid rgba(34,211,238,.18);
            color: #a7b1c2;
            font-size: 14px;
            line-height: 1.6;
        }

        .withdraw-warning {
            padding: 14px 16px;
            margin-top: 20px;
            margin-bottom: 20px;
            border-radius: 12px;
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.2);
            color: #fbbf24;
            font-size: 14px;
            line-height: 1.6;
        }

        .withdraw-error {
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 12px;
            background: rgba(239,68,68,.08);
            border: 1px solid rgba(239,68,68,.2);
            color: #fca5a5;
            font-size: 14px;
            line-height: 1.6;
        }

        .withdraw-success {
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 12px;
            background: rgba(34,197,94,.08);
            border: 1px solid rgba(34,197,94,.2);
            color: #86efac;
            font-size: 14px;
            line-height: 1.6;
        }

        .withdraw-disabled {
            opacity: .6;
            pointer-events: none;
        }

        .withdraw-hidden {
            display: none;
        }

    </style>

</head>

<body class="app-page">

<header class="app-header">

    <a class="brand" href="index.php">

        <span class="brand-mark">P</span>

        <span>
            Poket<span>Flow</span>
        </span>

    </a>

    <nav>

        <a href="dashboard.php">
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

        <a class="active" href="withdraw.php">
            Withdraw
        </a>

    </nav>

</header>

<main class="app-shell">

    <aside class="sidebar">

        <div class="sidebar-nav">

            <a href="dashboard.php">
                ⌂ <span>Home</span>
            </a>

            <a href="offers.php">
                ▦ <span>Offers</span>
            </a>

            <a href="history.php">
                ◷ <span>History</span>
            </a>

            <a href="referrals.php">
                ♧ <span>Refer & Earn</span>
            </a>

            <a class="active" href="withdraw.php">
                ▣ <span>Withdraw</span>
            </a>

        </div>

    </aside>

    <section class="app-content">

        <span class="kicker">
            REWARDS
        </span>

        <h1>
            Withdraw
        </h1>

        <p class="lead">
            Choose how you would like to receive your PoketFlow rewards.
        </p>

        <?php if ($error): ?>

            <div class="withdraw-error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <?php if ($success): ?>

            <div class="withdraw-success">
                <?= e($success) ?>
            </div>

        <?php endif; ?>

        <div class="withdraw-card">

            <div>

                <span>
                    Available balance
                </span>

                <strong>
                    $<?= number_format(
                        $availableBalance,
                        2
                    ) ?>
                </strong>

            </div>

            <small>

                Minimum withdrawal:
                $<?= number_format(
                    $minimumWithdrawal,
                    2
                ) ?>.

                <?php if ($canWithdraw): ?>

                    You are eligible to request a withdrawal.

                <?php else: ?>

                    You need
                    $<?= number_format(
                        max(
                            0,
                            $minimumWithdrawal -
                            $availableBalance
                        ),
                        2
                    ) ?>
                    more to reach the minimum withdrawal amount.

                <?php endif; ?>

            </small>

        </div>

        <form
            method="POST"
            action="withdraw.php"
            class="withdraw-form"
            id="withdraw-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <h2>
                Withdrawal method
            </h2>

            <?php if (!$canWithdraw): ?>

                <div class="withdraw-warning">

                    Your available balance has not yet reached
                    the minimum withdrawal amount.

                </div>

            <?php endif; ?>

            <div
                class="<?= !$canWithdraw
                    ? 'withdraw-methods withdraw-disabled'
                    : 'withdraw-methods' ?>"
            >

                <?php foreach (
                    $withdrawalMethods
                    as $method
                ): ?>

                    <label class="withdraw-method">

                        <input
                            type="radio"
                            name="withdrawal_method"
                            value="<?= e($method['id']) ?>"
                            data-type="<?= e($method['type']) ?>"
                            required
                        >

                        <span class="withdraw-method-icon">
                            <?= e($method['icon']) ?>
                        </span>

                        <span class="withdraw-method-info">

                            <span class="withdraw-method-name">
                                <?= e($method['name']) ?>
                            </span>

                            <span class="withdraw-method-description">
                                <?= e($method['description']) ?>
                            </span>

                        </span>

                    </label>

                <?php endforeach; ?>

            </div>

            <div
                id="withdraw-details"
                class="<?= !$canWithdraw
                    ? 'withdraw-hidden withdraw-disabled'
                    : 'withdraw-hidden' ?>"
            >

                <h2>
                    Payment details
                </h2>

                <div
                    id="bank-fields"
                    class="withdraw-hidden"
                >

                    <div class="withdraw-field">

                        <label for="account_name">
                            Account name
                        </label>

                        <input
                            type="text"
                            id="account_name"
                            name="account_name"
                            autocomplete="name"
                            placeholder="Enter account name"
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
                            placeholder="Enter 10-digit account number"
                        >

                    </div>

                </div>

                <div
                    id="paypal-fields"
                    class="withdraw-hidden"
                >

                    <div class="withdraw-field">

                        <label for="paypal_email">
                            PayPal email
                        </label>

                        <input
                            type="email"
                            id="paypal_email"
                            name="paypal_email"
                            autocomplete="email"
                            placeholder="you@example.com"
                        >

                    </div>

                </div>

                <div
                    id="crypto-fields"
                    class="withdraw-hidden"
                >

                    <div class="withdraw-field">

                        <label for="crypto_network">
                            Network
                        </label>

                        <select
                            id="crypto_network"
                            name="crypto_network"
                        >

                            <option value="">
                                Select network
                            </option>

                        </select>

                    </div>

                    <div class="withdraw-field">

                        <label for="wallet_address">
                            Wallet address
                        </label>

                        <input
                            type="text"
                            id="wallet_address"
                            name="wallet_address"
                            autocomplete="off"
                            placeholder="Enter wallet address"
                        >

                    </div>

                    <div class="withdraw-warning">

                        Make sure your wallet address and network
                        are correct. Cryptocurrency transactions
                        may be irreversible.

                    </div>

                </div>

                <div class="withdraw-field">

                    <label for="withdrawal_amount">
                        Withdrawal amount
                    </label>

                    <input
                        type="number"
                        id="withdrawal_amount"
                        name="withdrawal_amount"
                        min="<?= e(
                            number_format(
                                $minimumWithdrawal,
                                2,
                                '.',
                                ''
                            )
                        ) ?>"
                        max="<?= e(
                            number_format(
                                $availableBalance,
                                2,
                                '.',
                                ''
                            )
                        ) ?>"
                        step="0.01"
                        placeholder="0.00"
                        required
                    >

                    <small>

                        Minimum:
                        $<?= number_format(
                            $minimumWithdrawal,
                            2
                        ) ?>

                        ·

                        Available:
                        $<?= number_format(
                            $availableBalance,
                            2
                        ) ?>

                    </small>

                </div>

                <div class="withdraw-notice">

                    Withdrawals are reviewed by PoketFlow before
                    payment is released. Your requested amount
                    is reserved when you submit the request.

                </div>

                <button
                    class="btn btn-primary"
                    type="submit"
                    id="submit-withdrawal"
                >
                    Submit Withdrawal Request
                </button>

            </div>

        </form>

    </section>

</main>

<script>

const methodInputs =
    document.querySelectorAll(
        'input[name="withdrawal_method"]'
    );

const details =
    document.getElementById(
        'withdraw-details'
    );

const bankFields =
    document.getElementById(
        'bank-fields'
    );

const paypalFields =
    document.getElementById(
        'paypal-fields'
    );

const cryptoFields =
    document.getElementById(
        'crypto-fields'
    );

const cryptoNetwork =
    document.getElementById(
        'crypto_network'
    );

const cryptoNetworks =
    <?= json_encode(
        $cryptoNetworks,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    ) ?>;

methodInputs.forEach(
    function (input) {

        input.addEventListener(
            'change',
            function () {

                details.classList.remove(
                    'withdraw-hidden'
                );

                bankFields.classList.add(
                    'withdraw-hidden'
                );

                paypalFields.classList.add(
                    'withdraw-hidden'
                );

                cryptoFields.classList.add(
                    'withdraw-hidden'
                );

                cryptoNetwork.innerHTML =
                    '<option value="">Select network</option>';

                if (
                    input.dataset.type === 'bank'
                ) {

                    bankFields.classList.remove(
                        'withdraw-hidden'
                    );

                }

                if (
                    input.dataset.type === 'paypal'
                ) {

                    paypalFields.classList.remove(
                        'withdraw-hidden'
                    );

                }

                if (
                    input.dataset.type === 'crypto'
                ) {

                    cryptoFields.classList.remove(
                        'withdraw-hidden'
                    );

                    const networks =
                        cryptoNetworks[input.value]
                        || [];

                    networks.forEach(
                        function (network) {

                            const option =
                                document.createElement(
                                    'option'
                                );

                            option.value =
                                network;

                            option.textContent =
                                network;

                            cryptoNetwork.appendChild(
                                option
                            );

                        }
                    );

                }

            }
        );

    }
);

</script>

</body>

</html>
