<?php
declare(strict_types=1);

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

/**
 * Read secrets only from an interactive controlling terminal.
 * Fail closed rather than ever accepting a password that may be echoed.
 */
function askSecret(string $label): string
{
    if (!function_exists('shell_exec') || !function_exists('posix_isatty') || !posix_isatty(STDIN)) {
        fwrite(STDERR, "Secure hidden password input is unavailable in this terminal. No password was read and no file was changed.\n");
        exit(1);
    }

    $tty = @fopen('/dev/tty', 'r');
    if ($tty === false) {
        fwrite(STDERR, "Cannot open the controlling terminal. No password was read.\n");
        exit(1);
    }

    $result = shell_exec('stty -echo < /dev/tty 2>&1 && printf "__PF_STTY_OK__"');
    if (!is_string($result) || !str_contains($result, '__PF_STTY_OK__')) {
        fclose($tty);
        fwrite(STDERR, "Could not disable terminal echo. No password was read.\n");
        exit(1);
    }

    try {
        fwrite(STDOUT, $label . ': ');
        $value = fgets($tty);
        if ($value === false) {
            throw new RuntimeException('Could not read the password from the terminal.');
        }
        return trim($value);
    } finally {
        shell_exec('stty echo < /dev/tty 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
        fclose($tty);
    }
}

$host = ask('MySQL host', 'localhost');
$dbname = ask('Database name', 'poketflo_db');
$username = ask('Database username', 'poketflo_dbuser');
$password = askSecret('Database password (input hidden)');

if ($password === '') {
    fwrite(STDERR, "Password cannot be empty.\n");
    exit(1);
}

$dir = '/home/poketflo/private';
$file = $dir . '/poketflow-main-database.php';

if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Cannot create private directory.\n");
    exit(1);
}

$old = is_file($file) ? require $file : [];
$encryptionKey = (is_array($old) && !empty($old['encryption_key']))
    ? $old['encryption_key']
    : base64_encode(random_bytes(32));

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
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed. No configuration written. Check credentials.\n");
    exit(1);
}

$config = [
    'host' => $host,
    'dbname' => $dbname,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'encryption_key' => $encryptionKey,
];

$php = "<?php\n// Private PoketFlow configuration. Never place in public_html.\nreturn "
    . var_export($config, true) . ";\n";
$temp = $file . '.tmp.' . bin2hex(random_bytes(6));

if (file_put_contents($temp, $php, LOCK_EX) === false) {
    @unlink($temp);
    fwrite(STDERR, "Could not write temporary private configuration.\n");
    exit(1);
}
chmod($temp, 0600);

if (!rename($temp, $file)) {
    @unlink($temp);
    fwrite(STDERR, "Could not safely install private configuration.\n");
    exit(1);
}

chmod($file, 0600);
chmod($dir, 0700);
fwrite(STDOUT, "Database connection successful. Private configuration saved.\n");
