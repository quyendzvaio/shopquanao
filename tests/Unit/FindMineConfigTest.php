<?php
final class FindMineConfigTest extends \PHPUnit\Framework\TestCase
{
    public function testReportsExplicitOperationalStates(): void
    {
        $disabled = new FindMineConfig(false, 'https://api.findmine.com', '', 'v3', 'us', 'en', '/bin/true', __FILE__, 5000, 15000, 1);
        self::assertSame('DISABLED', $disabled->status());

        $configured = new FindMineConfig(true, 'https://api.findmine.com', 'assigned-app', 'v3', 'us', 'en', '/bin/true', __FILE__, 5000, 15000, 1);
        self::assertSame('CONFIGURED_NOT_VERIFIED', $configured->status());

        $live = new FindMineConfig(true, 'https://api.findmine.com', 'assigned-app', 'v3', 'us', 'en', '/bin/true', __FILE__, 5000, 15000, 1, 'product_id', 'product_color_id', true);
        self::assertSame('LIVE_READY', $live->status());
        self::assertSame('production', $live->childEnvironment()['NODE_ENV']);
        self::assertSame('15000', $live->childEnvironment()['FINDMINE_REQUEST_TIMEOUT_MS']);

        $demo = new FindMineConfig(true, 'https://api.findmine.com', 'DEMO_APP_ID', 'v3', 'us', 'en', '/bin/true', __FILE__, 5000, 15000, 1, 'product_id', 'product_color_id', false, true);
        self::assertTrue($demo->configured());
        self::assertSame('DEMO_READY', $demo->status());
        self::assertSame('production', $demo->childEnvironment()['NODE_ENV']);
        self::assertSame('true', $demo->childEnvironment()['FINDMINE_DEMO_MODE']);

        $unsafeDemo = new FindMineConfig(true, 'https://api.findmine.com', 'DEMO_APP_ID', 'v3', 'us', 'en', '/bin/true', __FILE__, 5000, 15000, 1);
        self::assertFalse($unsafeDemo->configured());
    }
}
