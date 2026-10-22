<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization of the OpenAI-compatible HTTP provider against a local stub
 * server (tests/support/llm_stub_router.php).
 */
final class DeepSeekProviderTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($probe, $errstr);
        self::$port = (int) parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        $router = dirname(__DIR__, 2) . '/support/llm_stub_router.php';
        $pipes = [];
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource(self::$server);

        $deadline = microtime(true) + 5;
        do {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        self::fail('LLM stub server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    private function provider(string $model, ?string $baseUrl = null): DeepSeekProvider
    {
        return new DeepSeekProvider(
            apiKey: 'k',
            baseUrl: $baseUrl ?? 'http://127.0.0.1:' . self::$port . '/v1',
            model: $model,
            timeout: 5
        );
    }

    /** @return array<int, array{role: string, content: string}> */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'hello ' . uniqid('', true)]];
    }

    public function testChatReturnsContent(): void
    {
        $response = $this->provider('ok')->chat($this->messages());

        $this->assertSame('xin chào', $response->content);
        $this->assertSame('stop', $response->finishReason);
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        try {
            $this->provider('ratelimited')->chat($this->messages());
            $this->fail('Expected LLMTransportException');
        } catch (LLMTransportException $e) {
            $this->assertSame('rate_limit', $e->category);
            $this->assertSame(7, $e->retryAfterSeconds);
            $this->assertSame('LLM rate limit exceeded (HTTP 429)', $e->getMessage());
        }
    }

    public function testServerErrorIsProviderUnavailable(): void
    {
        try {
            $this->provider('down')->chat($this->messages());
            $this->fail('Expected LLMTransportException');
        } catch (LLMTransportException $e) {
            $this->assertSame('provider_unavailable', $e->category);
            $this->assertSame('LLM provider unavailable (HTTP 503)', $e->getMessage());
        }
    }

    public function testConnectionRefusedIsProviderUnavailable(): void
    {
        try {
            $this->provider('ok', 'http://127.0.0.1:9')->chat($this->messages());
            $this->fail('Expected LLMTransportException');
        } catch (LLMTransportException $e) {
            $this->assertSame('provider_unavailable', $e->category);
            $this->assertStringStartsWith('LLM request failed: ', $e->getMessage());
        }
    }

    public function testStreamEmitsDeltasAndAccumulates(): void
    {
        $deltas = [];
        $response = $this->provider('stream')->chatStream(
            $this->messages(),
            static function (string $delta) use (&$deltas): void {
                $deltas[] = $delta;
            }
        );

        $this->assertSame(['Xin ', 'chào'], $deltas);
        $this->assertSame('Xin chào', $response->content);
    }

    public function testStreamServerErrorIsProviderUnavailable(): void
    {
        try {
            $this->provider('down')->chatStream($this->messages(), static function (string $delta): void {
            });
            $this->fail('Expected LLMTransportException');
        } catch (LLMTransportException $e) {
            $this->assertSame('provider_unavailable', $e->category);
            $this->assertSame('LLM provider unavailable (HTTP 503)', $e->getMessage());
        }
    }

    public function testStreamConnectionRefusedMessage(): void
    {
        try {
            $this->provider('stream', 'http://127.0.0.1:9')->chatStream($this->messages(), static function (string $delta): void {
            });
            $this->fail('Expected LLMTransportException');
        } catch (LLMTransportException $e) {
            $this->assertSame('provider_unavailable', $e->category);
            $this->assertStringStartsWith('LLM stream failed: ', $e->getMessage());
        }
    }
}
