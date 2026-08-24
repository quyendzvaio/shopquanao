# Catalog variant backfill results

Run date: 2026-08-24 (MariaDB 10.11 development catalog)

## Result

| Measure | Count |
| --- | ---: |
| Products processed | 50 |
| Variants created on initial run | 170 |
| Variants created on verification rerun | 0 |
| Products with one safely normalized color | 20 |
| Products requiring color review | 30 |
| Canonical colors seeded | 17 |
| Footwear subcategories seeded | 6 |

All 50 existing products now have at least one variant row. Backfilled `sku`, `price`, and `stock` remain `NULL`: SKU was not present in legacy data, while price and stock deliberately inherit the product-level values rather than fabricating per-size inventory.

## Manual color review

Ambiguous color/material phrases:

- Product `52`: detected `khaki`, `black`.
- Product `63`: detected `black`, `red`.
- Product `73`: detected `khaki`, `green`.

No trustworthy color detected:

```text
59, 62, 68, 69, 70, 71, 72, 74, 76,
78, 79, 80, 81, 82, 83, 84, 85, 87,
88, 89, 91, 92, 93, 94, 95, 98, 99
```

These variants remain colorless. No `other` color was assigned because doing so would hide missing catalog data.

## Idempotency

The second run created zero variants. It also reconciled safe improvements to legacy-generated color mappings; for example, product `65` now resolves to canonical `navy` without treating the Vietnamese word “đồ” as the alias `do`/red.
