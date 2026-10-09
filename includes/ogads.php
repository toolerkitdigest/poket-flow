<?php

declare(strict_types=1);


/**
 * Load private OGAds configuration.
 */
function getOgadsConfig(): array
{
    $configPath = '/home/u541027683/private/poketflow-config.php';

    if (!file_exists($configPath)) {
        throw new RuntimeException(
            'PoketFlow private configuration file was not found.'
        );
    }

    $config = require $configPath;

    if (
        !is_array($config) ||
        empty($config['ogads']['api_key']) ||
        empty($config['ogads']['endpoint'])
    ) {
        throw new RuntimeException(
            'OGAds configuration is missing or invalid.'
        );
    }

    return $config['ogads'];
}


/**
 * Fetch visitor-specific offers from OGAds.
 */
function fetchOgadsOffers(
    string $ip,
    string $userAgent,
    string $language,
    string $site,
    int $ctype = 0,
    int $max = 50
): array {

    $config = getOgadsConfig();

    $params = [
        'ip' => $ip,
        'user_agent' => $userAgent,
        'lang' => $language,
        'site' => $site,
        'ctype' => $ctype,
        'max' => $max,
    ];

    $url = $config['endpoint']
        . '?'
        . http_build_query($params);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['api_key'],
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        throw new RuntimeException(
            'OGAds API request failed: ' . $error
        );
    }

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'OGAds API returned HTTP status ' . $httpCode . '.'
        );
    }

    try {

        $data = json_decode(
            $response,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

    } catch (JsonException $e) {

        throw new RuntimeException(
            'OGAds API returned invalid JSON.'
        );
    }

    if (
        !isset($data['success']) ||
        $data['success'] !== true
    ) {

        $error = $data['error']
            ?? 'Unknown OGAds API error.';

        throw new RuntimeException(
            'OGAds API error: ' . $error
        );
    }

    return $data['offers'] ?? [];
}


/**
 * Get or create the OGAds network record.
 */
function getOgadsNetworkId(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'SELECT id
         FROM networks
         WHERE slug = ?
         LIMIT 1'
    );

    $stmt->execute([
        'ogads',
    ]);

    $networkId = $stmt->fetchColumn();

    if ($networkId !== false) {
        return (int) $networkId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO networks (
            name,
            slug,
            api_endpoint,
            status
        )
        VALUES (?, ?, ?, "ACTIVE")'
    );

    $stmt->execute([
        'OGAds',
        'ogads',
        'https://trckapp.org/api/v2',
    ]);

    return (int) $pdo->lastInsertId();
}


/**
 * Determine a simple PoketFlow category
 * from OGAds offer content.
 */
function getOgadsOfferCategory(array $offer): string
{
    $text = strtolower(
        implode(
            ' ',
            [
                (string) ($offer['name'] ?? ''),
                (string) ($offer['name_short'] ?? ''),
                (string) ($offer['description'] ?? ''),
                (string) ($offer['adcopy'] ?? ''),
            ]
        )
    );

    if (
        str_contains($text, 'survey') ||
        str_contains($text, 'questionnaire')
    ) {
        return 'Survey';
    }

    if (
        str_contains($text, 'install') ||
        str_contains($text, 'app') ||
        str_contains($text, 'android') ||
        str_contains($text, 'iphone')
    ) {
        return 'App';
    }

    if (
        str_contains($text, 'signup') ||
        str_contains($text, 'sign up') ||
        str_contains($text, 'registration') ||
        str_contains($text, 'register')
    ) {
        return 'Signup';
    }

    return 'Offer';
}


/**
 * Clean offer text before storing it.
 */
function cleanOgadsText(?string $text): string
{
    $text = (string) $text;

    $text = strip_tags($text);

    $text = html_entity_decode(
        $text,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $text = preg_replace(
        '/\s+/',
        ' ',
        $text
    );

    return trim($text);
}


/**
 * Check whether a raw OGAds offer is safe to display.
 *
 * An offer is rejected when:
 * - It has no meaningful searchable content.
 * - It matches an active REJECT filter.
 *
 * Safety fields checked:
 * - name
 * - name_short
 * - description
 * - adcopy
 * - link
 */
function isOgadsOfferSafe(
    PDO $pdo,
    array $offer
): bool {

    $searchableFields = [
        'name',
        'name_short',
        'description',
        'adcopy',
        'link',
    ];

    $searchableText = '';

    foreach ($searchableFields as $field) {

        $value = cleanOgadsText(
            (string) ($offer[$field] ?? '')
        );

        if ($value !== '') {
            $searchableText .= ' ' . $value;
        }
    }

    /*
     * Without meaningful information, the offer
     * cannot be evaluated safely.
     */
    if (trim($searchableText) === '') {
        return false;
    }

    /*
     * Normalize offer text.
     */
    $searchableText = strtolower($searchableText);

    $searchableText = str_replace(
        [
            '-',
            '_',
            '/',
            '\\',
            '.',
            ',',
            ':',
            ';',
            '|',
            '(',
            ')',
            '[',
            ']',
            '{',
            '}',
        ],
        ' ',
        $searchableText
    );

    $searchableText = preg_replace(
        '/\s+/u',
        ' ',
        $searchableText
    );

    $searchableText = trim(
        (string) $searchableText
    );

    /*
     * Load active safety filters.
     */
    $stmt = $pdo->query(
        'SELECT keyword
         FROM offer_filters
         WHERE action = "REJECT"
           AND active = 1
         ORDER BY CHAR_LENGTH(keyword) DESC'
    );

    $filters = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    foreach ($filters as $keyword) {

        $keyword = strtolower(
            trim((string) $keyword)
        );

        if ($keyword === '') {
            continue;
        }

        /*
         * Normalize filter keywords using the
         * same rules as the offer text.
         */
        $keyword = str_replace(
            [
                '-',
                '_',
                '/',
                '\\',
                '.',
                ',',
                ':',
                ';',
                '|',
                '(',
                ')',
                '[',
                ']',
                '{',
                '}',
            ],
            ' ',
            $keyword
        );

        $keyword = preg_replace(
            '/\s+/u',
            ' ',
            $keyword
        );

        $keyword = trim(
            (string) $keyword
        );

        if (
            $keyword !== '' &&
            str_contains(
                $searchableText,
                $keyword
            )
        ) {
            return false;
        }
    }

    return true;
}


/**
 * Calculate worker reward and platform margin.
 */
function calculateOgadsReward(
    PDO $pdo,
    float $networkPayout
): array {

    $rewardRate = (float) getSetting(
        $pdo,
        'default_worker_reward_rate',
        '40'
    );

    $workerReward = round(
        $networkPayout * ($rewardRate / 100),
        2
    );

    $platformMargin = round(
        $networkPayout - $workerReward,
        2
    );

    return [
        'reward_rate' => $rewardRate,
        'worker_reward' => $workerReward,
        'platform_margin' => $platformMargin,
    ];
}


/**
 * Synchronize one OGAds offer into campaigns.
 *
 * IMPORTANT:
 * - Never override existing administrative status.
 * - Never override existing approval status.
 * - Never automatically enable incentivized traffic.
 * - New offers require administrative review.
 */
function syncOgadsOffer(
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
        (string) (
            $offer['name_short']
            ?? $offer['name']
            ?? 'OGAds Offer'
        )
    );

    $description = cleanOgadsText(
        (string) ($offer['description'] ?? '')
    );

    $instructions = cleanOgadsText(
        (string) ($offer['adcopy'] ?? '')
    );

    $category = getOgadsOfferCategory($offer);

    $countries = trim(
        (string) ($offer['country'] ?? '')
    );

    $devices = trim(
        (string) ($offer['device'] ?? '')
    );

    /*
     * Network participation URL.
     */
    $networkOfferUrl = trim(
        (string) ($offer['link'] ?? '')
    );

    /*
     * Network offer image.
     */
    $imageUrl = trim(
        (string) ($offer['picture'] ?? '')
    );

    $networkPayout = round(
        (float) ($offer['payout'] ?? 0),
        2
    );

    if (
        $networkPayout <= 0 ||
        $networkOfferUrl === ''
    ) {
        return null;
    }

    /*
     * Apply PoketFlow safety filters.
     */
    if (!isOgadsOfferSafe($pdo, $offer)) {
        return null;
    }

    /*
     * Calculate the reward proposal.
     */
    $rewards = calculateOgadsReward(
        $pdo,
        $networkPayout
    );

    /*
     * Find the existing campaign.
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
     * Update existing offer information only.
     *
     * Do not modify:
     * - status
     * - approval_status
     * - incentive_allowed
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
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND network_id = ?
               AND external_offer_id = ?'
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
            $networkId,
            $externalOfferId,
        ]);

        return (int) $existingId;
    }

    /*
     * Insert new offers as pending review.
     *
     * They must not be automatically activated,
     * approved or enabled for incentivized traffic.
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
            incentive_allowed,
            status,
            approval_status
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
            0,
            "INACTIVE",
            "PENDING"
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
 * Synchronize all OGAds offers.
 */
function syncOgadsOffers(
    PDO $pdo,
    array $offers
): array {

    $networkId = getOgadsNetworkId($pdo);

    $result = [
        'received' => count($offers),
        'saved' => 0,
        'updated' => 0,
        'rejected' => 0,
    ];

    foreach ($offers as $offer) {

        $externalOfferId = trim(
            (string) ($offer['offerid'] ?? '')
        );

        if ($externalOfferId === '') {
            $result['rejected']++;
            continue;
        }

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

        $campaignId = syncOgadsOffer(
            $pdo,
            $networkId,
            $offer
        );

        if ($campaignId === null) {
            $result['rejected']++;
            continue;
        }

        if ($existingId !== false) {
            $result['updated']++;
        } else {
            $result['saved']++;
        }
    }

    return $result;
}
