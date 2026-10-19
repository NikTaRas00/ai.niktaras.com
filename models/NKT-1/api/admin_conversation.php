<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

bootstrap_session();
require_admin();
$pdo = db_or_fail();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Unsupported method.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing "id".']);
    exit;
}

try {
    // Admin can view any user's conversation -- no ownership check, unlike
    // api/conversations.php.
    $stmt = $pdo->prepare(
        'SELECT c.id, c.title, c.messages, c.created_at, c.updated_at, c.user_id, u.email
         FROM conversations c JOIN users u ON u.id = c.user_id
         WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found.']);
        exit;
    }

    $row['messages'] = json_decode($row['messages'], true) ?? [];
    echo json_encode($row);
} catch (Throwable $e) {
    error_log('[NKT-1 admin] conversation query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error. Have you run api/migrate_001_admin.sql against this database yet?']);
}
