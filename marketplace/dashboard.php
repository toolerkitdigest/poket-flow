<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);

$workerWallet = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id = ? AND wallet_type = 'WORKER' AND currency = 'USD' LIMIT 1");
$workerWallet->execute([(int)$user['id']]);
$workerWalletId = $workerWallet->fetchColumn();
$workerBalance = 0.0;
if ($workerWalletId) {
    $balance = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pf_wallet_ledger WHERE wallet_id = ?');
    $balance->execute([(int)$workerWalletId]);
    $workerBalance = (float)$balance->fetchColumn();
}

$advertiserWallet = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id = ? AND wallet_type = 'ADVERTISER' AND currency = 'USD' LIMIT 1");
$advertiserWallet->execute([(int)$user['id']]);
$advertiserWalletId = $advertiserWallet->fetchColumn();
$advertiserBalance = 0.0;
if ($advertiserWalletId) {
    $balance = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pf_wallet_ledger WHERE wallet_id = ?');
    $balance->execute([(int)$advertiserWalletId]);
    $advertiserBalance = (float)$balance->fetchColumn();
}

$taskCount = $pdo->prepare("SELECT COUNT(*) FROM pf_campaigns WHERE campaign_type='WORKER_TASK' AND status='ACTIVE'");
$taskCount->execute();
$availableTasks = (int)$taskCount->fetchColumn();

$myCampaigns = $pdo->prepare('SELECT COUNT(*) FROM pf_campaigns WHERE advertiser_id = ?');
$myCampaigns->execute([(int)$user['id']]);
$campaignCount = (int)$myCampaigns->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My PoketFlow | Dashboard</title>
<link rel="stylesheet" href="assets/marketplace.css">
</head>
<body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="tasks.php">Find tasks</a><a href="campaigns.php">Worker tasks</a><a href="blog-campaigns.php">Blog ads</a><a href="wallet.php">Wallets</a><a href="funding.php">Add funds</a><?php if (strtoupper((string) $user['role']) === 'ADMIN'): ?><a href="admin/index.php">Admin</a><?php endif; ?><a href="logout.php">Sign out</a></nav></header>
<main class="container">
<section class="welcome"><p class="eyebrow">YOUR UNIFIED ACCOUNT</p><h1>Welcome, <?= pf_e((string)$user['name']) ?></h1><p class="muted">Use the same account to earn from tasks and advertise to the PoketFlow community.</p></section>
<section class="mode-grid">
<article class="panel mode-worker"><p class="eyebrow">EARN MODE</p><h2>Complete tasks. Earn rewards.</h2><p class="muted">Browse advertiser-created tasks, follow instructions and submit evidence for review.</p><div class="metric">$<?= number_format($workerBalance,2) ?><span>Worker wallet</span></div><p class="muted"><?= number_format($availableTasks) ?> active tasks available</p><a class="button primary" href="tasks.php">Explore tasks</a></article>
<article class="panel mode-advertiser"><p class="eyebrow">ADVERTISE MODE</p><h2>Reach real people.</h2><p class="muted">Create worker tasks or request sponsored blog placements from one advertiser account.</p><div class="metric">$<?= number_format($advertiserBalance,2) ?><span>Advertiser wallet</span></div><p class="muted"><?= number_format($campaignCount) ?> campaigns in your account</p><a class="button primary" href="campaigns.php">Worker task campaigns</a><a class="button secondary" href="blog-campaigns.php">Blog sponsorships</a></article>
</section>
<p class="small muted">Both modes belong to this one account. Your advertiser funds and worker earnings are kept in separate ledgers.</p>
</main>
</body>
</html>
