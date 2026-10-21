<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatStreamProtocolTest extends TestCase
{
    public function testEncodesOneServerSentEventPerChatEvent(): void
    {
        $frame = ChatStreamProtocol::event(['type' => 'chat.delta', 'delta' => "Áo sơ mi\ntrắng"]);

        $this->assertSame("event: chat.delta\ndata: {\"type\":\"chat.delta\",\"delta\":\"Áo sơ mi\\ntrắng\"}\n\n", $frame);
    }

    public function testCardsKeepOnlyAllowListedFieldsAndDropInvalidIds(): void
    {
        $cards = ChatStreamProtocol::cards([
            ['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'description' => 'nội bộ', 'cost_price' => 90000, 'available_sizes' => ['M']],
            ['id' => 0, 'name' => 'không hợp lệ'],
            'not-a-card',
        ]);

        $this->assertSame([['id' => 61, 'name' => 'Áo Dài Tay Cổ Tim', 'price' => 250000, 'available_sizes' => ['M']]], $cards);
    }

    public function testSameOriginBrowserRequestsAreAllowed(): void
    {
        $this->assertTrue(ChatStreamProtocol::originAllowed('http://localhost:8092', 'localhost:8092', []));
        $this->assertTrue(ChatStreamProtocol::originAllowed('https://shop.example', 'shop.example', []));
    }

    public function testCrossOriginBrowserRequestsAreRejected(): void
    {
        $this->assertFalse(ChatStreamProtocol::originAllowed('https://evil.example', 'shop.example', []));
        $this->assertFalse(ChatStreamProtocol::originAllowed('null', 'shop.example', []));
    }

    public function testRequestsWithoutAnOriginHeaderAreAllowed(): void
    {
        $this->assertTrue(ChatStreamProtocol::originAllowed(null, 'shop.example', []));
        $this->assertTrue(ChatStreamProtocol::originAllowed('', 'shop.example', []));
    }

    protected function tearDown(): void
    {
        putenv('CHATBOT_MAX_MESSAGE_CHARS');
        putenv('CHATBOT_ALLOWED_ORIGINS');
    }

    public function testAcceptsASameOriginBrowserMessage(): void
    {
        $server = ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_FORWARDED_HOST' => 'localhost', 'HTTP_HOST' => 'app:8080'];

        $this->assertNull(ChatStreamProtocol::rejectBrowserRequest('áo thun dưới 300k', $server));
    }

    public function testRejectsACrossOriginBrowserMessageWith403(): void
    {
        $server = ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_X_FORWARDED_HOST' => 'localhost'];

        $this->assertSame(403, ChatStreamProtocol::rejectBrowserRequest('áo thun', $server)['status'] ?? null);
    }

    public function testRejectsMessagesOverTheConfiguredLengthWith413(): void
    {
        putenv('CHATBOT_MAX_MESSAGE_CHARS=11');

        $this->assertNull(ChatStreamProtocol::rejectBrowserRequest('áo sơ mi đỏ', []));
        $this->assertSame(413, ChatStreamProtocol::rejectBrowserRequest('áo sơ mi đỏ!', [])['status'] ?? null);
    }

    public function testConfiguredOriginsAreReadFromTheEnvironment(): void
    {
        putenv('CHATBOT_ALLOWED_ORIGINS=https://shop.example, https://admin.shop.example');
        $server = ['HTTP_ORIGIN' => 'https://admin.shop.example', 'HTTP_HOST' => 'app:8080'];

        $this->assertNull(ChatStreamProtocol::rejectBrowserRequest('áo thun', $server));
    }

    public function testConfiguredOriginsReplaceTheSameOriginRule(): void
    {
        $allowed = ['https://shop.example', 'https://admin.shop.example'];

        $this->assertTrue(ChatStreamProtocol::originAllowed('https://admin.shop.example/', 'api.internal', $allowed));
        $this->assertFalse(ChatStreamProtocol::originAllowed('https://api.internal', 'api.internal', $allowed));
    }
}
