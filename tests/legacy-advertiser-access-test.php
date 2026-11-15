<?php
declare(strict_types=1);
require_once __DIR__.'/../marketplace/bootstrap/auth.php';
$configPath=getenv('POKETFLOW_CONFIG');
if(!$configPath||!is_file($configPath))throw new RuntimeException('Test database config is missing.');
$config=require $configPath;
$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s',$config['host'],$config['dbname'],$config['charset']??'utf8mb4'),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$expected=[1=>'ACTIVE',2=>'SUSPENDED',3=>'REJECTED'];
foreach($expected as $userId=>$status){
 if(pf_legacy_advertiser_access_allowed($pdo,$userId)!==($status==='ACTIVE'))throw new RuntimeException('Legacy advertiser restriction mismatch for user '.$userId);
 $profile=$pdo->prepare('SELECT status FROM pf_advertiser_profiles WHERE user_id=?');$profile->execute([$userId]);
 if(strtoupper((string)$profile->fetchColumn())!==$status)throw new RuntimeException('Advertiser profile migration mismatch for user '.$userId);
 $mode=$pdo->prepare('SELECT can_advertise FROM pf_account_modes WHERE user_id=?');$mode->execute([$userId]);
 if((int)$mode->fetchColumn()!==1)throw new RuntimeException('Existing member advertiser capability missing for user '.$userId);
}
echo "Legacy advertiser restrictions are preserved; existing members retain advertiser access where permitted.\n";
