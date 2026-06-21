<?php
/**
 * DeepSeek LLM Provider
 * DeepSeek dùng OpenAI-compatible API format.
 * Base URL: https://api.deepseek.com
 */
require_once __DIR__ . '/LLMProvider.php';
require_once __DIR__ . '/LLMResponse.php';

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

        return new LLMResponse(
            content: $message['content'] ?? '',
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            usage: $data['usage'] ?? null
        );
    }
}
