<?php
declare(strict_types=1);

require_once __DIR__ . '/../marketplace/bootstrap/auth.php';

$configPath = getenv('POKETFLOW_CONFIG');
if (!$configPath || !is_file($configPath)) {
    throw new RuntimeException('Test database config is missing.');
}
$config = require $configPath;
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset'] ?? 'utf8mb4'),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$expected = [1 => true, 2 => false, 3 => false];
foreach ($expected as $userId => $advertiserAllowed) {
    if (pf_legacy_advertiser_access_allowed($pdo, $userId) !== $advertiserAllowed) {
        throw new RuntimeException('Legacy advertiser restriction mismatch for user ' . $userId);
    }
    $mode = $pdo->prepare('SELECT can_work,can_advertise FROM pf_account_modes WHERE user_id=?');
    $mode->execute([$userId]);
    $row = $mode->fetch();
    if (!$row || (int) $row['can_work'] !== 1 || (int) $row['can_advertise'] !== 1) {
        throw new RuntimeException('Unified account capabilities were not preserved for user ' . $userId);
    }
}
echo "Legacy ACTIVE advertiser allowed; SUSPENDED/REJECTED advertisers blocked; worker capability retained for all three.\n";
