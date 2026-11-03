# Answer Streaming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stream the chatbot's final answer to the browser sentence by sentence while the model writes it, with every sentence checked before it leaves `chat-graph`.

**Architecture:** A LangChain callback handler (`AnswerStreamer`, `lc_prefer_streaming = true`) is added to the graph run; it makes the agent's model calls stream, buffers tokens per model run, cuts complete sentences/lines, holds the first one, drops a run that turns into a tool call, checks each unit with the existing grounding/ticket/markup functions and emits it. At the end of the turn `turn.ts` reconciles what was streamed with `policy_out`'s final text (remaining `delta`s, or one `replace`). PHP forwards `replace` as SSE `chat.replace`; the widget swaps the bubble text.

**Tech Stack:** TypeScript (Node 22, `@langchain/core` 1.3.2, `langchain` 1.6.0, `node:test`), PHP 8.2 (PHPUnit), vanilla JS widget.

**Spec:** `docs/superpowers/specs/2026-11-04-answer-streaming-design.md`

## Global Constraints

- Only the final answer streams; `progress` events stay unforwarded by PHP (spec §1, user decision).
- Stream only when `req.stream === true`, no sold-out state (`recorder.soldOut === null`) and no valid ticket draft (`lastValidDraft(recorder.records) === null`), and only for model calls made inside the `agent` node; the `policy_out` retry never streams (spec §4.1).
- A unit is a sentence (`.`, `?`, `!` followed by whitespace; a `.` between two digits is not an end) or a line (`\n`), whichever comes first (spec §4.3).
- Each unit is emitted only if `hasToolCallMarkup(unit)` is false, `ungroundedClaims(unit, recorder.records)` is empty and `asksTicketConfirmation(unit)` is false; it is emitted as `plainText(unit)`. Any failure stops streaming for the rest of the turn (spec §4.3).
- New NDJSON event `{ type: 'replace', text: string }`; SSE `chat.replace` with field `text` (spec §4.4, §4.5).
- Invariant for every turn: concatenating the `delta` texts, resetting on each `replace`, equals the turn's final `message` (chat-graph) and the saved assistant message (PHP).
- `/turn` with `stream: false` and `POST /api/chatbot` are unchanged.
- Never commit `.env`, `reports/`, `docs/*.docx`, `output/`. Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

- A money amount split across tokens (`"giá 150."` then `"000đ. "`) must not be cut at the dot: pinned in Task 1 (`takeUnitsKeepsThousandsSeparator`).
- A model run that writes one lead-in sentence then a tool call must never show that sentence: Task 1 (`leadInBeforeToolCallIsNeverEmitted`) and Task 2 (`leadInThenToolCallStreamsOnlyTheAnswer`).
- An answer with no terminal punctuation (one line, no `.`) still reaches the customer exactly once: Task 1 (`runEndFlushesHeldAndTail`) and the Task 2 invariant.
- The turn deadline or a model error after text was streamed must leave the busy text on screen and in history: Task 2 (`deadlineAfterStreamedTextReplacesWithBusy`), Task 3 (`testTransportFailureAfterDeltasReplacesWithBusy`).
- Langfuse tracing must keep receiving the agent's model runs when the streamer is added to the callbacks: Task 2 runs `tests/tracingExport.test.ts` unchanged.

## File Structure

| File | Responsibility |
| --- | --- |
| `chat-graph/src/graph/answerStream.ts` (new) | `takeUnits` (pure unit splitter) and `AnswerStreamer` (callback handler: per-run buffering, hold-first, tool-call drop, checks, emit) |
| `chat-graph/src/graph/buildGraph.ts` | create the streamer per turn, add it to the run callbacks, gate it to the `agent` node |
| `chat-graph/src/turn.ts` | track streamed text, `closingTextEvents` reconciliation |
| `chat-graph/src/contract.ts` | `replace` event type |
| `chat-graph/tests/support/scriptedModel.ts` | `_streamResponseChunks` so scripted steps stream like the real model |
| `src/agent/ChatGraphClient.php`, `src/agent/ChatbotService.php`, `src/app/api/controllers/Chatbot/stream.php`, `templates/partials/chatbox.php` | forward and render `replace` |
| `CLAUDE.md`, `.claude/rules/architecture.md`, `docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md` | docs |

---

### Task 1: `AnswerStreamer` and the unit splitter

**Files:**
- Create: `chat-graph/src/graph/answerStream.ts`
- Test: `chat-graph/tests/answerStream.test.ts`

**Interfaces:**
- Consumes: `hasToolCallMarkup`, `ungroundedClaims`, `plainText` (`src/policies/grounding.ts`), `asksTicketConfirmation` (`src/policies/ticket.ts`), `ToolRecord` (`src/contract.ts`).
- Produces:
  - `export function takeUnits(buffer: string): { units: string[]; rest: string }` — complete units in order, each keeping its trailing whitespace/newline, so `units.join('') + rest === buffer`.
  - `export type AnswerStreamOptions = { emit: (text: string) => void; evidence: () => ToolRecord[]; allowed: () => boolean }`
  - `export class AnswerStreamer extends BaseCallbackHandler` with `name = 'answer_streamer'`, `lc_prefer_streaming = true`, `awaitHandlers = true` (so every emit happens before the model call returns), and `stopped: boolean` (read-only from outside). Handler methods used: `handleChatModelStart(llm, messages, runId)`, `handleLLMNewToken(token, idx, runId, parentRunId, tags, fields)`, `handleLLMEnd(output, runId)`, `handleLLMError(err, runId)`.

Per-run state, keyed by `runId`, created in `handleChatModelStart` only when `allowed()` is true and `stopped` is false: `buffer`, `held: string | null`, `emitted: number`, `dead: boolean`.
- A token whose `fields.chunk.message.tool_call_chunks` is non-empty marks the run `dead` and drops `held` and `buffer`.
- Each complete unit: if `emitted === 0 && held === null`, hold it; otherwise send the held unit (if any) then this one.
- Sending a unit runs the three checks against `evidence()`; a failure sets `stopped = true` and drops the run's remaining text; a pass calls `emit(plainText(unit))` (skip the call when `plainText(unit)` is empty) and increments `emitted`.
- `handleLLMEnd`: if the run is live and the generation's message has no `tool_calls`, send `held` then `buffer` (the tail) through the same checks; then delete the run. `handleLLMError`: delete the run.

- [ ] **Step 1: Write the failing tests** (`node:test`, call the handler methods directly with fake `runId`s; a token chunk helper builds `{ chunk: new ChatGenerationChunk({ text, message: new AIMessageChunk({ content: text, tool_call_chunks }) }) }`)

```ts
test('takeUnitsSplitsSentencesAndLines', () => {
  assert.deepEqual(takeUnits('Chào bạn. Shop có áo!\nGiá'), { units: ['Chào bạn. ', 'Shop có áo!\n'], rest: 'Giá' });
});
test('takeUnitsKeepsThousandsSeparator', () => {
  assert.deepEqual(takeUnits('Giá 150.'), { units: [], rest: 'Giá 150.' });
  assert.deepEqual(takeUnits('Giá 150.000đ. Còn'), { units: ['Giá 150.000đ. '], rest: 'Còn' });
});
test('firstUnitIsHeldUntilTheSecond', ...);          // tokens 'Một. ' -> emits []; 'Hai. ' -> emits ['Một. ', 'Hai. ']
test('leadInBeforeToolCallIsNeverEmitted', ...);      // 'Để mình xem. ' then a tool_call_chunks token, then end -> emits []
test('runEndFlushesHeldAndTail', ...);                // 'Một. ' + 'không chấm' then handleLLMEnd (no tool_calls) -> ['Một. ', 'không chấm']
test('ungroundedPriceStopsTheTurn', ...);             // evidence has price 150000; tokens 'A. ' 'Giá 99.000đ. ' 'C. ' -> emits ['A. '], stopped === true
test('ticketConfirmationQuestionStopsTheTurn', ...);  // 'Ok. Bạn xác nhận gửi yêu cầu này cho shop không? ' -> 'Ok. ' never followed by the question; stopped === true
test('markdownIsStrippedPerUnit', ...);               // '**Áo** đẹp. ' '- *mới* nhé. ' -> ['Áo đẹp. ', '- mới nhé. ']
test('notAllowedRunEmitsNothing', ...);               // allowed: () => false -> emits [] after start/tokens/end
test('stoppedStreamerIgnoresLaterRuns', ...);         // after a failed check, a new run's text emits nothing
```

Note for `ungroundedPriceStopsTheTurn`: with the hold rule, `'A. '` is held and is sent together with `'Giá 99.000đ. '`; `'A. '` passes and is emitted, the second fails → assert emits `['A. ']` and `stopped === true`, and `'C. '` is never emitted.

- [ ] **Step 2: Run to verify they fail**

Run: `npm --prefix chat-graph test -- --test-name-pattern='takeUnits|Held|leadIn|runEnd|ungrounded|ticketConfirmation|markdownIsStripped|notAllowed|stoppedStreamer'`
Expected: FAIL (module `../src/graph/answerStream.js` not found).

- [ ] **Step 3: Implement `answerStream.ts`** per the Interfaces block. `takeUnits` scans for the first index where either `\n` occurs, or `[.?!]` is followed by whitespace and is not a `.` with a digit on both sides; a unit ends after the run of whitespace that follows.

- [ ] **Step 4: Run to verify they pass**

Run: same command. Expected: PASS. Then `npm --prefix chat-graph run build` → exit 0.

- [ ] **Step 5: Commit**

```bash
git add chat-graph/src/graph/answerStream.ts chat-graph/tests/answerStream.test.ts
git commit -m "feat(chat-graph): AnswerStreamer checks and emits answer sentences as they are written"
```

### Task 2: Wire the streamer into the graph and the turn

**Files:**
- Modify: `chat-graph/src/contract.ts` (`TurnEvent`), `chat-graph/src/graph/buildGraph.ts` (`GraphDeps`, `compileTurnGraph`, `buildTurnGraph`), `chat-graph/src/turn.ts`
- Modify: `chat-graph/tests/support/scriptedModel.ts`
- Test: `chat-graph/tests/turn.test.ts`, existing `chat-graph/tests/*.test.ts`

**Interfaces:**
- Consumes: Task 1 `AnswerStreamer`, `AnswerStreamOptions`.
- Produces:
  - `TurnEvent` gains `| { type: 'replace'; text: string }`.
  - `GraphDeps.onAnswerText?: (text: string) => void`. When set, `buildTurnGraph(...).invoke` creates the `TurnRecorder` itself (pass it into `compileTurnGraph`), builds one `AnswerStreamer({ emit: deps.onAnswerText, evidence: () => recorder.records, allowed: () => inAgent && recorder.soldOut === null && lastValidDraft(recorder.records) === null })` and appends it to the graph run's `callbacks` next to `deps.callbacks`. `inAgent` is a per-turn flag set true at the start of `agentNode` and false after `agent.invoke` returns or throws.
  - `export function closingTextEvents(streamed: string, message: string): TurnEvent[]` in `turn.ts`: `streamed === ''` → `sentenceChunks(message)` as `delta`s; `message.startsWith(streamed)` → `sentenceChunks(message.slice(streamed.length))` as `delta`s; otherwise `[{ type: 'replace', text: message }]`.
  - `runTurn` passes `onAnswerText` only when `req.stream` is true; it appends to a local `streamed` string and emits `{ type: 'delta', text }` only while `!finished`. After the graph, it emits `closingTextEvents(streamed, outcome.message)`, then `cards`, then `complete` (unchanged order).
- `ScriptedChatModel` gains `_streamResponseChunks`: for an `AIMessage` step it records the call like `_generate`, then yields the content as word-sized `ChatGenerationChunk`s (calling `runManager?.handleLLMNewToken(text, undefined, undefined, undefined, undefined, { chunk })` for each), then, if the step has `tool_calls`, one chunk whose `AIMessageChunk` has `tool_call_chunks` `{ name, args: JSON.stringify(args), id, index }`. `Error` and `'hang'` steps behave as in `_generate`. Existing tests must keep passing; they now exercise the streaming path whenever `req().stream` is true.

- [ ] **Step 1: Write the failing tests in `turn.test.ts`** (a helper `textSeen(events)` folds `delta`/`replace` into the text the customer sees)

```ts
test('answerDeltasArriveBeforeTheModelCallEnds', ...);   // a model whose 2nd step streams 3 sentences with an await between tokens: the first delta is pushed before the model's _streamResponseChunks finished (record a flag)
test('leadInThenToolCallStreamsOnlyTheAnswer', ...);     // steps: ai('Để mình tìm nhé. ', [search call]), ai('Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem nhé.') -> textSeen === result.message and no delta contains 'Để mình tìm'
test('ungroundedSentenceEndsWithReplace', ...);          // answer with 'giá 99.000đ' then a retry step with a grounded answer -> last text event is replace with result.message
test('soldOutTurnDoesNotStreamDuringTheGraph', ...);     // reuse the existing sold-out fixture: every delta comes after the graph (no delta before the last progress) and textSeen === result.message
test('ticketDraftTurnDoesNotStream', ...);               // reuse the existing pending-ticket fixture: same assertions
test('nudgeTextArrivesAsAClosingDelta', ...);            // reuse the existing nudge fixture: no replace; textSeen === result.message
test('deadlineAfterStreamedTextReplacesWithBusy', ...);  // 2nd step streams one sentence then hangs; turnTimeoutMs small -> last text event is { type: 'replace', text: BUSY }
test('nonStreamingTurnEmitsNoTextBeforeTheEnd', ...);    // req({ stream: false }): runTurn's emit gets no delta before the graph returned
test('closingTextEventsCases', ...);                     // ('', 'A. B.') -> 2 deltas; ('A. ', 'A. B. nudge') -> deltas of 'B. nudge'; ('A. ', 'X.') -> [replace 'X.']
```

Every new test also asserts `textSeen(events) === result.message`.

- [ ] **Step 2: Run to verify they fail**

Run: `npm --prefix chat-graph test -- --test-name-pattern='answerDeltasArrive|leadInThenToolCall|ungroundedSentenceEnds|soldOutTurnDoesNotStream|ticketDraftTurnDoesNotStream|nudgeTextArrives|deadlineAfterStreamed|nonStreamingTurnEmits|closingTextEventsCases'`
Expected: FAIL.

- [ ] **Step 3: Implement** the Interfaces block in `contract.ts`, `buildGraph.ts`, `turn.ts`, `scriptedModel.ts`.

- [ ] **Step 4: Run the whole chat-graph suite and build**

Run: `npm --prefix mcp-server ci && npm --prefix chat-graph ci && npm --prefix chat-graph test && npm --prefix chat-graph run build`
Expected: all tests pass, including `tests/tracingExport.test.ts` and the existing `turnStreamsAfterGraphAndEndsWithComplete` unchanged (its answer is streamed after the tool's `progress`); build exit 0.

- [ ] **Step 5: Commit**

```bash
git add chat-graph/src chat-graph/tests
git commit -m "feat(chat-graph): stream the agent's final answer; replace event when the checked answer differs"
```

### Task 3: PHP and widget forward `replace`

**Files:**
- Modify: `src/agent/ChatGraphClient.php`, `src/agent/ChatbotService.php`, `src/app/api/controllers/Chatbot/stream.php`, `templates/partials/chatbox.php`
- Test: `tests/unit/agent/ChatGraphClientTest.php`, `tests/unit/agent/ChatbotServiceTest.php`

**Interfaces:**
- Consumes: Task 2 NDJSON `{"type":"replace","text":...}`.
- Produces:
  - `ChatGraphClient::EVENT_TYPES = ['progress', 'delta', 'replace', 'cards', 'complete']`; a `replace` sets the reader's `message` to `text` (a `delta` still appends).
  - `ChatbotService::respondStreaming(string $message, callable $onText): array` with `$onText(string $text, string $kind)`, `$kind` = `'delta'` or `'replace'`. Existing one-argument closures keep working (PHP ignores the extra argument). `delta` and `replace` from chat-graph are both forwarded; either one sets `$sentText`. When the chat-graph call throws after text was forwarded, call `$onText(BUSY_MESSAGE, 'replace')` (today it sends nothing). The `TICKET_FAILED` line and the ticket confirmation answers stay `'delta'`.
  - `stream.php`: `'delta'` → `['type' => 'chat.delta', 'delta' => $text]`; `'replace'` → `['type' => 'chat.replace', 'text' => $text]`. Update the file's header comment (text may stream before the graph finishes, each sentence already checked).
  - `chatbox.php` `handleChatStreamEvent`: `chat.replace` sets `active.text = String(data.text || '')`, `active.sawDelta = true`, and redraws the bubble exactly like `chat.delta`.

- [ ] **Step 1: Write the failing PHPUnit tests**

```php
public function testReplaceResetsTheStreamedMessage(): void            // ChatGraphClientTest: NDJSON delta 'Một. ', replace 'Hai.', complete -> result message 'Hai.', onEvent saw the replace
public function testStreamingForwardsReplaceAndSavesTheFinalText(): void // ChatbotServiceTest: graph streams delta 'A. ' then replace 'B.' -> $onText calls [['A. ','delta'],['B.','replace']]; saved assistant message 'B.'
public function testTransportFailureAfterDeltasReplacesWithBusy(): void  // ChatbotServiceTest: delta then the transport throws -> last call [BUSY_MESSAGE,'replace']; saved message BUSY_MESSAGE
```

Update `testBusyAfterPartialDeltasDoesNotRepeatTheBusyText` to the new behavior: the busy text arrives once, as a `replace`, not as a `delta` (rename it `testBusyAfterPartialDeltasArrivesAsOneReplace`).

- [ ] **Step 2: Run to verify they fail**

Run: `scripts/dev/php vendor/bin/phpunit --filter='testReplaceResetsTheStreamedMessage|testStreamingForwardsReplaceAndSavesTheFinalText|testTransportFailureAfterDeltasReplacesWithBusy|testBusyAfterPartialDeltasArrivesAsOneReplace'`
Expected: FAIL.

- [ ] **Step 3: Implement** the Interfaces block (all four files).

- [ ] **Step 4: Run the PHP gates**

Run: `scripts/dev/php vendor/bin/phpunit --testsuite=Unit && scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress && scripts/dev/php -l src/app/api/controllers/Chatbot/stream.php`
Expected: OK, no PHPStan errors, no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/agent src/app/api/controllers/Chatbot/stream.php templates/partials/chatbox.php tests/unit/agent
git commit -m "feat(chat): forward chat-graph replace events to the widget; busy after streamed text replaces it"
```

### Task 4: Docs, full gate, live check

**Files:**
- Modify: `CLAUDE.md` (Chatbot architecture block: `delta` can arrive before the graph ends, checked per sentence; `replace`), `.claude/rules/architecture.md` (step 4 `policy_out` and § Streaming boundary), `docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md` (one note pointing to the new spec where it says text leaves only after `policy_out`)

- [ ] **Step 1: Edit the three docs.** `.claude/rules/*.md` needs `git add -f`.

- [ ] **Step 2: Full local gate**

Run: `scripts/dev/gate.sh` (or `SKIP_SNAPSHOT=1 scripts/dev/gate.sh` if no snapshot baseline exists)
Expected: every stage passes.

- [ ] **Step 3: Rebuild and check live on dev**

Run: `docker compose build app chat-graph && docker compose up -d --no-deps app chat-graph`, wait for both healthy, then stream one policy question with timestamps (same curl loop used during the spec, `POST /api/chatbot/stream`, no `Origin` header) and one browser run (puppeteer, `/usr/bin/google-chrome`) on the storefront widget.
Expected: the curl shows `chat.delta` events spread over time (first one before the last), not all within a few ms; the browser bubble's text grows between two samples taken while the answer is being written, and its final text equals the saved message from `GET /api/chatbot/history`. A sold-out question ("quần baggy còn hàng không") still shows the full answer at once.

- [ ] **Step 4: Real-LLM eval (manual layer)**

Run: `npm --prefix chat-graph run eval -- --pace-ms=30000 --output=reports/eval/streaming.json`
Expected: pass rate >= 0.9 (the eval uses `POST /api/chatbot`, so this checks nothing regressed). Report failures with their cases; do not change expectations.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/superpowers/specs/2026-11-01-langgraph-chat-agent-design.md docs/superpowers/specs/2026-11-04-answer-streaming-design.md docs/superpowers/plans/2026-11-04-answer-streaming.md
git add -f .claude/rules/architecture.md
git commit -m "docs: answer streaming (sentence checks, replace event)"
```
