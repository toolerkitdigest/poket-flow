<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$member=pf_user($pdo);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Promote your business, product or service with clearly labelled advertising placements across PoketFlow and its editorial blog.">
<title>PoketFlow | Advertising that reaches people</title><link rel="stylesheet" href="assets/marketplace.css">
<style>
.hero{max-width:1160px;margin:0 auto;padding:82px 22px 58px;display:grid;grid-template-columns:1.1fr .9fr;gap:42px;align-items:center}
.hero h1{font-size:clamp(40px,6vw,66px);line-height:1.04;letter-spacing:-.05em;margin:12px 0 18px}.hero h1 span{color:var(--green)}
.hero p{font-size:17px;max-width:620px}.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:26px}
.hero-visual{background:linear-gradient(145deg,#e8fff3,#f4fffb);border:1px solid #ccefe0;border-radius:28px;padding:24px;display:grid;gap:14px}
.hero-card{background:#fff;border:1px solid var(--line);border-radius:17px;padding:18px;box-shadow:0 12px 28px rgba(16,80,55,.06)}
.hero-card strong{display:block;font-size:18px;margin:6px 0}.hero-card small{color:var(--muted)}
.feature-grid{max-width:1160px;margin:0 auto;padding:12px 22px 62px;display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.feature-grid .panel{padding:24px}.pf-sponsored-ad{max-width:1100px;margin:20px auto;padding:18px;border:1px solid var(--line);border-radius:16px;background:#fff}
.pf-ad-label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:800;color:var(--green);margin-bottom:8px}
.pf-sponsored-ad a{display:grid;gap:8px}.footer{border-top:1px solid var(--line);padding:26px;text-align:center;color:var(--muted);font-size:13px}
@media(max-width:760px){.hero{grid-template-columns:1fr;padding-top:52px}.feature-grid{grid-template-columns:1fr}}
</style></head>
<body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="opportunities.php">Opportunities</a><a href="#advertise">Advertise</a><?php if($member):?><a class="button primary" href="dashboard.php">Dashboard</a><?php else:?><a href="login.php">Sign in</a><a class="button primary" href="register.php">Advertise with us</a><?php endif;?></nav></header>
<main>
<?= pf_render_site_ad($pdo,'main_home_top') ?>
<section class="hero"><div><p class="eyebrow">ONE PLATFORM · MULTIPLE PLACEMENTS</p><h1>Put your brand<br><span>in the right places.</span></h1>
<p class="muted">PoketFlow helps businesses reach readers and visitors through clearly labelled banner, native and sponsored-content placements across our main website and editorial blog.</p>
<div class="hero-actions"><a class="button primary" href="<?= $member?'dashboard.php':'register.php' ?>">Start advertising</a><a class="button" style="background:#fff;border:1px solid var(--line)" href="opportunities.php">Explore opportunities</a></div></div>
<div class="hero-visual"><div class="hero-card"><small>MAIN WEBSITE</small><strong>Banner and native placements</strong><small>Promote your business across eligible PoketFlow pages</small></div><div class="hero-card"><small>EDITORIAL BLOG</small><strong>In-article and native placements</strong><small>Reach readers with clearly identified sponsored content</small></div><div class="hero-card"><small>CAMPAIGN CONTROL</small><strong>One advertiser dashboard</strong><small>Choose placement, duration and budget; track impressions and clicks</small></div></div></section>
<?= pf_render_site_ad($pdo,'main_home_inline') ?>
<section id="advertise" class="feature-grid"><article class="panel"><p class="eyebrow">01 · CREATE</p><h2>Choose a placement</h2><p class="muted">Select an available banner or native sponsored placement.</p></article><article class="panel"><p class="eyebrow">02 · SCHEDULE</p><h2>Set your duration</h2><p class="muted">Choose a campaign period and see the configured price before submitting.</p></article><article class="panel"><p class="eyebrow">03 · MEASURE</p><h2>Track performance</h2><p class="muted">Review campaign status, impressions and clicks in one place.</p></article></section>
<?= pf_render_site_ad($pdo,'site_footer_banner') ?>
</main><footer class="footer">© <?=date('Y')?> PoketFlow. All advertisements are reviewed and labelled. Gambling, adult content, casinos and alcohol are prohibited.</footer>
</body></html>
