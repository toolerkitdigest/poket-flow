<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/app.php';
$admin = pf_require_admin($pdo);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $disputeId = filter_var($_POST['dispute_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string)($_POST['action'] ?? '');
    $note = trim((string)($_POST['admin_note'] ?? ''));

    if (!$disputeId || !in_array($action, ['start_review','worker_wins','advertiser_wins'], true) || strlen($note) > 2000) {
        $error = 'Invalid appeal action or decision note.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM pf_submission_disputes WHERE id=? FOR UPDATE');
            $stmt->execute([$disputeId]);
            $dispute = $stmt->fetch();
            if (!$dispute) throw new RuntimeException('This appeal could not be found.');

            if ($action === 'start_review') {
                if ($dispute['status'] !== 'OPEN') throw new RuntimeException('Only a newly opened appeal can be moved into review.');
                $reviewUpdate = $pdo->prepare("UPDATE pf_submission_disputes SET status='UNDER_REVIEW',admin_note=? WHERE id=? AND status='OPEN'");
                $reviewUpdate->execute([$note ?: null,$disputeId]);
                if ($reviewUpdate->rowCount() !== 1) throw new RuntimeException('Appeal status changed. Refresh and try again.');
                $notice = 'Appeal moved into administrator review.';
            } else {
                if (!in_array($dispute['status'], ['OPEN','UNDER_REVIEW'], true)) {
                    throw new RuntimeException('This appeal has already been resolved.');
                }
                $campaignStmt = $pdo->prepare('SELECT id,status,reserved_budget,approved_quantity,total_quantity FROM pf_campaigns WHERE id=? FOR UPDATE');
                $campaignStmt->execute([(int)$dispute['campaign_id']]);
                $campaign = $campaignStmt->fetch();
                $submissionStmt = $pdo->prepare('SELECT id,status,reward_amount,currency FROM pf_submissions WHERE id=? AND worker_id=? FOR UPDATE');
                $submissionStmt->execute([(int)$dispute['submission_id'],(int)$dispute['worker_id']]);
                $submission = $submissionStmt->fetch();
                if (!$campaign || !$submission || $submission['status'] !== 'DISPUTED') {
                    throw new RuntimeException('The campaign or disputed submission has changed. No decision was applied.');
                }

                if ($action === 'worker_wins') {
                    if (in_array($campaign['status'], ['REJECTED','CANCELLED'], true)
                        || (int)$campaign['approved_quantity'] >= (int)$campaign['total_quantity']) {
                        throw new RuntimeException('The campaign cannot accept another approved completion.');
                    }
                    $reward = round((float)$submission['reward_amount'], 2);
                    if ($reward <= 0 || (float)$campaign['reserved_budget'] + 0.00001 < $reward) {
                        throw new RuntimeException('The campaign does not have enough reserved funds to pay this appeal. Manual funding reconciliation is required.');
                    }
                    $reservationStmt = $pdo->prepare("SELECT id,amount FROM pf_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
                    $reservationStmt->execute([(int)$dispute['campaign_id']]);
                    $reservation = $reservationStmt->fetch();
                    if (!$reservation || (float)$reservation['amount'] + 0.00001 < $reward
                        || abs((float)$reservation['amount'] - (float)$campaign['reserved_budget']) > 0.01) {
                        throw new RuntimeException('Campaign reserve and reservation ledger do not match. No reward was issued.');
                    }

                    $campaignUpdate = $pdo->prepare('UPDATE pf_campaigns SET reserved_budget=reserved_budget-?,spent_budget=spent_budget+?,approved_quantity=approved_quantity+1,status=CASE WHEN approved_quantity+1>=total_quantity THEN \'COMPLETED\' ELSE status END WHERE id=? AND reserved_budget>=? AND approved_quantity<total_quantity');
                    $campaignUpdate->execute([$reward,$reward,(int)$campaign['id'],$reward]);
                    if ($campaignUpdate->rowCount() !== 1) throw new RuntimeException('Campaign capacity or reserved funds changed. No reward was issued.');

                    $reservationUpdate = $pdo->prepare("UPDATE pf_campaign_reservations SET status=CASE WHEN amount<=? THEN 'CONSUMED' ELSE 'RESERVED' END,amount=amount-? WHERE id=? AND status='RESERVED' AND amount>=?");
                    $reservationUpdate->execute([$reward,$reward,(int)$reservation['id'],$reward]);
                    if ($reservationUpdate->rowCount() !== 1) throw new RuntimeException('The campaign reservation could not be reduced safely.');

                    $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'WORKER',?)")
                        ->execute([(int)$dispute['worker_id'],$submission['currency']]);
                    $walletStmt = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='WORKER' AND currency=? FOR UPDATE");
                    $walletStmt->execute([(int)$dispute['worker_id'],$submission['currency']]);
                    $walletId = (int)$walletStmt->fetchColumn();
                    if (!$walletId) throw new RuntimeException('Worker wallet could not be located.');

                    $ledger = $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'WORKER_REWARD',?,?,'SUBMISSION',?,?,?,?)");
                    $ledger->execute([$walletId,(int)$dispute['worker_id'],$reward,$submission['currency'],(int)$submission['id'],'worker-reward-submission-'.(int)$submission['id'],'Appeal resolved in worker favour',(int)$admin['id']]);
                    $ledgerId = (int)$pdo->lastInsertId();

                    $submissionUpdate = $pdo->prepare("UPDATE pf_submissions SET status='APPROVED',reviewed_by=?,reviewed_at=NOW(),review_note=?,reward_ledger_entry_id=? WHERE id=? AND status='DISPUTED'");
                    $submissionUpdate->execute([(int)$admin['id'],$note ?: 'Appeal resolved in worker favour.',$ledgerId,(int)$submission['id']]);
                    if ($submissionUpdate->rowCount() !== 1) throw new RuntimeException('Submission status changed. Reward transaction was rolled back.');
                    $disputeUpdate = $pdo->prepare("UPDATE pf_submission_disputes SET status='RESOLVED_WORKER',admin_note=?,resolved_by=?,resolved_at=NOW() WHERE id=? AND status IN ('OPEN','UNDER_REVIEW')");
                    $disputeUpdate->execute([$note ?: null,(int)$admin['id'],$disputeId]);
                    if ($disputeUpdate->rowCount() !== 1) throw new RuntimeException('Appeal status changed. Reward transaction was rolled back.');
                    $notice = 'Appeal resolved in the worker’s favour. The reserved reward has been credited.';
                } else {
                    $submissionUpdate = $pdo->prepare("UPDATE pf_submissions SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='DISPUTED'");
                    $submissionUpdate->execute([(int)$admin['id'],$note ?: 'Appeal reviewed; advertiser decision upheld.',(int)$submission['id']]);
                    if ($submissionUpdate->rowCount() !== 1) throw new RuntimeException('Submission status changed. No appeal decision was saved.');
                    $disputeUpdate = $pdo->prepare("UPDATE pf_submission_disputes SET status='RESOLVED_ADVERTISER',admin_note=?,resolved_by=?,resolved_at=NOW() WHERE id=? AND status IN ('OPEN','UNDER_REVIEW')");
                    $disputeUpdate->execute([$note ?: null,(int)$admin['id'],$disputeId]);
                    if ($disputeUpdate->rowCount() !== 1) throw new RuntimeException('Appeal status changed. Transaction rolled back.');
                    $notice = 'Appeal resolved in the advertiser’s favour. No worker reward was issued.';
                }
            }

            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ipBinary = filter_var($ip,FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
            $audit = $pdo->prepare('INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,before_json,after_json,ip_address) VALUES (?,?,?,?,?,?,?)');
            $audit->execute([(int)$admin['id'],'ADMIN_SUBMISSION_APPEAL_'.strtoupper($action),'SUBMISSION_DISPUTE',$disputeId,
                json_encode(['status'=>$dispute['status']],JSON_THROW_ON_ERROR),
                json_encode(['action'=>$action,'note'=>$note],JSON_THROW_ON_ERROR),$ipBinary]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The appeal action failed. No changes were saved.';
        }
    }
}

$disputes = $pdo->query(
    "SELECT d.*,s.submission_text,s.evidence_json AS submission_evidence,s.reward_amount,s.currency,
            c.title AS campaign_title,c.status AS campaign_status,
            u.name AS worker_name,u.email AS worker_email,a.name AS advertiser_name
     FROM pf_submission_disputes d
     JOIN pf_submissions s ON s.id=d.submission_id
     JOIN pf_campaigns c ON c.id=d.campaign_id
     JOIN users u ON u.id=d.worker_id
     JOIN users a ON a.id=d.advertiser_id
     WHERE d.status IN ('OPEN','UNDER_REVIEW')
     ORDER BY CASE d.status WHEN 'OPEN' THEN 0 ELSE 1 END,d.created_at ASC LIMIT 100"
)->fetchAll();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Submission appeals | PoketFlow</title><link rel="stylesheet" href="../assets/marketplace.css">
<style>.review{padding:20px 0;border-top:1px solid var(--line)}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.actions input{flex:1;min-width:220px;padding:11px;border:1px solid var(--line);border-radius:10px;font:inherit}.danger{background:#fff1f2;color:#9f1239}.evidence{white-space:pre-wrap;overflow-wrap:anywhere;background:var(--surface);padding:12px;border-radius:10px}</style></head>
<body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="index.php">Admin home</a><a href="users.php">Members</a><a href="finance.php">Finance</a><a href="../dashboard.php">Dashboard</a><a href="../logout.php">Sign out</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">FAIR REVIEW</p><h1>Worker submission appeals</h1><p class="muted">Review disputed rejections independently. A worker-favour decision pays only from the campaign’s remaining reserved funds.</p></section>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<section class="panel"><?php if(!$disputes):?><p class="muted">There are no open appeals.</p><?php endif;?>
<?php foreach($disputes as $d):?><article class="review"><p class="eyebrow">APPEAL #<?=(int)$d['id']?> · <?=pf_e($d['status'])?> · <?=pf_e($d['created_at'])?></p><h2><?=pf_e($d['campaign_title'])?></h2>
<p><strong>Worker:</strong> <?=pf_e($d['worker_name'])?> · <?=pf_e($d['worker_email'])?></p><p><strong>Advertiser:</strong> <?=pf_e($d['advertiser_name'])?></p>
<p><strong>Reward at issue:</strong> <?=pf_e($d['currency'])?> <?=number_format((float)$d['reward_amount'],2)?> · <strong>Campaign:</strong> <?=pf_e($d['campaign_status'])?></p>
<h3>Worker’s appeal</h3><div class="evidence"><?=pf_e($d['reason'])?></div>
<?php if($d['submission_text']):?><h3>Original completion details</h3><div class="evidence"><?=pf_e($d['submission_text'])?></div><?php endif;?>
<?php if($d['submission_evidence']):?><h3>Original submission evidence</h3><pre class="evidence"><?=pf_e((string)$d['submission_evidence'])?></pre><?php endif;?>
<?php if($d['evidence_json']):?><h3>Appeal evidence</h3><pre class="evidence"><?=pf_e((string)$d['evidence_json'])?></pre><?php endif;?>
<?php if($d['admin_note']):?><p class="small muted">Previous admin note: <?=pf_e($d['admin_note'])?></p><?php endif;?>
<?php if($d['status']==='OPEN'):?><form class="actions" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="dispute_id" value="<?=(int)$d['id']?>"><input name="admin_note" maxlength="2000" placeholder="Optional review note"><button class="button secondary" name="action" value="start_review">Move to review</button></form>
<?php else:?><form class="actions" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="dispute_id" value="<?=(int)$d['id']?>"><input name="admin_note" maxlength="2000" placeholder="Decision explanation (recommended)"><button class="button primary" name="action" value="worker_wins">Resolve for worker & pay</button><button class="button danger" name="action" value="advertiser_wins">Uphold rejection</button></form><?php endif;?>
</article><?php endforeach;?></section></main></body></html>