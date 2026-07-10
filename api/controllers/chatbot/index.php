<?php
/**
 * Chatbot API endpoint
 * POST /api/chatbot
 * Body: { "message": "string", "session_token": "string" }
 *
 * Architecture:
 * 1. Nếu LLM provider được cấu hình → AgenticOrchestrator (LLM + tools) - tự lưu messages
 * 2. Nếu không → Fallback về rule-based engine - tự lưu messages
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/AgenticOrchestrator.php';

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

// Get user ID from Bearer token if provided
$userId = null;
$token = getBearerToken();
if ($token) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    $userId = $user ? (int)$user['id'] : null;
}

// Create or resume session
if ($userId) {
    // Logged-in: always use or create ONE session per user
    $stmt = $pdo->prepare("SELECT id, session_token FROM chat_sessions WHERE user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $sessionId = (int)$existing['id'];
        $sessionToken = $existing['session_token'];
    } else {
        $sessionToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO chat_sessions (user_id, session_token) VALUES (?, ?)");
        $stmt->execute([$userId, $sessionToken]);
        $sessionId = (int)$pdo->lastInsertId();
    }
} elseif ($sessionToken) {
    // Anonymous user with existing token
    $stmt = $pdo->prepare("SELECT id FROM chat_sessions WHERE session_token = ? AND status = 'active'");
    $stmt->execute([$sessionToken]);
    $row = $stmt->fetch();
    if ($row) {
        $sessionId = (int)$row['id'];
    } else {
        $sessionToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO chat_sessions (user_id, session_token) VALUES (NULL, ?)");
        $stmt->execute([$sessionToken]);
        $sessionId = (int)$pdo->lastInsertId();
    }
} else {
    // New anonymous user
    $sessionToken = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO chat_sessions (user_id, session_token) VALUES (NULL, ?)");
    $stmt->execute([$sessionToken]);
    $sessionId = (int)$pdo->lastInsertId();
}

// Update session user_id if logged in (for sessions created before login)
if ($userId) {
    $pdo->prepare("UPDATE chat_sessions SET user_id = ? WHERE id = ? AND user_id IS NULL")
        ->execute([$userId, $sessionId]);
}

// Get response via orchestrator (AgenticOrchestrator tự lưu messages vào DB)
$orchestrator = new AgenticOrchestrator($pdo, $sessionId, $userId);
$result = $orchestrator->respond($message);

$responseText = $result['message'];
$products = $result['products'] ?? [];
$knowledgeSources = $result['knowledge_sources'] ?? [];

// Update session timestamp
$pdo->prepare("UPDATE chat_sessions SET updated_at = NOW(), user_id = COALESCE(?, user_id) WHERE id = ?")
    ->execute([$userId, $sessionId]);

jsonResponse([
    'message' => $responseText,
    'products' => $products,
    'knowledge_sources' => $knowledgeSources,
    'session_token' => $sessionToken,
    'session_id' => $sessionId,
]);
