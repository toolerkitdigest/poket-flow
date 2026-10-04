<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Conversions';

$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));

$allowedStatuses = [
    'PENDING',
    'APPROVED',
    'REJECTED',
    'REVERSED',
];

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 20;

$page = max(1, (int) ($_GET['page'] ?? 1));

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Build WHERE clause
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 'c.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = '(
        CAST(c.id AS CHAR) LIKE ?
        OR u.name LIKE ?
        OR u.email LIKE ?
        OR cp.title LIKE ?
        OR cp.external_offer_id LIKE ?
        OR c.external_transaction_id LIKE ?
    )';

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSql = '';

if ($where) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$totalConversions = (int) $pdo->query(
    "SELECT COUNT(*) FROM conversions"
)->fetchColumn();

$pendingConversions = (int) $pdo->query(
    "SELECT COUNT(*) FROM conversions WHERE status = 'PENDING'"
)->fetchColumn();

$approvedConversions = (int) $pdo->query(
    "SELECT COUNT(*) FROM conversions WHERE status = 'APPROVED'"
)->fetchColumn();

$rejectedConversions = (int) $pdo->query(
    "SELECT COUNT(*) FROM conversions WHERE status = 'REJECTED'"
)->fetchColumn();

$reversedConversions = (int) $pdo->query(
    "SELECT COUNT(*) FROM conversions WHERE status = 'REVERSED'"
)->fetchColumn();

$totalWorkerRewards = (float) $pdo->query(
    "SELECT COALESCE(SUM(worker_reward), 0)
     FROM conversions
     WHERE status = 'APPROVED'"
)->fetchColumn();

$totalPlatformRevenue = (float) $pdo->query(
    "SELECT COALESCE(SUM(platform_margin), 0)
     FROM conversions
     WHERE status = 'APPROVED'"
)->fetchColumn();

/*
|--------------------------------------------------------------------------
| Total filtered records
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*)
    FROM conversions c
    INNER JOIN users u
        ON u.id = c.worker_id
    LEFT JOIN campaigns cp
        ON cp.id = c.campaign_id
    $whereSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalFiltered = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalFiltered / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load conversions
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.campaign_id,
        c.worker_id,
        c.network_id,
        c.external_transaction_id,
        c.network_payout,
        c.reward_rate,
        c.worker_reward,
        c.platform_margin,
        c.status,
        c.converted_at,
        c.created_at,

        u.name AS worker_name,
        u.email AS worker_email,

        cp.title AS campaign_title,
        cp.external_offer_id,

        n.name AS network_name

    FROM conversions c

    INNER JOIN users u
        ON u.id = c.worker_id

    LEFT JOIN campaigns cp
        ON cp.id = c.campaign_id

    LEFT JOIN networks n
        ON n.id = c.network_id

    $whereSql

    ORDER BY
        CASE c.status
            WHEN 'PENDING' THEN 1
            WHEN 'APPROVED' THEN 2
            WHEN 'REJECTED' THEN 3
            WHEN 'REVERSED' THEN 4
            ELSE 5
        END,
        COALESCE(c.converted_at, c.created_at) DESC

    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$conversions = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function conversionStatusLabel(string $status): string
{
    return match ($status) {
        'APPROVED' => 'Approved',
        'PENDING' => 'Pending',
        'REJECTED' => 'Rejected',
        'REVERSED' => 'Reversed',
        default => ucfirst(strtolower($status)),
    };
}

function conversionStatusClass(string $status): string
{
    return match ($status) {
        'APPROVED' => 'status-approved',
        'PENDING' => 'status-pending',
        'REJECTED' => 'status-rejected',
        'REVERSED' => 'status-reversed',
        default => 'status-default',
    };
}

function conversionsQuery(array $extra = []): string
{
    $query = array_merge(
        [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
        ],
        $extra
    );

    $query = array_filter(
        $query,
        static fn ($value): bool => $value !== null && $value !== ''
    );

    return http_build_query($query);
}

/*
|--------------------------------------------------------------------------
| Admin Layout
|--------------------------------------------------------------------------
*/

$adminName = $adminUser['name'] ?? 'Administrator';

$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= e($pageTitle) ?> - PoketFlow Admin
    </title>


    link
        rel="stylesheet"
        href="assets/poketflow.css">
    



    <link
        rel="stylesheet"
        href="assets/conversions.css">

</head>

<body>


    <!-- Sidebar -->

    <aside class="admin-sidebar">

        <div class="admin-brand">

            <a href="index.php">
                PoketFlow
                <span>Admin</span>
            </a>

        </div>

        <nav class="admin-nav">

            <div class="nav-section-title">
                Overview
            </div>

            <a
                href="index.php"
                class="<?= $currentPage === 'index.php' ? 'active' : '' ?>"
            >
                <span>▦</span>
                Dashboard
            </a>

            <div class="nav-section-title">
                Users
            </div>

            <a
                href="users.php"
                class="<?= $currentPage === 'users.php' || $currentPage === 'user-view.php' ? 'active' : '' ?>"
            >
                <span>◉</span>
                Users
            </a>

            <div class="nav-section-title">
                Finance
            </div>

            <a
                href="withdrawals.php"
                class="<?= $currentPage === 'withdrawals.php' || $currentPage === 'withdrawal-view.php' ? 'active' : '' ?>"
            >
                <span>⇩</span>
                Withdrawals
            </a>

            <a
                href="conversions.php"
                class="<?= $currentPage === 'conversions.php' || $currentPage === 'conversion-view.php' ? 'active' : '' ?>"
            >
                <span>↗</span>
                Conversions
            </a>

            <a
                href="wallet.php"
                class="<?= $currentPage === 'wallet.php' ? 'active' : '' ?>"
            >
                <span>◈</span>
                Wallet
            </a>

            <div class="nav-section-title">
                Offers
            </div>

            <a
                href="campaigns.php"
                class="<?= $currentPage === 'campaigns.php' ? 'active' : '' ?>"
            >
                <span>▤</span>
                Campaigns
            </a>

            <div class="nav-section-title">
                System
            </div>

            <a
                href="settings.php"
                class="<?= $currentPage === 'settings.php' ? 'active' : '' ?>"
            >
                <span>⚙</span>
                Settings
            </a>

        </nav>

        <div class="sidebar-bottom">

            <a
                href="../index.php"
                class="sidebar-link"
                target="_blank"
            >
                <span>↗</span>
                View Site
            </a>

            <a
                href="../logout.php"
                class="sidebar-link logout-link"
            >
                <span>⇥</span>
                Logout
            </a>

        </div>

    </aside>

    <!-- Main -->

    <main class="admin-main">

        <header class="admin-topbar">

            <div class="topbar-title">

                <div class="mobile-menu-placeholder"></div>

                <div>
                    <h1>
                        <?= e($pageTitle) ?>
                    </h1>

                    <p>
                        Admin Control Center
                    </p>
                </div>

            </div>

            <div class="admin-user">

                <div class="admin-avatar">
                    <?= e(
                        strtoupper(
                            substr(
                                (string) $adminName,
                                0,
                                1
                            )
                        )
                    ) ?>
                </div>

                <div class="admin-user-info">

                    <strong>
                        <?= e((string) $adminName) ?>
                    </strong>

                    <span>
                        Administrator
                    </span>

                </div>

            </div>

        </header>

        <div class="admin-content">

            <!-- Page Header -->

            <div class="page-header">

                <div>

                    <h2>
                        Conversions
                    </h2>

                    <p>
                        Monitor offer conversions, worker rewards and platform revenue.
                    </p>

                </div>

            </div>

            <!-- Statistics -->

            <div class="stats-grid">

                <div class="stat-card">

                    <div class="stat-label">
                        Total Conversions
                    </div>

                    <div class="stat-value">
                        <?= number_format($totalConversions) ?>
                    </div>

                </div>

                <div class="stat-card">

                    <div class="stat-label">
                        Pending
                    </div>

                    <div class="stat-value">
                        <?= number_format($pendingConversions) ?>
                    </div>

                </div>

                <div class="stat-card">

                    <div class="stat-label">
                        Approved
                    </div>

                    <div class="stat-value">
                        <?= number_format($approvedConversions) ?>
                    </div>

                </div>

                <div class="stat-card">

                    <div class="stat-label">
                        Rejected
                    </div>

                    <div class="stat-value">
                        <?= number_format($rejectedConversions) ?>
                    </div>

                </div>

                <div class="stat-card">

                    <div class="stat-label">
                        Reversed
                    </div>

                    <div class="stat-value">
                        <?= number_format($reversedConversions) ?>
                    </div>

                </div>

                <div class="stat-card">

                    <div class="stat-label">
                        Worker Rewards
                    </div>

                    <div class="stat-value">
                        $<?= number_format($totalWorkerRewards, 2) ?>
                    </div>

                </div>

                <div class="stat-card">

                    <div class="stat-label">
                        Platform Revenue
                    </div>

                    <div class="stat-value">
                        $<?= number_format($totalPlatformRevenue, 2) ?>
                    </div>

                </div>

            </div>

            <!-- Filters -->

            <div class="filters-card">

                <form
                    method="get"
                    class="filters-form"
                >

                    <div class="filter-group search-group">

                        <label for="search">
                            Search
                        </label>

                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Worker, email, offer, transaction ID..."
                        >

                    </div>

                    <div class="filter-group">

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

                            <?php foreach ($allowedStatuses as $option): ?>

                                <option
                                    value="<?= e($option) ?>"
                                    <?= $status === $option ? 'selected' : '' ?>
                                >
                                    <?= e(
                                        conversionStatusLabel($option)
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="filter-actions">

                        <button
                            type="submit"
                            class="btn-primary"
                        >
                            Filter
                        </button>

                        <a
                            href="conversions.php"
                            class="btn-secondary"
                        >
                            Reset
                        </a>

                    </div>

                </form>

            </div>

            <!-- Conversion Table -->

            <div class="table-card">

                <div class="table-header">

                    <div>

                        <h2>
                            Conversion Records
                        </h2>

                        <span>
                            <?= number_format($totalFiltered) ?>
                            record<?= $totalFiltered === 1 ? '' : 's' ?>
                            found
                        </span>

                    </div>

                </div>

                <?php if (!$conversions): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            ↗
                        </div>

                        <h3>
                            No conversions found
                        </h3>

                        <p>
                            There are no conversion records matching your current filters.
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table>

                            <thead>

                                <tr>
                                    <th>ID</th>
                                    <th>Worker</th>
                                    <th>Offer</th>
                                    <th>Network</th>
                                    <th>Payout</th>
                                    <th>Worker Reward</th>
                                    <th>Platform Margin</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($conversions as $conversion): ?>

                                <?php

                                $conversionId = (int) $conversion['id'];

                                $conversionStatus = strtoupper(
                                    (string) $conversion['status']
                                );

                                ?>

                                <tr>

                                    <td>
                                        <strong>
                                            #<?= $conversionId ?>
                                        </strong>
                                    </td>

                                    <td>

                                        <div class="worker-cell">

                                            <strong>
                                                <?= e(
                                                    (string) $conversion['worker_name']
                                                ) ?>
                                            </strong>

                                            <span>
                                                <?= e(
                                                    (string) $conversion['worker_email']
                                                ) ?>
                                            </span>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="offer-cell">

                                            <strong>
                                                <?= e(
                                                    (string) (
                                                        $conversion['campaign_title']
                                                        ?: 'Unknown offer'
                                                    )
                                                ) ?>
                                            </strong>

                                            <?php if (!empty($conversion['external_offer_id'])): ?>

                                                <span>
                                                    Offer #
                                                    <?= e(
                                                        (string) $conversion['external_offer_id']
                                                    ) ?>
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                    <td>
                                        <?= e(
                                            (string) (
                                                $conversion['network_name']
                                                ?: '—'
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        $<?= number_format(
                                            (float) (
                                                $conversion['network_payout']
                                                ?? 0
                                            ),
                                            2
                                        ) ?>
                                    </td>

                                    <td class="money-positive">
                                        $<?= number_format(
                                            (float) $conversion['worker_reward'],
                                            2
                                        ) ?>
                                    </td>

                                    <td class="money-platform">
                                        $<?= number_format(
                                            (float) $conversion['platform_margin'],
                                            2
                                        ) ?>
                                    </td>

                                    <td>

                                        <span
                                            class="status-badge <?= e(
                                                conversionStatusClass(
                                                    $conversionStatus
                                                )
                                            ) ?>"
                                        >
                                            <?= e(
                                                conversionStatusLabel(
                                                    $conversionStatus
                                                )
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <span class="date-cell">
                                            <?= e(
                                                (string) (
                                                    $conversion['converted_at']
                                                    ?: $conversion['created_at']
                                                )
                                            ) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <a
                                            href="conversion-view.php?id=<?= $conversionId ?>"
                                            class="btn-view"
                                        >
                                            View
                                        </a>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if ($totalPages > 1): ?>

                        <div class="pagination">

                            <?php if ($page > 1): ?>

                                <a
                                    href="?<?= e(
                                        conversionsQuery([
                                            'page' => $page - 1
                                        ])
                                    ) ?>"
                                    class="page-link"
                                >
                                    ← Previous
                                </a>

                            <?php else: ?>

                                <span></span>

                            <?php endif; ?>

                            <div class="page-info">

                                Page
                                <?= number_format($page) ?>
                                of
                                <?= number_format($totalPages) ?>

                            </div>

                            <?php if ($page < $totalPages): ?>

                                <a
                                    href="?<?= e(
                                        conversionsQuery([
                                            'page' => $page + 1
                                        ])
                                    ) ?>"
                                    class="page-link"
                                >
                                    Next →
                                </a>

                            <?php else: ?>

                                <span></span>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

</body>
</html>
