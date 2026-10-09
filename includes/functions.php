<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow Common Functions
|--------------------------------------------------------------------------
| Shared helper and business-logic functions used throughout PoketFlow.
|
| PHP 7.2 compatible.
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| HTML Escaping
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}


/*
|--------------------------------------------------------------------------
| Generate Unique Referral Code
|--------------------------------------------------------------------------
*/

function generateReferralCode(PDO $pdo, string $name): string
{
    $base = strtoupper(
        preg_replace('/[^A-Za-z0-9]/', '', $name)
    );

    $base = substr($base ?: 'USER', 0, 8);

    do {
        $code = $base . strtoupper(
            substr(bin2hex(random_bytes(4)), 0, 6)
        );

        $stmt = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE referral_code = ?
             LIMIT 1'
        );

        $stmt->execute([$code]);

    } while ($stmt->fetchColumn() !== false);

    return $code;
}


/*
|--------------------------------------------------------------------------
| Get System Setting
|--------------------------------------------------------------------------
*/

function getSetting(
    PDO $pdo,
    string $key,
    ?string $default = null
): ?string {
    $stmt = $pdo->prepare(
        'SELECT setting_value
         FROM settings
         WHERE setting_key = ?
         LIMIT 1'
    );

    $stmt->execute([$key]);

    $value = $stmt->fetchColumn();

    if ($value === false) {
        return $default;
    }

    return (string) $value;
}


/*
|--------------------------------------------------------------------------
| Get User
|--------------------------------------------------------------------------
*/

function getUser(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            id,
            role,
            name,
            email,
            country,
            status,
            referral_code,
            referred_by,
            created_at
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}


/*
|--------------------------------------------------------------------------
| Get User Wallet Balance
|--------------------------------------------------------------------------
| Calculates the available balance from completed ledger entries.
|--------------------------------------------------------------------------
*/

function getUserBalance(PDO $pdo, int $userId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM wallet_transactions
         WHERE user_id = ?
           AND status = 'COMPLETED'"
    );

    $stmt->execute([$userId]);

    return (float) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Get User Pending Balance
|--------------------------------------------------------------------------
*/

function getUserPendingBalance(PDO $pdo, int $userId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM wallet_transactions
         WHERE user_id = ?
           AND status = 'PENDING'"
    );

    $stmt->execute([$userId]);

    return (float) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Get User Total Earned
|--------------------------------------------------------------------------
*/

function getUserTotalEarned(PDO $pdo, int $userId): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(worker_reward), 0)
         FROM conversions
         WHERE worker_id = ?
           AND status = 'APPROVED'"
    );

    $stmt->execute([$userId]);

    return (float) $stmt->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Normalize Country Name
|--------------------------------------------------------------------------
*/

function normalizeCountryName(?string $country): string
{
    $country = strtolower(trim((string) $country));

    $country = preg_replace('/\s+/', ' ', $country);

    return trim((string) $country);
}


/*
|--------------------------------------------------------------------------
| Check Country Eligibility
|--------------------------------------------------------------------------
| Empty country restrictions mean unrestricted.
|
| Supports comma-separated and pipe-separated values.
| Unknown country names are compared literally.
|--------------------------------------------------------------------------
*/

function isCountryEligible(
    ?string $campaignCountries,
    ?string $userCountry
): bool {
    $campaignCountries = trim((string) $campaignCountries);
    $userCountry = normalizeCountryName($userCountry);

    if ($campaignCountries === '') {
        return true;
    }

    $countries = preg_split(
        '/[,|]+/',
        $campaignCountries
    );

    if (!$countries) {
        return false;
    }

    $aliases = [
        'us' => [
            'usa',
            'united states',
            'united states of america'
        ],
        'usa' => [
            'us',
            'united states',
            'united states of america'
        ],
        'united states' => [
            'us',
            'usa',
            'united states of america'
        ],

        'gb' => [
            'uk',
            'united kingdom',
            'great britain'
        ],
        'uk' => [
            'gb',
            'united kingdom',
            'great britain'
        ],

        'ng' => ['nigeria'],
        'ca' => ['canada'],
        'au' => ['australia'],
        'de' => ['germany'],
        'fr' => ['france'],
        'it' => ['italy'],
        'es' => ['spain'],
        'za' => ['south africa'],
        'in' => ['india'],
    ];

    foreach ($countries as $country) {
        $country = normalizeCountryName($country);

        if ($country === '') {
            continue;
        }

        if (
            in_array(
                $country,
                ['all', 'worldwide', 'global', 'any'],
                true
            )
        ) {
            return true;
        }

        if ($country === $userCountry) {
            return true;
        }

        if (
            isset($aliases[$country]) &&
            in_array($userCountry, $aliases[$country], true)
        ) {
            return true;
        }

        if (isset($aliases[$userCountry])) {
            if (
                in_array(
                    $country,
                    $aliases[$userCountry],
                    true
                )
            ) {
                return true;
            }
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| Normalize Text For Offer Filtering
|--------------------------------------------------------------------------
*/

function normalizeOfferFilterText(string $text): string
{
    $text = strtolower($text);

    $text = str_replace(
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
        $text
    );

    $text = preg_replace('/\s+/', ' ', $text);

    return trim((string) $text);
}


/*
|--------------------------------------------------------------------------
| Check Campaign Offer Filters
|--------------------------------------------------------------------------
| Requires ACTIVE status and APPROVED approval status.
|
| Enforces active REJECT filters on offer metadata.
|--------------------------------------------------------------------------
*/

function isCampaignAllowed(PDO $pdo, array $campaign): bool
{
    $status = strtoupper(
        trim((string) ($campaign['status'] ?? ''))
    );

    if ($status !== 'ACTIVE') {
        return false;
    }

    $approvalStatus = strtoupper(
        trim((string) ($campaign['approval_status'] ?? ''))
    );

    if ($approvalStatus !== 'APPROVED') {
        return false;
    }

    $searchableFields = [
        'title',
        'description',
        'category',
        'instructions',
        'network_offer_url',
    ];

    $searchableText = '';

    foreach ($searchableFields as $field) {
        $value = trim(
            (string) ($campaign[$field] ?? '')
        );

        if ($value !== '') {
            $searchableText .= ' ' . $value;
        }
    }

    $searchableText = normalizeOfferFilterText(
        $searchableText
    );

    $stmt = $pdo->query(
        "SELECT keyword, action
         FROM offer_filters
         WHERE active = 1
         ORDER BY CHAR_LENGTH(keyword) DESC, id ASC"
    );

    $filters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filters as $filter) {
        $action = strtoupper(
            trim((string) ($filter['action'] ?? ''))
        );

        if ($action !== 'REJECT') {
            continue;
        }

        $keyword = normalizeOfferFilterText(
            trim((string) ($filter['keyword'] ?? ''))
        );

        if ($keyword === '') {
            continue;
        }

        if (strpos($searchableText, $keyword) !== false) {
            return false;
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Get Active Campaigns
|--------------------------------------------------------------------------
| Returns campaigns that have been approved and are active.
|
| This function does not independently establish that a network
| permits incentivized traffic. Check incentive_allowed before
| presenting a campaign as a reward-earning offer.
|--------------------------------------------------------------------------
*/

function getActiveCampaigns(
    PDO $pdo,
    ?string $country = null
): array {
    $stmt = $pdo->query(
        "SELECT
            id,
            source_type,
            title,
            description,
            category,
            instructions,
            worker_reward,
            countries,
            devices,
            os,
            image_url,
            incentive_allowed,
            status,
            approval_status,
            start_at,
            end_at
         FROM campaigns
         WHERE status = 'ACTIVE'
           AND approval_status = 'APPROVED'
           AND (start_at IS NULL OR start_at <= NOW())
           AND (end_at IS NULL OR end_at >= NOW())
         ORDER BY id DESC"
    );

    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($campaigns as $campaign) {
        if (
            $country !== null &&
            !isCountryEligible(
                $campaign['countries'] ?? '',
                $country
            )
        ) {
            continue;
        }

        if (!isCampaignAllowed($pdo, $campaign)) {
            continue;
        }

        $results[] = $campaign;
    }

    return $results;
}


/*
|--------------------------------------------------------------------------
| Get Single Campaign
|--------------------------------------------------------------------------
*/

function getCampaign(
    PDO $pdo,
    int $campaignId
): ?array {
    $stmt = $pdo->prepare(
        'SELECT
            id,
            source_type,
            image_url,
            advertiser_id,
            network_id,
            external_offer_id,
            network_offer_url,
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
            end_at
         FROM campaigns
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$campaignId]);

    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    return $campaign ?: null;
}


/*
|--------------------------------------------------------------------------
| Validate Campaign For Worker
|--------------------------------------------------------------------------
| A campaign must be approved, active, within its schedule,
| country-eligible, safe, and explicitly permitted for incentives.
|--------------------------------------------------------------------------
*/

function canStartCampaign(
    PDO $pdo,
    array $campaign,
    array $user
): bool {

    /*
    | Campaign status.
    */

    $status = strtoupper(
        trim((string) ($campaign['status'] ?? ''))
    );

    if ($status !== 'ACTIVE') {
        return false;
    }


    /*
    | Admin approval.
    */

    $approvalStatus = strtoupper(
        trim((string) ($campaign['approval_status'] ?? ''))
    );

    if ($approvalStatus !== 'APPROVED') {
        return false;
    }


    /*
    | Incentive permission.
    |
    | Missing or disabled permission must fail closed.
    */

    if (
        !isset($campaign['incentive_allowed']) ||
        (int) $campaign['incentive_allowed'] !== 1
    ) {
        return false;
    }


    /*
    | Campaign start date.
    */

    if (!empty($campaign['start_at'])) {
        $startTime = strtotime(
            (string) $campaign['start_at']
        );

        if ($startTime === false || $startTime > time()) {
            return false;
        }
    }


    /*
    | Campaign end date.
    */

    if (!empty($campaign['end_at'])) {
        $endTime = strtotime(
            (string) $campaign['end_at']
        );

        if ($endTime === false || $endTime < time()) {
            return false;
        }
    }


    /*
    | Country eligibility.
    */

    if (
        !isCountryEligible(
            $campaign['countries'] ?? '',
            $user['country'] ?? ''
        )
    ) {
        return false;
    }


    /*
    | Offer safety filters.
    */

    if (!isCampaignAllowed($pdo, $campaign)) {
        return false;
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Generate Unique Tracking ID
|--------------------------------------------------------------------------
*/

function generateTrackingId(): string
{
    return bin2hex(random_bytes(16));
}


/*
|--------------------------------------------------------------------------
| Create Campaign Click
|--------------------------------------------------------------------------
| Creates a click record and returns its tracking ID.
|
| The database should enforce a UNIQUE constraint on tracking_id.
|--------------------------------------------------------------------------
*/

function createCampaignClick(
    PDO $pdo,
    int $campaignId,
    int $workerId,
    ?string $ipAddress = null,
    ?string $userAgent = null
): string {

    /*
    | Confirm that the campaign exists and the worker exists.
    |
    | This is a basic consistency check. The calling workflow must
    | still perform the full eligibility checks before this function.
    */

    $trackingId = generateTrackingId();

    $ipHash = null;

    if ($ipAddress !== null && $ipAddress !== '') {
        $ipHash = hash('sha256', $ipAddress);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO campaign_clicks (
            campaign_id,
            worker_id,
            tracking_id,
            ip_hash,
            user_agent
        )
        VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $campaignId,
        $workerId,
        $trackingId,
        $ipHash,
        $userAgent,
    ]);

    return $trackingId;
}
