<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

bootstrap_session();

$user = current_user();

// An admin may have deleted this account while the session was still alive.
// If the row is gone, drop the login. A DB error leaves the session alone.
if ($user !== null) {
    try {
        $stmt = get_pdo()->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        if (!$stmt->fetch()) {
            unset($_SESSION['user_id'], $_SESSION['user_email']);
            $user = null;
        }
    } catch (Throwable $e) {
        error_log('[NKT-1 me] user existence check failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'loggedIn' => $user !== null,
    'email' => $user['email'] ?? null,
    'csrfToken' => csrf_token(),
]);
