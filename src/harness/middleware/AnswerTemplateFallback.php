<?php

/**
 * When the streamed final answer fails or comes back empty, emit the validated
 * grounded draft instead, so an LLM outage never breaks a chat turn. Deltas
 * already sent cannot be withdrawn, so a partial rewrite is kept and the full
 * draft follows it. The returned response carries the failure category in
 * LLMResponse::$fallbackReason.
 */
final class AnswerTemplateFallback extends BaseMiddleware
{
    public const REQUIRED = true;

    public function wrapModelCall(ModelCall $call, callable $next): LLMResponse
    {
        $onDelta = $call->onDelta;
        if ($call->purpose !== 'answer' || !$call->stream || $onDelta === null) {
            return $next($call);
        }

        $draft = $call->groundedDraft ?? '';
        $emitted = '';
        $forward = static function (string $delta) use (&$emitted, $onDelta): void {
            $emitted .= $delta;
            $onDelta($delta);
        };

        try {
            $response = $next($call->withOnDelta($forward));
        } catch (Throwable $error) {
            if ($draft === '') {
                throw $error;
            }
            $reason = $error instanceof LLMTransportException ? $error->category : 'runtime_failure';
            return $this->fallback($reason, $emitted, $draft, $onDelta);
        }

        if (trim($response->content) !== '' || $draft === '') {
            return $response;
        }
        return $this->fallback('empty_answer', $emitted, $draft, $onDelta);
    }

    private function fallback(string $reason, string $emitted, string $draft, Closure $onDelta): LLMResponse
    {
        $tail = trim($emitted) === '' ? $draft : "\n\n" . $draft;
        $onDelta($tail);
        return new LLMResponse(trim($emitted . $tail), 'stop', [], null, $reason);
    }
}
