<?php

final class FindMineProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
