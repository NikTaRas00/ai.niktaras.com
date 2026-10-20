<?php
declare(strict_types=1);

// Shared by every endpoint under /admin/api/. Include-only (denied by .htaccess).

header('Content-Type: application/json; charset=utf-8');

// To add a model: add its name here (exactly as chat.php's MODEL_KEY), then add
// it to the SELECT in migrate_002_model_limits.sql's seed if you want to backfill.
const MODELS = ['NKT-1', 'NKT-2L'];
// Must match RATE_LIMIT_WINDOW in each model's api/chat.php.
const RATE_LIMIT_WINDOW = 3600;

// Config comes from real env vars first, then admin/api/.env, then the models'
// own .env files (same database + ADMIN_CODE as the chat apps, so no extra
// setup is needed). Only the keys the admin panel needs are read.
function admin_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $files = [
        __DIR__ . '/.env',
        __DIR__ . '/../../models/NKT-1/api/.env',
        __DIR__ . '/../../models/NKT-2L/api/.env',
    ];
    $dotenv = [];
    foreach ($files as $path) {
        if (!is_readable($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            // First file to give a non-empty value wins.
            if ($value !== '' && !isset($dotenv[$key])) {
                $dotenv[$key] = $value;
            }
        }
    }

    $config = [];
    foreach (['ADMIN_CODE', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
        $env = getenv($name);
        $config[$name] = ($env !== false && $env !== '') ? trim($env) : (string)($dotenv[$name] ?? '');
    }
    if ($config['DB_HOST'] === '') {
        $config['DB_HOST'] = 'localhost';
    }
    return $config;
}

function admin_json_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($header === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $header)) {
        admin_json_error(403, 'Your session expired. Please reload the page and try again.');
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        admin_json_error(401, 'Admin access required.');
    }
}

function db_or_fail(): PDO
{
    $config = admin_config();
    if ($config['DB_NAME'] === '' || $config['DB_USER'] === '') {
        error_log('[admin] database is not configured (no DB_NAME/DB_USER found in any .env)');
        admin_json_error(500, "The database isn't set up on this server yet.");
    }
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_NAME']);
        return new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('[admin] database connection failed: ' . $e->getMessage());
        admin_json_error(500, "The database isn't set up on this server yet.");
    }
}

function db_error(Throwable $e, string $what): void
{
    error_log('[admin] ' . $what . ' failed: ' . $e->getMessage());
    admin_json_error(500, 'Database error. Have you run admin/api/migrate_002_model_limits.sql against this database yet?');
}

// One entry per model for a user: the explicit override (null = inherits the
// user's default), the limit that actually applies, and usage in the current
// window (0 once the window has rolled over, matching chat.php).
function build_model_limits(int $defaultLimit, array $rowsByModel): array
{
    $now = time();
    $out = [];
    foreach (MODELS as $model) {
        $row = $rowsByModel[$model] ?? null;
        $override = ($row !== null && $row['rate_limit'] !== null) ? (int)$row['rate_limit'] : null;
        $start = ($row !== null && !empty($row['rate_window_start'])) ? strtotime((string)$row['rate_window_start']) : false;
        $active = $start !== false && ($now - $start) < RATE_LIMIT_WINDOW;
        $out[] = [
            'model' => $model,
            'override' => $override,
            'limit' => $override !== null ? $override : $defaultLimit,
            'used' => ($active && $row !== null) ? (int)$row['rate_count'] : 0,
            'windowStart' => $row['rate_window_start'] ?? null,
        ];
    }
    return $out;
}
