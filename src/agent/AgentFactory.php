<?php

/** Builds the harness collaborators from one HarnessConfig; the only place that picks concrete classes. */
final class AgentFactory
{
    /** Middleware every config/harness.php must list; each declares REQUIRED = true. */
    public const REQUIRED_MIDDLEWARE = [AnswerTemplateFallback::class, CheckoutGuardrail::class];

    public function __construct(
        private HarnessConfig $config,
        private string $middlewareConfigPath = __DIR__ . '/../../config/harness.php',
    ) {
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

    /** The middleware listed in config/harness.php, in order; every REQUIRED_MIDDLEWARE class must be listed. */
    public function middlewareStack(PDO $pdo, int $sessionId): MiddlewareStack
    {
        $harness = require $this->middlewareConfigPath;
        $classes = array_map('strval', $harness['middleware'] ?? []);
        foreach (self::REQUIRED_MIDDLEWARE as $required) {
            if (!in_array($required, $classes, true)) {
                throw new LogicException('Required middleware missing: ' . $required);
            }
        }
        $middleware = [];
        foreach ($classes as $class) {
            $middleware[] = $this->makeMiddleware($class, $pdo, $sessionId);
        }
        return new MiddlewareStack($middleware);
    }

    /**
     * One turn's runner for a chat session. Every LLM is wrapped in the
     * middleware stack. An 'llm' override replaces both config-built LLMs:
     * null means "no LLM"; a provider is wrapped too, and also serves as the
     * streaming LLM when it streams.
     *
     * @param array{llm?:?LLMProvider,tool_gateway?:ChatbotToolGateway,memory?:ChatbotMemoryStore,conversation_store?:ChatbotConversationStore,response_generator?:ResponseGenerator} $overrides
     */
    public function turnRunner(PDO $pdo, int $sessionId, ?int $userId, array $overrides = []): TurnRunner
    {
        $middleware = $this->middlewareStack($pdo, $sessionId);
        if (array_key_exists('llm', $overrides)) {
            $override = $overrides['llm'];
            $llm = $override !== null ? new MiddlewareModel($override, $middleware) : null;
            $streamingLlm = $override instanceof StreamingLLMProvider ? $llm : null;
        } else {
            $llm = $this->llm($middleware);
            $streamingLlm = $this->streamingLlm($middleware);
        }
        $tools = $overrides['tool_gateway'] ?? $this->toolGateway($pdo, $userId);
        $memory = $overrides['memory'] ?? new ChatbotMemory($pdo, $sessionId, $userId);
        $conversations = $overrides['conversation_store'] ?? new PdoChatbotConversationStore($pdo, $sessionId);
        $responses = $overrides['response_generator'] ?? $this->responseGenerator();
        $memory->ensureSchema();

        return new TurnRunner(
            $tools,
            $memory,
            $conversations,
            $responses,
            $middleware,
            $llm,
            $streamingLlm,
            $this->config->templateResponse
        );
    }

    public function makeMiddleware(string $class, PDO $pdo, int $sessionId): Middleware
    {
        return match ($class) {
            CheckoutGuardrail::class => new CheckoutGuardrail($this->responseGenerator()),
            AnswerTemplateFallback::class => new AnswerTemplateFallback(),
            FreeShippingNudge::class => new FreeShippingNudge($pdo, $sessionId, $this->config->freeShipping),
            TraceOutbox::class => new TraceOutbox(new LangfuseTraceOutbox($pdo)),
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
