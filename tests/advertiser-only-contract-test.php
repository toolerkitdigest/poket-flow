<?php
declare(strict_types=1);
$root=realpath(__DIR__.'/../marketplace');if($root===false)throw new RuntimeException('Marketplace application directory is missing.');
$required=['index.php'=>['Advertise','opportunities.php','pf_render_site_ad'],'dashboard.php'=>['ADVERTISER','campaigns.php','blog-campaigns.php'],'register.php'=>["'ADVERTISER'",'can_work','can_advertise'],'bootstrap/app.php'=>['pf_require_advertiser','410'],'admin/index.php'=>['ACTIVE ADVERTISERS','placements.php','finance.php']];
$errors=[];foreach($required as $path=>$needles){$content=@file_get_contents($root.'/'.$path);if($content===false){$errors[]=$path.' is missing';continue;}foreach($needles as $needle)if(!str_contains($content,$needle))$errors[]=$path.' missing marker '.$needle;}
foreach(['tasks.php','my-submissions.php','withdraw.php','dispute.php','fund-campaign.php','admin/disputes.php'] as $path){$content=@file_get_contents($root.'/'.$path);if($content===false||!str_contains($content,'http_response_code(410)'))$errors[]=$path.' must return HTTP 410';}
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Advertiser-only product contract checks passed.\n";
