<?php

final readonly class FindMineConfig
{
    public function __construct(
        public bool $enabled,
        public string $apiUrl,
        public string $appId,
        public string $apiVersion,
        public string $defaultRegion,
        public string $defaultLanguage,
        public string $node,
        public string $script,
        public int $startupTimeoutMs,
        public int $requestTimeoutMs,
        public int $retryAttempts,
        public string $productIdentifierKey = 'product_id',
        public string $colorIdentifierKey = 'product_color_id',
        public bool $liveVerified = false,
        public bool $demoMode = false
    ) {
        if ($this->apiUrl !== '' && !filter_var($this->apiUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('FINDMINE_API_URL must be a valid URL');
        }
        if ($this->retryAttempts < 0 || $this->retryAttempts > 3) {
            throw new InvalidArgumentException('FINDMINE_RETRY_ATTEMPTS must be between 0 and 3');
        }
    }

    public static function fromEnvironment(): self
    {
        $enabled = filter_var(getenv('FINDMINE_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
        $apiUrl = rtrim((string) (getenv('FINDMINE_API_URL') ?: 'https://api.findmine.com'), '/');
        $appId = trim((string) (getenv('FINDMINE_APP_ID') ?: ''));
        return new self(
            $enabled,
            $apiUrl,
            $appId,
            trim((string) (getenv('FINDMINE_API_VERSION') ?: 'v3')),
            trim((string) (getenv('FINDMINE_DEFAULT_REGION') ?: 'us')),
            trim((string) (getenv('FINDMINE_DEFAULT_LANGUAGE') ?: 'en')),
            (string) (getenv('FINDMINE_MCP_NODE') ?: '/usr/bin/node'),
            (string) (getenv('FINDMINE_MCP_SCRIPT') ?: '/opt/findmine-mcp/build/index.js'),
            max(250, (int) (getenv('FINDMINE_STARTUP_TIMEOUT_MS') ?: '5000')),
            max(500, (int) (getenv('FINDMINE_REQUEST_TIMEOUT_MS') ?: '15000')),
            max(0, min(3, (int) (getenv('FINDMINE_RETRY_ATTEMPTS') ?: '1'))),
            trim((string) (getenv('FINDMINE_PRODUCT_IDENTIFIER_KEY') ?: 'product_id')),
            trim((string) (getenv('FINDMINE_COLOR_IDENTIFIER_KEY') ?: 'product_color_id')),
            filter_var(getenv('FINDMINE_LIVE_VERIFIED') ?: '0', FILTER_VALIDATE_BOOLEAN),
            filter_var(getenv('FINDMINE_DEMO_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function configured(): bool
    {
        if (!$this->enabled || $this->appId === '') return false;
        if ($this->demoMode) return $this->appId === 'DEMO_APP_ID';
        return $this->appId !== 'DEMO_APP_ID';
    }

    public function ready(): bool
    {
        return $this->configured() && is_executable($this->node) && is_file($this->script);
    }

    public function status(): string
    {
        if (!$this->enabled) return 'DISABLED';
        if ($this->demoMode && $this->ready()) return 'DEMO_READY';
        if (!$this->ready() || !$this->liveVerified) return 'CONFIGURED_NOT_VERIFIED';
        return 'LIVE_READY';
    }

    public function childEnvironment(): array
    {
        return [
            'NODE_ENV' => 'production',
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'FINDMINE_API_URL' => $this->apiUrl,
            'FINDMINE_APP_ID' => $this->appId,
            'FINDMINE_API_VERSION' => $this->apiVersion,
            'FINDMINE_DEFAULT_REGION' => $this->defaultRegion,
            'FINDMINE_DEFAULT_LANGUAGE' => $this->defaultLanguage,
            'FINDMINE_REQUEST_TIMEOUT_MS' => (string) $this->requestTimeoutMs,
            'FINDMINE_DEMO_MODE' => $this->demoMode ? 'true' : 'false',
        ];
    }
}
