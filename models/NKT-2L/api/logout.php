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

require_csrf();

$_SESSION = [];
session_destroy();

echo json_encode(['ok' => true]);
