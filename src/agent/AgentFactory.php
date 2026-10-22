<?php

/** Builds the harness collaborators from one HarnessConfig; the only place that picks concrete classes. */
final class AgentFactory
{
    public function __construct(private HarnessConfig $config)
    {
    }

    public function config(): HarnessConfig
    {
        return $this->config;
    }

    /** The configured LLM, wrapped in MiddlewareModel when a stack is given. */
    public function llm(?MiddlewareStack $middleware = null): ?LLMProvider
    {
        $provider = $this->config->forceL0 ? null : $this->provider($this->config->llm);
        return $provider !== null && $middleware !== null ? new MiddlewareModel($provider, $middleware) : $provider;
    }

    /** The configured final-answer LLM if it streams, wrapped in MiddlewareModel when a stack is given. */
    public function streamingLlm(?MiddlewareStack $middleware = null): ?StreamingLLMProvider
    {
        if ($this->config->forceL0) {
            return null;
        }
        $provider = $this->provider($this->config->streamLlm);
        if (!$provider instanceof StreamingLLMProvider) {
            return null;
        }
        return $middleware !== null ? new MiddlewareModel($provider, $middleware) : $provider;
    }

    /** The middleware listed in config/harness.php, in order. */
    public function middlewareStack(PDO $pdo, int $sessionId): MiddlewareStack
    {
        $harness = require dirname(__DIR__, 2) . '/config/harness.php';
        $middleware = [];
        foreach ($harness['middleware'] ?? [] as $class) {
            $middleware[] = $this->makeMiddleware((string) $class, $pdo, $sessionId);
        }
        return new MiddlewareStack($middleware);
    }

    public function makeMiddleware(string $class, PDO $pdo, int $sessionId): Middleware
    {
        return match ($class) {
            default => throw new InvalidArgumentException('Unknown harness middleware: ' . $class),
        };
    }

    public function toolGateway(PDO $pdo, ?int $userId): ChatbotToolGateway
    {
        $transport = $this->config->toolTransport;
        if ($transport === 'internal') {
            return new ToolRegistry($pdo, $userId);
        }
        if ($transport === 'shadow') {
            return new ShadowToolGateway(new McpToolGateway($userId, $this->config->mcp), new ToolRegistry($pdo, $userId));
        }
        if ($transport !== 'mcp') {
            throw new InvalidArgumentException('CHATBOT_TOOL_TRANSPORT must be mcp, shadow, or internal');
        }
        return new McpToolGateway($userId, $this->config->mcp);
    }

    public function responseGenerator(): ResponseGenerator
    {
        return new ResponseGenerator($this->config->freeShipping);
    }

    /**
     * @param array<string,mixed>|null $config provider, api_key, base_url, model, timeout
     */
    private function provider(?array $config): ?LLMProvider
    {
        if ($config === null || !($config['api_key'] ?? '')) {
            return null;
        }
        return match (strtolower((string) ($config['provider'] ?? ''))) {
            'deepseek' => new DeepSeekProvider(
                apiKey: $config['api_key'],
                baseUrl: $config['base_url'] ?? 'https://api.deepseek.com',
                model: $config['model'] ?? 'deepseek-chat',
                timeout: (int) ($config['timeout'] ?? 60)
            ),
            'openai', 'openai_compatible', 'litellm', 'gemini' => new DeepSeekProvider(
                apiKey: $config['api_key'],
                baseUrl: $config['base_url'] ?? '',
                model: $config['model'] ?? '',
                timeout: (int) ($config['timeout'] ?? 60)
            ),
            default => null,
        };
    }
}
