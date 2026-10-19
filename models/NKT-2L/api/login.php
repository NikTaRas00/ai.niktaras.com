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

$email = trim((string)($payload['email'] ?? ''));
$password = (string)($payload['password'] ?? '');

$pdo = db_or_fail();
$stmt = $pdo->prepare('SELECT id, email, password_hash, email_verified_at FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

if ($user['email_verified_at'] === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Verify your email before logging in.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_email'] = $user['email'];

echo json_encode(['email' => $user['email']]);
