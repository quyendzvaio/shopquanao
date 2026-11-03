# Support ticket via chatbot: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in customer files an order-support ticket through the chatbot (drafted by the LLM, created by PHP only after the customer confirms on the next turn); admins handle tickets in the admin pages; customers see status in chat and on their profile page.

**Architecture:** PHP owns the table, the rules (`SupportTicketService`) and the only write. The LLM gets two non-writing tools: `draft_support_ticket` (validates, writes nothing) and `get_support_tickets` (reads). chat-graph's `policy_out` turns a valid draft into `pending_ticket` on the outcome plus a fixed confirmation question. PHP stores it in the chat-session slots, and on the next turn a fixed yes/no phrase creates or drops it without calling chat-graph.

**Tech Stack:** PHP 8.2 (classmap, PHPUnit, SQLite unit fixtures), MariaDB 10.11 migration, Node 22 TypeScript (`chat-graph`, `mcp-server`, `node --test`).

**Spec:** `docs/superpowers/specs/2026-11-03-support-ticket-design.md` (approved 2026-11-03). § refs point there.

## Global Constraints

- **Branch:** `feat/support-ticket`, stacked on `feat/langgraph-chat-agent` at df72b10 (PR #24, not merged yet; user chose to start now). Its PR targets `develop` and is opened only after #24 merges (rebase onto `develop` first if #24 is squash-merged). Never commit to `develop`/`master`; merge only via PR with the user's go-ahead.
- **Types (stored value → label):** `doi_tra` → "Đổi size / đổi trả", `hang_loi` → "Hàng lỗi / giao sai", `huy_don` → "Huỷ đơn", `doi_dia_chi` → "Đổi địa chỉ giao", `khac` → "Khác".
  - An order is required for every type except `khac`.
- **Statuses (stored value → label):** `moi` → "Mới", `dang_xu_ly` → "Đang xử lý", `da_xong` → "Đã xong", `tu_choi` → "Từ chối". Open = `moi` or `dang_xu_ly`.
- **Limits:**
  - A duplicate is an open ticket with the same user, the same non-null `order_id` and the same `type`.
  - Max 5 open tickets per user.
  - `summary` is 1–500 characters after trim (`mb_strlen`).
  - A draft expires 900 s after it is stored.
- **Draft statuses (exact strings):** `draft`, `requires_login`, `order_required`, `order_not_found`, `duplicate_open`, `too_many_open`, `invalid`.
- **Copy, exact:**
  - Confirmation question: `Bạn xác nhận gửi yêu cầu này cho shop không?`
  - Created: `Đã gửi yêu cầu #{id} cho shop. Shop sẽ xử lý và cập nhật trạng thái; bạn có thể hỏi mình hoặc xem ở trang tài khoản.`
  - Declined: `Mình đã huỷ yêu cầu, chưa gửi gì cho shop.`
  - Create failed on re-check: `Chưa gửi được yêu cầu: {message}` (the service's message for the status).
- SQL only in `src/repository/`; env only in `src/agent/`, `src/core/`, `chat-graph/src/config.ts`.
- No LLM tool writes anything. `tests/unit/ArchitectureTest.php` must stay green without loosening it.
- State-changing web actions are POST with `Csrf` and are guarded by `AdminGuard` on admin pages. Templates escape every ticket field with `htmlspecialchars`.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `.env`, `reports/`, `docs/*.docx`, `output/`.

## Review Focus

1. **The customer confirms after the draft expired (> 900 s):** no ticket is created, the slot is cleared, and the turn goes to chat-graph as a normal turn. Test: Task 4 `testExpiredDraftIsDroppedAndTurnGoesToChatGraph`.
2. **The customer answers "đồng ý, mà đổi sang size XL luôn"** (yes plus a new request): this is not a confirmation, so the draft is dropped and chat-graph answers. Test: Task 4 `testConfirmationWithExtraWordsIsNotAConfirmation`.
3. **The customer says "đồng ý" twice:** the second turn creates nothing, because the slot was cleared by the first. Test: Task 4 `testSecondYesCreatesNothing`.
4. **Between draft and confirm, another ticket for the same order/type got created, or the user reached 5 open:** the re-check in `create` refuses with the "Chưa gửi được…" copy. Test: Task 1 `testCreateRechecksDuplicateAndLimit` and Task 4 `testCreateRefusedOnRecheckAnswersWithReason`.
5. **A summary containing `<script>` is shown in the admin list and on the profile page:** it is escaped. Test: Task 5 `testTicketFieldsAreEscaped`.

---

### Task 1: Table, repository, `SupportTicketService`

**Files:**
- Create:
  - `database/migrations/2026_11_03_support_tickets.sql`
  - `src/repository/SupportTicketRepository.php`
  - `src/service/support/SupportTicketService.php`
  - `src/service/support/README.md` (one paragraph)
- Modify: `tests/support/SqliteShop.php`. Add `SqliteShop::supportTables(PDO $pdo): void`, which creates `orders(id, user_id, total_price, status, created_at)` and `support_tickets` with the same columns as the migration.
- Test:
  - `tests/unit/service/support/SupportTicketServiceTest.php`
  - `tests/integration/service/SupportTicketRepositoryTest.php`

**Interfaces:**
- Produces:
  - `SupportTicketService::__construct(PDO $pdo)`, with constants `TYPES` (value ⇒ label), `STATUSES` (value ⇒ label) and `OPEN_STATUSES = ['moi','dang_xu_ly']`.
  - `draft(?int $userId, array $args): array`. Returns `['status'=>'draft','draft'=>['type'=>string,'order_id'=>?int,'summary'=>string]]`, or `['status'=>X,'message'=>string]` (plus `ticket_id` and `ticket_status` for `duplicate_open`).
  - `create(int $userId, array $draft, ?int $sessionId): array`. Returns `['status'=>'created','ticket_id'=>int]` or the same refusal shape as `draft`.
  - `listForUser(?int $userId, array $args): array`. Returns `['requires_login'=>true,'message'=>…]`, or `['tickets'=>list<{id,type,type_label,order_id,summary,status,status_label,admin_note,created_at,updated_at}>]`: the newest 5, or only `ticket_id` when it is the user's own.
  - `adminList(?string $status): array`. `null` means `moi` and `dang_xu_ly`; each row adds `username`.
  - `adminDetail(int $id): ?array`.
  - `adminUpdate(int $id, string $status, string $note): bool`. Returns false for an unknown status or id; the note is trimmed and capped at 2000 chars.
  - `countNew(): int`.
  - Repository methods are the implementer's choice; only the service calls them.

- [ ] **Step 1: Migration.** Columns per spec §5.1. `user_id` FK → `users(id)`, `order_id` FK → `orders(id)` `ON DELETE SET NULL`. Indexes `(user_id, status)` and `(status, created_at)`. `ENGINE=InnoDB`, `utf8mb4`.
- [ ] **Step 2: Write the failing unit tests** in `SupportTicketServiceTest` (SQLite via `SqliteShop::supportTables`, with users 1 and 2 and orders 12 owned by user 1 and 13 owned by user 2):
  - `testGuestGetsRequiresLogin`: `draft(null, …)['status'] === 'requires_login'`.
  - `testOrderRequiredExceptForKhac`: `draft(1, ['type'=>'huy_don','summary'=>'x'])['status'] === 'order_required'`; `draft(1, ['type'=>'khac','summary'=>'x'])['status'] === 'draft'` with `order_id` null.
  - `testOrderOfAnotherUserIsNotFound`: `draft(1, ['type'=>'doi_tra','order_id'=>13,'summary'=>'x'])['status'] === 'order_not_found'`.
  - `testInvalidTypeOrSummary`: unknown type → `invalid`; `summary` empty, whitespace-only, or 501 chars → `invalid`; 500 chars → `draft`.
  - `testDraftWritesNothing`: after a valid `draft`, `support_tickets` has 0 rows.
  - `testDuplicateOpenOnlyWithAnOrder`:
    - an open `doi_tra` ticket on order 12 → `draft` of `doi_tra`/12 returns `duplicate_open` with that `ticket_id` and `ticket_status`;
    - a `hang_loi`/12 draft is still `draft`;
    - two `khac` without an order are both allowed;
    - a `da_xong` `doi_tra`/12 ticket is not a duplicate.
  - `testTooManyOpen`: 5 open tickets → `too_many_open`; 4 open + 3 `da_xong` → `draft`.
  - `testCreateRechecksDuplicateAndLimit`: `create` with a draft that became a duplicate, or with the user at 5 open, returns the refusal status and writes nothing. A valid one returns `created` and stores status `moi`, the `chat_session_id` and the trimmed summary.
  - `testListForUserOnlyOwnTickets`: user 2 asking for user 1's `ticket_id` gets `tickets: []`; the newest 5 come first; labels are filled.
  - `testAdminUpdate`: `adminUpdate(id, 'dang_xu_ly', '  ghi chú ')` → true, stored note `ghi chú`; `adminUpdate(id, 'deleted', '')` → false; an unknown id → false.
- [ ] **Step 3: Run** `scripts/dev/php vendor/bin/phpunit tests/unit/service/support/SupportTicketServiceTest.php`. Expect FAIL (class missing).
- [ ] **Step 4: Implement** the repository and service; then run `scripts/dev/composer dump-autoload`.
- [ ] **Step 5: Integration test** `SupportTicketRepositoryTest` on `shop_test`: insert, the open-duplicate lookup, the open count, the admin list with username, update. Apply the migration first: `scripts/dev/test-db.sh` and then the migration runner, as `scripts/dev/test-integration.sh` already does.
- [ ] **Step 6: Run** the unit test file, `scripts/dev/test-integration.sh --filter=SupportTicket`, and PHPStan. All PASS.
- [ ] **Step 7: Commit** `feat(support): support_tickets table, repository and SupportTicketService`.

### Task 2: The two tools (PHP bridge, mcp-server, allow-list)

**Files:**
- Modify:
  - `src/harness/tools/ToolDefinitionCatalog.php`
  - `src/harness/tools/ToolApplicationService.php`
  - `mcp-server/src/tools.ts`
  - `chat-graph/src/policies/allowList.ts` (its comment says "eleven tools, none writes")
- Test:
  - `tests/unit/agent/ExecuteToolBridgeTest.php`
  - `mcp-server/tests/*` (the existing tool-list test)
  - `chat-graph/tests/policies.test.ts` (`ALLOWED_TOOLS.length === 11`)
  - `chat-graph/tests/contract.test.ts` (the list of 11 names)

**Interfaces:**
- Consumes: Task 1 `SupportTicketService::draft` and `listForUser`.
- Produces: tools `draft_support_ticket {type: enum(5), order_id?: int>0, summary: string 1..500}` and `get_support_tickets {ticket_id?: int>0}`. `ToolApplicationService::execute` maps them to `draft($this->userId, $args)` and `listForUser($this->userId, $args)`.

- [ ] **Step 1: Failing tests.**
  - PHP bridge: `execute('draft_support_ticket', …)` with user 1 returns `status: draft`; with no user it returns `requires_login`; `get_support_tickets` returns `tickets`.
  - Node: the allow-list has 11 entries and both new names; `openShopTools(null).tools` has the 11 names.
- [ ] **Step 2: Implement.**
  - Descriptions in Vietnamese. For `draft_support_ticket`: "Soạn nháp yêu cầu hỗ trợ đơn hàng (đổi trả, hàng lỗi, huỷ đơn, đổi địa chỉ, khác) để hỏi khách xác nhận; không tạo yêu cầu." For `get_support_tickets`: "Xem các yêu cầu hỗ trợ khách đã gửi và trạng thái xử lý."
  - Zod schemas mirror the interface. Both tools get the read-only annotation, like the others.
- [ ] **Step 3: Run** `scripts/dev/php vendor/bin/phpunit --testsuite=Unit`, `npm --prefix mcp-server test`, `npm --prefix chat-graph test`. All PASS.
- [ ] **Step 4: Commit** `feat(support): draft_support_ticket and get_support_tickets tools (no write)`.

### Task 3: chat-graph confirmation edge, grounding, prompt

**Files:**
- Modify:
  - `chat-graph/src/contract.ts`: `TurnSummary.pending_ticket: PendingTicket | null`, with `export type PendingTicket = { type: string; order_id: number | null; summary: string }`.
  - `chat-graph/src/graph/buildGraph.ts`: `policy_out`, `GraphOutcome.pending_ticket`.
  - `chat-graph/src/turn.ts`: pass `pending_ticket` through.
  - `chat-graph/src/copy.ts`: `TICKET_CONFIRM_QUESTION`, `ticketDraftLine(d: PendingTicket): string`.
  - `chat-graph/src/policies/grounding.ts`
  - `chat-graph/src/graph/prompt.ts`
- Test: `chat-graph/tests/graph.test.ts`, `policies.test.ts`, `prompt.test.ts`, `copy.test.ts`, `turn.test.ts`.

**Interfaces:**
- Consumes: Task 2 tool names and the result shapes from Task 1.
- Produces: the `complete` event and the non-stream result carry `pending_ticket` (object or `null`).

- [ ] **Step 1: Failing tests.**
  - `graph.test.ts`:
    - `aValidDraftSetsPendingTicketAndAsksToConfirm`: the script calls `draft_support_ticket` with the fake result `{status:'draft', draft:{type:'doi_tra',order_id:12,summary:'Nhận size M thay vì L'}}`, then the model writes `Mình sẽ gửi yêu cầu đổi size cho đơn #12. Bạn xác nhận gửi yêu cầu này cho shop không?`. Expect `pending_ticket` equal to the draft, `response_type === 'clarification'`, `cards` `[]`, and the message containing the question exactly once.
    - `theConfirmationQuestionIsAppendedWhenTheModelLeavesItOut`: the model writes only `Mình sẽ gửi yêu cầu đổi size cho đơn #12.`; the message ends with `' ' + TICKET_CONFIRM_QUESTION`.
    - `anEmptyAnswerOnADraftTurnGetsTheDraftLineAndTheQuestion`: the model writes `''`; the message is `ticketDraftLine(draft) + ' ' + TICKET_CONFIRM_QUESTION`, where `ticketDraftLine` gives `Mình sẽ gửi yêu cầu sau cho shop: Đổi size / đổi trả, đơn #12: Nhận size M thay vì L.` (and no `, đơn #N` without an order).
    - `refusedDraftsSetNoPendingTicket`: results with status `requires_login`, `duplicate_open` or `order_required` give `pending_ticket === null` and `response_type` `final_answer`.
    - `theLastValidDraftOfTheTurnWins`: two draft calls; `pending_ticket` is the second.
    - `aSoldOutTurnSetsNoPendingTicket`: the sold-out branch keeps priority, and `pending_ticket` is null.
  - `policies.test.ts` `groundingKnowsTicketAndDraftOrderIds`: `ungroundedClaims('đơn mã 12', [draftRecord])` returns `[]`, and the ids in `get_support_tickets` `tickets[].id`/`order_id` count as known.
  - `prompt.test.ts`: the prompt mentions `draft_support_ticket` together with "không bao giờ" claiming cancel/refund, and mentions `get_support_tickets`.
- [ ] **Step 2: Implement.**
  - `policy_out` reads the last `draft_support_ticket` record whose `result.status === 'draft'`, but only when `s.soldOut` is null.
  - Then the message check: an empty message → `ticketDraftLine(draft) + ' ' + TICKET_CONFIRM_QUESTION`; a message missing the question gets `' ' + TICKET_CONFIRM_QUESTION` appended. Then `cards = []` and `responseType = 'clarification'`.
  - The `nudge` node already skips non-`final_answer`.
  - Prompt lines (Vietnamese, one each):
    - When the customer needs a return or exchange, reports a defect or wrong delivery, wants to cancel or to change the address, first identify the order (call `get_order_status`; with no order id, ask "có phải đơn #N không"), then call `draft_support_ticket`. Never say an order was cancelled, exchanged or refunded; say the request will be sent once the customer confirms.
    - When the customer asks about a request they sent, call `get_support_tickets`.
- [ ] **Step 3: Run** `npm --prefix chat-graph test && npm --prefix chat-graph run build`. PASS.
- [ ] **Step 4: Commit** `feat(chat-graph): a valid ticket draft becomes pending_ticket with a fixed confirmation question`.

### Task 4: PHP confirmation turn

**Files:**
- Create: `src/agent/TicketConfirmation.php`. A pure class: `public static function classify(string $message): string` returns `'yes' | 'no' | 'other'`.
- Modify:
  - `src/agent/ChatGraphClient.php`: `normalize` keeps `pending_ticket` when it is an array with a valid `type` and a string `summary`; otherwise `null`.
  - `src/agent/ChatbotService.php`
  - `src/service/chat/ChatbotMemory.php`: add `forgetSlot(string $key): void` if no delete exists.
  - `src/app/api/controllers/Chatbot/index.php` and `stream.php`: add `ticket_pending` (bool) and `ticket_id` (int|null) to the response and the `complete` event.
- Test:
  - `tests/unit/agent/TicketConfirmationTest.php`
  - `tests/unit/agent/ChatbotServiceTest.php`
  - `tests/unit/agent/ChatGraphClientTest.php`
  - `tests/unit/app/api/ChatStreamProtocolTest.php` (if `complete` fields are allow-listed there)

**Interfaces:**
- Consumes: Task 1 `create`; Task 3 `pending_ticket`.
- Produces: `ChatbotService` results gain `ticket_pending` (bool, true when this turn stored a draft) and `ticket_id` (int|null, set on the turn that created one). The slot key is `pending_ticket`. Its value is a JSON string `{type, order_id, summary, user_id, expires_at}`. It is never in `ChatMemoryContext::ALLOWED_SLOTS`, so it is never sent to chat-graph.

- [ ] **Step 1: Failing tests.**
  - `TicketConfirmationTest`:
    - Normalize first: lowercase, NFC, trim, strip leading/trailing `.,!?…~` and spaces, collapse inner spaces.
    - `yes` for: `đồng ý`, `Đồng ý!`, `xác nhận`, `ok`, `oke`, `có`, `ừ`, `ừm`, `đúng rồi`, `gửi đi`, `gửi`, `được`, `yes`.
    - `no` for: `thôi`, `không`, `khỏi`, `huỷ`, `hủy`, `thôi khỏi`, `không gửi`, `không cần`.
    - `other` for: `đồng ý, mà đổi sang size XL luôn`, `có áo khác không`, `ok vậy còn đơn 13`, `''`.
  - `ChatbotServiceTest`. Add the `support_tickets` and `orders` tables to its SQLite setup via `SqliteShop::supportTables`; turns run through the fake transport.
    - `testDraftTurnStoresPendingSlotAndFlagsResponse`: a complete event with `pending_ticket` sets `ticket_pending=true` and stores the slot with `expires_at ≈ now+900` and `user_id`.
    - `testYesCreatesTicketWithoutCallingChatGraph`: stored slot + `đồng ý` → 0 transport requests; the message is exactly the created copy with the new id; `ticket_id` set; `response_type 'final_answer'`, `tools_used []`; the slot is cleared; the user and assistant messages are saved.
    - `testNoDropsDraft`: `thôi` → the declined copy, no row, slot cleared, 0 requests.
    - `testConfirmationWithExtraWordsIsNotAConfirmation`: the slot is cleared, 1 transport request, no row.
    - `testExpiredDraftIsDroppedAndTurnGoesToChatGraph`: `expires_at` in the past + `đồng ý` → 1 request, no row, slot cleared.
    - `testSecondYesCreatesNothing`: two `đồng ý` turns → one row; the second turn goes to chat-graph.
    - `testOtherUserCannotConfirm`: a slot with `user_id` 1 on a turn by user 2 or a guest → no row, slot cleared, normal turn.
    - `testCreateRefusedOnRecheckAnswersWithReason`: a duplicate inserted after the draft → message `Chưa gửi được yêu cầu: …`, no new row.
    - `testGuestTurnNeverStoresPendingSlot`: `user_id` null + `pending_ticket` in the result → nothing stored.
- [ ] **Step 2: Implement.**
  - At the start of `turn()`, read the slot with `ChatbotMemory::load()` before `rememberUserMessage`, in the order that existing tests allow.
  - When the slot exists:
    - it is expired, or `user_id` ≠ this turn's user → forget it and continue;
    - else `classify`: `yes` → `create`; `no` → declined; `other` → forget it and continue.
  - Save the turn with `PdoChatbotConversationStore` as usual.
  - After a chat-graph turn whose result has `pending_ticket` and a logged-in user, store the slot.
- [ ] **Step 3: Run** `scripts/dev/php vendor/bin/phpunit --testsuite=Unit` and PHPStan. PASS.
- [ ] **Step 4: Commit** `feat(support): PHP confirms the drafted ticket on the next turn and creates it`.

### Task 5: Admin pages and profile section

**Files:**
- Create:
  - `public/admin/manage_tickets.php`
  - `src/app/web/admin/AdminTicketsPage.php`
  - `templates/pages/admin/tickets.php`
- Modify:
  - `src/app/web/admin/AdminDashboardPage.php` and `src/service/admin/AdminDashboardService.php`: the count of new tickets, plus a link to `manage_tickets.php`.
  - the admin nav partial, wherever it lists `manage_orders.php`: add "Yêu cầu hỗ trợ".
  - `src/app/web/ProfilePage.php` and `templates/pages/profile.php`: a "Yêu cầu hỗ trợ" section (`listForUser`).
  - `scripts/dev/smoke.sh` and `scripts/dev/web-snapshot.sh`: add `admin/manage_tickets.php`.
- Test:
  - `tests/unit/app/web/admin/AdminTicketsPageTest.php`
  - `tests/unit/app/web/ProfilePageTest.php`
  - `tests/unit/app/web/admin/AdminDashboardPageTest.php`

**Interfaces:**
- Consumes: Task 1 admin methods, `listForUser`, `countNew`.
- Produces:
  - `AdminTicketsPage::__construct(SupportTicketService $tickets)`.
  - `handle(WebRequest, array $session): PageResult`:
    - GET `?status=` filters the list (`all` shows every status);
    - GET `?id=` shows the detail;
    - POST `update_ticket` with `ticket_id`, `status`, `admin_note` and the CSRF field → redirect to `manage_tickets.php?id={id}&msg=updated`;
    - a bad token or status redirects with no write.

- [ ] **Step 1: Failing tests** (follow `AdminOrdersPageTest`):
  - The guard denies a non-admin.
  - A POST without a valid CSRF token writes nothing.
  - A valid POST updates the status and note.
  - An unknown status writes nothing.
  - The list defaults to the open tickets.
  - The detail data includes the order id, which the view links to `manage_orders.php`.
  - `ProfilePageTest` gets `tickets` in its view data.
  - `testTicketFieldsAreEscaped`: render `templates/pages/admin/tickets.php` and the profile template with output buffering, using a summary and note of `<script>x</script>`; the output contains `&lt;script&gt;` and no `<script>x`.
- [ ] **Step 2: Implement.** Copy the layout and markup style of `templates/pages/admin/orders.php`. The labels come from `TYPES` and `STATUSES`.
- [ ] **Step 3: Run** the Unit suite, PHPStan, `scripts/dev/smoke.sh` (after `docker compose build app && docker compose up -d --no-deps app`, and with the migration applied via `docker compose run --rm --no-deps app php scripts/run_database_migrations.php`). PASS.
- [ ] **Step 4: Commit** `feat(support): admin ticket pages and the profile "Yêu cầu hỗ trợ" section`.

### Task 6: Eval group, docs, live run

**Files:**
- Modify:
  - `chat-graph/eval/fixtures.php`: `prepare` accepts `"orders":[{"total":415000,"status":"Đang giao"}]`. It creates order rows through `OrderRepository::insertOrder` + `OrderService::updateStatus`, with no items and no stock change, and returns `order_ids`.
  - `chat-graph/eval/cases.ts` and `scorer.ts`: new `expect` keys `ticket_pending` (bool) and `ticket_created` (bool: `ticket_id` is not null).
  - `chat-graph/eval/run.ts`: read `ticket_pending`/`ticket_id`. Cases can refer to the created order as `{order1}` in a turn's `message` and in `message_contains`/`message_contains_any`; `run.ts` substitutes the fixture's first order id before sending and before scoring.
  - `chat-graph/eval/cases.jsonl`: group `ticket`.
  - `chat-graph/tests/evalCases.test.ts` and `evalScorer.test.ts`.
  - `CLAUDE.md`, `.claude/rules/architecture.md`, `.claude/rules/eval.md`, `.claude/rules/database.md` (the new table), `src/service/README.md`.
- Test: the node eval-scorer and case tests, and the live eval.

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Failing node tests.**
  - The scorer: `ticket_pending: true` fails when the observation is false, and `ticket_created: true` fails without an id.
  - `parseCases` accepts both keys and the `orders` fixture field.
  - The case file has a `ticket` group of 6 cases.
- [ ] **Step 2: Cases** (all `user: true` except T02):
  - T01: order fixture; `đơn {order1} mình đặt áo size L mà giao size M, đổi giúp mình` → `tools_include [draft_support_ticket]`, `ticket_pending true`, `response_type clarification`. Turn 2: `đồng ý` → `ticket_created true`, `no_tools true`, `message_contains ["Đã gửi yêu cầu #"]`.
  - T02 (guest): `mình muốn đổi trả đơn 12` → `ticket_pending false`, `message_contains_any [["đăng nhập"]]`.
  - T03: order fixture; draft then `thôi` → `ticket_created false`, `message_contains ["Mình đã huỷ yêu cầu"]`.
  - T04: order fixture; T01's two turns, then turn 3 `yêu cầu của mình sao rồi` → `tools_include [get_support_tickets]`, `message_contains_any [["Mới"]]`.
  - T05: order fixture; T01's two turns, then turn 3 repeats turn 1 → `ticket_pending false` (`duplicate_open`).
  - T06: order fixture; `áo mình nhận bị rách` (no order id) → `tools_include [get_order_status]`, `ticket_pending false` (the bot asks which order first), `message_contains ["{order1}"]`.
- [ ] **Step 3: Docs.**
  - CLAUDE.md and `architecture.md`: "9 read-only tools" → 11 non-writing tools. Add the spec §6 sentence on the only write path, and a ticket line in the graph diagram.
  - `eval.md`: the `ticket` group row, the two `expect` keys, and the `orders` fixture field.
  - `database.md`: the table.
- [ ] **Step 4: Run** the full local gate: `scripts/dev/gate.sh`, then rebuild `app` and `chat-graph`, apply the migration, and run `npm --prefix chat-graph run eval -- --pace-ms=60000 --group=ticket`, then the full 57-case eval.
  - Expect the `ticket` group ≥ 5/6 and the full run ≥ 90%. Report any failure with its trace before changing anything, per CLAUDE.md "Fixing failures".
- [ ] **Step 5: Commit** `test(eval): ticket group; docs: support tickets`.
