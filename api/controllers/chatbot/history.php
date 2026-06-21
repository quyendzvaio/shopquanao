<?php
/**
 * Chatbot History API
 * GET /api/chatbot/history
 * Bearer token required. Returns full chat history for logged-in user.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../middleware.php';

$user = authenticate();
$userId = (int)$user['id'];

global $pdo;

// Find latest active session for this user
$stmt = $pdo->prepare("
    SELECT id, session_token FROM chat_sessions
    WHERE user_id = ? AND status = 'active'
    ORDER BY updated_at DESC LIMIT 1
");
$stmt->execute([$userId]);
$session = $stmt->fetch();

if (!$session) {
    jsonResponse(['messages' => [], 'session_token' => null]);
}

$sessionId = (int)$session['id'];
$sessionToken = $session['session_token'];

// Get all messages for this session
$stmt = $pdo->prepare("
    SELECT id, role, message, metadata, created_at
    FROM chat_messages
    WHERE session_id = ?
    ORDER BY id ASC
");
$stmt->execute([$sessionId]);
$rows = $stmt->fetchAll();

$messages = [];
foreach ($rows as $r) {
    $msg = [
        'id' => (int)$r['id'],
        'role' => $r['role'],
        'message' => $r['message'],
        'created_at' => $r['created_at'],
        'products' => [],
    ];
    $meta = $r['metadata'] ? json_decode($r['metadata'], true) : [];
    if ($meta && isset($meta['products'])) {
        $msg['products'] = $meta['products'];
    }
    $messages[] = $msg;
}

jsonResponse([
    'messages' => $messages,
    'session_token' => $sessionToken,
    'session_id' => $sessionId,
]);
