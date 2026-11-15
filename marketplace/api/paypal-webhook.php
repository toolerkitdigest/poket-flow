<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$webhookId=(string)($config['paypal_webhook_id']??'');if($webhookId===''||!pf_payment_provider_ready('PAYPAL')){http_response_code(503);exit;}
$raw=file_get_contents('php://input');$event=is_string($raw)?json_decode($raw,true):null;if(!is_array($event)){http_response_code(400);exit;}
$headers=[
 'auth_algo'=>(string)($_SERVER['HTTP_PAYPAL_AUTH_ALGO']??''),
 'cert_url'=>(string)($_SERVER['HTTP_PAYPAL_CERT_URL']??''),
 'transmission_id'=>(string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']??''),
 'transmission_sig'=>(string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']??''),
 'transmission_time'=>(string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME']??''),
 'webhook_id'=>$webhookId,
 'webhook_event'=>$event,
];
foreach(['auth_algo','cert_url','transmission_id','transmission_sig','transmission_time'] as $key)if($headers[$key]===''){http_response_code(401);exit;}
try{$token=pf_paypal_access_token();$verify=pf_payment_http('POST',pf_paypal_api_base().'/v1/notifications/verify-webhook-signature',['Authorization: Bearer '.$token,'Accept: application/json'],$headers);
 if(!$verify['ok']||($verify['data']['verification_status']??'')!=='SUCCESS'){http_response_code(401);exit;}
 if(($event['event_type']??'')!=='PAYMENT.CAPTURE.COMPLETED'){http_response_code(200);exit('ignored');}
 $resource=$event['resource']??[];$invoice=(string)($resource['invoice_id']??'');$custom=(string)($resource['custom_id']??'');
 if($invoice===''||$custom===''||($resource['status']??'')!=='COMPLETED'){http_response_code(400);exit;}
 $q=$pdo->prepare("SELECT * FROM pf_payment_orders WHERE provider='PAYPAL' AND provider_reference=? AND advertiser_id=? LIMIT 1");$q->execute([$invoice,(int)$custom]);$order=$q->fetch();
 if(!$order){http_response_code(200);exit('unknown order');}
 $amount=$resource['amount']??[];if(strtoupper((string)($amount['currency_code']??''))!==strtoupper((string)$order['currency'])||abs((float)($amount['value']??0)-(float)$order['amount'])>0.001){http_response_code(400);exit('amount mismatch');}
 pf_credit_payment_order($pdo,(int)$order['id'],(string)($resource['id']??'paypal-capture'));http_response_code(200);exit('ok');
}catch(Throwable $e){error_log('PoketFlow PayPal webhook failed: '.$e->getMessage());http_response_code(500);exit('verification failed');}
