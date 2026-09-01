# Glance Live MCP Bootstrap

## Post-OAuth verification

The authenticated session was re-established through the official remote MCP
endpoint using `npx --yes mcp-remote`. OAuth credentials were reused by the
bridge; no credential material was read or persisted by the project.

```text
endpoint=https://ember.ailooks.glance.com/mcp
transport=remote Streamable HTTP through mcp-remote
server=glanceai-mcp
server_version=0.2.0
mcp_protocol=2025-06-18
```

`initialize` and `tools/list` both succeeded. Eight live tools were discovered;
the complete sanitized inventory and schemas are recorded in
`tests/fixtures/glance/live-sanitized/post-oauth-tools.json`.

Relevant live tools:

* `get_mix_and_match` — builds complete multi-item outfits in anchor, query, or
  uploaded-image mode. This is the selected styling/reference-generation tool.
* `search_fashion_products` — searches Glance's fashion catalog and renders an
  interactive carousel. It is a provider search tool, not the private-shop
  Product Search source.

The harmless smoke call used `get_mix_and_match` query mode with a generic
smart-casual white-shirt request. It returned a successful MCP result
(`isError=false`) containing one `text` content block and a `structuredContent`
object. The observed machine-readable shape is `structuredContent.outfits[]`,
with each outfit containing `products[]`; each product exposes fields including
`sku`, `merchantVariantId`, `title`, `category`, `slot`, `image`, price/stock,
and carousel metadata. Five outfits with four products each were returned in
the mapper smoke. The raw body and sensitive `server_token` value were kept in
memory only and were not written to disk.

`GlanceLiveResponseMapper` now consumes this shape (structured content first),
retains provider SKU only as `sourceReferenceId`, maps `slot` to canonical
roles (`top`, `bottom`, `shoe`, `outerwear`), carries the outfit occasion, and
ignores unsupported fields. A live mapper smoke normalized 19 deduplicated
references with zero provider-ID leakage. No private catalog mapping or UC1/UC2
code was changed.

## UC1 runtime anchor probe

The live tool contract was probed with a real active private catalog product,
using only its numeric product identity as `anchor_sku`. The request was
accepted by MCP but returned the provider's no-matching-pieces text response
and no `structuredContent` (approximately 0.6 seconds). This proves neither a
valid direct private-SKU mapping nor a hard provider validation error; it is
therefore not treated as a usable direct-anchor integration.

The documented query mode was also verified, but the production bridge now
uses the provider-search anchor pattern so that `get_mix_and_match` receives a
Glance-owned SKU. A live `search_fashion_products` call returned in-stock
provider references in `structuredContent.tiers[].products[]`; the selected
provider SKU then produced five structured outfits (20 references) through
`get_mix_and_match`. The two provider calls took approximately 12 seconds in
that probe (the mix call about 8 seconds).

The runtime consequently uses a provider-specific **Glance search bridge**:

```text
private anchor product -> safe title/category/style description
-> search_fashion_products -> internal Glance anchor SKU
-> get_mix_and_match -> StyleReference[] -> PrivateCatalogStyleMapper
-> private shop products
```

`GlanceAnchorResolver` chooses an in-stock search result deterministically and
never exposes its Glance provider SKU to callers. `GlanceMcpClient` invokes the authenticated `mcp-remote`
bridge with finite request deadlines and turns an OAuth prompt into the safe
operator signal `MANUAL_ACTION_REQUIRED: GLANCE_OAUTH`; it does not inspect,
persist, or log OAuth material. The bridge dependency is pinned to
`mcp-remote@0.6.0` in the MCP runtime package.

The application image must be rebuilt before an HTTP UC1 validation can be
claimed. `GLANCE_LIVE_VERIFIED` remains `false` until that real application
path has returned validated private catalog products.

Sanitized evidence:

* OAuth bootstrap: `tests/fixtures/glance/live-sanitized/bootstrap-oauth.json`
* Post-OAuth discovery and smoke metadata:
  `tests/fixtures/glance/live-sanitized/post-oauth-tools.json`

The bridge remains runnable for another operator-controlled inspection:

```bash
set -a; . ./.env; set +a
./scripts/glance-live-inspect.sh
```

The script requires live mode and an endpoint; it never enables the demo
provider and never prints secrets.
