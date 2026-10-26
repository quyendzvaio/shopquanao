<?php


class PdoChatbotConversationStore implements ChatbotConversationStore
{
    private ChatConversationRepository $conversations;
    private ChatSessionRepository $sessions;

    public function __construct(
        PDO $pdo,
        private int $sessionId
    ) {
        $this->conversations = new ChatConversationRepository($pdo);
        $this->sessions = new ChatSessionRepository($pdo);
    }

    public function findLastProductId(): ?int
    {
        try {
            foreach ($this->conversations->recentBotMetadata($this->sessionId) as $row) {
                $metadata = json_decode((string)($row['metadata'] ?? ''), true);
                $first = is_array($metadata) && is_array($metadata['products'] ?? null)
                    ? ($metadata['products'][0] ?? null)
                    : null;
                if (is_array($first) && !empty($first['id'])) {
                    return (int)$first['id'];
                }
            }
        } catch (Throwable $e) {
            error_log('Last product lookup failed: ' . $e->getMessage());
        }

        return null;
    }

    public function saveMessages(
        string $userMessage,
        string $botMessage,
        array $products,
        array $knowledgeSources,
        array $evaluationMetadata,
        array $responseMetadata
    ): void {
        try {
            $this->conversations->addUserMessage(
                $this->sessionId,
                $userMessage,
                json_encode(['pipeline' => 'deterministic_hybrid'], JSON_UNESCAPED_UNICODE)
            );

            $metadata = [];
            if ($products !== []) {
                $metadata['products'] = $products;
            }
            if ($knowledgeSources !== []) {
                $metadata['knowledge_sources'] = $knowledgeSources;
            }
            if ($evaluationMetadata !== []) {
                $metadata['evaluation'] = $evaluationMetadata;
            }
            if ($responseMetadata !== []) {
                $metadata['pipeline'] = $responseMetadata;
            }

            $this->conversations->addBotMessage(
                $this->sessionId,
                $botMessage,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
            );
            $this->sessions->touchUpdatedAt($this->sessionId);
        } catch (Throwable $e) {
            error_log('Save message error: ' . $e->getMessage());
        }
    }

    public function logToolExecution(
        string $tool,
        array $arguments,
        mixed $result,
        int $durationMs,
        bool $success
    ): void {
        try {
            $this->conversations->logToolExecution(
                $this->sessionId,
                $tool,
                json_encode($arguments, JSON_UNESCAPED_UNICODE),
                is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result,
                $durationMs,
                $success ? 1 : 0
            );
        } catch (Throwable $e) {
            error_log('Tool execution log failed: ' . $e->getMessage());
        }
    }
}
