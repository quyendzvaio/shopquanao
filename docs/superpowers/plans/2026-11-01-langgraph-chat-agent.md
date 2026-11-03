# LangGraph chat agent: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the deterministic PHP chat pipeline with a TypeScript LangGraph agent (`chat-graph` service) where the LLM picks read-only shop tools, the old hard rules live in a policy middleware layer, and a sold-out branch asks the customer about similar in-stock products.

**Architecture:** PHP keeps data, tools, sessions, chat history and all four memories. It calls the internal Node service `chat-graph` over HTTP (`POST /turn`, NDJSON). `chat-graph` builds a LangGraph `StateGraph` around a LangChain.js v1 `createAgent`. Tools come from `mcp-server`'s `createMcpServer` over an in-memory MCP transport, and policies are `createMiddleware` hooks plus graph nodes. The old pipeline stays in the tree, unused, until the live eval reaches ≥ 90%. It is then deleted in one final commit.

**Tech Stack:** Node 22 + TypeScript 5.9 (`node --test` + `tsx`), `langchain` / `@langchain/core` / `@langchain/langgraph` / `@langchain/openai` / `@langchain/mcp-adapters` (all 1.x, pinned exact), `@modelcontextprotocol/sdk` 1.30.0, `zod` 4.4.3. PHP 8.2 + PHPUnit, MariaDB 10.11, Docker Compose, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md` (approved 2026-11-01). Section refs like §5.2a point there.

## Global Constraints

- No tool that writes cart, order or payment. The tool set is exactly 9 read-only tools: `search_products`, `get_product_detail`, `build_outfit`, `suggest_freeship_items`, `suggest_size`, `retrieve_knowledge`, `get_categories`, `get_order_status`, `find_similar_in_stock`.
- SQL only in `src/repository/`. Env read only in `src/agent/` and `src/core/` (PHP), and only in `chat-graph/src/config.ts` (Node).
- `chat-graph` has no public port and no nginx route. PHP authenticates to it with header `X-CHAT-GRAPH-TOKEN`, checked in constant time (`crypto.timingSafeEqual`).
- Per-turn budget defaults: `CHAT_GRAPH_MAX_TOOL_CALLS=4`, `CHAT_GRAPH_MAX_MODEL_CALLS=5`, `CHAT_GRAPH_TURN_TIMEOUT_MS=45000`. PHP waits for `/turn` at most 50 s (`CHAT_GRAPH_TIMEOUT=50`).
- Busy copy, exact: `Hệ thống đang bận, bạn thử lại sau ít phút nhé.` with `response_type=busy`.
- Checkout refusal copy, exact (port of `ResponseGenerator::checkoutRefusal` + `echoQuote`): `Với yêu cầu "<message, whitespace collapsed, cut to 117 chars + … when longer than 120>", mình không thể tự thêm giỏ hàng hoặc thanh toán giúp bạn. Bạn vui lòng bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán.`, with `response_type=refusal` and `primary_intent=unsupported_checkout`.
- Sold-out copy, exact:
  - Alone, ≥ 2 alternatives: `Mẫu {source.name} đang hết hàng. Bạn có muốn xem {alt1.name} hoặc {alt2.name} không?`
  - Alone, 1 alternative: `Mẫu {source.name} đang hết hàng. Bạn có muốn xem {alt1.name} không?`
  - After another answer: the same sentence with `Mẫu` replaced by `Còn mẫu`, joined to the LLM text with a single space.
  - No alternatives: `Shop có {N} mẫu {search} nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.`
- Sold-out note shown to the LLM instead of the search result, exact: `Mẫu {source.name} đã hết hàng; hệ thống sẽ tự hỏi khách về mẫu thay thế. Đừng nhắc tới mẫu này. Chỉ trả lời các ý khác của khách; nếu không còn ý nào, trả lời chuỗi rỗng.`
- Freeship nudge copy, exact (port of `FreeShippingNudge::message`): `Giỏ hàng của bạn còn thiếu {gap} để được miễn phí ship (đơn từ {threshold}); bạn có thể tham khảo thêm {name} (mã {id}) giá {price}.` Money is formatted `number_format(v, 0, ',', '.') . 'đ'`, e.g. `490.000đ`.
- History window: the last 6 messages. Cards per turn: at most 6.
- Memory sent to Node is whitelisted (§5.5). Never sent: `pregnant`, `skin_type`, `skin_tone`, `body_shape`, `important_events`, `feedback`, `avoid_ingredient`, `favorite_brand`, `favorite_country`, nor the slots `product_type`, `color`, `category_id`, `style`, `occasion`, `material`.
- LangChain.js packages must be ≥ 1.0 and pinned exact (`--save-exact`). Every LangChain/LangGraph API used must be checked against the installed `.d.ts` files, never from memory.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `.env`, `reports/`, `docs/*.docx`, `output/`.

## Review Focus

1. **The LLM omits `in_stock` for "quần baggy còn hàng".** The sold-out branch must still fire, because the search returns only sold-out items → `out_of_stock_ids=[69]`. Pinned by Task 1 `testUnfilteredSearchWithOnlySoldOutReportsIds` and Task 5 `soldOutFiresWithoutInStockArg`.
2. **The LLM writes a price as `490k` or `490.000 đ`.** Grounding must normalize these instead of flagging a correct price, otherwise every answer gets a retry. Pinned by Task 4 `groundingAcceptsPriceSpellings`.
3. **`chat-graph` is up but the LLM hangs.** Waiting for `LLM_TIMEOUT` on each of 5 model calls blows past 50 s. The 45 s turn deadline must abort and answer busy before PHP's own timeout. Pinned by Task 6 `turnDeadlineAnswersBusy`.
4. **A guest asks about free shipping.** The tool returns `requires_login`. The nudge must not fire for `user_id=null`, and the turn must still answer. Pinned by Task 5 `noNudgeForGuest`.
5. **The client disconnects mid-stream, or Node returns malformed NDJSON.** PHP must save the user message, answer busy, and never throw a 500 into the SSE stream. Pinned by Task 7 `malformedStreamAnswersBusy`.

---

### Task 1: `search_products` reports sold-out ids

**Files:**
- Modify: `src/service/catalog/ProductService.php`: `search()`, both return paths (cache hit and direct). Replace `soldOutMatchCount` with `soldOutIds`.
- Test: `tests/integration/service/ProductServiceTest.php`

**Interfaces:**
- Produces: the `search_products` result has `out_of_stock_ids: list<int>` whenever there is no `error` (an empty array when nothing qualifies), in search rank order. When the array is non-empty, `out_of_stock_matches = count(out_of_stock_ids)`; otherwise that key is absent.

Rules (§8.1):
- `in_stock === true` and `products === []` → the ids of the products matching every other filter (`in_stock` removed). Run them through the same reranker as the main search, so the order matches the ranking.
- No `in_stock` and every returned product is sold out → the ids of the returned products, in their returned order.
- "Sold out" uses the same definition as `ProductRepository::search`'s in-stock clause: not (`stock > 0` or an active variant with `COALESCE(v.stock, p.stock) > 0`). Read the `stock` the search returns. If variants make a product count as in stock while its row stock is 0, compute in-stock from the hydrated variants (`CatalogVariantHydrator` output) the same way.

- [ ] **Step 1: Write the failing tests**

```php
public function testInStockSearchCountsSoldOutMatches(): void   // existing, extend
{
    $result = $this->products->search(['search' => 'quần baggy', 'in_stock' => true]);
    $this->assertSame([], $result['products']);
    $this->assertSame([69], $result['out_of_stock_ids']);
    $this->assertSame(1, $result['out_of_stock_matches']);
}

public function testUnfilteredSearchWithOnlySoldOutReportsIds(): void
{
    $all = $this->products->search(['search' => 'quần baggy']);
    $this->assertSame([69], array_column($all['products'], 'id'));
    $this->assertSame([69], $all['out_of_stock_ids']);
    $this->assertSame(1, $all['out_of_stock_matches']);
}

public function testSearchWithInStockResultsHasEmptySoldOutIds(): void   // replaces testSearchWithoutStockFilterOrWithResultsHasNoSoldOutCount
{
    $inStock = $this->products->search(['search' => 'áo thun', 'in_stock' => true]);
    $this->assertNotSame([], $inStock['products']);
    $this->assertSame([], $inStock['out_of_stock_ids']);
    $this->assertArrayNotHasKey('out_of_stock_matches', $inStock);
}
```

The spec (§8.1, second bullet) deliberately changes PR #23's assertion that an unfiltered baggy search has no sold-out key. Delete that half of the old test. Do not keep both.

- [ ] **Step 2: Run them and see them fail.** Run `scripts/dev/test-db.sh && scripts/dev/php vendor/bin/phpunit tests/integration/service/ProductServiceTest.php` (with the integration env from `.claude/rules/testing.md`). Expected: FAIL on `out_of_stock_ids` undefined.
- [ ] **Step 3: Implement.** Add `private function soldOutIds(array $args, array $result): array` and a single `withSoldOut(array $result, array $args): array` applied before both returns of `search()`. Do not change the cache payload (`Cache::setProductSearchIds`). Remove `soldOutMatchCount`.
- [ ] **Step 4: Run the file plus the Unit suite.** Run `scripts/dev/php vendor/bin/phpunit --testsuite=Unit`. Expected: PASS. Old `OutOfStockMatchesTest` still passes, because it reads `out_of_stock_matches`.
- [ ] **Step 5: Commit** `feat(catalog): search_products reports out_of_stock_ids in rank order`.

### Task 2: tool `find_similar_in_stock`

**Files:**
- Modify: `src/repository/ProductRepository.php`: new `similarInStock(int $productId, int $categoryId, array $styleTags, float $price, int $limit): array`.
- Modify: `src/service/catalog/ProductService.php`: new `similar(array $args): array`.
- Modify: `src/harness/tools/ToolApplicationService.php`: match arm `'find_similar_in_stock' => $this->products->similar($arguments)`.
- Modify: `src/harness/tools/ToolDefinitionCatalog.php`: 9th definition.
- Modify: `mcp-server/src/tools.ts`: `registerTool('find_similar_in_stock', …)`, `readOnly` annotations. Modify `mcp-server/src/shop-client.ts`: add it to `retryable`.
- Test: `tests/integration/service/ProductServiceTest.php`, `mcp-server/tests/contract.test.ts`

**Interfaces:**
- Input: `{product_id: int ≥ 1 (required), limit?: int 1..5 = 2}`. MCP zod: `product_id: z.number().int().positive()`, `limit: z.number().int().min(1).max(5).optional()`. Description: `Use this when a product the customer asked about is sold out, to find in-stock products of the same category and similar style.`
- Output: `{source: {id, name, category_id, style_tags: list<string>}, products: list<product>}`, where each product has the same fields and hydration as a `search_products` product. Unknown id → `{error: 'Product not found'}`.
- Selection (§8.1): in stock (same definition as Task 1), same `category_id`, `id != source`, at least 1 shared `style_tags` tag. Order: shared tag count desc, then `ABS(price - source.price)` asc, then id asc. Matching tags in SQL with `FIND_IN_SET` over the comma list, or in PHP after fetching candidates of the category, are both acceptable. SQLite parity is not required (integration only).

- [ ] **Step 1: Write the failing tests**

```php
public function testSimilarInStockForSoldOutBaggy(): void
{
    $r = $this->products->similar(['product_id' => 69]);
    $this->assertSame(69, $r['source']['id']);
    $this->assertSame('Quần Baggy Vải Tuyết Mưa', $r['source']['name']);
    $this->assertSame([73, 71], array_column($r['products'], 'id')); // 2 shared tags; |560k-490k| < |720k-490k|
}

public function testSimilarInStockLimitAndExclusions(): void
{
    $ids = array_column($this->products->similar(['product_id' => 69, 'limit' => 5])['products'], 'id');
    $this->assertSame([73, 71, 68, 70], $ids);   // 65 shares a tag but is sold out; 74 shares none
    $this->assertNotContains(69, $ids);
}

public function testSimilarInStockUnknownProduct(): void
{
    $this->assertSame('Product not found', $this->products->similar(['product_id' => 999999])['error']);
}
```

Contract test: change the expected list to the 9 names (sorted). Every one is `readOnlyHint === true`. Add `find_similar_in_stock` with `product_id: -1` → `/Input validation error/`.

- [ ] **Step 2: Run them and see them fail.** Run the PHP file, then `npm --prefix mcp-server test`. Expected: FAIL.
- [ ] **Step 3: Implement** the repository method, `ProductService::similar` (clamp `limit` to 1..5), the tool arm, the catalog definition and the MCP registration.
- [ ] **Step 4: Run** the PHP file, the Unit suite (`ArchitectureTest` included), `npm --prefix mcp-server test && npm --prefix mcp-server run build`, and PHPStan level 1. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(tools): find_similar_in_stock read-only tool (9 tools)`.

### Task 3: `chat-graph` scaffold: config, contract, copy, tools, test doubles

**Files:**
- Create: `chat-graph/package.json` (`"type": "module"`, scripts `build: tsc -p tsconfig.json`, `start: node dist/chat-graph/src/server.js`, `test: node --test --import tsx tests/*.test.ts`), `package-lock.json`, `tsconfig.json` (copy `mcp-server/tsconfig.json`, set `rootDir: ".."`, include `src/**/*.ts`, `tests/**/*.ts`, `eval/**/*.ts`, `../mcp-server/src/**/*.ts`).
- Create: `chat-graph/src/config.ts`, `src/contract.ts`, `src/copy.ts`, `src/shopTools.ts`.
- Create: `chat-graph/tests/support/scriptedModel.ts`, `tests/support/fakeTools.ts`, `tests/contract.test.ts`, `tests/copy.test.ts`.

**Interfaces (Produces):**
- `config.ts`: `export type GraphConfig = { port: number /*CHAT_GRAPH_PORT=8090*/; token: string /*CHAT_GRAPH_TOKEN, required, not 'change-me'*/; llm: { baseUrl: string; apiKey: string; model: string; timeoutMs: number /*LLM_TIMEOUT s ×1000, default 25000*/ }; budget: { maxToolCalls: number; maxModelCalls: number; turnTimeoutMs: number }; freeship: { threshold: number /*FREE_SHIPPING_THRESHOLD=500000*/; maxGap: number /*FREESHIP_NUDGE_MAX_GAP=200000*/ }; langfuse: { enabled: boolean; baseUrl: string; publicKey: string; secretKey: string } }` and `export function loadConfig(env = process.env): GraphConfig`. This is the only file that reads `process.env`. `mcp-server/src/config.ts` keeps reading its own two variables.
- `contract.ts`: types `HistoryMessage {role: 'user'|'assistant'; content: string}`, `MemoryContext {summary: string; slots: Record<string, string|number>; profile: Record<string, unknown>|null}`, `TurnRequest {message; user_id: number|null; session_id: number; history: HistoryMessage[]; memory: MemoryContext; freeship_fingerprint: string|null; stream: boolean}`, `Card {id; name; price; stock; image: string|null; image_url: string|null; url: string /* '/product.php?id='+id */; category_id: number|null; available_sizes: string[]; available_colors: string[]; outfit_index?: number}`, `ResponseType = 'final_answer'|'clarification'|'refusal'|'busy'`, `Offered {id: number; name: string}`, `TurnSummary {response_type; primary_intent: string; tools_used: string[]; outfits: unknown[]; offered: Offered[]; freeship_nudge: boolean; freeship_fingerprint: string|null; trace_id: string /*32 lowercase hex*/; latency: Record<string, number|boolean>}`, `TurnResult = TurnSummary & {message: string; products: Card[]}`, `TurnEvent = {type:'progress'; stage: string} | {type:'delta'; text: string} | {type:'cards'; products: Card[]} | ({type:'complete'} & TurnSummary)`, `ToolRecord {name: string; args: Record<string, unknown>; result: Record<string, unknown>}`. Also `export function parseTurnRequest(body: unknown): TurnRequest`, a zod schema that throws `RequestError` (exported class) on bad input. `message` is 1..2000 chars, `history` has at most 6 items.
- `copy.ts`: `BUSY`, `checkoutRefusal(message)`, `soldOutNote(sourceName)`, `askAlternatives(sourceName, alts: Offered[], afterOtherAnswer: boolean)`, `soldOutNoAlternatives(count, search)`, `nudgeText(gap, threshold, product)`, `money(v)`, all with the exact Global Constraints copy. Also `PRIMARY_INTENT: Record<string,string>`, pinned as: `search_products→product_search`, `find_similar_in_stock→product_search`, `get_categories→product_search`, `get_product_detail→product_detail`, `build_outfit→build_outfit`, `suggest_freeship_items→freeship_gap`, `suggest_size→size_advice`, `retrieve_knowledge→policy`, `get_order_status→order_status`. No tool → `unknown`. The primary intent is the first tool used.
- `shopTools.ts`: `export async function openShopTools(userId: number|null): Promise<{ tools: StructuredToolInterface[]; call(name: string, args: Record<string, unknown>): Promise<Record<string, unknown>>; close(): Promise<void> }>`. It builds `createMcpServer({userId, mode:'service', scopes:['shop.read']})` imported from `../../mcp-server/src/tools.js`, links it with `InMemoryTransport.createLinkedPair()` to an MCP `Client`, and loads LangChain tools with `@langchain/mcp-adapters` (`loadMcpTools`, or whatever the installed version exports; check the `.d.ts`). `call` parses the tool's `structuredContent.data`, which the policies and graph nodes use to call tools outside the LLM. Also `export type ShopTools = Awaited<ReturnType<typeof openShopTools>>`.
- `tests/support/scriptedModel.ts`: `class ScriptedChatModel extends BaseChatModel`. Its constructor takes `steps: Array<AIMessage | Error | 'hang'>`. Each `_generate` returns the next step (`'hang'` never resolves). `bindTools()` returns `this`, and it records every call's messages in `.calls`. Do not rely on `FakeListChatModel`: it cannot emit tool calls.
- `tests/support/fakeTools.ts`: `fakeShopTools(handlers: Record<string, (args) => Record<string, unknown>>)`, with the same shape as `openShopTools`'s return. Every call is recorded.

- [ ] **Step 1: Install.** `cd chat-graph && npm install --save-exact langchain@1 @langchain/core@1 @langchain/langgraph@1 @langchain/openai@1 @langchain/mcp-adapters@1 @modelcontextprotocol/sdk@1.30.0 zod@4.4.3` and `npm install --save-exact -D typescript@5.9.3 tsx@4.21.0 @types/node@24.10.1`. Record the resolved versions in the commit message.
- [ ] **Step 2: Write the failing tests.** `contract.test.ts`: a valid body parses; `message: ''`, 7 history items, and `user_id: 'x'` each throw `RequestError`. `copy.test.ts`: `money(490000) === '490.000đ'`; `checkoutRefusal('thêm   vào giỏ giúp mình')` equals the exact refusal with `"thêm vào giỏ giúp mình", `; a 130-char message is quoted as 117 chars + `…`; `askAlternatives('Quần Baggy Vải Tuyết Mưa', [{id:73,name:'Quần Kaki Ống Rộng Màu Xanh Rêu'},{id:71,name:'Quần Bò Rách Gối Phong Cách'}], false)` equals `Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng Màu Xanh Rêu hoặc Quần Bò Rách Gối Phong Cách không?`; with `true` it starts with `Còn mẫu `; with one alternative there is no ` hoặc `. A test also calls `openShopTools(null)`: the tool names are exactly the 9 Global Constraints names. No network is needed, because listing tools calls no shop API.
- [ ] **Step 3: Run `npm --prefix chat-graph test`.** Expected: FAIL (modules missing).
- [ ] **Step 4: Implement** the four `src` files and the two support files.
- [ ] **Step 5: Run `npm --prefix chat-graph test && npm --prefix chat-graph run build`.** Expected: PASS, and `dist/chat-graph/src/server.js` is not needed yet.
- [ ] **Step 6: Commit** `feat(chat-graph): scaffold, turn contract, copy, MCP tools in-process`.

### Task 4: pure policies

**Files:**
- Create: `chat-graph/src/policies/checkout.ts`, `allowList.ts`, `budget.ts`, `cards.ts`, `grounding.ts`.
- Test: `chat-graph/tests/policies.test.ts`

**Interfaces (Produces):**
- `checkout.ts`: `matchesCheckout(message: string): boolean`. It is the regex of `src/harness/intent/CheckoutDetector.php`, copied verbatim with the flags `iu`, over `message.toLowerCase()`.
- `allowList.ts`: `ALLOWED_TOOLS: readonly string[]` (the 9 names) and `isAllowedTool(name): boolean`.
- `budget.ts`: `class TurnBudget { constructor(l: GraphConfig['budget'], now = Date.now); takeToolCall(): boolean; takeModelCall(): boolean; remainingMs(): number; expired(): boolean; counts(): {tool_calls: number; model_calls: number} }`. `take*` returns false once the limit is reached and does not increment.
- `cards.ts`:
  - `productToCard(p: Record<string, unknown>, outfitIndex?: number): Card`.
  - `verifyCard(card: Card, args: Record<string, unknown>): boolean`. Rules: `args.in_stock===true → card.stock>0`; `min_price`/`max_price` bound `card.price`; `category_id` must be equal when given.
  - `selectCards(answer: string, records: ToolRecord[]): Card[]`, following §5.3:
    1. Ids mentioned as `mã N` (regex `/mã\s*(\d+)/giu`), in order of first mention, that exist in any record's products. Products are found at `result.products[]`, `result.product`, `result.outfits[].items[]`, or the freeship `result.products`.
    2. Otherwise the products of the last record whose tool is a product tool (`search_products`, `get_product_detail`, `find_similar_in_stock`, `build_outfit`).
    3. Cap at 6, dedupe by id, and drop the cards that fail `verifyCard` against the args of the record they came from.

    Records of `find_similar_in_stock` reached through the sold-out branch are excluded by the caller, not here.
  - `outfitsFrom(records): unknown[]`: the last `build_outfit` record's `result.outfits`, unchanged.
- `grounding.ts`:
  - `ungroundedClaims(answer: string, records: ToolRecord[]): string[]`. A claim is a `mã N` whose N is not a product id in the records, or a money token whose value is not any number found anywhere in the records' results (a deep walk over all numeric leaves, compared after `Math.round`).
  - Money token regex: `/(\d{1,3}(?:[.,]\d{3})+|\d+)\s*(k|nghìn|đ|₫|vnđ|vnd)(?![\p{L}])/giu`. Strip the separators. `k`/`nghìn` means ×1000.
  - `summaryFromCards(cards: Card[]): string` = `Mình tìm được các mẫu sau: ` + `{name} (mã {id}) giá {money(price)}` joined with `; ` + `. Bạn bấm vào thẻ sản phẩm để xem chi tiết nhé.`. Empty cards → `BUSY`.

- [ ] **Step 1: Write the failing tests** in `policies.test.ts`:
  - `checkoutMatchesLegacyPhrases`: true for `thêm vào giỏ`, `thêm áo này vào giỏ`, `thanh toán giúp mình`, `chốt đơn`, `đặt hàng giúp`; false for `áo phông còn hàng`, `phí ship bao nhiêu`.
  - `allowListHasNineReadOnlyTools`: `ALLOWED_TOOLS.length === 9`; `isAllowedTool('add_to_cart') === false`.
  - `budgetStopsAtLimits`: with `maxToolCalls: 4`, the 5th `takeToolCall()` is false and `counts().tool_calls === 4`. An injected clock past `turnTimeoutMs` makes `expired()` true.
  - `selectCardsPrefersMentionedIds`: records hold ids 66, 72, 67; the answer mentions `mã 67` then `mã 66` → ids `[67, 66]`.
  - `selectCardsFallsBackToLastProductTool`: no mention → the products of the last product record, max 6.
  - `selectCardsDropsCardsFailingTheirArgs`: in a record with `in_stock: true`, a product with stock 0 is dropped.
  - `groundingFlagsInventedIdAndPrice`: the answer `mã 999 giá 123.000đ` against records without them → 2 claims.
  - `groundingAcceptsPriceSpellings`: a record price `490000` with answers `490.000đ`, `490k`, `490.000 đ` and `490,000 VND` → 0 claims each.
  - `summaryFromCardsCopy`: the exact string for one card `{id:73, name:'Quần Kaki Ống Rộng Màu Xanh Rêu', price:560000}`.
- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement** the five modules. Pure functions only: no LangChain imports.
- [ ] **Step 4: Run** `npm --prefix chat-graph test`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(chat-graph): policy rules (checkout, allow-list, budget, cards, grounding)`.

### Task 5: the graph

**Files:**
- Create: `chat-graph/src/graph/prompt.ts`, `src/graph/middleware.ts`, `src/graph/soldOut.ts`, `src/graph/buildGraph.ts`.
- Test: `chat-graph/tests/graph.test.ts`

**Interfaces:**
- Consumes: Tasks 3–4.
- Produces: `export function buildTurnGraph(deps: { model: BaseChatModel; tools: ShopTools /* return type of openShopTools */; budget: TurnBudget; config: GraphConfig; onProgress?: (stage: string) => void }): { invoke(req: TurnRequest): Promise<GraphOutcome> }`, where `GraphOutcome = { message: string; response_type: ResponseType; cards: Card[]; outfits: unknown[]; offered: Offered[]; tools_used: string[]; freeship_nudge: boolean; freeship_fingerprint: string|null }`.
- `prompt.ts`: `systemPrompt(req: TurnRequest): string`. It must contain:
  - Vietnamese, shop tools only, never order or pay on the customer's behalf;
  - mention products as `mã N`;
  - pass `in_stock: true` when the customer asks "còn hàng";
  - the memory block titled `Thông tin đã biết về khách (có thể đã cũ)` (slots, summary and profile as compact JSON), plus the line `Câu hiện tại của khách luôn được ưu tiên hơn thông tin đã biết.`
- `soldOut.ts`: `soldOutFrom(record: ToolRecord): {ids: number[]; search: string} | null` (non-null when `name==='search_products'` and `out_of_stock_ids` is non-empty), and `type SoldOutState = {source: {id; name}; alternatives: Offered[]; count: number; search: string}`.

Graph shape. This is a LangGraph `StateGraph` with an `Annotation` state. Verify the API names against the installed `@langchain/langgraph` typings.

```
START → guard ──(refused)──────────────────────────────────▶ END
          └──▶ agent → policy_out ──(state.soldOut)──▶ find_alternatives → ask_alternatives → END
                             └──(else)──▶ nudge → END
```

- **`guard` node:** `matchesCheckout(req.message)` → `{message: checkoutRefusal(..), response_type: 'refusal'}`. No model or tool call.
- **`agent` node:** `createAgent({ model, tools: deps.tools.tools, systemPrompt, middleware })` from `langchain`, invoked with `history` + the current message as messages. The `middleware` is built in `middleware.ts` with `createMiddleware`:
  - `wrapModelCall`: `budget.takeModelCall()` false or `budget.expired()` → stop the agent (the hook's jump-to-end mechanism, or a thrown `BudgetExceeded` caught by the node).
  - `wrapToolCall`:
    - Not allowed (`isAllowedTool`) or `!budget.takeToolCall()` → return a ToolMessage `Công cụ không được phép hoặc đã hết lượt gọi.` without running it.
    - Otherwise run it and push a `ToolRecord` (parsed `structuredContent.data`) into the turn's recorder, then call `onProgress('tool:'+name)`.
    - If `soldOutFrom(record)` is non-null and the recorder has no sold-out yet, call `deps.tools.call('find_similar_in_stock', {product_id: ids[0], limit: 2})`. This call does not count against the budget. Store a `SoldOutState`; on a tool error, use `alternatives: []` and `source.name` from the search result's matching product, else `search`. Return a ToolMessage whose content is `soldOutNote(source.name)`.

  The agent's final AI text is the node's `draft`.
- **`policy_out` node:**
  - Compute `ungroundedClaims(draft, records)`.
  - If there are claims and `budget.takeModelCall()` succeeds, call `model.invoke` once without tools, on the same messages plus a HumanMessage `Câu trả lời có thông tin không có trong kết quả công cụ: {claims joined ', '}. Viết lại, chỉ dùng dữ liệu từ công cụ.`, and re-check.
  - Still ungrounded → `summaryFromCards(selectCards('', recordsWithoutSoldOutBranch))`.
  - Then `cards = selectCards(text, recordsWithoutSoldOutBranch)` and `outfits = outfitsFrom(records)`.
  - An empty draft with no sold-out state and no records → `BUSY`, with `response_type: 'busy'`.
- **`find_alternatives` node:** reads the `SoldOutState` captured by `wrapToolCall`. The edge into it is a conditional edge on `state.soldOut`. This node is where §5.2's branch lives. No LLM call.
- **`ask_alternatives` node:**
  - `alternatives.length > 0` → `askAlternatives(source.name, alternatives, text !== '')`, appended to `text`.
  - Otherwise `soldOutNoAlternatives(count, search)`, also appended.
  - Set `response_type: 'clarification'` and `offered = alternatives`. Keep the cards of the other parts. Never add cards for the source or the alternatives.
- **`nudge` node:**
  - Skip when `user_id === null`, `response_type !== 'final_answer'`, or `tools_used` has none of `search_products, get_product_detail, build_outfit, find_similar_in_stock, get_order_status`.
  - Otherwise `deps.tools.call('suggest_freeship_items', {limit: 1})`, which is not counted against the budget. It is eligible when `status === 'gap'`, `products` is non-empty, `0 < gap <= config.freeship.maxGap` and `cart_fingerprint !== req.freeship_fingerprint`.
  - When eligible, append `' ' + nudgeText(...)`, add that product's card if it is missing, and set `freeship_nudge: true`, `freeship_fingerprint: cart_fingerprint`. Any error → no nudge, and the turn is unchanged.

- [ ] **Step 1: Write the failing tests** in `graph.test.ts`, using `ScriptedChatModel` and `fakeShopTools`:
  - `checkoutRefusedWithoutModelOrTools`: `thêm áo này vào giỏ` → `response_type 'refusal'`, `model.calls.length === 0`, no tool calls.
  - `soldOutBranchAsksAboutAlternatives`:
    - The script calls `search_products {search:'quần baggy', in_stock:true}` and returns `{products: [], out_of_stock_ids: [69], out_of_stock_matches: 1}`; `find_similar_in_stock` returns source 69 plus 73 and 71; the model's last message is `''`.
    - `message` = the exact Task 3 alone-copy; `response_type 'clarification'`; `cards` = []; `offered` ids = [73, 71]; `find_similar_in_stock` called with `{product_id: 69, limit: 2}`.
    - The ToolMessage the model saw contains `soldOutNote('Quần Baggy Vải Tuyết Mưa')`, and none of the raw search JSON.
  - `soldOutFiresWithoutInStockArg`: the same, but the search args lack `in_stock` and the result is `{products: [{id: 69, stock: 0, …}], out_of_stock_ids: [69]}` → still a clarification.
  - `multiIntentAnswersOtherPartThenAsks`: the model calls the search (sold out) and `suggest_size` in one AIMessage, then answers `Với 160cm, 50kg bạn mặc size S.` → `message` = that sentence + ` Còn mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem … không?`.
  - `soldOutWithoutAlternatives`: `find_similar_in_stock` returns `products: []` → `Shop có 1 mẫu quần baggy nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.`
  - `toolBudgetStopsFifthCall`: the script asks for 5 tool calls → the 5th gets the refusal ToolMessage and the fake tool records exactly 4 calls.
  - `disallowedToolIsNotRun`: the model asks for `add_to_cart` → not run, and the ToolMessage is the refusal text.
  - `ungroundedAnswerIsRetriedThenSummarized`: answer `mã 999` twice → `message` = `summaryFromCards` of the search cards, and `model.calls.length` = agent calls + 1 retry.
  - `cardsComeFromToolResultsOnly`: the answer mentions `mã 66`, which exists in the search result → `cards[0].price` equals the tool's price, even though the answer text says a different price, which was rewritten by the retry.
  - `noNudgeForGuest`: `user_id: null`, a search turn → `suggest_freeship_items` is never called and `freeship_nudge === false`.
  - `nudgeOncePerFingerprint`: `user_id: 5`, freeship returns `{status:'gap', gap: 50000, threshold: 500000, cart_fingerprint:'abc', products:[{id:90,name:'Túi Xách Mini Thời Trang',price:450000, stock: 4}]}`. With `freeship_fingerprint: null` → the nudge text is appended and `freeship_fingerprint === 'abc'`. With `'abc'` → no nudge.
  - `memoryBlockInPrompt`: `memory.slots = {height_cm: 160}` → the first model call's system message contains `Thông tin đã biết về khách (có thể đã cũ)` and `160`.
- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement** the four files. Where a LangChain/LangGraph hook name or return type differs from this text, follow the installed typings and keep the behavior the tests pin.
- [ ] **Step 4: Run** `npm --prefix chat-graph test && npm --prefix chat-graph run build`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(chat-graph): LangGraph turn graph with policy middleware and sold-out branch`.

### Task 6: `runTurn` + HTTP server

**Files:**
- Create: `chat-graph/src/model.ts`, `src/turn.ts`, `src/server.ts`, `src/tracing.ts`.
- Test: `chat-graph/tests/turn.test.ts`, `tests/server.test.ts`

**Interfaces:**
- `model.ts`: `createChatModel(c: GraphConfig['llm']): BaseChatModel`. Use `new ChatOpenAI({ model, apiKey, temperature: 0, timeout: timeoutMs, maxRetries: 0, configuration: { baseURL } })`.
- `turn.ts`: `export async function runTurn(req: TurnRequest, deps: { config: GraphConfig; model: BaseChatModel; openTools: (userId: number|null) => Promise<ShopTools> }, emit: (e: TurnEvent) => void): Promise<TurnResult>`.
  - Create a `TurnBudget` and race `buildTurnGraph(...).invoke(req)` against `budget.turnTimeoutMs`.
  - Any throw, timeout or model error → the busy result: `message: BUSY`, `response_type: 'busy'`, no cards, `primary_intent: 'unknown'`.
  - Always `close()` the tools.
  - Emits:
    - `progress` for each tool.
    - The final text as `delta` events, chunked by sentence (split after `.`, `?`, `!` followed by a space). It is emitted only after the graph returns.
    - Then `cards` (when non-empty).
    - Then `complete` with `primary_intent = PRIMARY_INTENT[tools_used[0]] ?? 'unknown'`, `'unsupported_checkout'` for refusals, a fresh random 32-hex `trace_id`, and `latency = {total_ms, tool_calls, model_calls}`.
- `server.ts`: `node:http`.
  - `GET /health` → 200 `{ok:true}`.
  - `POST /turn`:
    - Missing or wrong `X-CHAT-GRAPH-TOKEN` → 401 (`timingSafeEqual` on equal-length buffers; a different length is a mismatch). Bad body → 400 `{error}`.
    - `stream: true` → 200 `application/x-ndjson`, one JSON event per line.
    - `stream: false` → 200 `application/json` `TurnResult`.
  - Any other path → 404. `export function createServer(deps): http.Server`. Only `main` (guarded by `import.meta.url === pathToFileURL(process.argv[1]).href`) calls `loadConfig` and `listen`.
- `tracing.ts`: `langfuseCallbacks(c: GraphConfig['langfuse']): unknown[]`. Returns `[]` unless enabled with both keys. When enabled, it is the Langfuse LangChain callback handler; check the current package name on npm, e.g. `@langfuse/langchain`, pin it exact, and pass the callbacks to the agent invoke. A failure to build the handler returns `[]` and logs once to stderr.

- [ ] **Step 1: Write the failing tests:**
  - `turnStreamsAfterGraphAndEndsWithComplete`: with a scripted search turn, the events are `progress`… then `delta`… then `cards` then `complete`. Concatenated `delta` texts equal `TurnResult.message`. No `delta` arrives before the last `progress`.
  - `modelErrorAnswersBusy`: the script `[new Error('boom')]` → `message === BUSY`, `response_type 'busy'`.
  - `turnDeadlineAnswersBusy`: script `['hang']`, `turnTimeoutMs: 50` → resolves busy in < 1 s, and `close()` was called.
  - `serverRejectsBadToken`: a request with token `x` → 401; without the header → 401.
  - `serverRejectsBadBody`: `{message: ''}` → 400.
  - `serverNonStreamReturnsJson`: `stream: false` → 200 and the JSON has the `message`, `products`, `response_type`, `trace_id` keys.
  - `healthOk`.

  Server tests listen on port 0 with injected deps, using the fake model and tools.
- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm --prefix chat-graph test && npm --prefix chat-graph run build`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(chat-graph): runTurn with deadline/busy, NDJSON HTTP server, optional Langfuse`.

### Task 7: PHP calls `chat-graph`

**Files:**
- Create: `src/agent/ChatGraphClient.php`, `src/agent/ChatGraphUnavailable.php`, `src/agent/ChatMemoryContext.php`, `src/agent/ChatHistoryWindow.php`.
- Modify: `src/agent/HarnessConfig.php`, adding `chatGraphUrl` (`CHAT_GRAPH_URL`, default `http://chat-graph:8090`), `chatGraphToken` (`CHAT_GRAPH_TOKEN`) and `chatGraphTimeout` (`CHAT_GRAPH_TIMEOUT`, default 50).
- Modify: `src/agent/ChatbotService.php`. `respond` and `respondStreaming` keep their signatures and now go through the client. `open`, `sessionToken`, `sessionId`, `touchSession` and `errorCategory` are unchanged. A new constructor parameter `?ChatGraphClient $graph = null` is used for tests.
- Modify: `src/harness/memory/ChatbotMemory.php`, adding `public function rememberSlot(string $key, string $value): void` (the same upsert as `FreeShippingNudge::remember`).
- Modify: `src/app/api/controllers/Chatbot/index.php`, `stream.php`: the response keys (spec §5.3).
- Modify: `.env.example`, adding `CHAT_GRAPH_TOKEN=change-me`, `CHAT_GRAPH_URL=http://chat-graph:8090`.
- Test: `tests/unit/agent/ChatGraphClientTest.php`, `ChatMemoryContextTest.php`, `ChatHistoryWindowTest.php`, `ChatbotServiceTest.php` (rewrite). Delete `StreamWithoutLlmTest.php` and `tests/integration/AgentAssemblyTest.php`, which assert the old pipeline through the facade. Tests of the harness components stay.

**Interfaces:**
- `ChatGraphClient::__construct(string $url, string $token, int $timeoutSeconds, ?Closure $transport = null)`. `$transport(string $url, array $headers, string $body, int $timeout, callable $onChunk): int` returns the HTTP status. The default is curl with `CURLOPT_WRITEFUNCTION`, `CURLOPT_CONNECTTIMEOUT=2`, `CURLOPT_TIMEOUT=$timeoutSeconds`.
- `ChatGraphClient::turn(array $request, ?callable $onEvent = null): array` returns the `TurnResult` array. Streaming reads NDJSON, calls `$onEvent($event)` per line, and builds the result from `delta` + `cards` + `complete`. Throws `ChatGraphUnavailable` on a transport error, a timeout, a non-200 status, malformed JSON, or a stream without `complete`.
- `ChatMemoryContext::build(array $memory, ?int $userId): array{summary:string, slots:array, profile:?array}`, where `$memory` is the return of `ChatbotMemory::rememberUserMessage`.
  - `ALLOWED_SLOTS = ['size','height_cm','weight_kg','budget','min_price','max_price','gender']`, non-null values only.
  - `profile`: null for guests. Otherwise `{usual_size, favorite_color, favorite_style, favorite_texture, avoid_material, avoid_style, recent_purchases}`, where `recent_purchases` lists the `product` names of the last 5 `purchase_history` entries. Null and empty values are dropped.
- `ChatHistoryWindow::fromRows(array $rows, int $limit = 6): list<array{role:string,content:string}>`. `$rows` is `ChatConversationRepository::messagesForSession` output. Take the last `$limit` rows; `bot` maps to `assistant`. For an assistant row, append `"\n[Thẻ đã hiện: mã {id} {name}; …]"` when `metadata.products` is non-empty, and `"\n[Đã gợi ý: mã {id} {name}; …]"` when `metadata.offered` is non-empty.
- `ChatbotService::respond*` flow:
  1. `$memory = ChatbotMemory->rememberUserMessage($message)`.
  2. Build the request: `history` from the window, read **before** saving this turn; `memory`; `freeship_fingerprint` from `slots['freeship_nudged_fingerprint']`; `stream` set for `respondStreaming`.
  3. Call `$client->turn`. In streaming mode, forward `delta` to `$onDelta` and `progress` to nothing.
  4. On `ChatGraphUnavailable`: log `{operation:'chat_graph', success:false, error_category}`, then answer busy (`message = 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.'`, `response_type 'busy'`, no products). When streaming and no delta has been sent yet, emit the busy text as one delta.
  5. `PdoChatbotConversationStore::saveMessages($message, $result['message'], $cards, [], [], ['response_type'=>…, 'tools_used'=>…, 'offered'=>…])`. Save `offered` as metadata key `offered`, not in `products`, so history never renders it as cards.
  6. `ChatbotMemory->refreshSummary()`.
  7. When `freeship_fingerprint` changed and is non-null, call `rememberSlot('freeship_nudged_fingerprint', …)`.

  It returns `['message','answer','products','cards','response_type','primary_intent','outfits','freeship_nudge','trace_id','latency']`.
- `index.php` returns `message, answer, products, cards, session_token, session_id, response_type, primary_intent, outfits, freeship_nudge, trace_id, latency`. `stream.php` emits `chat.cards` from `products` and `chat.complete` with `session_token, session_id, response_type, primary_intent, trace_id, outfits, freeship_nudge, product_count, latency`. Drop `freeship_status`.

- [ ] **Step 1: Write the failing tests:**
  - `ChatGraphClientTest`:
    - `streamsDeltasAndBuildsResult`: a fake transport feeds NDJSON split mid-line across chunks → `onEvent` sees the deltas in order and the result `message` is their concatenation.
    - `non200Throws`: status 502 throws `ChatGraphUnavailable`.
    - `malformedStreamAnswersBusy`: garbage, or no `complete`, throws `ChatGraphUnavailable`.
    - `sendsToken`: the headers include `X-CHAT-GRAPH-TOKEN: t`.
  - `ChatMemoryContextTest`:
    - `forbiddenFieldsNeverSent`: feed every forbidden field from the Global Constraints, set → none appears anywhere in `json_encode(build(...))`.
    - `guestHasNoProfile`.
    - `keepsAllowedSlots`: `height_cm 160` is kept, `product_type 'quần'` is dropped.
  - `ChatHistoryWindowTest`: 8 rows give 6 items; a bot row with `metadata.products [{id:73,name:'Quần Kaki Ống Rộng Màu Xanh Rêu'}]` ends with `[Thẻ đã hiện: mã 73 Quần Kaki Ống Rộng Màu Xanh Rêu]`; the `offered` line likewise.
  - `ChatbotServiceTest` (SQLite fixture, fake client):
    - `busyWhenGraphDown`: the client throws → `response_type 'busy'`, and both user and bot messages are saved.
    - `offeredSavedOutsideProducts`: the stored metadata has `offered` and no `products`.
    - `fingerprintRemembered`.
    - `historyExcludesCurrentTurn`.
- [ ] **Step 2: Run them and see them fail.** Run `scripts/dev/php vendor/bin/phpunit --filter 'ChatGraphClient|ChatMemoryContext|ChatHistoryWindow|ChatbotService'`.
- [ ] **Step 3: Implement**, then run `scripts/dev/composer dump-autoload`.
- [ ] **Step 4: Run the Unit suite + PHPStan.** Expected: PASS. `ArchitectureTest` may need no change: `agent` may use `harness` and `repository`. Fix the code, not the test.
- [ ] **Step 5: Commit** `feat(agent): ChatbotService talks to chat-graph; whitelisted memory, busy fallback`.

### Task 8: Compose, Docker, CI, gate, docs

**Files:**
- Create: `chat-graph/Dockerfile`. Build context is the repo root. The stages are `node:22-alpine` (same digest as the root `Dockerfile`) → `npm ci` in `chat-graph` and in `mcp-server` → `npm run build` → runtime with `dist` + prod `node_modules`, `USER node`, `CMD ["node","dist/chat-graph/src/server.js"]`, and a `HEALTHCHECK` with `wget -qO- http://127.0.0.1:8090/health`.
- Modify: `docker-compose.yml`:
  - New service `chat-graph`:
    - `image: ${CHAT_GRAPH_IMAGE:-shop_quan_ao-chat-graph:latest}`, `env_file: .env`, `expose: ["8090"]`, `restart: unless-stopped`, `depends_on: app: service_healthy`.
    - Environment: `CHAT_GRAPH_PORT: 8090`, `CHAT_GRAPH_TOKEN: ${CHAT_GRAPH_TOKEN:-local-dev-chat-graph-secret-change-in-production}`, the `LLM_*` lines moved from `app`, `MCP_SERVICE_TOKEN` as in `app`, `SHOP_INTERNAL_URL: http://app:8080/api/internal/mcp`, `MCP_REQUEST_TIMEOUT_MS: 30000`, `FREE_SHIPPING_THRESHOLD` / `FREESHIP_NUDGE_MAX_GAP` as in `app`, `CHAT_GRAPH_MAX_TOOL_CALLS: ${…:-4}`, `CHAT_GRAPH_MAX_MODEL_CALLS: ${…:-5}`, `CHAT_GRAPH_TURN_TIMEOUT_MS: ${…:-45000}`, and the `LANGFUSE_*` keys as in `app`.
  - In `app`: add `CHAT_GRAPH_URL: http://chat-graph:8090`, `CHAT_GRAPH_TOKEN` (same default) and `CHAT_GRAPH_TIMEOUT: 50`. Keep the `LLM_*` lines in `app` until Task 10, because the old pipeline's tests read them.
  - Langfuse stays out of the default dependency graph.
- Modify: `.github/workflows/ci.yml`:
  - In Code Quality, the step `MCP contract tests and build` also runs `npm --prefix chat-graph ci && npm --prefix chat-graph test && npm --prefix chat-graph run build`.
  - Replace the step `Agent eval (UC-A outfits, …)` with nothing; the graph suite above is the CI eval (§9.1).
  - In Docker Build, add `Build chat-graph image` + scan, following the reranker steps.
- Modify: `scripts/dev/gate.sh`: replace the `agent eval (40 cases)` step with `npm --prefix chat-graph test`.
- Delete: `eval/` (cases + runner + `__pycache__`) and `scripts/run_agent_eval.php` (§9.2: the old eval is removed).
- Modify docs:
  - `CLAUDE.md`: the project line, the Commands (chat-graph test/build, live eval), the Chatbot architecture block, and the Hard rules line about tool selection. The new wording: "The LLM picks tools, only from the 9 read-only shop tools; PHP is the only place with SQL; the `chat-graph` policy middleware enforces the hard rules".
  - `.claude/rules/architecture.md`: the services table (`chat-graph`), the pipeline section, and the streaming boundary.
  - `.claude/rules/testing.md`, `.claude/rules/deployment.md`.
  - `.claude/rules/eval.md`, rewritten for `chat-graph/eval` (Task 9).

- [ ] **Step 1: Make the changes.**
- [ ] **Step 2: Verify:**
  - `docker compose config --quiet` → exit 0.
  - `docker compose build chat-graph` → success.
  - `NGINX_HTTP_PORT=8092 docker compose up -d chat-graph` (never `down`, never `--remove-orphans`).
  - `docker compose exec -T chat-graph wget -qO- http://127.0.0.1:8090/health` → `{"ok":true}`.
  - `scripts/dev/gate.sh` (with `SKIP_SNAPSHOT=1` if the baseline is stale) → `GATE PASSED`.
- [ ] **Step 3: Manual end-to-end.** Run `docker compose up -d --build app chat-graph`, then `curl -sN -X POST http://localhost:${NGINX_HTTP_PORT:-80}/api/chatbot/stream -H 'Content-Type: application/json' -H "Origin: http://localhost:${NGINX_HTTP_PORT:-80}" -d '{"message":"thêm áo này vào giỏ"}'`. Expected: `chat.delta` carries the refusal and `chat.complete` has `response_type":"refusal"`, with no LLM needed. A non-checkout message with the LLM down → the busy text. Record both outputs in the task report.
- [ ] **Step 4: Commit** `build(chat-graph): compose service, image, CI, gate; drop the PHP eval; docs`.

### Task 9: live eval suite (LLM real, not CI)

**Files:**
- Create: `chat-graph/eval/cases.jsonl`, `chat-graph/eval/run.ts`, an npm script `"eval": "tsx eval/run.ts"`.
- Modify: `.claude/rules/eval.md` (written in Task 8; fill in the case table).

**Interfaces:**
- Case line: `{"id", "group", "user": bool, "cart": [{product_id, quantity, size}]?, "turns": [{"message", "expect": {...}}]}`. The `expect` keys, all optional:
  - `response_type`;
  - `tools_include` (list), `no_tools` (bool);
  - `products_include` (ids), `min_products`, `max_products`;
  - `offered_include` (ids);
  - `message_contains` (list, all must appear), `message_not_contains`;
  - `nudge` (bool).
- `run.ts`:
  - Sets up users and carts on `shop_test` through PHP: it calls `docker compose exec -T app php -r` with a small script from `chat-graph/eval/fixtures.php` (create this) that inserts a test user and cart rows. Graph code never runs SQL directly.
  - Sends each turn through `POST http://localhost:${NGINX_HTTP_PORT:-80}/api/chatbot`, the whole stack, with the session token carried between turns.
  - Scores every expectation and writes `reports/eval/chat_graph_eval.json` with `{passed, failed, rate, by_group, cases[{id, failures, turns[{message, answer, response_type, product_ids, tools}]}]}`.
  - Exit code 0 iff `rate >= 0.9`.
- Cases (≥ 40):
  - The five §1 messages:
    - `quần baggy còn hàng` → `response_type: clarification`, `offered_include: [73]`, `message_contains: ['Quần Baggy Vải Tuyết Mưa', 'hết hàng']`;
    - `váy hai dây còn hàng` → `products_include: [83]` when 83 is in stock in `shop_test`, else the clarification;
    - `mũ còn hàng` → `products_include: [91]` or the clarification;
    - `khăn choàng còn hàng` → likewise with 94;
    - the multi-intent message → `tools_include: ['build_outfit', 'suggest_size']`.
  - Groups carried over from the old suite, rewritten for the new expect keys: `outfit` 10, `freeship` 6, `guardrail` 8 (these also run with no LLM), `unrelated` 5.
  - New `multi_intent` 4 and `sold_out` 3.

- [ ] **Step 1: Write the cases + runner.** Unit-test the scorer with `chat-graph/tests/evalScorer.test.ts`: each expect key passes and fails once on a hand-made turn.
- [ ] **Step 2: Run `npm --prefix chat-graph test`.** Expected: PASS.
- [ ] **Step 3: Live run, once `LLM_BASE_URL` answers.** First check with `docker compose exec -T chat-graph wget -qO- --timeout=8 "$LLM_BASE_URL/models"`, which must return HTTP 200 (without printing the key). Then run `npm --prefix chat-graph run eval`. Expected: `rate >= 0.9`.
  - If it is lower, fix code or prompt (suspect the code first; `.claude/rules/eval.md` principle), not expectations.
  - If the LLM is still unreachable, STOP the plan here and report. Task 10 waits.
- [ ] **Step 4: Commit** `test(chat-graph): live eval suite (≥ 90% gate, manual)`. The report stays out of git.

### Task 10: delete the old pipeline (separate final commit, only after Task 9 ≥ 90%)

**Files:**
- Move: `src/harness/memory/ChatbotMemory.php` → `src/service/chat/ChatbotMemory.php`. Drop `implements ChatbotMemoryStore` if that interface goes, and fix its references.
- Delete:
  - `src/harness/intent/`, `planning/`, `evidence/`, `loop/`, `response/`, `llm/`, `middleware/`, `turn/`;
  - from `src/harness/memory/`, everything except what `ChatbotService` still uses (expected to stay: `PdoChatbotConversationStore`, `ChatbotConversationStore`, `ChatbotSessionContext`);
  - `src/harness/tools/McpToolGateway.php`, `ShadowToolGateway.php` and `ToolRegistry.php` if nothing references them after the move;
  - `src/llm_providers/`;
  - `config/harness.php`, `config/chatbot_tools.php` if unreferenced;
  - their tests.
- Modify:
  - `src/agent/AgentFactory.php`: keep only `executeTool` (used by `Internal/mcp.php`) and what it needs.
  - `HarnessConfig.php`: drop the old LLM and middleware fields.
  - `tests/unit/ArchitectureTest.php` and `src/harness/README.md`: the component table becomes `tools/` and `memory/` only.
  - `docker-compose.yml`: drop `LLM_*`, `CHATBOT_TEMPLATE_RESPONSE`, `MCP_STDIO_*` from `app`, keeping `MCP_SERVICE_TOKEN`.
  - Root `Dockerfile`: keep the Node + `mcp-server` copy only if something in `app` still spawns it, otherwise drop the `mcp-*` stages.
  - Docs: remove the remaining mentions of the deleted classes.

- [ ] **Step 1:** Confirm the Task 9 report shows `rate >= 0.9` and the commit it ran on is HEAD~0 of the code (no code change since). Otherwise do not start.
- [ ] **Step 2:** Run `grep -rn` for each class name to be deleted, across `src public scripts tests config`. Delete only what has no remaining reference outside the deleted set.
- [ ] **Step 3:** Run `scripts/dev/composer dump-autoload` and `scripts/dev/gate.sh` → `GATE PASSED`; `npm --prefix chat-graph test` → PASS; `docker compose build app chat-graph` → success. Then repeat the Task 8 Step 3 manual curl plus one live product question → a real answer.
- [ ] **Step 4: Commit** (alone) `refactor!: delete the deterministic PHP chat pipeline (replaced by chat-graph)`.
