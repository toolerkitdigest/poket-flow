<?php
declare(strict_types=1);

$secret = (string) getenv('PF_TEST_HMAC_SECRET');
if (strlen($secret) < 32) {
    throw new RuntimeException('Test HMAC secret is missing.');
}
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=poketflow_test;charset=utf8mb4',
    'root',
    'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$campaignId = 99001;
$pdo->prepare(
    "INSERT INTO pf_campaigns
     (id,advertiser_id,campaign_type,title,slug,description,status,starts_at,ends_at)
     VALUES (?,1,'BLOG_SPONSORED','HTTP bridge test','http-bridge-test','Test campaign','ACTIVE',NOW()-INTERVAL 1 DAY,NOW()+INTERVAL 1 DAY)"
)->execute([$campaignId]);
$pdo->prepare(
    "INSERT INTO pf_blog_campaigns
     (campaign_id,public_token_hash,placement_key,requested_budget,creative_json,starts_at,ends_at,status,sync_status)
     VALUES (?,REPEAT('d',64),'article_top',25.00,'{}',NOW()-INTERVAL 1 DAY,NOW()+INTERVAL 1 DAY,'ACTIVE','SYNCED')"
)->execute([$campaignId]);

function sendEvent(int $campaignId, string $type, string $eventKey, string $occurredAt, string $nonce, string $secret, ?string $signatureOverride = null): array
{
    $payload = [
        'campaign_id' => $campaignId,
        'event_type' => $type,
        'event_key' => $eventKey,
        'occurred_at' => $occurredAt,
        'metadata' => ['source' => 'http-integration-test'],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = $signatureOverride ?? hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
    $curl = curl_init('http://127.0.0.1:8082/api/blog-events.php');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-PF-Timestamp: ' . $timestamp,
            'X-PF-Nonce: ' . $nonce,
            'X-PF-Signature: ' . $signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($response)) {
        throw new RuntimeException('Main event HTTP request failed: ' . $error);
    }
    return [$status, json_decode($response, true), $body, $timestamp, $signature];
}

$eventTime = date('Y-m-d H:i:s');
$impressionKey = bin2hex(random_bytes(16));
$impressionNonce = bin2hex(random_bytes(24));

[$badStatus] = sendEvent($campaignId, 'IMPRESSION', bin2hex(random_bytes(16)), $eventTime, bin2hex(random_bytes(24)), $secret, str_repeat('0', 64));
if ($badStatus !== 401) {
    throw new RuntimeException('Invalid event signature should return 401.');
}

[$okStatus, $ok] = sendEvent($campaignId, 'IMPRESSION', $impressionKey, $eventTime, $impressionNonce, $secret);
if ($okStatus !== 200 || empty($ok['ok']) || empty($ok['accepted']) || !empty($ok['duplicate'])) {
    throw new RuntimeException('Valid impression event was not accepted.');
}

[$replayStatus, $replay] = sendEvent($campaignId, 'IMPRESSION', $impressionKey, $eventTime, $impressionNonce, $secret);
if ($replayStatus !== 409 || ($replay['error'] ?? '') !== 'request_replayed') {
    throw new RuntimeException('Replayed signed event request was not rejected.');
}

[$duplicateStatus, $duplicate] = sendEvent($campaignId, 'IMPRESSION', $impressionKey, $eventTime, bin2hex(random_bytes(24)), $secret);
if ($duplicateStatus !== 200 || empty($duplicate['ok']) || empty($duplicate['duplicate']) || !empty($duplicate['accepted'])) {
    throw new RuntimeException('Duplicate event key was not idempotently ignored.');
}

[$clickStatus, $click] = sendEvent($campaignId, 'CLICK', bin2hex(random_bytes(16)), $eventTime, bin2hex(random_bytes(24)), $secret);
if ($clickStatus !== 200 || empty($click['accepted'])) {
    throw new RuntimeException('Valid click event was not accepted.');
}

// A queued event created before pause remains reportable after the campaign is paused.
$pdo->prepare("UPDATE pf_campaigns SET status='PAUSED' WHERE id=?")->execute([$campaignId]);
$pdo->prepare("UPDATE pf_blog_campaigns SET status='PAUSED' WHERE campaign_id=?")->execute([$campaignId]);
[$delayedStatus, $delayed] = sendEvent(
    $campaignId,
    'CLICK',
    bin2hex(random_bytes(16)),
    date('Y-m-d H:i:s', time() - 60),
    bin2hex(random_bytes(24)),
    $secret
);
if ($delayedStatus !== 200 || empty($delayed['accepted'])) {
    throw new RuntimeException('Eligible queued event after pause was not accepted.');
}

// Events outside the approved schedule must never be counted, even after expiry.
$pdo->prepare("UPDATE pf_campaigns SET status='COMPLETED' WHERE id=?")->execute([$campaignId]);
$pdo->prepare("UPDATE pf_blog_campaigns SET status='ENDED' WHERE campaign_id=?")->execute([$campaignId]);
$end = $pdo->query('SELECT ends_at FROM pf_blog_campaigns WHERE campaign_id=' . $campaignId)->fetchColumn();
$outside = date('Y-m-d H:i:s', strtotime((string) $end . ' +1 second'));
[$outsideStatus, $outsideBody] = sendEvent(
    $campaignId,
    'CLICK',
    bin2hex(random_bytes(16)),
    $outside,
    bin2hex(random_bytes(24)),
    $secret
);
if ($outsideStatus !== 404 || ($outsideBody['error'] ?? '') !== 'campaign_not_eligible_for_event') {
    throw new RuntimeException('Event after the campaign schedule was not rejected.');
}

$count = $pdo->prepare('SELECT COUNT(*) FROM pf_blog_events WHERE campaign_id=?');
$count->execute([$campaignId]);
if ((int) $count->fetchColumn() !== 3) {
    throw new RuntimeException('Expected exactly three accepted unique events.');
}

echo "Main marketplace HTTP integration passed: HMAC rejection, valid impression/click, nonce replay rejection, event-key idempotency, delayed post-pause delivery and post-schedule rejection.\n";
