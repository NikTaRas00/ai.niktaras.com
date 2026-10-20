<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

bootstrap_session();
require_admin();
$pdo = db_or_fail();

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// user_id => [model => row]
function limit_rows(PDO $pdo, ?int $userId): array
{
    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT user_id, model, rate_limit, rate_count, rate_window_start FROM user_model_limits WHERE user_id = ?');
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->query('SELECT user_id, model, rate_limit, rate_count, rate_window_start FROM user_model_limits');
    }
    $byUser = [];
    foreach ($stmt->fetchAll() as $row) {
        $byUser[(int)$row['user_id']][$row['model']] = $row;
    }
    return $byUser;
}

try {
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT id, email, email_verified_at, rate_limit AS default_limit, created_at FROM users WHERE id = ?');
            $stmt->execute([(int)$id]);
            $user = $stmt->fetch();
            if (!$user) {
                admin_json_error(404, 'Not found.');
            }

            $rows = limit_rows($pdo, (int)$user['id']);
            $user['limits'] = build_model_limits((int)$user['default_limit'], $rows[(int)$user['id']] ?? []);

            $convos = $pdo->prepare('SELECT id, model, title, updated_at FROM conversations WHERE user_id = ? ORDER BY updated_at DESC');
            $convos->execute([(int)$id]);
            $user['conversations'] = $convos->fetchAll();

            echo json_encode($user);
            exit;
        }

        $users = $pdo->query(
            'SELECT u.id, u.email, u.email_verified_at, u.rate_limit AS default_limit, u.created_at,
                    (SELECT COUNT(*) FROM conversations c WHERE c.user_id = u.id) AS conversation_count
             FROM users u
             ORDER BY u.created_at DESC'
        )->fetchAll();

        $rows = limit_rows($pdo, null);
        foreach ($users as &$u) {
            $u['limits'] = build_model_limits((int)$u['default_limit'], $rows[(int)$u['id']] ?? []);
        }
        unset($u);

        echo json_encode($users);
        exit;
    }

    if ($method === 'DELETE') {
        require_csrf();

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            admin_json_error(400, 'Request needs a valid "id".');
        }

        // Explicit deletes in a transaction rather than relying solely on the
        // ON DELETE CASCADE foreign keys.
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM conversations WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM user_model_limits WHERE user_id = ?')->execute([$id]);
        $del = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            admin_json_error(404, 'Not found.');
        }
        $pdo->commit();

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST') {
        require_csrf();

        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            admin_json_error(400, 'Invalid request body.');
        }
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            admin_json_error(400, 'Request needs a valid "id".');
        }

        $exists = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetch()) {
            admin_json_error(404, 'Not found.');
        }

        // Default limit: applies to every model that has no override of its own.
        if (array_key_exists('defaultLimit', $payload)) {
            $default = $payload['defaultLimit'];
            if (!is_int($default) || $default < 0 || $default > 1000) {
                admin_json_error(400, '"defaultLimit" must be a whole number from 0 to 1000.');
            }
            $pdo->prepare('UPDATE users SET rate_limit = ? WHERE id = ?')->execute([$default, $id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        $model = (string)($payload['model'] ?? '');
        if (!in_array($model, MODELS, true)) {
            admin_json_error(400, 'Unknown model.');
        }

        if (($payload['resetUsage'] ?? false) === true) {
            $pdo->prepare(
                'INSERT INTO user_model_limits (user_id, model, rate_count, rate_window_start) VALUES (?, ?, 0, NULL)
                 ON DUPLICATE KEY UPDATE rate_count = 0, rate_window_start = NULL'
            )->execute([$id, $model]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if (!array_key_exists('rateLimit', $payload)) {
            admin_json_error(400, 'Request needs "rateLimit" (0-1000, or null to use the default).');
        }
        $limit = $payload['rateLimit'];
        if ($limit !== null && (!is_int($limit) || $limit < 0 || $limit > 1000)) {
            admin_json_error(400, '"rateLimit" must be a whole number from 0 to 1000, or null to use the default.');
        }

        $pdo->prepare(
            'INSERT INTO user_model_limits (user_id, model, rate_limit) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate_limit = VALUES(rate_limit)'
        )->execute([$id, $model, $limit]);

        echo json_encode(['ok' => true]);
        exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    db_error($e, 'users endpoint');
}

admin_json_error(405, 'Unsupported method.');
