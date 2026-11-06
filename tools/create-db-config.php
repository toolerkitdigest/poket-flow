<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function ask(string $label, string $default = ''): string {
    $suffix = $default !== '' ? " [{$default}]" : '';
    $value = trim((string)readline($label . $suffix . ': '));
    return $value !== '' ? $value : $default;
}
function askSecret(string $label): string {
    fwrite(STDOUT, $label . ': ');
    if (function_exists('shell_exec')) shell_exec('stty -echo 2>/dev/null');
    try { $value = trim((string)fgets(STDIN)); }
    finally {
        if (function_exists('shell_exec')) shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }
    return $value;
}
$host = ask('MySQL host', 'localhost');
$dbname = ask('Database name', 'poketflo_db');
$username = ask('Database username', 'poketflo_dbuser');
$password = askSecret('Database password (input hidden)');
if ($password === '') { fwrite(STDERR, "Password cannot be empty.\n"); exit(1); }

$dir = '/home/poketflo/private';
$file = $dir . '/poketflow-main-database.php';
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR, "Cannot create private directory.\n"); exit(1); }
$old = is_file($file) ? require $file : [];
$config = [
 'host'=>$host, 'dbname'=>$dbname, 'username'=>$username, 'password'=>$password,
 'charset'=>'utf8mb4',
 'encryption_key'=>(is_array($old) && !empty($old['encryption_key'])) ? $old['encryption_key'] : base64_encode(random_bytes(32)),
];
try {
 $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4",$username,$password,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false,
 ]);
 $pdo->query('SELECT 1');
} catch (Throwable $e) {
 fwrite(STDERR, "Database connection failed. No configuration written. Check credentials.\n");
 exit(1);
}
$php = "<?php\n// Private PoketFlow configuration. Never place in public_html.\nreturn " . var_export($config,true) . ";\n";
if (file_put_contents($file,$php,LOCK_EX)===false) { fwrite(STDERR,"Could not write private config.\n"); exit(1); }
chmod($file,0600); chmod($dir,0700);
fwrite(STDOUT,"Database connection successful. Private configuration saved.\n");
