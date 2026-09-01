<?php
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../config/db.php';

$smokeBaseUrl = rtrim((string) (getenv('SHOP_SMOKE_BASE_URL') ?: 'http://127.0.0.1'), '/');

$suffix = gmdate('YmdHis') . '-' . getmypid();
$username = 'event_smoke_' . $suffix;
$token = bin2hex(random_bytes(32));
$userId = 0;
$sessionId = 0;
$eventId = '';

try {
    $pdo->prepare('INSERT INTO users (username,email,password,api_token,status) VALUES (?,?,?,?,1)')
        ->execute([$username, $username . '@example.invalid', 'smoke-not-used', $token]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_sessions (user_id,session_token,status) VALUES (?,?,'active')")
        ->execute([$userId, bin2hex(random_bytes(32))]);
    $sessionId = (int) $pdo->lastInsertId();
    $productId = (int) $pdo->query('SELECT id FROM products WHERE stock > 0 ORDER BY id LIMIT 1')->fetchColumn();
    if ($productId <= 0) throw new RuntimeException('No in-stock shop product exists');

    $body = json_encode(['product_id' => $productId, 'quantity' => 1, 'size' => 'M'], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true,
        'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
        'content' => $body, 'timeout' => 10,
    ]]);
    file_get_contents($smokeBaseUrl . '/api/cart', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s201\s/', $statusLine)) throw new RuntimeException('HTTP add-to-cart did not return 201: ' . $statusLine);

    $state = null;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $stmt = $pdo->prepare('SELECT pending_product_id,remaining_user_turns,eligible,source_event_id FROM proactive_styling_state WHERE user_id=? AND session_id=?');
        $stmt->execute([$userId, (string) $sessionId]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($state !== null) break;
        usleep(250000);
    }
    if ($state === null) throw new RuntimeException('Event was not consumed into proactive styling state');
    $eventId = (string) $state['source_event_id'];
    if ((int) $state['pending_product_id'] !== $productId || (int) $state['remaining_user_turns'] !== 2 || (int) $state['eligible'] !== 1) {
        throw new RuntimeException('Consumed proactive state is invalid');
    }
    $published = $pdo->prepare("SELECT COUNT(*) FROM fashion_event_outbox WHERE event_id=? AND status='published'");
    $published->execute([$eventId]);
    if ((int) $published->fetchColumn() !== 1) throw new RuntimeException('Outbox event was not marked published');

    $payloadQuery = $pdo->prepare('SELECT payload FROM fashion_event_outbox WHERE event_id=?');
    $payloadQuery->execute([$eventId]);
    $payload = (string) $payloadQuery->fetchColumn();
    if ($payload === '') throw new RuntimeException('Published outbox payload is unavailable for duplicate-delivery verification');
    if (!class_exists('Redis')) throw new RuntimeException('PHP Redis extension is unavailable');
    $redis = new Redis();
    $redis->connect((string) (getenv('REDIS_HOST') ?: 'redis'), (int) (getenv('REDIS_PORT') ?: 6379), (float) (getenv('REDIS_TIMEOUT') ?: 1));
    $stream = (string) (getenv('FASHION_EVENT_STREAM') ?: 'fashion:events');
    $duplicateStreamId = $redis->xAdd($stream, '*', [
        'event_id' => $eventId,
        'event_type' => 'cart.item_added',
        'payload' => $payload,
    ]);
    if (!is_string($duplicateStreamId) || $duplicateStreamId === '') throw new RuntimeException('Duplicate event was not delivered to Redis Stream');
    usleep(750000);
    $consumed = $pdo->prepare('SELECT COUNT(*) FROM fashion_consumed_events WHERE consumer_name=? AND event_id=?');
    $consumed->execute([(string) (getenv('FASHION_EVENT_GROUP') ?: 'proactive-styling'), $eventId]);
    if ((int) $consumed->fetchColumn() !== 1) throw new RuntimeException('Duplicate event created more than one logical consumption record');
    $duplicateState = $pdo->prepare('SELECT pending_product_id,remaining_user_turns,source_event_id FROM proactive_styling_state WHERE user_id=? AND session_id=?');
    $duplicateState->execute([$userId, (string) $sessionId]);
    $duplicateState = $duplicateState->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int) ($duplicateState['pending_product_id'] ?? 0) !== $productId
        || (int) ($duplicateState['remaining_user_turns'] ?? -1) !== 2
        || (string) ($duplicateState['source_event_id'] ?? '') !== $eventId
    ) throw new RuntimeException('Duplicate delivery changed the logical pending state');

    fwrite(STDOUT, "HTTP_CART_ADD=PASS\nTRANSACTIONAL_OUTBOX=PASS\nREDIS_STREAM_DELIVERY=PASS\nAGENT_EVENT_CONSUMER=PASS\nPENDING_TURNS=2\nDUPLICATE_DELIVERY_IDEMPOTENCY=PASS\nCART_EVENT_PIPELINE_STATUS=PASS\n");
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
