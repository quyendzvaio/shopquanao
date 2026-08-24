# FindMine real integration gate results

> This document tracks the real-tenant gate only. Demo-grounded UC1/UC2 verification now passes 70/70 with RAGAS/LangSmith evidence; see `findmine-agent-evaluation-results.md` and `findmine-ragas-results.md`. Demo evidence is not presented as proof of live tenant connectivity.

## Continuation gate log — 2026-08-24

### Task 1 — Recheck tenant access

```text
attempted: .env variants, repository/deployment references, running app environment, CI secret references, docs, and shell configuration references
evidence: .env contains no FindMine tenant keys; .env.local and .env.production are absent; running FINDMINE_APP_ID is empty; CI and shell configuration have no tenant reference
files changed: docs/findmine-real-integration-results.md
verification: redacted key-state inspection; no secret values printed
result: FINDMINE_TENANT_CONFIG=MISSING
blocker: vendor-assigned application identifier and tenant catalog data are unavailable
```

### Task 2 — Finalize onboarding request

```text
attempted: review the operator email against the seven required tenant facts and pinned production artifact
evidence: request names FINDMINE_APP_ID, CTL v3 enablement, product_* schema, feed method, identity semantics, known-good mapping, and region/language
files changed: docs/findmine-onboarding-request.md; docs/findmine-real-integration-results.md
verification: required-term check and git diff --check
result: PASS
blockers: none for the request document
```

### Task 3 — Exact operator action

```text
attempted: convert the external dependency into a numbered operator handoff
evidence: ACTION REQUIRED NOW lists vendor contact, App ID/CTL enablement, product_* schema, catalog onboarding, known-good mapping, safe configuration, and Task 4 resume point
files changed: docs/findmine-live-onboarding-guide.md; docs/findmine-real-integration-results.md
verification: required-step/status check and git diff --check
result: FINDMINE_EXTERNAL_ONBOARDING_STATUS=AWAITING_VENDOR
blocker: the operator must contact FindMine manually and wait for tenant provisioning
```

### Task 4 — Configure real tenant

```text
attempted: inspect production-like configuration state and invoke the deployed live inspector without substituting a fake tenant
evidence: FINDMINE_CONFIG_STATUS=DISABLED; FINDMINE_APP_ID_STATE=EMPTY; live inspector exited 2 with an explicit blocked diagnostic
files changed: none for runtime configuration; no tenant value was written
verification: FindMineConfig runtime status plus scripts/findmine_live_inspect.php fail-fast execution
result: BLOCKED
blocker: real FINDMINE_APP_ID has not been assigned
```

Tasks 5–44 are not executed in this continuation because Task 5 requires the actual onboarding response and all provider-dependent tasks inherit that gate. Previously verified offline/event results remain separately labeled; they are not promoted to live evidence.

## 1. Provider Artifact

```text
artifact: https://github.com/findmine/findmine-mcp
version: 0.2.0
Git SHA: 28a15b86ac0a7b212336748005393f88bcbfdad1
transport: stdio JSON-RPC MCP, protocol 2025-11-25
Complete-the-Look tool: get_complete_the_look
```

## 2. Authentication

```text
authentication model: application identifier assigned at integration start
tenant model: application plus tenant-specific catalog product_* identifiers
required config: FINDMINE_APP_ID and verified identifier mapping
verified: local configuration and fail-fast behavior only; live tenant BLOCKED
```

No secret or tenant ID is printed or fabricated.

## 3. Catalog Integration

```text
ingestion mechanism: requires tenant-specific confirmation from FindMine; no generic MCP ingestion tool exists
identity ownership: requires tenant confirmation
update semantics: requires tenant confirmation
offline sync/import implementation: dry-run capable idempotent mapping importer with generic product_* JSON
```

## 4. Known-Good Mapping

```text
shop product: unavailable
shop variant: unavailable
canonical color: unavailable
provider product identity: unavailable
provider variant/color identity: unavailable
mapping verified: no (0 synced FindMine mappings)
```

## 5. FindMineFashionProvider

```text
implemented: yes
timeout: finite MCP startup, tool-call, and downstream HTTP timeouts
retry policy: bounded transient-only adapter retries; upstream broad retries disabled
response validation: strict v3/MCP validation with response_uuid and provider item provenance
domain error mapping: authentication, invalid request, unknown product, timeout, rate limit, unavailable, invalid response, empty recommendation
```

## 6. Connectivity Smoke

```text
BLOCKED
duration: not measurable live
real provider called: no
tool discovered: yes locally in production mode; not authenticated live
```

## 7. Complete-the-Look Smoke

```text
BLOCKED
anchor accepted: not tested live
provider requirements returned: none
mapper validation: contract tests PASS; real response pending
```

## 8. E2E FindMine → Product Search Smoke

```text
BLOCKED
FindMine requirements: none live
normalized requirements: none live
parallel searches: implementation/tests PASS; live run blocked
shop matches: none live
zero-result groups: offline tests PASS
provider-ID leakage: offline invariant PASS
```

The separate production cart-event runtime smoke passes HTTP cart, transactional outbox, Redis Streams, idempotent consumer, pending state, and two actual chatbot HTTP turns.

## 9. Regression Suite

```text
tests: 158 PASS
assertions: 536 PASS
static analysis: PHPStan PASS; PHP syntax PASS
build: shop MCP tests/build PASS; pinned FindMine build and 45 upstream tests PASS
Docker: Compose validation, production image build, and service health PASS
HTTP: product health, cart event pipeline, and proactive chat turn smoke PASS
```

## 10. Remaining Risks

- Missing real `FINDMINE_APP_ID`, tenant catalog contract, and one known-good mapping block every provider-dependent live gate.
- Live response/failure shapes, taxonomy terms, rate-limit behavior, and end-to-end latency remain unobserved.
- The pinned upstream production dependency tree reports six npm audit findings (one low, two moderate, three high); changing them requires coordination because the artifact SHA is mandated.
- Cold local MCP initialize + discovery measured 277/272/277 ms. Reuse should be assessed only with real request and Apache lifecycle measurements.

## 11. Final Gate

```text
FINDMINE_INTEGRATION_GATE=BLOCKED
```

Exact blocker: FindMine onboarding has not supplied `FINDMINE_APP_ID`, the tenant `product_*` identifier schema/catalog confirmation, or a known-good provider mapping.

## Demo architecture continuation — 2026-08-24

The tenant gate above remains correctly blocked for real FindMine production access. The requested demo architecture is independently verified through the pinned MCP sample response:

```text
FASHION_PROVIDER=findmine_demo
FINDMINE_DEMO_MCP_STATUS=PASS
FASHION_EXTRACTION_STATUS=FAIL
EXTRACTION_SCHEMA_VALID_RATE=53.13% (32-case live evaluator; DeepSeek rate/structured-output failures)
EXTRACTION_HALLUCINATED_ATTRIBUTE_COUNT=0 in focused contract tests; live evaluator blocked by provider failures
FASHION_NORMALIZATION_STATUS=PASS
USE_CASE_1_STATUS=PASS (real MCP stdio gateway → demo provider → real shop IDs)
CART_EVENT_PIPELINE_STATUS=PASS
USE_CASE_2_STATUS=PASS (live HTTP cart → outbox → Redis Streams → consumer → two turns → demo provider → shop cards)
AGENT_EVAL_STATUS=FAIL (50/50 deterministic parser fixture only; no live provider cases)
AGENT_EVAL_CASES=50
RAGAS_STATUS=NOT_APPLICABLE (no final RAGAS corpus supplied)
HALLUCINATED_PRODUCT_COUNT=0
FASHION_INTEGRATION_GATE=BLOCKED
```

Demo evidence: 11 raw MCP suggestions, 11 extracted items, 9 normalized requirements, and shop Product Search IDs 66 and 93. The dedicated UC2 live smoke passed with 2 user turns and shop IDs 50, 58, 66, and 93. `NODE_ENV=production`; the MCP demo branch is explicit `FINDMINE_DEMO_MODE=true` and uses only the pinned repository sample response. The tenant gate remains blocked because no real App ID/catalog mapping is configured, and the 32-case live extraction evaluator was stopped by upstream LLM rate/structured-output limits rather than converted into a fabricated pass.
