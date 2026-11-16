<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$sig=(string)($_GET['sig']??'');
if (!$id || !preg_match('/\A[a-f0-9]{64}\z/',$sig) || !hash_equals(pf_site_ad_signature((int)$id),$sig)) {
    http_response_code(404); exit('Advertisement not found.');
}
$stmt=$pdo->prepare(
    "SELECT c.id,s.placement_key,s.creative_json
     FROM pf_campaigns c JOIN pf_site_campaigns s ON s.campaign_id=c.id
     JOIN users u ON u.id=c.advertiser_id
     JOIN pf_advertiser_profiles ap ON ap.user_id=c.advertiser_id
     WHERE c.id=? AND c.campaign_type='SITE_DISPLAY' AND c.status='ACTIVE' AND s.status='ACTIVE'
       AND u.status='ACTIVE' AND ap.status='ACTIVE'
       AND s.starts_at<=NOW() AND s.ends_at>=NOW() LIMIT 1"
);
$stmt->execute([$id]); $ad=$stmt->fetch();
if (!$ad) { http_response_code(404); exit('Advertisement is no longer available.'); }
$creative=json_decode((string)$ad['creative_json'],true);
$url=(string)($creative['url']??''); $parts=parse_url($url);
if (!filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host']) || !filter_var($parts['host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || isset($parts['user']) || isset($parts['pass'])) {
    http_response_code(410); exit('This advertisement destination is unavailable.');
}
try {
    $pdo->prepare("INSERT INTO pf_site_ad_events(campaign_id,event_type,placement_key,event_key,occurred_at) VALUES (?,'CLICK',?,?,NOW())")
        ->execute([(int)$id,$ad['placement_key'],bin2hex(random_bytes(24))]);
} catch (Throwable $exception) {
    // A tracking outage must not prevent a visitor from following the ad.
    error_log('PoketFlow site advertisement click could not be recorded: '.$exception->getMessage());
}
header('Location: '.$url, true, 302);
exit;
