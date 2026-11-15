<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$secret=(string)($config['paystack_secret_key']??'');$signature=(string)($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']??'');$raw=file_get_contents('php://input');
if($secret===''||$signature===''||!is_string($raw)||!hash_equals(hash_hmac('sha512',$raw,$secret),$signature)){http_response_code(401);exit;}
$event=json_decode($raw,true);if(!is_array($event)||($event['event']??'')!=='charge.success'){http_response_code(200);exit('ignored');}
$reference=(string)($event['data']['reference']??'');if($reference===''){http_response_code(400);exit;}
$q=$pdo->prepare("SELECT * FROM pf_payment_orders WHERE provider='PAYSTACK' AND provider_reference=? LIMIT 1");$q->execute([$reference]);$order=$q->fetch();
if(!$order){http_response_code(200);exit('unknown reference');}
if($order['status']==='PAID'){http_response_code(200);exit('already processed');}
try{$verified=pf_verify_paystack_order($order);if(!$verified['ok'])throw new RuntimeException('Provider verification failed.');pf_credit_payment_order($pdo,(int)$order['id'],$verified['transaction_id']);http_response_code(200);exit('ok');}
catch(Throwable $e){error_log('PoketFlow Paystack webhook verification failed: '.$e->getMessage());http_response_code(500);exit('verification failed');}
