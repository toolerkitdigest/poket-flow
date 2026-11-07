<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_worker($pdo);
$id = filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    pf_verify_csrf();
    $id = filter_input(INPUT_POST,'submission_id',FILTER_VALIDATE_INT);
    $reason = trim((string)($_POST['reason']??''));
    $evidenceUrl = trim((string)($_POST['evidence_url']??''));
    $parts = $evidenceUrl!=='' ? parse_url($evidenceUrl) : [];
    if (!$id || strlen($reason)<20 || strlen($reason)>2000
        || ($evidenceUrl!=='' && (!filter_var($evidenceUrl,FILTER_VALIDATE_URL)
            || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host']))) ) {
        $error = 'Explain the disagreement in at least 20 characters. Any evidence link must use HTTPS.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare(
                "SELECT s.id,s.status,s.reviewed_at,s.reward_amount,s.currency,c.id AS campaign_id,c.advertiser_id
                 FROM pf_submissions s JOIN pf_campaigns c ON c.id=s.campaign_id
                 WHERE s.id=? AND s.worker_id=? FOR UPDATE"
            );
            $stmt->execute([$id,(int)$user['id']]);
            $submission=$stmt->fetch();
            if (!$submission || $submission['status']!=='REJECTED'
                || !$submission['reviewed_at']
                || strtotime((string)$submission['reviewed_at'])<time()-72*3600) {
                throw new RuntimeException('This submission is not eligible for an appeal. Appeals must be filed within 72 hours of rejection.');
            }
            $evidence = $evidenceUrl!=='' ? json_encode([['type'=>'url','url'=>$evidenceUrl]],JSON_THROW_ON_ERROR) : null;
            $pdo->prepare(
                "INSERT INTO pf_submission_disputes(submission_id,campaign_id,worker_id,advertiser_id,reason,evidence_json,status)
                 VALUES (?,?,?,?,?,?,'OPEN')"
            )->execute([$id,(int)$submission['campaign_id'],(int)$user['id'],(int)$submission['advertiser_id'],$reason,$evidence]);
            $update=$pdo->prepare("UPDATE pf_submissions SET status='DISPUTED' WHERE id=? AND worker_id=? AND status='REJECTED'");
            $update->execute([$id,(int)$user['id']]);
            if($update->rowCount()!==1) throw new RuntimeException('Submission status changed. Please refresh and try again.');
            $pdo->prepare(
                "INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,before_json,after_json)
                 VALUES (?,?,?,?,?,?)"
            )->execute([(int)$user['id'],'WORKER_SUBMISSION_DISPUTED','SUBMISSION',$id,
                json_encode(['status'=>'REJECTED'],JSON_THROW_ON_ERROR),
                json_encode(['status'=>'DISPUTED'],JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            $notice='Your appeal has been sent to PoketFlow administration for review.';
        } catch (Throwable $exception) {
            if($pdo->inTransaction())$pdo->rollBack();
            $error=$exception instanceof RuntimeException?$exception->getMessage():'Your appeal could not be submitted.';
        }
    }
}

$stmt=$pdo->prepare(
    'SELECT s.id,s.status,s.reviewed_at,s.review_note,s.reward_amount,s.currency,c.title,u.name AS advertiser_name
     FROM pf_submissions s JOIN pf_campaigns c ON c.id=s.campaign_id JOIN users u ON u.id=c.advertiser_id
     WHERE s.id=? AND s.worker_id=? LIMIT 1'
);
$stmt->execute([$id,(int)$user['id']]);
$submission=$stmt->fetch();
if(!$submission){http_response_code(404);exit('Submission not found.');}
$eligible=$submission['status']==='REJECTED'&&$submission['reviewed_at']&&strtotime((string)$submission['reviewed_at'])>=time()-72*3600;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Appeal task rejection | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"><style>textarea,input{width:100%;padding:12px;border:1px solid var(--line);border-radius:12px;font:inherit}</style></head>
<body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><p class="eyebrow">SUBMISSION APPEAL</p><h1>Appeal this rejection</h1><p class="muted"><?=pf_e($submission['title'])?> · <?=pf_e($submission['advertiser_name'])?> · <?=pf_e($submission['currency'])?> <?=number_format((float)$submission['reward_amount'],2)?></p>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?><p><a href="my-submissions.php">View my submissions</a></p></div><?php endif;?>
<?php if($eligible&&!$notice):?><form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="submission_id" value="<?=(int)$submission['id']?>"><label>Why do you believe the rejection was incorrect?</label><textarea name="reason" rows="5" minlength="20" maxlength="2000" required></textarea><label>Evidence URL (optional, HTTPS only)</label><input type="url" name="evidence_url" placeholder="https://..."><p class="small muted">You have 72 hours after rejection to appeal. An administrator will review your explanation and evidence.</p><button class="button primary" type="submit">Submit appeal</button></form><?php else:?><p class="muted">This submission is no longer eligible for a new appeal.</p><?php endif;?>
</main></body></html>
