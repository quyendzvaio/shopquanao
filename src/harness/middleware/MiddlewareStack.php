<?php

/** Runs a list of middleware: beforeTurn until the first blocker, wrapModelCall as an onion, afterTurn in sequence. */
final class MiddlewareStack
{
    /** @var list<Middleware> */
    private array $middleware;

    /**
     * @param list<Middleware> $middleware index 0 runs first and is the outermost model-call wrapper
     */
    public function __construct(array $middleware)
    {
        $this->middleware = array_values($middleware);
    }

    public function beforeTurn(TurnContext $turn): ?TurnResult
    {
        foreach ($this->middleware as $middleware) {
            $result = $middleware->beforeTurn($turn);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * @param callable(ModelCall):LLMResponse $terminal the real provider call
     */
    public function wrapModelCall(ModelCall $call, callable $terminal): LLMResponse
    {
        $next = $terminal;
        foreach (array_reverse($this->middleware) as $middleware) {
            $inner = $next;
            $next = static fn (ModelCall $call): LLMResponse => $middleware->wrapModelCall($call, $inner);
        }
        return $next($call);
    }

    public function afterTurn(TurnContext $turn, TurnResult $result): TurnResult
    {
        foreach ($this->middleware as $middleware) {
            try {
                $result = $middleware->afterTurn($turn, $result);
            } catch (Throwable $e) {
                if ($middleware instanceof BaseMiddleware && $middleware::REQUIRED) {
                    throw $e;
                }
                error_log(json_encode([
                    'operation' => 'middleware_' . $middleware->name(),
                    'success' => false,
                    'error_category' => 'suppressed_runtime_failure',
                ]));
            }
        }
        return $result;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (Middleware $middleware): string => $middleware->name(), $this->middleware);
    }
}
