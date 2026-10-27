<?php

/** The request a page handler sees: method plus the query, post and files superglobals, read once in public/. */
final class WebRequest
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, $_POST, $_FILES);
    }
}
