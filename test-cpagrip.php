<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/includes/cpagrip.php';

echo '<h2>PoketFlow CPAGrip Tracking Test</h2>';

echo '<h3>PoketFlow Session</h3>';

if (!empty($_SESSION['user_id'])) {
    echo '<p style="color:green;">';
    echo 'Logged-in user ID: ';
    echo htmlspecialchars(
        (string) $_SESSION['user_id'],
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</p>';
} else {
    echo '<p style="color:red;">';
    echo 'No PoketFlow user is logged in.';
    echo '</p>';
}

echo '<h3>CPAGrip Offers</h3>';

if (empty($offers)) {

    echo '<p style="color:red;">';
    echo 'No offers returned.';
    echo '</p>';

} else {

    echo '<p style="color:green;">';
    echo 'Offers returned: ';
    echo count($offers);
    echo '</p>';

    foreach ($offers as $offer) {

        echo '<hr>';

        echo '<strong>Offer ID:</strong> '
            . htmlspecialchars(
                $offer['offer_id'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '<br>';

        echo '<strong>Title:</strong> '
            . htmlspecialchars(
                $offer['title'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '<br>';

        echo '<strong>Category:</strong> '
            . htmlspecialchars(
                $offer['category'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '<br>';

        echo '<strong>Type:</strong> '
            . htmlspecialchars(
                $offer['type'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '<br>';

        echo '<strong>CPAGrip Payout:</strong> $'
            . number_format(
                $offer['payout'],
                2
            )
            . '<br>';

        echo '<strong>PoketFlow Reward:</strong> $'
            . number_format(
                $offer['reward'],
                2
            )
            . '<br>';

        echo '<strong>PoketFlow Margin:</strong> $'
            . number_format(
                $offer['margin'],
                2
            )
            . '<br>';
    }
}

echo '<hr>';

echo '<p>';
echo '<strong>Important:</strong> ';
echo 'This test does not expose the CPAGrip private key.';
echo '</p>';
