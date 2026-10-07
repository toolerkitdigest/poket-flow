<?php

declare(strict_types=1);

/**
 * PoketFlow
 * CPAGrip Network Offers Module
 *
 * PHP 8.3
 *
 * TEMPORARY DIAGNOSTIC VERSION
 */

require_once dirname(__DIR__, 2) . '/cpagrip.php';


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

    $workerReward = round(
        (float) ($offer['reward'] ?? 0),
        2
    );
    

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

    $safetyAllowed = isCampaignAllowed(
    $pdo,
    $safetyCandidate
);
$finalSafetyCheck = isCampaignAllowed(
    $pdo,
    $campaign
);

echo '<pre>';
$finalSafetyCheck = isCampaignAllowed(
    $pdo,
    $campaign
);

echo '<pre>';

echo "=== FINAL CPAGrip SAFETY CHECK ===\n\n";

echo "Campaign ID: ";
var_dump($campaign['id']);

echo "Status: ";
var_dump($campaign['status']);

echo "Approval Status: ";
var_dump($campaign['approval_status']);

echo "Countries: ";
var_dump($campaign['countries']);

echo "\nisCampaignAllowed(): ";
var_dump($finalSafetyCheck);

echo "\nComplete Campaign:\n";
print_r($campaign);

echo '</pre>';

exit;prepare(
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


function getCpagripDisplayOffers(
    PDO $pdo
): array {

    $offers = require dirname(__DIR__, 2) . '/cpagrip.php';

    if (!is_array($offers)) {
        return [];
    }

    

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

        $campaign = getCampaign(
            $pdo,
            $campaignId
        );

        if (!$campaign) {
            continue;
        }

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
