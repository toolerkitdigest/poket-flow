<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user=pf_require_worker($pdo);
$error='';$notice='';
$minWithdrawal=10.00;
if($_SERVER['REQUEST_METHOD']==='POST'){
 pf_verify_csrf();
 $amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_FLOAT);
 $method=(string)($_POST['method']??'');
 $methodNetwork=strtoupper(trim((string)($_POST['method_network']??'')));
 $destination=trim((string)($_POST['destination']??''));
 $methods=['USDT','USDC','PAYPAL','MONIEPOINT','PALMPAY','OPAY'];
 $networkRequired=in_array($method,['USDT','USDC'],true);
 if($amount===false||$amount<$minWithdrawal||$amount>1000000||!in_array($method,$methods,true)||$destination===''||($networkRequired&&($methodNetwork===''||strlen($methodNetwork)>50))||(!$networkRequired&&$methodNetwork!=='')){
  $error='Enter a valid withdrawal amount (minimum $10), payout method and destination.';
 } elseif(empty($config['encryption_key'])||!function_exists('openssl_encrypt')){
  $error='Secure payout encryption is not configured. Please contact support.';
 } else {
  $key=base64_decode((string)$config['encryption_key'],true);
  if($key===false||strlen($key)!==32){$error='Secure payout encryption is not configured correctly.';}
  else {
   $iv=random_bytes(12);$tag='';
   $cipher=openssl_encrypt($destination,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
   if($cipher===false){$error='Could not protect payout details. Please try again.';}
   else {
    $encrypted=base64_encode($iv.$tag.$cipher);
    $pdo->beginTransaction();
    try{
     $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'WORKER','USD')")->execute([(int)$user['id']]);
     $w=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='WORKER' AND currency='USD' FOR UPDATE");
     $w->execute([(int)$user['id']]);$walletId=(int)$w->fetchColumn();
     $b=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pf_wallet_ledger WHERE wallet_id=?');
     $b->execute([$walletId]);$balance=(float)$b->fetchColumn();
     if($balance+0.00001<$amount){throw new RuntimeException('Your available worker wallet balance is not enough for this withdrawal.');}
     $hold=$pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description) VALUES (?,?,'WITHDRAWAL_HOLD',?,'USD','WITHDRAWAL',NULL,?,'Worker withdrawal reserved')");
     $hold->execute([$walletId,(int)$user['id'],-$amount,'withdrawal-hold-user-'.$user['id'].'-'.bin2hex(random_bytes(12))]);
     $holdId=(int)$pdo->lastInsertId();
     $ins=$pdo->prepare("INSERT INTO pf_withdrawals(user_id,wallet_id,amount,currency,method,method_network,destination_encrypted,status,hold_ledger_entry_id) VALUES (?,?,?,'USD',?,?,?,'PENDING',?)");
     $ins->execute([(int)$user['id'],$walletId,$amount,$method,$methodNetwork!==''?$methodNetwork:null,$encrypted,$holdId]);
     $pdo->commit();$notice='Withdrawal request submitted. The amount is reserved and cannot be spent again while your request is pending.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'Could not create your withdrawal request.';}
   }
  }
 }
}
$list=$pdo->prepare('SELECT amount,currency,method,method_network,status,payout_reference,admin_note,created_at FROM pf_withdrawals WHERE user_id=? ORDER BY id DESC LIMIT 30');
$list->execute([(int)$user['id']]);$history=$list->fetchAll();
$bal=$pdo->prepare("SELECT COALESCE(SUM(l.amount),0) FROM pf_wallet_ledger l JOIN pf_wallets w ON w.id=l.wallet_id WHERE w.user_id=? AND w.wallet_type='WORKER' AND w.currency='USD'");
$bal->execute([(int)$user['id']]);$available=(float)$bal->fetchColumn();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Withdraw rewards | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"><style>.stack{display:grid;gap:10px}.stack input,.stack select{padding:13px;border:1px solid var(--line);border-radius:12px;font:inherit;width:100%}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:12px;border-bottom:1px solid var(--line);white-space:nowrap}</style></head><body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="tasks.php">Earn</a><a href="wallet.php">Wallets</a><a href="blog-campaigns.php">Blog ads</a><a href="logout.php">Sign out</a></nav></header><main class="container"><section class="welcome"><p class="eyebrow">WORKER WALLET</p><h1>Withdraw rewards</h1><p class="muted">Available balance: <strong>$<?=number_format($available,2)?></strong>. Minimum withdrawal: $10.00. Payout details are encrypted before storage.</p></section><?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?><section class="panel"><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><label>Amount (USD)</label><input type="number" name="amount" min="10" max="1000000" step="0.01" required><label>Payment method</label><select name="method" required><option value="">Choose method</option><option>USDT</option><option>USDC</option><option>PAYPAL</option><option>MONIEPOINT</option><option>PALMPAY</option><option>OPAY</option></select><label>Network (required for USDT or USDC; leave blank for other methods)</label><input name="method_network" maxlength="50" placeholder="e.g. TRC20, ERC20 or BEP20"><label>Wallet address / PayPal email / account details</label><input name="destination" maxlength="500" required autocomplete="off"><button class="button primary" type="submit">Request withdrawal</button></form></section><section class="panel" style="margin-top:22px"><h2>Withdrawal history</h2><div style="overflow:auto"><table><thead><tr><th>Amount</th><th>Method</th><th>Network</th><th>Status</th><th>Reference</th><th>Date</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?=pf_e($h['currency'])?> <?=number_format((float)$h['amount'],2)?></td><td><?=pf_e($h['method'])?></td><td><?=pf_e((string)$h['method_network'])?></td><td><?=pf_e($h['status'])?></td><td><?=pf_e((string)$h['payout_reference'])?></td><td><?=pf_e($h['created_at'])?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="6">No withdrawals yet.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
