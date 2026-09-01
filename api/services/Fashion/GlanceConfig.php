<?php

final readonly class GlanceConfig
{
    public function __construct(
        public bool $enabled,
        public string $mode,
        public string $mcpUrl,
        public string $toolName,
        public bool $liveVerified
    ) {}

    public static function fromEnvironment(): self
    {
        $enabled = filter_var(getenv('GLANCE_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
        $mode = strtolower(trim((string) (getenv('GLANCE_PROVIDER_MODE') ?: 'demo')));
        if (!in_array($mode, ['disabled', 'demo', 'live'], true)) $mode = 'disabled';
        return new self(
            $enabled,
            $mode,
            trim((string) (getenv('GLANCE_MCP_URL') ?: '')),
            trim((string) (getenv('GLANCE_STYLING_TOOL') ?: '')),
            filter_var(getenv('GLANCE_LIVE_VERIFIED') ?: '0', FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function status(): string
    {
        if (!$this->enabled || $this->mode === 'disabled') return 'DISABLED';
        if ($this->mode === 'demo') return 'DEMO';
        if ($this->mcpUrl === '' || $this->toolName === '' || !$this->liveVerified) return 'CONFIGURED_NOT_VERIFIED';
        return 'LIVE_READY';
    }
}
