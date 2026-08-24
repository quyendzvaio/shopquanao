# FindMine demo mode

`FASHION_PROVIDER=findmine_demo` runs the pinned FindMine MCP (`0.2.0`, SHA `28a15b86ac0a7b212336748005393f88bcbfdad1`) over stdio with `NODE_ENV=production`.

When `FINDMINE_DEMO_MODE=true`, the MCP uses the official repository sample response (`sampleCompleteTheLookResponse`). This is an explicit demo transport mode, not a tenant API response. Sample IDs (`P12345`, `L1001`, etc.) are provenance only and are never passed to Product Search or rendered as shop cards.

Required local settings:

```text
FASHION_PROVIDER=findmine_demo
FINDMINE_ENABLED=true
FINDMINE_DEMO_ENABLED=true
FINDMINE_DEMO_MODE=true
FINDMINE_APP_ID=DEMO_APP_ID
```

Run `php scripts/smoke_findmine_demo.php --shop-product-id=50` in the app container. The command verifies MCP initialize, tool discovery, the raw response, extraction, normalization, bounded parallel search, and shop-ID grounding.

Tenant/live mode remains separate: it requires a real `FINDMINE_APP_ID`, tenant catalog identifiers, and a verified mapping.
