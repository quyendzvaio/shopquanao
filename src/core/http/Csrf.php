<?php

/**
 * Per-session CSRF token for state-changing web requests. Works on the session array it is given (never a
 * superglobal): `token()` creates the token on first use, `valid()` compares a submitted value to it.
 */
final class Csrf
{
    public const FIELD = 'csrf_token';

    /** @param array<string,mixed> $session */
    public static function token(array &$session): string
    {
        $token = $session[self::FIELD] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session[self::FIELD] = $token;
        }

        return $token;
    }

    /** @param array<string,mixed> $session */
    public static function valid(array $session, mixed $given): bool
    {
        $expected = $session[self::FIELD] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($given) || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }
}
