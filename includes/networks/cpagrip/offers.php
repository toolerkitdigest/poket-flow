<?php

declare(strict_types=1);

/**
 * PoketFlow
 * CPAGrip Network Offers Module
 *
 * PHP 8.3
 *
 * Responsibilities:
 * - Load live CPAGrip offers
 * - Apply PoketFlow safety filtering
 * - Synchronize offers into campaigns
 * - Preserve administrator status/approval decisions
 * - Return only ACTIVE + APPROVED campaigns
 */

require_once dirname(__DIR__, 2) . '/cpagrip.php';


/**
 * Synchronize one CPAGrip offer into campaigns.
 *
 * Existing status and approval_status are never
 * overwritten by a network refresh.
 */
function syncCpagripDisplayOffer(
    PDO $pdo,
    int $networkId,
    array $offer
): ?int {

    $externalOfferId = trim(
        (string) ($offer['offer_id'] ?? '')
    );

    if ($externalOfferId === '') {
        return null;
    }


    $title = trim(
        (string) ($offer['title'] ?? 'CPAGrip Offer')
    );

    $description = trim(
        (string) ($offer['description'] ?? '')
    );

    $category = trim(
        (string) ($offer['category'] ?? '')
    );

    if ($category === '') {
        $category = 'Offer';
    }


    $networkOfferUrl = trim(
        (string) ($offer['offerlink'] ?? '')
    );

    $imageUrl = trim(
        (string) ($offer['image'] ?? '')
    );

    $offerType = trim(
        (string) ($offer['type'] ?? '')
    );

    $countries = trim(
        (string) ($offer['accepted_countries'] ?? '')
    );


    /*
     * CPAGrip feed does not currently provide
     * a separate OS field in our normalized data.
     */
    $devices = $offerType;


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
     * The existing CPAGrip integration calculates
     * the worker reward before returning the offer.
     */
    $workerReward = round(
        (float) ($offer['reward'] ?? 0),
        2
    );


    /*
     * Safety candidate.
     *
     * We use the same campaign-level safety system
     * already used by PoketFlow.
     */
    $safetyCandidate = [
        'source_type' => 'CPA_NETWORK',
        'network_id' => $networkId,
        'external_offer_id' => $externalOfferId,
        'network_offer_url' => $networkOfferUrl,
        'image_url' => $imageUrl,
        'title' => $title,
        'description' => $description,
        'category' => $category,
        'instructions' => '',
        'network_payout' => $networkPayout,
        'reward_rate' => $networkPayout > 0
            ? round(
                ($workerReward / $networkPayout) * 100,
                2
            )
            : 0,
        'worker_reward' => $workerReward,
        'platform_margin' => round(
            $networkPayout - $workerReward,
            2
        ),
        'countries' => $countries,
        'devices' => $devices,
        'os' => '',
        'incentive_allowed' => 1,
        'status' => 'ACTIVE',
        'approval_status' => 'APPROVED',
    ];


    /*
     * Apply the existing PoketFlow campaign
     * safety rules before saving the offer.
     */
    if (!isCampaignAllowed(
        $pdo,
        $safetyCandidate
    )) {
        return null;
    }


    /*
     * Look for an existing CPAGrip campaign.
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
     * Update operational information only.
     *
     * IMPORTANT:
     * status and approval_status remain untouched.
     */
    if ($existingId !== false) {

        $platformMargin = round(
            $networkPayout - $workerReward,
            2
        );

        $rewardRate = $networkPayout > 0
            ? round(
                ($workerReward / $networkPayout) * 100,
                2
            )
            : 0;


        $stmt = $pdo->prepare(
            'UPDATE campaigns
             SET
                network_offer_url = ?,
                image_url = ?,
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
                os = ?,
                incentive_allowed = 1,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );

        $stmt->execute([
            $networkOfferUrl,
            $imageUrl,
            $title,
            $description,
            $category,
            '',
            $networkPayout,
            $rewardRate,
            $workerReward,
            $platformMargin,
            $countries,
            $devices,
            '',
            (int) $existingId,
        ]);


        return (int) $existingId;
    }


    /*
     * New CPAGrip offers require administrator approval.
     */
    $platformMargin = round(
        $networkPayout - $workerReward,
        2
    );

    $rewardRate = $networkPayout > 0
        ? round(
            ($workerReward / $networkPayout) * 100,
            2
        )
        : 0;


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
        '',
        $networkPayout,
        $rewardRate,
        $workerReward,
        $platformMargin,
        $countries,
        $devices,
    ]);


    return (int) $pdo->lastInsertId();
}


/**
 * Fetch, synchronize, and prepare CPAGrip offers
 * for display on PoketFlow.
 */
function getCpagripDisplayOffers(
    PDO $pdo
): array {

    /*
     * cpagrip.php remains responsible for:
     *
     * - private credentials
     * - visitor IP
     * - user agent
     * - tracking ID
     * - CPAGrip API request
     * - JSON parsing
     * - reward calculation
     * - normalization
     */
    $offers = require dirname(__DIR__, 2) . '/cpagrip.php';


    if (!is_array($offers)) {
        return [];
    }


    /*
     * Find the active CPAGrip network record.
     */
    $stmt = $pdo->prepare(
        'SELECT id
         FROM networks
         WHERE slug = ?
           AND status = ?
         LIMIT 1'
    );

    $stmt->execute([
        'cpagrip',
        'ACTIVE',
    ]);

    $networkId = $stmt->fetchColumn();


    if ($networkId === false) {
        throw new RuntimeException(
            'CPAGrip network is not configured.'
        );
    }


    $networkId = (int) $networkId;

    $campaigns = [];


    foreach ($offers as $offer) {

        if (!is_array($offer)) {
            continue;
        }


        $campaignId = syncCpagripDisplayOffer(
            $pdo,
            $networkId,
            $offer
        );


        if ($campaignId === null) {
            continue;
        }


        /*
         * Reload the authoritative campaign record.
         */
        $campaign = getCampaign(
            $pdo,
            $campaignId
        );


        if (!$campaign) {
            continue;
        }


        /*
         * Only approved active offers may reach
         * the public offers page.
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
         * Final campaign safety check.
         */
        if (!isCampaignAllowed(
            $pdo,
            $campaign
        )) {
            continue;
        }


        $campaign['network'] = 'CPAGrip';


        $campaigns[] = $campaign;
    }


    return $campaigns;
}
