# FindMine Demo Mode

## V1 Architecture

Current V1 uses `FASHION_PROVIDER=findmine_demo`. The FindMine Demo MCP server provides deterministic styling suggestions via the `get_complete_the_look` tool with `fake_result=true`.

## What Is and Is Not Required for V1

| Item | V1 Required? | Notes |
| --- | --- | --- |
| FindMine Demo MCP connectivity | ✅ YES | `FINDMINE_DEMO_MCP_STATUS=PASS` |
| `get_complete_the_look` tool with `fake_result` | ✅ YES | Present in Demo MCP |
| LLM extraction with canonical schema | ✅ YES | `FASHION_EXTRACTION_STATUS=PASS` |
| Fashion taxonomy normalization | ✅ YES | `FASHION_NORMALIZATION_STATUS=PASS` |
| UC1 complementary product flow | ✅ YES | `USE_CASE_1_STATUS=PASS` |
| Cart event → Redis pipeline | ✅ YES | `CART_EVENT_PIPELINE_STATUS=PASS` |
| UC2 proactive recommendation | ✅ YES | `USE_CASE_2_STATUS=PASS` |
| 50-case Agent Evaluation | ✅ YES | `AGENT_EVAL_STATUS=PASS` |
| `FINDMINE_APP_ID` (live tenant) | ❌ NOT V1 | Optional future upgrade |
| Real FindMine tenant onboarding | ❌ NOT V1 | Optional future upgrade |
| Live tenant catalog mapping | ❌ NOT V1 | Optional future upgrade |
| Live production connectivity | ❌ NOT V1 | Optional future upgrade |

## Release Gate

`FASHION_INTEGRATION_GATE=PASS` requires all V1 items above.

`FINDMINE_APP_ID` absence produces `FINDMINE_LIVE_UPGRADE_STATUS=NOT_CONFIGURED` (informational only) and does NOT block the gate.

## Future Optional Upgrade: findmine_live

When the shop is ready to onboard a real FindMine tenant:

1. Set `FASHION_PROVIDER=findmine_live`
2. Provide `FINDMINE_APP_ID` and tenant credentials
3. Configure tenant catalog mapping
4. Run `scripts/findmine_live_inspect.php` to verify live connectivity
5. `FINDMINE_LIVE_UPGRADE_STATUS` transitions from `NOT_CONFIGURED` to `PASS`

No V1 code changes are required for this upgrade path.
