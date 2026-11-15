<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
$admin=pf_require_admin($pdo);
$stats=[
 'members'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
 'active_members'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='ACTIVE'")->fetchColumn(),
 'pending_profiles'=>(int)$pdo->query("SELECT COUNT(*) FROM pf_advertiser_profiles WHERE status='PENDING'")->fetchColumn(),
 'active_advertisers'=>(int)$pdo->query("SELECT COUNT(*) FROM pf_advertiser_profiles WHERE status='ACTIVE'")->fetchColumn(),
 'campaign_review'=>(int)$pdo->query("SELECT COUNT(*) FROM pf_campaigns WHERE campaign_type IN ('SITE_DISPLAY','BLOG_SPONSORED') AND status='PENDING_REVIEW'")->fetchColumn(),
 'active_campaigns'=>(int)$pdo->query("SELECT COUNT(*) FROM pf_campaigns WHERE campaign_type IN ('SITE_DISPLAY','BLOG_SPONSORED') AND status='ACTIVE'")->fetchColumn(),
 'pending_funding'=>(int)$pdo->query("SELECT COUNT(*) FROM pf_funding_requests WHERE status='PENDING'")->fetchColumn(),
 'sync_failed'=>(int)$pdo->query("SELECT COUNT(*) FROM pf_blog_campaigns WHERE sync_status='FAILED'")->fetchColumn(),
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PoketFlow administration</title><link rel="stylesheet" href="../assets/marketplace.css"><style>.admin-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.admin-grid .panel{padding:20px}.admin-grid .metric{font-size:30px}@media(max-width:850px){.admin-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.admin-grid{grid-template-columns:1fr}}</style></head>
<body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="../dashboard.php">Advertiser dashboard</a><a href="advertisers.php">Advertisers</a><a href="placements.php">Placements & pricing</a><a href="finance.php">Finance & campaigns</a><a href="users.php">Members</a><a href="../logout.php">Sign out</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">PLATFORM CONTROL</p><h1>Advertising administration</h1><p class="muted">Manage advertiser access, campaign review, placements, pricing and payments. Legacy financial records remain preserved.</p></section>
<section class="admin-grid">
<?php foreach([['ALL MEMBERS','members'],['ACTIVE MEMBERS','active_members'],['ACTIVE ADVERTISERS','active_advertisers'],['PENDING ADVERTISERS','pending_profiles'],['CAMPAIGNS TO REVIEW','campaign_review'],['ACTIVE CAMPAIGNS','active_campaigns'],['PENDING FUNDING','pending_funding'],['BLOG SYNC FAILURES','sync_failed']] as [$label,$key]):?><article class="panel"><p class="eyebrow"><?=$label?></p><div class="metric"><?=number_format($stats[$key])?></div></article><?php endforeach;?>
</section><section class="panel" style="margin-top:24px"><h2>Administration tools</h2><p><a class="button primary" href="advertisers.php">Review advertisers</a> <a class="button" style="background:#fff;border:1px solid var(--line)" href="placements.php">Configure placements</a> <a class="button" style="background:#fff;border:1px solid var(--line)" href="finance.php">Review payments & campaigns</a></p><p class="small muted">Do not edit member passwords, emails, IDs, wallet balances or historical ledger records during advertiser moderation.</p></section>
</main></body></html>
