<?php

declare(strict_types=1);

/** Test helper: sets/unsets env keys across getenv, $_ENV and $_SERVER, and restores them. */
trait EnvSandbox
{
    private const ENV_KEYS = [
        'APP_ENV', 'CHATBOT_TOOL_TRANSPORT', 'CHATBOT_FORCE_L0', 'CHATBOT_TEMPLATE_RESPONSE',
        'LLM_PROVIDER', 'LLM_API_KEY', 'LLM_BASE_URL', 'LLM_MODEL', 'LLM_STREAM_MODEL', 'LLM_TIMEOUT',
        'MCP_SERVICE_TOKEN', 'MCP_REQUEST_TIMEOUT', 'MCP_STDIO_NODE', 'MCP_STDIO_SCRIPT',
        'SHOP_INTERNAL_URL', 'MCP_REQUEST_TIMEOUT_MS', 'FREE_SHIPPING_THRESHOLD', 'FREESHIP_NUDGE_MAX_GAP',
    ];
    /** @var array<string,array{0:string|false,1:mixed,2:mixed}> */
    private array $envSnapshot = [];

    private function cleanEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = [
                getenv($key),
                $_ENV[$key] ?? null,
                $_SERVER[$key] ?? null,
            ];
            $this->setEnv($key, null);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }

    private function restoreEnv(): void
    {
        foreach ($this->envSnapshot as $key => [$env, $envVar, $server]) {
            $env === false ? putenv($key) : putenv("$key=$env");
            $envVar === null ? $this->unsetGlobal($_ENV, $key) : $_ENV[$key] = $envVar;
            $server === null ? $this->unsetGlobal($_SERVER, $key) : $_SERVER[$key] = $server;
        }
        $this->envSnapshot = [];
    }

    private function unsetGlobal(array &$bag, string $key): void
    {
        unset($bag[$key]);
    }
}
