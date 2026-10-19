<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

bootstrap_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Send a POST request.']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

require_csrf();

$config = load_config();
$validCode = trim((string)($config['ADMIN_CODE'] ?? ''));
$providedCode = trim((string)($payload['code'] ?? ''));

if ($validCode === '' || $providedCode === '' || !hash_equals($validCode, $providedCode)) {
    http_response_code(401);
    echo json_encode(['error' => 'Incorrect code.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['is_admin'] = true;

echo json_encode(['ok' => true]);
