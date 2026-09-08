<?php
/**
 * Chatbot API endpoint
 * POST /api/chatbot
 * Body: { "message": "string", "session_token": "string" }
 *
 * Architecture: deterministic-first PHP pipeline (ChatbotService). PHP parses
 * intent, plans tools and verifies evidence; the optional LLM only enriches
 * unresolved descriptive entities. Turn tier (L0/L1) is reported in latency.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/ChatbotService.php';
require_once __DIR__ . '/ChatbotSessionContext.php';

/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$data = getJsonInput();
$message = trim($data['message'] ?? '');
$sessionToken = trim($data['session_token'] ?? '');

if (!$message) {
    errorResponse('Message is required', 400);
}

$context = ChatbotSessionContext::resolve($pdo, $sessionToken, getBearerToken());

// The PHP service persists messages and tool diagnostics while keeping
// the HTTP/auth/session boundary stable for the existing frontend.
$chatbot = new ChatbotService($pdo, $context->sessionId, $context->userId);
$result = $chatbot->respond($message);

$responseText = $result['message'];
$products = $result['products'] ?? [];
$knowledgeSources = $result['knowledge_sources'] ?? [];

$context->touch($pdo);

$response = [
    'message' => $responseText,
    'products' => $products,
    'knowledge_sources' => $knowledgeSources,
    'session_token' => $context->sessionToken,
    'session_id' => $context->sessionId,
];

foreach (['answer', 'response_type', 'primary_intent', 'secondary_intents', 'requested_fields', 'cards', 'missing_slots', 'trace_id', 'latency', 'proactive_styling', 'proactive_status', 'proactive_styling_metrics'] as $key) {
    if (array_key_exists($key, $result)) {
        $response[$key] = $result[$key];
    }
}

jsonResponse($response);
