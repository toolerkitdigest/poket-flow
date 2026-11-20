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

    // Set a long random secret. Enter the same value in CPAGrip's postback settings.
    'postback_password' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',

    // This is the exact offer URL supplied for this campaign.
    'offer_url' => 'https://getafilenow.com/show.php?l=0&u=820144&id=39689',

    // CPAGrip tracking parameter used by the offer URL.
    'tracking_parameter' => 'tracking_id',

    'site_url' => 'https://unlock.poketflow.com',
];
