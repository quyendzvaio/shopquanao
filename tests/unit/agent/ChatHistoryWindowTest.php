<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatHistoryWindowTest extends TestCase
{
    private function row(int $id, string $role, string $message, ?array $metadata = null): array
    {
        return [
            'id' => $id, 'role' => $role, 'message' => $message,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => '2026-11-01 10:00:00',
        ];
    }

    public function testKeepsTheLastSixOfEightRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = $this->row($i, $i % 2 === 1 ? 'user' : 'bot', "m$i");
        }

        $window = ChatHistoryWindow::fromRows($rows);

        $this->assertCount(6, $window);
        $this->assertSame(['m3', 'm4', 'm5', 'm6', 'm7', 'm8'], array_column($window, 'content'));
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant'], array_column($window, 'role'));
    }

    public function testAssistantRowListsTheShownCards(): void
    {
        $window = ChatHistoryWindow::fromRows([
            $this->row(1, 'user', 'quần baggy'),
            $this->row(2, 'bot', 'Đây ạ.', ['products' => [['id' => 73, 'name' => 'Quần Kaki Ống Rộng Màu Xanh Rêu']]]),
        ]);

        $this->assertSame('quần baggy', $window[0]['content']);
        $this->assertSame("Đây ạ.\n[Thẻ đã hiện: mã 73 Quần Kaki Ống Rộng Màu Xanh Rêu]", $window[1]['content']);
    }

    public function testAssistantRowListsOfferedAndSeveralCards(): void
    {
        $window = ChatHistoryWindow::fromRows([
            $this->row(1, 'bot', 'Mẫu A hết hàng.', [
                'products' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']],
                'offered' => [['id' => 73, 'name' => 'Quần Kaki'], ['id' => 71, 'name' => 'Quần Bò']],
            ]),
        ]);

        $this->assertSame(
            "Mẫu A hết hàng.\n[Thẻ đã hiện: mã 1 A; mã 2 B]\n[Đã gợi ý: mã 73 Quần Kaki; mã 71 Quần Bò]",
            $window[0]['content']
        );
    }

    public function testUserRowsAndBrokenMetadataAreLeftAlone(): void
    {
        $rows = [
            $this->row(1, 'user', 'hi', ['products' => [['id' => 9, 'name' => 'X']]]),
            ['id' => 2, 'role' => 'bot', 'message' => 'ok', 'metadata' => '{not json', 'created_at' => ''],
            $this->row(3, 'bot', 'ok2', ['products' => []]),
        ];

        $window = ChatHistoryWindow::fromRows($rows);

        $this->assertSame(['hi', 'ok', 'ok2'], array_column($window, 'content'));
    }

    public function testEmptyRowsGiveNoHistory(): void
    {
        $this->assertSame([], ChatHistoryWindow::fromRows([]));
    }
}
