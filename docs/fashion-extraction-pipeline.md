# Fashion Extraction Pipeline

## V1 Architecture

```
FindMine Demo MCP
  → RawFashionSuggestion[]
  → LlmFashionAttributeExtractor
      ├── Fast path: DeterministicFashionAttributeParser (trivial inputs)
      └── LLM path: emit_fashion_attributes tool call
          ├── Enum-constrained schema (category/subcategory/material/style/pattern/fit)
          ├── Two-stage validation: enum guard → semantic validator → deterministic output
          └── One bounded repair retry (invalid_schema only)
  → ExtractedFashionItem[]
  → FashionRequirementNormalizer
  → FashionRequirement[]
  → ParallelComplementaryProductSearcher (parallel shop Product Search)
  → ComplementaryProductFinder result
```

## Schema Versions

| Version | Schema | Prompt | Notes |
| --- | --- | --- | --- |
| v3 (current) | 3 | 3 | Enum-constrained tool schema; canonical mapping instructions for Vietnamese |
| v2 | 2 | 2 | Minimal prompt, no enum constraints |
| v1 | 1 | 1 | Legacy |

## Canonical Enums (v3)

| Field | Allowed Values |
| --- | --- |
| `category` | `shirt`, `trousers`, `footwear`, `jacket`, `dress`, `skirt`, `accessory`, `null` |
| `subcategory` | `sneakers`, `loafers`, `dress_shoes`, `blazer`, `hoodie`, `polo`, `jeans`, `midi_dress`, `belt`, `null` |
| `material` | `denim`, `linen`, `leather`, `wool`, `cotton`, `null` |
| `style` | `minimal`, `casual`, `simple`, `lightweight`, `null` |
| `pattern` | `striped`, `floral`, `pleated`, `null` |
| `fit` | `wide_leg`, `slim`, `oversized`, `regular`, `null` |

## Two-Stage Validation

- **Stage 1a (Enum guard)**: Rejects any non-canonical string in closed-vocabulary fields. Treated as `invalid_schema` → triggers single bounded repair retry.
- **Stage 1b (Structural contradiction)**: Rejects contradictions such as `category=shirt + subcategory=sneakers`.
- **Stage 2 (Canonical deterministic output)**: LLM output is replaced with `DeterministicFashionAttributeParser` result derived only from explicit source text. Never infers absent attributes (T12: null over inference).

## Rate Control

- Evaluation batch size: `FASHION_EXTRACTION_EVAL_BATCH_SIZE` (default 4).
- Rate-limited batches: exponential backoff with jitter, respects `Retry-After`.
- Infrastructure failures (429, timeout, provider unavailable) are classified and reported separately from semantic failures.

## Metrics

| Metric | Description |
| --- | --- |
| `fashion_extraction_fast_path_total` | Inputs handled by deterministic parser (no LLM call) |
| `fashion_extraction_llm_path_total` | Inputs sent to LLM |
| `fashion_extraction_calls_total` | Total LLM calls attempted |
| `fashion_extraction_repair_attempts_total` | Repair retry attempts |
| `fashion_extraction_repair_success_total` | Successful repairs |
| `fashion_extraction_invalid_schema_total` | Schema validation failures |
| `fashion_extraction_failure_total` | Total extraction failures |
| `fashion_extraction_success_total` | Successfully cached results |

## Hallucination Invariant

`EXTRACTION_HALLUCINATED_ATTRIBUTE_COUNT = 0`

The semantic validator always returns the deterministic parser output. Material, color, style, fit, and pattern are extracted only when explicitly present in the source text. This hard invariant is enforced at the `FashionExtractionSemanticValidator` level and tracked by the 32-case extraction evaluation.

## Future Optional Upgrade: findmine_live

Current V1 uses `FASHION_PROVIDER=findmine_demo`. Live tenant connectivity (`FINDMINE_APP_ID`, tenant catalog mapping) is a future optional upgrade only. `FINDMINE_LIVE_UPGRADE_STATUS=NOT_CONFIGURED` is informational and does NOT block `FASHION_INTEGRATION_GATE`.
