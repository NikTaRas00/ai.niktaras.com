<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

const MAX_MESSAGES = 200;

bootstrap_session();
$user = require_login();
$pdo = db_or_fail();

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT id, title, messages, created_at, updated_at FROM conversations WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$id, $user['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found.']);
            exit;
        }
        $row['messages'] = json_decode($row['messages'], true) ?? [];
        echo json_encode($row);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, title, updated_at FROM conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100');
    $stmt->execute([$user['id']]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    require_csrf();

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['messages']) || !is_array($payload['messages'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Request needs a "messages" array.']);
        exit;
    }

    $messages = array_slice($payload['messages'], -MAX_MESSAGES);
    $messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE);
    $id = isset($payload['id']) ? (int)$payload['id'] : 0;

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE conversations SET messages = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$messagesJson, $id, $user['id']]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found.']);
            exit;
        }
        echo json_encode(['id' => $id]);
        exit;
    }

    $title = 'Untitled chat';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            $candidate = trim((string)($m['text'] ?? ''));
            if ($candidate !== '') {
                $title = mb_substr($candidate, 0, 60);
            }
            break;
        }
    }

    $stmt = $pdo->prepare('INSERT INTO conversations (user_id, title, messages) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'], $title, $messagesJson]);
    echo json_encode(['id' => (int)$pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    require_csrf();

    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM conversations WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Unsupported method.']);
