<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow — Dynamic Featured Offers
|--------------------------------------------------------------------------
| This component:
| - Fetches real OGAds offers for the current visitor
| - Uses the existing OGAds safety filters
| - Uses the existing reward calculation
| - Shows only valid offers
| - Sends guests to register.php
| - Sends logged-in users to start-offer.php
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ogads.php';


/*
|--------------------------------------------------------------------------
| Visitor information
|--------------------------------------------------------------------------
*/

$featuredIp = $_SERVER['REMOTE_ADDR'] ?? '';

$featuredUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$featuredLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

$featuredScheme =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

$featuredHost =
    $_SERVER['HTTP_HOST']
        ?? 'poketflow.com';

$featuredSite =
    $featuredScheme
    . '://'
    . $featuredHost
    . '/';


/*
|--------------------------------------------------------------------------
| Fetch live OGAds offers
|--------------------------------------------------------------------------
*/

$featuredRawOffers = [];

try {

    $featuredRawOffers = fetchOgadsOffers(
        $featuredIp,
        $featuredUserAgent,
        $featuredLanguage,
        $featuredSite,
        0,
        20
    );

} catch (Throwable $e) {

    $featuredRawOffers = [];

}


/*
|--------------------------------------------------------------------------
| Prepare offers for homepage
|--------------------------------------------------------------------------
*/

$featuredOffers = [];

foreach ($featuredRawOffers as $offer) {

    if (!is_array($offer)) {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Offer ID
    |--------------------------------------------------------------------------
    */

    $externalOfferId = trim(
        (string) (
            $offer['offerid']
            ?? $offer['offer_id']
            ?? $offer['id']
            ?? ''
        )
    );

    if ($externalOfferId === '') {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Basic offer information
    |--------------------------------------------------------------------------
    */

    $title = trim(
        (string) (
            $offer['name_short']
            ?? $offer['name']
            ?? 'Special Offer'
        )
    );

    $description = cleanOgadsText(
        (string) (
            $offer['description']
            ?? $offer['adcopy']
            ?? ''
        )
    );

    $category = getOgadsOfferCategory($offer);


    /*
    |--------------------------------------------------------------------------
    | Network payout
    |--------------------------------------------------------------------------
    */

    $networkPayout = (float) (
        $offer['payout']
        ?? $offer['amount']
        ?? 0
    );

    if ($networkPayout <= 0) {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Offer URL
    |--------------------------------------------------------------------------
    */

    $offerUrl = trim(
        (string) (
            $offer['link']
            ?? $offer['url']
            ?? ''
        )
    );

    if ($offerUrl === '') {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Safety filtering
    |--------------------------------------------------------------------------
    */

    if (!isOgadsOfferSafe($pdo, $offer)) {
        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Calculate worker reward
    |--------------------------------------------------------------------------
    */

    $rewardData = calculateOgadsReward(
        $pdo,
        $networkPayout
    );

    $workerReward = (float) (
        $rewardData['worker_reward']
        ?? $rewardData['workerReward']
        ?? 0
    );

    $platformMargin = (float) (
        $rewardData['platform_margin']
        ?? $rewardData['platformMargin']
        ?? 0
    );


    /*
    |--------------------------------------------------------------------------
    | Image
    |--------------------------------------------------------------------------
    */

    $imageUrl = trim(
        (string) (
            $offer['picture']
            ?? $offer['image']
            ?? $offer['thumbnail']
            ?? ''
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Country / device data
    |--------------------------------------------------------------------------
    | These are intentionally retained internally for future
    | filtering/personalization, but are NOT displayed on the
    | homepage card.
    |--------------------------------------------------------------------------
    */

    $countries = trim(
        (string) (
            $offer['country']
            ?? $offer['countries']
            ?? ''
        )
    );

    $devices = trim(
        (string) (
            $offer['device']
            ?? $offer['devices']
            ?? ''
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Build homepage offer
    |--------------------------------------------------------------------------
    */

    $featuredOffers[] = [
        'id' => $externalOfferId,

        'title' => $title,

        'description' => $description,

        'category' => $category,

        'network_payout' => $networkPayout,

        'worker_reward' => $workerReward,

        'platform_margin' => $platformMargin,

        'countries' => $countries,

        'devices' => $devices,

        'image' => $imageUrl,

        'url' => $offerUrl,
    ];


    /*
    |--------------------------------------------------------------------------
    | Homepage limit
    |--------------------------------------------------------------------------
    */

    if (count($featuredOffers) >= 6) {
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('featuredOfferInitial')) {

    function featuredOfferInitial(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            return 'P';
        }

        return strtoupper(
            function_exists('mb_substr')
                ? mb_substr($title, 0, 1)
                : substr($title, 0, 1)
        );
    }
}


if (!function_exists('featuredOfferDescription')) {

    function featuredOfferDescription(
        string $description,
        int $limit = 105
    ): string {

        $description = cleanOgadsText($description);

        if ($description === '') {
            return 'Complete this offer and receive your reward.';
        }

        if (function_exists('mb_strlen')) {

            if (mb_strlen($description) <= $limit) {
                return $description;
            }

            return rtrim(
                mb_substr($description, 0, $limit - 3)
            ) . '...';
        }

        if (strlen($description) <= $limit) {
            return $description;
        }

        return rtrim(
            substr($description, 0, $limit - 3)
        ) . '...';
    }
}


if (!function_exists('featuredOfferMoney')) {

    function featuredOfferMoney(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}


if (!function_exists('featuredOfferLink')) {

    function featuredOfferLink(string $offerId): string
    {
        /*
        |--------------------------------------------------------------------------
        | Guests must register first.
        |--------------------------------------------------------------------------
        */

        if (!function_exists('isLoggedIn') || !isLoggedIn()) {

            return 'register.php';
        }


        /*
        |--------------------------------------------------------------------------
        | Logged-in users can start the offer.
        |--------------------------------------------------------------------------
        */

        return 'start-offer.php?offer_id='
            . rawurlencode($offerId);
    }
}

?>

<section
    class="pf-featured-offers"
    id="featured-offers"
>

    <div class="pf-featured-container">


        <!-- =====================================================
             SECTION HEADER
        ====================================================== -->

        <div class="pf-featured-header">

            <div class="pf-featured-heading-copy">

                <span class="pf-featured-eyebrow">
                    LIVE REWARDS
                </span>

                <h2>
                    Start discovering available rewards
                </h2>

                <p>
                    Explore real offers available through PoketFlow.
                    Complete an offer and receive the reward shown.
                </p>

            </div>


            <a
                href="offers.php"
                class="pf-featured-view-all"
            >
                View all offers
                <span aria-hidden="true">→</span>
            </a>

        </div>


        <!-- =====================================================
             OFFER GRID
        ====================================================== -->

        <?php if (!empty($featuredOffers)): ?>

            <div class="pf-featured-grid">

                <?php foreach ($featuredOffers as $featuredOffer): ?>

                    <?php

                    $offerTitle =
                        htmlspecialchars(
                            $featuredOffer['title'],
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $offerDescription =
                        htmlspecialchars(
                            featuredOfferDescription(
                                $featuredOffer['description']
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $offerCategory =
                        htmlspecialchars(
                            $featuredOffer['category'],
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $offerImage =
                        htmlspecialchars(
                            $featuredOffer['image'],
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $offerLink =
                        htmlspecialchars(
                            featuredOfferLink(
                                $featuredOffer['id']
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    ?>

                    <article class="pf-featured-card">

                        <!-- =================================================
                             OFFER IMAGE
                        ================================================== -->

                        <div class="pf-featured-card-top">

                            <div class="pf-featured-image">

                                <?php if ($offerImage !== ''): ?>

                                    <img
                                        src="<?= $offerImage ?>"
                                        alt=""
                                        loading="lazy"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"
                                    >

                                    <span
                                        class="pf-featured-fallback"
                                        style="display:none;"
                                    >
                                        <?= htmlspecialchars(
                                            featuredOfferInitial(
                                                $featuredOffer['title']
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="pf-featured-fallback">

                                        <?= htmlspecialchars(
                                            featuredOfferInitial(
                                                $featuredOffer['title']
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>


                        <!-- =================================================
                             OFFER CONTENT
                        ================================================== -->

                        <div class="pf-featured-content">

                            <div class="pf-featured-meta">

                                <span class="pf-featured-category">
                                    <?= $offerCategory ?>
                                </span>

                                <span class="pf-featured-source">
                                    OGAds
                                </span>

                            </div>


                            <h3 class="pf-featured-title">
                                <?= $offerTitle ?>
                            </h3>


                            <p class="pf-featured-description">
                                <?= $offerDescription ?>
                            </p>


                            <!-- =================================================
                                 REWARD ACTION
                            ================================================== -->

                            <div class="pf-featured-bottom">

                                <div class="pf-featured-reward-box">

                                    <span class="pf-featured-reward-label">
                                        YOU CAN EARN
                                    </span>

                                    <strong class="pf-featured-reward">
                                        <?= featuredOfferMoney(
                                            (float) $featuredOffer['worker_reward']
                                        ) ?>
                                    </strong>

                                    <span class="pf-featured-reward-note">
                                        Real reward
                                    </span>

                                </div>


                                <a
                                    href="<?= $offerLink ?>"
                                    class="pf-featured-button"
                                >

                                    <span>
                                        <?= (
                                            function_exists('isLoggedIn')
                                            && isLoggedIn()
                                        )
                                            ? 'Start Offer'
                                            : 'Get Started'
                                        ?>
                                    </span>

                                    <span
                                        class="pf-featured-button-arrow"
                                        aria-hidden="true"
                                    >
                                        →
                                    </span>

                                </a>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>


            <!-- =================================================
                 VIEW ALL
            ================================================== -->

            <div class="pf-featured-footer">

                <a href="offers.php">

                    Explore all available offers

                    <span aria-hidden="true">
                        →
                    </span>

                </a>

            </div>


        <?php else: ?>


            <!-- =================================================
                 EMPTY STATE
            ================================================== -->

            <div class="pf-featured-empty">

                <div class="pf-featured-empty-icon">
                    ✨
                </div>

                <h3>
                    New offers are arriving
                </h3>

                <p>
                    We couldn't find suitable offers for your
                    current device or location right now.
                    Please check again shortly.
                </p>

                <a
                    href="register.php"
                    class="pf-featured-empty-button"
                >
                    Create Your Free Account
                </a>

            </div>

        <?php endif; ?>

    </div>

</section>
