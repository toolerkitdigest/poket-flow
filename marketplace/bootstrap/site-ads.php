<?php
declare(strict_types=1);

/** Render one approved, scheduled main-site advertisement. */
function pf_render_site_ad(PDO $pdo, string $placementKey): string
{
    $stmt=$pdo->prepare(
        "SELECT c.id,c.title,s.placement_key,s.creative_json
         FROM pf_campaigns c
         JOIN pf_site_campaigns s ON s.campaign_id=c.id
         JOIN users u ON u.id=c.advertiser_id
         JOIN pf_advertiser_profiles ap ON ap.user_id=c.advertiser_id
         WHERE c.campaign_type='SITE_DISPLAY' AND c.status='ACTIVE' AND s.status='ACTIVE'
           AND u.status='ACTIVE' AND ap.status='ACTIVE'
           AND s.placement_key=? AND s.starts_at<=NOW() AND s.ends_at>=NOW()
         ORDER BY RAND() LIMIT 1"
    );
    $stmt->execute([$placementKey]);
    $ad=$stmt->fetch();
    if (!$ad) return '';
    try { $creative=json_decode((string)$ad['creative_json'],true,16,JSON_THROW_ON_ERROR); }
    catch (Throwable $exception) { return ''; }
    $sig=pf_site_ad_signature((int)$ad['id']);
    if ($sig === '') {
        error_log('PoketFlow site advertisement was not rendered because click signing is not configured.');
        return '';
    }

    try {
        $eventKey=bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO pf_site_ad_events(campaign_id,event_type,placement_key,event_key,occurred_at) VALUES (?,'IMPRESSION',?,?,NOW())")
            ->execute([(int)$ad['id'],$placementKey,$eventKey]);
    } catch (Throwable $exception) {
        // Analytics failure must never make the public page unavailable.
        error_log('PoketFlow site advertisement impression could not be recorded: '.$exception->getMessage());
    }

    $title=pf_e((string)($creative['title']??$ad['title']));
    $summary=pf_e((string)($creative['summary']??''));
    $image=trim((string)($creative['image']??''));
    $imageParts=$image!==''?parse_url($image):[];
    $imageHtml='';
    if (
        $image!=='' &&
        filter_var($image,FILTER_VALIDATE_URL) &&
        is_array($imageParts) &&
        strtolower((string)($imageParts['scheme']??''))==='https' &&
        !empty($imageParts['host']) &&
        filter_var($imageParts['host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) &&
        !isset($imageParts['user']) &&
        !isset($imageParts['pass'])
    ) {
        $imageHtml='<img loading="lazy" src="'.pf_e($image).'" alt="'.$title.'" style="max-width:100%;height:auto;border-radius:12px">';
    }
    return '<aside class="pf-sponsored-ad" aria-label="Advertisement"><span class="pf-ad-label">Sponsored</span><a href="ad-click.php?id='.(int)$ad['id'].'&sig='.rawurlencode($sig).'" rel="sponsored nofollow noopener" target="_blank">'.$imageHtml.'<strong>'.$title.'</strong><span>'.$summary.'</span></a></aside>';
}
function pf_site_ad_signature(int $campaignId): string
{
    global $config;
    $key=base64_decode((string)($config['encryption_key']??''),true);
    if ($key===false || strlen($key)!==32) return '';
    return hash_hmac('sha256','site-ad-click:'.$campaignId,$key);
}
