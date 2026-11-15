<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../marketplace/bootstrap/database.php';
require_once __DIR__.'/../marketplace/bootstrap/blog-sync.php';
$rows=$pdo->query("SELECT c.id,c.campaign_type FROM pf_campaigns c LEFT JOIN pf_site_campaigns s ON s.campaign_id=c.id LEFT JOIN pf_blog_campaigns b ON b.campaign_id=c.id WHERE c.campaign_type IN ('SITE_DISPLAY','BLOG_SPONSORED') AND c.status='ACTIVE' AND ((c.campaign_type='SITE_DISPLAY' AND s.status='ACTIVE' AND s.ends_at<NOW()) OR (c.campaign_type='BLOG_SPONSORED' AND b.status='ACTIVE' AND b.ends_at<NOW())) ORDER BY c.ends_at ASC LIMIT 200")->fetchAll();
$completed=0;$failedSync=0;
foreach($rows as $row){$id=(int)$row['id'];$type=(string)$row['campaign_type'];$table=$type==='SITE_DISPLAY'?'pf_site_campaign_reservations':'pf_blog_campaign_reservations';$child=$type==='SITE_DISPLAY'?'pf_site_campaigns':'pf_blog_campaigns';
 try{$pdo->beginTransaction();$q=$pdo->prepare('SELECT status,reserved_budget FROM pf_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch();if(!$c||$c['status']!=='ACTIVE'){$pdo->rollBack();continue;}
  $r=$pdo->prepare("SELECT id,amount FROM {$table} WHERE campaign_id=? AND status='RESERVED' FOR UPDATE");$r->execute([$id]);$res=$r->fetch();if(!$res||(float)$res['amount']<=0||abs((float)$res['amount']-(float)$c['reserved_budget'])>0.01){$pdo->rollBack();fwrite(STDERR,"Skipped campaign {$id}: reservation mismatch; manual review required.\n");continue;}
  $pdo->prepare("UPDATE pf_campaigns SET status='COMPLETED',spent_budget=spent_budget+?,reserved_budget=0 WHERE id=? AND status='ACTIVE'")->execute([$res['amount'],$id]);$pdo->prepare("UPDATE {$table} SET status='CONSUMED' WHERE id=? AND status='RESERVED'")->execute([(int)$res['id']]);
  if($type==='SITE_DISPLAY')$pdo->prepare("UPDATE {$child} SET status='ENDED' WHERE campaign_id=?")->execute([$id]);else $pdo->prepare("UPDATE {$child} SET status='ENDED',sync_status='PENDING' WHERE campaign_id=?")->execute([$id]);
  $pdo->prepare("INSERT INTO pf_audit_log(actor_user_id,action,subject_type,subject_id,after_json) VALUES (NULL,'SYSTEM_AD_CAMPAIGN_EXPIRED','AD_CAMPAIGN',?,?)")->execute([$id,json_encode(['status'=>'COMPLETED','budget_consumed'=>$res['amount'],'campaign_type'=>$type],JSON_THROW_ON_ERROR)]);$pdo->commit();
  if($type==='BLOG_SPONSORED'){$sync=pf_sync_blog_campaign($pdo,$id,'revoke');if($sync['ok'])$pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='SYNCED',last_sync_error=NULL WHERE campaign_id=?")->execute([$id]);else{$pdo->prepare("UPDATE pf_blog_campaigns SET sync_status='FAILED',last_sync_error=? WHERE campaign_id=?")->execute([$sync['error'],$id]);$failedSync++;}}
  $completed++;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Campaign {$id} was not expired: ".$e->getMessage()."\n");}
}
echo "Expired fixed-duration advertising campaigns: {$completed}; blog sync failures: {$failedSync}.\n";
