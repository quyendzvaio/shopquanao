<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../config/db.php';

if (getenv('GLANCE_ENABLED') !== 'true' || getenv('GLANCE_PROVIDER_MODE') !== 'live' || getenv('GLANCE_LIVE_VERIFIED') !== 'true') {
    fwrite(STDERR, "UC2_LIVE_STATUS=BLOCKED (Glance live runtime is not enabled and verified)\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('SHOP_SMOKE_BASE_URL') ?: 'http://nginx'), '/');
$wsUrl = (string) (getenv('UC2_WS_URL') ?: 'ws://nginx/ws/chatbot');
$wsOrigin = (string) (getenv('UC2_WS_ORIGIN') ?: 'http://nginx');
$anchorId = (int) (getenv('UC2_ANCHOR_PRODUCT_ID') ?: 68);
$suffix = gmdate('YmdHis') . '-' . getmypid();
$token = bin2hex(random_bytes(32));
$sessionToken = bin2hex(random_bytes(32));
$userId = 0; $sessionId = 0; $eventId = '';

/** @return array<string,mixed> */
function postJson(string $url, string $token, array $payload): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]]);
    $raw = file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d\d\s/', $status)) throw new RuntimeException("HTTP request failed: {$status}");
    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string,mixed> */
function websocketTurn(string $message, string $token, string $sessionToken, string $wsUrl, string $origin): array
{
    $environment = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
        'UC2_WS_URL' => $wsUrl,
        'UC2_WS_ORIGIN' => $origin,
        'UC2_SESSION_TOKEN' => $sessionToken,
        'UC2_AUTHORIZATION' => 'Bearer ' . $token,
    ];
    $command = ['/usr/bin/node', __DIR__ . '/smoke_proactive_ws_turn.mjs', $message];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start UC2 WebSocket smoke client');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('UC2 WebSocket turn failed: ' . trim($stderr));
    $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result) || ($result['status'] ?? '') !== 'PASS') throw new RuntimeException('UC2 WebSocket turn returned invalid result');
    return $result;
}

try {
    $anchor = $pdo->prepare('SELECT id FROM products WHERE id=? AND stock > 0');
    $anchor->execute([$anchorId]);
    if ((int) $anchor->fetchColumn() !== $anchorId) throw new RuntimeException('UC2 anchor is not an in-stock private product');
    $pdo->prepare('INSERT INTO users (username,email,password,api_token,status) VALUES (?,?,?,?,1)')
        ->execute(['uc2_live_' . $suffix, 'uc2_live_' . $suffix . '@example.invalid', 'smoke-not-used', $token]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_sessions (user_id,session_token,status) VALUES (?,?,'active')")->execute([$userId, $sessionToken]);
    $sessionId = (int) $pdo->lastInsertId();

    $startedAt = microtime(true);
    postJson($baseUrl . '/api/cart', $token, ['product_id' => $anchorId, 'quantity' => 1, 'size' => 'M']);
    $state = null;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        $query = $pdo->prepare('SELECT pending_product_id,remaining_user_turns,eligible,source_event_id FROM proactive_styling_state WHERE user_id=? AND session_id=?');
        $query->execute([$userId, (string) $sessionId]);
        $state = $query->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($state !== null) break;
        usleep(250000);
    }
    if ($state === null || (int) $state['pending_product_id'] !== $anchorId || (int) $state['remaining_user_turns'] !== 2) {
        throw new RuntimeException('Cart event did not create the expected pending state');
    }
    $eventId = (string) $state['source_event_id'];
    $eventToConsumerMs = (int) round((microtime(true) - $startedAt) * 1000);

    $first = websocketTurn('Phí ship nội thành là bao nhiêu?', $token, $sessionToken, $wsUrl, $wsOrigin);
    $query = $pdo->prepare('SELECT remaining_user_turns,suggested_anchor_product_id FROM proactive_styling_state WHERE user_id=? AND session_id=?');
    $query->execute([$userId, (string) $sessionId]); $afterFirst = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    if (($first['primary_intent'] ?? '') !== 'shipping' || (int) ($afterFirst['remaining_user_turns'] ?? -1) !== 1) {
        throw new RuntimeException('First user turn was not counted exactly once');
    }

    $triggerStartedAt = microtime(true);
    $second = websocketTurn('Tìm áo thun cho tôi', $token, $sessionToken, $wsUrl, $wsOrigin);
    $query->execute([$userId, (string) $sessionId]); $afterSecond = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    $ids = array_values(array_unique(array_map('intval', is_array($second['product_ids'] ?? null) ? $second['product_ids'] : [])));
    if (empty($second['proactive_styling']) || $ids === [] || !empty($second['provider_id_leak'])
        || (int) ($afterSecond['remaining_user_turns'] ?? -1) !== 0
        || (int) ($afterSecond['suggested_anchor_product_id'] ?? 0) !== $anchorId) {
        throw new RuntimeException('Second suitable user turn did not produce a grounded proactive recommendation');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $grounded = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ({$marks})"); $grounded->execute($ids);
    if ((int) $grounded->fetchColumn() !== count($ids)) throw new RuntimeException('A displayed proactive product is not a private SKU');

    fwrite(STDOUT, 'UC2_LIVE_STATUS=PASS' . PHP_EOL
        . 'CART_EVENT_PIPELINE_STATUS=PASS' . PHP_EOL
        . 'USER_TURNS=2' . PHP_EOL
        . 'UC2_EVENT_TO_CONSUMER_MS=' . $eventToConsumerMs . PHP_EOL
        . 'UC2_TRIGGER_TO_RECOMMENDATION_MS=' . (int) round((microtime(true) - $triggerStartedAt) * 1000) . PHP_EOL
        . 'UC2_PRIVATE_PRODUCT_IDS=' . json_encode($ids) . PHP_EOL
        . 'PROVIDER_ID_LEAKAGE=0' . PHP_EOL
        . 'HALLUCINATED_PRODUCT_COUNT=0' . PHP_EOL
        . 'DEMO_FALLBACK_USED=0' . PHP_EOL);
} finally {
    if ($userId > 0) {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM cart WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM proactive_styling_state WHERE user_id=?')->execute([$userId]);
        if ($eventId !== '') {
            $pdo->prepare('DELETE FROM fashion_consumed_events WHERE event_id=?')->execute([$eventId]);
            $pdo->prepare('DELETE FROM fashion_event_outbox WHERE event_id=?')->execute([$eventId]);
        }
        if ($sessionId > 0) $pdo->prepare('DELETE FROM chat_sessions WHERE id=?')->execute([$sessionId]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        $pdo->commit();
    }
}
