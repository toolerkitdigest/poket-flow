<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$member = pf_user($pdo);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Earn rewards by completing real tasks, or advertise your business to the PoketFlow community. One account, two ways to grow.">
<title>PoketFlow | Earn and Advertise</title>
<link rel="stylesheet" href="assets/marketplace.css">
<style>
.hero{max-width:1160px;margin:0 auto;padding:90px 22px 74px;display:grid;grid-template-columns:1.15fr .85fr;gap:50px;align-items:center}.hero h1{font-size:clamp(42px,6vw,68px);line-height:1.02;letter-spacing:-.055em;margin:12px 0 20px}.hero h1 span{color:var(--green)}.hero p{font-size:17px;max-width:600px}.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:28px}.secondary{border:1px solid var(--line);background:#fff;color:var(--ink)}.visual{background:linear-gradient(145deg,#e8fff3,#f4fffb);border:1px solid #ccefe0;border-radius:30px;padding:28px;min-height:330px;display:flex;flex-direction:column;justify-content:center;gap:16px}.visual-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:19px;box-shadow:0 12px 28px rgba(16,80,55,.06)}.visual-card strong{display:block;font-size:20px;margin:5px 0}.visual-card small{color:var(--muted)}.feature-grid{max-width:1160px;margin:0 auto;padding:0 22px 70px;display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.feature-grid .panel{padding:24px}.footer{border-top:1px solid var(--line);padding:28px 22px;text-align:center;color:var(--muted);font-size:13px}@media(max-width:760px){.hero{grid-template-columns:1fr;padding-top:58px}.visual{min-height:260px}.feature-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="#how">How it works</a><a href="#advertise">Advertise</a><?php if($member): ?><a class="button primary" href="dashboard.php">My dashboard</a><?php else: ?><a href="login.php">Sign in</a><a class="button primary" href="register.php">Get started</a><?php endif; ?></nav></header>
<main>
<section class="hero">
<div><p class="eyebrow">ONE ACCOUNT · TWO OPPORTUNITIES</p><h1>Earn rewards.<br><span>Grow your reach.</span></h1><p class="muted">PoketFlow connects people who want to earn with advertisers who need real people to complete tasks and discover their products or services.</p><div class="hero-actions"><a class="button primary" href="tasks.php">Explore tasks</a><a class="button secondary" href="campaigns.php">Advertise on PoketFlow</a></div></div>
<div class="visual"><div class="visual-card"><small>EARN MODE</small><strong>Complete tasks and get rewarded</strong><small>Clear instructions · Evidence submission · Approval-based rewards</small></div><div class="visual-card"><small>ADVERTISE MODE</small><strong>Reach an engaged community</strong><small>Campaign budgets · Performance tracking · Sponsored placements</small></div></div>
</section>
<section id="how" class="feature-grid"><article class="panel"><p class="eyebrow">01 · JOIN</p><h2>One account</h2><p class="muted">Use one login to participate as a worker, an advertiser, or both.</p></article><article class="panel"><p class="eyebrow">02 · PARTICIPATE</p><h2>Real task workflows</h2><p class="muted">Advertisers publish instructions and workers submit evidence for review.</p></article><article class="panel" id="advertise"><p class="eyebrow">03 · GROW</p><h2>Sponsored reach</h2><p class="muted">Promote eligible products and services through worker campaigns or clearly labeled blog placements.</p></article></section>
</main>
<footer class="footer">© <?= date('Y') ?> PoketFlow. Sponsored content is identified clearly. Prohibited categories include gambling, adult content, casinos and alcohol.</footer>
</body>
</html>
