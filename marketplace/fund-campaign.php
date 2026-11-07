<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user=pf_require_advertiser($pdo);
$error='';$notice='';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if($_SERVER['REQUEST_METHOD']==='POST'){
 pf_verify_csrf();$id=filter_input(INPUT_POST,'campaign_id',FILTER_VALIDATE_INT);
 if(!$id){$error='Invalid campaign.';}
 else{
  $pdo->beginTransaction();
  try{
   $c=$pdo->prepare("SELECT id,title,reward_per_completion,total_quantity,status,currency FROM pf_campaigns WHERE id=? AND advertiser_id=? FOR UPDATE");
   $c->execute([$id,(int)$user['id']]);$campaign=$c->fetch();
   if(!$campaign||$campaign['status']!=='DRAFT'){throw new RuntimeException('Only your draft campaigns can be funded.');}
   $amount=round((float)$campaign['reward_per_completion']*(int)$campaign['total_quantity'],2);
   if($amount<=0){throw new RuntimeException('Campaign reward budget is invalid.');}
   $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'ADVERTISER',?)")->execute([(int)$user['id'],$campaign['currency']]);
   $w=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");
   $w->execute([(int)$user['id'],$campaign['currency']]);$walletId=(int)$w->fetchColumn();
   $bal=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pf_wallet_ledger WHERE wallet_id=?');$bal->execute([$walletId]);$available=(float)$bal->fetchColumn();
   if($available+0.00001<$amount){throw new RuntimeException('Insufficient advertiser balance. Submit a funding request first.');}
   $ledger=$pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description) VALUES (?,?,'CAMPAIGN_RESERVE',?,?, 'CAMPAIGN',?,?,?)");
   $ledger->execute([$walletId,(int)$user['id'],-$amount,$campaign['currency'],$id,'campaign-reserve-'.$id,'Reserved campaign reward budget']);
   $ledgerId=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO pf_campaign_reservations(campaign_id,advertiser_id,amount,currency,status,ledger_entry_id) VALUES (?,?,?,?,'RESERVED',?)")->execute([$id,(int)$user['id'],$amount,$campaign['currency'],$ledgerId]);
   $pdo->prepare("UPDATE pf_campaigns SET reserved_budget=?,status='PENDING_REVIEW' WHERE id=? AND status='DRAFT'")->execute([$amount,$id]);
   $pdo->commit();$notice='The campaign budget is now reserved and the campaign has been submitted for administrator review.';
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'Could not reserve this campaign budget.';}
 }
}
if(!$id){http_response_code(400);exit('Campaign ID is required.');}
$stmt=$pdo->prepare('SELECT id,title,reward_per_completion,total_quantity,status,currency FROM pf_campaigns WHERE id=? AND advertiser_id=?');$stmt->execute([$id,(int)$user['id']]);$c=$stmt->fetch();
if(!$c){http_response_code(404);exit('Campaign not found.');}
$budget=round((float)$c['reward_per_completion']*(int)$c['total_quantity'],2);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fund campaign | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head><body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="campaigns.php">Campaigns</a><a href="wallet.php">Wallets</a></nav></header><main class="container"><section class="welcome"><p class="eyebrow">CAMPAIGN FUNDING</p><h1><?=pf_e($c['title'])?></h1><p class="muted">Total worker reward budget to reserve: <strong><?=pf_e($c['currency'])?> <?=number_format($budget,2)?></strong>. This amount is moved out of your available advertiser wallet balance and held for this campaign.</p></section><?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?><?php if($c['status']==='DRAFT'):?><section class="panel"><form method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>"><p>Reward per completion: <?=pf_e($c['currency'])?> <?=number_format((float)$c['reward_per_completion'],2)?></p><p>Number of completions: <?=(int)$c['total_quantity']?></p><button class="button primary" type="submit">Reserve budget & submit for review</button></form></section><?php else:?><section class="panel">Current campaign status: <strong><?=pf_e($c['status'])?></strong></section><?php endif;?></main></body></html>
