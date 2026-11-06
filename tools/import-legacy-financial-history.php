<?php
declare(strict_types=1);

/**
 * Import completed legacy worker wallet transactions and withdrawal history.
 *
 * Default mode is a read-only report. Writes require --apply plus typing the
 * exact database name. Run only against a verified disposable clone first.
 * Legacy users, password hashes and source financial tables are never changed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = in_array('--apply', $argv, true);
foreach ($argv as $arg) {
    if ($arg !== $argv[0] && $arg !== '--apply') {
        fwrite(STDERR, "Usage: php tools/import-legacy-financial-history.php [--apply]\n");
        exit(2);
    }
}

$configFile = getenv('POKETFLOW_CONFIG') ?: '/home/poketflo/private/poketflow-main-database.php';
if (!is_file($configFile) || !is_readable($configFile)) {
    fwrite(STDERR, "Private database configuration is not installed.\n");
    exit(1);
}
$config = require $configFile;
if (!is_array($config) || empty($config['dbname']) || empty($config['encryption_key'])) {
    fwrite(STDERR, "Database or encryption configuration is incomplete.\n");
    exit(1);
}

$key = base64_decode((string) $config['encryption_key'], true);
if ($key === false || strlen($key) !== 32 || !function_exists('openssl_encrypt')) {
    fwrite(STDERR, "A valid 32-byte AES-256-GCM encryption key is required.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset'] ?? 'utf8mb4'),
    $config['username'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$requiredTables = [
    'users', 'wallet_transactions', 'withdrawals', 'campaigns', 'conversions',
    'pf_wallets', 'pf_wallet_ledger', 'pf_withdrawals', 'pf_legacy_history',
];
foreach ($requiredTables as $table) {
    $check = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    if (!$check->fetchColumn()) {
        fwrite(STDERR, "Required table is missing: {$table}. Apply and verify the required schema migrations on a disposable database first.\n");
        exit(1);
    }
}

$legacyColumns = static function (PDO $pdo, string $table): array {
    return array_column($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(), 'Field');
};
$walletColumns = $legacyColumns($pdo, 'wallet_transactions');
$withdrawalColumns = $legacyColumns($pdo, 'withdrawals');
$requiredWalletColumns = ['id','user_id','type','reference_type','reference_id','amount','currency','status','description','created_at'];
$requiredWithdrawalColumns = ['id','user_id','amount','method','method_network','payment_details','status','admin_note','transaction_reference','processed_by','processed_at','created_at','updated_at'];
foreach ($requiredWalletColumns as $column) {
    if (!in_array($column, $walletColumns, true)) {
        fwrite(STDERR, "Legacy wallet_transactions schema mismatch: missing {$column}. No data changed.\n");
        exit(1);
    }
}
foreach ($requiredWithdrawalColumns as $column) {
    if (!in_array($column, $withdrawalColumns, true)) {
        fwrite(STDERR, "Legacy withdrawals schema mismatch: missing {$column}. No data changed.\n");
        exit(1);
    }
}
$destinationWithdrawalColumns = $legacyColumns($pdo, 'pf_withdrawals');
foreach (['legacy_withdrawal_id','method_network','hold_ledger_entry_id','destination_encrypted'] as $column) {
    if (!in_array($column, $destinationWithdrawalColumns, true)) {
        fwrite(STDERR, "Run migration 20261106_003_legacy_financial_preservation.sql first; missing pf_withdrawals.{$column}.\n");
        exit(1);
    }
}

$unsupportedCounts = [];
foreach (['advertisers','advertiser_transactions','ad_campaigns'] as $table) {
    $check = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    if ($check->fetchColumn()) {
        $unsupportedCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
}
$campaignCount = (int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
$conversionCount = (int) $pdo->query('SELECT COUNT(*) FROM conversions')->fetchColumn();
$walletSummary = $pdo->query(
    "SELECT user_id,currency,COUNT(*) AS transactions,COALESCE(SUM(amount),0) AS total
     FROM wallet_transactions
     WHERE status='COMPLETED' OR (status='PENDING' AND type='WITHDRAWAL')
     GROUP BY user_id,currency ORDER BY user_id,currency"
)->fetchAll();
$withdrawalSummary = $pdo->query(
    'SELECT status,COUNT(*) AS total FROM withdrawals GROUP BY status ORDER BY status'
)->fetchAll();
$walletCount = (int) $pdo->query("SELECT COUNT(*) FROM wallet_transactions WHERE status='COMPLETED' OR (status='PENDING' AND type='WITHDRAWAL')")->fetchColumn();
$withdrawalCount = (int) $pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
$unmatchedPaidWithdrawals = (int) $pdo->query(
    "SELECT COUNT(*) FROM withdrawals w
     WHERE w.status='PAID' AND NOT EXISTS (
       SELECT 1 FROM wallet_transactions t
       WHERE t.user_id=w.user_id AND t.type='WITHDRAWAL'
         AND LOWER(COALESCE(t.reference_type,''))='withdrawal'
         AND t.reference_id=w.id AND t.status='COMPLETED'
     )"
)->fetchColumn();

echo "POKETFLOW LEGACY FINANCIAL IMPORT\n";
echo "Database: " . $config['dbname'] . "\n";
echo "Mode: " . ($apply ? 'APPLY REQUESTED' : 'READ-ONLY DRY RUN') . "\n";
echo "Wallet transactions to preserve (completed entries + pending withdrawal holds): {$walletCount}\n";
foreach ($walletSummary as $row) {
    printf("  user_id=%d | currency=%s | transactions=%d | signed_total=%s\n",
        (int) $row['user_id'], (string) $row['currency'], (int) $row['transactions'], (string) $row['total']);
}
echo "Withdrawal history rows to preserve: {$withdrawalCount}\n";
echo "Paid withdrawals without a completed legacy debit: {$unmatchedPaidWithdrawals}\n";
echo "Inactive campaign history rows to archive: {$campaignCount}\n";
echo "Historical conversion rows to archive: {$conversionCount}\n";
foreach ($withdrawalSummary as $row) {
    printf("  status=%s | count=%d\n", (string) $row['status'], (int) $row['total']);
}
foreach ($unsupportedCounts as $table => $count) {
    echo "Legacy {$table} rows: {$count}\n";
}
if (array_sum($unsupportedCounts) > 0) {
    fwrite(STDERR, "Advertiser financial/campaign records exist. This worker-history importer will not run until a separate reconciliation/import path is prepared.\n");
    exit(1);
}
if ($unmatchedPaidWithdrawals > 0) {
    fwrite(STDERR, "Paid legacy withdrawals are missing their completed wallet debits. Manual reconciliation is required before import.\n");
    exit(1);
}

if (!$apply) {
    echo "Dry run complete. No records were changed. Review the report and test on a disposable database before using --apply.\n";
    exit(0);
}

$confirmation = 'IMPORT LEGACY FINANCIAL HISTORY INTO ' . $config['dbname'];
echo "This will add historical rows to pf_* tables only.\n";
echo "Type exactly: {$confirmation}\n> ";
$typed = trim((string) fgets(STDIN));
if (!hash_equals($confirmation, $typed)) {
    fwrite(STDERR, "Confirmation did not match. No records changed.\n");
    exit(1);
}

$encrypt = static function (string $plain, string $key): string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt legacy withdrawal destination.');
    }
    return base64_encode($iv . $tag . $cipher);
};

$pdo->beginTransaction();
try {
    $userCheck = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
    $walletInsert = $pdo->prepare(
        "INSERT IGNORE INTO pf_wallets (user_id,wallet_type,currency) VALUES (?,'WORKER',?)"
    );
    $walletFind = $pdo->prepare(
        "SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='WORKER' AND currency=? FOR UPDATE"
    );
    $ledgerFind = $pdo->prepare(
        'SELECT id,wallet_id,user_id,amount,currency,reference_type,reference_id
         FROM pf_wallet_ledger WHERE idempotency_key=? FOR UPDATE'
    );
    $ledgerInsert = $pdo->prepare(
        "INSERT INTO pf_wallet_ledger
         (wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_at)
         VALUES (?,?,'LEGACY_IMPORT',?,?,'LEGACY_WALLET_TXN',?,?,?,?)"
    );

    $legacyTransactions = $pdo->query(
        "SELECT id,user_id,type,reference_type,reference_id,amount,currency,description,created_at
         FROM wallet_transactions
          WHERE status='COMPLETED' OR (status='PENDING' AND type='WITHDRAWAL')
          ORDER BY id FOR UPDATE"
    )->fetchAll();

    $importedTransactions = 0;
    foreach ($legacyTransactions as $row) {
        $userCheck->execute([(int) $row['user_id']]);
        if (!$userCheck->fetchColumn()) {
            throw new RuntimeException('Legacy wallet transaction references a missing user ID: ' . (int) $row['user_id']);
        }
        $currency = strtoupper((string) $row['currency']);
        if (!preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new RuntimeException('Invalid currency on legacy wallet transaction ID ' . (int) $row['id']);
        }
        $walletInsert->execute([(int) $row['user_id'], $currency]);
        $walletFind->execute([(int) $row['user_id'], $currency]);
        $walletId = (int) $walletFind->fetchColumn();
        $keyName = 'legacy-wallet-txn-' . (int) $row['id'];
        $ledgerFind->execute([$keyName]);
        $existing = $ledgerFind->fetch();
        if ($existing) {
            if ((int) $existing['user_id'] !== (int) $row['user_id']
                || (int) $existing['wallet_id'] !== $walletId
                || (string) $existing['amount'] !== (string) $row['amount']
                || (string) $existing['currency'] !== $currency
                || $existing['reference_type'] !== 'LEGACY_WALLET_TXN'
                || (int) $existing['reference_id'] !== (int) $row['id']) {
                throw new RuntimeException('Existing legacy wallet import does not match source transaction ID ' . (int) $row['id']);
            }
            continue;
        }
        $description = 'Legacy ' . (string) $row['type'] . ': ' . (string) ($row['description'] ?? '');
        $ledgerInsert->execute([
            $walletId,
            (int) $row['user_id'],
            $row['amount'],
            $currency,
            (int) $row['id'],
            $keyName,
            mb_substr($description, 0, 255),
            $row['created_at'],
        ]);
        $importedTransactions++;
    }

    $withdrawalSelect = $pdo->query(
        'SELECT id,user_id,amount,method,method_network,payment_details,status,admin_note,
                transaction_reference,processed_by,processed_at,created_at,updated_at
         FROM withdrawals ORDER BY id FOR UPDATE'
    );
    $legacyWithdrawals = $withdrawalSelect->fetchAll();
    $withdrawalCheck = $pdo->prepare('SELECT id,user_id,amount,method,status FROM pf_withdrawals WHERE legacy_withdrawal_id=? FOR UPDATE');
    $withdrawalInsert = $pdo->prepare(
        'INSERT INTO pf_withdrawals
         (legacy_withdrawal_id,user_id,wallet_id,amount,currency,method,method_network,destination_encrypted,
          status,hold_ledger_entry_id,payout_reference,reviewed_by,reviewed_at,admin_note,created_at,updated_at)
         VALUES (?,?,?,?,"USD",?,?,?,?,?,?,?,?,?,?,?)'
    );
    $holdSource = $pdo->prepare(
        "SELECT id FROM wallet_transactions
         WHERE user_id=? AND type='WITHDRAWAL' AND LOWER(COALESCE(reference_type,''))='withdrawal'
           AND reference_id=? ORDER BY id LIMIT 1"
    );
    $holdLedger = $pdo->prepare(
        "SELECT id FROM pf_wallet_ledger WHERE idempotency_key=? LIMIT 1"
    );

    $importedWithdrawals = 0;
    foreach ($legacyWithdrawals as $row) {
        $legacyId = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $userCheck->execute([$userId]);
        if (!$userCheck->fetchColumn()) {
            throw new RuntimeException('Legacy withdrawal references a missing user ID: ' . $userId);
        }
        $status = strtoupper((string) $row['status']);
        if (!in_array($status, ['PENDING','PROCESSING','PAID','REJECTED','CANCELLED'], true)) {
            throw new RuntimeException('Unsupported legacy withdrawal status on ID ' . $legacyId);
        }
        $method = strtoupper(trim((string) $row['method']));
        if ($method === '' || mb_strlen($method) > 40) {
            throw new RuntimeException('Unsupported legacy withdrawal method on ID ' . $legacyId);
        }
        $walletInsert->execute([$userId, 'USD']);
        $walletFind->execute([$userId, 'USD']);
        $walletId = (int) $walletFind->fetchColumn();

        $withdrawalCheck->execute([$legacyId]);
        $existing = $withdrawalCheck->fetch();
        if ($existing) {
            if ((int) $existing['user_id'] !== $userId
                || (string) $existing['amount'] !== (string) $row['amount']
                || (string) $existing['method'] !== $method
                || (string) $existing['status'] !== $status) {
                throw new RuntimeException('Existing legacy withdrawal import does not match source withdrawal ID ' . $legacyId);
            }
            continue;
        }

        $holdSource->execute([$userId, $legacyId]);
        $sourceHoldId = $holdSource->fetchColumn();
        $holdLedgerId = null;
        if ($sourceHoldId) {
            $holdLedger->execute(['legacy-wallet-txn-' . (int) $sourceHoldId]);
            $holdLedgerId = $holdLedger->fetchColumn() ?: null;
        }
        if (in_array($status, ['PENDING','PROCESSING'], true) && !$holdLedgerId) {
            throw new RuntimeException('Open legacy withdrawal ' . $legacyId . ' has no matching completed wallet debit. Manual reconciliation is required.');
        }

        $destination = (string) $row['payment_details'];
        $encryptedDestination = $encrypt($destination, $key);
        $withdrawalInsert->execute([
            $legacyId,
            $userId,
            $walletId,
            $row['amount'],
            $method,
            $row['method_network'] !== null ? (string) $row['method_network'] : null,
            $encryptedDestination,
            $status,
            $holdLedgerId,
            $row['transaction_reference'] !== null ? (string) $row['transaction_reference'] : null,
            $row['processed_by'] !== null ? (int) $row['processed_by'] : null,
            $row['processed_at'],
            $row['admin_note'],
            $row['created_at'],
            $row['updated_at'],
        ]);
        $importedWithdrawals++;
    }

    // Archive historical campaign and conversion facts without restoring delivery.
    // The network_offer_url column is intentionally excluded because it may contain
    // old affiliate credentials or tracking tokens.
    $campaignRequired = ['id','source_type','advertiser_id','network_id','external_offer_id','image_url','title','description','category','instructions','network_payout','reward_rate','worker_reward','platform_margin','budget','spent','countries','devices','os','incentive_allowed','status','approval_status','start_at','end_at','created_at','updated_at'];
    $campaignColumns = $legacyColumns($pdo, 'campaigns');
    foreach ($campaignRequired as $column) {
        if (!in_array($column, $campaignColumns, true)) throw new RuntimeException('Legacy campaigns schema mismatch: missing ' . $column);
    }
    $conversionRequired = ['id','campaign_id','worker_id','network_id','external_transaction_id','network_payout','reward_rate','worker_reward','platform_margin','status','converted_at','created_at','updated_at'];
    $conversionColumns = $legacyColumns($pdo, 'conversions');
    foreach ($conversionRequired as $column) {
        if (!in_array($column, $conversionColumns, true)) throw new RuntimeException('Legacy conversions schema mismatch: missing ' . $column);
    }

    $archiveFind = $pdo->prepare('SELECT owner_user_id FROM pf_legacy_history WHERE source_table=? AND legacy_id=? FOR UPDATE');
    $archiveInsert = $pdo->prepare('INSERT INTO pf_legacy_history (source_table,legacy_id,owner_user_id,snapshot_json,source_created_at) VALUES (?,?,?,?,?)');
    $campaignRows = $pdo->query('SELECT id,source_type,advertiser_id,network_id,external_offer_id,image_url,title,description,category,instructions,network_payout,reward_rate,worker_reward,platform_margin,budget,spent,countries,devices,os,incentive_allowed,status,approval_status,start_at,end_at,created_at,updated_at FROM campaigns ORDER BY id FOR UPDATE')->fetchAll();
    $archivedCampaigns = 0;
    foreach ($campaignRows as $row) {
        $archiveFind->execute(['campaigns', (int) $row['id']]);
        $existing = $archiveFind->fetch();
        // Legacy campaigns.advertiser_id refers to advertisers.id, not users.id.
        // Keep owner_user_id null unless a separately verified mapping is imported.
        $ownerId = null;
        if ($existing) {
            if (($existing['owner_user_id'] === null ? null : (int) $existing['owner_user_id']) !== $ownerId) throw new RuntimeException('Existing legacy campaign archive does not match campaign ID ' . (int) $row['id']);
            continue;
        }
        $archiveInsert->execute(['campaigns',(int) $row['id'],$ownerId,json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),$row['created_at']]);
        $archivedCampaigns++;
    }

    $conversionRows = $pdo->query('SELECT id,campaign_id,worker_id,network_id,external_transaction_id,network_payout,reward_rate,worker_reward,platform_margin,status,converted_at,created_at,updated_at FROM conversions ORDER BY id FOR UPDATE')->fetchAll();
    $archivedConversions = 0;
    foreach ($conversionRows as $row) {
        $archiveFind->execute(['conversions', (int) $row['id']]);
        $existing = $archiveFind->fetch();
        $ownerId = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        if ($existing) {
            if (($existing['owner_user_id'] === null ? null : (int) $existing['owner_user_id']) !== $ownerId) throw new RuntimeException('Existing legacy conversion archive does not match conversion ID ' . (int) $row['id']);
            continue;
        }
        $archiveInsert->execute(['conversions',(int) $row['id'],$ownerId,json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),$row['created_at']]);
        $archivedConversions++;
    }

    // Reconcile imported history against the legacy source before commit.
    $expectedWalletRows = (int)$pdo->query("SELECT COUNT(*) FROM wallet_transactions WHERE status='COMPLETED' OR (status='PENDING' AND type='WITHDRAWAL')")->fetchColumn();
    $actualWalletRows = (int)$pdo->query("SELECT COUNT(*) FROM pf_wallet_ledger WHERE reference_type='LEGACY_WALLET_TXN' AND idempotency_key LIKE 'legacy-wallet-txn-%'")->fetchColumn();
    if ($expectedWalletRows !== $actualWalletRows) {
        throw new RuntimeException("Legacy wallet transaction count mismatch: source={$expectedWalletRows}, imported={$actualWalletRows}");
    }
    $expectedBalances = $pdo->query(
        "SELECT user_id,currency,COALESCE(SUM(amount),0) AS total
         FROM wallet_transactions
         WHERE status='COMPLETED' OR (status='PENDING' AND type='WITHDRAWAL')
         GROUP BY user_id,currency ORDER BY user_id,currency"
    )->fetchAll();
    $actualBalances = $pdo->query(
        "SELECT user_id,currency,COALESCE(SUM(amount),0) AS total
         FROM pf_wallet_ledger
         WHERE reference_type='LEGACY_WALLET_TXN' AND idempotency_key LIKE 'legacy-wallet-txn-%'
         GROUP BY user_id,currency ORDER BY user_id,currency"
    )->fetchAll();
    $balanceKey = static fn(array $row): string => (int)$row['user_id'] . ':' . strtoupper((string)$row['currency']);
    $actualByKey = [];
    foreach ($actualBalances as $row) $actualByKey[$balanceKey($row)] = (float)$row['total'];
    foreach ($expectedBalances as $row) {
        $keyName = $balanceKey($row);
        if (!array_key_exists($keyName,$actualByKey)
            || abs((float)$row['total']-$actualByKey[$keyName])>0.00001) {
            throw new RuntimeException('Legacy wallet balance reconciliation failed for account/currency ' . $keyName);
        }
        unset($actualByKey[$keyName]);
    }
    if ($actualByKey) {
        throw new RuntimeException('Unexpected extra legacy wallet import groups were found.');
    }

    $expectedWithdrawalRows = (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
    $actualWithdrawalRows = (int)$pdo->query('SELECT COUNT(*) FROM pf_withdrawals WHERE legacy_withdrawal_id IS NOT NULL')->fetchColumn();
    if ($expectedWithdrawalRows !== $actualWithdrawalRows) {
        throw new RuntimeException("Legacy withdrawal count mismatch: source={$expectedWithdrawalRows}, imported={$actualWithdrawalRows}");
    }
    foreach (['campaigns','conversions'] as $sourceTable) {
        $sourceCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . $sourceTable)->fetchColumn();
        $archiveCount = $pdo->prepare('SELECT COUNT(*) FROM pf_legacy_history WHERE source_table=?');
        $archiveCount->execute([$sourceTable]);
        if ($sourceCount !== (int)$archiveCount->fetchColumn()) {
            throw new RuntimeException('Legacy archive count mismatch for ' . $sourceTable);
        }
    }

    $pdo->commit();
    echo "Import complete. Wallet transactions added: {$importedTransactions}; withdrawal history rows added: {$importedWithdrawals}; campaign archives added: {$archivedCampaigns}; conversion archives added: {$archivedConversions}.\n";
    echo "Legacy source tables and user rows were not modified. Verify per-user balances and history before enabling member access.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Import failed; transaction rolled back: ' . $exception->getMessage() . "\n");
    exit(1);
}
