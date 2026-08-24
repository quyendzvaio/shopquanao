<?php

require_once __DIR__ . '/contracts/ChatbotToolGateway.php';
require_once __DIR__ . '/ToolDefinitionCatalog.php';

/** MCP stdio client. The Node server is a private child process of PHP. */
final class McpToolGateway implements ChatbotToolGateway
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $requestId = 0;
    private bool $initialized = false;
    private int $timeoutSeconds;

    public function __construct(private ?int $userId)
    {
        if ((string) (getenv('MCP_SERVICE_TOKEN') ?: '') === '') {
            throw new RuntimeException('MCP_SERVICE_TOKEN is not configured');
        }
        $this->timeoutSeconds = max(1, (int) (getenv('MCP_REQUEST_TIMEOUT') ?: 35));
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getDefinitions(): array
    {
        return ToolDefinitionCatalog::chatbotDefinitions();
    }

    public function execute(string $toolName, array $arguments): array
    {
        $this->ensureInitialized();
        $response = $this->request('tools/call', [
            'name' => $toolName,
            'arguments' => (object) $arguments,
        ]);
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        if (!empty($result['isError'])) {
            $message = (string) ($result['content'][0]['text'] ?? 'MCP tool call failed');
            throw new RuntimeException($message);
        }
        $data = $result['structuredContent']['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('MCP tool returned invalid structuredContent');
        }
        return $data;
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->startProcess();
        $response = $this->request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'fashion-shop-php-chatbot', 'version' => '1.0.0'],
        ]);
        if (!isset($response['result'])) {
            throw new RuntimeException('MCP initialization failed');
        }
        $this->send([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => (object) [],
        ]);
        $this->initialized = true;
    }

    private function request(string $method, array $params): array
    {
        $id = ++$this->requestId;
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);
        $response = $this->receive($id);
        if (isset($response['error'])) {
            throw new RuntimeException((string) ($response['error']['message'] ?? 'MCP request failed'));
        }
        return $response;
    }

    private function startProcess(): void
    {
        if (is_resource($this->process)) {
            return;
        }
        $configuredNode = (string) (getenv('MCP_STDIO_NODE') ?: '/usr/bin/node');
        $nodeCandidates = array_values(array_unique([
            $configuredNode,
            '/usr/local/bin/node',
            '/usr/bin/node',
        ]));
        $node = '';
        foreach ($nodeCandidates as $candidate) {
            if (is_executable($candidate)) {
                $node = $candidate;
                break;
            }
        }
        if ($node === '') {
            throw new RuntimeException('MCP Node executable not found; checked: ' . implode(', ', $nodeCandidates));
        }
        $script = (string) (getenv('MCP_STDIO_SCRIPT') ?: '/opt/mcp-server/dist/stdio.js');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = [
            'NODE_ENV' => 'production',
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'MCP_SERVICE_TOKEN' => (string) getenv('MCP_SERVICE_TOKEN'),
            'SHOP_INTERNAL_URL' => (string) (getenv('SHOP_INTERNAL_URL') ?: 'http://127.0.0.1/api/internal/mcp'),
            'MCP_REQUEST_TIMEOUT_MS' => (string) (getenv('MCP_REQUEST_TIMEOUT_MS') ?: '30000'),
            'MCP_PRINCIPAL_USER_ID' => $this->userId === null ? '' : (string) $this->userId,
        ];
        $process = proc_open([$node, $script], $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start MCP stdio process');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    private function send(array $payload): void
    {
        if (!isset($this->pipes[0])) {
            throw new RuntimeException('MCP stdio process is not running');
        }
        $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $written = 0;
        while ($written < strlen($message)) {
            $count = fwrite($this->pipes[0], substr($message, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Unable to write to MCP stdio process');
            }
            $written += $count;
        }
        fflush($this->pipes[0]);
    }

    private function receive(int $expectedId): array
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $remaining = max(0.001, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $ready = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                throw new RuntimeException('Failed while waiting for MCP stdio response');
            }
            if ($ready === 0) {
                continue;
            }
            $line = fgets($this->pipes[1]);
            if ($line === false) {
                $status = is_resource($this->process) ? proc_get_status($this->process) : ['running' => false];
                if (!($status['running'] ?? false)) {
                    throw new RuntimeException('MCP stdio process exited: ' . $this->readStderr());
                }
                continue;
            }
            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('MCP stdio returned invalid JSON');
            }
            if (($decoded['id'] ?? null) === $expectedId) {
                return $decoded;
            }
        }
        throw new RuntimeException('MCP stdio request timed out: ' . $this->readStderr());
    }

    private function readStderr(): string
    {
        return isset($this->pipes[2]) ? trim((string) stream_get_contents($this->pipes[2])) : '';
    }

    private function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }
}
