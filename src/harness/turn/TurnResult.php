<?php

/** Immutable wrapper over the chat turn result array; the array shape stays the contract. */
final class TurnResult
{
    /**
     * @param array<string,mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function with(string $key, mixed $value): self
    {
        $data = $this->data;
        $data[$key] = $value;
        return new self($data);
    }
}
