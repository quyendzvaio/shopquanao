<?php

/**
 * What a page handler returns: a view (template data) or a final response (redirect, raw output, halt).
 * Handlers never send anything themselves; public/ calls sendIfFinal() so handlers stay testable.
 */
final class PageResult
{
    private function __construct(
        private readonly string $kind,
        private readonly array $data,
        private readonly string $payload
    ) {
    }

    /** @param array<string,mixed> $data variables extracted for the template */
    public static function view(array $data = []): self
    {
        return new self('view', $data, '');
    }

    public static function redirect(string $location): self
    {
        return new self('redirect', [], $location);
    }

    /** Raw body followed by exit (the `<script>alert(...)</script>` responses). */
    public static function output(string $body): self
    {
        return new self('output', [], $body);
    }

    public static function halt(string $message): self
    {
        return new self('halt', [], $message);
    }

    public function isView(): bool
    {
        return $this->kind === 'view';
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    /** Does nothing for a view; otherwise performs the action and exits. */
    public function sendIfFinal(): void
    {
        switch ($this->kind) {
            case 'redirect':
                header('Location: ' . $this->payload);
                exit;
            case 'output':
                echo $this->payload;
                exit;
            case 'halt':
                die($this->payload);
        }
    }
}
