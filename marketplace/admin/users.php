<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/app.php';
$admin = pf_require_admin($pdo);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_verify_csrf();
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$targetId || $targetId === (int)$admin['id'] || !in_array($action, ['suspend','restore'], true)) {
        $error = 'This member action is not permitted.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id,role,status FROM users WHERE id=? FOR UPDATE');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if (!$target || strtoupper((string)$target['role']) === 'ADMIN') {
                throw new RuntimeException('Administrator accounts cannot be changed from this screen.');
            }
            $from = strtoupper((string)$target['status']);
            $to = $action === 'suspend' ? 'SUSPENDED' : 'ACTIVE';
            $expected = $action === 'suspend' ? 'ACTIVE' : 'SUSPENDED';
            if ($from !== $expected) {
                throw new RuntimeException('The member status has changed or is not eligible for this action.');
            }
            $blogCampaignIds = [];
            if ($action === 'suspend') {
                $blogQuery = $pdo->prepare("SELECT bc.campaign_id FROM pf_blog_campaigns bc JOIN pf_campaigns c ON c.id=bc.campaign_id WHERE c.advertiser_id=? AND c.campaign_type='BLOG_SPONSORED' AND c.status='ACTIVE' AND bc.status='ACTIVE'");
                $blogQuery->execute([$targetId]);
                $blogCampaignIds = array_map('intval',$blogQuery->fetchAll(PDO::FETCH_COLUMN));
            }
            $update = $pdo->prepare('UPDATE users SET status=? WHERE id=? AND status=?');
            $update->execute([$to,$targetId,$from]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Member status could not be updated.');
            }
            $pausedCampaigns = 0;
            if ($action === 'suspend') {
                $pause = $pdo->prepare("UPDATE pf_campaigns SET status='PAUSED' WHERE advertiser_id=? AND status='ACTIVE'");
                $pause->execute([$targetId]);
                $pausedCampaigns = $pause->rowCount();
                if ($blogCampaignIds) {
                    $placeholders = implode(',',array_fill(0,count($blogCampaignIds),'?'));
                    $pauseBlog = $pdo->prepare("UPDATE pf_blog_campaigns SET status='PAUSED',sync_status='PENDING' WHERE campaign_id IN ({$placeholders})");
                    $pauseBlog->execute($blogCampaignIds);
                }
            }
            $audit = $pdo->prepare(
                'INSERT INTO pf_audit_log (actor_user_id,action,subject_type,subject_id,before_json,after_json,ip_address)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ipBinary = filter_var($ip,FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
            $audit->execute([
                (int)$admin['id'],
                $action === 'suspend' ? 'ADMIN_MEMBER_SUSPENDED' : 'ADMIN_MEMBER_RESTORED',
                'USER',
                $targetId,
                json_encode(['status'=>$from],JSON_THROW_ON_ERROR),
                json_encode(['status'=>$to,'active_campaigns_paused'=>$pausedCampaigns],JSON_THROW_ON_ERROR),
                $ipBinary,
            ]);
            $pdo->commit();
            if ($action === 'suspend') {
                foreach ($blogCampaignIds as $blogCampaignId) {
                    $sync = pf_sync_blog_campaign($pdo,$blogCampaignId,'upsert');
                    if ($sync['ok']) {
                        $pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='SYNCED',last_sync_error=NULL WHERE campaign_id=?")->execute([$blogCampaignId]);
                    } else {
                        $pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='FAILED',last_sync_error=? WHERE campaign_id=?")->execute([$sync['error'],$blogCampaignId]);
                        pf_sync_blog_campaign($pdo,$blogCampaignId,'revoke');
                    }
                }
            }
            $notice = $action === 'suspend' ? 'Member access has been suspended.' : 'Member access has been restored.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Member status update failed.';
        }
    }
}

$query = trim((string)($_GET['q'] ?? ''));
$page = max(1,(int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page-1)*$limit;
$where = '';
$params = [];
if ($query !== '') {
    $where = ' WHERE name LIKE ? OR email LIKE ? OR CAST(id AS CHAR)=?';
    $params = ['%'.$query.'%','%'.$query.'%',$query];
}
$count = $pdo->prepare('SELECT COUNT(*) FROM users'.$where);
$count->execute($params);
$total = (int)$count->fetchColumn();
$list = $pdo->prepare(
    'SELECT id,name,email,role,status,email_verified,country,created_at FROM users'
    .$where.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset
);
$list->execute($params);
$members = $list->fetchAll();
$pages = max(1,(int)ceil($total/$limit));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Member management | PoketFlow</title><link rel="stylesheet" href="../assets/marketplace.css"><style>.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:12px;border-bottom:1px solid var(--line);white-space:nowrap}.tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.tools input{padding:11px;border:1px solid var(--line);border-radius:10px;font:inherit}.inline-form{display:inline}.button.small{padding:8px 11px;font-size:12px}.danger{background:#fff1f2;color:#9f1239}</style></head>
<body><header class="topbar"><a class="brand" href="../index.php"><span class="brand-mark">P</span><span>Poket<span>Flow</span></span></a><nav><a href="index.php">Admin home</a><a href="finance.php">Finance</a><a href="../dashboard.php">Member dashboard</a><a href="../logout.php">Sign out</a></nav></header>
<main class="container"><section class="welcome"><p class="eyebrow">ADMINISTRATION</p><h1>Member management</h1><p class="muted">Review account status and control access. Passwords, email addresses and wallet records are never edited here.</p></section>
<?php if($error):?><div class="alert"><?=pf_e($error)?></div><?php endif;?><?php if($notice):?><div class="panel"><?=pf_e($notice)?></div><?php endif;?>
<section class="panel"><form class="tools" method="get"><input name="q" value="<?=pf_e($query)?>" maxlength="100" placeholder="Search name, email or member ID"><button class="button primary" type="submit">Search</button><?php if($query!==''):?><a href="users.php">Clear</a><?php endif;?></form>
<p class="small muted"><?=number_format($total)?> members · Page <?=$page?> of <?=$pages?></p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Member</th><th>Role</th><th>Status</th><th>Email verified</th><th>Country</th><th>Joined</th><th>Action</th></tr></thead><tbody>
<?php foreach($members as $member):?><tr><td><?=(int)$member['id']?></td><td><strong><?=pf_e($member['name'])?></strong><br><span class="small muted"><?=pf_e($member['email'])?></span></td><td><?=pf_e($member['role'])?></td><td><?=pf_e($member['status'])?></td><td><?=((int)$member['email_verified']===1?'Yes':'No')?></td><td><?=pf_e((string)$member['country'])?></td><td><?=pf_e($member['created_at'])?></td><td><?php if(strtoupper((string)$member['role'])!=='ADMIN' && $member['status']==='ACTIVE'):?><form class="inline-form" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$member['id']?>"><button class="button small danger" name="action" value="suspend" type="submit">Suspend</button></form><?php elseif(strtoupper((string)$member['role'])!=='ADMIN' && $member['status']==='SUSPENDED'):?><form class="inline-form" method="post"><input type="hidden" name="_token" value="<?=pf_e(pf_csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$member['id']?>"><button class="button small primary" name="action" value="restore" type="submit">Restore</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
<?php if(!$members):?><tr><td colspan="8">No members matched your search.</td></tr><?php endif;?>
</tbody></table></div>
<div class="tools" style="margin-top:16px"><?php if($page>1):?><a href="?q=<?=rawurlencode($query)?>&page=<?=$page-1?>">Previous</a><?php endif;?><?php if($page<$pages):?><a href="?q=<?=rawurlencode($query)?>&page=<?=$page+1?>">Next</a><?php endif;?></div>
</section></main></body></html>
