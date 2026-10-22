<?php

/** A pluggable harness behaviour hooked into a chat turn at three points, run in config/harness.php order. */
interface Middleware
{
    /** Return a TurnResult to short-circuit the turn (no intent/tool/evidence work), or null to continue. */
    public function beforeTurn(TurnContext $turn): ?TurnResult;

    /**
     * Wrap one LLM call; $next performs the real call (or the next middleware).
     *
     * @param callable(ModelCall):LLMResponse $next
     */
    public function wrapModelCall(ModelCall $call, callable $next): LLMResponse;

    /** Receive the turn result and return it, possibly amended. */
    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult;

    public function name(): string;
}
