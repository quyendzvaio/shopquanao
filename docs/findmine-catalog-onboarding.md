# FindMine catalog onboarding

```text
shopquanao products / variants / colors / categories / images / stock
        ↓
FindMine tenant feed or retailer-API onboarding
        ↓
FindMine recognizes or assigns provider catalog identity
        ↓
provider mapping dataset
        ↓
local mapping import
```

The repository currently has products, first-class variants, canonical colors, taxonomy, images, price, and stock. It currently has no footwear products; no footwear products should be invented for onboarding. Twenty-seven products have unknown colors and three are ambiguous, so those records should be resolved before using them as color-sensitive anchors.

The official MCP repository does not expose a general catalog-ingestion tool. FindMine onboarding must therefore specify a feed or retailer API path. The local importer accepts shop product/variant IDs and provider product/variant/color values and persists them as `pending` before an operator marks the verified batch `synced`.

Catalog ingestion remains offline. It must never run on a chatbot request path. Price, stock, color, image, category, addition, removal, and deletion update semantics must be confirmed by FindMine for the tenant before scheduling the feed.
