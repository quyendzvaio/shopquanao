<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsCreatedOnceAndStoredInTheSession(): void
    {
        $session = [];

        $first = Csrf::token($session);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, $session['csrf_token']);
        $this->assertSame($first, Csrf::token($session));
    }

    public function testTwoSessionsGetDifferentTokens(): void
    {
        $a = [];
        $b = [];

        $this->assertNotSame(Csrf::token($a), Csrf::token($b));
    }

    public function testTheSessionTokenIsValid(): void
    {
        $session = [];
        $token = Csrf::token($session);

        $this->assertTrue(Csrf::valid($session, $token));
    }

    public function testAWrongTokenIsInvalid(): void
    {
        $session = [];
        Csrf::token($session);

        $this->assertFalse(Csrf::valid($session, str_repeat('0', 64)));
        $this->assertFalse(Csrf::valid($session, ''));
    }

    public function testAMissingOrNonStringGivenTokenIsInvalid(): void
    {
        $session = [];
        $token = Csrf::token($session);

        $this->assertFalse(Csrf::valid($session, null));
        $this->assertFalse(Csrf::valid($session, [$token]));
        $this->assertFalse(Csrf::valid($session, 12345));
    }

    public function testASessionWithoutATokenNeverValidates(): void
    {
        $this->assertFalse(Csrf::valid([], 'anything'));
        $this->assertFalse(Csrf::valid([], null));
        $this->assertFalse(Csrf::valid([], ''));
        $this->assertFalse(Csrf::valid(['csrf_token' => ''], ''));
        $this->assertFalse(Csrf::valid(['csrf_token' => ['x']], 'x'));
    }
}
