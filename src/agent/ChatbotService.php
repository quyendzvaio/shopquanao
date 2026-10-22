<?php

/**
 * Facade over one chat session: builds a TurnRunner from the environment
 * (AgentFactory) and runs each message as one turn, streamed or not.
 */
class ChatbotService
{
    private TurnRunner $runner;

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
        // An explicitly passed $llm (even null) disables the environment LLMs and is used unwrapped.
        if (func_num_args() >= 4) {
            $overrides['llm'] = $llm;
        }
        $this->runner = (new AgentFactory(HarnessConfig::fromEnvironment()))->turnRunner($pdo, $sessionId, $userId, $overrides);
    }

    public function respond(string $message): array
    {
        return $this->runner->run(new TurnContext($message, $this->sessionId, $this->userId, [], null))->toArray();
    }

    /**
     * Same turn, with the answer streamed to $onDelta; afterTurn deltas (the nudge) follow it.
     *
     * @param callable(string):void $onDelta
     */
    public function respondStreaming(string $message, callable $onDelta): array
    {
        return $this->runner->run(
            new TurnContext($message, $this->sessionId, $this->userId, [], Closure::fromCallable($onDelta))
        )->toArray();
    }
}
