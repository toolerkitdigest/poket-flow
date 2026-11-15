<?php
declare(strict_types=1);

require_once __DIR__ . '/../marketplace/bootstrap/blog-events.php';

$now = new DateTimeImmutable('2026-11-06 12:00:00');
$base = [
    'campaign_status' => 'ACTIVE',
    'blog_status' => 'ACTIVE',
    'starts_at' => '2026-11-01 00:00:00',
    'ends_at' => '2026-11-06 11:00:00',
];

$cases = [
    'active campaign event' => [$base, '2026-11-06 10:30:00', true],
    'queued event accepted after pause' => [[...$base, 'campaign_status' => 'PAUSED', 'blog_status' => 'PAUSED'], '2026-11-06 10:30:00', true],
    'queued event accepted after expiry' => [[...$base, 'campaign_status' => 'COMPLETED', 'blog_status' => 'ENDED'], '2026-11-06 10:59:59', true],
    'event after scheduled end rejected' => [[...$base, 'campaign_status' => 'COMPLETED', 'blog_status' => 'ENDED'], '2026-11-06 11:00:01', false],
    'event before scheduled start rejected' => [$base, '2026-10-31 23:59:59', false],
    'future event rejected' => [$base, '2026-11-06 12:06:00', false],
    'rejected campaign event rejected' => [[...$base, 'campaign_status' => 'REJECTED', 'blog_status' => 'REVOKED'], '2026-11-06 10:00:00', false],
    'mismatched state rejected' => [[...$base, 'campaign_status' => 'ACTIVE', 'blog_status' => 'ENDED'], '2026-11-06 10:00:00', false],
];

foreach ($cases as $label => [$campaign, $eventTime, $expected]) {
    $actual = pf_blog_event_is_eligible($campaign, new DateTimeImmutable($eventTime), $now);
    if ($actual !== $expected) {
        throw new RuntimeException('Blog event eligibility test failed: ' . $label);
    }
}

echo "Blog event eligibility tests passed: active, delayed pause/expiry delivery, date bounds and rejected campaigns.\n";
