# FindMine current-state audit

> Historical baseline captured before the 2026-08-26 demo-grounded 50-question evaluation. Use `findmine-agent-evaluation-results.md`, `findmine-ragas-results.md`, and the root README for current test status.

Audited against the repository and running Docker Compose stack on 2026-08-24. “Wired” means reachable from a production application path, not merely declared or unit-tested.

| Component | Exists | Wired at runtime | Tested | Production ready | Remaining gate |
|---|---:|---:|---:|---:|---|
| pinned FindMine MCP | yes | yes, production image | upstream 45 tests plus build | yes locally | tenant access required for live API |
| v3 adapter | yes | yes, provider | contract tests | conditional | confirm against tenant response |
| stdio client | yes | yes, production provider | unit and local protocol | conditional | authenticated smoke blocked |
| tenant `product_*` identifiers | yes | mapping JSON and patched MCP request | unit/import tests | yes locally | actual tenant schema unknown |
| FindMineFashionProvider | yes | yes, ToolRegistry | unit | conditional | no real mapping/provider call |
| mapping repository/importer | yes | yes | repository/import tests | yes offline | no real tenant mapping |
| complementary pipeline | yes | explicit and proactive tools | unit/regression | conditional | live provider gate blocked |
| Use Case 1 routing | yes | production chatbot/MCP contracts | regression | conditional | live anchor unavailable |
| Use Case 2 event producer | yes | CartService transaction | unit and HTTP smoke | yes | none locally |
| outbox publisher | yes | deployed worker | HTTP/event smoke | yes | none locally |
| broker | Redis Streams | deployed | event smoke | yes | none locally |
| agent event consumer | yes | deployed consumer group worker | event smoke | yes | none locally |
| pending state persistence | yes | deployed MariaDB | integration/runtime smoke | yes | none locally |
| 2-turn counter | yes | production chatbot user-message path | unit and HTTP smoke | yes | none locally |
| suitability gate | yes | deterministic primary intent | unit and HTTP smoke | yes | expand only from observed needs |
| once-per-anchor | yes | persisted state | unit | yes locally | live recommendation required to mark shown |
| latest-anchor-wins | yes | idempotent consumer | unit | yes | none locally |
| live FindMine config | stateful | DISABLED / CONFIGURED_NOT_VERIFIED / LIVE_READY | config tests | conditional | real App ID absent |
| real mapping | schema/import only | exact lookup | importer tests | no | vendor mapping absent |
| live inspector | yes | production MCP/client/adapter path | fail-fast gate | conditional | App ID and mapping absent |
| Agent Eval | yes | offline evaluator | 50/50 | offline only | zero live cases |
| RAGAS | not run | requires actual retrieval contexts and answers | none | blocked | live plan/contexts unavailable |

## Hard conclusion

All repository-controlled event-runtime work is implemented and the cart event pipeline passes an actual HTTP → transactional outbox → Redis Streams → consumer → pending-state smoke. Two chatbot turns and suppression were also exercised through the real HTTP chatbot endpoint. Live FindMine, UC1-live, and UC2-live remain blocked solely by missing tenant onboarding inputs: `FINDMINE_APP_ID`, the tenant `product_*` schema, and one known-good synced mapping.
