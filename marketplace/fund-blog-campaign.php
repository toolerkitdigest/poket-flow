<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);
$error = '';
$notice = '';
$id = filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $id = filter_input(INPUT_POST,'campaign_id',FILTER_VALIDATE_INT);
    if (!$id) {
        $error = 'Invalid blog sponsorship.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT c.id,c.status,c.currency,b.requested_budget
                 FROM pf_campaigns c JOIN pf_blog_campaigns b ON b.campaign_id=c.id
                 WHERE c.id=? AND c.advertiser_id=? AND c.campaign_type='BLOG_SPONSORED'
                 FOR UPDATE"
            );
            $stmt->execute([$id,(int)$user['id']]);
            $campaign = $stmt->fetch();
            if (!$campaign || $campaign['status']!=='DRAFT') {
                throw new RuntimeException('Only your draft blog sponsorships can be funded.');
            }
            $amount = round((float)$campaign['requested_budget'],2);
            if ($amount<=0) throw new RuntimeException('The proposed blog sponsorship budget is invalid.');

            $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'ADVERTISER',?)")
                ->execute([(int)$user['id'],$campaign['currency']]);
            $walletStmt = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");
            $walletStmt->execute([(int)$user['id'],$campaign['currency']]);
            $walletId = (int)$walletStmt->fetchColumn();
            $balanceStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pf_wallet_ledger WHERE wallet_id=?');
            $balanceStmt->execute([$walletId]);
            $available = (float)$balanceStmt->fetchColumn();
            if ($available+0.00001<$amount) {
                throw new RuntimeException('Your advertiser wallet does not have enough available funds. Submit a funding request first.');
            }

            $ledger = $pdo->prepare(
                "INSERT INTO pf_wallet_ledger
                 (wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description)
                 VALUES (?,?,'CAMPAIGN_RESERVE',?,?,'BLOG_CAMPAIGN',?,?,?)"
            );
            $ledger->execute([$walletId,(int)$user['id'],-$amount,$campaign['currency'],$id,'blog-campaign-reserve-'.$id,'Reserved fixed budget for blog sponsorship']);
            $ledgerId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO pf_blog_campaign_reservations(campaign_id,advertiser_id,amount,currency,status,ledger_entry_id)
                 VALUES (?,?,?,?,'RESERVED',?)"
            )->execute([$id,(int)$user['id'],$amount,$campaign['currency'],$ledgerId]);

            $update = $pdo->prepare("UPDATE pf_campaigns SET reserved_budget=?,status='PENDING_REVIEW' WHERE id=? AND status='DRAFT'");
            $update->execute([$amount,$id]);
            if ($update->rowCount()!==1) throw new RuntimeException('Campaign status changed. Please refresh and try again.');
            $pdo->commit();
            $notice = 'Your fixed blog sponsorship budget is reserved. The campaign is now waiting for administrator review.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Could not reserve the blog sponsorship budget.';
        }
    }
}
if (!$id) { http_response_code(400); exit('Campaign ID is required.'); }
$stmt = $pdo->prepare(
    'SELECT c.id,c.title,c.status,c.currency,b.requested_budget,b.placement_key,b.starts_at,b.ends_at
     FROM pf_campaigns c JOIN pf_blog_campaigns b ON b.campaign_id=c.id
     WHERE c.id=? AND c.advertiser_id=? AND c.campaign_type=\'BLOG_SPONSORED\''
);
$stmt->execute([$id,(int)$user['id']]);
$c = $stmt->fetch();
if (!$c) { http_response_code(404); exit('Blog sponsorship not found.'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fund blog sponsorship | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head>
<body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="blog-campaigns.php">Blog ads</a><a href="wallet.php">Wallets</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">BLOG SPONSORSHIP FUNDING</p><h1><?=pf_e($c['title'])?></h1><p class="muted">Proposed fixed budget: <strong><?=pf_e($c['currency'])?> <?=number_format((float)$c['requested_budget'],2)?></strong>. Placement: <?=pf_e($c['placement_key'])?>. Dates: <?=pf_e(substr((string)$c['starts_at'],0,10))?> to <?=pf_e(substr((string)$c['ends_at'],0,10))?>.</p><p class="small muted">This reservation is recorded separately from worker-task campaign reservations. The fixed sponsorship amount is held until moderation and delivery status are resolved.</p></section>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<?php if($c['status']==='DRAFT'):?><section class="panel"><form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>"><button class="button primary" type="submit">Reserve fixed budget & submit</button></form></section><?php else:?><section class="panel">Current status: <strong><?=pf_e($c['status'])?></strong></section><?php endif;?>
</main></body></html>
