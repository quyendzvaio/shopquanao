<?php

interface ConcurrentProductSearchGateway
{
    /**
     * @param array<string, array<string, mixed>> $searches
     * @return array<string, array{success: bool, products: array, error: ?string, duration_ms: int}>
     */
    public function searchBatch(array $searches, int $maxConcurrency): array;
}
