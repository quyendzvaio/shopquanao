<?php
/**
 * OpenAI-compatible chat-completions provider.
 * DeepSeek is the historical default; compatible gateways can use the same
 * wire format through LLM_PROVIDER=openai_compatible or LLM_PROVIDER=litellm.
 */
require_once __DIR__ . '/LLMProvider.php';
require_once __DIR__ . '/StreamingLLMProvider.php';
require_once __DIR__ . '/LLMResponse.php';
require_once __DIR__ . '/../../../cache/Cache.php';

class DeepSeekProvider implements LLMProvider, StreamingLLMProvider {
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

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            // The PHP client expects one JSON response, not an SSE stream.
            'stream' => false,
        ];

        if (array_key_exists('temperature', $options)) {
            $body['temperature'] = max(0.0, min(2.0, (float) $options['temperature']));
        }
        if (array_key_exists('max_tokens', $options)) {
            $body['max_tokens'] = max(1, min(2000, (int) $options['max_tokens']));
        }

        if (!empty($tools)) {
            $body['tools'] = $tools;
            $body['tool_choice'] = $toolChoice;
        }

        $cached = Cache::getLlmResponse($body);
        if (is_array($cached)) {
            return $this->responseFromArray($cached);
        }

        $url = str_ends_with($this->baseUrl, '/v1')
            ? $this->baseUrl . '/chat/completions'
            : $this->baseUrl . '/v1/chat/completions';

        $ch = curl_init($url);
        $responseHeaders = [];
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
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return $length;
            },
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            $category = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'provider_unavailable';
            throw new LLMTransportException($category, "LLM request failed: $error");
        }

        $data = json_decode($raw, true);
        if ($httpCode === 429) {
            $retryAfter = isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])
                ? (int) $responseHeaders['retry-after']
                : null;
            throw new LLMTransportException('rate_limit', 'LLM rate limit exceeded (HTTP 429)', $retryAfter);
        }
        if ($httpCode >= 500) {
            throw new LLMTransportException('provider_unavailable', "LLM provider unavailable (HTTP {$httpCode})");
        }
        if (!$data || !isset($data['choices'][0])) {
            $errMsg = $data['error']['message'] ?? 'Unknown LLM error';
            throw new LLMTransportException('invalid_response', "LLM error (HTTP $httpCode): $errMsg");
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

    /**
     * OpenAI-compatible SSE streaming. This deliberately bypasses the
     * response cache: a streamed response must reflect the live provider and
     * invoke the callback as bytes arrive.
     */
    public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
        ];
        if (array_key_exists('temperature', $options)) {
            $body['temperature'] = max(0.0, min(2.0, (float) $options['temperature']));
        }
        if (array_key_exists('max_tokens', $options)) {
            $body['max_tokens'] = max(1, min(2000, (int) $options['max_tokens']));
        }

        $url = str_ends_with($this->baseUrl, '/v1')
            ? $this->baseUrl . '/chat/completions'
            : $this->baseUrl . '/v1/chat/completions';

        $buffer = '';
        $content = '';
        $finishReason = 'stop';
        $usage = null;
        $callbackError = null;

        $processLine = static function (string $line) use (&$content, &$finishReason, &$usage, &$callbackError, $onDelta): void {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) return;
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') return;
            $data = json_decode($payload, true);
            if (!is_array($data)) return;

            if (isset($data['error'])) {
                $message = is_array($data['error'])
                    ? (string)($data['error']['message'] ?? 'LLM stream error')
                    : (string)$data['error'];
                $callbackError = new LLMTransportException('invalid_response', $message);
                return;
            }
            if (isset($data['usage']) && is_array($data['usage'])) $usage = $data['usage'];
            $choice = is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
            if (array_key_exists('finish_reason', $choice) && $choice['finish_reason'] !== null) {
                $finishReason = (string)$choice['finish_reason'];
            }
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            $text = (string)($delta['content'] ?? '');
            if ($text === '') return;
            $content .= $text;
            try {
                $onDelta($text);
            } catch (Throwable $error) {
                $callbackError = $error;
            }
        };

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, $processLine, &$callbackError): int {
                if ($callbackError !== null) return 0;
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $processLine($line);
                    if ($callbackError !== null) return 0;
                }
                return strlen($chunk);
            },
        ]);

        $curlResult = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($buffer !== '' && $callbackError === null) $processLine($buffer);
        if ($callbackError instanceof Throwable) throw $callbackError;
        if ($curlResult === false) {
            $category = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'provider_unavailable';
            throw new LLMTransportException($category, "LLM stream failed: $error");
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new LLMTransportException('authentication_error', "LLM authentication failed (HTTP {$httpCode})");
        }
        if ($httpCode === 429) {
            throw new LLMTransportException('rate_limit', 'LLM rate limit exceeded (HTTP 429)');
        }
        if ($httpCode >= 500) {
            throw new LLMTransportException('provider_unavailable', "LLM provider unavailable (HTTP {$httpCode})");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new LLMTransportException('invalid_response', "LLM stream failed (HTTP {$httpCode})");
        }
        if ($content === '') {
            throw new LLMTransportException('invalid_response', 'LLM stream returned no text');
        }

        return new LLMResponse(content: $content, finishReason: $finishReason, usage: $usage);
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
