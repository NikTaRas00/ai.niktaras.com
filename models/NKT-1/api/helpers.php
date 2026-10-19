<?php
declare(strict_types=1);

function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/config.php';
    $config = is_readable($path) ? (require_once $path) : [];
    return $config;
}

function send_verification_email(string $email, string $rawToken): bool
{
    $config = load_config();
    $siteUrl = rtrim((string)($config['SITE_URL'] ?? ''), '/');
    $from = trim((string)($config['MAIL_FROM'] ?? ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || substr($from, -10) === '@localhost') {
        // Shared hosts reject/drop mail from a non-domain sender, so this is
        // almost certainly the reason mail never arrives.
        error_log('[NKT-1 mail] MAIL_FROM is missing or invalid ("' . $from . '"); set it to an address on your own domain in api/.env');
        return false;
    }
    $link = $siteUrl . '/api/verify.php?token=' . $rawToken;

    $subject = 'Verify your NKT-1 account';
    $body = "Welcome to NKT-1!\r\n\r\nVerify your email by opening this link:\r\n{$link}\r\n\r\nThis link expires in 24 hours. If you didn't request this, ignore this email.\r\n";
    $domain = substr(strrchr($from, '@'), 1);
    $headers = implode("\r\n", [
        "From: NKT-1 <{$from}>",
        "Reply-To: {$from}",
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
    ]);

    // Preferred path: authenticated SMTP through a real mailbox. Many shared
    // hosts refuse PHP's mail() outright (it returns false with no reason).
    if (!empty($config['SMTP_HOST']) && !empty($config['SMTP_USER'])) {
        $sent = smtp_send($config, $from, $email, $subject, $headers, $body);
        if ($sent) {
            error_log('[NKT-1 mail] SMTP accepted for delivery to ' . $email);
        }
        return $sent;
    }

    // -f sets the envelope sender (Return-Path). cPanel/Exim and most spam
    // filters require it to match a real address on the domain.
    $sent = mail($email, $subject, $body, $headers, '-f' . $from);
    if (!$sent) {
        $lastError = error_get_last();
        error_log('[NKT-1 mail] mail() with -f failed for ' . $email . ($lastError ? (' | ' . $lastError['message']) : '') . ' -- retrying without -f');
        $sent = mail($email, $subject, $body, $headers);
    }
    if (!$sent) {
        $lastError = error_get_last();
        error_log('[NKT-1 mail] mail() returned false sending to ' . $email . ($lastError ? (' | ' . $lastError['message']) : ''));
    } else {
        error_log('[NKT-1 mail] mail() accepted for delivery to ' . $email);
    }
    return $sent;
}

// Minimal SMTP client (no Composer, PHP 7.4-safe). Port 465 = implicit TLS,
// anything else = plain connect then STARTTLS. Logs the failing step.
function smtp_send(array $config, string $from, string $to, string $subject, string $headers, string $body): bool
{
    $host = (string)$config['SMTP_HOST'];
    $port = (int)($config['SMTP_PORT'] ?: 465);
    $user = (string)$config['SMTP_USER'];
    $pass = (string)($config['SMTP_PASS'] ?? '');

    $errno = 0;
    $errstr = '';
    // Talking to the mail server on this same machine: its cert is issued for
    // the public hostname, not "localhost", so skip verification on loopback only.
    $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $ctx = stream_context_create(['ssl' => $isLoopback
        ? ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
        : ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("[NKT-1 smtp] connect to $host:$port failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($fp, 10);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $line, string $expect) use ($fp, $read, &$failed): bool {
        if ($line !== '') {
            fwrite($fp, $line . "\r\n");
        }
        $resp = $read();
        if (strpos($resp, $expect) !== 0) {
            // Credential lines are bare base64, so never echo them into the log.
            $label = $line === '' ? '(greeting)' : (preg_match('/^[A-Z]+( |$)/', $line) ? $line : 'AUTH credentials');
            $failed = $label . ' -> ' . trim($resp);
            return false;
        }
        return true;
    };

    $failed = '';
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    $ok = $cmd('', '220') && $cmd($ehlo, '250');
    if ($ok && $port !== 465) {
        $ok = $cmd('STARTTLS', '220')
            && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            && $cmd($ehlo, '250');
    }
    $ok = $ok
        && $cmd('AUTH LOGIN', '334')
        && $cmd(base64_encode($user), '334')
        && $cmd(base64_encode($pass), '235')
        && $cmd('MAIL FROM:<' . $from . '>', '250')
        && $cmd('RCPT TO:<' . $to . '>', '250')
        && $cmd('DATA', '354');
    if ($ok) {
        $message = 'To: <' . $to . ">\r\nSubject: " . $subject . "\r\n" . $headers . "\r\n\r\n"
            . preg_replace('/^\./m', '..', $body);
        fwrite($fp, $message . "\r\n.\r\n");
        $resp = $read();
        $ok = strpos($resp, '250') === 0;
        if (!$ok) {
            $failed = 'DATA end -> ' . trim($resp);
        }
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);

    if (!$ok) {
        error_log('[NKT-1 smtp] failed at: ' . ($failed !== '' ? $failed : 'STARTTLS handshake'));
    }
    return $ok;
}
