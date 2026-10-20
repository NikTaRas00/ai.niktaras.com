<?php
declare(strict_types=1);

// Public, read-only: tells the model picker whether NKT-2L's host is reachable.
// Returns only {"online": bool}; never the URL, key or any upstream detail.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/helpers.php';

$config = load_config();
$base = rtrim(trim((string)($config['LOCAL_LLM_URL'] ?? '')), '/');
$key = trim((string)($config['LOCAL_LLM_KEY'] ?? ''));

$online = false;

if ($base !== '') {
    $headers = [];
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    $ch = curl_init($base . '/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $online = $response !== false && $status === 200;
}

echo json_encode(['online' => $online]);
