# FindMine integration implementation plan

## Existing components to reuse

- `ChatbotService` remains the orchestration boundary and owns conversation persistence.
- `DeterministicIntentParser` / `IntentResolver` provide deterministic intent and suitability signals.
- `ChatbotMemory` and `chat_session_memory.slots` provide session-scoped product context.
- `ProductService` / `ToolRegistry::search_products` remain the inventory and SKU source of truth.
- `ProductAttributeNormalizer` provides the existing Vietnamese shop color and text taxonomy.
- `ChatbotToolGateway` and the private stdio MCP transport establish the project's MCP conventions.
- SQL migrations in `sql/migrations` and PDO repositories establish persistence conventions.
- PHPUnit unit/integration suites and the Python evaluation harness provide verification conventions.

## New modules required

- Provider-independent fashion domain values and a `FashionProvider` interface.
- PDO repository for shop-to-provider product/variant/color mappings.
- Offline catalog-sync service and CLI entry point; chat code must never invoke it.
- FindMine MCP client/adapter after the live tool contract is verified.
- FindMine-to-shop taxonomy normalizer that extends, rather than replaces, shop taxonomy.
- Bounded parallel complementary product-search coordinator with requirement-to-results grouping.
- Explicit styling use-case service and response composer.
- Transactional cart event/outbox publisher, idempotent consumer, and proactive session-state service.

## Database changes

- `fashion_provider_product_mapping`: unique shop/provider identity and provider identity constraints, optional variant/color mapping, sync status/version/timestamps/error.
- Transactional event outbox for `cart.item_added` if no existing outbox is found (none exists today).
- Consumed-event deduplication and session-scoped proactive styling state.

## FindMine integration point

The application boundary is `FashionProvider::completeTheLook(AnchorProductRef)`. A FindMine adapter resolves only an already-persisted mapping and converts the verified MCP response into a validated `ComplementaryPlan`. Provider IDs terminate at this boundary. The downstream product-search coordinator emits only shop product cards.

## Testing approach

1. Unit-test domain validation, normalization, mapping uniqueness/state transitions, provider response parsing, and grouped search behavior.
2. Run live provider connectivity and known-mapping smoke tests before enabling either chatbot use case.
3. Add explicit styling integration tests, then proactive event/state tests.
4. Run backend smoke tests, exactly 50 deterministic agent turns, and RAGAS only on answers grounded in actual Product Search contexts.

## Risks

- FindMine's MCP tool schema and catalog-ingestion contract may be tenant-only and unavailable without credentials. No request or parser can be implemented from guessed fields.
- The current shop catalog has products and sizes but no first-class variant/color tables; variant/color provider fields must therefore remain nullable until that model exists or the verified provider requires them.
- The existing `ParallelToolExecutor` is sequential despite its name; complementary search needs a genuinely concurrent I/O path without changing unrelated chatbot execution.
- Cart writes occur through both REST `CartService` and legacy `add_to_cart.php`; event reliability requires routing both through one transactional service or instrumenting both paths.
- Several orchestration/evaluation files have pre-existing uncommitted edits and must be merged without overwriting user work.
