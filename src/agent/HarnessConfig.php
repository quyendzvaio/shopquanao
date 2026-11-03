<?php

/**
 * Every environment-derived setting PHP needs for the chat-graph hand-off and the tool bridge, read once.
 * PHP-FPM may run with clear_env, so each key falls back getenv -> $_ENV -> $_SERVER.
 * Domain-service settings (and the free-shipping policy built from them) come from
 * ServiceConfig, which keeps each key's own lookup.
 */
final readonly class HarnessConfig
{
    public function __construct(
        public FreeShippingPolicy $freeShipping = new FreeShippingPolicy(),
        public ServiceConfig $services = new ServiceConfig(),
        public string $chatGraphUrl = 'http://chat-graph:8090',
        public string $chatGraphToken = '',
        public int $chatGraphTimeout = 50,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $services = ServiceConfig::fromEnvironment();

        return new self(
            freeShipping: new FreeShippingPolicy($services->freeShippingThreshold, $services->freeshipNudgeMaxGap),
            services: $services,
            chatGraphUrl: rtrim(self::env('CHAT_GRAPH_URL') ?: 'http://chat-graph:8090', '/'),
            chatGraphToken: self::env('CHAT_GRAPH_TOKEN'),
            chatGraphTimeout: max(1, (int) (self::env('CHAT_GRAPH_TIMEOUT') ?: 50)),
        );
    }

    private static function env(string $key): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return (string) ($_ENV[$key] ?? $_SERVER[$key] ?? '');
    }
}
