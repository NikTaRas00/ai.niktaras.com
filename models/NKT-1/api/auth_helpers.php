<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

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

// Every state-changing endpoint (register/login/logout/save/delete) must call
// this. Sessions exist (and carry a csrf token) before login too, so this
// also covers the pre-auth register/login requests, not just logged-in ones.
function require_csrf(): void
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($header === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $header)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Please reload the page and try again.']);
        exit;
    }
}

function db_or_fail(): PDO
{
    try {
        return get_pdo();
    } catch (Throwable $e) {
        error_log('[NKT-1 accounts] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'The database isn\'t set up on this server yet.']);
        exit;
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return ['id' => (int)$_SESSION['user_id'], 'email' => (string)($_SESSION['user_email'] ?? '')];
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Log in to continue.']);
        exit;
    }
    return $user;
}

function is_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
}
