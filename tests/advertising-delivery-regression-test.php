<?php
declare(strict_types=1);

/**
 * Regression guards for the advertiser delivery paths fixed in this branch.
 * These are source-contract checks; live HTTP/provider tests remain separate.
 */
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Required source file is missing: ' . $path);
    }
    return $content;
};

$sync = $read('marketplace/bootstrap/blog-sync.php');
$adminFinance = $read('marketplace/admin/finance.php');
$adminCampaigns = $read('marketplace/admin/campaigns.php');
$siteAds = $read('marketplace/bootstrap/site-ads.php');
$siteClick = $read('marketplace/ad-click.php');
$paypalWebhook = $read('marketplace/api/paypal-webhook.php');
$payments = $read('marketplace/bootstrap/payments.php');
$createPayment = $read('marketplace/create-payment.php');

$checks = [
    'blog synchronization checks advertiser status before activation' =>
        str_contains($sync, 'advertiser_status') &&
        str_contains($sync, 'hasReservedBudget') &&
        str_contains($sync, "=== 'ACTIVE'"),
    'blog synchronization requires a remaining reserved budget' =>
        str_contains($sync, 'remaining_reserved') &&
        str_contains($sync, 'hasReservedBudget'),
    'blog pause synchronizes a paused creative instead of ending it' =>
        str_contains($adminCampaigns, "syncAction='upsert';"),
    'failed blog synchronization retry performs an upsert' =>
        str_contains($adminFinance, "syncAction='upsert';") &&
        str_contains($adminFinance, 'Retry blog sync'),
    'main-site impression tracking failure does not stop ad rendering' =>
        str_contains($siteAds, 'Analytics failure must never make the public page unavailable.') &&
        str_contains($siteAds, 'catch (Throwable $exception)'),
    'main-site click tracking failure does not block destination redirect' =>
        str_contains($siteClick, 'A tracking outage must not prevent a visitor from following the ad.') &&
        strpos($siteClick, "header('Location: '.$url") > strpos($siteClick, 'catch (Throwable $exception)'),
    'PayPal webhook requires exact advertiser identity' =>
        str_contains($paypalWebhook, "custom===''") &&
        str_contains($paypalWebhook, 'advertiser_id') &&
        str_contains($paypalWebhook, '!==$custom') &&
        str_contains($paypalWebhook, 'supplementary_data') &&
        str_contains($paypalWebhook, 'related_ids'),
    'PayPal idempotency keys are sent as HTTP header lines' =>
        str_contains($payments, 'PayPal-Request-Id: pf-capture-') &&
        str_contains($createPayment, 'PayPal-Request-Id: pf-order-') &&
        str_contains($payments, 'paypalOrderId') &&
        str_contains($payments, 'transactionId'),
        !str_contains($payments, "'PayPal-Request-Id'=>") &&
        !str_contains($createPayment, "'PayPal-Request-Id'=>"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
}

echo 'Advertising delivery regression checks passed: ' . count($checks) . " checks.\n";
