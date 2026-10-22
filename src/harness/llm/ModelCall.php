<?php

/** One LLM call as seen by middleware; $options never contains the reserved 'harness' key. */
final readonly class ModelCall
{
    /**
     * @param string $purpose                       input_parse | entity_enrich | answer | unspecified
     * @param Closure(string):void|null $onDelta    set only for streaming calls
     */
    public function __construct(
        public string $purpose,
        public array $messages,
        public array $tools,
        public string $toolChoice,
        public array $options,
        public ?string $groundedDraft,
        public ?Closure $onDelta,
        public bool $stream,
    ) {
    }

    public function withOnDelta(Closure $onDelta): self
    {
        return new self(
            $this->purpose,
            $this->messages,
            $this->tools,
            $this->toolChoice,
            $this->options,
            $this->groundedDraft,
            $onDelta,
            $this->stream,
        );
    }
}
