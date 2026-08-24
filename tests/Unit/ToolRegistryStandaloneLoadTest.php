<?php

use PHPUnit\Framework\TestCase;

final class ToolRegistryStandaloneLoadTest extends TestCase
{
    public function testProductionEntryPointCanLoadToolRegistryWithoutTestBootstrap(): void
    {
        $registry = ROOT_DIR . '/api/controllers/chatbot/ToolRegistry.php';
        $script = 'require ' . var_export($registry, true) . '; echo class_exists("ToolRegistry") ? "loaded" : "missing";';
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame('loaded', implode("\n", $output));
    }
}
