<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

bootstrap_session();

echo json_encode([
    'isAdmin' => is_admin(),
    'csrfToken' => csrf_token(),
]);
