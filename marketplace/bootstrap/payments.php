<?php
declare(strict_types=1);

function pf_payment_http(string $method,string $url,array $headers=[],?array $payload=null): array
{
    if(!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'cURL is unavailable.'];
    $ch=curl_init($url);
    if($ch===false) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Could not initialize cURL.'];
    $options=[
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,
    ];
    if($payload!==null){
        $options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $options[CURLOPT_HTTPHEADER]=array_merge($headers,['Content-Type: application/json']);
    }
    curl_setopt_array($ch,$options);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    $data=is_string($body)?json_decode($body,true):null;
    return ['ok'=>$status>=200&&$status<300&&is_array($data),'status'=>$status,'data'=>$data,'error'=>$error!==''?$error:'HTTP '.$status];
}
function pf_payment_provider_ready(string $provider): bool
{
    global $config;
    if($provider==='PAYSTACK') return !empty($config['paystack_secret_key'])&&str_starts_with((string)$config['paystack_secret_key'],'sk_');
    if($provider==='PAYPAL') return !empty($config['paypal_client_id'])&&!empty($config['paypal_client_secret'])&&!empty($config['paypal_webhook_id'])&&in_array(($config['paypal_mode']??'sandbox'),['sandbox','live'],true);
    return false;
}
function pf_paypal_api_base(): string
{
    global $config;
    return ($config['paypal_mode']??'sandbox')==='live'?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com';
}
function pf_paypal_access_token(): string
{
    global $config;
    if(!function_exists('curl_init')) throw new RuntimeException('cURL is unavailable.');
    if(!pf_payment_provider_ready('PAYPAL')) throw new RuntimeException('PayPal is not configured.');
    $ch=curl_init(pf_paypal_api_base().'/v1/oauth2/token');
    if($ch===false) throw new RuntimeException('Could not initialize PayPal authentication.');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'grant_type=client_credentials',
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
        CURLOPT_USERPWD=>(string)$config['paypal_client_id'].':'.(string)$config['paypal_client_secret'],
        CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Language: en_US','Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $data=is_string($body)?json_decode($body,true):null;
    if($status!==200||!is_array($data)||empty($data['access_token'])) throw new RuntimeException('PayPal authentication failed. Check the private provider configuration.');
    return (string)$data['access_token'];
}
function pf_verify_paystack_order(array $order): array
{
    global $config;
    if(!pf_payment_provider_ready('PAYSTACK')) return ['ok'=>false,'transaction_id'=>'','error'=>'Paystack is not configured.'];
    $reference=(string)$order['provider_reference'];
    $result=pf_payment_http('GET','https://api.paystack.co/transaction/verify/'.rawurlencode($reference),[
        'Authorization: Bearer '.$config['paystack_secret_key'],'Accept: application/json'
    ]);
    $data=$result['data']['data']??null;
    if(!$result['ok']||!is_array($data)||($data['status']??'')!=='success'||($data['reference']??'')!==$reference)
        return ['ok'=>false,'transaction_id'=>'','error'=>'Paystack could not verify this payment.'];
    $expected=(int)round((float)$order['amount']*100);
    if((int)($data['amount']??0)!==$expected||strtoupper((string)($data['currency']??''))!==strtoupper((string)$order['currency']))
        return ['ok'=>false,'transaction_id'=>'','error'=>'Paystack payment amount or currency does not match the order.'];
    return ['ok'=>true,'transaction_id'=>(string)($data['id']??$reference),'error'=>''];
}
function pf_capture_paypal_order(array $order,string $paypalOrderId): array
{
    if(!pf_payment_provider_ready('PAYPAL')) return ['ok'=>false,'transaction_id'=>'','error'=>'PayPal is not configured.'];
    try{$token=pf_paypal_access_token();}catch(Throwable $e){return ['ok'=>false,'transaction_id'=>'','error'=>$e->getMessage()];}
    $result=pf_payment_http('POST',pf_paypal_api_base().'/v2/checkout/orders/'.rawurlencode($paypalOrderId).'/capture',[
        'Authorization: Bearer '.$token,'Accept: application/json','PayPal-Request-Id: poketflow-capture-'.$order['id']
    ]);
    $data=$result['data'];$unit=$data['purchase_units'][0]??[];$capture=$unit['payments']['captures'][0]??[];$amount=$capture['amount']??[];
    if(!$result['ok']||($data['status']??'')!=='COMPLETED'||(string)($unit['invoice_id']??'')!==(string)$order['provider_reference']||(string)($unit['custom_id']??'')!==(string)$order['advertiser_id']||strtoupper((string)($amount['currency_code']??''))!==strtoupper((string)$order['currency'])||abs((float)($amount['value']??0)-(float)$order['amount'])>0.001)
        return ['ok'=>false,'transaction_id'=>'','error'=>'PayPal capture could not be verified against this PoketFlow order.'];
    return ['ok'=>true,'transaction_id'=>(string)($capture['id']??$paypalOrderId),'error'=>''];
}
function pf_credit_payment_order(PDO $pdo,int $orderId,string $transactionId): bool
{
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM pf_payment_orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$order=$q->fetch();
        if(!$order) throw new RuntimeException('Payment order not found.');
        if($order['status']==='PAID'){$pdo->commit();return true;}
        if($order['status']!=='PENDING') throw new RuntimeException('Payment order is not awaiting confirmation.');
        $pdo->prepare("INSERT IGNORE INTO pf_wallets(user_id,wallet_type,currency) VALUES (?,'ADVERTISER',?)")->execute([(int)$order['advertiser_id'],$order['currency']]);
        $w=$pdo->prepare("SELECT id FROM pf_wallets WHERE user_id=? AND wallet_type='ADVERTISER' AND currency=? FOR UPDATE");
        $w->execute([(int)$order['advertiser_id'],$order['currency']]);$walletId=(int)$w->fetchColumn();
        $ledger=$pdo->prepare("INSERT INTO pf_wallet_ledger(wallet_id,user_id,entry_type,amount,currency,reference_type,reference_id,idempotency_key,description) VALUES (?,?,'DEPOSIT',?,?,'PAYMENT_ORDER',?,?,?)");
        $ledger->execute([$walletId,(int)$order['advertiser_id'],$order['amount'],$order['currency'],$orderId,'payment-'.$order['provider'].'-'.$order['provider_reference'],'Verified '.$order['provider'].' advertiser funding']);
        $pdo->prepare("UPDATE pf_payment_orders SET status='PAID',provider_transaction_id=?,verified_at=NOW() WHERE id=? AND status='PENDING'")->execute([$transactionId,$orderId]);
        $audit=$pdo->prepare("INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,after_json) VALUES (NULL,'PAYMENT_VERIFIED','PAYMENT_ORDER',?,?)");
        $audit->execute([$orderId,json_encode(['provider'=>$order['provider'],'amount'=>$order['amount'],'currency'=>$order['currency'],'provider_transaction_id'=>$transactionId],JSON_THROW_ON_ERROR)]);
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
