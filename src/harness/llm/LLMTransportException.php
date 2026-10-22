<?php

final class LLMTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
