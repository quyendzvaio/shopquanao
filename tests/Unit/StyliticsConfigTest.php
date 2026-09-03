<?php

final class StyliticsConfigTest extends \PHPUnit\Framework\TestCase
{
    protected function tearDown(): void
    {
        foreach (['STYLITICS_ENABLED', 'STYLITICS_PROVIDER_MODE', 'STYLITICS_API_URL', 'STYLITICS_API_KEY', 'STYLITICS_LIVE_VERIFIED'] as $key) {
            putenv($key);
        }
        parent::tearDown();
    }

    private function fromEnv(): StyliticsConfig
    {
        return StyliticsConfig::fromEnvironment();
    }

    public function testDefaultsToDisabledWhenUnset(): void
    {
        $config = $this->fromEnv();
        self::assertFalse($config->enabled);
        self::assertSame('demo', $config->mode);
        self::assertSame('DISABLED', $config->status());
    }

    public function testUnknownModeFallsBackToDisabled(): void
    {
        putenv('STYLITICS_ENABLED=true');
        putenv('STYLITICS_PROVIDER_MODE=staging');
        $config = $this->fromEnv();
        self::assertSame('disabled', $config->mode);
        self::assertSame('DISABLED', $config->status());
    }

    public function testDemoModeIsDemoStatus(): void
    {
        putenv('STYLITICS_ENABLED=true');
        putenv('STYLITICS_PROVIDER_MODE=demo');
        self::assertSame('DEMO', $this->fromEnv()->status());
    }

    public function testLiveWithoutVerificationIsConfiguredNotVerified(): void
    {
        putenv('STYLITICS_ENABLED=true');
        putenv('STYLITICS_PROVIDER_MODE=live');
        putenv('STYLITICS_API_URL=https://staging.example.com/complete-the-look');
        putenv('STYLITICS_API_KEY=staging-key');
        putenv('STYLITICS_LIVE_VERIFIED=false');
        self::assertSame('CONFIGURED_NOT_VERIFIED', $this->fromEnv()->status());
    }

    public function testLiveVerifiedBecomesLiveReady(): void
    {
        putenv('STYLITICS_ENABLED=true');
        putenv('STYLITICS_PROVIDER_MODE=live');
        putenv('STYLITICS_API_URL=https://staging.example.com/complete-the-look');
        putenv('STYLITICS_API_KEY=staging-key');
        putenv('STYLITICS_LIVE_VERIFIED=true');
        self::assertSame('LIVE_READY', $this->fromEnv()->status());
    }

    public function testLiveLiveReadyRequiresUrlAndKey(): void
    {
        putenv('STYLITICS_ENABLED=true');
        putenv('STYLITICS_PROVIDER_MODE=live');
        putenv('STYLITICS_LIVE_VERIFIED=true');
        self::assertSame('CONFIGURED_NOT_VERIFIED', $this->fromEnv()->status());
    }
}
