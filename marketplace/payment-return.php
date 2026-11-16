<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap/app.php';
$user=pf_require_login($pdo);$provider=strtoupper((string)($_GET['provider']??''));$error='';$success=false;$order=null;
try{
 if($provider==='PAYSTACK'){
  $reference=trim((string)($_GET['reference']??''));if($reference==='')throw new RuntimeException('The Paystack payment reference is missing.');
  $q=$pdo->prepare("SELECT * FROM pf_payment_orders WHERE provider='PAYSTACK' AND provider_reference=? AND advertiser_id=? LIMIT 1");$q->execute([$reference,(int)$user['id']]);$order=$q->fetch();
 }elseif($provider==='PAYPAL'){
  $reference=trim((string)($_GET['reference']??''));if($reference==='')throw new RuntimeException('The PayPal payment reference is missing.');
  $q=$pdo->prepare("SELECT * FROM pf_payment_orders WHERE provider='PAYPAL' AND provider_reference=? AND advertiser_id=? LIMIT 1");$q->execute([$reference,(int)$user['id']]);$order=$q->fetch();
  if(isset($_GET['cancelled'])&&(!$order||$order['status']!=='PAID')){if($order&&$order['status']==='PENDING')$pdo->prepare("UPDATE pf_payment_orders SET status='CANCELLED' WHERE id=? AND status='PENDING'")->execute([(int)$order['id']]);throw new RuntimeException('The PayPal checkout was cancelled. No funds were added.');}
 }else throw new RuntimeException('Unsupported payment provider.');
 if(!$order)throw new RuntimeException('The payment order could not be found for this account.');
 if($order['status']==='PAID'){$success=true;}
 elseif($order['status']!=='PENDING')throw new RuntimeException('This payment order is not awaiting confirmation.');
 else{
  $verified=$provider==='PAYSTACK'?pf_verify_paystack_order($order):pf_capture_paypal_order($order,(string)$order['provider_order_id']);
  if(!$verified['ok'])throw new RuntimeException($verified['error']);
  pf_credit_payment_order($pdo,(int)$order['id'],$verified['transaction_id']);$success=true;
 }
}catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Payment verification failed. No wallet credit was made.';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment confirmation | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"></head><body class="auth-shell"><main class="auth-card"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><?php if($success):?><p class="eyebrow">PAYMENT VERIFIED</p><h1>Wallet funded</h1><p class="muted">Your payment was verified directly with <?=pf_e($provider)?> and your advertiser wallet has been credited.</p><?php else:?><p class="eyebrow">PAYMENT NOT CONFIRMED</p><h1>We could not confirm this payment</h1><div class="alert"><?=pf_e($error)?></div><p class="muted">Do not pay again if your provider shows a completed transaction. Contact support with your payment reference so it can be checked.</p><?php endif;?><p><a class="button primary" href="wallet.php">View advertiser wallet</a></p><p class="muted small"><a href="funding.php">Back to funding</a></p></main></body></html>
