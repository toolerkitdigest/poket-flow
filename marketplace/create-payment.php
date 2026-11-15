<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap/app.php';
$user=pf_require_advertiser($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed.');}
pf_verify_csrf();$provider=strtoupper((string)($_POST['provider']??''));$amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_FLOAT);
if(!in_array($provider,['PAYSTACK','PAYPAL'],true)||$amount===false||$amount<=0||$amount>1000000){http_response_code(422);exit('Enter a valid payment provider and amount.');}
if(!pf_payment_provider_ready($provider)){http_response_code(503);exit('This payment provider is not configured yet. Please contact PoketFlow support.');}
$currency=$provider==='PAYSTACK'?'NGN':'USD';
if($provider==='PAYSTACK'&&$amount<100){http_response_code(422);exit('The minimum Paystack funding amount is NGN 100.');}
$amount=round((float)$amount,2);$reference='PF'.strtoupper(bin2hex(random_bytes(12)));
$q=$pdo->prepare("INSERT INTO pf_payment_orders(advertiser_id,provider,provider_reference,amount,currency,status) VALUES (?,?,?,?,?,'INITIALIZED')");$q->execute([(int)$user['id'],$provider,$reference,$amount,$currency]);$orderId=(int)$pdo->lastInsertId();
try{
 if($provider==='PAYSTACK'){
  $result=pf_payment_http('POST','https://api.paystack.co/transaction/initialize',['Authorization: Bearer '.$config['paystack_secret_key'],'Accept: application/json'],['email'=>$user['email'],'amount'=>(int)round($amount*100),'currency'=>'NGN','reference'=>$reference,'callback_url'=>'https://poketflow.com/payment-return.php?provider=PAYSTACK','metadata'=>['payment_order_id'=>$orderId,'advertiser_id'=>(int)$user['id']]]);
  $data=$result['data']['data']??null;
  if(!$result['ok']||empty($result['data']['status'])||!is_array($data)||($data['reference']??'')!==$reference||empty($data['authorization_url']))throw new RuntimeException('Paystack could not initialize checkout.');
  $checkout=(string)$data['authorization_url'];$pdo->prepare("UPDATE pf_payment_orders SET checkout_url=?,status='PENDING' WHERE id=?")->execute([$checkout,$orderId]);
 }else{
  $token=pf_paypal_access_token();$result=pf_payment_http('POST',pf_paypal_api_base().'/v2/checkout/orders',['Authorization: Bearer '.$token,'Accept: application/json','PayPal-Request-Id'=>'poketflow-order-'.$reference],['intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>(string)$orderId,'invoice_id'=>$reference,'custom_id'=>(string)$user['id'],'description'=>'PoketFlow advertiser wallet funding','amount'=>['currency_code'=>'USD','value'=>number_format($amount,2,'.','')]]],'application_context'=>['brand_name'=>'PoketFlow','user_action'=>'PAY_NOW','return_url'=>'https://poketflow.com/payment-return.php?provider=PAYPAL&reference='.rawurlencode($reference),'cancel_url'=>'https://poketflow.com/payment-return.php?provider=PAYPAL&cancelled=1&reference='.rawurlencode($reference)]]);
  $data=$result['data'];$checkout='';foreach(($data['links']??[]) as $link)if(($link['rel']??'')==='approve'){$checkout=(string)$link['href'];break;}
  if(!$result['ok']||($data['status']??'')!=='CREATED'||empty($data['id'])||$checkout==='')throw new RuntimeException('PayPal could not initialize checkout.');
  $pdo->prepare("UPDATE pf_payment_orders SET provider_transaction_id=?,checkout_url=?,status='PENDING' WHERE id=?")->execute([(string)$data['id'],$checkout,$orderId]);
 }
 header('Location: '.$checkout,true,303);exit;
}catch(Throwable $e){$pdo->prepare("UPDATE pf_payment_orders SET status='FAILED' WHERE id=? AND status='INITIALIZED'")->execute([$orderId]);error_log('PoketFlow payment initialization failed: '.$e->getMessage());http_response_code(502);exit('Payment checkout could not be started. No wallet funds have been credited. Please try again or contact support.');}
