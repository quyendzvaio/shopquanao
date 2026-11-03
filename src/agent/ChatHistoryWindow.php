<?php

/**
 * The last chat messages sent to chat-graph as `history`. An assistant message
 * carries the ids it showed as cards (and offered as alternatives) so a later
 * "cái thứ 2" or "ống rộng đi" can be resolved to a product id (spec 5.3).
 */
final class ChatHistoryWindow
{
    /**
     * @param list<array<int|string,mixed>> $rows ChatConversationRepository::messagesForSession output
     * @return list<array{role:string,content:string}>
     */
    public static function fromRows(array $rows, int $limit = 6): array
    {
        $history = [];
        foreach (array_slice($rows, -max(1, $limit)) as $row) {
            $isBot = (string) ($row['role'] ?? '') === 'bot';
            $content = (string) ($row['message'] ?? '');
            if ($isBot) {
                $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $content .= self::line('Thẻ đã hiện', $metadata['products'] ?? null);
                $content .= self::line('Đã gợi ý', $metadata['offered'] ?? null);
            }
            $history[] = ['role' => $isBot ? 'assistant' : 'user', 'content' => $content];
        }

        return $history;
    }

    private static function line(string $label, mixed $items): string
    {
        $parts = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_int($item['id'] ?? null) && $item['id'] > 0) {
                $parts[] = 'mã ' . $item['id'] . ' ' . trim((string) ($item['name'] ?? ''));
            }
        }

        return $parts === [] ? '' : "\n[" . $label . ': ' . implode('; ', array_map('rtrim', $parts)) . ']';
    }
}
