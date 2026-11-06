<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/app.php';
$admin=pf_require_login($pdo);
if(strtoupper((string)$admin['role'])!=='ADMIN'){http_response_code(403);exit('Administrator access required.');}
$error='';$notice='';$syncBlogAfterCommit=false;
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
     $fundingUpdate=$pdo->prepare("UPDATE pf_funding_requests SET status='APPROVED',ledger_entry_id=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='PENDING'");
     $fundingUpdate->execute([$lid,$admin['id'],$note?:null,$id]);
     if($fundingUpdate->rowCount()!==1)throw new RuntimeException('Funding request changed during approval. No credit was applied.');
    }else{$pdo->prepare("UPDATE pf_funding_requests SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='PENDING'")->execute([$admin['id'],$note?:null,$id]);}
   }elseif($action==='withdrawal'){
    $q=$pdo->prepare("SELECT * FROM pf_withdrawals WHERE id=? FOR UPDATE");$q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['status']!=='PENDING'||!in_array($decision,['paid','reject'],true))throw new RuntimeException('Withdrawal is no longer pending.');
    if($decision==='reject'){
     if(empty($r['hold_ledger_entry_id']))throw new RuntimeException('This withdrawal has no recorded wallet hold. Manual reconciliation is required.');
     $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'REFUND',?,?,'WITHDRAWAL',?,?,?,?)")->execute([(int)$r['wallet_id'],(int)$r['user_id'],$r['amount'],$r['currency'],$id,'withdrawal-release-'.$id,'Released rejected withdrawal hold',$admin['id']]);
     $withdrawalUpdate=$pdo->prepare("UPDATE pf_withdrawals SET status='REJECTED',reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=? AND status='PENDING'");
     $withdrawalUpdate->execute([$admin['id'],$note?:null,$id]);
     if($withdrawalUpdate->rowCount()!==1)throw new RuntimeException('Withdrawal changed during rejection. The transaction was rolled back.');
    }else{
     $payoutReference=trim((string)($_POST['payout_reference']??''));
     if($payoutReference===''){throw new RuntimeException('Enter the real payment transaction reference before marking a withdrawal as paid.');}
     if(empty($r['hold_ledger_entry_id']))throw new RuntimeException('This withdrawal has no recorded wallet hold. Manual reconciliation is required.');
     $withdrawalUpdate=$pdo->prepare("UPDATE pf_withdrawals SET status='PAID',reviewed_by=?,reviewed_at=NOW(),payout_reference=?,admin_note=? WHERE id=? AND status='PENDING'");
     $withdrawalUpdate->execute([$admin['id'],$payoutReference,$note?:null,$id]);
     if($withdrawalUpdate->rowCount()!==1)throw new RuntimeException('Withdrawal changed during settlement. The transaction was rolled back.');
     // The original hold already debited the wallet. Record settlement with a
     // zero-value immutable ledger event so available balance is not debited twice.
     $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'WITHDRAWAL_PAID',0,?,'WITHDRAWAL',?,?,?,?)")
       ->execute([(int)$r['wallet_id'],(int)$r['user_id'],$r['currency'],$id,'withdrawal-paid-'.$id,'Withdrawal paid; original hold remains the debit',$admin['id']]);
    }
   }elseif($action==='campaign'){
    $q=$pdo->prepare("SELECT * FROM pf_campaigns WHERE id=? FOR UPDATE");$q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['status']!=='PENDING_REVIEW'||!in_array($decision,['approve','reject'],true))throw new RuntimeException('Campaign is no longer awaiting review.');
    if($r['campaign_type']==='BLOG_SPONSORED'){
     $blog=$pdo->prepare("SELECT * FROM pf_blog_campaigns WHERE campaign_id=? FOR UPDATE");$blog->execute([$id]);$blogRow=$blog->fetch();
     if(!$blogRow)throw new RuntimeException('Blog sponsorship creative is missing.');
     if($decision==='approve'){
      $reservation=$pdo->prepare("SELECT id,amount FROM pf_blog_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
      $reservation->execute([$id]);$reserved=$reservation->fetch();
      if(!$reserved||(float)$reserved['amount']<=0||abs((float)$reserved['amount']-(float)$r['reserved_budget'])>0.01)throw new RuntimeException('Blog sponsorship budget reservation does not match. It cannot be activated.');
      $pdo->prepare("UPDATE pf_campaigns SET status='ACTIVE',reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=?")->execute([$admin['id'],$note?:null,$id]);
      $pdo->prepare("UPDATE pf_blog_campaigns SET status='ACTIVE',sync_status='PENDING',last_sync_error=NULL WHERE campaign_id=?")->execute([$id]);
      $syncBlogAfterCommit=true;
     }else{
      $reservation=$pdo->prepare("SELECT id,amount,advertiser_id,currency FROM pf_blog_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
      $reservation->execute([$id]);$reserved=$reservation->fetch();
      if(!$reserved||(float)$reserved['amount']<=0||abs((float)$reserved['amount']-(float)$r['reserved_budget'])>0.01)throw new RuntimeException('Blog sponsorship reservation does not match. Rejection was rolled back for reconciliation.');
      $pdo->prepare("UPDATE pf_campaigns SET status='REJECTED',reserved_budget=0,reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=?")->execute([$admin['id'],$note?:null,$id]);
      $wallet=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");$wallet->execute([(int)$reserved['advertiser_id'],$reserved['currency']]);$wid=(int)$wallet->fetchColumn();
      if(!$wid)throw new RuntimeException('Advertiser wallet missing; blog budget cannot be released.');
      $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'CAMPAIGN_RELEASE',?,?,'BLOG_CAMPAIGN',?,?,?,?)")->execute([$wid,(int)$reserved['advertiser_id'],$reserved['amount'],$reserved['currency'],$id,'blog-campaign-release-'.$id,'Released rejected blog sponsorship budget',$admin['id']]);
      $pdo->prepare("UPDATE pf_blog_campaign_reservations SET status='RELEASED' WHERE id=? AND status='RESERVED'")->execute([(int)$reserved['id']]);
      $pdo->prepare("UPDATE pf_blog_campaigns SET status='REVOKED',sync_status='PENDING' WHERE campaign_id=?")->execute([$id]);
     }
    }else{
     if($decision==='approve'){
      $reservation=$pdo->prepare("SELECT id,amount FROM pf_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
      $reservation->execute([$id]);$reserved=$reservation->fetch();
      if(!$reserved||(float)$reserved['amount']<=0||(float)$r['reserved_budget']+0.00001<(float)$reserved['amount'])throw new RuntimeException('This campaign has no valid reserved budget. It cannot be activated.');
      $pdo->prepare("UPDATE pf_campaigns SET status='ACTIVE',reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=?")->execute([$admin['id'],$note?:null,$id]);
     }else{
      $pdo->prepare("UPDATE pf_campaigns SET status='REJECTED',reserved_budget=0,reviewed_by=?,reviewed_at=NOW(),admin_note=? WHERE id=?")->execute([$admin['id'],$note?:null,$id]);
      $res=$pdo->prepare("SELECT id,amount,advertiser_id,currency FROM pf_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");$res->execute([$id]);$reserved=$res->fetch();
      if(!$reserved||(float)$reserved['amount']<=0||abs((float)$reserved['amount']-(float)$r['reserved_budget'])>0.01)throw new RuntimeException('The campaign reservation does not match its remaining budget. Rejection was rolled back for manual reconciliation.');
      $wallet=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");$wallet->execute([(int)$reserved['advertiser_id'],$reserved['currency']]);$wid=(int)$wallet->fetchColumn();
      if(!$wid)throw new RuntimeException('Advertiser wallet missing; budget cannot be safely released.');
      $pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description,created_by) VALUES (?,?,'CAMPAIGN_RELEASE',?,?,'CAMPAIGN',?,?,?,?)")->execute([$wid,(int)$reserved['advertiser_id'],$reserved['amount'],$reserved['currency'],$id,'campaign-release-'.$id,'Released budget for rejected campaign',$admin['id']]);
      $pdo->prepare("UPDATE pf_campaign_reservations SET status='RELEASED' WHERE id=? AND status='RESERVED'")->execute([(int)$reserved['id']]);
     }
    }
   }elseif($action==='blog_sync_retry'){
    $q=$pdo->prepare("SELECT c.id,c.status,bc.status AS blog_status,bc.sync_status FROM pf_campaigns c JOIN pf_blog_campaigns bc ON bc.campaign_id=c.id WHERE c.id=? AND c.campaign_type='BLOG_SPONSORED' FOR UPDATE");
    $q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['status']!=='PAUSED'||$r['sync_status']!=='FAILED')throw new RuntimeException('This blog campaign is not awaiting a sync retry.');
    $reservation=$pdo->prepare("SELECT amount FROM pf_blog_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
    $reservation->execute([$id]);$remaining=$reservation->fetchColumn();
    if($remaining===false||(float)$remaining<=0)throw new RuntimeException('This blog campaign has no remaining reserved budget.');
    $pdo->prepare("UPDATE pf_campaigns SET status='ACTIVE' WHERE id=? AND status='PAUSED'")->execute([$id]);
    $pdo->prepare("UPDATE pf_blog_campaigns SET status='ACTIVE',sync_status='PENDING',last_sync_error=NULL WHERE campaign_id=?")->execute([$id]);
    $syncBlogAfterCommit=true;   }else{throw new RuntimeException('Unknown admin action.');}
   $remoteIp=(string)($_SERVER['REMOTE_ADDR']??'');
   $ipBinary=filter_var($remoteIp,FILTER_VALIDATE_IP)?inet_pton($remoteIp):null;
   $audit=$pdo->prepare('INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,after_json,ip_address) VALUES (?,?,?,?,?,?)');
   $audit->execute([(int)$admin['id'],'ADMIN_'.strtoupper($action),strtoupper($action),$id,json_encode(['decision'=>$decision,'note'=>$note],JSON_THROW_ON_ERROR),$ipBinary]);
   $pdo->commit();
   $notice='The requested finance or campaign action has been recorded.';
   if($syncBlogAfterCommit){
    $syncResult=pf_sync_blog_campaign($pdo,(int)$id,'upsert');
    if($syncResult['ok']){
     $pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='SYNCED',last_sync_error=NULL WHERE campaign_id=?")->execute([$id]);
     $notice='Blog sponsorship approved and creative successfully synced to blog.poketflow.com.';
    }else{
     $pdo->beginTransaction();
     $pdo->prepare("UPDATE pf_campaigns SET status='PAUSED' WHERE id=? AND campaign_type='BLOG_SPONSORED'")->execute([$id]);
     $pdo->prepare("UPDATE pf_blog_campaigns SET status='PAUSED',sync_status='FAILED',last_sync_error=? WHERE campaign_id=?")->execute([$syncResult['error'],$id]);
     $pdo->prepare("INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,after_json) VALUES (?,?,?,?,?)")
       ->execute([(int)$admin['id'],'ADMIN_BLOG_SYNC_FAILED','BLOG_CAMPAIGN',$id,json_encode(['error'=>$syncResult['error']],JSON_THROW_ON_ERROR)]);
     $pdo->commit();
     // Best-effort compensation in case the blog accepted the creative but
     // the response was lost or timed out.
     pf_sync_blog_campaign($pdo,(int)$id,'revoke');
     $error='Campaign approval was saved, but blog publication failed. The campaign is paused and can be retried from this screen.';
    }
   }
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'The action failed and was rolled back.';}
 }
}
$fund=$pdo->query("SELECT f.*,u.name,u.email FROM pf_funding_requests f JOIN users u ON u.id=f.advertiser_id WHERE f.status='PENDING' ORDER BY f.id ASC LIMIT 100")->fetchAll();
$withdraw=$pdo->query("SELECT w.*,u.name,u.email FROM pf_withdrawals w JOIN users u ON u.id=w.user_id WHERE w.status='PENDING' ORDER BY w.id ASC LIMIT 100")->fetchAll();
$withdrawalDestinations=[];
if(!empty($config['encryption_key'])){
 $key=base64_decode((string)$config['encryption_key'],true);
 if($key!==false&&strlen($key)===32){
  foreach($withdraw as $wr){
   $packed=base64_decode((string)$wr['destination_encrypted'],true);
   if($packed!==false&&strlen($packed)>28){
    $iv=substr($packed,0,12);$tag=substr($packed,12,16);$cipher=substr($packed,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    $withdrawalDestinations[(int)$wr['id']]=$plain===false?'[Unable to decrypt payout details]':$plain;
   }else{$withdrawalDestinations[(int)$wr['id']]='[Invalid encrypted payout details]';}
  }
 }
}
$campaigns=$pdo->query("SELECT c.*,u.name AS advertiser_name,bc.placement_key,bc.starts_at AS blog_starts_at,bc.ends_at AS blog_ends_at FROM pf_campaigns c JOIN users u ON u.id=c.advertiser_id LEFT JOIN pf_blog_campaigns bc ON bc.campaign_id=c.id WHERE c.status='PENDING_REVIEW' ORDER BY c.id ASC LIMIT 100")->fetchAll();
$blogSyncFailed=$pdo->query("SELECT c.id,c.title,c.reserved_budget,c.currency,u.name AS advertiser_name,bc.placement_key,bc.last_sync_error FROM pf_campaigns c JOIN users u ON u.id=c.advertiser_id JOIN pf_blog_campaigns bc ON bc.campaign_id=c.id WHERE c.campaign_type='BLOG_SPONSORED' AND c.status='PAUSED' AND bc.sync_status='FAILED' ORDER BY c.id ASC LIMIT 100")->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finance administration | PoketFlow</title><link rel="stylesheet" href="../assets/marketplace.css"><style>.stack{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.stack input{padding:10px;border:1px solid var(--line);border-radius:10px}.review{padding:18px 0;border-top:1px solid var(--line)}.danger{background:#fff1f2;color:#9f1239}.section{margin-top:24px}</style></head><body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="index.php">Admin home</a><a href="users.php">Members</a><a href="../dashboard.php">Dashboard</a><a href="../logout.php">Sign out</a></nav></header><main class="container"><section class="welcome"><p class="eyebrow">ADMINISTRATION</p><h1>Finance & campaign review</h1><p class="muted">Verify payments independently before approving funding. Withdrawal requests already have funds held in the worker wallet.</p></section><?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<section class="panel section"><h2>Pending advertiser funding</h2><?php foreach($fund as $r):?><div class="review"><strong><?=pf_e($r['name'])?></strong> · <?=pf_e($r['email'])?><p><?=pf_e($r['currency'])?> <?=number_format((float)$r['amount'],2)?> · <?=pf_e($r['payment_method'])?> · Ref: <?=pf_e((string)$r['payer_reference'])?></p><?php if($r['proof_url']):?><p><a href="<?=pf_e($r['proof_url'])?>" target="_blank" rel="noopener">Open payment proof</a></p><?php endif;?><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="funding"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="note" placeholder="Admin note"><button class="button primary" name="decision" value="approve">Verify & credit wallet</button><button class="button danger" name="decision" value="reject">Reject</button></form></div><?php endforeach;?><?php if(!$fund):?><p class="muted">No pending funding requests.</p><?php endif;?></section>
<section class="panel section"><h2>Pending withdrawals</h2><?php foreach($withdraw as $r):?><div class="review"><strong><?=pf_e($r['name'])?></strong> · <?=pf_e($r['email'])?><p><?=pf_e($r['currency'])?> <?=number_format((float)$r['amount'],2)?> · <?=pf_e($r['method'])?> · Withdrawal #<?=(int)$r['id']?></p><p><strong>Encrypted destination (decrypted for authorized admin):</strong> <?=pf_e($withdrawalDestinations[(int)$r['id']]??'[Unavailable]')?></p><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="withdrawal"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="payout_reference" placeholder="Payment transaction reference"><input name="note" placeholder="Admin note"><button class="button primary" name="decision" value="paid">Mark paid</button><button class="button danger" name="decision" value="reject">Reject & release hold</button></form></div><?php endforeach;?><?php if(!$withdraw):?><p class="muted">No pending withdrawals.</p><?php endif;?></section>
<section class="panel section"><h2>Campaigns awaiting approval</h2><?php foreach($campaigns as $r):?><div class="review"><strong><?=pf_e($r['title'])?></strong> · <?=pf_e($r['advertiser_name'])?><?php if($r['campaign_type']==='BLOG_SPONSORED'):?><p>Blog sponsorship · Placement: <?=pf_e((string)$r['placement_key'])?> · Dates: <?=pf_e(substr((string)$r['blog_starts_at'],0,10))?> to <?=pf_e(substr((string)$r['blog_ends_at'],0,10))?></p><p>Fixed budget reserved: <?=pf_e($r['currency'])?> <?=number_format((float)$r['reserved_budget'],2)?></p><?php else:?><p>Worker task · Reserved budget: <?=pf_e($r['currency'])?> <?=number_format((float)$r['reserved_budget'],2)?> · <?= (int)$r['total_quantity']?> completions</p><?php endif;?><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="campaign"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input name="note" placeholder="Moderation note"><button class="button primary" name="decision" value="approve">Approve & activate</button><button class="button danger" name="decision" value="reject">Reject & release budget</button></form></div><?php endforeach;?><?php if(!$campaigns):?><p class="muted">No campaigns awaiting review.</p><?php endif;?></section>
<section class="panel section"><h2>Blog campaigns needing sync retry</h2><?php foreach($blogSyncFailed as $r):?><div class="review"><strong><?=pf_e($r['title'])?></strong> · <?=pf_e($r['advertiser_name'])?><p><?=pf_e($r['placement_key'])?> · Reserved <?=pf_e($r['currency'])?> <?=number_format((float)$r['reserved_budget'],2)?></p><p class="small muted"><?=pf_e((string)$r['last_sync_error'])?></p><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="blog_sync_retry"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="button primary" type="submit">Retry blog sync</button></form></div><?php endforeach;?><?php if(!$blogSyncFailed):?><p class="muted">No failed blog syncs.</p><?php endif;?></section>
</main></body></html>
