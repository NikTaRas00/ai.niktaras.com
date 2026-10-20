<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_helpers.php';

const MAX_MESSAGES = 40;
const MAX_CHARS = 4000;
const LLM_TIMEOUT = 120;
const SEARCH_RESULT_COUNT = 5;
const RATE_LIMIT = 5;
// Must match this model's entry in admin/api/bootstrap.php MODELS.
const MODEL_KEY = 'NKT-2L';
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

// $message is shown to the user verbatim, so it must never name the underlying model,
// expose server file paths, or repeat upstream error text (SYSTEM_PROMPT.txt
// forbids the model itself from admitting it's Qwen; a leaked error message
// would break that at the exact moment the user is already stressed). Pass
// the real detail via $internalDetail so it still reaches the server log.
function fail(int $status, string $message, ?string $internalDetail = null): never
{
    if ($internalDetail !== null) {
        error_log('[NKT-2L chat.php] ' . $message . ' | ' . $internalDetail);
    }
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Calls the model running on the owner's PC (LM Studio, exposed through a
// Cloudflare Tunnel) via its OpenAI-compatible /v1/chat/completions API.
function call_local_llm(array $body, array $config): array
{
    $base = rtrim(trim((string)($config['LOCAL_LLM_URL'] ?? '')), '/');
    $key = trim((string)($config['LOCAL_LLM_KEY'] ?? ''));
    $body['model'] = trim((string)($config['LOCAL_LLM_MODEL'] ?? '')) ?: 'qwen3-14b';

    $headers = ['Content-Type: application/json'];
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    $ch = curl_init($base . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => LLM_TIMEOUT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        fail(503, 'NKT-2L is offline right now. Please try again later.', 'curl error: ' . $curlErr);
    }

    $decoded = json_decode($response, true);

    if ($status === 429) {
        fail(429, 'NKT-2L is getting a lot of requests right now. Please wait a moment and try again.', 'upstream 429');
    }

    if ($status !== 200 || !is_array($decoded)) {
        $detail = is_array($decoded) ? ($decoded['error']['message'] ?? ($decoded['error'] ?? 'Unknown error.')) : substr((string)$response, 0, 200);
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }
        // 52x/530 come from Cloudflare when the tunnel is up but the PC's server is not.
        $offline = $status === 0 || $status >= 520 || $status === 502 || $status === 503;
        fail(
            $offline ? 503 : ($status ?: 502),
            $offline ? 'NKT-2L is offline right now. Please try again later.' : 'NKT-2L hit a problem answering that. Please try again.',
            'upstream status ' . $status . ': ' . $detail
        );
    }

    return $decoded;
}

// Qwen3 is a reasoning model and may emit <think>...</think> before its answer.
function strip_reasoning(string $text): string
{
    $text = preg_replace('#<think>.*?</think>#s', '', $text);
    // An unterminated block means the token limit cut the answer off mid-thought.
    $text = preg_replace('#<think>.*$#s', '', (string)$text);
    return trim((string)$text);
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
if (trim((string)($config['LOCAL_LLM_URL'] ?? '')) === '') {
    fail(500, "NKT-2L isn't configured correctly right now. Please try again later.", 'LOCAL_LLM_URL missing in api/.env');
}

// Rate limit: 5 messages/hour by default. Logged-in accounts get a
// persistent, admin-adjustable limit per model (user_model_limits, falling
// back to users.rate_limit as the account's default -- see /admin/);
// anonymous visitors are limited per browser session instead, since
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
        $stmt = $pdo->prepare(
            'SELECT u.rate_limit AS default_limit, l.rate_limit AS model_limit, l.rate_count, l.rate_window_start
             FROM users u
             LEFT JOIN user_model_limits l ON l.user_id = u.id AND l.model = ?
             WHERE u.id = ?'
        );
        $stmt->execute([MODEL_KEY, $user['id']]);
        $row = $stmt->fetch() ?: [];

        $limit = (int)($row['model_limit'] ?? $row['default_limit'] ?? RATE_LIMIT);
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
            $update = $pdo->prepare(
                'INSERT INTO user_model_limits (user_id, model, rate_count, rate_window_start) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rate_count = VALUES(rate_count), rate_window_start = VALUES(rate_window_start)'
            );
            $update->execute([$user['id'], MODEL_KEY, $count, date('Y-m-d H:i:s', $windowStart)]);
        }

        $accountLimited = true;
    } catch (Throwable $e) {
        error_log('[NKT-2L chat.php] account rate-limit error, falling back to session limit: ' . $e->getMessage());
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
$chat = [];

// "/no_think" is Qwen3's soft switch to skip the reasoning phase (faster, and
// reasoning tokens can otherwise eat the whole max_tokens budget -> empty reply).
$systemPrompt = trim(load_system_prompt() . "\n\n/no_think");
$hasSystem = $systemPrompt !== '';
if ($hasSystem) {
    $chat[] = ['role' => 'system', 'content' => mb_substr($systemPrompt, 0, MAX_CHARS)];
}

foreach ($messages as $message) {
    $role = ($message['role'] ?? '') === 'model' ? 'assistant' : 'user';
    $text = mb_substr(trim((string)($message['text'] ?? '')), 0, MAX_CHARS);
    if ($text === '') {
        continue;
    }
    $chat[] = ['role' => $role, 'content' => $text];
}

if (count($chat) === ($hasSystem ? 1 : 0)) {
    fail(400, 'Nothing to send.');
}

$body = [
    'messages' => $chat,
    'temperature' => 0.7,
    'max_tokens' => 3000,
    'stream' => false,
];

if ($searchEnabled) {
    $body['tools'] = [[
        'type' => 'function',
        'function' => [
            'name' => 'web_search',
            'description' => 'Search the live web for current information: news, prices, weather, recent events, or anything that may have changed since training. Use it whenever the answer depends on up-to-date facts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The search query.'],
                ],
                'required' => ['query'],
            ],
        ],
    ]];
}

$decoded = call_local_llm($body, $config);
$assistantMessage = $decoded['choices'][0]['message'] ?? [];

$sources = [];

$toolCall = null;
foreach ($assistantMessage['tool_calls'] ?? [] as $tc) {
    if (($tc['function']['name'] ?? '') === 'web_search') {
        $toolCall = $tc;
        break;
    }
}

if ($toolCall !== null && $searchEnabled) {
    $args = json_decode((string)($toolCall['function']['arguments'] ?? '{}'), true);
    $query = trim((string)($args['query'] ?? ''));
    $results = $query !== '' ? web_search($query, $tavilyApiKey) : [];

    $seenUris = [];
    foreach ($results as $r) {
        if (isset($seenUris[$r['link']])) {
            continue;
        }
        $seenUris[$r['link']] = true;
        $sources[] = ['title' => $r['title'], 'uri' => $r['link']];
    }

    $body['messages'][] = [
        'role' => 'assistant',
        'content' => (string)($assistantMessage['content'] ?? ''),
        'tool_calls' => [$toolCall],
    ];
    $body['messages'][] = [
        'role' => 'tool',
        'tool_call_id' => $toolCall['id'] ?? 'call_0',
        'content' => json_encode(['results' => $results], JSON_UNESCAPED_UNICODE),
    ];

    $decoded = call_local_llm($body, $config);
    $assistantMessage = $decoded['choices'][0]['message'] ?? [];
}

$reply = strip_reasoning((string)($assistantMessage['content'] ?? ''));

if ($reply === '') {
    $reason = $decoded['choices'][0]['finish_reason'] ?? 'unknown';
    fail(502, "NKT-2L didn't have a response for that. Please try rephrasing or try again.", 'empty reply, finish_reason: ' . $reason);
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
                $firstText = trim((string)($m['text'] ?? ''));
                if ($firstText !== '') {
                    $title = mb_substr($firstText, 0, 60);
                }
                break;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO anonymous_chats (session_id, model, title, messages) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE messages = VALUES(messages), updated_at = NOW()'
        );
        $stmt->execute([session_id(), MODEL_KEY, $title,json_encode($transcript, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('[NKT-2L chat.php] anonymous chat logging failed: ' . $e->getMessage());
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
