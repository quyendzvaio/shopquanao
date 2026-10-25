<?php

/** Free-shipping threshold from knowledge/policies.md; the env override is read by ServiceConfig. */
final readonly class FreeShippingPolicy
{
    public function __construct(
        public int $threshold = 500000,
        public int $nudgeMaxGap = 200000,
    ) {
    }
}
