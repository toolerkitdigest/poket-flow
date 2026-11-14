<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Reach the right audience with sponsored banners, native placements and business promotions across PoketFlow.">
    <meta name="theme-color" content="#ffffff">
    <title>PoketFlow — Advertising That Finds Its Place</title>
    <link rel="stylesheet" href="index.css?v=advertiser-refresh-1">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="index.php" aria-label="PoketFlow home">
            <span class="brand-mark">P</span>
            <span class="brand-name">Poket<span>Flow</span></span>
        </a>
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="primaryNav">
            <span></span><span></span><span></span>
        </button>
        <nav class="primary-nav" id="primaryNav" aria-label="Main navigation">
            <a href="#placements">Ad placements</a>
            <a href="#how-it-works">How it works</a>
            <a href="#for-businesses">For businesses</a>
            <a href="https://blog.poketflow.com/" target="_blank" rel="noopener">Blog</a>
        </nav>
        <div class="header-actions">
            <a class="btn btn-quiet" href="login.php">Log in</a>
            <a class="btn btn-primary" href="register.php">Advertise with us <span aria-hidden="true">→</span></a>
        </div>
    </div>
</header>

<main>
    <section class="hero">
        <div class="container hero-grid">
            <div class="hero-copy">
                <span class="eyebrow"><span class="eyebrow-dot"></span> A simpler way to promote your business</span>
                <h1>Put your brand <span>where people are already exploring.</span></h1>
                <p class="hero-lead">Promote your business, product or opportunity across PoketFlow and our content blog with clearly labelled advertising placements booked for the time you choose.</p>
                <div class="hero-actions">
                    <a class="btn btn-primary btn-large" href="register.php">Start advertising <span aria-hidden="true">→</span></a>
                    <a class="btn btn-outline btn-large" href="#placements">Explore ad spaces</a>
                </div>
                <div class="hero-points">
                    <span><i aria-hidden="true">✓</i> Clear placement options</span>
                    <span><i aria-hidden="true">✓</i> Time-based campaigns</span>
                    <span><i aria-hidden="true">✓</i> One advertiser account</span>
                </div>
            </div>
            <div class="hero-visual" aria-label="Illustration of advertising placements">
                <div class="visual-glow"></div>
                <div class="preview-window">
                    <div class="preview-top"><span></span><span></span><span></span><b>poketflow.com</b></div>
                    <div class="preview-content">
                        <div class="preview-nav"><strong>Poket<span>Flow</span></strong><small>Discover · Explore · Read</small></div>
                        <div class="preview-ad-label">ADVERTISEMENT</div>
                        <div class="preview-banner">
                            <div><small>YOUR BRAND, IN THE SPOTLIGHT</small><strong>Make your next<br>move count.</strong><em>Explore the offer ↗</em></div>
                            <div class="banner-art"><span class="art-circle"></span><span class="art-card"></span></div>
                        </div>
                        <div class="preview-lines"><span></span><span></span><span></span></div>
                        <div class="preview-bottom"><span></span><span></span><span></span></div>
                    </div>
                </div>
                <div class="floating-tag tag-top"><span class="tag-icon">▤</span><span><b>Display ads</b><small>Prominent placements</small></span></div>
                <div class="floating-tag tag-bottom"><span class="tag-icon tag-icon-blue">✦</span><span><b>Sponsored content</b><small>Native, clearly labelled</small></span></div>
            </div>
        </div>
    </section>

    <section class="trust-band">
        <div class="container trust-inner">
            <div><strong>One account</strong><span>Manage your advertising in one place</span></div>
            <div><strong>Flexible durations</strong><span>Choose a campaign period that suits you</span></div>
            <div><strong>Clear reporting</strong><span>See available impression and click data</span></div>
        </div>
    </section>

    <section class="section placements-section" id="placements">
        <div class="container">
            <div class="section-heading">
                <span class="eyebrow">WHERE YOUR AD CAN APPEAR</span>
                <h2>Choose the space that fits your goal.</h2>
                <p>Reach visitors across the main PoketFlow website and the PoketFlow blog. Available spaces and formats are managed by our advertising team.</p>
            </div>
            <div class="placement-grid">
                <article class="placement-card placement-featured">
                    <div class="placement-art art-home"><span class="art-label">ADVERTISEMENT</span><div class="art-banner"><b>Your brand<br>belongs here.</b><i>Discover more →</i></div></div>
                    <div class="placement-body"><span class="placement-type">MAIN WEBSITE</span><h3>Homepage banner</h3><p>Give your campaign a prominent position on the PoketFlow homepage.</p></div>
                </article>
                <article class="placement-card">
                    <div class="placement-art art-blog"><div class="mini-blog-head">PoketFlow <b>Journal</b></div><span class="art-label">SPONSORED</span><div class="mini-blog-ad"></div><div class="mini-blog-lines"></div></div>
                    <div class="placement-body"><span class="placement-type">BLOG</span><h3>Blog homepage</h3><p>Introduce your business to readers browsing the latest articles.</p></div>
                </article>
                <article class="placement-card">
                    <div class="placement-art art-article"><span class="art-label">ADVERTISEMENT</span><div class="article-lines"><i></i><i></i><i></i></div><div class="article-ad"></div><div class="article-lines short"><i></i><i></i></div></div>
                    <div class="placement-body"><span class="placement-type">ARTICLE PAGES</span><h3>In-article banners</h3><p>Place a relevant visual promotion between sections of an article.</p></div>
                </article>
                <article class="placement-card">
                    <div class="placement-art art-native"><span class="native-label">SPONSORED</span><div class="native-thumb"></div><div class="native-copy"><b>A business worth discovering</b><i>Promoted listing · Learn more</i></div></div>
                    <div class="placement-body"><span class="placement-type">NATIVE PROMOTION</span><h3>Sponsored listings</h3><p>Present a product, service or opportunity in a natural card format.</p></div>
                </article>
                <article class="placement-card">
                    <div class="placement-art art-sidebar"><div class="sidebar-paper"><span class="art-label">AD</span><div class="sidebar-banner"></div><div class="article-lines"><i></i><i></i><i></i></div></div></div>
                    <div class="placement-body"><span class="placement-type">BLOG SIDEBAR</span><h3>Sidebar banner</h3><p>Keep your promotion visible alongside selected article content.</p></div>
                </article>
                <article class="placement-card">
                    <div class="placement-art art-sponsored"><span class="sponsored-pill">SPONSORED STORY</span><div class="sponsored-title">A closer look at<br>what your business offers</div><div class="sponsored-meta">Promoted feature · PoketFlow</div></div>
                    <div class="placement-body"><span class="placement-type">CONTENT PROMOTION</span><h3>Sponsored articles</h3><p>Promote a story or business feature with an unmistakable sponsored label.</p></div>
                </article>
            </div>
            <p class="section-footnote">Not every format is available in every location. Campaigns are subject to placement availability and approval.</p>
        </div>
    </section>

    <section class="how-section section" id="how-it-works">
        <div class="container how-grid">
            <div class="section-heading align-left">
                <span class="eyebrow">GET STARTED IN A FEW STEPS</span>
                <h2>From idea to live campaign, without the guesswork.</h2>
                <p>PoketFlow keeps the process straightforward. You choose where and how long to advertise; our team reviews the creative before it appears.</p>
                <a class="text-link" href="register.php">Create your advertiser account <span>→</span></a>
            </div>
            <div class="steps-list">
                <article class="how-step"><span>01</span><div><h3>Create your account</h3><p>Register your business details and access your advertiser dashboard.</p></div></article>
                <article class="how-step"><span>02</span><div><h3>Choose your placements</h3><p>Select available ad spaces, campaign duration and the package that fits your plan.</p></div></article>
                <article class="how-step"><span>03</span><div><h3>Submit your advertisement</h3><p>Upload your banner or creative, add a destination link and send it for review.</p></div></article>
                <article class="how-step"><span>04</span><div><h3>Pay and go live</h3><p>Complete payment. Once approved and scheduled, your advertisement appears in its selected spaces.</p></div></article>
            </div>
        </div>
    </section>

    <section class="business-section section" id="for-businesses">
        <div class="container business-panel">
            <div><span class="eyebrow">BUILT FOR BUSINESSES</span><h2>Your next customers are discovering something new. Be part of it.</h2><p>Whether you are promoting a product, service, store, event or business opportunity, choose a PoketFlow placement that makes sense for your message.</p></div>
            <a class="btn btn-light btn-large" href="register.php">Advertise on PoketFlow <span aria-hidden="true">→</span></a>
        </div>
    </section>

    <section class="section faq-section" id="faq">
        <div class="container faq-layout">
            <div class="section-heading align-left"><span class="eyebrow">NEED TO KNOW</span><h2>Frequently asked questions.</h2><p>Some quick answers before you launch your first campaign.</p></div>
            <div class="faq-list">
                <details><summary>Who can advertise on PoketFlow?</summary><p>Businesses, brands, service providers and organisations with legitimate products, services or opportunities may apply. All advertisements are subject to review and our advertising rules.</p></details>
                <details><summary>How long can I run an advertisement?</summary><p>Campaign durations will be selected from the packages and scheduling options available for each placement. Options may include monthly and longer-term campaigns.</p></details>
                <details><summary>Can I choose where my advertisement appears?</summary><p>Yes. You can select from available placements on the main PoketFlow website and blog. Some packages may combine multiple placements.</p></details>
                <details><summary>When will my advertisement become visible?</summary><p>After payment is verified, your creative is reviewed and approved, and the campaign reaches its scheduled start date.</p></details>
                <details><summary>Are all advertising formats available immediately?</summary><p>We will make formats available as each placement is configured and tested. Intrusive formats are restricted and may not be offered.</p></details>
            </div>
        </div>
    </section>

    <section class="final-cta">
        <div class="container final-cta-inner"><div><span class="eyebrow">READY TO BE SEEN?</span><h2>Let's put your business in the right place.</h2><p>Create an advertiser account and explore the available PoketFlow placements.</p></div><a class="btn btn-primary btn-large" href="register.php">Get started <span aria-hidden="true">→</span></a></div>
    </section>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <div><a class="brand" href="index.php"><span class="brand-mark">P</span><span class="brand-name">Poket<span>Flow</span></span></a><p>Advertising spaces for businesses, presented with care for readers.</p></div>
        <div class="footer-links"><a href="#placements">Ad placements</a><a href="#how-it-works">How it works</a><a href="https://blog.poketflow.com/" target="_blank" rel="noopener">Blog</a><a href="login.php">Advertiser login</a></div>
        <small>© <?= date('Y') ?> PoketFlow. All rights reserved.</small>
    </div>
</footer>
<script>
(() => {
    const toggle = document.getElementById('menuToggle');
    const nav = document.getElementById('primaryNav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    });
    nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open menu');
    }));
})();
</script>
</body>
</html>
