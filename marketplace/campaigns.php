<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $requirements = trim((string)($_POST['requirements'] ?? ''));
        $reward = filter_var($_POST['reward'] ?? null, FILTER_VALIDATE_FLOAT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $evidenceRequired = isset($_POST['evidence_required']) ? 1 : 0;

        if ($title === '' || $description === '' || $instructions === '' || $reward === false || $reward <= 0 || $quantity === false || $quantity < 1 || $quantity > 100000) {
            $error = 'Complete all required fields and enter a valid reward and quantity.';
        } else {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-')) . '-' . bin2hex(random_bytes(4));
            $stmt = $pdo->prepare("INSERT INTO pf_campaigns (advertiser_id,campaign_type,title,slug,summary,description,instructions,requirements,reward_per_completion,total_quantity,currency,evidence_required,status) VALUES (?,'WORKER_TASK',?,?,?,?,?,?,?,?,'USD',?,'DRAFT')");
            $stmt->execute([(int)$user['id'],$title,$slug,$summary ?: null,$description,$instructions,$requirements ?: null,$reward,$quantity,$evidenceRequired]);
            $notice = 'Campaign saved as a draft. It will not be shown to workers until it is funded and approved.';
        }
    }

    if ($action === 'resume') {
        $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$campaignId) {
            $error = 'Invalid campaign.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT id,status,approved_quantity,total_quantity,reserved_budget,ends_at FROM pf_campaigns WHERE id=? AND advertiser_id=? FOR UPDATE');
                $stmt->execute([$campaignId,(int)$user['id']]);
                $campaign = $stmt->fetch();
                if (!$campaign || $campaign['status'] !== 'PAUSED'
                    || (int)$campaign['approved_quantity'] >= (int)$campaign['total_quantity']
                    || ($campaign['ends_at'] !== null && strtotime((string)$campaign['ends_at']) < time())) {
                    throw new RuntimeException('This campaign cannot be resumed. Check its status, remaining quantity and end date.');
                }
                $reservation = $pdo->prepare("SELECT id,amount FROM pf_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
                $reservation->execute([$campaignId]);
                $reserved = $reservation->fetch();
                if (!$reserved || (float)$reserved['amount'] <= 0
                    || (float)$campaign['reserved_budget'] + 0.00001 < (float)$reserved['amount']) {
                    throw new RuntimeException('This campaign has no remaining reserved budget and cannot be resumed.');
                }
                $update = $pdo->prepare("UPDATE pf_campaigns SET status='ACTIVE' WHERE id=? AND advertiser_id=? AND status='PAUSED'");
                $update->execute([$campaignId,(int)$user['id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Campaign status changed. Please refresh and try again.');
                }
                $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $ipBinary = filter_var($remoteIp,FILTER_VALIDATE_IP) ? inet_pton($remoteIp) : null;
                $audit = $pdo->prepare('INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,before_json,after_json,ip_address) VALUES (?,?,?,?,?,?,?)');
                $audit->execute([(int)$user['id'],'ADVERTISER_CAMPAIGN_RESUMED','CAMPAIGN',$campaignId,json_encode(['status'=>'PAUSED'],JSON_THROW_ON_ERROR),json_encode(['status'=>'ACTIVE'],JSON_THROW_ON_ERROR),$ipBinary]);
                $pdo->commit();
                $notice = 'Your campaign has been resumed.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Campaign could not be resumed.';
            }
        }
    }

    if ($action === 'review') {
        $submissionId = filter_var($_POST['submission_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string)($_POST['decision'] ?? '');
        $note = trim((string)($_POST['review_note'] ?? ''));
        if (!$submissionId || !in_array($decision, ['approve','reject'], true)) {
            $error = 'Invalid submission review request.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT s.id,s.campaign_id,s.worker_id,s.status,s.reward_amount,s.currency,c.advertiser_id,c.reserved_budget,c.status AS campaign_status FROM pf_submissions s JOIN pf_campaigns c ON c.id=s.campaign_id WHERE s.id=? FOR UPDATE");
                $stmt->execute([$submissionId]);
                $s = $stmt->fetch();
                if (!$s || (int)$s['advertiser_id'] !== (int)$user['id'] || $s['status'] !== 'PENDING') {
                    throw new RuntimeException('This submission is not available for review.');
                }
                if ($decision === 'reject') {
                    $upd = $pdo->prepare("UPDATE pf_submissions SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='PENDING'");
                    $upd->execute([(int)$user['id'],$note ?: null,$submissionId]);
                    $notice = 'Submission rejected. No worker reward was issued.';
                } else {
                    $reward = (float)$s['reward_amount'];
                    if ($s['campaign_status'] !== 'ACTIVE' || (float)$s['reserved_budget'] < $reward) {
                        throw new RuntimeException('This campaign has insufficient reserved budget or is not active. No reward was issued.');
                    }
                    $upd = $pdo->prepare("UPDATE pf_campaigns SET reserved_budget=reserved_budget-?,spent_budget=spent_budget+?,approved_quantity=approved_quantity+1 WHERE id=? AND reserved_budget>=? AND approved_quantity<total_quantity");
                    $upd->execute([$reward,$reward,(int)$s['campaign_id'],$reward]);
                    if ($upd->rowCount() !== 1) {
                        throw new RuntimeException('Campaign budget or completion limit changed. Please refresh and try again.');
                    }
                    $reservationUpdate = $pdo->prepare("UPDATE pf_campaign_reservations SET status=CASE WHEN amount<=? THEN 'CONSUMED' ELSE 'RESERVED' END, amount=amount-? WHERE campaign_id=? AND status='RESERVED' AND amount>=?");
                    $reservationUpdate->execute([$reward,$reward,(int)$s['campaign_id'],$reward]);
                    if ($reservationUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Campaign reservation does not match the remaining campaign budget.');
                    }
                    $pdo->prepare("INSERT IGNORE INTO pf_wallets (user_id,wallet_type,currency) VALUES (?,'WORKER',?)")->execute([(int)$s['worker_id'],$s['currency']]);
                    $walletStmt = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='WORKER' AND currency=? FOR UPDATE");
                    $walletStmt->execute([(int)$s['worker_id'],$s['currency']]);
                    $walletId = (int)$walletStmt->fetchColumn();
                    $ledger = $pdo->prepare("INSERT INTO pf_wallet_ledger (wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'WORKER_REWARD',? ,?,'SUBMISSION',?,?,?,?)");
                    $ledger->execute([$walletId,(int)$s['worker_id'],$reward,$s['currency'],$submissionId,'worker-reward-submission-'.$submissionId,'Approved task reward',(int)$user['id']]);
                    $ledgerId = (int)$pdo->lastInsertId();
                    $upd = $pdo->prepare("UPDATE pf_submissions SET status='APPROVED',reviewed_by=?,reviewed_at=NOW(),review_note=?,reward_ledger_entry_id=? WHERE id=? AND status='PENDING'");
                    $upd->execute([(int)$user['id'],$note ?: null,$ledgerId,$submissionId]);
                    if ($upd->rowCount() !== 1) {
                        throw new RuntimeException('Submission status changed. No reward was issued.');
                    }
                    $notice = 'Submission approved and worker reward credited.';
                }
                $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $ipBinary = filter_var($remoteIp, FILTER_VALIDATE_IP) ? inet_pton($remoteIp) : null;
                $audit = $pdo->prepare(
                    'INSERT INTO pf_audit_log (actor_user_id,action,subject_type,subject_id,before_json,after_json,ip_address)
                     VALUES (?,?,?,?,?,?,?)'
                );
                $audit->execute([
                    (int) $user['id'],
                    $decision === 'approve' ? 'ADVERTISER_SUBMISSION_APPROVED' : 'ADVERTISER_SUBMISSION_REJECTED',
                    'SUBMISSION',
                    (int) $submissionId,
                    json_encode(['status'=>'PENDING'], JSON_THROW_ON_ERROR),
                    json_encode(['status'=>$decision === 'approve' ? 'APPROVED' : 'REJECTED'], JSON_THROW_ON_ERROR),
                    $ipBinary,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Review failed. No reward was issued.';
            }
        }
    }
}

$list = $pdo->prepare('SELECT * FROM pf_campaigns WHERE advertiser_id=? ORDER BY created_at DESC');
$list->execute([(int)$user['id']]);
$myCampaigns = $list->fetchAll();
$submissions = $pdo->prepare("SELECT s.id,s.campaign_id,s.worker_id,s.submission_text,s.evidence_json,s.status,s.reward_amount,s.currency,s.created_at,c.title,u.name AS worker_name,u.email AS worker_email FROM pf_submissions s JOIN pf_campaigns c ON c.id=s.campaign_id JOIN users u ON u.id=s.worker_id WHERE c.advertiser_id=? ORDER BY s.created_at DESC LIMIT 100");
$submissions->execute([(int)$user['id']]);
$reviews = $submissions->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Advertiser campaigns | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"><style>form.stack{display:grid;gap:10px}form.stack input,form.stack textarea{width:100%;padding:12px;border:1px solid var(--line);border-radius:12px;font:inherit}.table-wrap{overflow:auto}.review-row{border-top:1px solid var(--line);padding:18px 0}.review-actions{display:flex;gap:8px;flex-wrap:wrap}.button.danger{background:#fff1f2;color:#9f1239}</style></head><body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="tasks.php">Earn</a><a href="wallet.php">Wallets</a><a href="logout.php">Sign out</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">ADVERTISE MODE</p><h1>Your campaigns</h1><p class="muted">Create tasks for workers and review submitted evidence. Campaigns remain drafts until funding and moderation are complete.</p></section>
<?php if($notice): ?><div class="panel" style="margin-bottom:18px"><?=pf_e($notice)?></div><?php endif; ?><?php if($error): ?><div class="alert" style="margin-bottom:18px"><?=pf_e($error)?></div><?php endif; ?>
<section class="panel" style="margin-bottom:24px"><h2>Create a worker task</h2><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="create"><label>Campaign title</label><input name="title" maxlength="180" required><label>Short summary</label><input name="summary" maxlength="500"><label>Description</label><textarea name="description" rows="3" required></textarea><label>Worker instructions</label><textarea name="instructions" rows="4" required></textarea><label>Requirements</label><textarea name="requirements" rows="3"></textarea><label>Reward per approved completion (USD)</label><input name="reward" type="number" min="0.01" step="0.01" required><label>Number of completions</label><input name="quantity" type="number" min="1" max="100000" required><label><input type="checkbox" name="evidence_required" checked> Evidence required</label><button class="button primary" type="submit">Save campaign draft</button></form></section>
<section class="panel" style="margin-bottom:24px"><h2>Campaign history</h2><?php if(!$myCampaigns): ?><p class="muted">You have not created a campaign yet.</p><?php else: ?><div class="table-wrap"><table style="width:100%;border-collapse:collapse;text-align:left"><thead><tr><th>Campaign</th><th>Status</th><th>Reward</th><th>Progress</th><th>Reserved</th><th>Action</th></tr></thead><tbody><?php foreach($myCampaigns as $c): ?><tr><td><?=pf_e($c['title'])?></td><td><?=pf_e($c['status'])?></td><td><?=pf_e($c['currency'])?> <?=number_format((float)$c['reward_per_completion'],2)?></td><td><?=(int)$c['approved_quantity']?> / <?=(int)$c['total_quantity']?></td><td><?=number_format((float)$c['reserved_budget'],2)?></td><td><?php if($c['status']==='DRAFT'): ?><a href='fund-campaign.php?id=<?=(int)$c['id']?>'>Fund &amp; submit</a><?php elseif($c['status']==='PAUSED'): ?><form method="post" style="display:inline"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="resume"><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>"><button class="button primary" type="submit">Resume</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="panel"><h2>Worker submissions</h2><?php if(!$reviews): ?><p class="muted">No submissions have arrived yet.</p><?php else: ?><?php foreach($reviews as $s): ?><div class="review-row"><p class="eyebrow"><?=pf_e($s['title'])?> · <?=pf_e($s['status'])?></p><strong><?=pf_e($s['worker_name'])?></strong> <span class="small muted"><?=pf_e($s['worker_email'])?></span><p><?=nl2br(pf_e((string)$s['submission_text']))?></p><?php if($s['evidence_json']): ?><pre style="white-space:pre-wrap"><?=pf_e((string)$s['evidence_json'])?></pre><?php endif; ?><p class="small muted">Reward: <?=pf_e($s['currency'])?> <?=number_format((float)$s['reward_amount'],2)?></p><?php if($s['status']==='PENDING'): ?><form method="post" class="review-actions"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="review"><input type="hidden" name="submission_id" value="<?=(int)$s['id']?>"><input name="review_note" placeholder="Optional review note"><button class="button primary" name="decision" value="approve">Approve & pay</button><button class="button danger" name="decision" value="reject">Reject</button></form><?php endif; ?></div><?php endforeach; ?><?php endif; ?></section>
</main></body></html>
