<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $campaignId = filter_input(INPUT_POST, 'campaign_id', FILTER_VALIDATE_INT);
    $submissionText = trim((string)($_POST['submission_text'] ?? ''));
    $evidenceUrl = trim((string)($_POST['evidence_url'] ?? ''));

    if (!$campaignId) {
        $error = 'Choose a valid task.';
    } elseif ($evidenceUrl !== '' && !filter_var($evidenceUrl, FILTER_VALIDATE_URL)) {
        $error = 'Enter a valid evidence URL, or leave it blank if the task does not require one.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, advertiser_id, reward_per_completion, currency, total_quantity, approved_quantity, evidence_required FROM pf_campaigns WHERE id=? AND campaign_type='WORKER_TASK' AND status='ACTIVE' AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) FOR UPDATE");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch();

            if (!$campaign || (int)$campaign['advertiser_id'] === (int)$user['id']) {
                throw new RuntimeException('This task is not available to your account.');
            }
            $claimed = $pdo->prepare("SELECT COUNT(*) FROM pf_submissions WHERE campaign_id=? AND status IN ('PENDING','APPROVED')");
            $claimed->execute([$campaignId]);
            if ((int)$claimed->fetchColumn() >= (int)$campaign['total_quantity']) {
                throw new RuntimeException('All available places for this task have already been claimed.');
            }
            if ((int)$campaign['evidence_required'] === 1 && $evidenceUrl === '' && $submissionText === '') {
                throw new RuntimeException('Provide the requested evidence or completion details.');
            }

            $dupe = $pdo->prepare("SELECT id FROM pf_submissions WHERE campaign_id=? AND worker_id=? AND status IN ('PENDING','APPROVED') LIMIT 1");
            $dupe->execute([$campaignId, (int)$user['id']]);
            if ($dupe->fetchColumn()) {
                throw new RuntimeException('You have already submitted this task.');
            }

            $evidence = $evidenceUrl !== '' ? json_encode([['type'=>'url','url'=>$evidenceUrl]], JSON_THROW_ON_ERROR) : null;
            $insert = $pdo->prepare("INSERT INTO pf_submissions (campaign_id,worker_id,submission_text,evidence_json,status,reward_amount,currency) VALUES (?,?,?,?, 'PENDING',?,?)");
            $insert->execute([$campaignId,(int)$user['id'],$submissionText ?: null,$evidence,$campaign['reward_per_completion'],$campaign['currency']]);
            $pdo->commit();
            $message = 'Your work has been submitted. The advertiser will review it before any reward is credited.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'We could not submit your work. Please try again.';
        }
    }
}

$stmt = $pdo->query("SELECT c.id,c.title,c.summary,c.description,c.instructions,c.requirements,c.reward_per_completion,c.currency,c.total_quantity,c.approved_quantity,(SELECT COUNT(*) FROM pf_submissions ps WHERE ps.campaign_id=c.id AND ps.status IN ('PENDING','APPROVED')) AS claimed_quantity,c.evidence_required,c.evidence_instructions,u.name AS advertiser_name FROM pf_campaigns c JOIN users u ON u.id=c.advertiser_id WHERE c.campaign_type='WORKER_TASK' AND c.status='ACTIVE' AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.ends_at IS NULL OR c.ends_at>=NOW()) AND (SELECT COUNT(*) FROM pf_submissions ps WHERE ps.campaign_id=c.id AND ps.status IN ('PENDING','APPROVED'))<c.total_quantity ORDER BY c.created_at DESC LIMIT 100");
$campaigns = $stmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Available tasks | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head><body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="campaigns.php">Advertise</a><a href="wallet.php">Wallets</a><a href="logout.php">Sign out</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">EARN MODE</p><h1>Available tasks</h1><p class="muted">Complete advertiser-created tasks and submit your evidence. Rewards are credited only after approval.</p></section>
<?php if($message): ?><div class="panel" style="margin-bottom:18px"><?=pf_e($message)?></div><?php endif; ?><?php if($error): ?><div class="alert" style="margin-bottom:18px"><?=pf_e($error)?></div><?php endif; ?>
<?php if(!$campaigns): ?><div class="panel"><h2>No active tasks right now</h2><p class="muted">Please check again later.</p></div><?php endif; ?>
<div class="mode-grid"><?php foreach($campaigns as $c): ?><article class="panel"><p class="eyebrow"><?=pf_e((string)($c['advertiser_name']??'Advertiser'))?></p><h2><?=pf_e($c['title'])?></h2><div class="metric"><?=pf_e($c['currency'])?> <?=number_format((float)$c['reward_per_completion'],2)?><span>Reward after approval</span></div><p class="muted"><?=pf_e((string)($c['summary']??$c['description']))?></p><p class="small muted"><?=max(0,(int)$c['total_quantity']-(int)$c['claimed_quantity'])?> places remaining</p><details><summary>Read instructions and submit</summary><p><?=nl2br(pf_e((string)$c['instructions']))?></p><p><?=nl2br(pf_e((string)$c['requirements']))?></p><p class="small muted"><?=pf_e((string)$c['evidence_instructions'])?></p><form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>"><label>Completion details</label><textarea name="submission_text" rows="3" style="width:100%;padding:12px;border:1px solid var(--line);border-radius:12px"></textarea><label>Evidence URL (if requested)</label><input name="evidence_url" type="url" placeholder="https://..." style="width:100%;padding:12px;border:1px solid var(--line);border-radius:12px"><button class="button primary" type="submit" style="margin-top:12px">Submit for review</button></form></details></article><?php endforeach; ?></div></main></body></html>
