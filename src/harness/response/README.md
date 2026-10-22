# response

Builds the answer from normalized evidence only.

- Entry points: `ResponseGenerator::generate()` (grounded template draft; also owns the checkout refusal text,
  `checkoutRefusal()`) and `StreamingResponseGenerator::stream()` (asks the LLM to rewrite the grounded draft).
- The LLM-failure fallback is not here: the required `AnswerTemplateFallback` middleware wraps the model call and
  emits the grounded draft instead; this class reads `LLMResponse::$fallbackReason`.
- May use: `llm/` (interface only), plus service/model/core.
