<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once __DIR__ . '/../config/db.php';

$smokeBaseUrl = rtrim((string) (getenv('SHOP_SMOKE_BASE_URL') ?: 'http://127.0.0.1'), '/');

/** @return array<string,mixed> */
function postJson(string $url, string $token, array $payload, int $expectedStatus): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'ignore_errors' => true,
        'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content' => $body,
        'timeout' => 30,
    ]]);
    $raw = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s' . $expectedStatus . '\s/', $statusLine)) {
        throw new RuntimeException("{$url} returned {$statusLine}: " . substr((string) $raw, 0, 300));
    }

    try {
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(
            "{$url} returned invalid JSON: " . substr(strip_tags((string) $raw), 0, 800),
            0,
            $exception
        );
    }
    if (!is_array($decoded)) {
        throw new RuntimeException("{$url} returned a non-object JSON response");
    }
    return $decoded;
}

/** @return array<string,mixed>|null */
function proactiveState(PDO $pdo, int $userId, int $sessionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT pending_product_id,remaining_user_turns,eligible,source_event_id '
        . 'FROM proactive_styling_state WHERE user_id=? AND session_id=?'
    );
    $stmt->execute([$userId, (string) $sessionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$suffix = gmdate('YmdHis') . '-' . getmypid();
$username = 'chat_turn_smoke_' . $suffix;
$token = bin2hex(random_bytes(32));
$sessionToken = bin2hex(random_bytes(32));
$userId = 0;
$sessionId = 0;
$eventId = '';

try {
    $pdo->prepare('INSERT INTO users (username,email,password,api_token,status) VALUES (?,?,?,?,1)')
        ->execute([$username, $username . '@example.invalid', 'smoke-not-used', $token]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_sessions (user_id,session_token,status) VALUES (?,?,'active')")
        ->execute([$userId, $sessionToken]);
    $sessionId = (int) $pdo->lastInsertId();

    $productId = (int) $pdo->query('SELECT id FROM products WHERE stock > 0 ORDER BY id LIMIT 1')->fetchColumn();
    if ($productId <= 0) {
        throw new RuntimeException('No in-stock shop product exists');
    }

    postJson($smokeBaseUrl . '/api/cart', $token, [
        'product_id' => $productId,
        'quantity' => 1,
        'size' => 'M',
    ], 201);

    $state = null;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $state = proactiveState($pdo, $userId, $sessionId);
        if ($state !== null) {
            break;
        }
        usleep(250000);
    }
    if ($state === null || (int) $state['remaining_user_turns'] !== 2) {
        throw new RuntimeException('Cart event did not establish a two-turn pending state');
    }
    $eventId = (string) $state['source_event_id'];

    $first = postJson($smokeBaseUrl . '/api/chatbot', $token, [
        'message' => 'Phí ship nội thành là bao nhiêu?',
        'session_token' => $sessionToken,
    ], 200);
    $state = proactiveState($pdo, $userId, $sessionId);
    if (($first['primary_intent'] ?? '') !== 'shipping' || (int) ($state['remaining_user_turns'] ?? -1) !== 1) {
        throw new RuntimeException('First real user message did not decrement pending turns from 2 to 1');
    }

    $second = postJson($smokeBaseUrl . '/api/chatbot', $token, [
        'message' => 'Đơn hàng giao trong bao lâu?',
        'session_token' => $sessionToken,
    ], 200);
    $state = proactiveState($pdo, $userId, $sessionId);
    if (!in_array(($second['primary_intent'] ?? ''), ['shipping', 'order_status'], true)
        || (int) ($state['remaining_user_turns'] ?? -1) !== 0
        || (int) ($state['eligible'] ?? 0) !== 1
    ) {
        throw new RuntimeException('Second unsuitable user message did not reach zero while keeping the anchor eligible: '
            . json_encode(['intent' => $second['primary_intent'] ?? null, 'state' => $state]));
    }

    $third = postJson($smokeBaseUrl . '/api/chatbot', $token, [
        'message' => 'Tìm áo thun cho tôi',
        'session_token' => $sessionToken,
    ], 200);
    $state = proactiveState($pdo, $userId, $sessionId);
    if (($third['primary_intent'] ?? '') !== 'product_search' || (int) ($state['remaining_user_turns'] ?? -1) !== 0) {
        throw new RuntimeException('Suitable retry did not reach the pending styling decision');
    }
    $demoMode = strtolower((string) (getenv('FINDMINE_DEMO_ENABLED') ?: '')) === 'true';
    if ($demoMode) {
        if (empty($third['proactive_styling']) || empty($third['products'])) {
            throw new RuntimeException('Configured demo provider did not return proactive shop products');
        }
    } elseif ((int) ($state['eligible'] ?? 0) !== 1) {
        throw new RuntimeException('Suitable retry without a live mapping did not retain the pending anchor');
    }

    fwrite(STDOUT, "HTTP_CHAT_MESSAGE_1=PASS\n");
    fwrite(STDOUT, "HTTP_CHAT_MESSAGE_2=PASS\n");
    fwrite(STDOUT, "ONLY_USER_TURNS_COUNTED=PASS\n");
    fwrite(STDOUT, "UNSUITABLE_CONTEXT_SUPPRESSED=PASS\n");
    fwrite(STDOUT, ($demoMode ? "DEMO_PROVIDER_RECOMMENDATION=PASS" : "MISSING_LIVE_MAPPING_RETAINS_ANCHOR=PASS") . "\n");
    fwrite(STDOUT, "PROACTIVE_CHAT_TURN_SMOKE=PASS\n");
} finally {
    if ($userId > 0) {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM cart WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM proactive_styling_state WHERE user_id=?')->execute([$userId]);
        if ($eventId !== '') {
            $pdo->prepare('DELETE FROM fashion_consumed_events WHERE event_id=?')->execute([$eventId]);
            $pdo->prepare('DELETE FROM fashion_event_outbox WHERE event_id=?')->execute([$eventId]);
        }
        if ($sessionId > 0) {
            $pdo->prepare('DELETE FROM chat_sessions WHERE id=?')->execute([$sessionId]);
        }
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        $pdo->commit();
    }
}
