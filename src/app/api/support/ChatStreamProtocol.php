<?php

/**
 * Wire format and browser boundary for POST /api/chatbot/stream.
 *
 * The storefront reads this endpoint directly as Server-Sent Events, so the
 * guarantees the old WebSocket gateway enforced live here: only allow-listed
 * product card fields leave PHP, and browser requests must be same-origin.
 */
final class ChatStreamProtocol
{
    public const CARD_FIELDS = [
        'id', 'name', 'price', 'stock', 'image', 'image_url', 'url', 'category_id',
        'subcategory_id', 'variant_id', 'available_sizes', 'available_colors',
        'outfit_index',
    ];

    public static function event(array $event): string
    {
        $type = (string)($event['type'] ?? 'message');
        $data = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return "event: {$type}\ndata: {$data}\n\n";
    }

    /** @return list<array<string, mixed>> */
    public static function cards(array $products): array
    {
        $cards = [];
        foreach ($products as $product) {
            if (!is_array($product) || !is_int($product['id'] ?? null) || $product['id'] <= 0) continue;
            $cards[] = array_intersect_key($product, array_flip(self::CARD_FIELDS));
        }
        return $cards;
    }

    /**
     * Shared guard for both browser chat endpoints (stream and its HTTP fallback).
     *
     * @return array{status: int, message: string}|null null when the request may proceed
     */
    public static function rejectBrowserRequest(string $message, array $server, AppConfig $config): ?array
    {
        $allowed = $config->allowedOrigins;
        $host = (string)($server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? '');
        if (!self::originAllowed($server['HTTP_ORIGIN'] ?? null, $host, $allowed)) {
            return ['status' => 403, 'message' => 'Origin not allowed'];
        }
        if (mb_strlen($message) > $config->maxMessageChars) {
            return ['status' => 413, 'message' => 'Message is too long'];
        }
        return null;
    }

    /**
     * Browsers always send Origin on a POST; non-browser clients (curl, eval)
     * may omit it. With no configured list, the origin's host must match the
     * Host the request was addressed to.
     *
     * @param list<string> $allowedOrigins
     */
    public static function originAllowed(?string $origin, string $host, array $allowedOrigins): bool
    {
        $origin = rtrim(trim((string)$origin), '/');
        if ($origin === '') return true;
        if ($allowedOrigins !== []) {
            return in_array($origin, array_map(static fn (string $o): string => rtrim(trim($o), '/'), $allowedOrigins), true);
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) return false;
        $originHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return strcasecmp($originHost, $host) === 0;
    }
}
