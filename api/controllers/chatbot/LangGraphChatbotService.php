<?php

require_once __DIR__ . '/PdoChatbotConversationStore.php';

/** Thin authenticated adapter; all conversational decisions live in LangGraph. */
final class LangGraphChatbotService
{
    private PdoChatbotConversationStore $conversationStore;

    public function __construct(
        private PDO $pdo,
        private int $sessionId,
        private ?int $userId
    ) {
        $this->conversationStore = new PdoChatbotConversationStore($pdo, $sessionId);
    }

    public function respond(string $message): array
    {
        $result = $this->invoke($message);
        foreach (($result['tool_executions'] ?? []) as $execution) {
            if (!is_array($execution)) continue;
            $this->conversationStore->logToolExecution(
                (string)($execution['tool'] ?? 'unknown'),
                is_array($execution['arguments'] ?? null) ? $execution['arguments'] : [],
                $execution['result'] ?? ($execution['error'] ?? null),
                (int)($execution['duration_ms'] ?? 0),
                !empty($execution['success'])
            );
        }
        $this->conversationStore->saveMessages(
            $message,
            (string)$result['message'],
            is_array($result['products'] ?? null) ? $result['products'] : [],
            is_array($result['knowledge_sources'] ?? null) ? $result['knowledge_sources'] : [],
            [],
            ['pipeline' => 'langgraph', 'trace_id' => $result['trace_id'] ?? null]
        );
        unset($result['tool_executions']);
        return $result;
    }

    /** @param callable(string):void $onDelta */
    public function respondStreaming(string $message, callable $onDelta): array
    {
        $result = $this->respond($message);
        $onDelta((string)$result['message']);
        $result['latency']['streaming'] = false;
        return $result;
    }

    private function invoke(string $message): array
    {
        $url = rtrim((string)(getenv('CHATBOT_ORCHESTRATOR_URL') ?: 'http://agent-orchestrator:8092'), '/') . '/invoke';
        $token = (string)(getenv('AGENT_SERVICE_TOKEN') ?: getenv('MCP_SERVICE_TOKEN') ?: '');
        $payload = json_encode([
            'threadId' => (string)$this->sessionId,
            'userId' => $this->userId,
            'message' => $message,
            'authContext' => [
                'authenticated' => $this->userId !== null,
                'scopes' => $this->userId === null ? ['shop.read'] : ['shop.read', 'shop.write'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Agent-Service-Token: ' . $token],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => (int)(getenv('AGENT_REQUEST_TIMEOUT') ?: 90),
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('LangGraph orchestrator failed: ' . ($decoded['message'] ?? $error ?: "HTTP $status"));
        }
        foreach (['message', 'response_type', 'primary_intent', 'trace_id'] as $required) {
            if (!array_key_exists($required, $decoded)) throw new RuntimeException("Invalid LangGraph response: missing $required");
        }
        return $decoded;
    }
}
