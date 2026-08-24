# Catalog variant and footwear readiness plan

## Current catalog structure

- `products` stores one category, base price, aggregate stock, description, and one image.
- `categories` is a flat four-row lookup (`Áo`, `Quần`, `Váy & Đầm`, `Phụ kiện`).
- `product_sizes` stores product-level size labels without SKU, price, stock, or color.
- Color is inferred from product name/description by `ProductAttributeNormalizer`.
- Product Search reads `products`, then attaches sizes in one query; it has no structured variant/color join.
- Product cards expose the legacy fields `id`, `price`, `stock`, `available_sizes`, and `available_colors`.
- `fashion_provider_product_mapping` was prepared with string-like variant identifiers but no foreign key to a catalog variant.

## Current limitations

- Footwear has neither a category nor subcategory representation.
- SKU, color, size, price, and stock cannot be represented as one sellable variant.
- Product-level stock cannot be safely distributed among sizes during backfill.
- Existing inferred Vietnamese colors are useful for compatibility but are not canonical structured data.
- Provider color mappings cannot be tied to a real local variant row.

## Existing fields that can be reused

- `products.id`, `price`, and `stock` remain the backward-compatible product/base values.
- `products.category_id` remains the top-level category foreign key.
- `product_sizes.size_name` supplies known size labels during backfill.
- Product names/descriptions can normalize a single known color, but absent or ambiguous colors remain unknown.
- Existing product image remains the product-level image; no unsupported variant-image model is introduced.

## Required schema changes

- Add canonical metadata to top-level categories and seed the `footwear` family.
- Add `product_subcategories` and seed footwear subcategories.
- Add nullable `products.subcategory_id`.
- Add canonical `colors`.
- Add `product_variants` with nullable SKU/color/size/price/stock and an explicit stable variant key.
- Change provider mappings to reference numeric `product_variants.id`, while retaining safe product-level mappings.

The catalog enrichment module will expose one small interface that batch-loads variants/colors for product rows. This keeps structured catalog complexity local and avoids separate query logic in chatbot and REST callers.

## Migration risks

- Adding foreign keys can fail if migrations are applied out of order; the catalog migration must sort before the mapping migration.
- Existing aggregate stock cannot be truthfully split across generated variants, so backfilled variant stock stays `NULL` and inherits product-level availability.
- Existing implicit color phrases may be absent or ambiguous; those records must remain colorless and be reported for manual review.
- The migration runner has no rollback convention, so the additive migration is forward-only and preserves legacy columns/tables.

## Backward-compatibility risks

- Existing callers assume category IDs `1..4`; those IDs and meanings remain unchanged and footwear is added as ID `5`.
- Existing callers expect Vietnamese `available_colors`; that field remains, while canonical colors and variants are additive.
- Existing cart/order rows reference products rather than variants; this task does not force an immediate checkout/cart migration.
- Products without variant rows continue to use base price/stock and legacy size/color behavior.

## Product Search changes

- Accept additive `category`, `subcategory`, and structured color filters while retaining `category_id` and text search.
- Join/filter variants with `EXISTS`, then hydrate all returned product variants in one batch query.
- Return additive `subcategory`, `variants`, `colors`, and `canonical_colors` fields without exposing provider identifiers.
- Preserve current product visibility and product-level fallback behavior.

## Tests required

- Canonical footwear family and all required footwear aliases/subcategories.
- Canonical English color values and Vietnamese aliases.
- Multiple sizes/colors and inherited price/stock behavior.
- Exact variant/color provider mapping lookup and uniqueness.
- Footwear, color, combined footwear/color, and existing apparel searches.
- Legacy products without variants and legacy response fields.
- MariaDB migration/backfill, catalog smoke tests, and the full regression suite.
