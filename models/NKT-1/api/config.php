<?php
// Secrets are NOT stored here. They live in api/.env (gitignored, never committed)
// or in real server environment variables, which take precedence.
// See api/.env.example for the expected format.

function niktaras_load_dotenv(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $vars;
}

function niktaras_env(string $name, array $dotenv): string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return trim($value);
    }
    return trim((string)($dotenv[$name] ?? ''));
}

$dotenv = niktaras_load_dotenv(__DIR__ . '/.env');

return [
    'GEMINI_API_KEY' => niktaras_env('GEMINI_API_KEY', $dotenv),
    // Optional: enables live web search via Tavily (free, no billing
    // required, 1,000 searches/month). Leave blank to disable.
    'TAVILY_API_KEY' => niktaras_env('TAVILY_API_KEY', $dotenv),
    // Required for accounts/saved chats (see api/schema.sql).
    'DB_HOST' => niktaras_env('DB_HOST', $dotenv) ?: 'localhost',
    'DB_NAME' => niktaras_env('DB_NAME', $dotenv),
    'DB_USER' => niktaras_env('DB_USER', $dotenv),
    'DB_PASS' => niktaras_env('DB_PASS', $dotenv),
    // Used to build the verification link emailed to new users, and as the
    // "From" address on that email.
    'SITE_URL' => niktaras_env('SITE_URL', $dotenv),
    'MAIL_FROM' => niktaras_env('MAIL_FROM', $dotenv) ?: 'noreply@localhost',
    // Optional but recommended: send verification mail over authenticated SMTP
    // (a real cPanel mailbox) instead of PHP's mail(), which many shared hosts
    // refuse. Leave SMTP_HOST/SMTP_USER blank to fall back to mail().
    'SMTP_HOST' => niktaras_env('SMTP_HOST', $dotenv),
    'SMTP_PORT' => niktaras_env('SMTP_PORT', $dotenv),
    'SMTP_USER' => niktaras_env('SMTP_USER', $dotenv),
    'SMTP_PASS' => niktaras_env('SMTP_PASS', $dotenv),
    // Lets a visitor bypass the 5-messages/hour rate limit one message at a
    // time (see api/chat.php). Leave blank to disable bypassing entirely.
    'UNLOCK_CODE' => niktaras_env('UNLOCK_CODE', $dotenv),
    // Gates the /admin/ panel (see api/admin_login.php). Separate from
    // UNLOCK_CODE on purpose -- anyone given the unlock code to bypass the
    // rate limit should not thereby gain access to every account's and every
    // anonymous visitor's chat history.
    'ADMIN_CODE' => niktaras_env('ADMIN_CODE', $dotenv),
];
