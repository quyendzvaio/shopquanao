<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AgentFactoryTest extends TestCase
{
    private function llmConfig(): array
    {
        return ['provider' => 'deepseek', 'api_key' => 'k', 'base_url' => 'http://x', 'model' => 'm', 'timeout' => 5];
    }

    public function testMcpWithoutTokenFailsAtConstruction(): void
    {
        $factory = new AgentFactory(new HarnessConfig(toolTransport: 'mcp', mcp: ['service_token' => '']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP_SERVICE_TOKEN is not configured');
        $factory->toolGateway(SqliteShop::pdo(), null);
    }

    public function testMcpWithTokenBuildsMcpGateway(): void
    {
        $factory = new AgentFactory(new HarnessConfig(toolTransport: 'mcp', mcp: ['service_token' => 't']));

        $this->assertInstanceOf(McpToolGateway::class, $factory->toolGateway(SqliteShop::pdo(), null));
    }

    public function testShadowTransportBuildsShadowGateway(): void
    {
        $factory = new AgentFactory(new HarnessConfig(toolTransport: 'shadow', mcp: ['service_token' => 't']));

        $this->assertInstanceOf(ShadowToolGateway::class, $factory->toolGateway(SqliteShop::pdo(), null));
    }

    public function testInternalTransportBuildsToolRegistry(): void
    {
        $factory = new AgentFactory(new HarnessConfig(toolTransport: 'internal'));

        $this->assertInstanceOf(ToolRegistry::class, $factory->toolGateway(SqliteShop::pdo(), null));
    }

    public function testUnknownTransportThrows(): void
    {
        $factory = new AgentFactory(new HarnessConfig(toolTransport: 'carrier-pigeon'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CHATBOT_TOOL_TRANSPORT must be mcp, shadow, or internal');
        $factory->toolGateway(SqliteShop::pdo(), null);
    }

    public function testBuildsLlmAndStreamingLlmFromConfig(): void
    {
        $factory = new AgentFactory(new HarnessConfig(llm: $this->llmConfig(), streamLlm: $this->llmConfig()));

        $this->assertInstanceOf(DeepSeekProvider::class, $factory->llm());
        $this->assertInstanceOf(StreamingLLMProvider::class, $factory->streamingLlm());
    }

    public function testNoLlmWithoutConfigOrApiKey(): void
    {
        $this->assertNull((new AgentFactory(new HarnessConfig()))->llm());
        $noKey = ['provider' => 'deepseek', 'api_key' => ''] + $this->llmConfig();
        $factory = new AgentFactory(new HarnessConfig(llm: $noKey, streamLlm: $noKey));
        $this->assertNull($factory->llm());
        $this->assertNull($factory->streamingLlm());
    }

    public function testUnknownProviderYieldsNoLlm(): void
    {
        $config = ['provider' => 'mystery'] + $this->llmConfig();

        $this->assertNull((new AgentFactory(new HarnessConfig(llm: $config)))->llm());
    }

    public function testForceL0DisablesBothModels(): void
    {
        $factory = new AgentFactory(new HarnessConfig(forceL0: true, llm: $this->llmConfig(), streamLlm: $this->llmConfig()));

        $this->assertNull($factory->llm());
        $this->assertNull($factory->streamingLlm());
    }

    public function testMiddlewareStackComesFromHarnessConfig(): void
    {
        $factory = new AgentFactory(new HarnessConfig());
        $configured = (require dirname(__DIR__, 3) . '/config/harness.php')['middleware'];

        $this->assertSame($configured, $factory->middlewareStack(SqliteShop::pdo(), 1)->names());
    }

    public function testConfiguredLlmsAreWrappedWhenGivenAStack(): void
    {
        $factory = new AgentFactory(new HarnessConfig(llm: $this->llmConfig(), streamLlm: $this->llmConfig()));
        $stack = new MiddlewareStack([]);

        $this->assertInstanceOf(MiddlewareModel::class, $factory->llm($stack));
        $this->assertInstanceOf(MiddlewareModel::class, $factory->streamingLlm($stack));
        $this->assertNull((new AgentFactory(new HarnessConfig()))->llm($stack));
        $this->assertNull((new AgentFactory(new HarnessConfig(forceL0: true, streamLlm: $this->llmConfig())))->streamingLlm($stack));
    }

    public function testUnknownMiddlewareClassIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown harness middleware: NoSuchMiddleware');
        (new AgentFactory(new HarnessConfig()))->makeMiddleware('NoSuchMiddleware', SqliteShop::pdo(), 1);
    }

    public function testResponseGeneratorUsesConfiguredFreeShippingThreshold(): void
    {
        $factory = new AgentFactory(new HarnessConfig(freeShipping: new FreeShippingPolicy(750000)));
        $intent = ['primary_intent' => 'freeship_gap'];

        $message = $factory->responseGenerator()->generate('free ship', $intent, ['cards' => [], 'evidence' => []], ['response_type' => 'final_answer'])['message'];

        $this->assertStringContainsString('750.000', $message);
    }
}
