<?php

/** What a middleware may know about the current chat turn. */
final readonly class TurnContext
{
    /**
     * @param array<string,mixed> $memoryContext the loaded session memory for this turn
     * @param Closure(string):void|null $emit    streaming delta sink; null on the non-streaming path
     */
    public function __construct(
        public string $message,
        public int $sessionId,
        public ?int $userId,
        public array $memoryContext,
        public ?Closure $emit,
    ) {
    }

    /** Send a text delta to the streaming client; a no-op when the turn is not streamed. */
    public function emit(string $delta): void
    {
        if ($this->emit !== null) {
            ($this->emit)($delta);
        }
    }
}
