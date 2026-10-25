<?php

/**
 * Facade over one chat session: builds a TurnRunner from the environment
 * (AgentFactory) and runs each message as one turn, streamed or not.
 * Controllers use open() / sessionToken() / touchSession(), so the session
 * seam (ChatbotSessionContext) and the harness classes stay behind it.
 */
class ChatbotService
{
    private ?TurnRunner $runner = null;
    /** @var Closure(): TurnRunner builds the runner on the first turn */
    private Closure $buildRunner;
    private PDO $pdo;
    private ?ChatbotSessionContext $context = null;

    public function __construct(
        PDO $pdo,
        private int $sessionId,
        private ?int $userId,
        ?LLMProvider $llm = null,
        ?ChatbotToolGateway $toolGateway = null,
        ?ChatbotMemoryStore $memory = null,
        ?ChatbotConversationStore $conversationStore = null,
        ?ResponseGenerator $responseGenerator = null
    ) {
        $overrides = array_filter([
            'tool_gateway' => $toolGateway,
            'memory' => $memory,
            'conversation_store' => $conversationStore,
            'response_generator' => $responseGenerator,
        ], static fn ($collaborator): bool => $collaborator !== null);
        // An explicitly passed $llm (even null) replaces the environment LLMs; a provider is still wrapped in the middleware stack.
        if (func_num_args() >= 4) {
            $overrides['llm'] = $llm;
        }
        $this->pdo = $pdo;
        $factory = new AgentFactory(HarnessConfig::fromEnvironment());
        // Built on the first turn, not here: the stream endpoint reports progress between opening the session and assembling the harness.
        $this->buildRunner = static fn (): TurnRunner => $factory->turnRunner($pdo, $sessionId, $userId, $overrides);
    }

    /** Resolves the durable chat session (bearer user first, then the guest token, else a new one) and opens the service on it. */
    public static function open(PDO $pdo, ?string $sessionToken, ?string $bearerToken): self
    {
        $context = ChatbotSessionContext::resolve($pdo, $sessionToken ?? '', $bearerToken);
        $service = new self($pdo, $context->sessionId, $context->userId);
        $service->context = $context;
        return $service;
    }

    public function sessionToken(): string
    {
        return $this->context()->sessionToken;
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }

    /** End-of-request touch: attaches a guest session to the logged-in user and bumps updated_at. */
    public function touchSession(): void
    {
        $this->context()->touch($this->pdo);
    }

    /** Log category of a failed turn: the transport category for LLM failures, otherwise runtime_failure. */
    public static function errorCategory(Throwable $e): string
    {
        return $e instanceof LLMTransportException ? $e->category : 'runtime_failure';
    }

    public function respond(string $message): array
    {
        return $this->runner()->run(new TurnContext($message, $this->sessionId, $this->userId, [], null))->toArray();
    }

    /**
     * Same turn, with the answer streamed to $onDelta; afterTurn deltas (the nudge) follow it.
     *
     * @param callable(string):void $onDelta
     */
    public function respondStreaming(string $message, callable $onDelta): array
    {
        return $this->runner()->run(
            new TurnContext($message, $this->sessionId, $this->userId, [], Closure::fromCallable($onDelta))
        )->toArray();
    }

    private function runner(): TurnRunner
    {
        return $this->runner ??= ($this->buildRunner)();
    }

    private function context(): ChatbotSessionContext
    {
        return $this->context ?? throw new LogicException('ChatbotService was not opened with ChatbotService::open()');
    }
}
