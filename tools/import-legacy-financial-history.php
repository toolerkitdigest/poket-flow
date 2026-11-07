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

$configFile = getenv('POKETFLOW_CONFIG');
if (!is_string($configFile) || trim($configFile) === '') {
    fwrite(STDERR, "Set POKETFLOW_CONFIG to an explicit disposable-database configuration. Implicit/live database defaults are disabled.\n");
    exit(1);
}
$resolvedConfigFile = realpath($configFile);
if ($resolvedConfigFile === false || !is_file($resolvedConfigFile) || !is_readable($resolvedConfigFile)) {
    fwrite(STDERR, "Explicit disposable database configuration is missing or unreadable.\n");
    exit(1);
}
$configFile = $resolvedConfigFile;
$liveConfigFile = realpath('/home/poketflo/private/poketflow-main-database.php')
    ?: '/home/poketflo/private/poketflow-main-database.php';
if ($configFile === $liveConfigFile) {
    fwrite(STDERR, "Refusing to use the live PoketFlow database configuration.\n");
    exit(1);
}
$config = require $configFile;
if (!is_array($config) || empty($config['dbname']) || empty($config['encryption_key'])) {
    fwrite(STDERR, "Database or encryption configuration is incomplete.\n");
    exit(1);
}
if (($config['environment'] ?? null) !== 'disposable'
    || strcasecmp((string) $config['dbname'], 'poketflo_db') === 0) {
    fwrite(STDERR, "Refusing import: configuration must explicitly identify a non-production disposable database.\n");
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
    'pf_legacy_advertiser_accounts', 'pf_legacy_advertiser_history',
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
$canonicalSnapshot = static function (array $row): string {
    ksort($row);
    return json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
};
$matchesSnapshot = static function (string $storedJson, array $sourceRow) use ($canonicalSnapshot): bool {
    try {
        $stored = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }
    return is_array($stored)
        && hash_equals(
            hash('sha256', $canonicalSnapshot($sourceRow)),
            hash('sha256', $canonicalSnapshot($stored))
        );
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

// Advertiser financial preservation is intentionally strict: map legacy
// advertisers.id -> advertisers.user_id -> users.id, never assume the IDs match.
$advertiserRequired = ['id','user_id','company_name','website','description','contact_email','status','balance','created_at','updated_at'];
$advertiserTransactionRequired = ['id','advertiser_id','campaign_id','type','amount','reference','status','description','created_at'];
$adCampaignRequired = ['id','advertiser_id','name','creative_type','creative_url','destination_url','placement','budget','spent','status','approval_status','start_at','end_at','created_at','updated_at'];
foreach ([
    'advertisers' => $advertiserRequired,
    'advertiser_transactions' => $advertiserTransactionRequired,
    'ad_campaigns' => $adCampaignRequired,
] as $table => $required) {
    $check = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    if (!$check->fetchColumn()) {
        fwrite(STDERR, "Required legacy advertiser table is missing: {$table}. No data changed.\n");
        exit(1);
    }
    $available = $legacyColumns($pdo, $table);
    foreach ($required as $column) {
        if (!in_array($column, $available, true)) {
            fwrite(STDERR, "Legacy {$table} schema mismatch: missing {$column}. No data changed.\n");
            exit(1);
        }
    }
}
$advertiserRows = $pdo->query(
    'SELECT id,user_id,company_name,website,description,contact_email,status,balance,created_at,updated_at
     FROM advertisers ORDER BY id'
)->fetchAll();
$advertiserUserMap = [];
foreach ($advertiserRows as $advertiser) {
    $legacyAdvertiserId = (int) $advertiser['id'];
    $userId = (int) $advertiser['user_id'];
    if ($legacyAdvertiserId < 1 || $userId < 1 || isset($advertiserUserMap[$legacyAdvertiserId])) {
        fwrite(STDERR, "Invalid or duplicate legacy advertiser mapping. No data changed.\n");
        exit(1);
    }
    $advertiserUserMap[$legacyAdvertiserId] = $userId;
}
$duplicateAdvertiserUsers = (int)$pdo->query(
    'SELECT COUNT(*) FROM (SELECT user_id FROM advertisers GROUP BY user_id HAVING COUNT(*)>1) duplicates'
)->fetchColumn();
if ($duplicateAdvertiserUsers > 0) {
    fwrite(STDERR, "Multiple legacy advertiser profiles map to the same member account. Manual reconciliation is required.\n");
    exit(1);
}
$mappingCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id=?');
foreach ($advertiserUserMap as $legacyAdvertiserId => $userId) {
    $mappingCheck->execute([$userId]);
    if ((int) $mappingCheck->fetchColumn() !== 1) {
        fwrite(STDERR, "Legacy advertiser {$legacyAdvertiserId} maps to a missing user ID {$userId}. No data changed.\n");
        exit(1);
    }
}
$advertiserTransactionCount = (int) $pdo->query('SELECT COUNT(*) FROM advertiser_transactions')->fetchColumn();
$adCampaignCount = (int) $pdo->query('SELECT COUNT(*) FROM ad_campaigns')->fetchColumn();
$orphanAdvertiserTransactions = (int) $pdo->query(
    'SELECT COUNT(*) FROM advertiser_transactions t LEFT JOIN advertisers a ON a.id=t.advertiser_id WHERE a.id IS NULL'
)->fetchColumn();
$orphanAdCampaigns = (int) $pdo->query(
    'SELECT COUNT(*) FROM ad_campaigns c LEFT JOIN advertisers a ON a.id=c.advertiser_id WHERE a.id IS NULL'
)->fetchColumn();
if ($orphanAdvertiserTransactions > 0 || $orphanAdCampaigns > 0) {
    fwrite(STDERR, "Legacy advertiser history contains orphaned advertiser references. Manual reconciliation is required.\n");
    exit(1);
}
$advertiserBalanceSummary = $pdo->query(
    "SELECT status,COUNT(*) AS accounts,COALESCE(SUM(balance),0) AS current_balance
     FROM advertisers GROUP BY status ORDER BY status"
)->fetchAll();
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
echo "Legacy advertiser accounts to preserve: " . count($advertiserRows) . "\n";
echo "Legacy advertiser transaction history rows to archive: {$advertiserTransactionCount}\n";
echo "Legacy advertiser ad campaign rows to archive: {$adCampaignCount}\n";
foreach ($advertiserBalanceSummary as $row) {
    printf("  status=%s | accounts=%d | current_balance=%s USD (legacy advertiser table has no currency column)\n",
        (string)$row['status'],(int)$row['accounts'],(string)$row['current_balance']);
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
$decrypt = static function (string $encoded, string $key): string {
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Stored legacy withdrawal destination is not valid encrypted data.');
    }
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    if ($plain === false) {
        throw new RuntimeException('Stored legacy withdrawal destination could not be authenticated.');
    }
    return $plain;
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
    $withdrawalCheck = $pdo->prepare(
        'SELECT id,user_id,wallet_id,amount,currency,method,method_network,destination_encrypted,status,
                hold_ledger_entry_id,payout_reference,reviewed_by,reviewed_at,admin_note,created_at,updated_at
         FROM pf_withdrawals WHERE legacy_withdrawal_id=? FOR UPDATE'
    );
    $withdrawalInsert = $pdo->prepare(
        'INSERT INTO pf_withdrawals
         (legacy_withdrawal_id,user_id,wallet_id,amount,currency,method,method_network,destination_encrypted,
          status,hold_ledger_entry_id,payout_reference,reviewed_by,reviewed_at,admin_note,created_at,updated_at)
         VALUES (?,?,?,?,"USD",?,?,?,?,?,?,?,?,?,?,?)'
    );
    $holdSource = $pdo->prepare(
        "SELECT id,user_id,amount,currency,status FROM wallet_transactions
         WHERE user_id=? AND type='WITHDRAWAL' AND LOWER(COALESCE(reference_type,''))='withdrawal'
           AND reference_id=? ORDER BY id FOR UPDATE"
    );
    $holdLedger = $pdo->prepare(
        "SELECT id,wallet_id,user_id,amount,currency,reference_type,reference_id
         FROM pf_wallet_ledger WHERE idempotency_key=? FOR UPDATE"
    );
    $holdAmountMatches = $pdo->prepare(
        'SELECT CAST(? AS DECIMAL(18,2)) = -CAST(? AS DECIMAL(18,2))'
    );
    $ledgerAmountMatches = $pdo->prepare(
        'SELECT CAST(? AS DECIMAL(18,2)) = CAST(? AS DECIMAL(18,2))'
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

        $holdSource->execute([$userId, $legacyId]);
        $sourceHolds = $holdSource->fetchAll();
        if (count($sourceHolds) > 1) {
            throw new RuntimeException('Legacy withdrawal ' . $legacyId . ' has multiple matching wallet debits; manual reconciliation is required.');
        }
        $holdLedgerId = null;
        if ($sourceHolds) {
            $sourceHold = $sourceHolds[0];
            if ((int)$sourceHold['user_id'] !== $userId
                || strtoupper((string)$sourceHold['currency']) !== 'USD'
                || !in_array(strtoupper((string)$sourceHold['status']), ['COMPLETED','PENDING'], true)) {
                throw new RuntimeException('Legacy withdrawal ' . $legacyId . ' has an invalid matching wallet debit.');
            }
            $holdAmountMatches->execute([$sourceHold['amount'], $row['amount']]);
            if ((int)$holdAmountMatches->fetchColumn() !== 1) {
                throw new RuntimeException('Legacy withdrawal ' . $legacyId . ' wallet debit does not exactly match the withdrawal amount.');
            }
            $sourceHoldId = (int)$sourceHold['id'];
            $holdLedger->execute(['legacy-wallet-txn-' . $sourceHoldId]);
            $holdLedgerRow = $holdLedger->fetch();
            if (!$holdLedgerRow
                || (int)$holdLedgerRow['user_id'] !== $userId
                || (int)$holdLedgerRow['wallet_id'] !== $walletId
                || strtoupper((string)$holdLedgerRow['currency']) !== 'USD'
                || $holdLedgerRow['reference_type'] !== 'LEGACY_WALLET_TXN'
                || (int)$holdLedgerRow['reference_id'] !== $sourceHoldId) {
                throw new RuntimeException('Legacy withdrawal ' . $legacyId . ' has no matching imported wallet debit.');
            }
            $ledgerAmountMatches->execute([$holdLedgerRow['amount'], $sourceHold['amount']]);
            if ((int)$ledgerAmountMatches->fetchColumn() !== 1) {
                throw new RuntimeException('Imported wallet debit does not match the source debit for withdrawal ' . $legacyId . '.');
            }
            $holdLedgerId = (int)$holdLedgerRow['id'];
        }
        if (in_array($status, ['PENDING','PROCESSING','PAID'], true) && !$holdLedgerId) {
            throw new RuntimeException('Open or paid legacy withdrawal ' . $legacyId . ' has no matching wallet debit. Manual reconciliation is required.');
        }

        $withdrawalCheck->execute([$legacyId]);
        $existing = $withdrawalCheck->fetch();
        $destination = (string) $row['payment_details'];
        if ($existing) {
            $existingDestination = $decrypt((string)$existing['destination_encrypted'], $key);
            $matches = (int)$existing['user_id'] === $userId
                && (int)$existing['wallet_id'] === $walletId
                && (string)$existing['currency'] === 'USD'
                && (string)$existing['amount'] === (string)$row['amount']
                && (string)$existing['method'] === $method
                && (string)($existing['method_network'] ?? '') === (string)($row['method_network'] ?? '')
                && hash_equals($existingDestination, $destination)
                && (string)$existing['status'] === $status
                && ($existing['hold_ledger_entry_id'] === null ? null : (int)$existing['hold_ledger_entry_id']) === $holdLedgerId
                && (string)($existing['payout_reference'] ?? '') === (string)($row['transaction_reference'] ?? '')
                && ($existing['reviewed_by'] === null ? null : (int)$existing['reviewed_by']) === ($row['processed_by'] === null ? null : (int)$row['processed_by'])
                && (string)($existing['reviewed_at'] ?? '') === (string)($row['processed_at'] ?? '')
                && (string)($existing['admin_note'] ?? '') === (string)($row['admin_note'] ?? '')
                && (string)($existing['created_at'] ?? '') === (string)($row['created_at'] ?? '')
                && (string)($existing['updated_at'] ?? '') === (string)($row['updated_at'] ?? '');
            if (!$matches) {
                throw new RuntimeException('Existing legacy withdrawal import does not fully match source withdrawal ID ' . $legacyId);
            }
            continue;
        }

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

    // Preserve advertiser profiles and the current spendable balance.
    // Historical advertiser_transactions are archived below, never replayed as
    // credits/debits: advertisers.balance is the authoritative current snapshot.
    $advertiserWalletInsert = $pdo->prepare(
        "INSERT IGNORE INTO pf_wallets (user_id,wallet_type,currency) VALUES (?,'ADVERTISER','USD')"
    );
    $advertiserWalletFind = $pdo->prepare(
        "SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency='USD' FOR UPDATE"
    );
    $advertiserAccountFind = $pdo->prepare(
        'SELECT * FROM pf_legacy_advertiser_accounts WHERE legacy_advertiser_id=? FOR UPDATE'
    );
    $advertiserAccountInsert = $pdo->prepare(
        'INSERT INTO pf_legacy_advertiser_accounts
         (legacy_advertiser_id,user_id,company_name,website,description,contact_email,legacy_status,
          legacy_balance,wallet_id,opening_ledger_entry_id,source_created_at,source_updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $advertiserLedgerInsert = $pdo->prepare(
        "INSERT INTO pf_wallet_ledger
         (wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by)
         VALUES (?,?,'LEGACY_IMPORT',?,'USD','LEGACY_ADVERTISER_BALANCE',?,?,?,NULL)"
    );
    $advertiserOpeningKey = static fn(int $id): string => 'legacy-advertiser-opening-balance-' . $id;
    $importedAdvertiserAccounts = 0;
    foreach ($advertiserRows as $row) {
        $legacyId = (int)$row['id'];
        $userId = (int)$row['user_id'];
        $advertiserWalletInsert->execute([$userId]);
        $advertiserWalletFind->execute([$userId]);
        $walletId = (int)$advertiserWalletFind->fetchColumn();
        if (!$walletId) throw new RuntimeException('Advertiser wallet could not be created for legacy advertiser ID ' . $legacyId);

        $amount = (string)$row['balance'];
        $keyName = $advertiserOpeningKey($legacyId);
        $ledgerFind->execute([$keyName]);
        $opening = $ledgerFind->fetch();
        if ($opening) {
            if ((int)$opening['user_id'] !== $userId || (int)$opening['wallet_id'] !== $walletId
                || (string)$opening['amount'] !== $amount || (string)$opening['currency'] !== 'USD'
                || $opening['reference_type'] !== 'LEGACY_ADVERTISER_BALANCE'
                || (int)$opening['reference_id'] !== $legacyId) {
                throw new RuntimeException('Existing advertiser opening balance does not match legacy advertiser ID ' . $legacyId);
            }
            $openingLedgerId = (int)$opening['id'];
        } else {
            $otherLedger = $pdo->prepare('SELECT COUNT(*) FROM pf_wallet_ledger WHERE wallet_id=?');
            $otherLedger->execute([$walletId]);
            if ((int)$otherLedger->fetchColumn() > 0) {
                throw new RuntimeException('Advertiser wallet already has non-legacy ledger activity for user ID ' . $userId . '; reconcile before importing the opening balance.');
            }
            $advertiserLedgerInsert->execute([
                $walletId,$userId,$amount,$legacyId,$keyName,
                'Opening balance imported from legacy advertiser account #' . $legacyId
            ]);
            $openingLedgerId = (int)$pdo->lastInsertId();
        }

        $advertiserAccountFind->execute([$legacyId]);
        $existingAccount = $advertiserAccountFind->fetch();
        if ($existingAccount) {
            $matches = (int)$existingAccount['user_id'] === $userId
                && (string)$existingAccount['company_name'] === (string)$row['company_name']
                && (string)($existingAccount['website'] ?? '') === (string)($row['website'] ?? '')
                && (string)($existingAccount['description'] ?? '') === (string)($row['description'] ?? '')
                && (string)($existingAccount['contact_email'] ?? '') === (string)($row['contact_email'] ?? '')
                && (string)$existingAccount['legacy_status'] === (string)$row['status']
                && (string)$existingAccount['legacy_balance'] === $amount
                && (int)$existingAccount['wallet_id'] === $walletId
                && (int)$existingAccount['opening_ledger_entry_id'] === $openingLedgerId;
            if (!$matches) throw new RuntimeException('Existing legacy advertiser account archive does not match source ID ' . $legacyId);
            continue;
        }
        $advertiserAccountInsert->execute([
            $legacyId,$userId,$row['company_name'],$row['website'],$row['description'],
            $row['contact_email'],$row['status'],$amount,$walletId,$openingLedgerId,
            $row['created_at'],$row['updated_at']
        ]);
        $importedAdvertiserAccounts++;
    }

    // Archive every advertiser transaction status and old ad creative. No old
    // campaign is reactivated, and historical transaction amounts are not replayed.
    $advertiserHistoryFind = $pdo->prepare(
        'SELECT owner_user_id,legacy_advertiser_id,snapshot_json FROM pf_legacy_advertiser_history WHERE source_table=? AND legacy_id=? FOR UPDATE'
    );
    $advertiserHistoryInsert = $pdo->prepare(
        'INSERT INTO pf_legacy_advertiser_history
         (source_table,legacy_id,legacy_advertiser_id,owner_user_id,snapshot_json,source_created_at)
         VALUES (?,?,?,?,?,?)'
    );
    $importedAdvertiserTransactions = 0;
    $legacyAdvertiserTransactions = $pdo->query(
        'SELECT id,advertiser_id,campaign_id,type,amount,reference,status,description,created_at
         FROM advertiser_transactions ORDER BY id FOR UPDATE'
    )->fetchAll();
    foreach ($legacyAdvertiserTransactions as $row) {
        $legacyAdvertiserId = (int)$row['advertiser_id'];
        if (!isset($advertiserUserMap[$legacyAdvertiserId])) {
            throw new RuntimeException('Advertiser transaction references an unmapped legacy advertiser ID ' . $legacyAdvertiserId);
        }
        $advertiserHistoryFind->execute(['advertiser_transactions',(int)$row['id']]);
        $existing = $advertiserHistoryFind->fetch();
        if ($existing) {
            if ((int)$existing['legacy_advertiser_id'] !== $legacyAdvertiserId
                || (int)$existing['owner_user_id'] !== $advertiserUserMap[$legacyAdvertiserId]
                || !$matchesSnapshot((string)$existing['snapshot_json'], $row)) {
                throw new RuntimeException('Existing advertiser transaction archive does not match source transaction ID ' . (int)$row['id']);
            }
            continue;
        }
        $advertiserHistoryInsert->execute([
            'advertiser_transactions',(int)$row['id'],$legacyAdvertiserId,
            $advertiserUserMap[$legacyAdvertiserId],
            json_encode($row,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),$row['created_at']
        ]);
        $importedAdvertiserTransactions++;
    }

    $importedAdCampaigns = 0;
    $legacyAdCampaigns = $pdo->query(
        'SELECT id,advertiser_id,name,creative_type,creative_url,destination_url,placement,budget,spent,
                status,approval_status,start_at,end_at,created_at,updated_at
         FROM ad_campaigns ORDER BY id FOR UPDATE'
    )->fetchAll();
    foreach ($legacyAdCampaigns as $row) {
        $legacyAdvertiserId = (int)$row['advertiser_id'];
        if (!isset($advertiserUserMap[$legacyAdvertiserId])) {
            throw new RuntimeException('Legacy ad campaign references an unmapped advertiser ID ' . $legacyAdvertiserId);
        }
        $advertiserHistoryFind->execute(['ad_campaigns',(int)$row['id']]);
        $existing = $advertiserHistoryFind->fetch();
        if ($existing) {
            if ((int)$existing['legacy_advertiser_id'] !== $legacyAdvertiserId
                || (int)$existing['owner_user_id'] !== $advertiserUserMap[$legacyAdvertiserId]
                || !$matchesSnapshot((string)$existing['snapshot_json'], $row)) {
                throw new RuntimeException('Existing advertiser ad archive does not match source campaign ID ' . (int)$row['id']);
            }
            continue;
        }
        $advertiserHistoryInsert->execute([
            'ad_campaigns',(int)$row['id'],$legacyAdvertiserId,
            $advertiserUserMap[$legacyAdvertiserId],
            json_encode($row,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),$row['created_at']
        ]);
        $importedAdCampaigns++;
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

    $archiveFind = $pdo->prepare('SELECT owner_user_id,snapshot_json FROM pf_legacy_history WHERE source_table=? AND legacy_id=? FOR UPDATE');
    $archiveInsert = $pdo->prepare('INSERT INTO pf_legacy_history (source_table,legacy_id,owner_user_id,snapshot_json,source_created_at) VALUES (?,?,?,?,?)');
    $campaignRows = $pdo->query('SELECT id,source_type,advertiser_id,network_id,external_offer_id,image_url,title,description,category,instructions,network_payout,reward_rate,worker_reward,platform_margin,budget,spent,countries,devices,os,incentive_allowed,status,approval_status,start_at,end_at,created_at,updated_at FROM campaigns ORDER BY id FOR UPDATE')->fetchAll();
    $archivedCampaigns = 0;
    foreach ($campaignRows as $row) {
        $archiveFind->execute(['campaigns', (int) $row['id']]);
        $existing = $archiveFind->fetch();
        // Legacy campaigns.advertiser_id refers to advertisers.id, not users.id.
        $legacyAdvertiserId = $row['advertiser_id'] !== null ? (int)$row['advertiser_id'] : 0;
        if ($legacyAdvertiserId > 0 && !isset($advertiserUserMap[$legacyAdvertiserId])) {
            throw new RuntimeException('Legacy campaign references an unmapped advertiser ID ' . $legacyAdvertiserId);
        }
        $ownerId = $legacyAdvertiserId > 0 ? $advertiserUserMap[$legacyAdvertiserId] : null;
        if ($existing) {
            if (($existing['owner_user_id'] === null ? null : (int) $existing['owner_user_id']) !== $ownerId
                || !$matchesSnapshot((string)$existing['snapshot_json'], $row)) {
                throw new RuntimeException('Existing legacy campaign archive does not fully match campaign ID ' . (int) $row['id']);
            }
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
            if (($existing['owner_user_id'] === null ? null : (int) $existing['owner_user_id']) !== $ownerId
                || !$matchesSnapshot((string)$existing['snapshot_json'], $row)) {
                throw new RuntimeException('Existing legacy conversion archive does not fully match conversion ID ' . (int) $row['id']);
            }
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
    // Compare DECIMAL aggregates inside MySQL. Do not convert financial totals
    // to PHP floats, which can hide or introduce rounding differences.
    $sourceWalletTotals = "(SELECT user_id,CAST(UPPER(currency) AS BINARY) AS currency_key,SUM(amount) AS total
        FROM wallet_transactions
        WHERE status='COMPLETED' OR (status='PENDING' AND type='WITHDRAWAL')
        GROUP BY user_id,CAST(UPPER(currency) AS BINARY))";
    $importedWalletTotals = "(SELECT user_id,CAST(UPPER(currency) AS BINARY) AS currency_key,SUM(amount) AS total
        FROM pf_wallet_ledger
        WHERE reference_type='LEGACY_WALLET_TXN' AND idempotency_key LIKE 'legacy-wallet-txn-%'
        GROUP BY user_id,CAST(UPPER(currency) AS BINARY))";
    $walletBalanceMismatch = (int)$pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM {$sourceWalletTotals} s
             LEFT JOIN {$importedWalletTotals} i
               ON i.user_id=s.user_id AND i.currency_key=s.currency_key
             WHERE i.user_id IS NULL OR s.total<>i.total)
          + (SELECT COUNT(*) FROM {$importedWalletTotals} i
             LEFT JOIN {$sourceWalletTotals} s
               ON s.user_id=i.user_id AND s.currency_key=i.currency_key
             WHERE s.user_id IS NULL OR s.total<>i.total)"
    )->fetchColumn();
    if ($walletBalanceMismatch !== 0) {
        throw new RuntimeException('Legacy wallet balance reconciliation failed using exact DECIMAL comparisons.');
    }

    $expectedWithdrawalRows = (int)$pdo->query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
    $actualWithdrawalRows = (int)$pdo->query('SELECT COUNT(*) FROM pf_withdrawals WHERE legacy_withdrawal_id IS NOT NULL')->fetchColumn();
    if ($expectedWithdrawalRows !== $actualWithdrawalRows) {
        throw new RuntimeException("Legacy withdrawal count mismatch: source={$expectedWithdrawalRows}, imported={$actualWithdrawalRows}");
    }
    $expectedAdvertiserAccounts = (int)$pdo->query('SELECT COUNT(*) FROM advertisers')->fetchColumn();
    $actualAdvertiserAccounts = (int)$pdo->query('SELECT COUNT(*) FROM pf_legacy_advertiser_accounts')->fetchColumn();
    if ($expectedAdvertiserAccounts !== $actualAdvertiserAccounts) {
        throw new RuntimeException("Legacy advertiser account count mismatch: source={$expectedAdvertiserAccounts}, imported={$actualAdvertiserAccounts}");
    }
    $openingBalanceMismatch = (int)$pdo->query(
        "SELECT COUNT(*) FROM advertisers a
         JOIN pf_legacy_advertiser_accounts x ON x.legacy_advertiser_id=a.id
         JOIN pf_wallet_ledger l ON l.id=x.opening_ledger_entry_id
         WHERE x.user_id<>a.user_id OR x.legacy_balance<>a.balance OR l.amount<>a.balance
            OR l.user_id<>a.user_id OR l.reference_type<>'LEGACY_ADVERTISER_BALANCE'
            OR l.reference_id<>a.id"
    )->fetchColumn();
    if ($openingBalanceMismatch !== 0) {
        throw new RuntimeException('Legacy advertiser opening-balance reconciliation failed.');
    }
    foreach (['advertiser_transactions','ad_campaigns'] as $sourceTable) {
        $sourceCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . $sourceTable)->fetchColumn();
        $archiveCount = $pdo->prepare('SELECT COUNT(*) FROM pf_legacy_advertiser_history WHERE source_table=?');
        $archiveCount->execute([$sourceTable]);
        if ($sourceCount !== (int)$archiveCount->fetchColumn()) {
            throw new RuntimeException('Legacy advertiser history count mismatch for ' . $sourceTable);
        }
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
    echo "Import complete. Worker wallet transactions added: {$importedTransactions}; withdrawals added: {$importedWithdrawals}; advertiser accounts added: {$importedAdvertiserAccounts}; advertiser transactions archived: {$importedAdvertiserTransactions}; advertiser ad campaigns archived: {$importedAdCampaigns}; old campaigns archived: {$archivedCampaigns}; conversions archived: {$archivedConversions}.\n";
    echo "Legacy source tables and user rows were not modified. Verify per-user balances and history before enabling member access.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Import failed; transaction rolled back: ' . $exception->getMessage() . "\n");
    exit(1);
}
