<?php

final readonly class StyliticsConfig
{
    public function __construct(
        public bool $enabled,
        public string $mode,
        public string $apiUrl,
        public string $apiKey,
        public bool $liveVerified
    ) {}

    public static function fromEnvironment(): self
    {
        $enabled = filter_var(getenv('STYLITICS_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
        $mode = strtolower(trim((string) (getenv('STYLITICS_PROVIDER_MODE') ?: 'demo')));
        if (!in_array($mode, ['disabled', 'demo', 'live'], true)) $mode = 'disabled';
        return new self(
            $enabled,
            $mode,
            trim((string) (getenv('STYLITICS_API_URL') ?: '')),
            trim((string) (getenv('STYLITICS_API_KEY') ?: '')),
            filter_var(getenv('STYLITICS_LIVE_VERIFIED') ?: '0', FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function status(): string
    {
        if (!$this->enabled || $this->mode === 'disabled') return 'DISABLED';
        if ($this->mode === 'demo') return 'DEMO';
        if ($this->apiUrl === '' || $this->apiKey === '' || !$this->liveVerified) return 'CONFIGURED_NOT_VERIFIED';
        return 'LIVE_READY';
    }
}
