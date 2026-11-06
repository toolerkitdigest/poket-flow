<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_worker($pdo);
$stmt = $pdo->prepare(
    'SELECT s.id,s.status,s.reward_amount,s.currency,s.created_at,s.reviewed_at,s.review_note,
            c.title,u.name AS advertiser_name
     FROM pf_submissions s JOIN pf_campaigns c ON c.id=s.campaign_id
     JOIN users u ON u.id=c.advertiser_id
     WHERE s.worker_id=? ORDER BY s.created_at DESC LIMIT 100'
);
$stmt->execute([(int)$user['id']]);
$submissions = $stmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My submissions | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"><style>.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:12px;border-bottom:1px solid var(--line);white-space:nowrap}</style></head>
<body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="tasks.php">Find tasks</a><a href="wallet.php">Wallets</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">WORKER HISTORY</p><h1>My submissions</h1><p class="muted">Track advertiser decisions and appeal a rejection within 72 hours.</p></section>
<section class="panel"><?php if(!$submissions):?><p class="muted">You have not submitted a task yet.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Task</th><th>Advertiser</th><th>Reward</th><th>Status</th><th>Submitted</th><th>Reviewed</th><th>Action</th></tr></thead><tbody>
<?php foreach($submissions as $s):?><tr><td><?=pf_e($s['title'])?></td><td><?=pf_e($s['advertiser_name'])?></td><td><?=pf_e($s['currency'])?> <?=number_format((float)$s['reward_amount'],2)?></td><td><?=pf_e($s['status'])?><?php if($s['review_note']):?><br><span class="small muted"><?=pf_e($s['review_note'])?></span><?php endif;?></td><td><?=pf_e($s['created_at'])?></td><td><?=pf_e((string)$s['reviewed_at'])?></td><td><?php if($s['status']==='REJECTED'&&$s['reviewed_at']&&strtotime((string)$s['reviewed_at'])>=time()-72*3600):?><a href="dispute.php?id=<?=(int)$s['id']?>">Appeal rejection</a><?php elseif($s['status']==='DISPUTED'):?>Under review<?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?></section></main></body></html>
