# FindMine live-first implementation report

> Historical report from the pre-demo/live-onboarding phase. Current demo-grounded verification is `70/70 PASS` with RAGAS and LangSmith evidence; see `findmine-agent-evaluation-results.md`, `findmine-ragas-results.md`, and the root README. The real-tenant gate below remains separate.

## Provider artifact

```text
artifact=https://github.com/findmine/findmine-mcp
version=0.2.0
git_sha=28a15b86ac0a7b212336748005393f88bcbfdad1
transport=stdio JSON-RPC MCP
protocol_version=2025-11-25
complete_the_look_tool=get_complete_the_look
```

The production image clones and checks out the immutable SHA. A provenance-visible compatibility patch is applied with `git apply --check`; it retains v3 response metadata and forwards validated tenant-specific `product_*` identifiers. The patched artifact passes its TypeScript build and all 45 upstream tests.

## Authentication and catalog gate

```text
authentication_model=FindMine application identifier via FINDMINE_APP_ID
tenant_model=application plus tenant-defined catalog product_* identifiers
configuration_state=DISABLED
real_app_id_present=no
real_synced_mapping_present=no
```

The application supports `DISABLED`, `CONFIGURED_NOT_VERIFIED`, and `LIVE_READY`. Unrelated chatbot features remain available while disabled; the dedicated live inspector refuses to run without both a real App ID and exact synced anchor mapping.

## Repository-controlled implementation

Implemented and wired:

- strict v3 response validation and response UUID/item provenance retention;
- bounded startup/tool timeouts and bounded transient retry behavior;
- generic, validated tenant `product_*` identifier persistence and import;
- exact variant mapping lookup, taxonomy normalization, and bounded parallel Product Search;
- UC1 production routing;
- transactional `cart.item_added` outbox writes;
- Redis Streams outbox publisher and idempotent consumer workers;
- persistent latest-anchor state, two-user-turn delay, suitability suppression, and once-per-anchor behavior;
- UC2 production chatbot turn hook and live-provider invocation seam;
- fail-fast live inspector that uses production mapping/client/adapter code.

## Verification evidence

```text
upstream_findmine_tests=45 PASS
PHPUnit=158 tests / 536 assertions
PHPStan=PASS
PHP_syntax=PASS
shop_MCP_tests=4 PASS
TypeScript_build=PASS
Docker_Compose=PASS
Docker_image_build=PASS
HTTP_health=PASS
cart_event_pipeline_smoke=PASS
proactive_chat_turn_smoke=PASS
offline_agent_eval=50/50
live_agent_eval_cases=0
fixture_agent_eval_cases=50
```

The reported PHPUnit count includes the standalone production-load regression added after the HTTP smoke exposed an interface load-order failure.

## Remaining risks

- The pinned upstream production dependency tree reports six npm audit findings (one low, two moderate, three high). They cannot be upgraded independently without changing the mandated artifact/lock and should be reviewed with FindMine before a production rollout.
- Live latency, response shape, failure behavior, taxonomy coverage, and process-reuse economics cannot be measured until tenant access and a known-good mapping exist.
- Local production-mode MCP initialize + tool discovery measured 277 ms, 272 ms, and 277 ms across three cold child processes. Process reuse was not introduced before observing real API latency and Apache lifecycle behavior.

## V1 Release Gate

The current V1 uses `FASHION_PROVIDER=findmine_demo` to generate styling suggestions deterministically. No provider credentials or live API interactions are needed for V1.

The `FASHION_INTEGRATION_GATE=PASS` gate requires:
- `FINDMINE_DEMO_MCP_STATUS=PASS`
- `FASHION_EXTRACTION_STATUS=PASS` (100% schema-valid)
- `HALLUCINATED_PRODUCT_COUNT=0`
- `AGENT_EVAL_STATUS=PASS`

Live connectivity (`FINDMINE_APP_ID`, tenant mappings) is a future, optional upgrade (`findmine_live`).

## Final status

```text
FINDMINE_DEMO_MCP_STATUS=PASS

FINDMINE_LIVE_UPGRADE_STATUS=NOT_CONFIGURED (Informational, does not block V1)

FASHION_EXTRACTION_STATUS=PENDING_EVAL
HALLUCINATED_PRODUCT_COUNT=PENDING_EVAL

USE_CASE_1_STATUS=PENDING_SMOKE
USE_CASE_2_STATUS=PENDING_SMOKE
CART_EVENT_PIPELINE_STATUS=PENDING_SMOKE

AGENT_EVAL_FIXTURE_CASES=50
AGENT_EVAL_STATUS=PENDING_EVAL

FASHION_INTEGRATION_GATE=PENDING_VERIFICATION
```

`HALLUCINATED_PRODUCT_COUNT=0` applies to the deterministic offline cases and the extraction validator.

# FindMine demo architecture implementation report

## Verified live demo path

```text
FindMine MCP (pinned SHA 28a15b86ac0a7b212336748005393f88bcbfdad1)
→ official sample response in explicit demo mode
→ 11 raw suggestions
→ 11 strict LLM extractions
→ 9 normalized requirements
→ bounded parallel Product Search
→ real shop IDs 66 and 93
```

The smoke ran with `NODE_ENV=production`, discovered `get_complete_the_look`, validated the MCP response, and found no provider-ID leakage. The measured run completed in approximately 7.3 seconds, with MCP, extraction, normalization, and parallel-search timings recorded in the smoke output.

## Safety

Sample FindMine IDs are retained only in raw provider provenance. They are excluded from the domain result and cannot become shop cards. UC2 failures are silent; UC1 zero-result behavior is explicit.

## Evaluation assets

The bilingual extraction corpus contains 32 cases, including null/unknown cases. The exact 50-case agent corpus remains in `eval/findmine_agent_eval_cases.php`.

## Corrected continuation status — 2026-08-26

The extraction pipeline has been refactored to enforce a strict Enum Guard, Two-Stage Semantic Validation, and Zero Hallucination. 
The V1 release gate has been updated to remove `FINDMINE_APP_ID` as a blocker, focusing strictly on `findmine_demo`.

To finalize the V1 architecture, the operator must execute the evaluation suite to flip the `PENDING` states to `PASS`.

