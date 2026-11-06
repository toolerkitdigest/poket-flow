<?php
declare(strict_types=1);

/**
 * Expire fixed-duration blog sponsorships.
 * Schedule through CLI/cron only; never expose this script under public_html.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../marketplace/bootstrap/app.php';

$ids = $pdo->query(
    "SELECT c.id
     FROM pf_campaigns c
     JOIN pf_blog_campaigns b ON b.campaign_id=c.id
     WHERE c.campaign_type='BLOG_SPONSORED'
       AND c.status='ACTIVE'
       AND b.status='ACTIVE'
       AND b.sync_status='SYNCED'
       AND b.ends_at IS NOT NULL
       AND b.ends_at<NOW()
     ORDER BY b.ends_at ASC LIMIT 200"
)->fetchAll(PDO::FETCH_COLUMN);

$completed = 0;
$failedSync = 0;
foreach ($ids as $rawId) {
    $campaignId = (int)$rawId;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT c.status,c.reserved_budget,c.spent_budget,b.status AS blog_status,b.sync_status
             FROM pf_campaigns c JOIN pf_blog_campaigns b ON b.campaign_id=c.id
             WHERE c.id=? AND c.campaign_type='BLOG_SPONSORED' FOR UPDATE"
        );
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch();
        if (!$campaign || $campaign['status']!=='ACTIVE'
            || $campaign['blog_status']!=='ACTIVE' || $campaign['sync_status']!=='SYNCED') {
            $pdo->rollBack();
            continue;
        }
        $reservation = $pdo->prepare(
            "SELECT id,amount FROM pf_blog_campaign_reservations
             WHERE campaign_id=? AND status='RESERVED' FOR UPDATE"
        );
        $reservation->execute([$campaignId]);
        $reserved = $reservation->fetch();
        if (!$reserved || (float)$reserved['amount']<=0
            || abs((float)$reserved['amount']-(float)$campaign['reserved_budget'])>0.01) {
            $pdo->rollBack();
            fwrite(STDERR,"Skipped campaign {$campaignId}: reservation mismatch; manual review required.\n");
            continue;
        }

        $pdo->prepare(
            "UPDATE pf_campaigns
             SET status='COMPLETED',spent_budget=spent_budget+?,reserved_budget=0
             WHERE id=? AND status='ACTIVE'"
        )->execute([$reserved['amount'],$campaignId]);
        $pdo->prepare(
            "UPDATE pf_blog_campaign_reservations SET status='CONSUMED' WHERE id=? AND status='RESERVED'"
        )->execute([(int)$reserved['id']]);
        $pdo->prepare(
            "UPDATE pf_blog_campaigns SET status='ENDED',sync_status='PENDING' WHERE campaign_id=?"
        )->execute([$campaignId]);
        $pdo->prepare(
            "INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,after_json)
             VALUES (NULL,'SYSTEM_BLOG_CAMPAIGN_EXPIRED','BLOG_CAMPAIGN',?,?)"
        )->execute([$campaignId,json_encode(['status'=>'COMPLETED','budget_consumed'=>$reserved['amount']],JSON_THROW_ON_ERROR)]);
        $pdo->commit();

        $sync = pf_sync_blog_campaign($pdo,$campaignId,'revoke');
        if ($sync['ok']) {
            $pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='SYNCED',last_sync_error=NULL WHERE campaign_id=?")
                ->execute([$campaignId]);
        } else {
            $pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='FAILED',last_sync_error=? WHERE campaign_id=?")
                ->execute([$sync['error'],$campaignId]);
            $failedSync++;
        }
        $completed++;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR,"Campaign {$campaignId} was not expired: ".$exception->getMessage()."\n");
    }
}

echo "Expired fixed-duration blog sponsorships: {$completed}; sync failures: {$failedSync}.\n";
