<?php
declare(strict_types=1);

/**
 * Signed outbound sync to the separate PoketFlow Blog database/API.
 * The blog receives only the creative and dates, never main DB credentials.
 *
 * @return array{ok:bool,error:string}
 */
function pf_sync_blog_campaign(PDO $pdo, int $campaignId, string $action = 'upsert'): array
{
    global $config;
    if (!in_array($action, ['upsert','revoke'], true)) {
        return ['ok'=>false,'error'=>'Unsupported sync action.'];
    }
    $endpoint = 'https://blog.poketflow.com/api/poketflow-sync.php';
    $secret = (string) ($config['blog_integration_secret'] ?? '');
    if (strlen($secret) < 32 || !function_exists('curl_init')) {
        return ['ok'=>false,'error'=>'Blog sync is not configured or cURL is unavailable.'];
    }

    $payload = ['action'=>$action,'campaign_id'=>$campaignId];
    if ($action === 'upsert') {
        $stmt = $pdo->prepare(
            'SELECT c.title,c.status AS campaign_status,bc.placement_key,bc.creative_json,
                    bc.starts_at,bc.ends_at,bc.status AS blog_status
             FROM pf_campaigns c JOIN pf_blog_campaigns bc ON bc.campaign_id=c.id
             WHERE c.id=? AND c.campaign_type=\'BLOG_SPONSORED\' LIMIT 1'
        );
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok'=>false,'error'=>'Blog sponsorship record was not found.'];
        }
        try {
            $creative = json_decode((string)$row['creative_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return ['ok'=>false,'error'=>'Stored blog creative is invalid.'];
        }
        $url = (string)($creative['url'] ?? '');
        $parts = parse_url($url);
        if (!filter_var($url,FILTER_VALIDATE_URL)
            || strtolower((string)($parts['scheme']??''))!=='https'
            || empty($parts['host'])) {
            return ['ok'=>false,'error'=>'Blog destination URL is not a valid HTTPS address.'];
        }
        $start = $row['starts_at'] ? date('Y-m-d',strtotime((string)$row['starts_at'])) : null;
        $end = $row['ends_at'] ? date('Y-m-d',strtotime((string)$row['ends_at'])) : null;
        $payload += [
            'name'=>(string)($creative['name'] ?? $row['title']),
            'placement'=>(string)$row['placement_key'],
            'image'=>'',
            'url'=>$url,
            'status'=>$row['blog_status']==='ACTIVE' ? 'active' : ($row['blog_status']==='ENDED' ? 'ended' : 'paused'),
            'start_date'=>$start,
            'end_date'=>$end,
        ];
    }

    try {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(24));
        $signature = hash_hmac('sha256',$timestamp."\n".$nonce."\n".$body,$secret);
        $curl = curl_init($endpoint);
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Accept: application/json',
                'X-PF-Timestamp: '.$timestamp,
                'X-PF-Nonce: '.$nonce,
                'X-PF-Signature: '.$signature,
            ],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>7,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);
        $response = curl_exec($curl);
        $http = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $decoded = is_string($response) ? json_decode($response,true) : null;
        if ($http===200 && is_array($decoded) && !empty($decoded['ok'])) {
            return ['ok'=>true,'error'=>''];
        }
        return ['ok'=>false,'error'=>substr($error!==''?$error:'Blog API returned HTTP '.$http,0,255)];
    } catch (Throwable $exception) {
        error_log('PoketFlow blog creative sync failed: '.$exception->getMessage());
        return ['ok'=>false,'error'=>'Blog sync request failed.'];
    }
}
