<?php
declare(strict_types=1);

/*
 * Read-only legacy database audit.
 * Never writes to, alters, or deletes production data.
 * Reports structure and aggregate counts only; no emails or password hashes are printed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configFile = '/home/poketflo/private/poketflow-main-database.php';
if (!is_file($configFile) || !is_readable($configFile)) {
    fwrite(STDERR, "Private PoketFlow database configuration is not installed.\n");
    exit(1);
}
$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "Private database configuration is invalid.\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();

$lines = [];
$add = static function (string $line) use (&$lines): void { $lines[] = $line; };
$quoteIdentifier = static function (string $name): string {
    return chr(96) . str_replace(chr(96), chr(96) . chr(96), $name) . chr(96);
};

$add('POKETFLOW LEGACY DATABASE — READ-ONLY AUDIT');
$add('Generated: ' . date(DATE_ATOM));
$add('Database: ' . (string) $config['dbname']);
$add('Mode: READ ONLY — no application tables or records changed');
$add(str_repeat('=', 72));

$tables = $pdo->query(
    'SELECT TABLE_NAME, ENGINE, TABLE_ROWS
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"
     ORDER BY TABLE_NAME'
)->fetchAll();

$add('TABLE INVENTORY');
foreach ($tables as $table) {
    $add(sprintf('- %s | engine=%s | estimated_rows=%s', $table['TABLE_NAME'], $table['ENGINE'] ?? 'unknown', $table['TABLE_ROWS'] ?? 'unknown'));
}
$add('');

$knownTables = ['users','wallet_transactions','withdrawals','campaigns','campaign_clicks','conversions','networks','settings','offer_filters'];
$existing = array_column($tables, 'TABLE_NAME');

foreach ($knownTables as $tableName) {
    if (!in_array($tableName, $existing, true)) {
        $add('MISSING EXPECTED TABLE: ' . $tableName);
        continue;
    }

    $quoted = $quoteIdentifier($tableName);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
    $add('EXACT ROW COUNT ' . $tableName . ': ' . $count);

    if ($tableName === 'users') {
        $columns = $pdo->query('SHOW FULL COLUMNS FROM ' . $quoted)->fetchAll();
        $add('USERS TABLE COLUMNS');
        foreach ($columns as $column) {
            $add(sprintf('  %s | %s | null=%s | default=%s | extra=%s',
                $column['Field'], $column['Type'], $column['Null'],
                $column['Default'] === null ? 'NULL' : (string) $column['Default'], $column['Extra']));
        }

        $indexes = $pdo->query('SHOW INDEX FROM ' . $quoted)->fetchAll();
        $add('USERS TABLE INDEXES');
        foreach ($indexes as $index) {
            $add(sprintf('  %s | column=%s | unique=%s | sequence=%s',
                $index['Key_name'], $index['Column_name'],
                (int) $index['Non_unique'] === 0 ? 'yes' : 'no', $index['Seq_in_index']));
        }

        $columnNames = array_column($columns, 'Field');
        foreach (['role', 'status', 'email_verified'] as $columnName) {
            if (in_array($columnName, $columnNames, true)) {
                $column = $quoteIdentifier($columnName);
                $groups = $pdo->query(
                    'SELECT ' . $column . ' AS value, COUNT(*) AS total FROM users GROUP BY '
                    . $column . ' ORDER BY total DESC'
                )->fetchAll();
                $add('USERS GROUPED BY ' . $columnName);
                foreach ($groups as $group) {
                    $add('  ' . (string) ($group['value'] ?? 'NULL') . ': ' . (int) $group['total']);
                }
            }
        }

        if (in_array('password_hash', $columnNames, true)) {
            $groups = $pdo->query(
                'SELECT LENGTH(password_hash) AS hash_length, LEFT(password_hash, 7) AS hash_prefix, COUNT(*) AS total
                 FROM users GROUP BY LENGTH(password_hash), LEFT(password_hash, 7) ORDER BY total DESC'
            )->fetchAll();
            $add('PASSWORD HASH FORMAT COUNTS (hash values are not exposed)');
            foreach ($groups as $group) {
                $add('  prefix=' . (string) $group['hash_prefix'] . ' | length=' . (int) $group['hash_length'] . ' | count=' . (int) $group['total']);
            }
        }
    }

    if (in_array($tableName, ['wallet_transactions','withdrawals','campaigns','conversions'], true)) {
        $columns = $pdo->query('SHOW FULL COLUMNS FROM ' . $quoted)->fetchAll();
        $columnNames = array_column($columns, 'Field');
        $add(strtoupper($tableName) . ' TABLE COLUMNS');
        foreach ($columns as $column) {
            $add(sprintf('  %s | %s | null=%s | key=%s | default=%s | extra=%s',
                $column['Field'], $column['Type'], $column['Null'], $column['Key'],
                $column['Default'] === null ? 'NULL' : (string) $column['Default'], $column['Extra']));
        }
        if (in_array('status', $columnNames, true)) {
            $amountSelect = in_array('amount', $columnNames, true) ? ', COALESCE(SUM(amount),0) AS amount_total' : '';
            $groups = $pdo->query(
                'SELECT status, COUNT(*) AS total' . $amountSelect
                . ' FROM ' . $quoted . ' GROUP BY status ORDER BY total DESC'
            )->fetchAll();
            $add(strtoupper($tableName) . ' STATUS SUMMARY');
            foreach ($groups as $group) {
                $line = '  ' . (string) ($group['status'] ?? 'NULL') . ' | count=' . (int) $group['total'];
                if (array_key_exists('amount_total', $group)) {
                    $line .= ' | amount_total=' . (string) $group['amount_total'];
                }
                $add($line);
            }
        }
        if ($tableName === 'wallet_transactions' && in_array('amount', $columnNames, true) && in_array('status', $columnNames, true)) {
            $balance = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='COMPLETED'")->fetchColumn();
            $add('LEGACY COMPLETED WALLET LEDGER SUM (all currencies, if present): ' . (string) $balance);
        }
    }
    $add('');
}

$pdo->commit();
$report = implode(PHP_EOL, $lines) . PHP_EOL;
$privateDir = '/home/poketflo/private';
if (!is_dir($privateDir)) {
    mkdir($privateDir, 0700, true);
}
$path = $privateDir . '/poketflow-legacy-audit-' . date('Ymd-His') . '.txt';
if (file_put_contents($path, $report, LOCK_EX) === false) {
    fwrite(STDERR, "Audit completed, but the private report could not be saved.\n");
    exit(1);
}
chmod($path, 0600);
fwrite(STDOUT, $report);
fwrite(STDOUT, 'Private report saved to: ' . $path . PHP_EOL);
