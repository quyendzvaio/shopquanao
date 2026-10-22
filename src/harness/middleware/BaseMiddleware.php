<?php

/** Pass-through defaults for every hook; subclasses override only the hooks they use. */
abstract class BaseMiddleware implements Middleware
{
    /** A required middleware's afterTurn failure propagates instead of being logged and skipped. */
    public const REQUIRED = false;

    public function beforeTurn(TurnContext $turn): ?TurnResult
    {
        return null;
    }

    public function wrapModelCall(ModelCall $call, callable $next): LLMResponse
    {
        return $next($call);
    }

    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
    {
        return $result;
    }

    public function name(): string
    {
        return (new ReflectionClass($this))->getShortName();
    }
}
