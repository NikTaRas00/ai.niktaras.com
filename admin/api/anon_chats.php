<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

bootstrap_session();
require_admin();
$pdo = db_or_fail();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    admin_json_error(405, 'Unsupported method.');
}

$id = $_GET['id'] ?? null;

try {
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT id, model, title, messages, created_at, updated_at FROM anonymous_chats WHERE id = ?');
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        if (!$row) {
            admin_json_error(404, 'Not found.');
        }
        $row['messages'] = json_decode($row['messages'], true) ?? [];
        echo json_encode($row);
        exit;
    }

    $model = (string)($_GET['model'] ?? '');
    if ($model !== '') {
        if (!in_array($model, MODELS, true)) {
            admin_json_error(400, 'Unknown model.');
        }
        $stmt = $pdo->prepare('SELECT id, model, title, created_at, updated_at FROM anonymous_chats WHERE model = ? ORDER BY updated_at DESC LIMIT 200');
        $stmt->execute([$model]);
    } else {
        $stmt = $pdo->query('SELECT id, model, title, created_at, updated_at FROM anonymous_chats ORDER BY updated_at DESC LIMIT 200');
    }
    echo json_encode($stmt->fetchAll());
} catch (Throwable $e) {
    db_error($e, 'anonymous chats query');
}
