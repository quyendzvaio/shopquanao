<?php

final class FashionExtractionException extends RuntimeException
{
    public function __construct(public readonly string $category, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
