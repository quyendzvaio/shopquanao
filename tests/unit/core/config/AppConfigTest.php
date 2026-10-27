<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../agent/EnvSandbox.php';

final class AppConfigTest extends TestCase
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

    public function testDefaultsMatchPreviousEnvFallbacks(): void
    {
        $config = AppConfig::fromEnvironment();

        $this->assertSame([], $config->allowedOrigins);
        $this->assertSame(2000, $config->maxMessageChars);
        $this->assertSame('', $config->mcpServiceToken);
        $this->assertSame('admin@gmail.com', $config->adminEmail);
        $this->assertSame('', $config->adminUser);
        $this->assertSame('', $config->adminPassword);
    }

    public function testReadsEachVariable(): void
    {
        $this->setEnv('CHATBOT_ALLOWED_ORIGINS', ' https://shop.example, ,https://admin.shop.example ');
        $this->setEnv('CHATBOT_MAX_MESSAGE_CHARS', '11');
        $this->setEnv('MCP_SERVICE_TOKEN', 'secret');
        $this->setEnv('ADMIN_EMAIL', 'boss@shop.example');
        $this->setEnv('ADMIN_USER', 'boss');
        $this->setEnv('ADMIN_PASSWORD', 'pw');

        $config = AppConfig::fromEnvironment();

        $this->assertSame(['https://shop.example', 'https://admin.shop.example'], $config->allowedOrigins);
        $this->assertSame(11, $config->maxMessageChars);
        $this->assertSame('secret', $config->mcpServiceToken);
        $this->assertSame('boss@shop.example', $config->adminEmail);
        $this->assertSame('boss', $config->adminUser);
        $this->assertSame('pw', $config->adminPassword);
    }

    public function testKeepsEachKeysPreviousLookupSemantics(): void
    {
        // getenv only (no $_ENV/$_SERVER fallback); a falsy "0" is the default where the old code used ?:
        $_SERVER['MCP_SERVICE_TOKEN'] = 'from-server-array';
        $_ENV['ADMIN_PASSWORD'] = 'from-env-array';
        $this->setEnv('CHATBOT_MAX_MESSAGE_CHARS', '0');
        $this->setEnv('ADMIN_EMAIL', '0');

        $config = AppConfig::fromEnvironment();

        $this->assertSame('', $config->mcpServiceToken);
        $this->assertSame('', $config->adminPassword);
        $this->assertSame(2000, $config->maxMessageChars);
        $this->assertSame('admin@gmail.com', $config->adminEmail);

        $this->setEnv('CHATBOT_MAX_MESSAGE_CHARS', 'abc');
        $this->setEnv('MCP_SERVICE_TOKEN', '0');
        $this->assertSame(0, AppConfig::fromEnvironment()->maxMessageChars);
        $this->assertSame('', AppConfig::fromEnvironment()->mcpServiceToken);
    }
}
