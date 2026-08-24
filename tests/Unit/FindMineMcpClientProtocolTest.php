<?php

use PHPUnit\Framework\TestCase;

final class FindMineMcpClientProtocolTest extends TestCase
{
    public function testInitializeThenToolDiscoveryUsesMcpObjectParams(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'findmine-mcp-fixture-');
        self::assertNotFalse($script);
        file_put_contents($script, <<<'PHP'
<?php
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line);
    if (!isset($request->id)) continue;
    if ($request->method === 'initialize') {
        $result = ['protocolVersion' => '2025-11-25'];
    } elseif ($request->method === 'tools/list' && isset($request->params) && is_object($request->params)) {
        $result = ['tools' => [['name' => 'get_complete_the_look']]];
    } else {
        fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $request->id, 'error' => ['message' => 'invalid params']]) . "\n");
        fflush(STDOUT);
        continue;
    }
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $request->id, 'result' => $result]) . "\n");
    fflush(STDOUT);
}
PHP);

        try {
            $config = new FindMineConfig(
                true,
                'https://api.findmine.com',
                'protocol-test',
                'v3',
                'us',
                'en',
                PHP_BINARY,
                $script,
                1000,
                1000,
                0
            );
            $client = new FindMineMcpClient($config);

            self::assertSame('2025-11-25', $client->initialize()['protocolVersion']);
            self::assertSame('get_complete_the_look', $client->listTools()[0]['name']);
        } finally {
            unlink($script);
        }
    }
}
