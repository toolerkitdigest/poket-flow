<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);

$walletsStmt = $pdo->prepare('SELECT id,wallet_type,currency FROM pf_wallets WHERE user_id=? ORDER BY wallet_type');
$walletsStmt->execute([(int)$user['id']]);
$wallets = $walletsStmt->fetchAll();
$balances = [];
$entries = [];
foreach ($wallets as $wallet) {
    $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pf_wallet_ledger WHERE wallet_id=?');
    $sum->execute([(int)$wallet['id']]);
    $balances[$wallet['wallet_type']] = (float)$sum->fetchColumn();
    $history = $pdo->prepare('SELECT entry_type,amount,currency,description,created_at FROM pf_wallet_ledger WHERE wallet_id=? ORDER BY id DESC LIMIT 30');
    $history->execute([(int)$wallet['id']]);
    $entries[$wallet['wallet_type']] = $history->fetchAll();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wallets | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head><body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="tasks.php">Earn</a><a href="campaigns.php">Advertise</a><a href="logout.php">Sign out</a></nav></header><main class="container"><section class="welcome"><p class="eyebrow">ACCOUNT FINANCES</p><h1>Your wallets</h1><p class="muted">Advertiser funding and worker earnings are kept separate. Balances are calculated from the immutable transaction ledger.</p></section><section class="mode-grid"><article class="panel"><p class="eyebrow">WORKER WALLET</p><div class="metric">$<?=number_format($balances['WORKER']??0,2)?></div><p class="muted">Approved task rewards and withdrawal movements.</p><a class="button primary" href="withdraw.php">Request withdrawal</a></article><article class="panel"><p class="eyebrow">ADVERTISER WALLET</p><div class="metric">$<?=number_format($balances['ADVERTISER']??0,2)?></div><p class="muted">Funds reserved for your advertising campaigns.</p><a class="button secondary" href="campaigns.php">Manage campaigns</a></article></section><?php foreach(['WORKER'=>'Worker transactions','ADVERTISER'=>'Advertiser transactions'] as $type=>$heading): ?><section class="panel" style="margin-top:22px"><h2><?=$heading?></h2><?php if(empty($entries[$type])): ?><p class="muted">No transactions yet.</p><?php else: ?><div style="overflow:auto"><table style="width:100%;border-collapse:collapse;text-align:left"><thead><tr><th>Type</th><th>Details</th><th>Amount</th><th>Date</th></tr></thead><tbody><?php foreach($entries[$type] as $entry): ?><tr><td><?=pf_e($entry['entry_type'])?></td><td><?=pf_e((string)$entry['description'])?></td><td><?=((float)$entry['amount']>=0?'+':'')?><?=pf_e($entry['currency'])?> <?=number_format((float)$entry['amount'],2)?></td><td><?=pf_e($entry['created_at'])?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endforeach; ?></main></body></html>
