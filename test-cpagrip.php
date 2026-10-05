<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo '<h2>PoketFlow CPAGrip Test</h2>';

echo '<p>PHP is running.</p>';

$configPath = '/home/YOUR_ACCOUNT/private/poketflow-cpagrip.php';

echo '<p>Checking configuration file...</p>';

if (!file_exists($configPath)) {
    die(
        '<p style="color:red;">
        ERROR: CPAGrip configuration file was not found.<br><br>
        Current path being checked:<br>
        <strong>' .
        htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8') .
        '</strong>
        </p>'
    );
}

echo '<p style="color:green;">Configuration file exists.</p>';

$config = require $configPath;

echo '<p style="color:green;">Configuration loaded successfully.</p>';

if (!is_array($config)) {
    die(
        '<p style="color:red;">
        ERROR: Configuration file must return an array.
        </p>'
    );
}

if (empty($config['user_id'])) {
    die(
        '<p style="color:red;">
        ERROR: CPAGrip user_id is missing.
        </p>'
    );
}

if (empty($config['private_key'])) {
    die(
        '<p style="color:red;">
        ERROR: CPAGrip private_key is missing.
        </p>'
    );
}

echo '<p style="color:green;">CPAGrip credentials are present.</p>';

echo '<p>Testing cURL...</p>';

if (!function_exists('curl_init')) {
    die(
        '<p style="color:red;">
        ERROR: PHP cURL extension is not enabled.
        </p>'
    );
}

echo '<p style="color:green;">cURL is available.</p>';

echo '<hr>';

echo '<p><strong>Configuration test completed.</strong></p>';

echo '<p>
No private key or credential value is displayed by this test.
</p>';
