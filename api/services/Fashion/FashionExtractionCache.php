<?php

interface FashionExtractionCache
{
    /** @return list<array<string,mixed>>|null */
    public function get(string $key): ?array;
    /** @param list<array<string,mixed>> $items */
    public function set(string $key, array $items): void;
}
