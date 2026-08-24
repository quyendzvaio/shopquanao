# Fashion extraction pipeline

Both styling use cases share this pipeline:

```text
shop anchor
  → FindMine Demo MCP
  → RawFashionSuggestion[]
  → LlmFashionAttributeExtractor
  → ExtractedFashionItem[]
  → FashionRequirementNormalizer
  → validated FashionRequirement[]
  → controlled relaxation + bounded parallel Product Search
  → real shop products
```

The extractor uses the existing LLM provider with a required strict function, temperature `0`, `max_tokens=600`, and at most one retry. It receives styling text only; it cannot emit product IDs or inventory. Literal unknown tokens (`null`, `unknown`, `none`, `n/a`) are converted to `null`.

Normalization is deterministic and owns aliases, canonical colors, materials, footwear taxonomy, impossible combinations, and safe textual fallback. Search never relaxes to an unscoped query. Zero-result groups remain explicit.

Metrics: `fashion_extraction_calls_total`, `fashion_extraction_success_total`, `fashion_extraction_failure_total`, `fashion_extraction_invalid_schema_total`, `fashion_normalization_success_total`, `fashion_normalization_unknown_category_total`, and `fashion_search_relaxation_total`.
