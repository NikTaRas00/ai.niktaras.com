<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

bootstrap_session();
require_admin();
$pdo = db_or_fail();

$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT id, email, email_verified_at, rate_limit, rate_count, rate_window_start, created_at FROM users WHERE id = ?');
            $stmt->execute([(int)$id]);
            $user = $stmt->fetch();
            if (!$user) {
                http_response_code(404);
                echo json_encode(['error' => 'Not found.']);
                exit;
            }

            $convos = $pdo->prepare('SELECT id, title, updated_at FROM conversations WHERE user_id = ? ORDER BY updated_at DESC');
            $convos->execute([(int)$id]);
            $user['conversations'] = $convos->fetchAll();

            echo json_encode($user);
            exit;
        }

        $stmt = $pdo->query(
            'SELECT u.id, u.email, u.email_verified_at, u.rate_limit, u.rate_count, u.rate_window_start, u.created_at,
                    (SELECT COUNT(*) FROM conversations c WHERE c.user_id = u.id) AS conversation_count
             FROM users u
             ORDER BY u.created_at DESC'
        );
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($method === 'DELETE') {
        require_csrf();

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Request needs a valid "id".']);
            exit;
        }

        // Explicit deletes in a transaction rather than relying solely on the
        // ON DELETE CASCADE foreign key, in case the table was created without it.
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM conversations WHERE user_id = ?')->execute([$id]);
        $del = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Not found.']);
            exit;
        }
        $pdo->commit();

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST') {
        require_csrf();

        $payload = json_decode((string)file_get_contents('php://input'), true);
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        $rateLimit = isset($payload['rateLimit']) ? (int)$payload['rateLimit'] : null;

        if ($id <= 0 || $rateLimit === null || $rateLimit < 0 || $rateLimit > 1000) {
            http_response_code(400);
            echo json_encode(['error' => 'Request needs a valid "id" and "rateLimit" (0-1000).']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE users SET rate_limit = ? WHERE id = ?');
        $stmt->execute([$rateLimit, $id]);
        if ($stmt->rowCount() === 0) {
            // Could also mean the value didn't change; confirm the row exists.
            $check = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Not found.']);
                exit;
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }
} catch (Throwable $e) {
    error_log('[NKT-1 admin] users query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error. Have you run api/migrate_001_admin.sql against this database yet?']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Unsupported method.']);
