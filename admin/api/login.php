<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    admin_json_error(405, 'Send a POST request.');
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    admin_json_error(400, 'Invalid request body.');
}

require_csrf();

$validCode = admin_config()['ADMIN_CODE'];
$providedCode = trim((string)($payload['code'] ?? ''));

if ($validCode === '' || $providedCode === '' || !hash_equals($validCode, $providedCode)) {
    usleep(500000);
    admin_json_error(401, 'Incorrect code.');
}

session_regenerate_id(true);
$_SESSION['is_admin'] = true;

echo json_encode(['ok' => true]);
