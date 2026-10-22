<?php

/**
 * Every environment-derived setting of the chatbot harness, read once.
 * PHP-FPM may run with clear_env, so each key falls back getenv -> $_ENV -> $_SERVER.
 */
final readonly class HarnessConfig
{
    /**
     * @param array<string,mixed>|null $llm       provider config for the main LLM, or null when none is configured
     * @param array<string,mixed>|null $streamLlm provider config for the final-answer (streaming) LLM
     * @param array<string,mixed> $mcp            service_token, timeout_seconds, node, script, path, shop_internal_url, request_timeout_ms
     */
    public function __construct(
        public string $toolTransport = 'mcp',
        public bool $forceL0 = false,
        public bool $templateResponse = false,
        public ?array $llm = null,
        public ?array $streamLlm = null,
        public array $mcp = [],
        public FreeShippingPolicy $freeShipping = new FreeShippingPolicy(),
    ) {
    }

    public static function fromEnvironment(): self
    {
        $isTest = strtolower(self::env('APP_ENV')) === 'test';
        $provider = self::env('LLM_PROVIDER');
        $apiKey = self::env('LLM_API_KEY');
        $baseUrl = self::env('LLM_BASE_URL') ?: 'https://api.deepseek.com';
        $timeout = (int) (self::env('LLM_TIMEOUT') ?: 60);
        $model = self::env('LLM_MODEL') ?: 'deepseek-chat';

        return new self(
            toolTransport: strtolower(self::env('CHATBOT_TOOL_TRANSPORT') ?: ($isTest ? 'internal' : 'mcp')),
            forceL0: self::flag('CHATBOT_FORCE_L0'),
            templateResponse: self::flag('CHATBOT_TEMPLATE_RESPONSE'),
            llm: $provider === '' ? null : [
                'provider' => $provider,
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'model' => $model,
                'timeout' => $timeout,
            ],
            streamLlm: $provider === '' ? null : [
                'provider' => $provider,
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'model' => self::env('LLM_STREAM_MODEL') ?: $model,
                'timeout' => $timeout,
            ],
            mcp: [
                'service_token' => self::env('MCP_SERVICE_TOKEN'),
                'timeout_seconds' => max(1, (int) (self::env('MCP_REQUEST_TIMEOUT') ?: 35)),
                'node' => self::env('MCP_STDIO_NODE') ?: '/usr/bin/node',
                'script' => self::env('MCP_STDIO_SCRIPT') ?: '/opt/mcp-server/dist/stdio.js',
                'path' => self::env('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                'shop_internal_url' => self::env('SHOP_INTERNAL_URL') ?: 'http://127.0.0.1/api/internal/mcp',
                'request_timeout_ms' => self::env('MCP_REQUEST_TIMEOUT_MS') ?: '30000',
            ],
            freeShipping: FreeShippingPolicy::fromEnvironment(),
        );
    }

    private static function env(string $key): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return (string) ($_ENV[$key] ?? $_SERVER[$key] ?? '');
    }

    private static function flag(string $key): bool
    {
        $value = self::env($key);
        return $value === '1' || strtolower($value) === 'true';
    }
}
