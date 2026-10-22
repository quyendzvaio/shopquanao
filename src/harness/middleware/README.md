# middleware

The plug-in mechanism of the harness: three hooks, listed in `config/harness.php`.

- Entry points: the `Middleware` interface (`beforeTurn`, `wrapModelCall`, `afterTurn`), `BaseMiddleware` (pass-through
  defaults, `REQUIRED` flag) and `MiddlewareStack` (runs them in config order).
- Implementations: `CheckoutGuardrail` and `AnswerTemplateFallback` (required, cannot be switched off),
  `FreeShippingNudge` and `TraceOutbox` (optional). Table and "how to add" steps: `../README.md`.
- May use: `turn/` (only `TurnContext`, `TurnResult`), `llm/`, `intent/` (only `CheckoutDetector`), `response/`,
  `evidence/`, plus service/model/core.
