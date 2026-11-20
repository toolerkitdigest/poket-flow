<?php
declare(strict_types=1);

/*
 * Copy this file to config.php and enter the credentials from cPanel.
 * Keep config.php private and never commit real passwords to GitHub.
 */
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'YOUR_CPANEL_DATABASE',
        'user' => 'YOUR_CPANEL_DATABASE_USER',
        'pass' => 'CHANGE_THIS_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // Use the same password entered in the CPAGrip Global Postback settings.
    'postback_password' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',

    // Exact offer link supplied for this campaign.
    'offer_url' => 'https://getafilenow.com/show.php?l=0&u=820144&id=39689',

    // CPAGrip explicitly documents tracking_id as the offer SubID parameter.
    'tracking_parameter' => 'tracking_id',

    // Only this offer is allowed to unlock this funnel.
    'expected_offer_id' => '39689',

    'site_url' => 'https://unlock.poketflow.com',
];
