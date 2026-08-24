# Verified FindMine MCP contract

Research date: 2026-08-24 (Asia/Ho_Chi_Minh)

## Bottom line

There is no single publicly released contract matching the current default branch:

- The official npm registry currently publishes `findmine-mcp@0.1.1` (published 2025-03-09). This is what unpinned `npx findmine-mcp` installs. The [official npm package metadata](https://registry.npmjs.org/findmine-mcp/0.1.1) and [published tarball](https://registry.npmjs.org/findmine-mcp/-/findmine-mcp-0.1.1.tgz) are the authoritative artifacts for that path.
- The official GitHub default branch declares version `0.2.0`, but that version is not published on npm. It was last changed by the 2025-12-03 audit/modernization work; see the [pinned package manifest](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/package.json#L1-L5) and [changelog](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/CHANGELOG.md#L7-L17).

The tool name and Complete-the-Look input/output shape are substantially the same in both artifacts. Error behavior is not: npm `0.1.1` returns custom top-level `error` objects, while GitHub `0.2.0` returns MCP tool results with `isError: true`. An integration must therefore pin an artifact/version and test against it; “latest” is not a sufficient contract.

There is also a material mismatch between the official v3 API response and the MCP adapter's model. The API returns concrete catalog `items` identified by `item_id`; it does not return abstract styling requirements such as desired category/style/color/material. The current MCP mapper can drop those item IDs. A provider-independent semantic requirements model may be useful inside `shopquanao`, but it cannot be represented as a verified FindMine response contract.

## Available tools

The runtime tool list is feature-flag dependent, not merely the three tools listed in the README.

| Tool | Exposed by default | Notes |
| --- | --- | --- |
| `get_style_guide` | Yes | Local static guidance; no FindMine API call. |
| `get_complete_the_look` | Yes | Read-only, open-world API call on GitHub `0.2.0`. |
| `get_visually_similar` | Yes | Read-only, open-world API call on GitHub `0.2.0`. |
| `track_interaction` | No | Added only when `FINDMINE_ENABLE_TRACKING=true`. |
| `update_item_details` | No | Added only when `FINDMINE_ENABLE_ITEM_UPDATES=true`. Despite its name, this updates stock/sale fields; it is not a catalog-ingestion tool. |

This is defined by the [official tool-list implementation](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/tools/index.ts#L329-L353) and [feature-flag configuration](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/config.ts#L84-L102). The npm `0.1.1` source at its release commit likewise conditionally adds the two mutation tools ([tool definitions and flags](https://github.com/findmine/findmine-mcp/blob/0f3ee07a44332653438019c92d10eced3e1cd35a/src/index.ts#L200-L456)).

## `get_complete_the_look`

### MCP input

The exact public MCP tool name is `get_complete_the_look`. The input object is:

| Field | MCP requirement | Validated/defaulted behavior |
| --- | --- | --- |
| `product_id` | Required | Non-empty string in GitHub `0.2.0`; npm `0.1.1` checks it for truthiness. |
| `product_color_id` | Optional | String; described as applicable only when the product has a color ID. |
| `in_stock` | Optional | Boolean, default `true`. Sent to the API as `product_in_stock`. |
| `on_sale` | Optional | Boolean, default `false`. Sent as `product_on_sale`. |
| `customer_id` | Optional | String for personalization. |
| `customer_gender` | Optional | One of `M`, `W`, `U`. |
| `return_pdp_item` | Optional | Boolean, default `true`. |
| `session_id` | Optional | String. If omitted, the server uses `FINDMINE_DEFAULT_SESSION_ID`, defaulting to `mcp-default-session`. |
| `api_version` | Optional | String overriding `FINDMINE_API_VERSION`, whose default is `v3`. |

Sources: [the exact `0.2.0` MCP definition](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/tools/index.ts#L85-L143), [runtime Zod schema and defaults](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/schemas/tool-inputs.ts#L47-L77), and [npm `0.1.1` release implementation](https://github.com/findmine/findmine-mcp/blob/0f3ee07a44332653438019c92d10eced3e1cd35a/src/index.ts#L490-L522).

### API request and authentication

The MCP server is a local stdio process. The public implementation exposes no MCP OAuth flow, API-key header, or bearer-token configuration.

For the upstream FindMine call it issues:

```text
GET {FINDMINE_API_URL}/api/{api_version}/complete-the-look
```

The request uses query parameters. The server always supplies:

- `application`: the value of `FINDMINE_APP_ID` (default literal `DEMO_APP_ID`)
- `customer_session_id`: supplied/default session ID
- `product_id`
- `product_in_stock`
- `product_on_sale`

It may also supply `product_color_id`, `customer_id`, `customer_gender`, `return_pdp_item`, `region`, and `language`. The HTTP headers are only JSON content negotiation; the implementation does not add an authorization header. See the [base-request construction and Complete-the-Look call](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/api/findmine-client.ts#L219-L270) and [fetch headers](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/api/findmine-client.ts#L121-L130).

The [official Complete-the-Look v3 contract](https://findmine.notion.site/Complete-the-Look-API-v3-66ed19d933c848659722c59dc6132601?pvs=21) says `application` is required and is assigned at integration start, but explicitly says it is **not an authorization secret** and is safe to embed in public HTML. The live official API confirms that omitting it returns HTTP 400 with `reason` indicating a missing required `application` field. `FINDMINE_APP_ID` is therefore a required tenant/application selector, not a bearer credential. No separate public authentication mechanism is documented for this endpoint.

The MCP schema and upstream API requirements are not identical. The official v3 API contract describes `product_*`, `product_in_stock`, `product_on_sale`, and `customer_session_id` as required. Its introduction qualifies current-state parameters as required for PDP calls but optional for background/secondary integrations. The MCP supplies defaults for all three non-identity fields, so its callers need only provide `product_id`.

### Product and color identity requirements

- `product_id` is the sole MCP-required field and is forwarded unchanged to FindMine. The public code provides no translation from a retailer/shop ID to a FindMine ID.
- The upstream API does not prescribe one universal identity schema. Its required `product_*` identifiers and exact parameter names are configured during onboarding, represent each visually distinct product, and must match the catalog feed exactly. The official examples include `product_id`, `product_color_id`, and even `product_pattern`.
- `product_color_id` is optional in the generic MCP schema, but the [official Product Catalog guide](https://findmine.notion.site/Product-Catalog-197a1d4b30a980f4b4addca1104c2dd5?pvs=21) says a color identifier is always required unless the unique product identifier is itself unique to the color variation. Therefore the tenant-specific onboarding contract, not the generic MCP schema, decides whether color is required.
- The response mapper treats `product_id` and optional `product_color_id` as provider-returned identities and builds `product:///...` URIs from them ([product mapper](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/utils/resource-mapper.ts#L10-L29), [URI construction](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/types/mcp.ts#L48-L60)).

Therefore it is not verified that an arbitrary `shopquanao` product/variant ID is accepted. A persisted shop-to-provider mapping is required unless FindMine provisions the tenant to use the shop's IDs verbatim.

### MCP success response

The tool returns an MCP content array containing one `text` item. The text is a JSON string; the implementation does **not** return MCP `structuredContent` or an output schema.

The handler intends to emit normalized JSON text with this shape:

```json
{
  "product": {
    "product_id": "provider-product-id",
    "name": "Product name",
    "uri": "product:///provider-product-id?color=provider-color-id"
  },
  "looks": [
    {
      "look_id": "provider-look-id",
      "title": "Look title",
      "description": "Look description",
      "image_url": "https://...",
      "products": [
        {
          "product_id": "provider-product-id",
          "name": "Product name",
          "uri": "product:///provider-product-id?color=provider-color-id"
        }
      ],
      "uri": "look:///provider-look-id"
    }
  ],
  "total_looks": 1
}
```

`product` may be `null`; `looks` may be empty; missing optional strings are emitted as empty strings; an unresolved product URI can be `null`. Because the upstream-to-resource mapping is incompatible with the documented/live v3 item shape, this is the handler's intended contract, not a verified guarantee that all shown fields will be populated. This output is assembled in the [official handler](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/handlers/tools.ts#L125-L215).

The [official v3 API contract](https://findmine.notion.site/Complete-the-Look-API-v3-66ed19d933c848659722c59dc6132601?pvs=21) defines a different raw response:

- Top level: `looks`, `response_uuid`, and `result` (`"success"` on success).
- Each look: `look_id` and `items`; live/test responses also include `featured` and `order`.
- Common item fields guaranteed by the public guide: `item_id`, `title`, `item_url`, `image_url`, and `price`. The live synthetic response also included `category`, but the guide does not guarantee it as a universal field; advanced fields are integration-specific.
- No-look/failure responses use `result: "error"`, an empty `looks` array, and a top-level `reason`. FindMine warns that reason strings can evolve and be client-specific, so consumers must not treat them as a closed enum.

The MCP source attempts to accommodate `look_id`/`id`, `products`/`items`, and `order` variations ([API types](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/types/findmine-api.ts#L72-L124), [normalization](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/api/findmine-client.ts#L159-L197)), but its current mapping is incompatible with the documented/live item shape: the client copies `items` to `products`, then the mapper's `products` branch reads only `product.product_id`, not `item_id`; it also expects `name`/`url`, not `title`/`item_url` ([look mapper](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/utils/resource-mapper.ts#L35-L61), [product mapper](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/utils/resource-mapper.ts#L10-L29)). As a result, the current MCP adapter is not verified to preserve recommended item IDs/names/URLs and must not be treated as a trustworthy production contract without a vendor fix or an integration-level compatibility patch.

## Errors

### GitHub `0.2.0` behavior

Validation, unknown-tool, and caught provider errors are returned as:

```json
{
  "isError": true,
  "content": [{ "type": "text", "text": "error message" }]
}
```

Validation text starts with `Validation failed:` and joins field issues; upstream exceptions are passed through as text. See the [error helper and validation formatter](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/handlers/tools.ts#L22-L56) and [Complete-the-Look dispatch/catch](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/handlers/tools.ts#L425-L448).

For non-2xx upstream responses, the client only reads `error.message` and otherwise throws `FindMine API error: Unknown error` ([client error handling](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/api/findmine-client.ts#L139-L157)).

### npm `0.1.1` behavior

The published package instead returns custom top-level error objects such as:

```json
{
  "error": {
    "message": "Product ID is required",
    "code": "VALIDATION_ERROR"
  }
}
```

Caught Complete-the-Look failures use code `COMPLETE_THE_LOOK_ERROR`. This is visible in the [source at the npm release commit](https://github.com/findmine/findmine-mcp/blob/0f3ee07a44332653438019c92d10eced3e1cd35a/src/index.ts#L490-L604).

### Live API behavior observed on 2026-08-24

Read-only probes of the official endpoint showed a second incompatibility that callers must handle:

- Missing `application` or missing `product_id` returned HTTP 400 with JSON shaped as `{ "looks": [], "response_uuid": "...", "result": "error", "reason": "..." }`.
- `application=DEMO_APP_ID` returned HTTP 200 with `{ "looks": [], "response_uuid": "...", "result": "error", "reason": "INVALID_STORE" }`.
- Adding `fake_result=true` returned HTTP 200 synthetic looks with the documented `item_id`/`title`/`item_url` item shape, further confirming the mapper mismatch described above.

Because the client only treats non-2xx as errors and only extracts `error.message`, an HTTP-200 `result: "error"` can flow through as a successful empty-look MCP response, while an HTTP-400 `reason` becomes `FindMine API error: Unknown error`. These observations can be reproduced against the [official Complete-the-Look endpoint](https://api.findmine.com/api/v3/complete-the-look?application=DEMO_APP_ID&customer_session_id=contract-research&product_id=P12345&product_in_stock=true&product_on_sale=false&return_pdp_item=true). Response UUIDs are generated per call.

## Timeout, retries, and cache

- No HTTP timeout is implemented. The `fetch` call has no abort signal, so a stalled attempt can wait indefinitely according to the runtime/network stack.
- The client defaults to `retryCount = 3`, implemented as attempts `0..3`: up to **four total attempts**.
- It waits a fixed 1,000 ms between failed attempts (three waits at defaults). It retries every thrown failure, including HTTP errors and JSON parse errors; there is no status-based retry policy or exponential backoff.
- Complete-the-Look caching is enabled by default for one hour. Its cache key includes product ID, color ID/default, in-stock, on-sale, and `return_pdp_item`, but not customer ID, customer gender, session, region, language, or API version. This has personalization/cross-context implications.

No public FindMine timeout, latency SLO, or rate-limit contract was found. Therefore neither the upstream service's maximum response time nor a safe retry budget can be inferred from the public materials.

Sources: [request/retry loop](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/api/findmine-client.ts#L63-L218), [cache configuration](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/config.ts#L84-L102), and [Complete-the-Look cache key](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/services/findmine-service.ts#L48-L105).

## Catalog ingestion

No catalog-ingestion tool or universal executable ingestion schema exists in the official MCP repository. The only catalog-adjacent optional tool is `update_item_details`, which posts stock/sale changes for already identified products to `/api/{version}/item-details`; its item fields are `product_id`, optional `product_color_id`, `in_stock`, and `on_sale` ([tool contract](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/tools/index.ts#L263-L326), [API call](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/api/findmine-client.ts#L308-L343)). The [official Item Details API guide](https://findmine.notion.site/Item-Details-API-v3-36f64b35aaf649acaeb7fc8f46a740f0?pvs=21) confirms that this endpoint only sets stock/sale status, is disabled by default server-side, and must be enabled through a FindMine representative.

FindMine's official implementation overview makes ingestion an explicit prerequisite: FindMine first accesses product data and recreates each catalog product as a FindMine item. The supported ongoing paths are feed files dropped to `sftp.findmine.com` (once daily is typically recommended; credentials come from the account representative) or FindMine consuming the retailer's product API ([official Implementation Overview](https://findmine.notion.site/Implementation-Overview-471c429851e442d6b4086fd48e231fa5?pvs=21)).

The [official Product Catalog guide](https://findmine.notion.site/Product-Catalog-197a1d4b30a980f4b4addca1104c2dd5?pvs=21) accepts CSV, TSV, PSV, XML, JSON, Shopify endpoints, or an API integration, subject to a feed evaluation. Its published minimum fields are:

- unique identity at the most specific **style** variation (color/fabric/pattern), excluding non-style size variants;
- color identity, unless the main identifier is already color-unique;
- category indicator(s);
- product title;
- PDP URL;
- image URL(s).

Official customer material confirms the same tenant-specific flexibility: one deployment enhances an existing product feed and refreshes the catalog every 24 hours ([official feed-integration case study](https://www.findmine.com/case-studies/meta-dpa-roas)); another says FindMine pulled product information directly from the customer's API without feed configuration ([official TSC case study](https://www.findmine.com/case-studies/tsc)).

What is verifiable:

- Catalog ingestion precedes ordinary retailer recommendations; FindMine must operate with retailer-specific catalog identity/data to produce retailer looks.
- The MCP recommendation call does not ingest products at runtime.
- Catalog transfer can be feed-based or a FindMine pull from a retailer API, depending on onboarding.

What is **not** publicly verifiable:

- A universally applicable feed/API ingestion endpoint or full schema usable by `shopquanao`; onboarding/feed evaluation is tenant-specific.
- Additional required attributes beyond the public minimum, refresh SLA, deletion semantics, or readiness/status API.
- Whether a newly supplied product is immediately eligible for Complete the Look.
- Whether FindMine mandates color-level records for this tenant.

Those details require FindMine-issued onboarding documentation/credentials. They must not be inferred from the MCP recommendation schema.

## Sandbox/demo mode

The official API has a synthetic-response test switch, but the MCP does not expose it:

1. The [official Complete-the-Look v3 contract](https://findmine.notion.site/Complete-the-Look-API-v3-66ed19d933c848659722c59dc6132601?pvs=21) documents `fake_result=true` for randomized looks while feed ingestion is being prepared and says an application ID must still be supplied. Although the guide's synthetic example omits product identity, the live endpoint on 2026-08-24 returned HTTP 400 until `product_id` was also supplied. The MCP input schema has no `fake_result` field and its API request builder never sends one.
2. `FINDMINE_APP_ID` defaults to `DEMO_APP_ID` ([configuration](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/config.ts#L84-L91)). On 2026-08-24, the live API accepted `DEMO_APP_ID` with `fake_result=true` and returned synthetic looks, but returned `INVALID_STORE` without `fake_result`. Thus it is useful for a direct-API schema probe, not verified for normal recommendation traffic.
3. The README says `NODE_ENV=development` uses sample data ([README](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/README.md#L150-L157)). The actual bootstrap does not install mock API responses; it loops over sample products and asynchronously calls the real `findMineService.getCompleteTheLook` ([bootstrap source](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/index.ts#L48-L55)). The sample products themselves use `example.com` URLs and synthetic IDs ([mock data](https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/src/utils/mock-data.ts#L1-L77)).

No separate sandbox hostname, fixture transport, or fake-result tool argument is exposed by the MCP contract. A real non-synthetic smoke test therefore requires a valid FindMine application ID and at least one known provisioned product (plus the tenant's other configured style-identity fields, commonly color).

## Integration gate for `shopquanao`

Before implementing against FindMine, obtain and record all of the following from FindMine:

1. The supported artifact/version (`npm 0.1.1`, a Git SHA, or a newer private/released server).
2. An assigned `FINDMINE_APP_ID` (not a secret per official docs) and any separate private integration credentials needed for catalog transfer.
3. The tenant's catalog onboarding mechanism and product/color identity mapping.
4. One known-good product ID/color ID and its expected non-empty Complete-the-Look response for a smoke test.
5. Provider timeout/SLA/rate-limit guidance and authoritative upstream error schema.

Until those are available, only an adapter boundary and mocked contract tests can be implemented honestly. A live integration or catalog synchronizer would otherwise depend on an invented contract.
