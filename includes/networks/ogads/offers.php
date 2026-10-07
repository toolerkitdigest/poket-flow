<?php

declare(strict_types=1);

/**
 * PoketFlow
 * OGAds Network Offers Module
 *
 * PHP 8.3
 *
 * Responsibilities:
 * - Fetch visitor-specific OGAds offers
 * - Apply PoketFlow safety filters
 * - Synchronize offers into campaigns
 * - Preserve administrator status/approval decisions
 * - Return only ACTIVE + APPROVED campaigns
 */

require_once dirname(__DIR__, 2) . '/ogads.php';


/**
 * Get visitor information required by OGAds.
 */
function getOgadsVisitorContext(): array
{
    $ip = isset($_SERVER['REMOTE_ADDR'])
        ? trim((string) $_SERVER['REMOTE_ADDR'])
        : '';

    $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
        ? trim((string) $_SERVER['HTTP_USER_AGENT'])
        : '';

    $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        ? trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE'])
        : '';

    $scheme = (
        isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    )
        ? 'https'
        : 'http';

    $host = isset($_SERVER['HTTP_HOST'])
        ? trim((string) $_SERVER['HTTP_HOST'])
        : '';

    $site = $host !== ''
        ? $scheme . '://' . $host
        : 'https://poketflow.com';

    return [
        'ip' => $ip,
        'user_agent' => $userAgent,
        'language' => $language,
        'site' => $site,
    ];
}


/**
 * Synchronize one OGAds offer into campaigns.
 *
 * IMPORTANT:
 * Existing status and approval_status are deliberately
 * NOT modified here.
 */
function syncOgadsDisplayOffer(
    PDO $pdo,
    int $networkId,
    array $offer
): ?int {

    $externalOfferId = trim(
        (string) ($offer['offerid'] ?? '')
    );

    if ($externalOfferId === '') {
        return null;
    }


    $title = cleanOgadsText(
        $offer['name_short']
            ?? $offer['name']
            ?? 'OGAds Offer'
    );

    $description = cleanOgadsText(
        $offer['description'] ?? ''
    );

    $instructions = cleanOgadsText(
        $offer['adcopy'] ?? ''
    );

    $category = getOgadsOfferCategory(
        $offer
    );

    $countries = trim(
        (string) ($offer['country'] ?? '')
    );

    $devices = trim(
        (string) ($offer['device'] ?? '')
    );

    $networkOfferUrl = trim(
        (string) ($offer['link'] ?? '')
    );

    $imageUrl = trim(
        (string) ($offer['picture'] ?? '')
    );

    $networkPayout = round(
        (float) ($offer['payout'] ?? 0),
        2
    );


    if (
        $title === ''
        || $networkOfferUrl === ''
        || $networkPayout <= 0
    ) {
        return null;
    }


    /*
     * Apply the existing PoketFlow safety filter.
     */
    if (!isOgadsOfferSafe($pdo, $offer)) {
        return null;
    }


    $rewards = calculateOgadsReward(
        $pdo,
        $networkPayout
    );


    /*
     * Check whether the campaign already exists.
     */
    $stmt = $pdo->prepare(
        'SELECT id
         FROM campaigns
         WHERE network_id = ?
           AND external_offer_id = ?
         LIMIT 1'
    );

    $stmt->execute([
        $networkId,
        $externalOfferId,
    ]);

    $existingId = $stmt->fetchColumn();


    /*
     * Existing campaign:
     *
     * Update operational/network information only.
     *
     * We intentionally DO NOT update:
     * - status
     * - approval_status
     */
    if ($existingId !== false) {

        $stmt = $pdo->prepare(
            'UPDATE campaigns
             SET
                title = ?,
                description = ?,
                category = ?,
                instructions = ?,
                network_payout = ?,
                reward_rate = ?,
                worker_reward = ?,
                platform_margin = ?,
                countries = ?,
                devices = ?,
                network_offer_url = ?,
                image_url = ?,
                incentive_allowed = 1,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );

        $stmt->execute([
            $title,
            $description,
            $category,
            $instructions,
            $networkPayout,
            $rewards['reward_rate'],
            $rewards['worker_reward'],
            $rewards['platform_margin'],
            $countries,
            $devices,
            $networkOfferUrl,
            $imageUrl,
            (int) $existingId,
        ]);

        return (int) $existingId;
    }


    /*
     * New network offers require administrator approval.
     *
     * Therefore:
     * status = ACTIVE
     * approval_status = PENDING
     */
    $stmt = $pdo->prepare(
        'INSERT INTO campaigns (
            source_type,
            advertiser_id,
            network_id,
            external_offer_id,
            network_offer_url,
            image_url,
            title,
            description,
            category,
            instructions,
            network_payout,
            reward_rate,
            worker_reward,
            platform_margin,
            countries,
            devices,
            os,
            incentive_allowed,
            status,
            approval_status,
            start_at,
            end_at,
            created_at,
            updated_at
        )
        VALUES (
            "CPA_NETWORK",
            NULL,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            "",
            1,
            "ACTIVE",
            "PENDING",
            NULL,
            NULL,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )'
    );

    $stmt->execute([
        $networkId,
        $externalOfferId,
        $networkOfferUrl,
        $imageUrl,
        $title,
        $description,
        $category,
        $instructions,
        $networkPayout,
        $rewards['reward_rate'],
        $rewards['worker_reward'],
        $rewards['platform_margin'],
        $countries,
        $devices,
    ]);

    return (int) $pdo->lastInsertId();
}


/**
 * Fetch, synchronize, and prepare OGAds offers
 * for display on PoketFlow.
 *
 * Only ACTIVE + APPROVED campaigns are returned.
 */
function getOgadsDisplayOffers(
    PDO $pdo,
    array $visitorContext = []
): array {

    if ($visitorContext === []) {
        $visitorContext = getOgadsVisitorContext();
    }


    /*
     * Get or create the OGAds network record.
     */
    $networkId = getOgadsNetworkId($pdo);


    /*
     * Fetch live visitor-specific inventory.
     */
    $offers = fetchOgadsOffers(
        $visitorContext['ip'] ?? '',
        $visitorContext['user_agent'] ?? '',
        $visitorContext['language'] ?? '',
        $visitorContext['site'] ?? 'https://poketflow.com',
        0,
        100
    );


    $campaigns = [];


    foreach ($offers as $offer) {

        if (!is_array($offer)) {
            continue;
        }


        /*
         * Synchronize the live offer into campaigns.
         */
        $campaignId = syncOgadsDisplayOffer(
            $pdo,
            $networkId,
            $offer
        );


        if ($campaignId === null) {
            continue;
        }


        /*
         * Reload the campaign from the database.
         *
         * This is important because the database is
         * authoritative for status and approval.
         */
        $campaign = getCampaign(
            $pdo,
            $campaignId
        );


        if (!$campaign) {
            continue;
        }


        /*
         * Never display an offer unless it has been
         * explicitly activated and approved.
         */
        if (
            strtoupper((string) ($campaign['status'] ?? ''))
                !== 'ACTIVE'
        ) {
            continue;
        }

        if (
            strtoupper((string) ($campaign['approval_status'] ?? ''))
                !== 'APPROVED'
        ) {
            continue;
        }


        /*
         * Run the final campaign-level safety check.
         */
        if (!isCampaignAllowed(
            $pdo,
            $campaign
        )) {
            continue;
        }


        /*
         * Tell the UI which network this campaign
         * belongs to.
         */
        $campaign['network'] = 'OGAds';


        $campaigns[] = $campaign;
    }


    return $campaigns;
}
