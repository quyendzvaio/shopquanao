<?php

/**
 * Facade over one chat session. Each message is one turn answered by the
 * internal chat-graph service (ChatGraphClient): PHP keeps the data, the
 * session, the chat history and the memories, and sends chat-graph only a
 * whitelisted memory slice (ChatMemoryContext) and a short history window.
 * When chat-graph is unreachable, slow or malformed, the turn answers busy.
 * Controllers use open() / sessionToken() / touchSession().
 */
class ChatbotService
{
    public const BUSY_MESSAGE = 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.';
    private const FINGERPRINT_SLOT = 'freeship_nudged_fingerprint';
    private const MAX_MESSAGE_CHARS = 2000;

    private ?ChatGraphClient $graph;
    private PDO $pdo;
    private ?ChatbotSessionContext $context = null;

    public function __construct(
        PDO $pdo,
        private int $sessionId,
        private ?int $userId,
        ?ChatGraphClient $graph = null
    ) {
        $this->pdo = $pdo;
        $this->graph = $graph;
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

    /** Log category of a failed turn: the chat-graph transport category, otherwise runtime_failure. */
    public static function errorCategory(Throwable $e): string
    {
        return $e instanceof ChatGraphUnavailable ? $e->category : 'runtime_failure';
    }

    public function respond(string $message): array
    {
        return $this->turn($message, null);
    }

    /**
     * Same turn, with the answer streamed to $onDelta once chat-graph has finished its graph.
     *
     * @param callable(string):void $onDelta
     */
    public function respondStreaming(string $message, callable $onDelta): array
    {
        return $this->turn($message, $onDelta);
    }

    /** @param (callable(string):void)|null $onDelta */
    private function turn(string $message, ?callable $onDelta): array
    {
        $memory = new ChatbotMemory($this->pdo, $this->sessionId, $this->userId);
        $remembered = $memory->rememberUserMessage($message);
        $storedFingerprint = $remembered['slots'][self::FINGERPRINT_SLOT] ?? null;
        $fingerprint = is_string($storedFingerprint) && $storedFingerprint !== '' ? $storedFingerprint : null;
        $request = [
            'message' => mb_substr($message, 0, self::MAX_MESSAGE_CHARS),
            'user_id' => $this->userId !== null && $this->userId > 0 ? $this->userId : null,
            'session_id' => $this->sessionId,
            // Read before this turn is saved, so the window never contains the message being answered.
            'history' => ChatHistoryWindow::fromRows((new ChatConversationRepository($this->pdo))->messagesForSession($this->sessionId)),
            'memory' => ChatMemoryContext::build($remembered, $this->userId),
            'freeship_fingerprint' => $fingerprint,
            'stream' => $onDelta !== null,
        ];

        $sentText = false;
        $forwarding = $onDelta !== null;
        try {
            $result = $this->graph()->turn($request, $onDelta === null ? null : static function (array $event) use ($onDelta, &$sentText, &$forwarding): void {
                if (!$forwarding || ($event['type'] ?? '') !== 'delta' || !is_string($event['text'] ?? null) || $event['text'] === '') {
                    return;
                }
                try {
                    $sentText = true;
                    $onDelta($event['text']);
                } catch (Throwable) {
                    // The browser went away mid-stream: stop writing, but still finish and persist the turn.
                    $forwarding = false;
                }
            });
        } catch (Throwable $e) {
            error_log(json_encode([
                'operation' => 'chat_graph',
                'success' => false,
                'error_category' => $e instanceof ChatGraphUnavailable ? $e->category : 'runtime_failure',
            ], JSON_UNESCAPED_SLASHES));
            $result = self::busyResult();
            if ($forwarding && !$sentText) {
                try {
                    $onDelta($result['message']);
                } catch (Throwable) {
                    // Nothing left to write to; the busy answer is still saved below.
                }
            }
        }

        $cards = $result['products'];
        (new PdoChatbotConversationStore($this->pdo, $this->sessionId))->saveMessages(
            $message,
            $result['message'],
            $cards,
            [],
            [],
            ['response_type' => $result['response_type'], 'tools_used' => $result['tools_used'], 'offered' => $result['offered']]
        );
        $memory->refreshSummary();
        if ($result['freeship_fingerprint'] !== null && $result['freeship_fingerprint'] !== $fingerprint) {
            $memory->rememberSlot(self::FINGERPRINT_SLOT, $result['freeship_fingerprint']);
        }

        return [
            'message' => $result['message'],
            'answer' => $result['message'],
            'products' => $cards,
            'cards' => $cards,
            'response_type' => $result['response_type'],
            'primary_intent' => $result['primary_intent'],
            'tools_used' => $result['tools_used'],
            'outfits' => $result['outfits'],
            'offered' => $result['offered'],
            'freeship_nudge' => $result['freeship_nudge'],
            'trace_id' => $result['trace_id'],
            'latency' => $result['latency'],
        ];
    }

    /** @return array<string,mixed> a TurnResult for a turn chat-graph could not answer */
    private static function busyResult(): array
    {
        return [
            'message' => self::BUSY_MESSAGE,
            'products' => [],
            'response_type' => 'busy',
            'primary_intent' => 'unknown',
            'tools_used' => [],
            'outfits' => [],
            'offered' => [],
            'freeship_nudge' => false,
            'freeship_fingerprint' => null,
            'trace_id' => bin2hex(random_bytes(16)),
            'latency' => ['graph_unavailable' => true],
        ];
    }

    private function graph(): ChatGraphClient
    {
        if ($this->graph === null) {
            $config = HarnessConfig::fromEnvironment();
            $this->graph = new ChatGraphClient($config->chatGraphUrl, $config->chatGraphToken, $config->chatGraphTimeout);
        }

        return $this->graph;
    }

    private function context(): ChatbotSessionContext
    {
        return $this->context ?? throw new LogicException('ChatbotService was not opened with ChatbotService::open()');
    }
}
