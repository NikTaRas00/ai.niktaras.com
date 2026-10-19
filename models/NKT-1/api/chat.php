<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

const MODEL = 'gemini-3.5-flash-lite';
const MAX_MESSAGES = 40;
const MAX_CHARS = 4000;
const SEARCH_RESULT_COUNT = 5;
const RATE_LIMIT = 5;
const RATE_LIMIT_WINDOW = 3600;

function check_unlock_code(array $config, array $payload): array
{
    $validCode = trim((string)($config['UNLOCK_CODE'] ?? ''));
    $providedCode = trim((string)($payload['unlockCode'] ?? ''));
    $ok = $validCode !== '' && $providedCode !== '' && hash_equals($validCode, $providedCode);
    $message = $providedCode === ''
        ? "You've reached the hourly limit. Enter your access code to send another message."
        : 'Incorrect code.';
    return [$ok, $message];
}

function load_system_prompt(): string
{
    $path = __DIR__ . '/SYSTEM_PROMPT.txt';
    if (!is_readable($path)) {
        return '';
    }
    $text = file_get_contents($path);
    return $text === false ? '' : trim($text);
}

// $message is shown to the user verbatim, so it must never name Gemini,
// expose server file paths, or repeat upstream error text (SYSTEM_PROMPT.txt
// forbids the model itself from admitting it's Gemini; a leaked error message
// would break that at the exact moment the user is already stressed). Pass
// the real detail via $internalDetail so it still reaches the server log.
function fail(int $status, string $message, ?string $internalDetail = null): never
{
    if ($internalDetail !== null) {
        error_log('[NKT-1 chat.php] ' . $message . ' | ' . $internalDetail);
    }
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function call_gemini(array $body, string $apiKey): array
{
    $url = sprintf(
        'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
        MODEL
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        fail(502, "NKT-1 couldn't reach its model just now. Please try again in a moment.", 'curl error: ' . $curlErr);
    }

    $decoded = json_decode($response, true);

    if ($status === 429) {
        $detail = $decoded['error']['message'] ?? 'Unknown error.';
        fail(429, 'NKT-1 is getting a lot of requests right now. Please wait a moment and try again.', $detail);
    }

    if ($status !== 200) {
        $detail = $decoded['error']['message'] ?? 'Unknown error.';
        fail($status ?: 502, 'NKT-1 hit a problem answering that. Please try again.', 'upstream status ' . $status . ': ' . $detail);
    }

    return $decoded;
}

// Free, no-billing web search via Tavily (1,000 free searches/month,
// no credit card required). Returns [] on any failure or missing config.
function web_search(string $query, string $apiKey): array
{
    $ch = curl_init('https://api.tavily.com/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'query' => $query,
            'max_results' => SEARCH_RESULT_COUNT,
            'search_depth' => 'basic',
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return [];
    }

    $decoded = json_decode($response, true);
    $results = [];
    foreach ($decoded['results'] ?? [] as $item) {
        $link = $item['url'] ?? '';
        if ($link === '') {
            continue;
        }
        $results[] = [
            'title' => $item['title'] ?? $link,
            'link' => $link,
            'snippet' => $item['content'] ?? '',
        ];
    }
    return $results;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'Send a POST request.');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    fail(400, 'Empty request body.');
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['messages']) || !is_array($payload['messages'])) {
    fail(400, 'Request needs a "messages" array.');
}

$config = load_config();
$apiKey = trim((string)($config['GEMINI_API_KEY'] ?? ''));
if ($apiKey === '') {
    fail(500, "NKT-1 isn't configured correctly right now. Please try again later.", 'GEMINI_API_KEY missing in api/config.php');
}

// Rate limit: 5 messages/hour by default. Logged-in accounts get a
// persistent, admin-adjustable limit (users.rate_limit -- see the admin
// panel); anonymous visitors are limited per browser session instead, since
// there's no stable identity to attach a custom limit to. Once spent, every
// further message needs a correct UNLOCK_CODE (api/.env) -- one message per
// correct entry, never resetting the window/count, so it must be re-entered
// each time until the hour rolls over.
bootstrap_session();
$user = current_user();
$now = time();
$needsCode = false;
$codeError = '';
$accountLimited = false;

if ($user !== null) {
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT rate_limit, rate_count, rate_window_start FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        $limit = (int)($row['rate_limit'] ?? RATE_LIMIT);
        $count = (int)($row['rate_count'] ?? 0);
        $windowStart = !empty($row['rate_window_start']) ? strtotime($row['rate_window_start']) : false;

        if ($windowStart === false || ($now - $windowStart) >= RATE_LIMIT_WINDOW) {
            $windowStart = $now;
            $count = 0;
        }

        if ($count >= $limit) {
            [$codeOk, $codeError] = check_unlock_code($config, $payload);
            $needsCode = !$codeOk;
        }

        if (!$needsCode) {
            $count++;
            $update = $pdo->prepare('UPDATE users SET rate_count = ?, rate_window_start = ? WHERE id = ?');
            $update->execute([$count, date('Y-m-d H:i:s', $windowStart), $user['id']]);
        }

        $accountLimited = true;
    } catch (Throwable $e) {
        error_log('[NKT-1 chat.php] account rate-limit error, falling back to session limit: ' . $e->getMessage());
    }
}

if (!$accountLimited) {
    if (empty($_SESSION['rate_window_start']) || ($now - $_SESSION['rate_window_start']) >= RATE_LIMIT_WINDOW) {
        $_SESSION['rate_window_start'] = $now;
        $_SESSION['rate_count'] = 0;
    }

    if (($_SESSION['rate_count'] ?? 0) >= RATE_LIMIT) {
        [$codeOk, $codeError] = check_unlock_code($config, $payload);
        $needsCode = !$codeOk;
    }

    if (!$needsCode) {
        $_SESSION['rate_count'] = ($_SESSION['rate_count'] ?? 0) + 1;
    }
}

if ($needsCode) {
    http_response_code(429);
    echo json_encode(['error' => $codeError, 'needsCode' => true]);
    exit;
}

$tavilyApiKey = trim((string)($config['TAVILY_API_KEY'] ?? ''));
$webAccessRequested = ($payload['webAccess'] ?? false) === true;
$searchEnabled = $tavilyApiKey !== '' && $webAccessRequested;

$messages = array_slice($payload['messages'], -MAX_MESSAGES);
$contents = [];

foreach ($messages as $message) {
    $role = ($message['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = mb_substr(trim((string)($message['text'] ?? '')), 0, MAX_CHARS);
    if ($text === '') {
        continue;
    }
    $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
}

if ($contents === []) {
    fail(400, 'Nothing to send.');
}

$body = [
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.8,
        'maxOutputTokens' => 2048,
    ],
];

if ($searchEnabled) {
    $body['tools'] = [[
        'functionDeclarations' => [[
            'name' => 'web_search',
            'description' => 'Search the live web for current information: news, prices, weather, recent events, or anything that may have changed since training. Use it whenever the answer depends on up-to-date facts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The search query.'],
                ],
                'required' => ['query'],
            ],
        ]],
    ]];
}

$systemPrompt = load_system_prompt();
if ($systemPrompt !== '') {
    $body['systemInstruction'] = [
        'parts' => [['text' => mb_substr($systemPrompt, 0, MAX_CHARS)]],
    ];
}

$decoded = call_gemini($body, $apiKey);
$candidate = $decoded['candidates'][0] ?? null;
$parts = $candidate['content']['parts'] ?? [];

$sources = [];

$functionCallPart = null;
foreach ($parts as $part) {
    if (isset($part['functionCall']['name']) && $part['functionCall']['name'] === 'web_search') {
        $functionCallPart = $part;
        break;
    }
}

if ($functionCallPart !== null && $searchEnabled) {
    $query = trim((string)($functionCallPart['functionCall']['args']['query'] ?? ''));
    $results = $query !== '' ? web_search($query, $tavilyApiKey) : [];

    $seenUris = [];
    foreach ($results as $r) {
        if (isset($seenUris[$r['link']])) {
            continue;
        }
        $seenUris[$r['link']] = true;
        $sources[] = ['title' => $r['title'], 'uri' => $r['link']];
    }

    $body['contents'][] = ['role' => 'model', 'parts' => [$functionCallPart]];
    $body['contents'][] = [
        'role' => 'user',
        'parts' => [[
            'functionResponse' => [
                'name' => 'web_search',
                'response' => ['results' => $results],
            ],
        ]],
    ];

    $decoded = call_gemini($body, $apiKey);
    $candidate = $decoded['candidates'][0] ?? null;
}

$reply = $candidate['content']['parts'][0]['text'] ?? '';

if ($reply === '') {
    $reason = $candidate['finishReason'] ?? 'unknown';
    fail(502, "NKT-1 didn't have a response for that. Please try rephrasing or try again.", 'empty reply, finishReason: ' . $reason);
}

$result = ['reply' => $reply];
if ($sources !== []) {
    $result['sources'] = $sources;
}

// Logged-in users' chats are saved by the frontend calling
// api/conversations.php; anonymous ones are never sent there, so this is the
// only place they can be captured for the admin panel's "Anonymous Chats"
// tab. Keyed by PHP session id, best-effort -- must never break the reply.
if ($user === null) {
    try {
        $pdo = get_pdo();
        $transcript = $messages;
        $transcript[] = ['role' => 'model', 'text' => $reply];

        $title = 'Untitled chat';
        foreach ($transcript as $m) {
            if (($m['role'] ?? '') === 'user') {
                $candidate = trim((string)($m['text'] ?? ''));
                if ($candidate !== '') {
                    $title = mb_substr($candidate, 0, 60);
                }
                break;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO anonymous_chats (session_id, title, messages) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE messages = VALUES(messages), updated_at = NOW()'
        );
        $stmt->execute([session_id(), $title, json_encode($transcript, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('[NKT-1 chat.php] anonymous chat logging failed: ' . $e->getMessage());
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
