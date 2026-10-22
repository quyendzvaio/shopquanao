# Layered refactor, Part A (harness) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure `src/` into responsibility layers and split the chatbot into a component-based harness. LLM providers plug in through one interface. Guardrail, fallback, nudge and tracing become real pluggable middleware. External behaviour stays identical.

**Architecture:**
- **Task 1** moves every file into the target tree (`app/ core/ service/ repository/ model/ harness/ agent/ llm_providers/`). It changes no logic; Composer classmap makes this safe.
- **Tasks 2–8** carve the chatbot out of `ChatbotService`:
  - LLM out of the harness;
  - env reading into `AgentFactory`;
  - a 3-hook middleware stack;
  - four middleware;
  - `TurnRunner`.
- **Task 9** adds a test that enforces the boundaries.

**Tech Stack:** PHP 8.2 (Composer classmap, no namespaces), PHPUnit, PHPStan level 1, MariaDB 10.11 (`shop_test`), Docker wrappers `scripts/dev/php` and `scripts/dev/composer`.

**Spec:** `docs/superpowers/specs/2026-10-22-layered-refactor-design.md`. Part A covers §3, §3.1 (harness rules), §4, §6 and §7 steps 1–6. Part B (website) is a separate plan.

## Global Constraints

- **No test expectation changes.** No existing assertion and none of the 40 eval cases may change. Tests may only change file paths and the lines that construct or call a moved or re-signatured class.
- Composer classmap, **no namespaces**. Directories are lowercase. Class names keep PascalCase and their current names unless a task says otherwise.
- **No change** to: HTTP API, URLs, DB schema, HTML/CSS, `mcp-server/`.
- **Full gate after every commit.** Each command must exit 0. Expected: Unit `OK`, eval `40/40 passed`.
  ```bash
  scripts/dev/composer dump-autoload -q
  scripts/dev/php vendor/bin/phpunit --testsuite=Unit
  scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
  scripts/dev/test-db.sh && scripts/dev/test-integration.sh
  DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="${MARIADB_ROOT_PASSWORD:-root_pass}" scripts/dev/php scripts/run_agent_eval.php
  npm --prefix mcp-server test
  ```
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- **Never** commit `.env`, `reports/`, `output/` or `docs/*.docx`. Never run `docker compose down` or `--remove-orphans`.
- Middleware named `required` in `config/harness.php`: `CheckoutGuardrail`, `AnswerTemplateFallback`. Optional: `FreeShippingNudge`, `TraceOutbox`.
- Exact copy that must not change:
  - checkout refusal (built by `ResponseGenerator`);
  - nudge sentence (`FreeShippingNudge::message`);
  - `latency.streaming` values `true` / `'template'` / `'template_fallback'`;
  - log JSON `{"operation":"chatbot_stream_llm","success":false,"error_category":<reason>}` and `{"operation":"freeship_nudge","success":false,"error_category":"suppressed_runtime_failure"}`.

## Review Focus

1. **A checkout request is refused but still saved to chat history** and appears in `/api/chatbot/history`. Test in Task 8 (`AgentAssemblyTest::testBlockedCheckoutTurnIsStillSaved`).
2. **The streaming LLM fails after partial text on a cart near the threshold.** The user sees the partial text, then `\n\n` plus the draft, then the nudge sentence as the last delta. Test in Task 7 (`FreeShippingNudgeMiddlewareTest::testNudgeDeltaComesAfterFallbackText`).
3. **A guest (no user) with nudge enabled** gets no nudge and no error. Test in Task 7.
4. **`CHATBOT_TOOL_TRANSPORT=mcp` without `MCP_SERVICE_TOKEN`** still fails at construction with `RuntimeException('MCP_SERVICE_TOKEN is not configured')`. Test in Task 3.
5. **The `harness` options key never reaches the provider's HTTP body.** Test in Task 4 (`MiddlewareModelTest::testHarnessKeyIsStrippedBeforeProvider`).

---

### Task 0: Preconditions

- [ ] **Step 1:** Confirm PR #14 is merged: `gh pr view 14 --json state -q .state`. Expected: `MERGED`. If it is not merged, stop and ask the user.
- [ ] **Step 2:** `git checkout refactor/harness-components && git rebase origin/develop` (after `git fetch`). Run the full gate. It must be green before Task 1 starts. Record the Unit count from the PHPUnit summary as the baseline (expected 157).

### Task 1: Move every file into the layered tree (no logic change)

**Files:** `git mv` exactly as below. All paths are relative to `src/` unless they start with `tests/`.

| From | To |
|---|---|
| `bootstrap.php` | `core/bootstrap.php`. Leave a one-line shim at `src/bootstrap.php`: `<?php require __DIR__ . '/core/bootstrap.php';` |
| `Infrastructure/Cache/Cache.php` | `core/cache/Cache.php` |
| `Infrastructure/Database/MigrationRunner.php` | `core/database/MigrationRunner.php` |
| `Infrastructure/Observability/*.php` | `core/observability/` |
| `Http/helpers.php` | `core/http/helpers.php` |
| `Http/middleware.php` | `app/api/middleware.php` |
| `Http/routes.php` | `app/api/routes.php` |
| `Http/Controllers/<Group>/*.php` | `app/api/controllers/<Group>/*.php` (group folder names unchanged, so `routes.php` values stay valid) |
| `Chatbot/ChatStreamProtocol.php` | `app/api/support/ChatStreamProtocol.php` |
| `Chatbot/ChatbotService.php`, `Chatbot/Llm/LLMFactory.php` | `agent/` |
| `Chatbot/Llm/DeepSeekProvider.php` | `llm_providers/DeepSeekProvider.php` |
| `Chatbot/Llm/{LLMProvider,LLMResponse,StreamingLLMProvider}.php` | `harness/llm/` |
| `Chatbot/Pipeline/{IntentResolver,DeterministicIntentParser,PartialParseResult,ConflictDetector,ConflictResolver,MergeEngine,LlmInputParser,SemanticEntityEnricher,TurnTierGate}.php` | `harness/intent/` |
| `Chatbot/Pipeline/{ChatbotToolManifest,CapabilityRegistry,ToolPlanner,PlanValidator}.php` | `harness/planning/` |
| `Chatbot/Pipeline/{EvidenceExecutionLoop,ParallelToolExecutor,NoProgressDetector,EvidenceDecisionRouter}.php` | `harness/loop/` |
| `Chatbot/Pipeline/{EvidenceNormalizer,ProductConstraintVerifier,ObservationEvaluator,LightweightEvidenceScorer}.php` | `harness/evidence/` |
| `Chatbot/Pipeline/{ResponseGenerator,StreamingResponseGenerator}.php` | `harness/response/` |
| `Chatbot/Contracts/ChatbotToolGateway.php`, `Chatbot/Tools/*.php` | `harness/tools/` |
| `Chatbot/Contracts/{ChatbotMemoryStore,ChatbotConversationStore}.php`, `Chatbot/Memory/*.php`, `Chatbot/ChatbotSessionContext.php` | `harness/memory/` |
| `Chatbot/FreeShipping/FreeShippingNudge.php` | `harness/middleware/FreeShippingNudge.php` |
| `Chatbot/FreeShipping/{FreeShippingService,FreeShippingGapAdvisor}.php` | `service/freeship/` |
| `Chatbot/FreeShipping/FreeShippingPolicy.php` | `model/FreeShippingPolicy.php` |
| `Chatbot/Outfit/{OutfitBuilder,OutfitService,OutfitPalette}.php` | `service/outfit/` |
| `Chatbot/Outfit/OutfitCatalogRepository.php` | `repository/OutfitCatalogRepository.php` |
| `Chatbot/Outfit/{OutfitItem,OutfitRequest,OutfitResult,OutfitSet,OutfitSlot}.php` | `model/` |
| `Chatbot/Knowledge/*.php` | `service/knowledge/` |
| `Shop/ProductService.php`, `Shop/Catalog/{CatalogTaxonomy,CatalogVariantBackfill,CatalogVariantHydrator,ProductAttributeNormalizer,ProductReranker}.php` | `service/catalog/` |
| `Shop/Catalog/{CatalogColor,ProductVariant,StyleTag}.php` | `model/` |
| `Shop/CartService.php` | `service/cart/` |
| `Shop/OrderService.php` | `service/order/` |
| `Shop/SizeService.php` | `service/size/` |
| `tests/Unit` | `tests/unit/<mirror of the src path of the primary class under test>` (table below) |
| `tests/Integration` | `tests/integration` |
| `tests/Support` | `tests/support` |

Test placement:

| Folder | Tests |
|---|---|
| `app/api` | ChatStreamProtocolTest |
| `harness/intent` | ChatbotGuardrailTest, ConflictGateTest, FreeShippingIntentTest, InputParserGateTest, OutfitIntentTest, PolicyIntentKeywordTest, TurnTierGateTest, FinalReviewMinorsTest |
| `harness/planning` | ChatbotToolManifestTest, ManifestPlannerTest, OutfitPlanningTest |
| `harness/evidence` | EvidenceNormalizerImageUrlTest, ToolOutcomeEvidenceTest |
| `harness/response` | OutfitResponseTest, RefusalEchoTest, ResponseGeneratorGroundingTest, StreamingResponseGeneratorTest |
| `harness/tools` | ToolRegistryDelegationTest |
| `harness/middleware` | FreeShippingNudgeTest |
| `service/outfit` | OutfitBuilderTest, OutfitServiceTest, OutfitSlotPaletteTest |
| `service/freeship` | FreeShippingGapAdvisorTest, FreeShippingServiceTest |
| `service/catalog` | ColorGuardTest |
| `repository` | OutfitCatalogRepositoryTest |
| `model` | StyleTagTest |
| `core/database` | MigrationRunnerTest, ProductStyleTagsMigrationTest |

**Path fixes (the only content edits in this task):**
- `core/bootstrap.php`: `dirname(__DIR__)` becomes `dirname(__DIR__, 2)` for both `vendor/autoload.php` and `config/db.php`.
- `public/api/index.php:12,13,28,62`:
  - `src/Http/helpers.php` → `src/core/http/helpers.php`
  - `src/Http/middleware.php` → `src/app/api/middleware.php`
  - `src/Http/routes.php` → `src/app/api/routes.php`
  - `src/Http/Controllers/` → `src/app/api/controllers/`
- `app/api/middleware.php:2`: `__DIR__ . '/helpers.php'` → `dirname(__DIR__, 2) . '/core/http/helpers.php'`.
- `app/api/controllers/Admin/product_delete.php:14-15`: `dirname(__DIR__, 4)` → `dirname(__DIR__, 5)`.
- `harness/planning/ChatbotToolManifest.php` and `service/knowledge/KnowledgeRetriever.php` keep `dirname(__DIR__, 3)`. The depth is unchanged. Confirm that it still resolves to the repo root.
- `phpunit.xml`: `tests/Unit` → `tests/unit`, `tests/Integration` → `tests/integration`.
- `tests/bootstrap.php`: `/Support/SqliteShop.php` → `/support/SqliteShop.php`.
- Docblocks that name an old path (`Http/Controllers`, `src/Chatbot/...`): update the text.

- [ ] **Step 1:** Run the `git mv` commands from the table. Then `rmdir` the now-empty `src/Chatbot`, `src/Shop`, `src/Http`, `src/Infrastructure`.
- [ ] **Step 2:** Apply the path fixes above.
- [ ] **Step 3:** Run `grep -rn "src/Http\|src/Chatbot\|src/Shop\|src/Infrastructure\|tests/Unit\|tests/Integration\|tests/Support" --include=*.php --include=*.xml --include=*.sh --include=*.yml . | grep -v "^./docs/\|^./vendor/"`. Expected: no output. Remaining doc references are handled in Task 10.
- [ ] **Step 4:** Run the full gate. Expected: same Unit count as the baseline, eval 40/40. Also rebuild and run the stack: `docker compose up -d --build app`, wait until healthy, then `scripts/dev/smoke.sh`. Expected: every line starts with `ok`.
- [ ] **Step 5:** Commit: `refactor: move src into app/core/service/repository/model/harness/agent/llm_providers (no logic change)`.

### Task 2: LLM port split and DeepSeek HTTP core

**Files:**
- Create: `src/harness/llm/LLMTransportException.php` (cut from `LLMProvider.php`), `src/harness/llm/ToolCall.php` (cut from `LLMResponse.php`)
- Modify: `src/harness/llm/LLMResponse.php`, `src/llm_providers/DeepSeekProvider.php`
- Test: `tests/unit/llm_providers/DeepSeekProviderTest.php`, `tests/support/llm_stub_router.php`

**Interfaces:**
- Produces: `LLMResponse::__construct(string $content = '', string $finishReason = 'stop', array $toolCalls = [], ?array $usage = null, ?string $fallbackReason = null)`. Adds a public `?string $fallbackReason` property.

- [ ] **Step 1: Write characterization tests first, against the current provider.** `tests/support/llm_stub_router.php` is a PHP built-in-server router. Its response depends on the `model` field of the request body:
  - `ok` → 200 JSON `{"choices":[{"message":{"content":"xin chào"},"finish_reason":"stop"}]}`
  - `ratelimited` → 429 with header `Retry-After: 7`
  - `down` → 503
  - `stream` → `text/event-stream` with lines `data: {"choices":[{"delta":{"content":"Xin "}}]}`, `data: {"choices":[{"delta":{"content":"chào"}}]}`, `data: [DONE]`

  In `setUpBeforeClass`, the test starts `php -S 127.0.0.1:<free port> tests/support/llm_stub_router.php` with `proc_open`, and kills it in `tearDownAfterClass`. Test methods:
  - `testChatReturnsContent`: content `'xin chào'`.
  - `testRateLimitCarriesRetryAfter`: `LLMTransportException` with category `rate_limit` and `retryAfterSeconds === 7`.
  - `testServerErrorIsProviderUnavailable`: category `provider_unavailable`.
  - `testConnectionRefusedIsProviderUnavailable`: base URL `http://127.0.0.1:9`, category `provider_unavailable`.
  - `testStreamEmitsDeltasAndAccumulates`: deltas `['Xin ', 'chào']`, content `'Xin chào'`.
  - `testStreamServerErrorIsProviderUnavailable`.

  Construct the provider as `new DeepSeekProvider(apiKey: 'k', baseUrl: 'http://127.0.0.1:<port>/v1', model: '<case>', timeout: 5)`. Disable the response cache in this test by setting `putenv('REDIS_HOST=')` if `Cache` needs it. Check `Cache::getLlmResponse` and use a unique message per test so no case can hit a cached entry.
- [ ] **Step 2:** Run `scripts/dev/php vendor/bin/phpunit tests/unit/llm_providers/DeepSeekProviderTest.php`. Expected: PASS, because these tests pin the current behaviour.
- [ ] **Step 3:** Move `LLMTransportException` and `ToolCall` into their own files. Add `fallbackReason` to `LLMResponse`. In `DeepSeekProvider`, extract `private function endpoint(): string` and `private function classifyFailure(int|false $httpCode, int $curlErrno, string $curlError, array $headers): ?LLMTransportException`. Make `chat` and `chatStream` both use them. Keep the public behaviour, messages and categories exactly as they are.
- [ ] **Step 4:** Run the DeepSeek tests and then the full gate. Expected: PASS.
- [ ] **Step 5:** Commit: `refactor(llm): split LLM port types, share DeepSeek HTTP failure handling`.

### Task 3: AgentFactory owns configuration and wiring

**Files:**
- Create: `src/agent/AgentFactory.php`, `src/agent/HarnessConfig.php`
- Delete: `src/agent/LLMFactory.php` (its three readers move into `HarnessConfig` and `AgentFactory`)
- Modify:
  - `src/agent/ChatbotService.php`: the constructor stops reading env and stops calling `new` for collaborators.
  - `src/harness/tools/McpToolGateway.php`: no `getenv`.
  - `src/harness/response/ResponseGenerator.php`: `FreeShippingPolicy` is injected.
- Test: `tests/unit/agent/HarnessConfigTest.php`, `tests/unit/agent/AgentFactoryTest.php`

**Interfaces:**
- Produces:
  - `HarnessConfig::fromEnvironment(): self`. A readonly class with fields:
    - `string $toolTransport` (`mcp` by default, `internal` when `APP_ENV=test`);
    - `bool $forceL0`, `bool $templateResponse`;
    - `?array $llm`, `?array $streamLlm`, `?array $extractionLlm`: each is the provider config array `LLMFactory::create` takes today, or `null`;
    - `array $mcp`: keys `service_token`, `timeout_seconds`, `node`, `script`, `path`, `shop_internal_url`, `request_timeout_ms`;
    - `FreeShippingPolicy $freeShipping`.

    Env reading keeps the current `getenv` → `$_ENV` → `$_SERVER` fallback.
  - `AgentFactory::__construct(HarnessConfig $config)`
  - `AgentFactory::llm(): ?LLMProvider`
  - `AgentFactory::streamingLlm(): ?StreamingLLMProvider`
  - `AgentFactory::toolGateway(PDO $pdo, ?int $userId): ChatbotToolGateway`
  - `AgentFactory::responseGenerator(): ResponseGenerator`
  - `McpToolGateway::__construct(?int $userId, array $config)`. Uses the `HarnessConfig::$mcp` keys. Throws `RuntimeException('MCP_SERVICE_TOKEN is not configured')` when `service_token` is `''`.
  - `ResponseGenerator::__construct(FreeShippingPolicy $freeShipping = new FreeShippingPolicy())`
  - `ChatbotService` keeps its exact public constructor and methods. When `$llm` is not injected (`func_num_args() < 4`), it asks `AgentFactory` built from `HarnessConfig::fromEnvironment()`.

- [ ] **Step 1: Write the failing tests.**
  - `HarnessConfigTest::testDefaultsToMcpTransport` and `testTestEnvDefaultsToInternal`: `putenv('APP_ENV=test')`, expect `toolTransport === 'internal'`.
  - `testForceL0AndTemplateFlags`: `'1'` and `'true'` both mean `true`.
  - `testFreeShippingFromEnv`: `FREE_SHIPPING_THRESHOLD=600000`, expect `freeShipping->threshold === 600000`.
  - `testStreamModelFallsBackToMainModel`: `LLM_STREAM_MODEL` unset, so `streamLlm['model']` equals `llm['model']`.
  - `AgentFactoryTest::testMcpWithoutTokenFailsAtConstruction` (Review Focus 4): transport `mcp` and empty token, so `toolGateway()` throws `RuntimeException` with message `MCP_SERVICE_TOKEN is not configured`.
  - `testInternalTransportBuildsToolRegistry`.
  - `testUnknownTransportThrows`: `InvalidArgumentException('CHATBOT_TOOL_TRANSPORT must be mcp, shadow, or internal')`.
  - `testForceL0DisablesBothModels`.

  Each test resets env in `tearDown`.
- [ ] **Step 2:** Run both test files. Expected: FAIL, because the classes are missing.
- [ ] **Step 3:** Implement. Move the logic of `LLMFactory::create/fromEnv/streamingFromEnv/fashionExtractionFromEnv`, `ChatbotService::envFlag/createToolGateway`, and the `getenv` reads in `McpToolGateway`/`ResponseGenerator` into `HarnessConfig` and `AgentFactory`. Every default value stays the same.
- [ ] **Step 4:** Run the new tests and the full gate. Also run `grep -n "getenv\|\$_ENV\|\$_SERVER" src/agent/ChatbotService.php src/harness/tools/McpToolGateway.php src/harness/response/ResponseGenerator.php`. Expected: no output.
- [ ] **Step 5:** Commit: `refactor(agent): AgentFactory + HarnessConfig own env and wiring`.

### Task 4: Middleware mechanism

**Files:**
- Create:
  - `src/harness/middleware/{Middleware,BaseMiddleware,MiddlewareStack}.php`
  - `src/harness/llm/{ModelCall,MiddlewareModel}.php`
  - `src/harness/turn/{TurnContext,TurnResult}.php`
  - `config/harness.php`
- Modify: `src/agent/AgentFactory.php`, `src/agent/ChatbotService.php`
- Test: `tests/unit/harness/middleware/MiddlewareStackTest.php`, `tests/unit/harness/llm/MiddlewareModelTest.php`

**Interfaces:**
- `interface Middleware`:
  - `beforeTurn(TurnContext $turn): ?TurnResult`
  - `wrapModelCall(ModelCall $call, callable $next): LLMResponse`, where `$next` is `callable(ModelCall): LLMResponse`
  - `afterTurn(TurnContext $turn, TurnResult $result): TurnResult`
  - `name(): string`
- `abstract class BaseMiddleware implements Middleware`:
  - pass-through defaults;
  - `name()` returns the short class name;
  - `public const REQUIRED = false`.
- `final class TurnContext`, readonly constructor:
  - `string $message`, `int $sessionId`, `?int $userId`, `array $memoryContext`
  - `?Closure $emit` (`null` means non-streaming)

  Method `emit(string $delta): void` is a no-op when there is no closure.
- `final class TurnResult`. An immutable wrapper over today's result array, so the array shape stays the contract:
  - `fromArray(array $data): self`
  - `toArray(): array`
  - `get(string $key, mixed $default = null): mixed`
  - `with(string $key, mixed $value): self`
- `final class ModelCall`, readonly:
  - `string $purpose` (`input_parse` | `entity_enrich` | `answer` | `unspecified`)
  - `array $messages`, `array $tools`, `string $toolChoice`, `array $options` (harness key removed)
  - `?string $groundedDraft`, `?Closure $onDelta`
  - `bool $stream`

  Method `withOnDelta(Closure $onDelta): self`.
- `final class MiddlewareStack`:
  - `__construct(array $middleware)` (list of `Middleware`)
  - `beforeTurn(TurnContext): ?TurnResult`
  - `wrapModelCall(ModelCall, callable $terminal): LLMResponse`
  - `afterTurn(TurnContext, TurnResult): TurnResult`
  - `names(): array`

  Behaviour:
  - `beforeTurn` stops at the first non-null result.
  - `wrapModelCall` nests onion-style: index 0 is outermost.
  - In `afterTurn`, if a non-`REQUIRED` middleware throws, the stack keeps the previous result and calls `error_log(json_encode(['operation' => 'middleware_' . $name, 'success' => false, 'error_category' => 'suppressed_runtime_failure']))`. A `REQUIRED` middleware's exception propagates.
- `final class MiddlewareModel implements LLMProvider, StreamingLLMProvider`:
  - `__construct(LLMProvider $inner, MiddlewareStack $stack)`
  - `chat(...)` and `chatStream(...)` build a `ModelCall`. They read and remove `$options['harness']` (`purpose` defaults to `unspecified`, `grounded_draft` defaults to `null`).
  - The terminal callable calls `$inner` with the stripped options.
  - `chatStream` throws `LogicException('Wrapped provider does not support streaming')` when `$inner` is not a `StreamingLLMProvider`.
- `config/harness.php` returns `['middleware' => []]` in this task.
- `AgentFactory`:
  - `middlewareStack(PDO $pdo, int $sessionId): MiddlewareStack` reads `config/harness.php` and instantiates each class with `AgentFactory::makeMiddleware(string $class, PDO $pdo, int $sessionId): Middleware`. This is a `match` on the class; later tasks add their arm.
  - `llm()` and `streamingLlm()` now return providers wrapped in `MiddlewareModel`.
- `ChatbotService`:
  - calls `$stack->beforeTurn` after the memory load and before `runPipeline`;
  - if `beforeTurn` returns a result, that result is used instead of `runPipeline`'s result;
  - calls `$stack->afterTurn` after the answer step and before `saveMessages`.

  Both `respond` and `respondStreaming` do this.

- [ ] **Step 1: Write the failing tests.**
  - `MiddlewareStackTest::testBeforeTurnStopsAtFirstBlocker`: the second middleware is never called.
  - `testWrapModelCallIsOnionOrdered`: a trace array equals `['a:in','b:in','call','b:out','a:out']`.
  - `testAfterTurnChainsResults`.
  - `testOptionalAfterTurnFailureIsIsolated`: result unchanged, the turn continues.
  - `testRequiredAfterTurnFailurePropagates`.
  - `MiddlewareModelTest::testHarnessKeyIsStrippedBeforeProvider` (Review Focus 5): a fake inner provider records its `$options`, and `array_key_exists('harness', $recorded)` is false.
  - `testPurposeAndDraftReachMiddleware`.
  - `testChatStreamOnNonStreamingProviderThrows`.
- [ ] **Step 2:** Run the tests. Expected: FAIL, because the classes are missing.
- [ ] **Step 3:** Implement the classes and the `ChatbotService`/`AgentFactory` wiring above.
- [ ] **Step 4:** Run the new tests and the full gate. Expected: PASS. With an empty stack, behaviour is unchanged.
- [ ] **Step 5:** Commit: `feat(harness): pluggable middleware stack (beforeTurn, wrapModelCall, afterTurn)`.

### Task 5: AnswerTemplateFallback middleware (required)

**Files:**
- Create: `src/harness/middleware/AnswerTemplateFallback.php`
- Modify:
  - `src/harness/response/StreamingResponseGenerator.php`: remove the try/catch and `fallback()`. Pass `['harness' => ['purpose' => 'answer', 'grounded_draft' => $draft]]` in the options.
  - `LlmInputParser.php` and `SemanticEntityEnricher.php` pass purpose `input_parse` and `entity_enrich`.
  - `config/harness.php`.
  - `AgentFactory`: add an arm, and in `middlewareStack()` throw `LogicException('Required middleware missing: <Class>')` for any class with `REQUIRED = true` that is absent from config. Required classes are discovered from the list `AgentFactory::REQUIRED_MIDDLEWARE = [CheckoutGuardrail::class, AnswerTemplateFallback::class]`. `CheckoutGuardrail` is added to that list in Task 6.
- Test: rename `tests/unit/harness/response/StreamingResponseGeneratorTest.php` to `tests/unit/harness/middleware/AnswerTemplateFallbackTest.php`.

**Interfaces:**
- `AnswerTemplateFallback extends BaseMiddleware`, `REQUIRED = true`. `wrapModelCall` acts only when `$call->purpose === 'answer' && $call->stream`:
  - It wraps `onDelta` to record the emitted text.
  - On `Throwable` it uses `reason` = `LLMTransportException->category`, otherwise `'runtime_failure'`.
  - If the trimmed content is `''`, `reason` = `'empty_answer'`.
  - When there is no draft (`groundedDraft` is null or `''`), it rethrows, or returns the empty response unchanged.
  - Otherwise it emits the tail (`$draft` if the emitted text trims to `''`, else `"\n\n" . $draft`) and returns `new LLMResponse(trim($emitted . $tail), 'stop', [], null, $reason)`.
- `StreamingResponseGenerator::stream(...)` still returns `array{text: string, fallback: ?string}`, with `fallback = $response->fallbackReason`.
- `StreamingResponseGenerator` still throws `RuntimeException('Streaming LLM returned an empty answer')` when the content is empty.

- [ ] **Step 1:** Move the test file. Change only its `generate()` helper so it passes `new MiddlewareModel($fake, new MiddlewareStack([new AnswerTemplateFallback()]))` to `stream()`. All six test methods and their assertions stay byte-identical. Add `testRequiredFallbackCannotBeRemovedFromConfig`: `AgentFactory` with a config list missing `AnswerTemplateFallback` throws `LogicException('Required middleware missing: AnswerTemplateFallback')`. Make the config path injectable with `AgentFactory::__construct(HarnessConfig $config, string $middlewareConfigPath = <repo>/config/harness.php)`.
- [ ] **Step 2:** Run the test file. Expected: FAIL, because the class is missing.
- [ ] **Step 3:** Implement, then set `config/harness.php` to `['middleware' => [AnswerTemplateFallback::class]]`.
- [ ] **Step 4:** Run the test file and the full gate. Then the end-to-end check from the spec:

  ```bash
  docker compose run --rm --no-deps -T -v "$PWD/src:/var/www/html/src:ro" -v "$PWD/config:/var/www/html/config:ro" \
    -e LLM_BASE_URL=http://127.0.0.1:9/v1 -e LLM_TIMEOUT=5 -e CHATBOT_TEMPLATE_RESPONSE=0 -e CHATBOT_TOOL_TRANSPORT=internal \
    --entrypoint php app <script that calls respondStreaming("Có 800k, cần đồ đi phỏng vấn, cao 1m70 nặng 65kg")>
  ```

  Expected: `latency.streaming === 'template_fallback'`, 3 cards, and the answer starts with `Mình gợi ý 2 set`.
- [ ] **Step 5:** Commit: `refactor(harness): LLM answer fallback becomes required middleware`.

### Task 6: CheckoutDetector + CheckoutGuardrail (required)

**Files:**
- Create: `src/harness/intent/CheckoutDetector.php`, `src/harness/middleware/CheckoutGuardrail.php`
- Modify:
  - `DeterministicIntentParser::isCheckout` delegates to `CheckoutDetector::matches()`.
  - `ResponseGenerator`: the refusal moves into `public function checkoutRefusal(string $message): string`. The `unsupported_checkout` branch calls it, so the text stays byte-identical.
  - `config/harness.php`: `CheckoutGuardrail` goes first.
  - `AgentFactory`: add an arm, and add `CheckoutGuardrail` to `REQUIRED_MIDDLEWARE`.
- Test: `tests/unit/harness/intent/CheckoutDetectorTest.php`, `tests/unit/harness/middleware/CheckoutGuardrailTest.php`

**Interfaces:**
- `final class CheckoutDetector`: `public static function matches(string $message): bool`. Applies `mb_strtolower` and then the existing regex from `DeterministicIntentParser::isCheckout`, copied verbatim.
- `CheckoutGuardrail extends BaseMiddleware`, `REQUIRED = true`. Constructor: `ResponseGenerator $responses`.

  `beforeTurn` returns `null` unless `CheckoutDetector::matches($turn->message)`. When it matches, it returns `TurnResult::fromArray(...)` with:
  - `message` and `answer`: `$responses->checkoutRefusal($turn->message)`
  - `products` and `cards`: `[]`
  - `knowledge_sources`: `[]`
  - `response_type`: `'final_answer'`
  - `primary_intent`: `'unsupported_checkout'`
  - `secondary_intents`, `requested_fields`, `missing_slots`: `[]`
  - `trace_id`: a new 32-hex id
  - `latency`: `['pipeline' => 'deterministic_hybrid_pipeline', 'blocked_by' => 'CheckoutGuardrail', 'total_ms' => 0]`

- [ ] **Step 1: Write the failing tests.**
  - `CheckoutDetectorTest`: a data provider of the 8 `guardrail` eval messages (read `eval/cases.jsonl`, group `guardrail`) asserts `true`. `'áo thun dưới 300k'`, `'mua thêm gì để được free ship'` and `'chính sách thanh toán'` assert `false`.
  - `CheckoutGuardrailTest::testBlocksCheckoutWithRefusal`: the message equals `(new ResponseGenerator())->checkoutRefusal($msg)` and `primary_intent` is `unsupported_checkout`.
  - `testPassesThroughOtherMessages`.
- [ ] **Step 2:** Run the tests. Expected: FAIL.
- [ ] **Step 3:** Implement. In streaming mode the blocked result's `message` is the grounded draft for the answer step: `ChatbotService` passes it through the same streaming or template branch as a pipeline result. For a blocked turn, `ChatbotService` also writes the `routing_pipeline` log with `['blocked_by' => 'CheckoutGuardrail', 'selected_tools' => []]`, so the blocked turn still leaves its routing record. The pipeline's own `unsupported_checkout` branches stay in place.
- [ ] **Step 4:** Run the tests and the full gate. Eval group `guardrail` must stay 8/8 with `no_tools`.
- [ ] **Step 5:** Commit: `refactor(harness): checkout guardrail as required beforeTurn middleware`.

### Task 7: FreeShippingNudge and TraceOutbox middleware

**Files:**
- Modify: `src/harness/middleware/FreeShippingNudge.php` (it becomes the middleware), `src/agent/ChatbotService.php` (delete `freeShippingNudge()`, `withNudgeCard()`, `enqueueLangfuseTrace()`), `config/harness.php`, `AgentFactory`
- Create: `src/harness/middleware/TraceOutbox.php`
- Test: `tests/unit/harness/middleware/FreeShippingNudgeMiddlewareTest.php`, `tests/unit/harness/middleware/TraceOutboxTest.php`

**Interfaces:**
- `FreeShippingNudge extends BaseMiddleware`, optional.
  - Constructor: `__construct(PDO $pdo, int $sessionId, FreeShippingPolicy $policy = new FreeShippingPolicy(), ?FreeShippingService $service = null)`. The existing `new FreeShippingNudge($pdo, 9)` calls in tests keep working.
  - The static methods `turnQualifies`, `eligible`, `message` and the instance methods `lastFingerprint`, `remember` stay unchanged.
  - `afterTurn` contains today's `ChatbotService::freeShippingNudge` logic plus `withNudgeCard`, with `$userId` read from `$turn->userId`.
  - On success it appends `' ' . $text` to `message`/`answer`, calls `$turn->emit(' ' . $text)`, and sets `freeship_nudge`. It also sets `freeship_nudge: false` when nothing was added.
  - It keeps its own try/catch and the existing log line. The stack's isolation is a second layer.
- `TraceOutbox extends BaseMiddleware`, optional. Constructor: `LangfuseTraceOutbox $outbox`. `afterTurn` calls `$outbox->enqueueSafely($result->get('trace_id'), $turn->message, $result->toArray(), $turn->userId, $turn->sessionId)` and returns `$result`.
- `config/harness.php` final order: `[CheckoutGuardrail, AnswerTemplateFallback, FreeShippingNudge, TraceOutbox]`.

- [ ] **Step 1: Write the failing tests.** Use the `tests/support/SqliteShop` fixture with a cart for user 1 just below the threshold, as in `FreeShippingServiceTest`.
  - `FreeShippingNudgeMiddlewareTest::testAppendsNudgeAndCardOnce`: the second identical turn adds nothing.
  - `testGuestGetsNoNudge` (Review Focus 3).
  - `testNudgeDeltaComesAfterFallbackText` (Review Focus 2): run the stream through `MiddlewareModel` with `AnswerTemplateFallback` and a fake that emits `'Bạn có thể '` and then throws `LLMTransportException('provider_unavailable', 'x')`, then `afterTurn`. The recorded deltas end with `["\n\n" . $draft, ' ' . $nudgeText]`.
  - `testNonShoppingIntentIsIgnored`.
  - `TraceOutboxTest::testEnqueuesResult`: use a fake `LangfuseTraceOutbox` subclass, or the real one on SQLite if its SQL is compatible.
- [ ] **Step 2:** Run the tests. Expected: FAIL.
- [ ] **Step 3:** Implement and delete the three `ChatbotService` methods.
- [ ] **Step 4:** Run the tests and the full gate. Eval group `freeship` must stay 10/10.
- [ ] **Step 5:** Commit: `refactor(harness): free-ship nudge and trace outbox as optional afterTurn middleware`.

### Task 8: TurnRunner and the ChatbotService facade

**Files:**
- Create: `src/harness/turn/TurnRunner.php`
- Modify: `src/agent/ChatbotService.php` (facade only), `src/agent/AgentFactory.php` (adds `turnRunner(...)`)
- Test: `tests/integration/AgentAssemblyTest.php`

**Interfaces:**
- `final class TurnRunner`:
  - Constructor:
    `__construct(ChatbotToolGateway $tools, ChatbotMemoryStore $memory, ChatbotConversationStore $conversations, ResponseGenerator $responses, MiddlewareStack $middleware, ?LLMProvider $llm, ?StreamingLLMProvider $streamingLlm, bool $templateResponse)`
  - `public function run(TurnContext $turn): TurnResult`

  `run` is a short sequence of private steps:
  1. `loadMemory`
  2. `beforeTurn`
  3. `resolveAndExecute`, which holds today's `runPipeline` together with `clarificationResponse`, `routingLog`, `selectedTools` and `recordToolExecutions`
  4. `answer`: non-stream uses the draft; stream uses the template branch, the `StreamingResponseGenerator` branch, or throws `RuntimeException('Configured LLM provider does not support native token streaming')`
  5. `afterTurn`
  6. `persist`: `saveMessages` + `refreshSummary`

  Because `TurnContext` is immutable, `loadMemory` returns a new `TurnContext` carrying `memoryContext`.
- `AgentFactory::turnRunner(PDO $pdo, int $sessionId, ?int $userId, array $overrides = []): TurnRunner`. The override keys are `llm`, `tool_gateway`, `memory`, `conversation_store` and `response_generator`, matching today's optional constructor arguments.
- `ChatbotService::respond(string $message): array` returns `$runner->run(new TurnContext($message, $sessionId, $userId, [], null))->toArray()`.
- `respondStreaming` does the same with `emit` set to `Closure::fromCallable($onDelta)`.

- [ ] **Step 1: Write the failing integration tests** on `shop_test`, with transport `internal` and no LLM. Seed rows in the `setUp` style of `OutfitCatalogIntegrationTest`.
  - `testBlockedCheckoutTurnIsStillSaved` (Review Focus 1): `respond('thêm áo mã 50 vào giỏ giúp mình')`. Expected: `primary_intent` is `unsupported_checkout`, the `chat_messages` table for the session has the user and bot rows, and no `tool_execution_logs` row has a tool name.
  - `testNudgeDisabledByConfig`: build `AgentFactory` with a temporary config file listing only the two required middleware. Use a user whose cart is 30000 below the threshold, asking `'tìm áo thun'`. Expected: `freeship_nudge` is falsy and the message does not contain `'miễn phí ship'`.
  - `testMissingRequiredMiddlewareFailsFast`.
  - `testTemplateModeEmitsSingleDraftDelta`: `CHATBOT_TEMPLATE_RESPONSE=1`. Expected: exactly one delta before the nudge delta, and `latency.streaming === 'template'`.

  Check the real table and column names in `database/schema.sql` before writing the assertions.
- [ ] **Step 2:** Run `scripts/dev/test-integration.sh --filter AgentAssemblyTest`. Expected: FAIL, because `TurnRunner` is missing or `AgentFactory` overrides are missing.
- [ ] **Step 3:** Implement `TurnRunner` by moving code out of `ChatbotService`. Reduce `ChatbotService` to the constructor plus the two methods. `wc -l src/agent/ChatbotService.php` must be ≤ 60.
- [ ] **Step 4:** Run the full gate. Then on localhost: `docker compose up -d --build app`, `scripts/dev/smoke.sh`, and `CHAT_STREAM_SMOKE_URL=http://localhost/api/chatbot/stream node scripts/smoke_chat_stream_latency.mjs` (`pass: true`). Also run `curl` on the outfit question. Expected: the same 2 sets as before, with ids 61+66 and 61+82.
- [ ] **Step 5:** Commit: `refactor(harness): TurnRunner runs one turn; ChatbotService becomes a facade`.

### Task 9: Boundaries enforced, long functions split

**Files:**
- Create: `tests/unit/ArchitectureTest.php`
- Modify, splitting into named private methods (each new public or private method ≤ 60 lines; behaviour unchanged):
  - `harness/loop/EvidenceExecutionLoop::run`
  - `harness/planning/PlanValidator::validateUseCase` and `::validate`
  - `harness/planning/CapabilityRegistry::knownMetadata`
  - `harness/intent/LlmInputParser::sanitize`
  - `harness/intent/SemanticEntityEnricher::enrich`
  - `harness/intent/DeterministicIntentParser::priceCandidates`
- Modify (formatting only):
  - `harness/middleware/FreeShippingNudge.php`: remove the blank line between a signature and its `{`.
  - `model/FreeShippingPolicy.php`: same fix.

**Interfaces:**
- `ArchitectureTest` scans `src/harness/**.php` with regex, after stripping comments, against the spec §4.1 table. Each class name maps to a component through the classmap (`vendor/composer/autoload_classmap.php`). It asserts:
  - `testHarnessNeverReferencesOutside`: no references to classes under `src/app`, `src/agent` or `src/llm_providers`.
  - `testHarnessReadsNoEnvironment`: no `getenv(`, `$_ENV` or `$_SERVER`.
  - `testComponentDependenciesFollowTable`: one allowed-set per component, copied from spec §4.1, plus `service`, `model`, `core` and `repository`. `middleware`→`turn` is limited to `TurnContext`/`TurnResult`. `llm`→`middleware` is limited to `Middleware`/`MiddlewareStack`.
  - `testNoHarnessFunctionOver80Lines`.

- [ ] **Step 1:** Write `ArchitectureTest` and run it. Expected: FAIL. The output must list concrete violations, for example the long functions or any env read left over.
- [ ] **Step 2:** Fix each violation by moving code or injecting it, never by widening the table. If a violation cannot be fixed without breaking the spec, stop and report it.
- [ ] **Step 3:** Run `ArchitectureTest` and the full gate. Expected: PASS.
- [ ] **Step 4:** Commit: `refactor(harness): enforce component boundaries; split long functions`.

### Task 10: Documentation and PR

**Files:**
- Create: `src/harness/README.md`, which contains:
  - the one-turn flow diagram: `loadMemory → beforeTurn → intent → loop(planning/tools/evidence) → response(llm via middleware) → afterTurn → persist`;
  - the spec §4.1 table;
  - "how to add a middleware";
  - "how to add an intent/tool".
- Create: `README.md` in each of `src/harness/{llm,turn,intent,planning,loop,evidence,tools,response,memory,middleware}/`. Each is 5–15 lines covering: what it does, its entry point, and what it may use.
- Modify: `README.md` (directory table), `docs/chatbot-spec.md` (call graph), `docs/technical-spec.md`, and the local-only `CLAUDE.md` and `.claude/rules/{architecture,testing,database}.md` (paths: `tests/unit`, `src/core/database/MigrationRunner`, the `src/harness` layout).

- [ ] **Step 1:** Write the READMEs and update the docs. Run `grep -rn "src/Chatbot\|src/Shop\|src/Http\|src/Infrastructure\|tests/Unit" README.md docs/*.md CLAUDE.md .claude/rules`. Expected: no output. Specs and plans under `docs/superpowers` are history; leave them.
- [ ] **Step 2:** Run the full gate one last time, plus `scripts/dev/smoke.sh` on the rebuilt stack.
- [ ] **Step 3:** Commit: `docs: harness component guide and updated architecture docs`.
- [ ] **Step 4:** Ask the user before pushing. Then `git push -u origin refactor/harness-components` and `gh pr create --base develop` with a body that lists:
  - the move map;
  - the middleware table;
  - the one behaviour difference (a checkout request with an unresolved conflict is now refused instead of clarified);
  - the gate results.

  End the body with the PR attribution line.
