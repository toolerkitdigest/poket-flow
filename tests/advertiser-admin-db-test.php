<?php
declare(strict_types=1);
$configPath=getenv('POKETFLOW_CONFIG');if(!$configPath||!is_file($configPath))throw new RuntimeException('Test database config is missing.');
$config=require $configPath;$pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s',$config['host'],$config['dbname'],$config['charset']??'utf8mb4'),$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$queries=[
 'admin campaign listing'=>"SELECT c.id,c.title,c.campaign_type,c.status,c.currency,c.reserved_budget,c.starts_at,c.ends_at,u.name advertiser_name,p.status advertiser_status,sc.placement_key site_placement,bc.placement_key blog_placement,bc.sync_status,(SELECT COUNT(*) FROM pf_site_ad_events se WHERE se.campaign_id=c.id AND se.event_type='IMPRESSION')+(SELECT COUNT(*) FROM pf_blog_events be WHERE be.campaign_id=c.id AND be.event_type='IMPRESSION') impressions,(SELECT COUNT(*) FROM pf_site_ad_events sclick WHERE sclick.campaign_id=c.id AND sclick.event_type='CLICK')+(SELECT COUNT(*) FROM pf_blog_events bclick WHERE bclick.campaign_id=c.id AND bclick.event_type='CLICK') clicks FROM pf_campaigns c JOIN users u ON u.id=c.advertiser_id JOIN pf_advertiser_profiles p ON p.user_id=c.advertiser_id LEFT JOIN pf_site_campaigns sc ON sc.campaign_id=c.id LEFT JOIN pf_blog_campaigns bc ON bc.campaign_id=c.id WHERE c.campaign_type IN ('SITE_DISPLAY','BLOG_SPONSORED') ORDER BY c.created_at DESC LIMIT 5",
 'admin advertiser review'=>"SELECT p.user_id,p.advertiser_name,p.contact_email,p.website,p.description,p.review_note,p.status,u.name,u.email FROM pf_advertiser_profiles p JOIN users u ON u.id=p.user_id ORDER BY p.user_id LIMIT 5",
 'advertiser profile editing'=>"SELECT p.advertiser_name,p.contact_email,p.website,p.description,p.status,m.can_advertise FROM pf_advertiser_profiles p JOIN pf_account_modes m ON m.user_id=p.user_id LIMIT 5",
 'online payment reconciliation'=>"SELECT p.provider,p.provider_reference,p.amount,p.currency,p.status,p.created_at,u.name,u.email FROM pf_payment_orders p JOIN users u ON u.id=p.advertiser_id ORDER BY p.id DESC LIMIT 5",
 'multi-currency placements'=>"SELECT p.placement_key,p.surface,p.label,p.formats_json,pr.currency,pr.price_per_day,pr.is_active FROM pf_ad_placements p JOIN pf_ad_placement_prices pr ON pr.placement_key=p.placement_key ORDER BY p.placement_key,pr.currency LIMIT 20"
];
foreach($queries as $name=>$sql){$pdo->query($sql)->fetchAll();}
$cols=$pdo->query("SHOW COLUMNS FROM pf_advertiser_profiles")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('description',$cols,true)||!in_array('website',$cols,true))throw new RuntimeException('Advertiser profile columns are incomplete.');
$prices=$pdo->query("SELECT COUNT(*) FROM pf_ad_placement_prices WHERE currency IN ('USD','NGN')")->fetchColumn();
if((int)$prices<2)throw new RuntimeException('USD/NGN placement price rows were not created.');
$format=$pdo->query("SELECT formats_json FROM pf_ad_placements WHERE placement_key='main_home_top'")->fetchColumn();
$formats=json_decode((string)$format,true);if(!is_array($formats)||!in_array('banner',$formats,true))throw new RuntimeException('Main-site banner placement format is missing.');
echo "Advertiser profile, admin queries, payment reconciliation and USD/NGN placement schema checks passed.\n";
