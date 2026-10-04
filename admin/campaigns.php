<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Campaigns';

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['campaigns_csrf_token'])) {
    $_SESSION['campaigns_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['campaigns_csrf_token'];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function campaignEscape(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function campaignStatusClass(string $status): string
{
    switch (strtoupper($status)) {

        case 'ACTIVE':
            return 'status-active';

        case 'PAUSED':
            return 'status-paused';

        case 'COMPLETED':
            return 'status-completed';

        case 'EXPIRED':
            return 'status-expired';

        case 'DRAFT':
            return 'status-draft';

        default:
            return 'status-default';
    }
}

function campaignApprovalClass(string $status): string
{
    switch (strtoupper($status)) {

        case 'APPROVED':
            return 'approval-approved';

        case 'PENDING':
            return 'approval-pending';

        case 'REJECTED':
            return 'approval-rejected';

        default:
            return 'approval-default';
    }
}

function campaignSourceClass(string $source): string
{
    switch (strtoupper($source)) {

        case 'CPA_NETWORK':
            return 'source-network';

        case 'DIRECT_ADVERTISER':
            return 'source-advertiser';

        default:
            return 'source-default';
    }
}

function campaignSourceLabel(string $source): string
{
    switch (strtoupper($source)) {

        case 'CPA_NETWORK':
            return 'CPA Network';

        case 'DIRECT_ADVERTISER':
            return 'Direct Advertiser';

        default:
            return ucwords(
                strtolower(
                    str_replace('_', ' ', $source)
                )
            );
    }
}

function campaignMoney(float $amount): string
{
    return '$' . number_format($amount, 2);
}

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

$actionMessage = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string) (
        $_POST['csrf_token'] ?? ''
    );

    if (
        $submittedToken === '' ||
        !hash_equals($csrfToken, $submittedToken)
    ) {

        $actionError =
            'Security validation failed. Please refresh the page and try again.';

    } else {

        $action = strtoupper(
            trim(
                (string) ($_POST['action'] ?? '')
            )
        );

        $campaignId = (int) (
            $_POST['campaign_id'] ?? 0
        );

        $allowedActions = [
            'APPROVE',
            'REJECT',
            'ACTIVATE',
            'PAUSE',
        ];

        if (
            $campaignId <= 0 ||
            !in_array(
                $action,
                $allowedActions,
                true
            )
        ) {

            $actionError = 'Invalid campaign action.';

        } else {

            try {

                /*
                 * Make sure the campaign exists.
                 */
                $campaignStmt = $pdo->prepare(
                    'SELECT
                        id,
                        title,
                        status,
                        approval_status
                     FROM campaigns
                     WHERE id = :id
                     LIMIT 1'
                );

                $campaignStmt->execute([
                    'id' => $campaignId,
                ]);

                $campaign = $campaignStmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$campaign) {

                    $actionError = 'Campaign not found.';

                } else {

                    switch ($action) {

                        /*
                        |--------------------------------------------------------------------------
                        | APPROVE
                        |--------------------------------------------------------------------------
                        */

                        case 'APPROVE':

                            $stmt = $pdo->prepare(
                                'UPDATE campaigns
                                 SET approval_status = "APPROVED",
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = :id'
                            );

                            $stmt->execute([
                                'id' => $campaignId,
                            ]);

                            $actionMessage =
                                'Campaign approved successfully.';

                            break;


                        /*
                        |--------------------------------------------------------------------------
                        | REJECT
                        |--------------------------------------------------------------------------
                        */

                        case 'REJECT':

                            $stmt = $pdo->prepare(
                                'UPDATE campaigns
                                 SET approval_status = "REJECTED",
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = :id'
                            );

                            $stmt->execute([
                                'id' => $campaignId,
                            ]);

                            $actionMessage =
                                'Campaign rejected successfully.';

                            break;


                        /*
                        |--------------------------------------------------------------------------
                        | ACTIVATE
                        |--------------------------------------------------------------------------
                        */

                        case 'ACTIVATE':

                            if (
                                strtoupper(
                                    (string) $campaign['approval_status']
                                ) !== 'APPROVED'
                            ) {

                                $actionError =
                                    'Only approved campaigns can be activated.';

                                break;
                            }

                            $stmt = $pdo->prepare(
                                'UPDATE campaigns
                                 SET status = "ACTIVE",
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = :id'
                            );

                            $stmt->execute([
                                'id' => $campaignId,
                            ]);

                            $actionMessage =
                                'Campaign activated successfully.';

                            break;


                        /*
                        |--------------------------------------------------------------------------
                        | PAUSE
                        |--------------------------------------------------------------------------
                        */

                        case 'PAUSE':

                            $stmt = $pdo->prepare(
                                'UPDATE campaigns
                                 SET status = "PAUSED",
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = :id'
                            );

                            $stmt->execute([
                                'id' => $campaignId,
                            ]);

                            $actionMessage =
                                'Campaign paused successfully.';

                            break;
                    }
                }

            } catch (Throwable $e) {

                error_log(
                    'Campaign action error: ' .
                    $e->getMessage()
                );

                $actionError =
                    'Unable to update the campaign. Please try again.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) ($_GET['search'] ?? '')
);

$status = strtoupper(
    trim(
        (string) ($_GET['status'] ?? '')
    )
);

$approval = strtoupper(
    trim(
        (string) ($_GET['approval'] ?? '')
    )
);

$source = strtoupper(
    trim(
        (string) ($_GET['source'] ?? '')
    )
);

$allowedStatuses = [
    'DRAFT',
    'ACTIVE',
    'PAUSED',
    'COMPLETED',
    'EXPIRED',
];

$allowedApprovals = [
    'PENDING',
    'APPROVED',
    'REJECTED',
];

$allowedSources = [
    'CPA_NETWORK',
    'DIRECT_ADVERTISER',
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if (!in_array($approval, $allowedApprovals, true)) {
    $approval = '';
}

if (!in_array($source, $allowedSources, true)) {
    $source = '';
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 20;

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$where = [];
$params = [];

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = '(
        c.title LIKE :search
        OR c.external_offer_id LIKE :search
        OR c.category LIKE :search
        OR n.name LIKE :search
        OR a.company_name LIKE :search
    )';

    $params['search'] =
        '%' . $search . '%';
}

/*
|--------------------------------------------------------------------------
| Status Filter
|--------------------------------------------------------------------------
*/

if ($status !== '') {

    $where[] =
        'c.status = :status';

    $params['status'] =
        $status;
}

/*
|--------------------------------------------------------------------------
| Approval Filter
|--------------------------------------------------------------------------
*/

if ($approval !== '') {

    $where[] =
        'c.approval_status = :approval';

    $params['approval'] =
        $approval;
}

/*
|--------------------------------------------------------------------------
| Source Filter
|--------------------------------------------------------------------------
*/

if ($source !== '') {

    $where[] =
        'c.source_type = :source';

    $params['source'] =
        $source;
}

$whereSql = '';

if (!empty($where)) {

    $whereSql =
        'WHERE ' .
        implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| Total Count
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*)
    FROM campaigns c

    LEFT JOIN networks n
        ON n.id = c.network_id

    LEFT JOIN advertisers a
        ON a.id = c.advertiser_id

    {$whereSql}
";

$countStmt = $pdo->prepare(
    $countSql
);

$countStmt->execute(
    $params
);

$totalCampaigns = (int) (
    $countStmt->fetchColumn()
);

$totalPages = max(
    1,
    (int) ceil(
        $totalCampaigns / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| Campaign Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.source_type,
        c.advertiser_id,
        c.network_id,
        c.external_offer_id,
        c.image_url,
        c.title,
        c.description,
        c.category,
        c.network_payout,
        c.reward_rate,
        c.worker_reward,
        c.platform_margin,
        c.budget,
        c.spent,
        c.countries,
        c.devices,
        c.os,
        c.incentive_allowed,
        c.status,
        c.approval_status,
        c.start_at,
        c.end_at,
        c.created_at,
        c.updated_at,

        n.name AS network_name,

        a.company_name AS advertiser_name

    FROM campaigns c

    LEFT JOIN networks n
        ON n.id = c.network_id

    LEFT JOIN advertisers a
        ON a.id = c.advertiser_id

    {$whereSql}

    ORDER BY
        CASE
            WHEN c.approval_status = 'PENDING' THEN 0
            WHEN c.approval_status = 'APPROVED' THEN 1
            ELSE 2
        END,
        c.created_at DESC,
        c.id DESC

    LIMIT {$perPage}
    OFFSET {$offset}
";

$stmt = $pdo->prepare(
    $sql
);

$stmt->execute(
    $params
);

$campaigns = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Query String Helper
|--------------------------------------------------------------------------
*/

$queryParams = [];

if ($search !== '') {
    $queryParams['search'] =
        $search;
}

if ($status !== '') {
    $queryParams['status'] =
        $status;
}

if ($approval !== '') {
    $queryParams['approval'] =
        $approval;
}

if ($source !== '') {
    $queryParams['source'] =
        $source;
}

function campaignPageUrl(
    array $params,
    int $page
): string {

    $params['page'] =
        $page;

    return 'campaigns.php?' .
        http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#080d1a"
    >

    <title>
        Campaigns — PoketFlow Admin
    </title>

    <link
        rel="stylesheet"
        href="assets/campaigns.css"
    >

</head>

<body>

<div class="campaigns-app">

    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="campaigns-sidebar">

        <div class="sidebar-brand">

            <a
                href="index.php"
                class="brand-link"
            >

                <span class="brand-mark">
                    P
                </span>

                <span class="brand-text">
                    Poket<span>Flow</span>
                </span>

            </a>

            <span class="admin-label">
                ADMIN
            </span>

        </div>


        <nav class="sidebar-navigation">

            <a
                href="index.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a
                href="users.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">◉</span>
                <span>Users</span>
            </a>

            <a
                href="campaigns.php"
                class="sidebar-link active"
            >
                <span class="sidebar-icon">◈</span>
                <span>Campaigns</span>
            </a>

            <a
                href="conversions.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">✓</span>
                <span>Conversions</span>
            </a>

            <a
                href="withdrawals.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">↗</span>
                <span>Withdrawals</span>
            </a>

            <a
                href="advertisers.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">▣</span>
                <span>Advertisers</span>
            </a>

            <a
                href="networks.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">◎</span>
                <span>Networks</span>
            </a>

            <a
                href="settings.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">⚙</span>
                <span>Settings</span>
            </a>

        </nav>


        <div class="sidebar-bottom">

            <a
                href="../index.php"
                class="sidebar-link"
            >
                <span class="sidebar-icon">↗</span>
                <span>View Website</span>
            </a>

            <a
                href="../logout.php"
                class="sidebar-link logout-link"
            >
                <span class="sidebar-icon">⇥</span>
                <span>Logout</span>
            </a>

        </div>

    </aside>


    <!-- =====================================================
         MAIN
    ====================================================== -->

    <main class="campaigns-main">

        <!-- Mobile Header -->

        <header class="mobile-header">

            <a
                href="index.php"
                class="mobile-brand"
            >

                <span class="brand-mark">
                    P
                </span>

                <span class="brand-text">
                    Poket<span>Flow</span>
                </span>

            </a>

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open menu"
                aria-expanded="false"
            >

                <span></span>
                <span></span>
                <span></span>

            </button>

        </header>


        <!-- Mobile Navigation -->

        <div
            class="mobile-navigation"
            id="mobileNavigation"
        >

            <a href="index.php">
                Dashboard
            </a>

            <a href="users.php">
                Users
            </a>

            <a
                href="campaigns.php"
                class="active"
            >
                Campaigns
            </a>

            <a href="conversions.php">
                Conversions
            </a>

            <a href="withdrawals.php">
                Withdrawals
            </a>

            <a href="advertisers.php">
                Advertisers
            </a>

            <a href="networks.php">
                Networks
            </a>

            <a href="settings.php">
                Settings
            </a>

            <a href="../index.php">
                View Website
            </a>

            <a href="../logout.php">
                Logout
            </a>

        </div>


        <!-- =================================================
             PAGE HEADER
        ================================================== -->

        <section class="page-header">

            <div>

                <span class="page-eyebrow">
                    CAMPAIGN MANAGEMENT
                </span>

                <h1>
                    Campaigns
                </h1>

                <p>
                    Manage CPA network and direct advertiser campaigns,
                    rewards, approvals and campaign status.
                </p>

            </div>

            <div class="page-header-stat">

                <span>
                    Total Campaigns
                </span>

                <strong>
                    <?= number_format($totalCampaigns) ?>
                </strong>

            </div>

        </section>


        <!-- =================================================
             ALERTS
        ================================================== -->

        <?php if ($actionMessage !== ''): ?>

            <div class="campaign-alert success">

                <span class="alert-icon">
                    ✓
                </span>

                <span>
                    <?= campaignEscape($actionMessage) ?>
                </span>

            </div>

        <?php endif; ?>


        <?php if ($actionError !== ''): ?>

            <div class="campaign-alert error">

                <span class="alert-icon">
                    !
                </span>

                <span>
                    <?= campaignEscape($actionError) ?>
                </span>

            </div>

        <?php endif; ?>


        <!-- =================================================
             FILTERS
        ================================================== -->

        <section class="filters-card">

            <form
                method="get"
                action="campaigns.php"
                class="campaign-filters"
            >

                <div class="search-field">

                    <label for="search">
                        Search
                    </label>

                    <div class="search-input-wrap">

                        <span class="search-icon">
                            ⌕
                        </span>

                        <input
                            type="search"
                            id="search"
                            name="search"
                            value="<?= campaignEscape($search) ?>"
                            placeholder="Campaign, network, advertiser..."
                        >

                    </div>

                </div>


                <div class="filter-field">

                    <label for="status">
                        Status
                    </label>

                    <select
                        id="status"
                        name="status"
                    >

                        <option value="">
                            All statuses
                        </option>

                        <?php foreach ($allowedStatuses as $item): ?>

                            <option
                                value="<?= campaignEscape($item) ?>"
                                <?= $status === $item ? 'selected' : '' ?>
                            >
                                <?= campaignEscape($item) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="filter-field">

                    <label for="approval">
                        Approval
                    </label>

                    <select
                        id="approval"
                        name="approval"
                    >

                        <option value="">
                            All approvals
                        </option>

                        <?php foreach ($allowedApprovals as $item): ?>

                            <option
                                value="<?= campaignEscape($item) ?>"
                                <?= $approval === $item ? 'selected' : '' ?>
                            >
                                <?= campaignEscape($item) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="filter-field">

                    <label for="source">
                        Source
                    </label>

                    <select
                        id="source"
                        name="source"
                    >

                        <option value="">
                            All sources
                        </option>

                        <?php foreach ($allowedSources as $item): ?>

                            <option
                                value="<?= campaignEscape($item) ?>"
                                <?= $source === $item ? 'selected' : '' ?>
                            >
                                <?= campaignEscape(
                                    campaignSourceLabel($item)
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="filter-actions">

                    <button
                        type="submit"
                        class="filter-button"
                    >
                        Apply Filters
                    </button>

                    <a
                        href="campaigns.php"
                        class="clear-button"
                    >
                        Clear
                    </a>

                </div>

            </form>

        </section>


        <!-- =================================================
             CAMPAIGNS TABLE
        ================================================== -->

        <section class="campaigns-card">

            <div class="card-header">

                <div>

                    <h2>
                        Campaign Inventory
                    </h2>

                    <p>
                        <?= number_format($totalCampaigns) ?>
                        campaign<?= $totalCampaigns === 1 ? '' : 's' ?>
                        found
                    </p>

                </div>

                <div class="page-count">

                    Page
                    <?= number_format($page) ?>
                    of
                    <?= number_format($totalPages) ?>

                </div>

            </div>


            <?php if (!$campaigns): ?>

                <div class="empty-state">

                    <div class="empty-icon">
                        ◈
                    </div>

                    <h3>
                        No campaigns found
                    </h3>

                    <p>
                        There are no campaigns matching the current filters.
                    </p>

                    <?php if (
                        $search !== '' ||
                        $status !== '' ||
                        $approval !== '' ||
                        $source !== ''
                    ): ?>

                        <a
                            href="campaigns.php"
                            class="empty-button"
                        >
                            Clear Filters
                        </a>

                    <?php endif; ?>

                </div>

            <?php else: ?>

                <div class="table-wrapper">

                    <table class="campaigns-table">

                        <thead>

                            <tr>

                                <th>
                                    Campaign
                                </th>

                                <th>
                                    Source
                                </th>

                                <th>
                                    Earnings
                                </th>

                                <th>
                                    Targeting
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Approval
                                </th>

                                <th>
                                    Created
                                </th>

                                <th>
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($campaigns as $campaign): ?>

                            <?php

                            $campaignId =
                                (int) $campaign['id'];

                            $sourceType =
                                strtoupper(
                                    (string) $campaign['source_type']
                                );

                            $statusValue =
                                strtoupper(
                                    (string) $campaign['status']
                                );

                            $approvalValue =
                                strtoupper(
                                    (string) $campaign['approval_status']
                                );

                            $networkName =
                                trim(
                                    (string) (
                                        $campaign['network_name'] ?? ''
                                    )
                                );

                            $advertiserName =
                                trim(
                                    (string) (
                                        $campaign['advertiser_name'] ?? ''
                                    )
                                );

                            $providerName =
                                $sourceType === 'CPA_NETWORK'
                                    ? (
                                        $networkName !== ''
                                            ? $networkName
                                            : 'CPA Network'
                                    )
                                    : (
                                        $advertiserName !== ''
                                            ? $advertiserName
                                            : 'Direct Advertiser'
                                    );

                            $countries =
                                trim(
                                    (string) (
                                        $campaign['countries'] ?? ''
                                    )
                                );

                            $devices =
                                trim(
                                    (string) (
                                        $campaign['devices'] ?? ''
                                    )
                                );

                            $os =
                                trim(
                                    (string) (
                                        $campaign['os'] ?? ''
                                    )
                                );

                            ?>

                            <tr>

                                <!-- Campaign -->

                                <td class="campaign-main-cell">

                                    <div class="campaign-title-row">

                                        <?php if (
                                            !empty(
                                                $campaign['image_url']
                                            )
                                        ): ?>

                                            <img
                                                src="<?= campaignEscape(
                                                    (string) $campaign['image_url']
                                                ) ?>"
                                                alt=""
                                                class="campaign-image"
                                                loading="lazy"
                                                onerror="this.style.display='none';"
                                            >

                                        <?php else: ?>

                                            <div class="campaign-image fallback">
                                                ◈
                                            </div>

                                        <?php endif; ?>


                                        <div class="campaign-title-content">

                                            <strong class="campaign-title">

                                                <?= campaignEscape(
                                                    (string) $campaign['title']
                                                ) ?>

                                            </strong>

                                            <span class="campaign-id">

                                                ID #<?= $campaignId ?>

                                                <?php if (
                                                    !empty(
                                                        $campaign['external_offer_id']
                                                    )
                                                ): ?>

                                                    <span>
                                                        • Offer
                                                        <?= campaignEscape(
                                                            (string) $campaign['external_offer_id']
                                                        ) ?>
                                                    </span>

                                                <?php endif; ?>

                                            </span>

                                        </div>

                                    </div>


                                    <?php if (
                                        !empty(
                                            $campaign['category']
                                        )
                                    ): ?>

                                        <span class="category-label">

                                            <?= campaignEscape(
                                                (string) $campaign['category']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Source -->

                                <td>

                                    <div class="source-stack">

                                        <span
                                            class="source-badge <?= campaignSourceClass(
                                                $sourceType
                                            ) ?>"
                                        >
                                            <?= campaignEscape(
                                                campaignSourceLabel(
                                                    $sourceType
                                                )
                                            ) ?>
                                        </span>

                                        <span class="provider-name">
                                            <?= campaignEscape(
                                                $providerName
                                            ) ?>
                                        </span>

                                    </div>

                                </td>


                                <!-- Earnings -->

                                <td class="earnings-cell">

                                    <div class="earning-row">

                                        <span>
                                            Payout
                                        </span>

                                        <strong>
                                            <?= campaignMoney(
                                                (float) $campaign['network_payout']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="earning-row worker">

                                        <span>
                                            Worker
                                        </span>

                                        <strong>
                                            <?= campaignMoney(
                                                (float) $campaign['worker_reward']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="earning-row margin">

                                        <span>
                                            Margin
                                        </span>

                                        <strong>
                                            <?= campaignMoney(
                                                (float) $campaign['platform_margin']
                                            ) ?>
                                        </strong>

                                    </div>

                                </td>


                                <!-- Targeting -->

                                <td>

                                    <div class="targeting-list">

                                        <?php if (
                                            $countries !== ''
                                        ): ?>

                                            <span class="target-pill">
                                                🌍
                                                <?= campaignEscape(
                                                    $countries
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if (
                                            $devices !== ''
                                        ): ?>

                                            <span class="target-pill">
                                                ▣
                                                <?= campaignEscape(
                                                    $devices
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if (
                                            $os !== ''
                                        ): ?>

                                            <span class="target-pill">
                                                ◉
                                                <?= campaignEscape(
                                                    $os
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if (
                                            (int) $campaign[
                                                'incentive_allowed'
                                            ] === 1
                                        ): ?>

                                            <span class="target-pill incentive">
                                                Incentive
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <!-- Status -->

                                <td>

                                    <span
                                        class="status-badge <?= campaignStatusClass(
                                            $statusValue
                                        ) ?>"
                                    >
                                        <?= campaignEscape(
                                            $statusValue
                                        ) ?>
                                    </span>

                                </td>


                                <!-- Approval -->

                                <td>

                                    <span
                                        class="approval-badge <?= campaignApprovalClass(
                                            $approvalValue
                                        ) ?>"
                                    >
                                        <?= campaignEscape(
                                            $approvalValue
                                        ) ?>
                                    </span>

                                </td>


                                <!-- Created -->

                                <td class="date-cell">

                                    <span>

                                        <?= campaignEscape(
                                            date(
                                                'M j, Y',
                                                strtotime(
                                                    (string) $campaign[
                                                        'created_at'
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </span>

                                    <small>

                                        <?= campaignEscape(
                                            date(
                                                'H:i',
                                                strtotime(
                                                    (string) $campaign[
                                                        'created_at'
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </small>

                                </td>


                                <!-- Actions -->

                                <td>

                                    <div class="campaign-actions">

                                        <?php if (
                                            $approvalValue === 'PENDING'
                                        ): ?>

                                            <!-- APPROVE -->

                                            <form
                                                method="post"
                                                class="inline-action-form"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= campaignEscape(
                                                        $csrfToken
                                                    ) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="campaign_id"
                                                    value="<?= $campaignId ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="APPROVE"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-button approve"
                                                >
                                                    Approve
                                                </button>

                                            </form>


                                            <!-- REJECT -->

                                            <form
                                                method="post"
                                                class="inline-action-form"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= campaignEscape(
                                                        $csrfToken
                                                    ) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="campaign_id"
                                                    value="<?= $campaignId ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="REJECT"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-button reject"
                                                >
                                                    Reject
                                                </button>

                                            </form>


                                        <?php elseif (
                                            $approvalValue === 'APPROVED'
                                        ): ?>


                                            <?php if (
                                                $statusValue === 'ACTIVE'
                                            ): ?>

                                                <!-- PAUSE -->

                                                <form
                                                    method="post"
                                                    class="inline-action-form"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= campaignEscape(
                                                            $csrfToken
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="campaign_id"
                                                        value="<?= $campaignId ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="PAUSE"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="action-button pause"
                                                    >
                                                        Pause
                                                    </button>

                                                </form>


                                            <?php elseif (
                                                $statusValue === 'PAUSED'
                                            ): ?>

                                                <!-- ACTIVATE -->

                                                <form
                                                    method="post"
                                                    class="inline-action-form"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= campaignEscape(
                                                            $csrfToken
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="campaign_id"
                                                        value="<?= $campaignId ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="ACTIVATE"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="action-button activate"
                                                    >
                                                        Activate
                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- =================================================
                     PAGINATION
                ================================================== -->

                <?php if (
                    $totalPages > 1
                ): ?>

                    <div class="pagination">

                        <?php if (
                            $page > 1
                        ): ?>

                            <a
                                href="<?= campaignEscape(
                                    campaignPageUrl(
                                        $queryParams,
                                        $page - 1
                                    )
                                ) ?>"
                                class="pagination-button"
                            >
                                ← Previous
                            </a>

                        <?php endif; ?>


                        <div class="pagination-pages">

                            <?php

                            $startPage =
                                max(
                                    1,
                                    $page - 2
                                );

                            $endPage =
                                min(
                                    $totalPages,
                                    $page + 2
                                );

                            for (
                                $i = $startPage;
                                $i <= $endPage;
                                $i++
                            ):
                            ?>

                                <a
                                    href="<?= campaignEscape(
                                        campaignPageUrl(
                                            $queryParams,
                                            $i
                                        )
                                    ) ?>"
                                    class="pagination-number <?= $i === $page ? 'active' : '' ?>"
                                >
                                    <?= $i ?>
                                </a>

                            <?php endfor; ?>

                        </div>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= campaignEscape(
                                    campaignPageUrl(
                                        $queryParams,
                                        $page + 1
                                    )
                                ) ?>"
                                class="pagination-button"
                            >
                                Next →
                            </a>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </section>


        <!-- =================================================
             INFORMATION
        ================================================== -->

        <section class="campaign-info">

            <div class="info-icon">
                ⓘ
            </div>

            <div>

                <strong>
                    Campaign safety
                </strong>

                <p>
                    Campaign approval controls whether an offer can become
                    available to workers. Approved campaigns can still be
                    paused at any time by an administrator.
                </p>

            </div>

        </section>

    </main>

</div>


<script>

(function () {

    const menuButton =
        document.getElementById('mobileMenuButton');

    const navigation =
        document.getElementById('mobileNavigation');

    if (!menuButton || !navigation) {
        return;
    }

    menuButton.addEventListener(
        'click',
        function () {

            const isOpen =
                navigation.classList.toggle('open');

            menuButton.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );

            menuButton.setAttribute(
                'aria-label',
                isOpen
                    ? 'Close menu'
                    : 'Open menu'
            );
        }
    );


    navigation
        .querySelectorAll('a')
        .forEach(
            function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        navigation.classList.remove(
                            'open'
                        );

                        menuButton.setAttribute(
                            'aria-expanded',
                            'false'
                        );

                        menuButton.setAttribute(
                            'aria-label',
                            'Open menu'
                        );

                    }
                );

            }
        );


    document.addEventListener(
        'click',
        function (event) {

            if (
                !navigation.contains(
                    event.target
                ) &&
                !menuButton.contains(
                    event.target
                )
            ) {

                navigation.classList.remove(
                    'open'
                );

                menuButton.setAttribute(
                    'aria-expanded',
                    'false'
                );

            }

        }
    );

})();

</script>

</body>
</html>
