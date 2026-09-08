# Styling providers (Stylitics / FindMine)

Providers supply styling REFERENCES and outfit intent only. The products shown to users always come from the private shop catalog via Product Search (`ParallelComplementaryProductSearcher` — hard-filtered, bounded parallel search).

## Stylitics modes

```env
STYLING_PROVIDER=stylitics
STYLITICS_ENABLED=true
STYLITICS_PROVIDER_MODE=demo      # demo | live
STYLITICS_LIVE_VERIFIED=false     # vendor gate
```

- Demo mode generates local styling references for testing. It is NOT evidence of Stylitics production readiness.
- Live mode requires vendor-confirmed endpoint, authentication, and tool schema. Current vendor gate status: `BLOCKED` — no live claim allowed anywhere (docs, reports, commits).
- Production deploy keeps Stylitics live `disabled` by default; enable only via environment secrets after the vendor provides the contract.

## Use cases

- **UC1**: user asks to style a specific `product_id` → fetch Stylitics styling references → display only real SKUs from Product Search.
- **UC2**: after a cart-add event and two suitable user turns → fetch Stylitics references → proactively suggest private SKUs once, for the latest anchor.

Contracts: UC1/UC2 được định nghĩa trong `README.md` (mục Chức năng) và `docs/cart-styling-event-architecture.md`.

## Provider mapping

`fashion_provider_mapping` tables (migrations `2026_08_24_*`) map provider identifiers → shop SKUs. `FashionProviderMappingRepository` owns lookups; `PrivateCatalogStyleMapper` + `FashionTaxonomyNormalizer` normalize provider taxonomy to shop vocabulary. Offline importer: `scripts/import_fashion_provider_mappings.php`.

## Leakage rule

Provider identifiers (Stylitics/FindMine IDs) stay in internal provenance. API responses and UI must contain only shop product cards.
