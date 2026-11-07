<?php
declare(strict_types=1);

/*
 * Runtime database loader for the new marketplace build.
 * Credentials are created by tools/create-db-config.php and never committed.
 */
$configFile = '/home/poketflo/private/poketflow-main-database.php';

if (!is_file($configFile) || !is_readable($configFile)) {
    throw new RuntimeException('Private PoketFlow database configuration is not installed.');
}

$config = require $configFile;
foreach (['host', 'dbname', 'username', 'password'] as $required) {
    if (!isset($config[$required]) || $config[$required] === '') {
        throw new RuntimeException('Private database configuration is incomplete.');
    }
}

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['dbname'],
    $config['charset'] ?? 'utf8mb4'
);

$pdo = new PDO(
    $dsn,
    $config['username'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
