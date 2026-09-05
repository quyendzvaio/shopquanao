<?php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../services/ToolApplicationService.php';
require_once __DIR__ . '/../chatbot/ToolRegistry.php';
require_once __DIR__ . '/../../services/Fashion/ProactiveStylingStateMachine.php';
require_once __DIR__ . '/../../services/Fashion/ProactiveStylingStateStore.php';
require_once __DIR__ . '/../../services/Fashion/ProactiveChatTurnService.php';

/** @var PDO $pdo */

$expectedToken = (string) (getenv('MCP_SERVICE_TOKEN') ?: '');
$providedToken = (string) ($_SERVER['HTTP_X_MCP_SERVICE_TOKEN'] ?? '');
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    errorResponse('Forbidden', 403);
}

$data = getJsonInput();
$operation = (string) ($data['operation'] ?? 'tool.call');
try {
    if ($operation === 'agent.proactive_turn') {
        $userId = isset($data['user_id']) && (int)$data['user_id'] > 0 ? (int)$data['user_id'] : null;
        if ($userId === null) errorResponse('Authentication required', 401);
        $sessionId = trim((string)($data['session_id'] ?? ''));
        if ($sessionId === '') errorResponse('session_id is required', 400);
        $service = new ProactiveChatTurnService(
            new ProactiveStylingStateStore($pdo),
            new ProactiveStylingStateMachine(),
            new ToolRegistry($pdo, $userId)
        );
        jsonResponse($service->handle($userId, $sessionId, (string)($data['primary_intent'] ?? 'unknown')));
    }
    if ($operation !== 'tool.call') {
        errorResponse('Unknown internal operation', 400);
    }

    $tool = (string) ($data['tool'] ?? '');
    $arguments = is_array($data['arguments'] ?? null) ? $data['arguments'] : [];
    $userId = isset($data['user_id']) && (int) $data['user_id'] > 0 ? (int) $data['user_id'] : null;
    $result = (new ToolApplicationService($pdo, $userId))->execute($tool, $arguments);
    jsonResponse(['result' => $result]);
} catch (InvalidArgumentException $error) {
    errorResponse($error->getMessage(), 400);
} catch (RuntimeException $error) {
    $status = str_contains(strtolower($error->getMessage()), 'auth') ? 401 : 409;
    errorResponse($error->getMessage(), $status);
} catch (Throwable $error) {
    error_log('Internal MCP error: ' . $error->getMessage());
    errorResponse('Internal MCP operation failed', 500);
}
