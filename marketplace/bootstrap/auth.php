<?php
declare(strict_types=1);

/** Privacy-preserving rate limit for public authentication forms. */
function pf_auth_rate_allowed(PDO $pdo, string $action, string $identity, int $limit, int $minutes): bool
{
    global $config;
    $secret = base64_decode((string) ($config['encryption_key'] ?? ''), true);
    if ($secret === false || strlen($secret) !== 32) {
        return false;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipHash = hash_hmac('sha256', $ip, $secret);
    $identityHash = hash_hmac('sha256', strtolower(trim($identity)), $secret);
    $since = date('Y-m-d H:i:s', time() - ($minutes * 60));

    $pdo->prepare('DELETE FROM pf_auth_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)')->execute();
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM pf_auth_attempts
         WHERE action=? AND ip_hash=? AND identity_hash=? AND attempted_at>=?'
    );
    $count->execute([$action, $ipHash, $identityHash, $since]);
    if ((int) $count->fetchColumn() >= $limit) {
        return false;
    }

    $insert = $pdo->prepare(
        'INSERT INTO pf_auth_attempts (action,ip_hash,identity_hash) VALUES (?,?,?)'
    );
    $insert->execute([$action, $ipHash, $identityHash]);
    return true;
}

/** Send plain-text transactional email using the main platform mail settings. */
function pf_send_mail(PDO $pdo, string $to, string $subject, string $message): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        return false;
    }

    $settings = [];
    try {
        $stmt = $pdo->prepare('SELECT setting_key,setting_value FROM settings WHERE setting_key IN (?,?,?)');
        $stmt->execute(['mail_from_name','mail_from_address','mail_reply_to']);
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
    } catch (Throwable $exception) {
        error_log('PoketFlow mail settings could not be loaded.');
        return false;
    }

    $fromAddress = $settings['mail_from_address'] ?? 'no-reply@poketflow.com';
    $replyTo = $settings['mail_reply_to'] ?? 'support@poketflow.com';
    $fromName = $settings['mail_from_name'] ?? 'PoketFlow';
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromName = trim(str_replace(["\r", "\n", '"'], '', $fromName));
    $subject = trim(str_replace(["\r", "\n"], '', $subject));
    $encodedName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($fromName, 'UTF-8')
        : $fromName;
    $headers = [
        'From: ' . $encodedName . ' <' . $fromAddress . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PoketFlow',
    ];

    try {
        return mail($to, $subject, $message, implode("\r\n", $headers));
    } catch (Throwable $exception) {
        error_log('PoketFlow transactional email could not be sent.');
        return false;
    }
}
