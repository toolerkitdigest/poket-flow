<?php
declare(strict_types=1);

/*
 * Run from SSH CLI only. This writes database credentials outside public_html.
 * Never commit the generated private config file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function ask(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    $value = trim((string) readline($label . $suffix . ': '));
    return $value !== '' ? $value : $default;
}

function askSecret(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($canHide) {
        shell_exec('stty -echo 2>/dev/null');
    }
    try {
        $value = trim((string) fgets(STDIN));
    } finally {
        if ($canHide) {
            shell_exec('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        }
    }
    return $value;
}

$host = ask('MySQL host', 'localhost');
$dbname = ask('Database name', 'poketflo_db');
$username = ask('Database username', 'poketflo_dbuser');
$password = askSecret('Database password (input hidden)');

if ($password === '') {
    fwrite(STDERR, "Password cannot be empty. No file written.\n");
    exit(1);
}

$targetDir = '/home/poketflo/private';
$targetFile = $targetDir . '/poketflow-main-database.php';

if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) {
    fwrite(STDERR, "Cannot create private configuration directory.\n");
    exit(1);
}

$config = [
    'host' => $host,
    'dbname' => $dbname,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
];

$php = "<?php\n// Private PoketFlow database configuration. Keep outside public_html.\nreturn "
    . var_export($config, true) . ";\n";

if (file_put_contents($targetFile, $php, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write private configuration file.\n");
    exit(1);
}

@chmod($targetFile, 0600);
@chmod($targetDir, 0700);

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->query('SELECT 1');
    fwrite(STDOUT, "Database connection successful.\n");
    fwrite(STDOUT, "Private config saved outside public_html.\n");
} catch (Throwable $e) {
    @unlink($targetFile);
    fwrite(STDERR, "Connection failed; temporary config removed. Check host/database/user/password.\n");
    exit(1);
}
