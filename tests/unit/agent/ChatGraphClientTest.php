<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatGraphClientTest extends TestCase
{
    private const COMPLETE = [
        'type' => 'complete', 'response_type' => 'final_answer', 'primary_intent' => 'product_search',
        'tools_used' => ['search_products'], 'outfits' => [], 'offered' => [['id' => 71, 'name' => 'Quần Bò']],
        'freeship_nudge' => false, 'freeship_fingerprint' => null, 'trace_id' => 'abc', 'latency' => ['total_ms' => 12],
    ];

    /** @param list<string> $chunks */
    private function client(array $chunks, int $status = 200, array &$seen = []): ChatGraphClient
    {
        return new ChatGraphClient('http://graph:8090', 't', 50, static function (string $url, array $headers, string $body, int $timeout, callable $onChunk) use ($chunks, $status, &$seen): int {
            $seen = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
            foreach ($chunks as $chunk) {
                $onChunk($chunk);
            }
            return $status;
        });
    }

    private function request(bool $stream = true): array
    {
        return [
            'message' => 'quần baggy', 'user_id' => null, 'session_id' => 3, 'history' => [],
            'memory' => ['summary' => '', 'slots' => [], 'profile' => null],
            'freeship_fingerprint' => null, 'stream' => $stream,
        ];
    }

    public function testStreamsDeltasAndBuildsResult(): void
    {
        $card = ['id' => 73, 'name' => 'Quần Kaki', 'price' => 490000];
        $lines = [
            json_encode(['type' => 'progress', 'stage' => 'graph']),
            json_encode(['type' => 'delta', 'text' => 'Chào '], JSON_UNESCAPED_UNICODE),
            json_encode(['type' => 'delta', 'text' => 'bạn'], JSON_UNESCAPED_UNICODE),
            json_encode(['type' => 'cards', 'products' => [$card]]),
            json_encode(self::COMPLETE),
        ];
        $all = implode("\n", $lines) . "\n";
        // Split in the middle of the second delta line and again inside a multibyte character.
        $cut1 = strpos($all, 'bạn') - 5;
        $cut2 = strpos($all, 'ạ') + 1;
        $chunks = [substr($all, 0, $cut1), substr($all, $cut1, $cut2 - $cut1), substr($all, $cut2)];
        $events = [];

        $result = $this->client($chunks)->turn($this->request(), static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertSame(['progress', 'delta', 'delta', 'cards', 'complete'], array_column($events, 'type'));
        $this->assertSame(['Chào ', 'bạn'], array_values(array_column(array_filter($events, static fn ($e) => $e['type'] === 'delta'), 'text')));
        $this->assertSame('Chào bạn', $result['message']);
        $this->assertSame([$card], $result['products']);
        $this->assertSame('final_answer', $result['response_type']);
        $this->assertSame('product_search', $result['primary_intent']);
        $this->assertSame([['id' => 71, 'name' => 'Quần Bò']], $result['offered']);
        $this->assertSame('abc', $result['trace_id']);
        $this->assertSame(['total_ms' => 12], $result['latency']);
    }

    public function testNonStreamingReadsTheJsonBody(): void
    {
        $body = json_encode(['message' => 'Xin chào', 'products' => []] + array_diff_key(self::COMPLETE, ['type' => 1]));
        $seen = [];

        $result = $this->client([substr($body, 0, 20), substr($body, 20)], 200, $seen)->turn($this->request(false));

        $this->assertSame('Xin chào', $result['message']);
        $this->assertSame([], $result['products']);
        $this->assertSame('final_answer', $result['response_type']);
        $this->assertFalse(json_decode($seen['body'], true)['stream']);
    }

    public function testNon200Throws(): void
    {
        $this->expectException(ChatGraphUnavailable::class);

        $this->client(['{"error":"bad gateway"}'], 502)->turn($this->request());
    }

    public function testMalformedStreamAnswersBusy(): void
    {
        foreach ([["not json at all\n"], [json_encode(['type' => 'delta', 'text' => 'x']) . "\n"], []] as $chunks) {
            try {
                $this->client($chunks)->turn($this->request());
                $this->fail('expected ChatGraphUnavailable');
            } catch (ChatGraphUnavailable $e) {
                $this->assertNotSame('', $e->category);
            }
        }
    }

    public function testMalformedNonStreamingBodyThrows(): void
    {
        $this->expectException(ChatGraphUnavailable::class);

        $this->client(['<html>oops</html>'])->turn($this->request(false));
    }

    public function testTransportErrorBecomesUnavailable(): void
    {
        $client = new ChatGraphClient('http://graph:8090', 't', 50, static function (): int {
            throw new RuntimeException('connection refused');
        });

        $this->expectException(ChatGraphUnavailable::class);

        $client->turn($this->request());
    }

    public function testSendsTokenAndEmptyMapsAsJsonObjects(): void
    {
        $seen = [];
        $this->client([json_encode(self::COMPLETE) . "\n"], 200, $seen)->turn($this->request());

        $this->assertContains('X-CHAT-GRAPH-TOKEN: t', $seen['headers']);
        $this->assertContains('Content-Type: application/json', $seen['headers']);
        $this->assertSame('http://graph:8090/turn', $seen['url']);
        $this->assertSame(50, $seen['timeout']);
        $this->assertStringContainsString('"slots":{}', $seen['body']);
        $this->assertStringContainsString('"history":[]', $seen['body']);
        $this->assertStringContainsString('"user_id":null', $seen['body']);
    }
}
