<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/api/controllers/chatbot/llm/LLMFactory.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== Chatbot Diagnostic ===\n\n";

$llm = LLMFactory::fromEnv();
if ($llm) {
    echo "LLM configured: " . get_class($llm) . "\n";
    try {
        $resp = $llm->chat([['role' => 'user', 'content' => 'Say hello in 3 words']]);
        echo "LLM response: " . $resp->content . "\n";
        echo "Finish reason: " . $resp->finishReason . "\n";
    } catch (Throwable $e) {
        echo "LLM call failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "LLM NOT configured\n";
    echo "LLM_PROVIDER: " . (getenv('LLM_PROVIDER') ?: 'MISSING') . "\n";
    echo "LLM_API_KEY set: " . (getenv('LLM_API_KEY') ? 'YES' : 'NO') . "\n";
    echo "LLM_BASE_URL: " . (getenv('LLM_BASE_URL') ?: 'MISSING') . "\n";
    echo "LLM_MODEL: " . (getenv('LLM_MODEL') ?: 'MISSING') . "\n";
}
