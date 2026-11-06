<?php
declare(strict_types=1);

/*
 * Signed event receiver for the isolated PoketFlow Blog application.
 * The blog has its own database and never receives main-database credentials.
 */
require_once dirname(__DIR__) . '/bootstrap/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pf_blog_api_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    pf_blog_api_reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$secret = (string) ($config['blog_integration_secret'] ?? '');
if (strlen($secret) < 32) {
    pf_blog_api_reply(503, ['ok' => false, 'error' => 'integration_not_configured']);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 16384) {
    pf_blog_api_reply(400, ['ok' => false, 'error' => 'invalid_body']);
}

$timestamp = (string) ($_SERVER['HTTP_X_PF_TIMESTAMP'] ?? '');
$nonce = (string) ($_SERVER['HTTP_X_PF_NONCE'] ?? '');
$signature = strtolower((string) ($_SERVER['HTTP_X_PF_SIGNATURE'] ?? ''));
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
    || !preg_match('/\A[a-f0-9]{32,96}\z/', $nonce)
    || !preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
    pf_blog_api_reply(401, ['ok' => false, 'error' => 'invalid_signature_headers']);
}

$expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $raw, $secret);
if (!hash_equals($expected, $signature)) {
    pf_blog_api_reply(401, ['ok' => false, 'error' => 'signature_mismatch']);
}

try {
    $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    pf_blog_api_reply(400, ['ok' => false, 'error' => 'invalid_json']);
}

$campaignId = filter_var($payload['campaign_id'] ?? null, FILTER_VALIDATE_INT);
$eventType = strtoupper((string) ($payload['event_type'] ?? ''));
$eventKey = (string) ($payload['event_key'] ?? '');
$occurredAt = (string) ($payload['occurred_at'] ?? '');
$metadata = $payload['metadata'] ?? null;

if (!$campaignId || !in_array($eventType, ['IMPRESSION', 'CLICK'], true)
    || !preg_match('/\A[a-zA-Z0-9._:-]{12,120}\z/', $eventKey)
    || ($occurredAt !== '' && strtotime($occurredAt) === false)
    || ($metadata !== null && !is_array($metadata))) {
    pf_blog_api_reply(422, ['ok' => false, 'error' => 'invalid_event']);
}

try {
    $campaign = $pdo->prepare(
        "SELECT bc.campaign_id
         FROM pf_blog_campaigns bc
         JOIN pf_campaigns c ON c.id = bc.campaign_id
         WHERE bc.campaign_id = ? AND bc.status = 'ACTIVE' AND c.status = 'ACTIVE'
         LIMIT 1"
    );
    $campaign->execute([$campaignId]);
    if (!$campaign->fetchColumn()) {
        pf_blog_api_reply(404, ['ok' => false, 'error' => 'campaign_not_active']);
    }

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO pf_blog_events
         (campaign_id,event_type,event_key,occurred_at,metadata_json)
         VALUES (?,?,?,?,?)'
    );
    $insert->execute([
        $campaignId,
        $eventType,
        $eventKey,
        $occurredAt !== '' ? date('Y-m-d H:i:s', strtotime($occurredAt)) : date('Y-m-d H:i:s'),
        $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
    ]);

    pf_blog_api_reply(200, [
        'ok' => true,
        'accepted' => $insert->rowCount() === 1,
        'duplicate' => $insert->rowCount() === 0,
    ]);
} catch (Throwable $exception) {
    error_log('PoketFlow blog event ingestion failed: ' . $exception->getMessage());
    pf_blog_api_reply(500, ['ok' => false, 'error' => 'event_processing_failed']);
}
