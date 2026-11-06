<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/app.php';
$admin = pf_require_admin($pdo);

$stats = [
    'members' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active_members' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='ACTIVE'")->fetchColumn(),
    'pending_members' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status='PENDING'")->fetchColumn(),
    'active_tasks' => (int) $pdo->query("SELECT COUNT(*) FROM pf_campaigns WHERE campaign_type='WORKER_TASK' AND status='ACTIVE'")->fetchColumn(),
    'campaign_review' => (int) $pdo->query("SELECT COUNT(*) FROM pf_campaigns WHERE status='PENDING_REVIEW'")->fetchColumn(),
    'pending_submissions' => (int) $pdo->query("SELECT COUNT(*) FROM pf_submissions WHERE status='PENDING'")->fetchColumn(),
    'pending_funding' => (int) $pdo->query("SELECT COUNT(*) FROM pf_funding_requests WHERE status='PENDING'")->fetchColumn(),
    'pending_withdrawals' => (int) $pdo->query("SELECT COUNT(*) FROM pf_withdrawals WHERE status='PENDING'")->fetchColumn(),
    'open_disputes' => (int) $pdo->query("SELECT COUNT(*) FROM pf_submission_disputes WHERE status IN ('OPEN','UNDER_REVIEW')")->fetchColumn(),
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administration | PoketFlow</title><link rel="stylesheet" href="../assets/marketplace.css"><style>.admin-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.admin-grid .panel{padding:20px}.admin-grid .metric{font-size:30px}@media(max-width:850px){.admin-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.admin-grid{grid-template-columns:1fr}}</style></head>
<body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="../dashboard.php">Member dashboard</a><a href="users.php">Members</a><a href="finance.php">Finance</a><a href="disputes.php">Appeals</a><a href="../logout.php">Sign out</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">PLATFORM CONTROL</p><h1>Administrator dashboard</h1><p class="muted">Monitor members, worker tasks, submissions and financial queues.</p></section>
<section class="admin-grid">
<article class="panel"><p class="eyebrow">ALL MEMBERS</p><div class="metric"><?=number_format($stats['members'])?></div><p class="muted">Registered accounts</p></article>
<article class="panel"><p class="eyebrow">ACTIVE MEMBERS</p><div class="metric"><?=number_format($stats['active_members'])?></div><p class="muted">Can sign in</p></article>
<article class="panel"><p class="eyebrow">PENDING MEMBERS</p><div class="metric"><?=number_format($stats['pending_members'])?></div><p class="muted">Awaiting verification</p></article>
<article class="panel"><p class="eyebrow">ACTIVE TASKS</p><div class="metric"><?=number_format($stats['active_tasks'])?></div><p class="muted">Visible to workers</p></article>
<article class="panel"><p class="eyebrow">CAMPAIGN REVIEW</p><div class="metric"><?=number_format($stats['campaign_review'])?></div><p class="muted">Awaiting moderation</p></article>
<article class="panel"><p class="eyebrow">SUBMISSIONS</p><div class="metric"><?=number_format($stats['pending_submissions'])?></div><p class="muted">Awaiting advertiser review</p></article>
<article class="panel"><p class="eyebrow">FUNDING REQUESTS</p><div class="metric"><?=number_format($stats['pending_funding'])?></div><p class="muted">Awaiting payment verification</p></article>
<article class="panel"><p class="eyebrow">WITHDRAWALS</p><div class="metric"><?=number_format($stats['pending_withdrawals'])?></div><p class="muted">Awaiting payout</p></article>
<article class="panel"><p class="eyebrow">WORKER APPEALS</p><div class="metric"><?=number_format($stats['open_disputes'])?></div><p class="muted">Awaiting administrator review</p></article>
</section>
<section class="panel" style="margin-top:24px"><h2>Administration tools</h2><p><a class="button primary" href="users.php">Manage members</a> <a class="button secondary" href="finance.php">Review finance & campaigns</a> <a class="button secondary" href="disputes.php">Review worker appeals</a></p><p class="small muted">Member status changes are audited. Existing passwords, email addresses and wallet history are not edited here.</p></section>
</main></body></html>
