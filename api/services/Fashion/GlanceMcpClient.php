<?php

/** Remote MCP stdio client for the Glance Streamable HTTP MCP endpoint. */
final class GlanceMcpClient implements GlanceMcpClientContract
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private int $requestId = 0;
    private bool $initialized = false;
    private string $stderr = '';

    /** @param list<string>|null $command */
    public function __construct(private GlanceConfig $config, private ?array $command = null, private ?int $timeoutMs = null)
    {
        // Glance is a remote styling provider. It must never inherit the broad
        // internal MCP deadline, otherwise one slow provider call pins a PHP
        // worker and its WebSocket request for the full global timeout.
        $this->timeoutMs = max(1000, $this->timeoutMs ?? (int) (getenv('GLANCE_MCP_TIMEOUT_MS') ?: 30000));
    }

    public function __destruct() { $this->close(); }

    public function call(string $toolName, array $arguments): array
    {
        if (!in_array($toolName, [$this->config->toolName, 'search_fashion_products'], true)) {
            throw new InvalidArgumentException('Unexpected Glance MCP tool');
        }
        $started = microtime(true);
        try {
            $this->initialize();
            $response = $this->request('tools/call', ['name' => $toolName, 'arguments' => (object) $arguments]);
            $result = $response['result'] ?? null;
            if (!is_array($result)) throw new GlanceMcpException('INVALID_PROVIDER_RESPONSE', 'Glance MCP returned an invalid tool result');
            if (!empty($result['isError'])) {
                throw $this->mapError((string) ($result['content'][0]['text'] ?? 'Glance MCP tool failed'));
            }
            $this->observe($toolName, true, null, $started);
            return $result;
        } catch (Throwable $error) {
            $category = $error instanceof GlanceMcpException ? $error->category : 'PROVIDER_UNAVAILABLE';
            $this->observe($toolName, false, $category, $started);
            throw $error;
        }
    }

    private function initialize(): void
    {
        if ($this->initialized) return;
        $this->start();
        $response = $this->request('initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'shopquanao-glance-client', 'version' => '1.0.0'],
        ]);
        if (!is_array($response['result'] ?? null)) throw new GlanceMcpException('PROVIDER_UNAVAILABLE', 'Glance MCP initialization failed', true);
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->initialized = true;
    }

    private function start(): void
    {
        if (is_resource($this->process)) return;
        if (!$this->config->enabled || $this->config->mode !== 'live' || $this->config->mcpUrl === '' || $this->config->toolName === '') {
            throw new GlanceMcpException('CONFIGURATION_ERROR', 'Glance live MCP is not configured');
        }
        $command = $this->command ?? $this->defaultCommand();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) throw new GlanceMcpException('PROVIDER_UNAVAILABLE', 'Unable to start Glance MCP bridge', true);
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /** @return list<string> */
    private function defaultCommand(): array
    {
        $runtimeBridge = '/opt/mcp-server/node_modules/.bin/mcp-remote';
        $command = is_file($runtimeBridge)
            ? ['/usr/bin/node', $runtimeBridge, $this->config->mcpUrl]
            : ['npx', '--yes', 'mcp-remote@0.6.0', $this->config->mcpUrl];

        $authMode = strtolower(trim((string) (getenv('GLANCE_AUTH_MODE') ?: '')));
        if ($authMode !== 'bearer') return $command;
        if (trim((string) (getenv('GLANCE_AUTH_TOKEN') ?: '')) === '') {
            throw new GlanceMcpException('CONFIGURATION_ERROR', 'GLANCE_AUTH_TOKEN is required when GLANCE_AUTH_MODE=bearer');
        }

        // mcp-remote expands the environment placeholder itself. Keeping the token
        // out of argv prevents it from appearing in the process table.
        $command[] = '--header';
        $command[] = 'Authorization:Bearer ${GLANCE_AUTH_TOKEN}';
        return $command;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function request(string $method, array $params): array
    {
        $id = ++$this->requestId;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
        $deadline = microtime(true) + ($this->timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            $this->drainStderr();
            if ($this->bridgeRequiresAuthentication()) {
                throw new GlanceMcpException('AUTHENTICATION_ERROR', 'MANUAL_ACTION_REQUIRED: GLANCE_OAUTH');
            }
            $line = fgets($this->pipes[1]);
            if ($line !== false) {
                $decoded = json_decode(trim($line), true);
                if (!is_array($decoded)) throw new GlanceMcpException('INVALID_PROVIDER_RESPONSE', 'Glance MCP returned invalid JSON');
                if (($decoded['id'] ?? null) !== $id) continue;
                if (isset($decoded['error'])) throw $this->mapError((string) ($decoded['error']['message'] ?? 'Glance MCP request failed'));
                return $decoded;
            }
            // OAuth prompts and bridge failures are written to stderr. Watching
            // only stdout makes an authentication failure appear as a request
            // timeout for the whole MCP deadline.
            $read = [$this->pipes[1], $this->pipes[2]]; $write = null; $except = null;
            $remaining = max(0.001, $deadline - microtime(true));
            $ready = stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
            if ($ready === false) throw new GlanceMcpException('PROVIDER_UNAVAILABLE', 'Glance MCP read failed', true);
            $this->drainStderr();
            if ($this->bridgeRequiresAuthentication()) {
                throw new GlanceMcpException('AUTHENTICATION_ERROR', 'MANUAL_ACTION_REQUIRED: GLANCE_OAUTH');
            }
        }
        $this->drainStderr();
        if ($this->bridgeRequiresAuthentication()) {
            throw new GlanceMcpException('AUTHENTICATION_ERROR', 'MANUAL_ACTION_REQUIRED: GLANCE_OAUTH');
        }
        throw new GlanceMcpException('PROVIDER_TIMEOUT', 'Glance MCP request timed out', true);
    }

    private function bridgeRequiresAuthentication(): bool
    {
        return preg_match(
            '/(?:authorization|authentication)\s+required|please authorize this client|waiting for authorization|authentication-needed|\bunauthorized\b|\bforbidden\b|\b(?:401|403)\b/i',
            $this->stderr
        ) === 1;
    }

    /** Collect diagnostic state only; it is never logged or returned to callers. */
    private function drainStderr(): void
    {
        if (!isset($this->pipes[2])) return;
        while (($line = fgets($this->pipes[2])) !== false) {
            $this->stderr = substr($this->stderr . $line, -4096);
        }
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload): void
    {
        if (!isset($this->pipes[0])) throw new GlanceMcpException('PROVIDER_UNAVAILABLE', 'Glance MCP is not running', true);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->pipes[0], $json) !== strlen($json)) throw new GlanceMcpException('PROVIDER_UNAVAILABLE', 'Unable to write Glance MCP request', true);
        fflush($this->pipes[0]);
    }

    private function mapError(string $message): GlanceMcpException
    {
        $category = match (true) {
            (bool) preg_match('/oauth|authorization|unauthorized|forbidden|\b401\b|\b403\b/i', $message) => 'AUTHENTICATION_ERROR',
            (bool) preg_match('/timeout|timed out|abort/i', $message) => 'PROVIDER_TIMEOUT',
            (bool) preg_match('/rate.?limit|\b429\b/i', $message) => 'RATE_LIMITED',
            default => 'PROVIDER_UNAVAILABLE',
        };
        return new GlanceMcpException($category, $message, in_array($category, ['PROVIDER_TIMEOUT', 'RATE_LIMITED', 'PROVIDER_UNAVAILABLE'], true));
    }

    private function observe(string $toolName, bool $success, ?string $failureCategory, float $started): void
    {
        error_log(json_encode([
            'provider' => 'glance',
            'operation' => 'mcp_tool_call',
            'tool' => $toolName,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'timeout_ms' => $this->timeoutMs,
            'success' => $success,
            'failure_category' => $failureCategory,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function close(): void
    {
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $this->pipes = [];
        if (is_resource($this->process)) { proc_terminate($this->process); proc_close($this->process); }
        $this->process = null;
    }
}
