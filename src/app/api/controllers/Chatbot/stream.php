<?php
/**
 * POST /api/chatbot/stream
 * Server-Sent Events consumed directly by the storefront widget (fetch +
 * ReadableStream; EventSource cannot POST). chat-graph sends the agent's answer sentence by
 * sentence while it is written, each sentence already checked (chat.delta), and chat.replace
 * when the final checked answer differs from what was sent; cards and metadata follow as
 * whole events.
 */


/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$data = getJsonInput();
$message = trim((string)($data['message'] ?? ''));
$sessionToken = trim((string)($data['session_token'] ?? ''));
if ($message === '') errorResponse('Message is required', 400);
$rejection = ChatStreamProtocol::rejectBrowserRequest($message, $_SERVER, AppConfig::fromEnvironment());
if ($rejection !== null) errorResponse($rejection['message'], $rejection['status']);

// A dropped browser must not kill PHP mid-turn: the turn is still saved (messages, summary, fingerprint).
ignore_user_abort(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

$emit = static function (array $event): void {
    echo ChatStreamProtocol::event($event);
    if (ob_get_level() > 0) @ob_flush();
    flush();
};

try {
    // open() resolves the session only; the chat-graph client is built on the first turn, after this progress event.
    $chatbot = ChatbotService::open($pdo, $sessionToken, getBearerToken());
    $emit(['type' => 'chat.progress', 'stage' => 'pipeline']);
    $result = $chatbot->respondStreaming($message, static function (string $text, string $kind) use ($emit): void {
        $emit(ChatStreamProtocol::textEvent($kind, $text));
    });

    $cards = ChatStreamProtocol::cards(is_array($result['products'] ?? null) ? $result['products'] : []);
    if ($cards !== []) $emit(['type' => 'chat.cards', 'products' => $cards]);
    $chatbot->touchSession();
    $emit([
        'type' => 'chat.complete',
        'session_token' => $chatbot->sessionToken(),
        'session_id' => $chatbot->sessionId(),
        'response_type' => $result['response_type'] ?? 'final_answer',
        'primary_intent' => $result['primary_intent'] ?? 'unknown',
        'trace_id' => $result['trace_id'] ?? null,
        'outfits' => $result['outfits'] ?? [],
        'freeship_nudge' => (bool)($result['freeship_nudge'] ?? false),
        'ticket_pending' => (bool)($result['ticket_pending'] ?? false),
        'ticket_id' => is_int($result['ticket_id'] ?? null) ? $result['ticket_id'] : null,
        'product_count' => count($cards),
        'latency' => array_filter(
            is_array($result['latency'] ?? null) ? $result['latency'] : [],
            static fn ($value): bool => is_int($value) || is_float($value) || is_bool($value)
        ),
    ]);
} catch (Throwable $error) {
    error_log(json_encode([
        'operation' => 'chatbot_stream',
        'success' => false,
        'error_category' => ChatbotService::errorCategory($error),
    ], JSON_UNESCAPED_SLASHES));
    $emit(['type' => 'chat.error', 'code' => 'STREAM_FAILED', 'message' => 'Không thể tạo câu trả lời dạng streaming, vui lòng thử lại.']);
}
