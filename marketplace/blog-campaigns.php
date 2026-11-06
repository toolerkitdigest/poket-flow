<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/app.php';
$user = pf_require_login($pdo);
$error = '';
$notice = '';
$placements = [
    'site_top'=>'Site-wide top',
    'home_inline'=>'Homepage inline',
    'article_top'=>'Article top',
    'article_middle'=>'Article middle',
    'article_bottom'=>'Article bottom',
    'article_sidebar'=>'Article sidebar',
    'opportunity_inline'=>'Opportunity page inline',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $action = (string)($_POST['action'] ?? 'create');
    if ($action === 'resume') {
        $campaignId = filter_var($_POST['campaign_id'] ?? null,FILTER_VALIDATE_INT);
        if (!$campaignId) {
            $error = 'Invalid blog sponsorship.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT c.status,c.reserved_budget,b.status AS blog_status,b.ends_at
                     FROM pf_campaigns c JOIN pf_blog_campaigns b ON b.campaign_id=c.id
                     WHERE c.id=? AND c.advertiser_id=? AND c.campaign_type='BLOG_SPONSORED'
                       AND (b.ends_at IS NULL OR b.ends_at>=NOW()) FOR UPDATE"
                );
                $stmt->execute([$campaignId,(int)$user['id']]);
                $campaign = $stmt->fetch();
                if (!$campaign || $campaign['status']!=='PAUSED' || $campaign['blog_status']!=='PAUSED') {
                    throw new RuntimeException('This blog sponsorship is not available to resume.');
                }
                $reservation = $pdo->prepare("SELECT amount FROM pf_blog_campaign_reservations WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");
                $reservation->execute([$campaignId]);
                $remaining = $reservation->fetchColumn();
                if ($remaining===false || (float)$remaining<=0
                    || (float)$campaign['reserved_budget']+0.00001<(float)$remaining) {
                    throw new RuntimeException('This sponsorship has no remaining reserved budget.');
                }
                $pdo->prepare("UPDATE pf_campaigns SET status='ACTIVE' WHERE id=? AND status='PAUSED'")->execute([$campaignId]);
                $pdo->prepare("UPDATE pf_blog_campaigns SET status='ACTIVE',sync_status='PENDING',last_sync_error=NULL WHERE campaign_id=?")->execute([$campaignId]);
                $pdo->commit();
                $sync = pf_sync_blog_campaign($pdo,$campaignId,'upsert');
                if ($sync['ok']) {
                    $pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='SYNCED',last_sync_error=NULL WHERE campaign_id=?")->execute([$campaignId]);
                    $notice = 'Your blog sponsorship has resumed and the creative is synced.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE pf_campaigns SET status='PAUSED' WHERE id=?")->execute([$campaignId]);
                    $pdo->prepare("UPDATE pf_blog_campaigns SET status='PAUSED',sync_status='FAILED',last_sync_error=? WHERE campaign_id=?")->execute([$sync['error'],$campaignId]);
                    $pdo->commit();
                    pf_sync_blog_campaign($pdo,$campaignId,'revoke');
                    $error = 'The sponsorship could not be synced to the blog. It remains paused; please contact support or try again later.';
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The blog sponsorship could not be resumed.';
            }
        }
    } else {
    $title = trim((string)($_POST['title'] ?? ''));
    $summary = trim((string)($_POST['summary'] ?? ''));
    $url = trim((string)($_POST['destination_url'] ?? ''));
    $placement = (string)($_POST['placement'] ?? '');
    $startDate = (string)($_POST['start_date'] ?? '');
    $endDate = (string)($_POST['end_date'] ?? '');
    $budget = filter_var($_POST['budget'] ?? null,FILTER_VALIDATE_FLOAT);
    $policyAccepted = isset($_POST['policy_agree']);
    $parts = parse_url($url);
    $validDate = static fn(string $date): bool =>
        preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$date) === 1 && date('Y-m-d',strtotime($date)) === $date;

    if ($title === '' || strlen($title)>180 || $summary === '' || strlen($summary)>500
        || !filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)($parts['scheme']??''))!=='https' || empty($parts['host'])
        || !isset($placements[$placement]) || !$validDate($startDate) || !$validDate($endDate)
        || $startDate < date('Y-m-d') || $endDate < $startDate
        || $budget === false || $budget <= 0 || $budget > 1000000 || !$policyAccepted) {
        $error = 'Check the title, summary, HTTPS destination, placement, dates and fixed campaign budget.';
    } else {
        $pdo->beginTransaction();
        try {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$title),'-')).'-'.bin2hex(random_bytes(4));
            $startsAt = $startDate.' 00:00:00';
            $endsAt = $endDate.' 23:59:59';
            $insert = $pdo->prepare(
                "INSERT INTO pf_campaigns
                 (advertiser_id,campaign_type,title,slug,summary,description,destination_url,reward_per_completion,
                  total_quantity,reserved_budget,spent_budget,currency,evidence_required,starts_at,ends_at,status)
                 VALUES (?,'BLOG_SPONSORED',?,?,?,?,?,0,0,0,0,'USD',0,?,?,'DRAFT')"
            );
            $insert->execute([(int)$user['id'],$title,$slug,$summary,$summary,$url,$startsAt,$endsAt]);
            $campaignId = (int)$pdo->lastInsertId();
            $creative = [
                'name'=>$title,
                'placement'=>$placement,
                'image'=>'',
                'url'=>$url,
                'status'=>'paused',
                'start_date'=>$startDate,
                'end_date'=>$endDate,
            ];
            $pdo->prepare(
                "INSERT INTO pf_blog_campaigns
                 (campaign_id,public_token_hash,placement_key,requested_budget,creative_json,starts_at,ends_at,status,sync_status)
                 VALUES (?,?,?,?,?,?,?,'PENDING','PENDING')"
            )->execute([
                $campaignId,
                hash('sha256',random_bytes(32)),
                $placement,
                $budget,
                json_encode($creative,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $startsAt,
                $endsAt,
            ]);
            $pdo->commit();
            $notice = 'Your blog sponsorship request is saved as a draft. Review the details and reserve its fixed budget to submit it for approval.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('PoketFlow blog campaign creation failed: '.$exception->getMessage());
            $error = 'The blog campaign could not be saved. Please try again.';
        }
    }
    }
}

$list = $pdo->prepare(
    'SELECT c.id,c.title,c.status,c.reserved_budget,c.spent_budget,c.currency,c.created_at,
            b.placement_key,b.requested_budget,b.sync_status,b.last_sync_error,b.starts_at,b.ends_at
     FROM pf_campaigns c JOIN pf_blog_campaigns b ON b.campaign_id=c.id
     WHERE c.advertiser_id=? ORDER BY c.created_at DESC'
);
$list->execute([(int)$user['id']]);
$campaigns = $list->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Blog advertising | PoketFlow</title><link rel="stylesheet" href="assets/marketplace.css"><style>.stack{display:grid;gap:10px}.stack input,.stack select,.stack textarea{padding:12px;border:1px solid var(--line);border-radius:12px;font:inherit;width:100%}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:12px;border-bottom:1px solid var(--line);white-space:nowrap}.table-wrap{overflow:auto}</style></head>
<body><header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="dashboard.php">Dashboard</a><a href="campaigns.php">Worker tasks</a><a href="wallet.php">Wallets</a><a href="funding.php">Add funds</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">ADVERTISE ON THE BLOG</p><h1>Reach blog readers</h1><p class="muted">Request a clearly labelled sponsored text placement on blog.poketflow.com. You propose one fixed total budget and date range; PoketFlow reviews the request before publication.</p></section>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<section class="panel"><h2>Create a blog sponsorship</h2><form class="stack" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>">
<label>Ad title / business name</label><input name="title" maxlength="180" required>
<label>Short ad message</label><textarea name="summary" maxlength="500" rows="3" required></textarea>
<label>HTTPS destination URL</label><input type="url" name="destination_url" placeholder="https://example.com" required>
<label>Blog placement</label><select name="placement" required><?php foreach($placements as $key=>$label):?><option value="<?=pf_e($key)?>"><?=pf_e($label)?></option><?php endforeach;?></select>
<label>Start date</label><input type="date" name="start_date" min="<?=date('Y-m-d')?>" required>
<label>End date</label><input type="date" name="end_date" min="<?=date('Y-m-d')?>" required>
<label>Proposed fixed total budget (USD)</label><input type="number" name="budget" min="0.01" max="1000000" step="0.01" required>
<p class="small muted">This first release supports text-only creatives and fixed-duration sponsorship requests. No impression/click billing is applied. Gambling, adult content, casinos and alcohol are prohibited. All creatives are reviewed before publication.</p>
<label><input type="checkbox" name="policy_agree" required> I confirm this advertisement follows PoketFlow's advertising policy.</label>
<button class="button primary" type="submit">Save sponsorship draft</button></form></section>
<section class="panel" style="margin-top:24px"><h2>Your blog sponsorships</h2><?php if(!$campaigns):?><p class="muted">You have not created a blog sponsorship yet.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Campaign</th><th>Placement</th><th>Dates</th><th>Proposed budget</th><th>Status</th><th>Sync</th><th>Action</th></tr></thead><tbody><?php foreach($campaigns as $c):?><tr><td><?=pf_e($c['title'])?></td><td><?=pf_e($placements[$c['placement_key']]??$c['placement_key'])?></td><td><?=pf_e(substr((string)$c['starts_at'],0,10))?> – <?=pf_e(substr((string)$c['ends_at'],0,10))?></td><td><?=pf_e($c['currency'])?> <?=number_format((float)$c['requested_budget'],2)?></td><td><?=pf_e($c['status'])?></td><td><?=pf_e($c['sync_status'])?><?php if($c['last_sync_error']):?><br><span class="small muted"><?=pf_e($c['last_sync_error'])?></span><?php endif;?></td><td><?php if($c['status']==='DRAFT'):?><a href="fund-blog-campaign.php?id=<?=(int)$c['id']?>">Reserve & submit</a><?php elseif($c['status']==='PAUSED'):?><form method="post" style="display:inline"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="action" value="resume"><input type="hidden" name="campaign_id" value="<?=(int)$c['id']?>"><button class="button primary" type="submit">Resume</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
</main></body></html>
