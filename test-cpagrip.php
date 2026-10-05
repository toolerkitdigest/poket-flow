<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/cpagrip.php';

echo '<pre>';

if (empty($offers)) {
    echo "No CPAGrip offers were returned.\n";
} else {

    echo "CPAGrip offers returned: "
        . count($offers)
        . "\n\n";

    foreach ($offers as $offer) {

        echo "----------------------------------------\n";

        echo "Title: "
            . $offer['title']
            . "\n";

        echo "Payout: $"
            . number_format(
                $offer['payout'],
                2
            )
            . "\n";

        echo "PoketFlow Reward: $"
            . number_format(
                $offer['reward'],
                2
            )
            . "\n";

        echo "Link: "
            . $offer['offerlink']
            . "\n";
    }
}

echo '</pre>';
