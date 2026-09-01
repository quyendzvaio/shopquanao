<?php

use PHPUnit\Framework\TestCase;

final class GlanceMcpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GLANCE_AUTH_MODE');
        putenv('GLANCE_AUTH_TOKEN');
        putenv('GLANCE_MCP_TIMEOUT_MS');
        putenv('MCP_REQUEST_TIMEOUT_MS');
    }

    public function testBearerModePassesAnEnvironmentPlaceholderInsteadOfTheToken(): void
    {
        putenv('GLANCE_AUTH_MODE=bearer');
        putenv('GLANCE_AUTH_TOKEN=not-a-real-token');
        $command = $this->defaultCommand();

        self::assertContains('--header', $command);
        self::assertContains('Authorization:Bearer ${GLANCE_AUTH_TOKEN}', $command);
        self::assertNotContains('not-a-real-token', $command);
    }

    public function testBearerModeFailsFastWhenTokenIsMissing(): void
    {
        putenv('GLANCE_AUTH_MODE=bearer');
        putenv('GLANCE_AUTH_TOKEN');

        $this->expectException(GlanceMcpException::class);
        $this->expectExceptionMessage('GLANCE_AUTH_TOKEN is required');
        $this->defaultCommand();
    }

    public function testDedicatedGlanceTimeoutDoesNotInheritTheGlobalMcpDeadline(): void
    {
        putenv('MCP_REQUEST_TIMEOUT_MS=80000');
        putenv('GLANCE_MCP_TIMEOUT_MS=27000');
        $client = new GlanceMcpClient(new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false));

        $timeout = new ReflectionProperty($client, 'timeoutMs');
        self::assertSame(27000, $timeout->getValue($client));
    }

    public function testOAuthDiscoveryDiagnosticsDoNotBecomeAnAuthenticationFailure(): void
    {
        $server = <<<'PHP'
fwrite(STDERR, "Discovering OAuth server configuration...\n");
fwrite(STDERR, "Discovered authorization server: https://auth.example.test\n");
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['id'])) continue;
    $result = $request['method'] === 'initialize'
        ? ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'serverInfo' => ['name' => 'test', 'version' => '1']]
        : ['content' => [['type' => 'text', 'text' => '{}']], 'structuredContent' => ['tiers' => []]];
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]) . "\n");
    fflush(STDOUT);
}
PHP;
        $client = new GlanceMcpClient(
            new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false),
            [PHP_BINARY, '-r', $server],
            1000
        );

        $result = $client->call('search_fashion_products', []);
        self::assertSame([], $result['structuredContent']['tiers']);
    }

    public function testOAuthPromptOnStderrFailsBeforeTheMcpRequestDeadline(): void
    {
        $server = <<<'PHP'
fwrite(STDERR, "Please authorize this client in your browser.\n");
fflush(STDERR);
usleep(5_000_000);
PHP;
        $client = new GlanceMcpClient(
            new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false),
            [PHP_BINARY, '-r', $server],
            5000
        );

        $startedAt = hrtime(true);
        try {
            $client->call('search_fashion_products', []);
            self::fail('Expected an authentication exception');
        } catch (GlanceMcpException $exception) {
            self::assertSame('AUTHENTICATION_ERROR', $exception->category);
            self::assertSame('MANUAL_ACTION_REQUIRED: GLANCE_OAUTH', $exception->getMessage());
        }
        self::assertLessThan(1000, (hrtime(true) - $startedAt) / 1_000_000);
    }

    /** @return list<string> */
    private function defaultCommand(): array
    {
        $client = new GlanceMcpClient(new GlanceConfig(true, 'live', 'https://ember.ailooks.glance.com/mcp', 'get_mix_and_match', false));
        $method = new ReflectionMethod($client, 'defaultCommand');
        return $method->invoke($client);
    }
}
