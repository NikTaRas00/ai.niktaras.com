<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    admin_json_error(405, 'Send a POST request.');
}

require_csrf();

// Only clear the admin flag -- this browser may also have a regular logged-in
// user session that should stay intact.
unset($_SESSION['is_admin']);

echo json_encode(['ok' => true]);
