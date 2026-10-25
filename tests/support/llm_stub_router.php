<?php
// PHP built-in-server router that stands in for an OpenAI-compatible LLM API.
// The response depends on the "model" field of the request body.

$body = json_decode((string) file_get_contents('php://input'), true);
$model = is_array($body) ? (string) ($body['model'] ?? '') : '';

switch ($model) {
    case 'ok':
        header('Content-Type: application/json');
        echo json_encode([
            'choices' => [['message' => ['content' => 'xin chào'], 'finish_reason' => 'stop']],
        ], JSON_UNESCAPED_UNICODE);
        break;
    case 'ratelimited':
        http_response_code(429);
        header('Retry-After: 7');
        header('Content-Type: application/json');
        echo '{"error":{"message":"slow down"}}';
        break;
    case 'down':
        http_response_code(503);
        header('Content-Type: application/json');
        echo '{"error":{"message":"down"}}';
        break;
    case 'stream':
        header('Content-Type: text/event-stream');
        echo 'data: {"choices":[{"delta":{"content":"Xin "}}]}' . "\n\n";
        echo 'data: {"choices":[{"delta":{"content":"chào"}}]}' . "\n\n";
        echo "data: [DONE]\n\n";
        break;
    default:
        http_response_code(400);
        echo '{"error":{"message":"unknown stub model"}}';
}
