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
    public const TICKET_CREATED = 'Đã gửi yêu cầu #%d cho shop. Shop sẽ xử lý và cập nhật trạng thái; bạn có thể hỏi mình hoặc xem ở trang tài khoản.';
    public const TICKET_DECLINED = 'Mình đã huỷ yêu cầu, chưa gửi gì cho shop.';
    public const TICKET_REFUSED = 'Chưa gửi được yêu cầu: %s';
    public const TICKET_EXPIRED = 'Yêu cầu soạn trước đó đã hết hạn nên mình chưa gửi gì cho shop. Bạn nhắn lại yêu cầu để mình soạn lại nhé.';
    public const TICKET_FAILED = 'Chưa gửi được yêu cầu do lỗi hệ thống. Bạn nhắn lại yêu cầu để mình soạn lại nhé.';
    private const MAX_MESSAGE_CHARS = 2000;

    private ?ChatGraphClient $graph;
    private PDO $pdo;
    private ?ChatbotSessionContext $context = null;
    /** @var Closure():int unix seconds; injectable so draft expiry is testable */
    private Closure $now;
    /** True for the current turn when the answer is streamed (never cached). */
    private bool $turnWasStreaming = false;
    /** Turn-level cache for guest turns (never breaks a turn: all reads/writes are guarded). */
    private PromptCache $promptCache;

    public function __construct(
        PDO $pdo,
        private int $sessionId,
        private ?int $userId,
        ?ChatGraphClient $graph = null,
        ?Closure $now = null,
        ?PromptCache $promptCache = null
    ) {
        $this->pdo = $pdo;
        $this->graph = $graph;
        $this->now = $now ?? static fn (): int => time();
        $this->promptCache = $promptCache ?? new PromptCache();
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
     * Same turn, with the answer streamed to $onText: chat-graph sends each checked sentence of the agent's answer
     * while it is written ('delta'), and 'replace' when the final checked answer differs from what was sent.
     *
     * @param callable(string, string):void $onText ($text, 'delta' | 'replace')
     */
    public function respondStreaming(string $message, callable $onText): array
    {
        return $this->turn($message, $onText);
    }

    /** @param (callable(string, string):void)|null $onText */
    private function turn(string $message, ?callable $onText): array
    {
        $this->turnWasStreaming = $onText !== null;
        $memory = new ChatbotMemory($this->pdo, $this->sessionId, $this->userId);
        $confirmation = $this->answerTicketConfirmation($memory, $message, $onText);
        if ($confirmation !== null) {
            return $confirmation;
        }
        $remembered = $memory->rememberUserMessage($message);
        $storedFingerprint = $remembered['slots'][self::FINGERPRINT_SLOT] ?? null;
        $fingerprint = is_string($storedFingerprint) && $storedFingerprint !== '' ? $storedFingerprint : null;

        // ── Turn-level cache (prompt-cache plan 2.3) ─────────────────────────
        // Only guest turns are cached: no orders, tickets or carts to leak, and
        // the turn depends only on message + history + memory + catalog state.
        // Streaming and live ticket confirmations always run the graph. A hit
        // still saves the conversation row and refreshes the summary, so the
        // session history stays exactly as if the graph had answered.
        $cacheKey = $this->cacheEligible($memory) ? $this->promptCache->keyFor(
            $message,
            $this->historyWindow(),
            (string) ($remembered['summary'] ?? ''),
            is_array($remembered['slots'] ?? null) ? $remembered['slots'] : []
        ) : null;
        $cached = $cacheKey !== null ? $this->promptCache->get($cacheKey) : null;
        if (is_array($cached)) {
            // Same persistence as the graph path, so a hit leaves the session exactly as a real turn would.
            (new PdoChatbotConversationStore($this->pdo, $this->sessionId))->saveMessages(
                $message,
                (string) $cached['message'],
                is_array($cached['products'] ?? null) ? $cached['products'] : [],
                [],
                [],
                [
                    'response_type' => $cached['response_type'] ?? 'final_answer',
                    'tools_used' => is_array($cached['tools_used'] ?? null) ? $cached['tools_used'] : [],
                    'offered' => is_array($cached['offered'] ?? null) ? $cached['offered'] : [],
                ]
            );
            $memory->refreshSummary();
            $cached['latency'] = ['prompt_cache' => 'hit'] + (is_array($cached['latency'] ?? null) ? $cached['latency'] : []);
            error_log(json_encode(['operation' => 'prompt_cache', 'success' => true, 'hit' => true], JSON_UNESCAPED_SLASHES));
            return $cached;
        }

        $request = [
            'message' => mb_substr($message, 0, self::MAX_MESSAGE_CHARS),
            'user_id' => $this->userId !== null && $this->userId > 0 ? $this->userId : null,
            'session_id' => $this->sessionId,
            // Read before this turn is saved, so the window never contains the message being answered.
            'history' => ChatHistoryWindow::fromRows((new ChatConversationRepository($this->pdo))->messagesForSession($this->sessionId)),
            'memory' => ChatMemoryContext::build($remembered, $this->userId),
            'freeship_fingerprint' => $fingerprint,
            'stream' => $onText !== null,
        ];

        $sentText = false;
        $forwarding = $onText !== null;
        try {
            $result = $this->graph()->turn($request, $onText === null ? null : static function (array $event) use ($onText, &$sentText, &$forwarding): void {
                $type = $event['type'] ?? '';
                if (!$forwarding || ($type !== 'delta' && $type !== 'replace') || !is_string($event['text'] ?? null) || ($type === 'delta' && $event['text'] === '')) {
                    return;
                }
                try {
                    $sentText = true;
                    $onText($event['text'], $type);
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
            if ($forwarding) {
                try {
                    // Text already on screen is replaced, so the customer sees exactly the busy answer that is saved.
                    $onText($result['message'], $sentText ? 'replace' : 'delta');
                } catch (Throwable) {
                    // Nothing left to write to; the busy answer is still saved below.
                }
            }
        }

        // The draft is stored before the turn is saved: if it cannot be stored, "đồng ý" would find nothing, so the
        // customer must not be asked to confirm it.
        $ticketPending = false;
        if ($result['pending_ticket'] !== null && $this->userId !== null && $this->userId > 0) {
            $stored = $memory->rememberSlot(PendingTicketSlot::KEY, json_encode($result['pending_ticket'] + [
                'user_id' => $this->userId,
                'expires_at' => ($this->now)() + PendingTicketSlot::TTL,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($stored) {
                $ticketPending = true;
            } else {
                $result['message'] = self::TICKET_FAILED;
                $result['response_type'] = 'final_answer';
                if ($forwarding) {
                    try {
                        // The draft question was already streamed: replace it, so the screen shows the saved text.
                        $onText(self::TICKET_FAILED, 'replace');
                    } catch (Throwable) {
                        // The browser went away; the corrected answer is still saved below.
                    }
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

        $final = [
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
            'ticket_pending' => $ticketPending,
            'ticket_id' => null,
        ];
        $this->storeCachedTurn($memory, $message, $cacheKey ?? '', $final);
        return $final;
    }

    /**
     * Turn-level cache eligibility for guest turns (prompt-cache plan 2.3).
     * All required: guest session (no user_id), no streaming, no live ticket
     * draft in the slots. Logged-in turns see orders, tickets, carts: never
     * cached. Streamed answers must come from the graph, sentence by sentence.
     */
    private function cacheEligible(ChatbotMemory $memory): bool
    {
        if ($this->userId !== null || $this->turnWasStreaming || !$this->promptCache->enabled()) {
            return false;
        }
        $slots = $memory->load()['slots'] ?? [];
        return !PendingTicketSlot::isLive($slots[PendingTicketSlot::KEY] ?? null, $this->userId, ($this->now)());
    }

    /** The history window the request carries, identical to the one built for chat-graph. */
    private function historyWindow(): array
    {
        return ChatHistoryWindow::fromRows((new ChatConversationRepository($this->pdo))->messagesForSession($this->sessionId));
    }

    /**
     * Stores a finished guest turn in the prompt cache (same eligibility as
     * cacheEligible). Final answers are cached, including text-only ones (FAQ,
     * size advice, shop policy): those are the most repeated questions, so they
     * are where the cache saves the most. A busy, refusal or clarification turn
     * is never stored, and a nudged turn is left out because the nudge depends
     * on the cart of the moment. Price/stock drift is bounded by PROMPT_CACHE_TTL.
     */
    private function storeCachedTurn(ChatbotMemory $memory, string $message, string $cacheKey, array $result): void
    {
        if ($this->userId !== null || $this->turnWasStreaming || $cacheKey === '') {
            return;
        }
        if (($result['response_type'] ?? '') !== 'final_answer' || ($result['freeship_nudge'] ?? true) === true) {
            return;
        }
        if (($result['freeship_fingerprint'] ?? null) !== null) {
            return; // the nudge/fingerprint pair is tied to the cart of the moment
        }
        try {
            $this->promptCache->set($cacheKey, $result);
            error_log(json_encode(['operation' => 'prompt_cache', 'success' => true, 'hit' => false, 'stored' => true], JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // Cache must never break a turn.
        }
    }

    /**
     * The one write path of the chatbot. If the session holds a drafted ticket, it is taken out of the
     * slots FIRST, so a later "đồng ý" finds nothing. Two truly concurrent requests can both read the slot
     * before either clears it (accepted; the create re-check limits duplicates for tickets with an order).
     * Then: still valid, this user's and answered with a fixed yes/no phrase -> created or dropped here,
     * without chat-graph. This user's but expired: a yes gets the fixed "expired" reply, a no the decline
     * reply, both without chat-graph. Anything else (other user, corrupt, other words) just drops the
     * draft and the turn goes on.
     *
     * @param (callable(string, string):void)|null $onText
     * @return array<string,mixed>|null the finished turn, or null when chat-graph should answer
     */
    private function answerTicketConfirmation(ChatbotMemory $memory, string $message, ?callable $onText): ?array
    {
        $stored = $memory->load()['slots'][PendingTicketSlot::KEY] ?? null;
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!$memory->forgetSlot(PendingTicketSlot::KEY)) {
            // The draft could not be taken out, so a second "đồng ý" could create it again: not a confirmation.
            return null;
        }

        $draft = is_string($stored) ? json_decode($stored, true) : null;
        $userId = $this->userId !== null && $this->userId > 0 ? $this->userId : null;
        if (
            !is_array($draft)
            || $userId === null
            || ($draft['user_id'] ?? null) !== $userId
            || !is_int($draft['expires_at'] ?? null)
        ) {
            return null;
        }
        $answer = TicketConfirmation::classify($message);
        if ($answer === 'other') {
            return null;
        }
        $expired = $draft['expires_at'] <= ($this->now)();

        $ticketId = null;
        if ($answer === 'no') {
            $text = self::TICKET_DECLINED;
        } elseif ($expired) {
            $text = self::TICKET_EXPIRED;
        } else {
            try {
                $created = (new SupportTicketService($this->pdo))->create($userId, [
                    'type' => $draft['type'] ?? null,
                    'order_id' => $draft['order_id'] ?? null,
                    'summary' => $draft['summary'] ?? null,
                ], $this->sessionId);
            } catch (Throwable $e) {
                error_log(json_encode(['operation' => 'support_ticket_create', 'success' => false, 'error' => $e::class], JSON_UNESCAPED_SLASHES));
                $created = ['status' => 'error'];
            }
            if (($created['status'] ?? '') === 'created') {
                $ticketId = (int) $created['ticket_id'];
                $text = sprintf(self::TICKET_CREATED, $ticketId);
            } elseif (($created['status'] ?? '') === 'error') {
                $text = self::TICKET_FAILED;
            } else {
                $text = sprintf(self::TICKET_REFUSED, (string) ($created['message'] ?? ''));
            }
        }
        if ($onText !== null) {
            try {
                $onText($text, 'delta');
            } catch (Throwable) {
                // The browser went away; the turn is still saved below.
            }
        }
        (new PdoChatbotConversationStore($this->pdo, $this->sessionId))->saveMessages(
            $message,
            $text,
            [],
            [],
            [],
            ['response_type' => 'final_answer', 'tools_used' => [], 'offered' => []]
        );

        return [
            'message' => $text,
            'answer' => $text,
            'products' => [],
            'cards' => [],
            'response_type' => 'final_answer',
            'primary_intent' => 'support_ticket',
            'tools_used' => [],
            'outfits' => [],
            'offered' => [],
            'freeship_nudge' => false,
            'trace_id' => bin2hex(random_bytes(16)),
            'latency' => ['ticket_confirmation' => true],
            'ticket_pending' => false,
            'ticket_id' => $ticketId,
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
            'pending_ticket' => null,
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
