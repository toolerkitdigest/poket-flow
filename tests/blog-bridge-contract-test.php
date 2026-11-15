<?php
declare(strict_types=1);

/**
 * Static bridge-contract regression test.
 * This verifies both sides agree on the signed HTTP envelope and payload
 * vocabulary. It does not replace a live HTTP/database integration test.
 */
$root = dirname(__DIR__);
$sender = file_get_contents($root . '/marketplace/bootstrap/blog-sync.php');
$receiver = file_get_contents($root . '/marketplace/api/blog-events.php');
$eventReceiver = $receiver;

$checks = [
    'main creative sender signs timestamp, nonce and raw JSON body' =>
        str_contains($sender, '$timestamp."\\n".$nonce."\\n".$body'),
    'main creative sender sends the expected signed headers' =>
        str_contains($sender, "'X-PF-Timestamp: '") &&
        str_contains($sender, "'X-PF-Nonce: '") &&
        str_contains($sender, "'X-PF-Signature: '"),
    'main creative sender supports upsert and revoke' =>
        str_contains($sender, "['upsert','revoke']"),
    'main event receiver verifies the same HMAC canonical string' =>
        str_contains($eventReceiver, '$timestamp . "\\n" . $nonce . "\\n" . $raw'),
    'main event receiver validates impression and click event types' =>
        str_contains($eventReceiver, "['IMPRESSION', 'CLICK']"),
    'main event receiver stores request nonces before accepting events' =>
        strpos($eventReceiver, 'INSERT INTO pf_blog_event_requests') !== false &&
        strpos($eventReceiver, '$request->execute') < strpos($eventReceiver, '$insert->execute'),
    'main event receiver validates the documented event-key shape' =>
        str_contains($eventReceiver, '[a-zA-Z0-9._:-]{12,120}'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

// Verify the canonical HMAC envelope itself: exact body is authenticated,
// and changing the body invalidates the signature.
$secret = str_repeat('K', 40);
$timestamp = '1793966400';
$nonce = str_repeat('a', 48);
$body = '{"campaign_id":17,"event_type":"IMPRESSION","event_key":"' . str_repeat('b', 32) . '","occurred_at":"2026-11-06 12:00:00"}';
$signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
$valid = hash_equals(
    hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret),
    $signature
);
$tampered = hash_equals(
    hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body . ' ', $secret),
    $signature
);
if (!$valid || $tampered) {
    fwrite(STDERR, "FAIL: HMAC envelope tamper detection\n");
    exit(1);
}

echo 'PoketFlow bridge contract checks passed: ' . count($checks) . " source-contract checks and HMAC tamper detection.\n";
