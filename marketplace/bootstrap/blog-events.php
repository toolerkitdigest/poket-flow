<?php
declare(strict_types=1);

/**
 * A signed blog event may arrive after a campaign is paused or expires.
 * Accept only events inside the campaign's scheduled window and only for
 * campaigns that were approved and subsequently active on the blog.
 */
function pf_blog_event_is_eligible(
    array $campaign,
    DateTimeImmutable $occurredAt,
    ?DateTimeImmutable $now = null
): bool {
    $campaignStatus = strtoupper((string) ($campaign['campaign_status'] ?? ''));
    $blogStatus = strtoupper((string) ($campaign['blog_status'] ?? ''));

    $allowedStates = [
        'ACTIVE' => 'ACTIVE',
        'PAUSED' => 'PAUSED',
        'COMPLETED' => 'ENDED',
    ];

    if (!isset($allowedStates[$campaignStatus])
        || $allowedStates[$campaignStatus] !== $blogStatus) {
        return false;
    }

    $now ??= new DateTimeImmutable('now');
    if ($occurredAt > $now->modify('+5 minutes')) {
        return false;
    }

    try {
        $startsAt = !empty($campaign['starts_at'])
            ? new DateTimeImmutable((string) $campaign['starts_at'])
            : null;
        $endsAt = !empty($campaign['ends_at'])
            ? new DateTimeImmutable((string) $campaign['ends_at'])
            : null;
    } catch (Throwable $exception) {
        return false;
    }

    if ($startsAt !== null && $occurredAt < $startsAt) {
        return false;
    }
    if ($endsAt !== null && $occurredAt > $endsAt) {
        return false;
    }

    return true;
}
