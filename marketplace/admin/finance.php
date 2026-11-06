<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/app.php';
$admin=pf_require_login($pdo);
if(strtoupper((string)$admin['role'])!=='ADMIN'){http_response_code(403);exit('Administrator access required.');}
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 pf_verify_csrf();$action=(string)($_POST['action']??'');$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);$decision=(string)($_POST['decision']??'');$note=trim((string)($_POST['note']??''));
 if(!$id){$error='Invalid record.';}
 else{
  $pdo->beginTransaction();
  try{
   if($action==='funding'){
    $q=$pdo->prepare("SELECT * FROM pf_funding_requests WHERE id=? FOR UPDATE");$q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['status']!=='PENDING'||!in_array($decision,['approve','reject'],true))throw new RuntimeException('Funding request is no longer pending.');
    if($decision==='approve'){
     $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'ADVERTISER',?)")->execute([(int)$r['advertiser_id'],$r['currency']]);
     $w=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");$w->execute([(int)$r['advertiser_id'],$r['currency']]);$wid=(int)$w->fetchColumn();
     $l=$pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'DEPOSIT',?,?,'FUNDING_REQUEST',?,?,?,?)");
     $l->execute([$wid,(int)$r['advertiser_id'],$r['amount'],$r['currency'],$id,'funding-request-'.$id,'Verified advertiser deposit',$admin['id']]);$lid=(int)$pdo->lastInsertId();
     $pdo->prepare("UPDATE pf_funding_requests SET status='APPROVED',ledger_entry_id=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='PENDING'")->execute([$lid,$admin['id'],$note?:null,$id]);
    }else{$pdo->prepare("UPDATE pf_funding_requests SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='PENDING'")->execute([$admin['id'],$note?:null,$id]);}
   }elseif($action==='withdrawal'){
    $q=$pdo->prepare("SELECT * FROM pf_withdrawals WHERE id=? FOR UPDATE");$q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['status']!=='PENDING'||!in_array($decision,['paid','reject'],true))throw new RuntimeException('Withdrawal is no longer pending.');
    if($decision==='reject'){
     $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'REFUND',?,?,'WITHDRAWAL',?,?,?,?)")->execute([(int)$r['wallet_id'],(int)$r['user_id'],$r['amount'],$r['currency'],$id,'withdrawal-release-'.$id,'Released rejected withdrawal hold',$admin['id']]);
     $pdo->prepare("UPDATE pf_withdrawals SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=? AND status='PENDING'")->execute([$admin['id'],$note?:null,$id]);
    }else{
     $pdo->prepare("UPDATE pf_withdrawals SET status='PAID',reviewed_by=?,reviewed_at=NOW(),payout_reference=?,admin_note=? WHERE id=? AND status='PENDING'")->execute([$admin['id'],trim((string)($_POST['payout_reference']??''))?:null,$note?:null,$id]);
    }
   }elseif($action==='campaign'){
    $q=$pdo->prepare("SELECT * FROM pf_campaigns WHERE id=? FOR UPDATE");$q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['status']!=='PENDING_REVIEW'||!in_array($decision,['approve','reject'],true))throw new RuntimeException('Campaign is no longer awaiting review.');
    if($decision==='approve'){$pdo->prepare("UPDATE pf_campaigns SET status='ACTIVE',reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=?")->execute([$admin['id'],$note?:null,$id]);}
    else{
     $pdo->prepare("UPDATE pf_campaigns SET status='REJECTED',reserved_budget=0,reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=?")->execute([$admin['id'],$note?:null,$id]);
     $res=$pdo->prepare("SELECT id,amount,advertiser_id,currency,ledger_entry_id FROM pf_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");$res->execute([$id]);$reservation=$res->fetch();
     if($reservation){
      $wallet=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");$wallet->execute([(int)$reservation['advertiser_id'],$reservation['currency']]);$wid=(int)$wallet->fetchColumn();
      $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'CAMPAIGN_RELEASE',?,?,'CAMPAIGN',?,?,?,?)")->execute([$wid,(int)$reservation['advertiser_id'],$reservation['amount'],$reservation['currency'],$id,'campaign-release-'.$id,'Released budget for rejected campaign',$admin['id']]);
      $pdo->prepare("UPDATE pf_campaign_reservations SET status='RELEASED' WHERE id=?")->execute([(int)$reservation['id']]);
     }
    }
   }else{throw new RuntimeException('Unknown admin action.');}
   $pdo->commit();$notice='The requested finance or campaign action has been recorded.';
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'The action failed and was rolled back.';}
 }
}
$fund=$pdo->query("SELECT f.*,u.name,u.email FROM pf_funding_requests f JOIN users u ON u.id=f.advertiser_id WHERE f.status='PENDING' ORDER BY f.id ASC LIMIT 100")->fetchAll();
$withdraw=$pdo->query("SELECT w.*,u.name,u.email FROM pf_withdrawals w JOIN users u ON u.id=w.user_id WHERE w.status='PENDING' ORDER BY w.id ASC LIMIT 100")->fetchAll();
$campaigns=$pdo->query("SELECT c.*,u.name AS advertiser_name FROM pf_campaigns c JOIN users u ON u.id=c.advertiser_id WHERE c.status='PENDING_REVIEW' ORDER BY c.id ASC LIMIT 100")->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finance administration | PoketFlow</title><link rel="stylesheet" href="../assets/marketplace.css"><style>.stack{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.stack input{padding:10px;border:1px solid var(--line);border-radius:10px}.review{padding:18px 0;border-top:1px solid var(--line)}.danger{background:#fff1f2;color:#9f1239}.section{margin-top:24px}</style></head><body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="../dashboard.php">Dashboard</a><a href="../logout.php">Sign out</a></nav></header><main class="container"><section class="welcome"><p class="eyebrow">ADMINISTRATION</p><h1>Finance & campaign review</h1><p class="muted">Verify payments independently before approving funding. Withdrawal requests already have funds held in the worker wallet.</p></section><?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<section class="panel section"><h2>Pending advertiser funding</h2><?php foreach($fund as $r):?><div class="review"><strong><?=pf_e($r['name'])?></strong> · <?=pf_e($r['email'])?><p><?=pf_e($r['currency'])?> <?=number_format((float)$r['amount'],2)?> · <?=pf_e($r['payment_method'])?> · Ref: <?=pf_e((string)$r['payer_reference'])?></p><?php if($r['proof_url']):?><p><a href="<?=pf_e($r['proof_url'])?>" target="_blank" rel="noopener">Open payment proof</a></p><?php endif;?><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="funding"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="note" placeholder="Admin note"><button class="button primary" name="decision" value="approve">Verify & credit wallet</button><button class="button danger" name="decision" value="reject">Reject</button></form></div><?php endforeach;?><?php if(!$fund):?><p class="muted">No pending funding requests.</p><?php endif;?></section>
<section class="panel section"><h2>Pending withdrawals</h2><?php foreach($withdraw as $r):?><div class="review"><strong><?=pf_e($r['name'])?></strong> · <?=pf_e($r['email'])?><p><?=pf_e($r['currency'])?> <?=number_format((float)$r['amount'],2)?> · <?=pf_e($r['method'])?> · Withdrawal #<?=(int)$r['id']?></p><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="withdrawal"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="payout_reference" placeholder="Payment transaction reference"><input name="note" placeholder="Admin note"><button class="button primary" name="decision" value="paid">Mark paid</button><button class="button danger" name="decision" value="reject">Reject & release hold</button></form></div><?php endforeach;?><?php if(!$withdraw):?><p class="muted">No pending withdrawals.</p><?php endif;?></section>
<section class="panel section"><h2>Campaigns awaiting approval</h2><?php foreach($campaigns as $r):?><div class="review"><strong><?=pf_e($r['title'])?></strong> · <?=pf_e($r['advertiser_name'])?><p>Reserved budget: <?=pf_e($r['currency'])?> <?=number_format((float)$r['reserved_budget'],2)?> · <?= (int)$r['total_quantity']?> completions</p><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="campaign"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="note" placeholder="Moderation note"><button class="button primary" name="decision" value="approve">Approve & activate</button><button class="button danger" name="decision" value="reject">Reject & release budget</button></form></div><?php endforeach;?><?php if(!$campaigns):?><p class="muted">No campaigns awaiting review.</p><?php endif;?></section></main></body></html>
