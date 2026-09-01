<?php

/**
 * Optional capability for providers that can deliver assistant text deltas.
 *
 * Keeping this as a capability interface (rather than adding a method to
 * LLMProvider) preserves the existing structured/tool-call test doubles and
 * makes streaming support explicit at the runtime seam.
 */
interface StreamingLLMProvider
{
    /**
     * Stream assistant content and invoke $onDelta for every non-empty chunk.
     * The returned response contains the accumulated content and final usage.
     *
     * @param callable(string):void $onDelta
     */
    public function chatStream(array $messages, callable $onDelta, array $options = []): LLMResponse;
}
