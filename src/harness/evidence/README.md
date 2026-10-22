# evidence

Turns raw tool output into grounded, verified facts.

- Entry points: `EvidenceNormalizer`, `ProductConstraintVerifier` (every product card must pass it against the private
  catalog), `ObservationEvaluator`; `LightweightEvidenceScorer` scores evidence for the loop.
- Rule: product cards come only from the private catalog; nothing here talks to the LLM.
- May use: nothing else in the harness (plus service/model/core).
