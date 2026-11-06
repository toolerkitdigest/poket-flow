<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/app.php';
$admin = pf_require_admin($pdo);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $disputeId = filter_var($_POST['dispute_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['admin_note'] ?? ''));

    if (!$disputeId || !in_array($decision, ['worker','advertiser'], true) || strlen($note) > 2000) {
        $error = 'Choose a valid dispute decision and keep the note under 2,000 characters.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT d.id,d.submission_id,d.campaign_id,d.worker_id,d.advertiser_id,d.status AS dispute_status,
                        s.status AS submission_status,s.reward_amount,s.currency,
                        c.status AS campaign_status,c.reserved_budget,c.approved_quantity,c.total_quantity
                 FROM pf_submission_disputes d
                 JOIN pf_submissions s ON s.id=d.submission_id
                 JOIN pf_campaigns c ON c.id=d.campaign_id
                 WHERE d.id=? FOR UPDATE"
            );
            $stmt->execute([$disputeId]);
            $d = $stmt->fetch();
            if (!$d || $d['dispute_status'] !== 'OPEN' || $d['submission_status'] !== 'DISPUTED') {
                throw new RuntimeException('This dispute has already been resolved or is no longer available.');
            }

            if ($decision === 'advertiser') {
                $update = $pdo->prepare(
                    "UPDATE pf_submissions SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),
                     review_note=? WHERE id=? AND status='DISPUTED'"
                );
                $update->execute([(int)$admin['id'], $note !== '' ? $note : 'Administrator upheld the advertiser decision.', (int)$d['submission_id']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('Submission status changed. No dispute decision was saved.');
                $disputeStatus = 'RESOLVED_ADVERTISER';
            } else {
                $reward = round((float)$d['reward_amount'], 2);
                if ($reward <= 0 || (float)$d['reserved_budget'] + 0.00001 < $reward
                    || (int)$d['approved_quantity'] >= (int)$d['total_quantity']) {
                    throw new RuntimeException('The campaign no longer has enough reserved budget or completion capacity. Manual reconciliation is required.');
                }

                $reservation = $pdo->prepare(
                    "SELECT id,amount FROM pf_campaign_reservations
                     WHERE campaign_id=? AND status='RESERVED' FOR UPDATE"
                );
                $reservation->execute([(int)$d['campaign_id']]);
                $reserve = $reservation->fetch();
                if (!$reserve || (float)$reserve['amount'] + 0.00001 < $reward
                    || abs((float)$reserve['amount'] - (float)$d['reserved_budget']) > 0.01) {
                    throw new RuntimeException('Campaign reservation and remaining budget do not reconcile. No reward was issued.');
                }

                $campaignUpdate = $pdo->prepare(
                    'UPDATE pf_campaigns SET reserved_budget=reserved_budget-?,spent_budget=spent_budget+?,
                     approved_quantity=approved_quantity+1 WHERE id=? AND reserved_budget>=? AND approved_quantity<total_quantity'
                );
                $campaignUpdate->execute([$reward,$reward,(int)$d['campaign_id'],$reward]);
                if ($campaignUpdate->rowCount() !== 1) throw new RuntimeException('Campaign budget changed. No reward was issued.');

                $reserveUpdate = $pdo->prepare(
                    "UPDATE pf_campaign_reservations SET status=CASE WHEN amount<=? THEN 'CONSUMED' ELSE 'RESERVED' END,
                     amount=amount-? WHERE id=? AND status='RESERVED' AND amount>=?"
                );
                $reserveUpdate->execute([$reward,$reward,(int)$reserve['id'],$reward]);
                if ($reserveUpdate->rowCount() !== 1) throw new RuntimeException('Campaign reservation changed. No reward was issued.');

                $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'WORKER',?)")
                    ->execute([(int)$d['worker_id'],$d['currency']]);
                $wallet = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='WORKER' AND currency=? FOR UPDATE");
                $wallet->execute([(int)$d['worker_id'],$d['currency']]);
                $walletId = (int)$wallet->fetchColumn();
                if (!$walletId) throw new RuntimeException('Worker wallet could not be located.');

                $ledger = $pdo->prepare(
                    "INSERT INTO pf_wallet_ledger
                     (wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by)
                     VALUES (?,?,'WORKER_REWARD',?,?,'SUBMISSION',?,?,?,?)"
                );
                $ledger->execute([$walletId,(int)$d['worker_id'],$reward,$d['currency'],(int)$d['submission_id'],
                    'worker-reward-submission-'.(int)$d['submission_id'],'Reward issued after administrator dispute resolution',(int)$admin['id']]);
                $ledgerId = (int)$pdo->lastInsertId();

                $submissionUpdate = $pdo->prepare(
                    "UPDATE pf_submissions SET status='APPROVED',reviewed_by=?,reviewed_at=NOW(),
                     review_note=?,reward_ledger_entry_id=? WHERE id=? AND status='DISPUTED'"
                );
                $submissionUpdate->execute([(int)$admin['id'],$note !== '' ? $note : 'Administrator resolved the appeal in favour of the worker.',$ledgerId,(int)$d['submission_id']]);
                if ($submissionUpdate->rowCount() !== 1) throw new RuntimeException('Submission status changed. Reward transaction was rolled back.');
                $disputeStatus = 'RESOLVED_WORKER';
            }

            $disputeUpdate = $pdo->prepare(
                'UPDATE pf_submission_disputes SET status=?,admin_note=?,resolved_by=?,resolved_at=NOW()
                 WHERE id=? AND status=\'OPEN\''
            );
            $disputeUpdate->execute([$disputeStatus,$note !== '' ? $note : null,(int)$admin['id'],$disputeId]);
            if ($disputeUpdate->rowCount() !== 1) throw new RuntimeException('Dispute status changed. Transaction rolled back.');

            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ipBinary = filter_var($ip,FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
            $audit = $pdo->prepare(
                'INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,before_json,after_json,ip_address)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $audit->execute([(int)$admin['id'],'ADMIN_DISPUTE_RESOLVED','SUBMISSION',(int)$d['submission_id'],
                json_encode(['dispute_status'=>'OPEN','submission_status'=>'DISPUTED'],JSON_THROW_ON_ERROR),
                json_encode(['dispute_status'=>$disputeStatus,'decision'=>$decision],JSON_THROW_ON_ERROR),$ipBinary]);
            $pdo->commit();
            $notice = $decision === 'worker'
                ? 'Appeal resolved in favour of the worker. The reserved reward has been credited.'
                : 'Appeal resolved in favour of the advertiser. The submission remains rejected.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Dispute resolution failed. No changes were saved.';
        }
    }
}

$disputes = $pdo->query(
    "SELECT d.id,d.reason,d.evidence_json,d.created_at,d.worker_id,d.advertiser_id,
            s.id AS submission_id,s.reward_amount,s.currency,s.submission_text,
            c.title,c.reserved_budget,c.status AS campaign_status,
            w.name AS worker_name,w.email AS worker_email,
            a.name AS advertiser_name,a.email AS advertiser_email
     FROM pf_submission_disputes d
     JOIN pf_submissions s ON s.id=d.submission_id
     JOIN pf_campaigns c ON c.id=d.campaign_id
     JOIN users w ON w.id=d.worker_id
     JOIN users a ON a.id=d.advertiser_id
     WHERE d.status IN ('OPEN','UNDER_REVIEW')
     ORDER BY d.created_at ASC LIMIT 100"
)->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Submission appeals | PoketFlow</title><link rel="stylesheet" href="../assets/marketplace.css"><style>.appeal{padding:20px 0;border-top:1px solid var(--line)}.stack{display:flex;gap:8px;flex-wrap:wrap}.stack input{flex:1;min-width:220px;padding:11px;border:1px solid var(--line);border-radius:10px}.danger{background:#fff1f2;color:#9f1239}.evidence{white-space:pre-wrap;overflow-wrap:anywhere}</style></head>
<body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="index.php">Admin home</a><a href="finance.php">Finance</a><a href="users.php">Members</a><a href="../dashboard.php">Dashboard</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">FAIR REVIEW</p><h1>Worker submission appeals</h1><p class="muted">Review disputed rejections independently. A worker-favour decision credits the reward only from the campaign's remaining reserved budget.</p></section>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<section class="panel"><?php if(!$disputes):?><p class="muted">There are no open appeals.</p><?php else:?><?php foreach($disputes as $d):?><article class="appeal"><p class="eyebrow">APPEAL #<?=(int)$d['id']?> · <?=pf_e($d['created_at'])?></p><h2><?=pf_e($d['title'])?></h2><p><strong>Worker:</strong> <?=pf_e($d['worker_name'])?> · <?=pf_e($d['worker_email'])?></p><p><strong>Advertiser:</strong> <?=pf_e($d['advertiser_name'])?> · <?=pf_e($d['advertiser_email'])?></p><p><strong>Reward under appeal:</strong> <?=pf_e($d['currency'])?> <?=number_format((float)$d['reward_amount'],2)?></p><p><strong>Worker's explanation</strong></p><div class="evidence"><?=pf_e($d['reason'])?></div><?php if($d['submission_text']):?><p><strong>Original completion details</strong></p><div class="evidence"><?=pf_e($d['submission_text'])?></div><?php endif;?><?php if($d['evidence_json']):?><p><strong>Appeal evidence</strong></p><pre class="evidence"><?=pf_e((string)$d['evidence_json'])?></pre><?php endif;?><p class="small muted">Campaign: <?=pf_e($d['campaign_status'])?> · Remaining campaign reserve: <?=pf_e($d['currency'])?> <?=number_format((float)$d['reserved_budget'],2)?></p><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="dispute_id" value="<?=(int)$d['id']?>"><input name="admin_note" maxlength="2000" placeholder="Decision reason (recommended)"><button class="button primary" name="decision" value="worker" type="submit">Uphold worker & credit reward</button><button class="button danger" name="decision" value="advertiser" type="submit">Uphold advertiser rejection</button></form></article><?php endforeach;?><?php endif;?></section></main></body></html>
