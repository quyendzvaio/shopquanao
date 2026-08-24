<?php

/** Minimal JSON-RPC stdio client for the pinned FindMine MCP server. */
final class FindMineMcpClient implements FindMineMcpClientContract
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $requestId = 0;
    private ?array $initializeResult = null;

    public function __construct(private FindMineConfig $config)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function initialize(): array
    {
        if ($this->initializeResult !== null) return $this->initializeResult;
        $this->start();
        $response = $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'shopquanao-findmine-client', 'version' => '1.0.0'],
        ], $this->config->startupTimeoutMs);
        if (!is_array($response['result'] ?? null)) {
            throw new FindMineProviderException('PROVIDER_UNAVAILABLE', 'FindMine MCP initialization failed', true);
        }
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => (object) []]);
        return $this->initializeResult = $response['result'];
    }

    public function listTools(): array
    {
        $this->initialize();
        $response = $this->request('tools/list', (object) [], $this->config->requestTimeoutMs);
        $tools = $response['result']['tools'] ?? null;
        if (!is_array($tools)) {
            throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine tools/list response is invalid');
        }
        return array_values(array_filter($tools, 'is_array'));
    }

    public function call(string $toolName, array $arguments): array
    {
        if ($toolName !== 'get_complete_the_look') {
            throw new InvalidArgumentException('Only get_complete_the_look is enabled by this adapter');
        }
        $this->initialize();
        $response = $this->request('tools/call', [
            'name' => $toolName,
            'arguments' => (object) $arguments,
        ], $this->config->requestTimeoutMs);
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine MCP tool result is invalid');
        }
        if (!empty($result['isError'])) {
            $message = (string) ($result['content'][0]['text'] ?? 'FindMine MCP tool failed');
            throw $this->mapError($message);
        }
        return $result;
    }

    private function start(): void
    {
        if (is_resource($this->process)) return;
        if (!$this->config->configured()) {
            throw new FindMineProviderException('AUTHENTICATION_ERROR', 'FindMine is not configured');
        }
        if (!is_executable($this->config->node) || !is_file($this->config->script)) {
            throw new FindMineProviderException('PROVIDER_UNAVAILABLE', 'Pinned FindMine MCP artifact is unavailable', true);
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$this->config->node, $this->config->script], $descriptors, $pipes, null, $this->config->childEnvironment());
        if (!is_resource($process)) {
            throw new FindMineProviderException('PROVIDER_UNAVAILABLE', 'Unable to start FindMine MCP', true);
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    private function request(string $method, array|object $params, int $timeoutMs): array
    {
        $id = ++$this->requestId;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            // Check PHP's stream buffer before select(). A prior fgets() may
            // already have buffered the next JSON-RPC line even when the OS
            // descriptor is no longer reported as readable.
            $line = fgets($this->pipes[1]);
            if ($line !== false) {
                $decoded = json_decode(trim($line), true);
                if (!is_array($decoded)) {
                    throw new FindMineProviderException('INVALID_PROVIDER_RESPONSE', 'FindMine MCP returned invalid JSON');
                }
                if (($decoded['id'] ?? null) !== $id) continue;
                if (isset($decoded['error'])) {
                    $message = (string) ($decoded['error']['message'] ?? 'FindMine MCP request failed');
                    throw $this->mapError($message);
                }
                return $decoded;
            }

            $remaining = max(0.001, $deadline - microtime(true));
            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $ready = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) throw new FindMineProviderException('PROVIDER_UNAVAILABLE', 'FindMine MCP read failed', true);
            if ($ready === 0) continue;
        }
        throw new FindMineProviderException('PROVIDER_TIMEOUT', 'FindMine MCP request timed out', true);
    }

    private function send(array $payload): void
    {
        if (!isset($this->pipes[0])) throw new FindMineProviderException('PROVIDER_UNAVAILABLE', 'FindMine MCP is not running', true);
        $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($this->pipes[0], $message);
        if ($written === false || $written !== strlen($message)) {
            throw new FindMineProviderException('PROVIDER_UNAVAILABLE', 'Unable to write to FindMine MCP', true);
        }
        fflush($this->pipes[0]);
    }

    private function mapError(string $message): FindMineProviderException
    {
        $category = match (true) {
            (bool) preg_match('/invalid[_ ]?store|invalid application|authentication|unauthorized|forbidden|\b401\b|\b403\b/i', $message)
                => 'AUTHENTICATION_ERROR',
            (bool) preg_match('/rate.?limit|too many requests|\b429\b/i', $message)
                => 'RATE_LIMITED',
            (bool) preg_match('/unknown (?:provider )?product|product[^\n]*(?:not found|does not exist)|\b404\b/i', $message)
                => 'UNKNOWN_PROVIDER_PRODUCT',
            (bool) preg_match('/validation failed|invalid (?:request|identifier|product_)|missing|required field|bad request|\b400\b|\b422\b/i', $message)
                => 'INVALID_REQUEST',
            (bool) preg_match('/timeout|timed out|aborterror/i', $message)
                => 'PROVIDER_TIMEOUT',
            default => 'PROVIDER_UNAVAILABLE',
        };
        return new FindMineProviderException(
            $category,
            $message,
            in_array($category, ['PROVIDER_TIMEOUT', 'PROVIDER_UNAVAILABLE', 'RATE_LIMITED'], true)
        );
    }

    private function close(): void
    {
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $this->pipes = [];
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }
}
