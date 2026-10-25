<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnvSandbox.php';

final class HarnessConfigTest extends TestCase
{
    use EnvSandbox;

    protected function setUp(): void
    {
        $this->cleanEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    public function testDefaultsToMcpTransport(): void
    {
        $config = HarnessConfig::fromEnvironment();

        $this->assertSame('mcp', $config->toolTransport);
        $this->assertFalse($config->forceL0);
        $this->assertFalse($config->templateResponse);
        $this->assertNull($config->llm);
        $this->assertNull($config->streamLlm);
        $this->assertSame('', $config->mcp['service_token']);
        $this->assertSame(35, $config->mcp['timeout_seconds']);
        $this->assertSame('/usr/bin/node', $config->mcp['node']);
        $this->assertSame('/opt/mcp-server/dist/stdio.js', $config->mcp['script']);
        $this->assertSame('http://127.0.0.1/api/internal/mcp', $config->mcp['shop_internal_url']);
        $this->assertSame('30000', $config->mcp['request_timeout_ms']);
    }

    public function testTestEnvDefaultsToInternal(): void
    {
        putenv('APP_ENV=test');

        $this->assertSame('internal', HarnessConfig::fromEnvironment()->toolTransport);
    }

    public function testExplicitTransportWinsOverTestEnv(): void
    {
        putenv('APP_ENV=test');
        putenv('CHATBOT_TOOL_TRANSPORT=Shadow');

        $this->assertSame('shadow', HarnessConfig::fromEnvironment()->toolTransport);
    }

    public function testForceL0AndTemplateFlags(): void
    {
        putenv('CHATBOT_FORCE_L0=1');
        putenv('CHATBOT_TEMPLATE_RESPONSE=true');
        $config = HarnessConfig::fromEnvironment();
        $this->assertTrue($config->forceL0);
        $this->assertTrue($config->templateResponse);

        putenv('CHATBOT_FORCE_L0=true');
        putenv('CHATBOT_TEMPLATE_RESPONSE=1');
        $config = HarnessConfig::fromEnvironment();
        $this->assertTrue($config->forceL0);
        $this->assertTrue($config->templateResponse);

        putenv('CHATBOT_FORCE_L0=0');
        $this->assertFalse(HarnessConfig::fromEnvironment()->forceL0);
    }

    public function testFreeShippingFromEnv(): void
    {
        putenv('FREE_SHIPPING_THRESHOLD=600000');

        $this->assertSame(600000, HarnessConfig::fromEnvironment()->freeShipping->threshold);
    }

    public function testStreamModelFallsBackToMainModel(): void
    {
        putenv('LLM_PROVIDER=deepseek');
        putenv('LLM_API_KEY=k');
        putenv('LLM_MODEL=main-model');

        $config = HarnessConfig::fromEnvironment();

        $this->assertSame('main-model', $config->llm['model']);
        $this->assertSame($config->llm['model'], $config->streamLlm['model']);

        putenv('LLM_STREAM_MODEL=fast-model');
        $this->assertSame('fast-model', HarnessConfig::fromEnvironment()->streamLlm['model']);
    }

    public function testLlmDefaultsAndFallbackToServerGlobals(): void
    {
        $_SERVER['LLM_PROVIDER'] = 'deepseek';
        $_SERVER['LLM_API_KEY'] = 'k';

        $llm = HarnessConfig::fromEnvironment()->llm;

        $this->assertSame('https://api.deepseek.com', $llm['base_url']);
        $this->assertSame('deepseek-chat', $llm['model']);
        $this->assertSame(60, $llm['timeout']);
    }

    public function testMcpSettingsFromEnv(): void
    {
        putenv('MCP_SERVICE_TOKEN=secret');
        putenv('MCP_REQUEST_TIMEOUT=0');
        putenv('MCP_STDIO_NODE=/opt/node');

        $mcp = HarnessConfig::fromEnvironment()->mcp;

        $this->assertSame('secret', $mcp['service_token']);
        $this->assertSame(35, $mcp['timeout_seconds']);
        $this->assertSame('/opt/node', $mcp['node']);
    }
}
