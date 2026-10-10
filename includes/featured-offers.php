<?php

declare(strict_types=1);

/**
 * PoketFlow Featured Offers
 *
 * Displays up to three eligible OGAds offers on the homepage.
 * Designed for guests and logged-in users.
 *
 * Eligibility is checked against the existing campaign database
 * and the same OGAds validation logic used by offers.php.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ogads.php';

// --------------------------------------------------
// SAFE OUTPUT
// --------------------------------------------------

if (!function_exists('featuredOfferEscape')) {
    function featuredOfferEscape($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

// --------------------------------------------------
// OFFER TITLE INITIAL
// --------------------------------------------------

if (!function_exists('featuredOfferInitial')) {
    function featuredOfferInitial(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            return 'O';
        }

        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8');
        }

        return strtoupper(substr($title, 0, 1));
    }
}

// --------------------------------------------------
// SHORT DESCRIPTION
// --------------------------------------------------

if (!function_exists('featuredOfferDescription')) {
    function featuredOfferDescription(
        string $description,
        int $limit = 105
    ): string {
        if (function_exists('cleanOgadsText')) {
            $description = cleanOgadsText($description);
        }

        $description = strip_tags($description);

        $description = preg_replace(
            '/\s+/',
            ' ',
            trim($description)
        ) ?? '';

        if ($description === '') {
            $description = 'Complete this offer to earn your reward.';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($description, 'UTF-8') > $limit) {
                $description = rtrim(
                    mb_substr($description, 0, $limit - 3, 'UTF-8')
                ) . '...';
            }
        } elseif (strlen($description) > $limit) {
            $description = rtrim(
                substr($description, 0, $limit - 3)
            ) . '...';
        }

        return $description;
    }
}

// --------------------------------------------------
// FORMAT REWARD
// --------------------------------------------------

if (!function_exists('featuredOfferMoney')) {
    function featuredOfferMoney(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}

// --------------------------------------------------
// OFFER DESTINATION
// --------------------------------------------------

if (!function_exists('featuredOfferLink')) {
    function featuredOfferLink(string $offerId): string
    {
        if (
            function_exists('isLoggedIn')
            && isLoggedIn()
        ) {
            return 'start-offer.php?offer_id=' . rawurlencode($offerId);
        }

        return 'register.php';
    }
}

// --------------------------------------------------
// IMAGE URL VALIDATION
// --------------------------------------------------

if (!function_exists('featuredOfferImageUrl')) {
    function featuredOfferImageUrl(string $imageUrl): string
    {
        $imageUrl = trim($imageUrl);

        if (
            $imageUrl === ''
            || !filter_var($imageUrl, FILTER_VALIDATE_URL)
        ) {
            return '';
        }

        $scheme = strtolower(
            (string) parse_url($imageUrl, PHP_URL_SCHEME)
        );

        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $imageUrl;
    }
}

// --------------------------------------------------
// FETCH AND VALIDATE OFFERS
// --------------------------------------------------

$featuredOffers = [];
$featuredOffersError = false;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException(
            'Database connection is unavailable.'
        );
    }

    $featuredIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $featuredUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $featuredLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    $featuredScheme = (
        !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    ) ? 'https' : 'http';

    $featuredHost = $_SERVER['HTTP_HOST'] ?? 'poketflow.com';

    // Use the current page as the request URL.
    $featuredSiteUrl = $featuredScheme
        . '://'
        . $featuredHost
        . ($_SERVER['REQUEST_URI'] ?? '/');

    $featuredNetworkId = getOgadsNetworkId($pdo);

    $featuredRawOffers = fetchOgadsOffers(
        $featuredIp,
        $featuredUserAgent,
        $featuredLanguage,
        $featuredSiteUrl,
        0,
        100
    );

    if (!is_array($featuredRawOffers)) {
        $featuredRawOffers = [];
    }

    // Find the campaign already registered in our database.
    $featuredCampaignLookup = $pdo->prepare(
        'SELECT id
         FROM campaigns
         WHERE network_id = ?
           AND external_offer_id = ?
         LIMIT 1'
    );

    $featuredEligibleOffers = [];

    foreach ($featuredRawOffers as $featuredOffer) {
        if (!is_array($featuredOffer)) {
            continue;
        }

        // ------------------------------------------
        // EXTERNAL OFFER ID
        // ------------------------------------------

        $featuredExternalId = trim(
            (string) ($featuredOffer['offerid'] ?? '')
        );

        if (
            $featuredExternalId === ''
            || !ctype_digit($featuredExternalId)
        ) {
            continue;
        }

        // ------------------------------------------
        // OFFER DETAILS
        // ------------------------------------------

        $featuredTitle = cleanOgadsText(
            $featuredOffer['name_short']
                ?? $featuredOffer['name']
                ?? 'OGAds Offer'
        );

        $featuredDescription = cleanOgadsText(
            $featuredOffer['description'] ?? ''
        );

        $featuredInstructions = cleanOgadsText(
            $featuredOffer['adcopy'] ?? ''
        );

        $featuredCategory = getOgadsOfferCategory(
            $featuredOffer
        );

        $featuredCountries = $featuredOffer['country'] ?? '';
        $featuredDevices = $featuredOffer['device'] ?? '';

        $featuredNetworkOfferUrl = trim(
            (string) ($featuredOffer['link'] ?? '')
        );

        $featuredImageUrl = featuredOfferImageUrl(
            (string) ($featuredOffer['picture'] ?? '')
        );

        $featuredNetworkPayout = round(
            (float) ($featuredOffer['payout'] ?? 0),
            2
        );

        // ------------------------------------------
        // BASIC VALIDATION
        // ------------------------------------------

        if (
            $featuredNetworkPayout <= 0
            || $featuredNetworkOfferUrl === ''
            || !filter_var(
                $featuredNetworkOfferUrl,
                FILTER_VALIDATE_URL
            )
        ) {
            continue;
        }

        $featuredLinkScheme = strtolower(
            (string) parse_url(
                $featuredNetworkOfferUrl,
                PHP_URL_SCHEME
            )
        );

        if (
            !in_array(
                $featuredLinkScheme,
                ['http', 'https'],
                true
            )
        ) {
            continue;
        }

        // ------------------------------------------
        // OGADS SAFETY CHECK
        // ------------------------------------------

        if (!isOgadsOfferSafe($pdo, $featuredOffer)) {
            continue;
        }

        // ------------------------------------------
        // CAMPAIGN MUST EXIST IN OUR DATABASE
        // ------------------------------------------

        $featuredCampaignLookup->execute([
            $featuredNetworkId,
            $featuredExternalId,
        ]);

        $featuredCampaignId = $featuredCampaignLookup->fetchColumn();

        if (!$featuredCampaignId) {
            continue;
        }

        $featuredDatabaseCampaign = getCampaign(
            $pdo,
            (int) $featuredCampaignId
        );

        if (!$featuredDatabaseCampaign) {
            continue;
        }

        // ------------------------------------------
        // CAMPAIGN MUST BE ALLOWED
        // ------------------------------------------

        if (
            !isCampaignAllowed(
                $pdo,
                $featuredDatabaseCampaign
            )
        ) {
            continue;
        }

        // ------------------------------------------
        // INCENTIVE ELIGIBILITY
        // ------------------------------------------

        if (
            !isset($featuredDatabaseCampaign['incentive_allowed'])
            || (int) $featuredDatabaseCampaign['incentive_allowed'] !== 1
        ) {
            continue;
        }

        // ------------------------------------------
        // CALCULATE THE ACTUAL MEMBER REWARD
        // ------------------------------------------

        $featuredRewards = calculateOgadsReward(
            $pdo,
            $featuredNetworkPayout
        );

        $featuredWorkerReward = (float) (
            $featuredRewards['worker_reward'] ?? 0
        );

        if ($featuredWorkerReward <= 0) {
            continue;
        }

        // ------------------------------------------
        // NORMALIZED OFFER
        // Keep the same structure used by offers.php.
        // ------------------------------------------

        $featuredEligibleOffers[] = [
            'id' => $featuredExternalId,
            'campaign_id' => (int) $featuredCampaignId,
            'source_type' => 'CPA_NETWORK',
            'network' => 'ogads',
            'network_id' => $featuredNetworkId,
            'external_offer_id' => $featuredExternalId,
            'network_offer_url' => $featuredNetworkOfferUrl,
            'image_url' => $featuredImageUrl,
            'title' => $featuredTitle,
            'description' => $featuredDescription,
            'category' => $featuredCategory,
            'instructions' => $featuredInstructions,
            'network_payout' => $featuredNetworkPayout,
            'reward_rate' => (float) (
                $featuredRewards['reward_rate'] ?? 0
            ),
            'worker_reward' => $featuredWorkerReward,
            'platform_margin' => (float) (
                $featuredRewards['platform_margin'] ?? 0
            ),
            'countries' => $featuredCountries,
            'devices' => $featuredDevices,
            'os' => '',
            'incentive_allowed' => 1,
            'status' => 'ACTIVE',
            'approval_status' => 'APPROVED',
        ];
    }

    // Preserve compatibility with start-offer.php,
    // which may use the normalized offers stored in the session.
    $_SESSION['ogads_offers'] = $featuredEligibleOffers;

    // Display no more than three cards on the homepage.
    $featuredOffers = array_slice(
        $featuredEligibleOffers,
        0,
        4
    );

} catch (Throwable $featuredException) {
    error_log(
        'PoketFlow featured offers error: '
        . $featuredException->getMessage()
    );

    $featuredOffersError = true;
    $featuredOffers = [];
}

// --------------------------------------------------
// RENDER FEATURED OFFERS
// --------------------------------------------------

?>

<section
    class="pf-featured-offers"
    id="featured-offers"
    aria-labelledby="pf-featured-heading"
>
    <div class="pf-featured-container">

        <div class="pf-featured-heading">
            <div class="pf-featured-heading-copy">
                <span class="pf-featured-eyebrow">
                    EARN WITH POKETFLOW
                </span>

                <h2 id="pf-featured-heading">
                    Featured Offers
                </h2>

                <p>
                    Complete offers and earn rewards for your effort.
                </p>
            </div>

            <a
                class="pf-featured-view-all"
                href="<?= featuredOfferEscape(
                    function_exists('isLoggedIn') && isLoggedIn()
                        ? 'offers.php'
                        : 'register.php'
                ) ?>"
            >
                View offers
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <?php if (!empty($featuredOffers)): ?>

            <div class="pf-featured-grid">

                <?php foreach ($featuredOffers as $featuredCard): ?>

                    <?php
                    $cardTitle = trim(
                        (string) ($featuredCard['title'] ?? '')
                    );

                    if ($cardTitle === '') {
                        $cardTitle = 'Featured Offer';
                    }

                    $cardDescription = featuredOfferDescription(
                        (string) ($featuredCard['description'] ?? ''),
                        105
                    );

                    $cardReward = (float) (
                        $featuredCard['worker_reward'] ?? 0
                    );

                    $cardImage = featuredOfferImageUrl(
                        (string) ($featuredCard['image_url'] ?? '')
                    );

                    $cardCategory = trim(
                        (string) ($featuredCard['category'] ?? '')
                    );

                    if ($cardCategory === '') {
                        $cardCategory = 'Special Offer';
                    }

                    $cardOfferId = (string) (
                        $featuredCard['external_offer_id']
                            ?? $featuredCard['id']
                            ?? ''
                    );

                    $cardLink = featuredOfferLink($cardOfferId);
                    ?>

                    <article class="pf-featured-card">

                        <div class="pf-featured-image-wrap">

                            <?php if ($cardImage !== ''): ?>

                                <img
                                    class="pf-featured-image"
                                    src="<?= featuredOfferEscape($cardImage) ?>"
                                    alt="<?= featuredOfferEscape($cardTitle) ?>"
                                    loading="lazy"
                                    referrerpolicy="no-referrer"
                                >

                            <?php else: ?>

                                <div
                                    class="pf-featured-fallback"
                                    aria-hidden="true"
                                >
                                    <?= featuredOfferEscape(
                                        featuredOfferInitial($cardTitle)
                                    ) ?>
                                </div>

                            <?php endif; ?>

                            <span class="pf-featured-category">
                                <?= featuredOfferEscape($cardCategory) ?>
                            </span>

                        </div>

                        <div class="pf-featured-content">

                            <h3 class="pf-featured-title">
                                <?= featuredOfferEscape($cardTitle) ?>
                            </h3>

                            <p class="pf-featured-description">
                                <?= featuredOfferEscape($cardDescription) ?>
                            </p>

                            <div class="pf-featured-reward">
                                <span class="pf-featured-reward-label">
                                    YOUR REWARD
                                </span>

                                <strong class="pf-featured-reward-amount">
                                    <?= featuredOfferEscape(
                                        featuredOfferMoney($cardReward)
                                    ) ?>
                                </strong>
                            </div>

                            <a
                                class="pf-featured-button"
                                href="<?= featuredOfferEscape($cardLink) ?>"
                            >
                                <?= (
                                    function_exists('isLoggedIn')
                                    && isLoggedIn()
                                )
                                    ? 'Start Offer'
                                    : 'Join to Earn'
                                ?>

                                <span aria-hidden="true">&rarr;</span>
                            </a>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="pf-featured-empty">

                <div class="pf-featured-empty-icon" aria-hidden="true">
                    ✦
                </div>

                <h3>
                    New rewards are on the way
                </h3>

                <p>
                    We couldn't find any eligible featured offers right now.
                    Please check back soon.
                </p>

                <?php if (!$featuredOffersError): ?>

                    <a
                        class="pf-featured-button"
                        href="<?= featuredOfferEscape(
                            function_exists('isLoggedIn') && isLoggedIn()
                                ? 'offers.php'
                                : 'register.php'
                        ) ?>"
                    >
                        Explore PoketFlow
                        <span aria-hidden="true">&rarr;</span>
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>
</section>
