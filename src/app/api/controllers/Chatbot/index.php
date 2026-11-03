<?php
/**
 * Chatbot API endpoint
 * POST /api/chatbot
 * Body: { "message": "string", "session_token": "string" }
 *
 * Architecture: ChatbotService sends the turn to the internal chat-graph service
 * (LangGraph agent with read-only shop tools) and persists the messages and memory.
 * This is the widget's fallback when /api/chatbot/stream fails before any text.
 */


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
$rejection = ChatStreamProtocol::rejectBrowserRequest($message, $_SERVER, AppConfig::fromEnvironment());
if ($rejection !== null) errorResponse($rejection['message'], $rejection['status']);

// The PHP service persists messages and memory while keeping
// the HTTP/auth/session boundary stable for the existing frontend.
$chatbot = ChatbotService::open($pdo, $sessionToken, getBearerToken());
$result = $chatbot->respond($message);

$chatbot->touchSession();

$cards = ChatStreamProtocol::cards(is_array($result['products'] ?? null) ? $result['products'] : []);
$response = [
    'message' => $result['message'],
    'answer' => $result['answer'] ?? $result['message'],
    'products' => $cards,
    'cards' => $cards,
    'session_token' => $chatbot->sessionToken(),
    'session_id' => $chatbot->sessionId(),
];

foreach (['response_type', 'primary_intent', 'tools_used', 'outfits', 'offered', 'freeship_nudge', 'trace_id', 'latency', 'ticket_pending', 'ticket_id'] as $key) {
    if (array_key_exists($key, $result)) {
        $response[$key] = $result[$key];
    }
}

jsonResponse($response);
