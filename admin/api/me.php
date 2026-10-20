<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

bootstrap_session();

echo json_encode([
    'isAdmin' => is_admin(),
    'csrfToken' => csrf_token(),
    'models' => MODELS,
]);
