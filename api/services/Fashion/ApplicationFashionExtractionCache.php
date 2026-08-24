<?php

final class ApplicationFashionExtractionCache implements FashionExtractionCache
{
    public function get(string $key): ?array
    {
        $value = Cache::get($key);
        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $items): void
    {
        $ttl = max(0, (int) (getenv('FASHION_EXTRACTION_CACHE_TTL') ?: 86400));
        Cache::set($key, $items, $ttl);
    }
}
