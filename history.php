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
| Get Offer Completion History
|--------------------------------------------------------------------------
| Only conversions belonging to the currently logged-in user are shown.
|
| APPROVED = completed/credited offer
| PENDING  = conversion received but not yet finalized
| REJECTED = conversion rejected
| REVERSED = previously credited conversion reversed
|--------------------------------------------------------------------------
*/

$historyStmt = $pdo->prepare(
    'SELECT
        c.id,
        c.campaign_id,
        c.network_payout,
        c.reward_rate,
        c.worker_reward,
        c.platform_margin,
        c.status,
        c.external_transaction_id,
        c.converted_at,
        c.created_at,

        cp.title AS offer_title,
        cp.category AS offer_category

     FROM conversions c

     INNER JOIN campaigns cp
        ON cp.id = c.campaign_id

     WHERE c.worker_id = ?

     ORDER BY COALESCE(c.converted_at, c.created_at) DESC
     LIMIT 100'
);

$historyStmt->execute([$userId]);

$history = $historyStmt->fetchAll();

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title>History — PoketFlow</title>

    <link rel="stylesheet" href="assets/poketflow.css?v2">
</head>

<body class="app-page">

<header class="app-header">

    <a class="brand" href="index.html">
        <span class="brand-mark">P</span>
        <span>Poket<span>Flow</span></span>
    </a>

    <nav>
        <a href="dashboard.php">Home</a>
        <a href="offers.php">Earn</a>
        <a class="active" href="history.php">History</a>
        <a href="referrals.php">Refer & Earn</a>
        <a href="withdraw.php">Withdraw</a>
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

            <a class="active" href="history.php">
                ◷ <span>History</span>
            </a>

            <a href="referrals.php">
                ♧ <span>Refer & Earn</span>
            </a>

            <a href="withdraw.php">
                ▣ <span>Withdraw</span>
            </a>

        </div>

    </aside>

    <section class="app-content">

        <span class="kicker">ACCOUNT ACTIVITY</span>

        <h1>Offer History</h1>

        <p class="lead">
            Track your recent offer activity and completion status.
        </p>

        <?php if (empty($history)): ?>

            <div class="empty-state">

                <div>◷</div>

                <h2>No activity yet</h2>

                <p>
                    Your completed offers will appear here.
                </p>

                <a class="btn btn-primary" href="offers.php">
                    Browse Offers →
                </a>

            </div>

        <?php else: ?>

            <div class="history-list">

                <?php foreach ($history as $item): ?>

                    <?php
                    $status = strtoupper((string) $item['status']);

                    $statusLabel = match ($status) {
                        'APPROVED' => 'Completed',
                        'PENDING' => 'Pending',
                        'REJECTED' => 'Rejected',
                        'REVERSED' => 'Reversed',
                        default => ucfirst(strtolower($status)),
                    };

                    $dateValue = $item['converted_at'] ?: $item['created_at'];

                    $date = $dateValue
                        ? date('M j, Y · g:i A', strtotime((string) $dateValue))
                        : '—';

                    $reward = (float) $item['worker_reward'];
                    ?>

                    <div class="history-item">

                        <div class="history-main">

                            <div class="history-icon">
                                ✓
                            </div>

                            <div>

                                <h3>
                                    <?= e($item['offer_title'] ?: 'Offer Completion') ?>
                                </h3>

                                <p>
                                    <?= e($item['offer_category'] ?: 'Offer') ?>
                                    ·
                                    <?= e($date) ?>
                                </p>

                            </div>

                        </div>

                        <div class="history-right">

                            <strong>
                                +$<?= number_format($reward, 2) ?>
                            </strong>

                            <span class="history-status status-<?= strtolower($status) ?>">
                                <?= e($statusLabel) ?>
                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
