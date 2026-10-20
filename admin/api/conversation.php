<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

bootstrap_session();
require_admin();
$pdo = db_or_fail();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    admin_json_error(405, 'Unsupported method.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    admin_json_error(400, 'Missing "id".');
}

try {
    // Any user's conversation, no ownership check (unlike each model's api/conversations.php).
    $stmt = $pdo->prepare(
        'SELECT c.id, c.model, c.title, c.messages, c.created_at, c.updated_at, c.user_id, u.email
         FROM conversations c JOIN users u ON u.id = c.user_id
         WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        admin_json_error(404, 'Not found.');
    }

    $row['messages'] = json_decode($row['messages'], true) ?? [];
    echo json_encode($row);
} catch (Throwable $e) {
    db_error($e, 'conversation query');
}
