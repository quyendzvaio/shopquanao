<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FindMineMcpClientErrorMappingTest extends TestCase
{
    #[DataProvider('messages')]
    public function testMcpErrorTextMapsToStableRetrySemantics(
        string $message,
        string $category,
        bool $retryable
    ): void {
        $config = new FindMineConfig(true, 'https://api.findmine.com', 'app', 'v3', 'us', 'en', '/bin/true', __FILE__, 5000, 15000, 1);
        $method = new ReflectionMethod(FindMineMcpClient::class, 'mapError');
        $error = $method->invoke(new FindMineMcpClient($config), $message);

        self::assertSame($category, $error->category);
        self::assertSame($retryable, $error->retryable);
    }

    public static function messages(): array
    {
        return [
            'auth' => ['FindMine API error: invalid application', 'AUTHENTICATION_ERROR', false],
            'unknown product' => ['product P123 not found', 'UNKNOWN_PROVIDER_PRODUCT', false],
            'invalid input' => ['Validation failed: required field product_id', 'INVALID_REQUEST', false],
            'rate limit' => ['HTTP 429 Too Many Requests', 'RATE_LIMITED', true],
            'timeout' => ['request timed out', 'PROVIDER_TIMEOUT', true],
            'unavailable' => ['connection reset', 'PROVIDER_UNAVAILABLE', true],
        ];
    }
}
