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

    public function __construct(
        PDO $pdo,
        private int $sessionId,
        private ?int $userId,
        ?ChatGraphClient $graph = null,
        ?Closure $now = null
    ) {
        $this->pdo = $pdo;
        $this->graph = $graph;
        $this->now = $now ?? static fn (): int => time();
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
        $confirmation = $this->answerTicketConfirmation($memory, $message, $onDelta);
        if ($confirmation !== null) {
            return $confirmation;
        }
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
                        // The draft question was already streamed; this line tells the customer it cannot be confirmed.
                        $onDelta(' ' . self::TICKET_FAILED);
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
            'ticket_pending' => $ticketPending,
            'ticket_id' => null,
        ];
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
     * @param (callable(string):void)|null $onDelta
     * @return array<string,mixed>|null the finished turn, or null when chat-graph should answer
     */
    private function answerTicketConfirmation(ChatbotMemory $memory, string $message, ?callable $onDelta): ?array
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
        if ($onDelta !== null) {
            try {
                $onDelta($text);
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
