<?php

/** Free-shipping threshold from knowledge/policies.md, overridable per env. */
final readonly class FreeShippingPolicy
{
    public function __construct(
        public int $threshold = 500000,
        public int $nudgeMaxGap = 200000,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            max(0, self::intEnv('FREE_SHIPPING_THRESHOLD', 500000)),
            max(0, self::intEnv('FREESHIP_NUDGE_MAX_GAP', 200000))
        );
    }

    /** PHP-FPM may run with clear_env, so fall back to $_ENV/$_SERVER. */
    private static function intEnv(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return $value === '' ? $default : (int)$value;
    }
}
