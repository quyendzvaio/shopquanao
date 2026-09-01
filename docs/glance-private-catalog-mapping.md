# Glance reference grounding

`PrivateCatalogStyleMapper` is the provider-neutral seam between a normalized
`StyleReference` and the shop's existing Product Search. It does not know
Glance schemas and never treats a provider identifier as a shop product ID.

For each reference it:

1. normalizes the observed Glance categories (`Topwear`, `Bottomwear`,
   `Footwear`, `Outerwear`) through `FashionTaxonomyNormalizer`;
2. retrieves multiple candidates through the existing bounded parallel search
   gateway;
3. hard-rejects unavailable, hidden, wrong-role, and wrong-category products;
4. deterministically scores accepted private products using category, color,
   style, subcategory, and anchor compatibility; and
5. returns `MappedStyleReference` with private candidates and a selected shop
   product.

`mapMany()` submits all supported references in one bounded search batch, so
independent Product Search calls retain the existing parallelism. Provider IDs
remain only in `StyleReference.sourceReferenceId`; candidates contain shop
catalog IDs and provider fields are removed.

The mapper has deterministic unit coverage, including a wrong-category loafer
case and provider-ID leakage checks. A full live UC1 request remains gated on a
runtime Glance anchor provider and a tenant-confirmed Glance anchor mapping;
this change does not fabricate either one.
