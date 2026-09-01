<?php
/**
 * POST /api/chatbot/stream
 * Chunked NDJSON transport consumed by the WebSocket gateway.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/ChatbotService.php';
require_once __DIR__ . '/ChatbotSessionContext.php';

/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$data = getJsonInput();
$message = trim((string)($data['message'] ?? ''));
$sessionToken = trim((string)($data['session_token'] ?? ''));
if ($message === '') errorResponse('Message is required', 400);

header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

$emit = static function (array $event): void {
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (ob_get_level() > 0) @ob_flush();
    flush();
};

try {
    $context = ChatbotSessionContext::resolve($pdo, $sessionToken, getBearerToken());
    $emit(['type' => 'chat.progress', 'stage' => 'pipeline']);
    $chatbot = new ChatbotService($pdo, $context->sessionId, $context->userId);
    $result = $chatbot->respondStreaming($message, static function (string $delta) use ($emit): void {
        $emit(['type' => 'chat.delta', 'delta' => $delta]);
    });

    $cards = is_array($result['products'] ?? null) ? $result['products'] : [];
    if ($cards !== []) $emit(['type' => 'chat.cards', 'products' => $cards]);
    $context->touch($pdo);
    $emit([
        'type' => 'chat.complete',
        'session_token' => $context->sessionToken,
        'session_id' => $context->sessionId,
        'response_type' => $result['response_type'] ?? 'final_answer',
        'primary_intent' => $result['primary_intent'] ?? 'unknown',
        'trace_id' => $result['trace_id'] ?? null,
        'proactive_styling' => (bool)($result['proactive_styling'] ?? false),
        'product_count' => count($cards),
        'latency' => is_array($result['latency'] ?? null) ? $result['latency'] : [],
    ]);
} catch (Throwable $error) {
    error_log(json_encode([
        'operation' => 'chatbot_stream',
        'success' => false,
        'error_category' => $error instanceof LLMTransportException ? $error->category : 'runtime_failure',
    ], JSON_UNESCAPED_SLASHES));
    $emit(['type' => 'chat.error', 'code' => 'STREAM_FAILED', 'message' => 'Không thể tạo câu trả lời dạng streaming, vui lòng thử lại.']);
}
