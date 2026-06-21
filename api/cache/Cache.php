<?php
/**
 * Simple file-based Cache with TTL.
 * Uses /tmp/shop_cache/ as storage (persists across requests, auto-cleaned by OS).
 * Thread-safe via atomic writes (write to temp, then rename).
 */
class Cache {
    private static string $dir = '';

    private static function init(): void {
        if (self::$dir !== '') return;
        self::$dir = rtrim(sys_get_temp_dir(), '/') . '/shop_cache';
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0755, true);
        }
    }

    /**
     * Build cache file path from key.
     */
    private static function path(string $key): string {
        self::init();
        $hash = md5($key);
        // Sub-directory to avoid too many files in one dir
        $sub = substr($hash, 0, 2);
        $subDir = self::$dir . '/' . $sub;
        if (!is_dir($subDir)) {
            @mkdir($subDir, 0755, true);
        }
        return $subDir . '/' . $hash . '.cache';
    }

    /**
     * Get from cache. Returns null if miss or expired.
     */
    public static function get(string $key): mixed {
        $path = self::path($key);
        if (!file_exists($path)) return null;

        $data = @file_get_contents($path);
        if ($data === false || $data === '') return null;

        $payload = @unserialize($data);
        if ($payload === false) return null;

        // Check expiry
        if (isset($payload['expires']) && time() < $payload['expires']) {
            return $payload['data'];
        }

        // Expired — delete
        @unlink($path);
        return null;
    }

    /**
     * Set cache entry.
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Seconds until expiry (default 300 = 5 min)
     */
    public static function set(string $key, mixed $data, int $ttl = 300): void {
        $path = self::path($key);
        $payload = serialize([
            'expires' => time() + $ttl,
            'data' => $data,
            'created' => time(),
        ]);

        // Atomic write: write to temp file then rename
        $tmp = $path . '.' . getmypid() . '.' . uniqid();
        $written = @file_put_contents($tmp, $payload, LOCK_EX);
        if ($written !== false) {
            @rename($tmp, $path);
        }
    }

    /**
     * Delete specific cache entry.
     */
    public static function delete(string $key): void {
        $path = self::path($key);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Clear all cache (for admin maintenance).
     */
    public static function flush(): void {
        self::init();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                @unlink($file->getRealPath());
            } elseif ($file->isDir()) {
                @rmdir($file->getRealPath());
            }
        }
    }

    /**
     * Build a normalized cache key from tool name and args.
     * Sorts args to ensure consistent keys.
     */
    public static function buildKey(string $prefix, array $params): string {
        ksort($params);
        return $prefix . '|' . http_build_query($params);
    }

    /** Shortcut: get search_products cache */
    public static function getSearchResult(array $params): ?array {
        return self::get(self::buildKey('sp', $params));
    }

    /** Shortcut: set search_products cache (TTL 5 min) */
    public static function setSearchResult(array $params, array $result): void {
        self::set(self::buildKey('sp', $params), $result, 300);
    }

    /** Shortcut: get FAQ cache (TTL 1 hour) */
    public static function getFaqResult(array $params): ?array {
        return self::get(self::buildKey('faq', $params));
    }

    /** Shortcut: set FAQ cache (TTL 1 hour) */
    public static function setFaqResult(array $params, array $result): void {
        self::set(self::buildKey('faq', $params), $result, 3600);
    }

    /** Shortcut: get categories cache (TTL 24 hours) */
    public static function getCategories(): ?array {
        return self::get('categories');
    }

    /** Shortcut: set categories cache (TTL 24 hours) */
    public static function setCategories(array $result): void {
        self::set('categories', $result, 86400);
    }

    /** Shortcut: get product detail cache (TTL 5 min) */
    public static function getProductDetail(int $id): ?array {
        return self::get('pd|' . $id);
    }

    /** Shortcut: set product detail cache (TTL 5 min) */
    public static function setProductDetail(int $id, array $result): void {
        self::set('pd|' . $id, $result, 300);
    }

    /** Shortcut: get size guide cache (TTL 10 min) */
    public static function getSizeGuide(array $params): ?array {
        return self::get(self::buildKey('sg', $params));
    }

    /** Shortcut: set size guide cache (TTL 10 min) */
    public static function setSizeGuide(array $params, array $result): void {
        self::set(self::buildKey('sg', $params), $result, 600);
    }

    /** Shortcut: get outfit cache (TTL 10 min) */
    public static function getOutfit(array $params): ?array {
        return self::get(self::buildKey('of', $params));
    }

    /** Shortcut: set outfit cache (TTL 10 min) */
    public static function setOutfit(array $params, array $result): void {
        self::set(self::buildKey('of', $params), $result, 600);
    }
}
