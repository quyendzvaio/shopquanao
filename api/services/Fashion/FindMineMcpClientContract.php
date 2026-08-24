<?php

interface FindMineMcpClientContract
{
    /** @return array<string, mixed> */
    public function initialize(): array;

    /** @return list<array<string, mixed>> */
    public function listTools(): array;

    /** @return array<string, mixed> */
    public function call(string $toolName, array $arguments): array;
}
