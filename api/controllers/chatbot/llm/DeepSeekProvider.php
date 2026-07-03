<?php
/**
 * DeepSeek LLM Provider
 * DeepSeek dùng OpenAI-compatible API format.
 * Base URL: https://api.deepseek.com
 */
require_once __DIR__ . '/LLMProvider.php';
require_once __DIR__ . '/LLMResponse.php';
require_once __DIR__ . '/../../../cache/Cache.php';

class DeepSeekProvider implements LLMProvider {
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.deepseek.com',
        string $model = 'deepseek-chat',
        int $timeout = 60
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->timeout = $timeout;
    }

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto'): LLMResponse {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = $toolChoice;
        }

        $cached = Cache::getLlmResponse($body);
        if (is_array($cached)) {
            return $this->responseFromArray($cached);
        }

        $url = $this->baseUrl . '/v1/chat/completions';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new RuntimeException("LLM request failed: $error");
        }

        $data = json_decode($raw, true);
        if (!$data || !isset($data['choices'][0])) {
            $errMsg = $data['error']['message'] ?? 'Unknown LLM error';
            throw new RuntimeException("LLM error (HTTP $httpCode): $errMsg");
        }

        $message = $data['choices'][0]['message'] ?? [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $args = [];
            if (isset($tc['function']['arguments'])) {
                $args = json_decode($tc['function']['arguments'], true) ?? [];
            }
            $toolCalls[] = new ToolCall(
                $tc['id'] ?? 'call_' . uniqid(),
                $tc['function']['name'] ?? '',
                $args
            );
        }

        $llmResponse = new LLMResponse(
            content: $message['content'] ?? '',
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            usage: $data['usage'] ?? null
        );
        Cache::setLlmResponse($body, $this->responseToArray($llmResponse));
        return $llmResponse;
    }

    private function responseToArray(LLMResponse $response): array {
        return [
            'content' => $response->content,
            'finish_reason' => $response->finishReason,
            'tool_calls' => array_map(fn($tc) => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments,
            ], $response->toolCalls),
            'usage' => $response->usage,
        ];
    }

    private function responseFromArray(array $data): LLMResponse {
        $toolCalls = [];
        foreach ($data['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                $tc['id'] ?? 'cached_' . uniqid(),
                $tc['name'] ?? '',
                is_array($tc['arguments'] ?? null) ? $tc['arguments'] : []
            );
        }
        return new LLMResponse(
            content: (string)($data['content'] ?? ''),
            finishReason: (string)($data['finish_reason'] ?? 'stop'),
            toolCalls: $toolCalls,
            usage: is_array($data['usage'] ?? null) ? $data['usage'] : null
        );
    }
}
