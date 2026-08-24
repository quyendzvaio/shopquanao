<?php
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../config/db.php';
function demoPost(string $url, string $token, array $payload): array {
    $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n", 'content' => json_encode($payload, JSON_THROW_ON_ERROR), 'timeout' => 45]]);
    $raw = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d\d\s/', $statusLine)) throw new RuntimeException('HTTP request failed: ' . $statusLine);
    return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}
$suffix = gmdate('YmdHis') . '-' . getmypid(); $token = bin2hex(random_bytes(32)); $sessionToken = bin2hex(random_bytes(32)); $userId = 0; $sessionId = 0; $eventId = '';
try {
    $pdo->prepare('INSERT INTO users (username,email,password,api_token,status) VALUES (?,?,?,?,1)')->execute(['demo_uc2_' . $suffix, 'demo_uc2_' . $suffix . '@example.invalid', 'smoke-not-used', $token]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_sessions (user_id,session_token,status) VALUES (?,?,'active')")->execute([$userId, $sessionToken]);
    $sessionId = (int) $pdo->lastInsertId();
    $productId = (int) $pdo->query('SELECT id FROM products WHERE stock > 0 ORDER BY id LIMIT 1')->fetchColumn();
    demoPost('http://127.0.0.1/api/cart', $token, ['product_id' => $productId, 'quantity' => 1, 'size' => 'M']);
    $state = null;
    for ($i = 0; $i < 40; $i++) {
        $q = $pdo->prepare('SELECT pending_product_id,remaining_user_turns,eligible,source_event_id FROM proactive_styling_state WHERE user_id=? AND session_id=?'); $q->execute([$userId, (string) $sessionId]); $state = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($state !== null) break; usleep(250000);
    }
    if ($state === null || (int) $state['remaining_user_turns'] !== 2) throw new RuntimeException('pending state was not created');
    $eventId = (string) $state['source_event_id'];
    $first = demoPost('http://127.0.0.1/api/chatbot', $token, ['message' => 'Phí ship nội thành là bao nhiêu?', 'session_token' => $sessionToken]);
    if (($first['primary_intent'] ?? '') !== 'shipping') throw new RuntimeException('first turn intent was not shipping');
    $second = demoPost('http://127.0.0.1/api/chatbot', $token, ['message' => 'Tìm áo thun cho tôi', 'session_token' => $sessionToken]);
    $cards = array_values(array_filter($second['products'] ?? [], static fn (mixed $p): bool => is_array($p) && (int) ($p['id'] ?? 0) > 0));
    $after = $pdo->prepare('SELECT pending_product_id,remaining_user_turns,eligible,suggested_anchor_product_id FROM proactive_styling_state WHERE user_id=? AND session_id=?'); $after->execute([$userId, (string)$sessionId]);
    if (empty($second['proactive_styling']) || $cards === []) throw new RuntimeException('live demo proactive recommendation was not shown: ' . json_encode(['intent' => $second['primary_intent'] ?? null, 'products' => count($cards), 'state_after' => $after->fetch(PDO::FETCH_ASSOC), 'response_keys' => array_keys($second)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, 'UC2_LIVE_STATUS=PASS' . PHP_EOL . 'USER_TURNS=2' . PHP_EOL . 'PROACTIVE_PRODUCTS=' . count($cards) . PHP_EOL . 'SHOP_PRODUCT_IDS=' . json_encode(array_map(static fn (array $p): int => (int) $p['id'], $cards)) . PHP_EOL);
} finally {
    if ($userId > 0) {
        $pdo->beginTransaction(); $pdo->prepare('DELETE FROM cart WHERE user_id=?')->execute([$userId]); $pdo->prepare('DELETE FROM proactive_styling_state WHERE user_id=?')->execute([$userId]); if ($eventId !== '') { $pdo->prepare('DELETE FROM fashion_consumed_events WHERE event_id=?')->execute([$eventId]); $pdo->prepare('DELETE FROM fashion_event_outbox WHERE event_id=?')->execute([$eventId]); } $pdo->prepare('DELETE FROM chat_sessions WHERE id=?')->execute([$sessionId]); $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); $pdo->commit();
    }
}
