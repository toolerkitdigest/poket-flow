<?php
declare(strict_types=1);

/**
 * Architecture guard: the locked marketplace must remain independent of
 * CPA offer-wall providers. Historical root-level files are not deployed;
 * this test scans only the production marketplace application directory.
 */

$root = realpath(__DIR__ . '/../marketplace');
if ($root === false || !is_dir($root)) {
    throw new RuntimeException('Marketplace application directory is missing.');
}

$forbidden = [
    '/ogads/i',
    '/cpagrip/i',
    '/indoleads/i',
    '/aff_sub4/i',
    '/CPA_NETWORK/i',
    '/network_payout/i',
    '/postback/i',
];

$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'sql', 'js', 'css', 'json', 'htaccess'], true)) {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        throw new RuntimeException('Could not inspect marketplace source file.');
    }

    foreach ($forbidden as $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            $violations[] = substr($file->getPathname(), strlen($root) + 1)
                . ' contains a prohibited CPA integration identifier.';
            break;
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

echo "Marketplace architecture guard passed: no CPA offer-wall providers, tracking parameters or postback integration found.\n";
