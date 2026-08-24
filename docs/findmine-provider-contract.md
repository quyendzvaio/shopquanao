# FindMine provider contract gate

The production image checks out the immutable upstream SHA and then applies `docker/findmine-mcp-shopquanao.patch`. This narrow, provenance-visible compatibility patch forwards tenant-configured `product_*` identifiers and preserves v3 `item_id`, item metadata/attributes, and `response_uuid` that the pinned upstream mapper can otherwise discard. `git apply --check` and the patched TypeScript build are hard Docker build gates.

Verification date: 2026-08-24 (Asia/Ho_Chi_Minh)

## Gate result

**Task 1: PASS — the project has explicitly selected the official GitHub artifact below.**

Production target:

```text
repository: https://github.com/findmine/findmine-mcp
Git SHA: 28a15b86ac0a7b212336748005393f88bcbfdad1
declared version: 0.2.0
```

The npm `0.1.1` release is intentionally excluded from production. The selected Git SHA is vendored during the Docker build with an immutable checkout; tenant credentials remain optional at boot and are required only for live calls.

## Repository evidence

The repository currently proves only the following:

- The shop's own MCP server is a private Node stdio child process, built from `mcp-server/` and invoked by `McpToolGateway`.
- The shop MCP package is `fashion-shop-mcp-server`; it does not depend on `findmine-mcp`.
- There are no `FINDMINE_*` settings in `.env` or `.env.example`.
- There is no FindMine MCP command, endpoint, package, Git URL, version, SHA, SSE configuration, or Streamable HTTP configuration in the project.
- `FashionProvider::completeTheLook()` is a provider-independent boundary only; no production FindMine adapter exists.
- `docs/findmine-mcp-contract.md` explicitly records artifact selection, tenant onboarding, and a known-good provider identity as prerequisites that must be obtained from FindMine.

Search covered documentation, hidden configuration, package manifests and locks, MCP source/configuration, migrations, services, tests, scripts, and comments using the requested FindMine/MCP/Complete-the-Look terms.

## Official artifact candidates

### Candidate A — published npm release

```text
artifact: findmine-mcp
package: https://www.npmjs.com/package/findmine-mcp
version: 0.1.1
npm gitHead: 0f3ee072975ffa8e2724e812c77f9d7e5231dc67
published tarball SHA-1: 3b631b158e9c259331261c30956fc71b4541ada6
published tarball integrity: sha512-lw/32+tYUefOqB6AxAcz6Ic820xyYhZ3QhO0FP/C0O7ZoLRUv19n9iBUccgVbjrGRwz8/d2DEqsS9cHNqjX3Kw==
runtime: Node.js >=18
entrypoint: build/index.js (bin: findmine-mcp)
transport: stdio
MCP SDK: 0.6.0
observed MCP protocol: 2024-11-05
```

The package was downloaded from the npm registry, its integrity metadata and contents were inspected, dependencies were installed in a temporary directory, and its real stdio process was initialized. It returned the default tool inventory below. Despite package version `0.1.1`, its initialize response reports server version `0.1.0`; this inconsistency is part of the observed contract.

The npm registry still exposes `0.1.1` as the sole published release. The official README recommends `npx findmine-mcp`, which currently resolves to this artifact.

### Candidate B — official GitHub head, not published to npm

```text
artifact: findmine/findmine-mcp source build
repository: https://github.com/findmine/findmine-mcp
declared version: 0.2.0
Git SHA: 28a15b86ac0a7b212336748005393f88bcbfdad1
changelog date: 2025-12-03
runtime: Node.js >=18
entrypoint: build/index.js (bin: findmine-mcp)
transport: stdio
MCP SDK declaration: ^1.24.2
observed MCP protocol: 2025-11-25
```

The exact SHA was cloned and built in a temporary directory. Its real stdio process initialized and returned the default tool inventory below. It also reports server version `0.1.0` during initialization even though `package.json` declares `0.2.0`.

Version `0.2.0` has not been published to npm and no Git release/tag identifies it as the supported production artifact. Selecting this SHA would therefore be a project decision, not a fact established by current tenant/vendor material.

## MCP lifecycle and compatibility

Both candidates use a locally spawned stdio server. The verified lifecycle is:

```text
spawn Node process
→ initialize JSON-RPC request
→ initialize response with negotiated protocol and capabilities
→ notifications/initialized
→ tools/list
→ tools/call
→ terminate the child process when the client request lifecycle ends
```

This transport is conceptually compatible with the existing shop `McpToolGateway`, which already manages a Node stdio child process and newline-delimited JSON-RPC. Compatibility is not interchangeable at the artifact level:

- npm `0.1.1` uses MCP SDK `0.6.0`, negotiated protocol `2024-11-05`, and custom top-level `error` objects for tool failures.
- Git SHA `28a15b...` uses the modern SDK, negotiated protocol `2025-11-25`, and MCP tool results with `isError: true`.
- The shop client would need to be tested and pinned against the selected behavior; supporting one does not prove support for the other.

No official FindMine SSE or Streamable HTTP MCP endpoint was found. The MCP server itself calls the vendor's HTTPS API behind the stdio boundary.

## Verified tool inventory

Both inspected candidates expose these tools by default:

### `get_style_guide`

Purpose: return local/static styling guidance; it does not call the recommendation API.

Input object:

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `category` | No | string | Defaults to `general`. |
| `occasion` | No | string | Occasion-specific guidance. |
| `fashion_season` | No | string | Season-specific guidance. |

Result: MCP text content containing prose/Markdown styling guidance. Provider/API errors are not expected because this is local content; handler failures differ by artifact (`error` object versus `isError` tool result).

### `get_complete_the_look`

Purpose: invoke FindMine Complete the Look for a provider-known product.

Input object:

| Field | Required | Type | Verified behavior |
| --- | --- | --- | --- |
| `product_id` | Yes | string | Forwarded as provider product identity. |
| `product_color_id` | No | string | Used when the tenant's catalog identity includes color. |
| `in_stock` | No | boolean | Defaults to `true`; forwarded as `product_in_stock`. |
| `on_sale` | No | boolean | Defaults to `false`; forwarded as `product_on_sale`. |
| `customer_id` | No | string | Optional personalization/analytics identity. |
| `customer_gender` | No | `M`, `W`, or `U` | Optional. |
| `return_pdp_item` | No | boolean | Defaults to `true`. |
| `session_id` | No | string | Otherwise the MCP server uses its configured default session. |
| `api_version` | No | string | Overrides the configured API version. |

The MCP handler intends to return one text content block whose text is JSON:

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
      "image_url": "https://example.invalid/look.jpg",
      "products": [
        {
          "product_id": "provider-product-id",
          "name": "Product name",
          "uri": "product:///provider-product-id"
        }
      ],
      "uri": "look:///provider-look-id"
    }
  ],
  "total_looks": 1
}
```

This is an intended mapper result, not a trustworthy output guarantee. The official v3 API's verified raw response instead contains top-level `result`, `response_uuid`, and `looks`; each look contains `look_id` and `items`; each documented item uses `item_id`, `title`, `item_url`, `image_url`, and `price`. The current GitHub mapper copies `items` into `products` but then reads legacy `product_id`/`name`/`url` fields. It can therefore drop the real `item_id`, title, and URL. No output schema is declared through MCP `structuredContent`.

Observed/documented errors include missing `product_id`, invalid response, upstream failure, HTTP-200 API responses with `result: "error"`, and non-2xx API responses. Error envelopes differ between the two candidates, and the current upstream client does not reliably preserve the v3 `reason` field.

### `get_visually_similar`

Purpose: invoke FindMine visually-similar recommendations.

Input object:

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `product_id` | Yes | string | Provider product identity. |
| `product_color_id` | No | string | Optional provider color identity. |
| `limit` | No | number | Defaults to 10. |
| `offset` | No | number | Defaults to 0. |
| `customer_id` | No | string | Optional. |
| `customer_gender` | No | `M`, `W`, or `U` | Optional. |
| `session_id` | No | string | Optional. |
| `api_version` | No | string | Optional override. |

Intended result: MCP JSON text containing `products`, `total`, `limit`, `offset`, and `source_product_id`. It has the same artifact-specific error-envelope distinction and provider-response mapping risk.

## Feature-flagged tools

The following are real tools but are omitted from the default `tools/list` response:

### `track_interaction`

Exposed only when `FINDMINE_ENABLE_TRACKING=true`.

Required fields: `event_type` (`view`, `click`, `add_to_cart`, or `purchase`) and `product_id`.

Optional fields: `product_color_id`, `look_id`, `source_product_id`, `price`, `quantity` (default 1), `customer_id`, `session_id`, `force_enable` (default false), and `api_version`.

Result: handler acknowledgement/provider tracking result in MCP content; validation, disabled-feature, and upstream errors use the selected artifact's error envelope.

### `update_item_details`

Exposed only when `FINDMINE_ENABLE_ITEM_UPDATES=true`.

Required input: `items`, an array whose elements require `product_id`, `in_stock`, and `on_sale`.

Optional item field: `product_color_id`. Optional request fields include `customer_id`, `session_id`, and `api_version`.

Result: handler acknowledgement/provider update result in MCP content. This updates state for products FindMine already knows; it is not a catalog-ingestion tool.

## Version pinning status

Deterministic pins are technically available:

```text
npm: findmine-mcp@0.1.1 plus published integrity hash
Git: 28a15b86ac0a7b212336748005393f88bcbfdad1
```

`shopquanao` now pins the Git SHA in `Dockerfile` through the `FINDMINE_MCP_SHA` build argument default. The npm candidate remains documented for provenance only and is not used by production.

## Remaining live prerequisites

Task 1 is complete. Live provider verification still requires tenant-specific values that cannot be fabricated:

1. A valid `FINDMINE_APP_ID`.
2. A FindMine-provisioned catalog and one known-good provider product/color identity.
3. Confirmation that the selected tenant returns structured category/style fields sufficient for local `ComplementaryPlan` normalization, or an approved tenant-specific mapping source.

The compatibility adapter now rejects item identity/category loss instead of silently accepting it.

## Task 1 execution record

### What was verified

- Full repository references and absence of a selected FindMine dependency/configuration.
- npm metadata, integrity, package contents, runtime, entrypoint, tool definitions, stdio initialization, negotiated protocol, and default tool discovery for `0.1.1`.
- Official Git repository head, exact SHA, declared version/date, build, stdio initialization, negotiated protocol, and default tool discovery for the `0.2.0` source candidate.
- Complete-the-Look MCP input and intended output mapping against the official v3 raw API documentation.

### Evidence

- Official npm package: https://www.npmjs.com/package/findmine-mcp
- npm tarball: https://registry.npmjs.org/findmine-mcp/-/findmine-mcp-0.1.1.tgz
- Official repository: https://github.com/findmine/findmine-mcp
- Pinned Git manifest: https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/package.json
- Pinned changelog: https://github.com/findmine/findmine-mcp/blob/28a15b86ac0a7b212336748005393f88bcbfdad1/CHANGELOG.md
- Official Complete-the-Look v3 contract: https://findmine.notion.site/Complete-the-Look-API-v3-66ed19d933c848659722c59dc6132601
- Local detailed audit: `docs/findmine-mcp-contract.md`

### Files changed

- `docs/findmine-provider-contract.md`

### Tests/probes run

- Repository-wide reference/configuration search: completed.
- `npm view findmine-mcp@0.1.1`: completed; version and immutable distribution metadata verified.
- npm `0.1.1` tarball inspection: completed.
- Real local MCP initialize + `tools/list` against npm `0.1.1`: completed successfully; no provider recommendation call was made.
- `git ls-remote` against the official repository: completed; exact head verified.
- Clone/build at Git SHA `28a15b...`: completed.
- Real local MCP initialize + `tools/list` against Git SHA `28a15b...`: completed successfully; no provider recommendation call was made.

### Result

```text
TASK_1_FINDMINE_ARTIFACT_GATE=PASS
```

### Blockers

The artifact decision is resolved by the continuation mission. Live tenant access remains a separate verification gate.
