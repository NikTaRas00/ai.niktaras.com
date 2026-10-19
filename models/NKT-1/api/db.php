<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = load_config();
    $host = (string)($config['DB_HOST'] ?? 'localhost');
    $name = (string)($config['DB_NAME'] ?? '');
    $user = (string)($config['DB_USER'] ?? '');
    $pass = (string)($config['DB_PASS'] ?? '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database is not configured. See api/.env.example.');
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
