<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 pf_verify_csrf();
 $amount = filter_var($_POST['amount'] ?? null,FILTER_VALIDATE_FLOAT);
 $method = (string)($_POST['payment_method'] ?? '');
 $reference = trim((string)($_POST['payer_reference'] ?? ''));
 $proof = trim((string)($_POST['proof_url'] ?? ''));
 $methods = ['BANK_TRANSFER','PAYPAL','USDT','USDC'];
 if ($amount===false || $amount<1 || $amount>1000000 || !in_array($method,$methods,true)) {
  $error='Enter a valid amount and choose a supported funding method.';
 } elseif ($proof!=='' && !filter_var($proof,FILTER_VALIDATE_URL)) {
  $error='The proof link must be a valid URL.';
 } else {
  $stmt=$pdo->prepare("INSERT INTO pf_funding_requests (advertiser_id,amount,currency,payment_method,payer_reference,proof_url) VALUES (?,?,'USD',?,?,?)");
  $stmt->execute([(int)$user['id'],$amount,$method,$reference?:null,$proof?:null]);
  $notice='Your funding request has been submitted for administrator verification. Your advertiser wallet will only be credited after payment is independently confirmed.';
 }
}
$stmt=$pdo->prepare('SELECT amount,currency,payment_method,payer_reference,status,review_note,created_at FROM pf_funding_requests WHERE advertiser_id=? ORDER BY id DESC LIMIT 30');
$stmt->execute([(int)$user['id']]);
$requests=$stmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fund advertiser wallet | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"><style>.stack{display:grid;gap:10px}.stack input,.stack select{padding:13px;border:1px solid var(--line);border-radius:12px;font:inherit;width:100%}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:12px;border-bottom:1px solid var(--line);white-space:nowrap}</style></head><body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="campaigns.php">Campaigns</a><a href="wallet.php">Wallets</a><a href="blog-campaigns.php">Blog ads</a><a href="logout.php">Sign out</a></nav></header><main class="container"><section class="welcome"><p class="eyebrow">ADVERTISER WALLET</p><h1>Request wallet funding</h1><p class="muted">Submit your payment details for review. A funding request is not a deposit; funds are credited only after an administrator verifies receipt.</p></section><?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?><section class="panel"><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><label>Amount (USD)</label><input type="number" name="amount" min="1" max="1000000" step="0.01" required><label>Payment method</label><select name="payment_method" required><option value="">Choose method</option><option value="BANK_TRANSFER">Bank transfer</option><option value="PAYPAL">PayPal</option><option value="USDT">USDT</option><option value="USDC">USDC</option></select><label>Payment reference / transaction ID</label><input name="payer_reference" maxlength="160"><label>Proof URL (optional)</label><input type="url" name="proof_url" maxlength="2048" placeholder="Secure payment receipt link"><button class="button primary" type="submit">Submit funding request</button></form></section><section class="panel" style="margin-top:22px"><h2>Funding history</h2><div style="overflow:auto"><table><thead><tr><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Date</th></tr></thead><tbody><?php foreach($requests as $r):?><tr><td><?=pf_e($r['currency'])?> <?=number_format((float)$r['amount'],2)?></td><td><?=pf_e($r['payment_method'])?></td><td><?=pf_e((string)$r['payer_reference'])?></td><td><?=pf_e($r['status'])?></td><td><?=pf_e($r['created_at'])?></td></tr><?php endforeach;?><?php if(!$requests):?><tr><td colspan="5">No funding requests yet.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
