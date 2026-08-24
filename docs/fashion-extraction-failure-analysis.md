# Fashion extraction failure analysis

Baseline run: 2026-08-24, unchanged 32-case corpus in `tests/fixtures/findmine/fashion-extraction-cases.php`.

## Baseline metrics

```text
cases=32
successful_cases=32
schema_valid_outputs=32
schema_valid_rate_on_completed_requests=100%
semantic_exact_cases=2
semantic_exact_rate=6.25%
hallucinated_attributes=22
hard_category_color_material_hallucinations=0
batch_schema_failures=1
batch_retries=1
single_case_fallbacks=4
rate_limits=0
timeouts=0
duration_ms=18728
```

The earlier 53.13% result was not reproducible in this run. All requests completed; therefore the dominant failure is semantic taxonomy drift, not infrastructure. One four-item batch returned an invalid structured shape twice and was recovered by four single-item calls. The legacy evaluator did not retain the provider's raw HTTP body; `model output` below is the exact decoded `emit_fashion_attributes.arguments.items` value that reached application validation.

## Failure category counts

| Category | Count | Classification |
| --- | ---: | --- |
| `UNKNOWN_ENUM` / non-canonical taxonomy | 27 cases | `EXTRACTION_MODEL_FAILURE` |
| `NULL_HANDLING` / unsupported inferred attribute | 10 cases | `EXTRACTION_MODEL_FAILURE` |
| `OTHER` / explicit attribute placed in wrong field | 7 cases | `EXTRACTION_MODEL_FAILURE` |
| `MISSING_REQUIRED_ROOT` or invalid structured batch | 1 batch | `EXTRACTION_MODEL_FAILURE` |
| `RATE_LIMIT` | 0 | `INFRASTRUCTURE_FAILURE` |
| `TIMEOUT` | 0 | `INFRASTRUCTURE_FAILURE` |
| Provider unavailable / connection error | 0 | `INFRASTRUCTURE_FAILURE` |

Counts overlap because one case can contain several semantic defects.

## Per-case results

Cases 4 (`giày sneaker đen`) and 13 (`casual piece`) matched exactly. All other cases were schema-valid but semantically different:

| ID | Raw suggestion | Model output differences | Expected differences | Failure type |
| ---: | --- | --- | --- | --- |
| 1 | `white denim trousers` | category=`bottomwear`, subcategory=`trousers` | category=`trousers`, subcategory=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 2 | `quần jean trắng` | category=`bottomwear`, subcategory=`jeans` | category=`trousers`, subcategory=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 3 | `black minimal sneakers` | style=null | style=`minimal` | `OTHER` |
| 5 | `beige blazer` | category=`outerwear` | category=`jacket` | `UNKNOWN_ENUM` |
| 6 | `áo khoác màu be` | category=`outerwear`, subcategory=`áo khoác`, color=`beigé` | category=`jacket`, subcategory=null, color=`beige` | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 7 | `blue slim-fit jeans` | category=`pants`, material=null, fit=`slim-fit` | category=`trousers`, material=`denim`, fit=`slim` | `UNKNOWN_ENUM`, `OTHER` |
| 8 | `white cotton shirt` | category=`tops`, subcategory=`shirt` | category=`shirt`, subcategory=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 9 | `striped navy trousers` | category=`bottomwear`, subcategory=`trousers` | category=`trousers`, subcategory=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 10 | `nice trousers` | category=`bottomwear`, subcategory=`trousers` | category=`trousers`, subcategory=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 11 | `stylish shoes` | subcategory=`shoes`, style=`stylish` | both null | `NULL_HANDLING` |
| 12 | `summer look` | style=`summer` | style=null | `NULL_HANDLING` |
| 14 | `red linen wide-leg trousers` | category=`bottoms`, subcategory=`trousers`, fit=`wide-leg` | category=`trousers`, subcategory=null, fit=`wide_leg` | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 15 | `quần ống rộng vải lanh đỏ` | category=`quần`, subcategory=`quần ống rộng`, color=`đỏ`, material=`vải lanh`, fit=`ống rộng` | `trousers`, null, `red`, `linen`, `wide_leg` | `UNKNOWN_ENUM` |
| 16 | `brown leather loafers` | category=`shoes` | category=`footwear` | `UNKNOWN_ENUM` |
| 17 | `giày lười da nâu` | category=`giày`, subcategory=`giày lười`, color=`nâu`, material=`da`, style=`lười` | `footwear`, `loafers`, `brown`, `leather`, style=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 18 | `navy wool blazer` | category=`áo khoác`, color=`xanh navy`, material=`len`, style=`blazer` | `jacket`, `navy`, `wool`, style=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 19 | `áo blazer len màu xanh navy` | same drift as case 18 | same canonical values as case 18 | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 20 | `floral midi dress` | category=`đầm`, subcategory=`đầm midi`, style=`midi`, pattern=null | `dress`, `midi_dress`, style=null, pattern=`floral` | `UNKNOWN_ENUM`, `OTHER`, `NULL_HANDLING` |
| 21 | `váy midi họa tiết hoa` | category=`women's clothing`, subcategory=`dress`, fit=`midi`, pattern=null | `dress`, `midi_dress`, fit=null, pattern=`floral` | `UNKNOWN_ENUM`, `OTHER`, `NULL_HANDLING` |
| 22 | `oversized black hoodie` | category=`women's clothing` | category=`shirt` | `UNKNOWN_ENUM` |
| 23 | `áo hoodie đen dáng rộng` | category=`women's clothing`, fit=null | category=`shirt`, fit=`oversized` | `UNKNOWN_ENUM`, `OTHER` |
| 24 | `cream pleated skirt` | category=`women's clothing`, subcategory=`skirt`, fit=`pleated`, pattern=null | category=`skirt`, subcategory=null, fit=null, pattern=`pleated` | `UNKNOWN_ENUM`, `OTHER`, `NULL_HANDLING` |
| 25 | `chân váy xếp ly màu kem` | category=`clothing`, subcategory=`skirt`, pattern=null | category=`skirt`, subcategory=null, pattern=`pleated` | `UNKNOWN_ENUM`, `OTHER`, `NULL_HANDLING` |
| 26 | `monk strap shoes` | category=`shoes`, subcategory=`shoes`, style=`monk strap` | `footwear`, `dress_shoes`, style=null | `UNKNOWN_ENUM`, `NULL_HANDLING` |
| 27 | `simple black belt` | category=`accessories` | category=`accessory` | `UNKNOWN_ENUM` |
| 28 | `thắt lưng đen` | category=`accessories` | category=`accessory` | `UNKNOWN_ENUM` |
| 29 | `lightweight beige jacket` | category=`outerwear`, subcategory=`jacket`, style=null | category=`jacket`, subcategory=null, style=`lightweight` | `UNKNOWN_ENUM`, `NULL_HANDLING`, `OTHER` |
| 30 | `áo khoác nhẹ màu be` | same drift as case 29 | same canonical values as case 29 | `UNKNOWN_ENUM`, `NULL_HANDLING`, `OTHER` |
| 31 | `regular-fit polo shirt` | category=`top`, subcategory=`polo shirt` | category=`shirt`, subcategory=`polo` | `UNKNOWN_ENUM` |
| 32 | `áo polo dáng thường` | category=`top`, subcategory=`polo shirt`, fit=null | category=`shirt`, subcategory=`polo`, fit=`regular` | `UNKNOWN_ENUM`, `OTHER` |

## Root causes

1. The schema constrained types but did not constrain the taxonomy vocabulary, so syntactically valid arbitrary strings passed.
2. The prompt allowed attributes “strongly represented,” encouraging inference.
3. Validation checked structure only; it had no deterministic semantic consistency layer.
4. The retry repeated the same request instead of supplying the invalid output and exact error.
5. The evaluator mixed request reliability, schema validity, and semantic accuracy into one status.
6. The evaluator's four-item batch fallback increased request pressure and did not classify infrastructure errors independently.

These findings determine the next implementation slices: strict prompt/tool contract, explicit canonical semantic validation, one repair-only retry, typed infrastructure classification, conservative deterministic fast path, and corrected evaluation metrics.
