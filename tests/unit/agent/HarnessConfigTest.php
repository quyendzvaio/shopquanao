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

    public function testFreeShippingFromEnv(): void
    {
        putenv('FREE_SHIPPING_THRESHOLD=600000');

        $this->assertSame(600000, HarnessConfig::fromEnvironment()->freeShipping->threshold);
    }

    public function testNonNumericFreeShippingEnvFallsBackToDefaults(): void
    {
        putenv('FREE_SHIPPING_THRESHOLD=abc');
        putenv('FREESHIP_NUDGE_MAX_GAP=200k');

        $policy = HarnessConfig::fromEnvironment()->freeShipping;

        $this->assertSame(500000, $policy->threshold);
        $this->assertSame(200000, $policy->nudgeMaxGap);
    }

    public function testChatGraphDefaults(): void
    {
        $config = HarnessConfig::fromEnvironment();

        $this->assertSame('http://chat-graph:8090', $config->chatGraphUrl);
        $this->assertSame('', $config->chatGraphToken);
        $this->assertSame(50, $config->chatGraphTimeout);
    }

    public function testChatGraphSettingsFromEnv(): void
    {
        putenv('CHAT_GRAPH_URL=http://graph.local:9000');
        putenv('CHAT_GRAPH_TOKEN=tok');
        putenv('CHAT_GRAPH_TIMEOUT=20');

        $config = HarnessConfig::fromEnvironment();

        $this->assertSame('http://graph.local:9000', $config->chatGraphUrl);
        $this->assertSame('tok', $config->chatGraphToken);
        $this->assertSame(20, $config->chatGraphTimeout);
    }
}
