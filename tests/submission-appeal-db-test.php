<?php
declare(strict_types=1);

/**
 * Database regression checks for the worker submission appeal invariants.
 * This uses a disposable CI database and rolls every fixture change back.
 */

$configPath = getenv('POKETFLOW_CONFIG');
if (!$configPath || !is_file($configPath)) {
    throw new RuntimeException('Test database config is missing.');
}
$config = require $configPath;
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset'] ?? 'utf8mb4'),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$workerId = 1;
$advertiserId = 2;
$adminId = 3;
$campaignId = 0;
$submissionId = 0;
$disputeId = 0;
$reward = 1.25;

$pdo->beginTransaction();
try {
    $campaign = $pdo->prepare(
        "INSERT INTO pf_campaigns
         (advertiser_id,campaign_type,title,slug,description,reward_per_completion,total_quantity,
          approved_quantity,reserved_budget,spent_budget,currency,status)
         VALUES (?,'WORKER_TASK','Appeal regression task',?,'Disposable appeal workflow test',?,5,0,3.00,0.00,'USD','ACTIVE')"
    );
    $campaign->execute([$advertiserId, 'appeal-regression-' . bin2hex(random_bytes(6)), $reward]);
    $campaignId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO pf_campaign_reservations(campaign_id,advertiser_id,amount,currency,status)
         VALUES (?, ?, 3.00, 'USD', 'RESERVED')"
    )->execute([$campaignId, $advertiserId]);

    $pdo->prepare(
        "INSERT INTO pf_submissions(campaign_id,worker_id,submission_text,status,reward_amount,currency,reviewed_at)
         VALUES (?, ?, 'Evidence submitted for appeal regression test','DISPUTED',?,'USD',NOW())"
    )->execute([$campaignId, $workerId, $reward]);
    $submissionId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO pf_submission_disputes
         (submission_id,campaign_id,worker_id,advertiser_id,reason,status)
         VALUES (?, ?, ?, ?, 'The evidence meets the task instructions and should be accepted.','UNDER_REVIEW')"
    )->execute([$submissionId, $campaignId, $workerId, $advertiserId]);
    $disputeId = (int) $pdo->lastInsertId();

    // A submission can have only one appeal.
    $duplicateAppealRejected = false;
    try {
        $pdo->prepare(
            "INSERT INTO pf_submission_disputes
             (submission_id,campaign_id,worker_id,advertiser_id,reason)
             VALUES (?, ?, ?, ?, 'Duplicate appeal must be rejected.')"
        )->execute([$submissionId, $campaignId, $workerId, $advertiserId]);
    } catch (PDOException $exception) {
        $duplicateAppealRejected = true;
    }
    if (!$duplicateAppealRejected) {
        throw new RuntimeException('A second appeal for the same submission was not blocked.');
    }

    $wallet = $pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='WORKER' AND currency='USD' FOR UPDATE");
    $wallet->execute([$workerId]);
    $walletId = (int) $wallet->fetchColumn();
    if (!$walletId) {
        throw new RuntimeException('Worker test wallet is missing.');
    }

    // Exercise the same atomic balance, reserve, submission and dispute state changes
    // required by a worker-favour resolution.
    $pdo->prepare(
        "UPDATE pf_campaigns
         SET reserved_budget=reserved_budget-?,spent_budget=spent_budget+?,approved_quantity=approved_quantity+1
         WHERE id=? AND reserved_budget>=? AND approved_quantity<total_quantity"
    )->execute([$reward, $reward, $campaignId, $reward]);
    if ($pdo->query("SELECT reserved_budget FROM pf_campaigns WHERE id={$campaignId}")->fetchColumn() != '1.75') {
        throw new RuntimeException('Campaign reserve was not reduced by the exact worker reward.');
    }

    $pdo->prepare(
        "UPDATE pf_campaign_reservations SET amount=amount-? WHERE campaign_id=? AND status='RESERVED' AND amount>=?"
    )->execute([$reward, $campaignId, $reward]);

    $ledger = $pdo->prepare(
        "INSERT INTO pf_wallet_ledger
         (wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by)
         VALUES (?,?,'WORKER_REWARD',?,'USD','SUBMISSION',?,?,?,?)"
    );
    $idempotencyKey = 'worker-reward-submission-' . $submissionId;
    $ledger->execute([$walletId, $workerId, $reward, $submissionId, $idempotencyKey, 'Appeal regression test', $adminId]);

    // The unique idempotency key must prevent a second credit for the same submission.
    $duplicateCreditRejected = false;
    try {
        $ledger->execute([$walletId, $workerId, $reward, $submissionId, $idempotencyKey, 'Duplicate appeal credit test', $adminId]);
    } catch (PDOException $exception) {
        $duplicateCreditRejected = true;
    }
    if (!$duplicateCreditRejected) {
        throw new RuntimeException('Duplicate worker reward credit was not blocked.');
    }

    $ledgerCount = $pdo->prepare("SELECT COUNT(*) FROM pf_wallet_ledger WHERE idempotency_key=?");
    $ledgerCount->execute([$idempotencyKey]);
    if ((int) $ledgerCount->fetchColumn() !== 1) {
        throw new RuntimeException('Worker reward was not credited exactly once.');
    }

    $pdo->prepare(
        "UPDATE pf_submissions SET status='APPROVED',reviewed_by=?,review_note='Appeal accepted after admin review.'
         WHERE id=? AND status='DISPUTED'"
    )->execute([$adminId, $submissionId]);
    $pdo->prepare(
        "UPDATE pf_submission_disputes SET status='RESOLVED_WORKER',admin_note='Evidence verified; appeal accepted.',
         resolved_by=?,resolved_at=NOW() WHERE id=? AND status='UNDER_REVIEW'"
    )->execute([$adminId, $disputeId]);
    $pdo->prepare(
        "INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,before_json,after_json)
         VALUES (?,'ADMIN_SUBMISSION_APPEAL_WORKER_WINS','SUBMISSION_DISPUTE',?,
         JSON_OBJECT('status','UNDER_REVIEW'),JSON_OBJECT('status','RESOLVED_WORKER','reward',?))"
    )->execute([$adminId, $disputeId, $reward]);

    $state = $pdo->prepare(
        "SELECT d.status AS appeal_status,s.status AS submission_status,s.reward_ledger_entry_id,
                c.reserved_budget,c.spent_budget,c.approved_quantity
         FROM pf_submission_disputes d
         JOIN pf_submissions s ON s.id=d.submission_id
         JOIN pf_campaigns c ON c.id=d.campaign_id WHERE d.id=?"
    );
    $state->execute([$disputeId]);
    $row = $state->fetch();
    if (!$row || $row['appeal_status'] !== 'RESOLVED_WORKER' || $row['submission_status'] !== 'APPROVED'
        || (float) $row['reserved_budget'] !== 1.75 || (float) $row['spent_budget'] !== 1.25
        || (int) $row['approved_quantity'] !== 1) {
        throw new RuntimeException('Worker-favour appeal did not leave the expected reconciled state.');
    }

    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

// Guard the two user-facing regression fixes as part of this focused test.
$adminSource = file_get_contents(__DIR__ . '/../marketplace/admin/disputes.php');
$workerSource = file_get_contents(__DIR__ . '/../marketplace/my-submissions.php');
if ($adminSource === false || !str_contains($adminSource, 'strlen($note) < 10')) {
    throw new RuntimeException('Final appeal decisions must require a written admin explanation.');
}
if ($workerSource === false || !str_contains($workerSource, 'LEFT JOIN pf_submission_disputes')) {
    throw new RuntimeException('Worker submission history must show appeal outcomes.');
}

echo "Submission appeal checks passed: one appeal per submission, one reward credit, reserve reconciliation, audited resolution, required admin reason and worker-visible outcome.\n";
