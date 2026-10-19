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

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a valid email address.']);
    exit;
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 8 characters.']);
    exit;
}

$pdo = db_or_fail();

$stmt = $pdo->prepare('SELECT id, email_verified_at FROM users WHERE email = ?');
$stmt->execute([$email]);
$existing = $stmt->fetch();

$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

// Regardless of which branch runs, the response is identical -- an existing,
// already-verified email must not be distinguishable from a new signup.
if ($existing && $existing['email_verified_at'] !== null) {
    // Verified account already exists: do nothing, say nothing new.
} elseif ($existing) {
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, verification_token_hash = ?, verification_token_expires_at = ? WHERE id = ?');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $tokenHash, $expiresAt, $existing['id']]);
    $mailOk = send_verification_email($email, $rawToken);
} else {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, verification_token_hash, verification_token_expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $tokenHash, $expiresAt]);
    $mailOk = send_verification_email($email, $rawToken);
}

if (isset($mailOk) && !$mailOk) {
    http_response_code(500);
    echo json_encode(['error' => "We couldn't send the verification email. Please try again in a few minutes."]);
    exit;
}

echo json_encode(['message' => 'Check your email for a verification link.']);
