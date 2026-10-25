# loop

The evidence execution loop: plan, run tools, judge the evidence, decide the next step. A PHP-rule retry loop,
not model reasoning.

- Entry point: `EvidenceExecutionLoop::run(message, intent, memoryContext)`; it emits a `decision` (`fallback` forces `response_type=fallback`).
- Pieces: `ParallelToolExecutor`, `NoProgressDetector`, `EvidenceDecisionRouter`. Retries and query rewrites are bounded by the loop budget.
- Tool retries stay here (they belong to the loop budget); there is no tool-call middleware.
- May use: `planning/`, `tools/`, `evidence/`, plus service/model/core.
