import assert from 'node:assert/strict';
import test from 'node:test';
import { tool } from '@langchain/core/tools';
import { z } from 'zod/v4';
import type { ShopTools } from '../src/shopTools.js';
import type { GraphConfig } from '../src/config.js';
import type { TurnEvent } from '../src/contract.js';
import { BUSY } from '../src/copy.js';
import { closingTextEvents, runTurn } from '../src/turn.js';
import { fakeShopTools } from './support/fakeTools.js';
import { ScriptedChatModel, type ScriptedStep } from './support/scriptedModel.js';
import { ai, call, CONFIG, req, searchTurn, setup, TrackedModel } from './support/turnHarness.js';

test('turnStreamsAfterGraphAndEndsWithComplete', async () => {
  const { deps, closed } = setup(searchTurn);
  const events: TurnEvent[] = [];
  const result = await runTurn(req(), deps, (e) => events.push(e));

  const types = events.map((e) => e.type);
  const lastProgress = types.lastIndexOf('progress');
  const firstDelta = types.indexOf('delta');
  assert.ok(lastProgress >= 0, 'progress emitted');
  assert.ok(firstDelta > lastProgress, 'no delta before the last progress');
  assert.equal(types[types.length - 1], 'complete');
  assert.equal(types[types.length - 2], 'cards');
  assert.deepEqual(events.filter((e) => e.type === 'progress').map((e) => (e as { stage: string }).stage), ['tool:search_products']);

  const deltas = events.filter((e): e is Extract<TurnEvent, { type: 'delta' }> => e.type === 'delta');
  assert.ok(deltas.length >= 3, 'chunked by sentence');
  assert.equal(deltas.map((d) => d.text).join(''), result.message);
  assert.equal(result.response_type, 'final_answer');
  assert.equal(result.primary_intent, 'product_search');
  assert.deepEqual(result.tools_used, ['search_products']);
  assert.equal(result.products[0]?.id, 66);
  assert.match(result.trace_id, /^[0-9a-f]{32}$/);
  assert.equal(result.latency.tool_calls, 1);
  assert.equal(result.latency.model_calls, 2);
  assert.equal(typeof result.latency.total_ms, 'number');
  const complete = events[events.length - 1] as Extract<TurnEvent, { type: 'complete' }>;
  assert.equal(complete.trace_id, result.trace_id);
  assert.equal(closed(), 1);
});

test('checkoutRefusalIsUnsupportedCheckoutIntent', async () => {
  const { deps } = setup([]);
  const events: TurnEvent[] = [];
  const result = await runTurn(req({ message: 'thêm áo này vào giỏ' }), deps, (e) => events.push(e));
  assert.equal(result.response_type, 'refusal');
  assert.equal(result.primary_intent, 'unsupported_checkout');
  assert.ok(!events.some((e) => e.type === 'cards'));
});

test('modelErrorAnswersBusy', async () => {
  const { deps, closed } = setup([new Error('boom')]);
  const events: TurnEvent[] = [];
  const result = await runTurn(req(), deps, (e) => events.push(e));
  assert.equal(result.message, BUSY);
  assert.equal(result.response_type, 'busy');
  assert.deepEqual(result.products, []);
  assert.equal(result.primary_intent, 'unknown');
  assert.equal(events[events.length - 1]?.type, 'complete');
  assert.ok(!events.some((e) => e.type === 'cards'));
  assert.equal(closed(), 1);
});

test('openToolsFailureAnswersBusy', async () => {
  const deps = { config: CONFIG, model: new ScriptedChatModel([]), openTools: async () => { throw new Error('mcp down'); } };
  const result = await runTurn(req(), deps, () => {});
  assert.equal(result.response_type, 'busy');
});

test('turnDeadlineAnswersBusy', async () => {
  const config = { ...CONFIG, budget: { ...CONFIG.budget, turnTimeoutMs: 50 } } as GraphConfig;
  const model = new TrackedModel(['hang']);
  const { deps, closed } = setup([], config, model);
  const started = Date.now();
  const result = await runTurn(req(), deps, () => {});
  assert.ok(Date.now() - started < 1000, 'resolved fast');
  assert.equal(result.response_type, 'busy');
  assert.equal(result.message, BUSY);
  assert.equal(closed(), 1);
  await new Promise((r) => setTimeout(r, 50));
  assert.equal(model.settled, true, 'in-flight model call was aborted');
});

const FAST = { ...CONFIG, budget: { ...CONFIG.budget, turnTimeoutMs: 50 } } as GraphConfig;
const never = <T>(): Promise<T> => new Promise<T>(() => {});

test('openToolsThatNeverResolvesAnswersBusyAtDeadline', async () => {
  const deps = { config: FAST, model: new ScriptedChatModel([]), openTools: () => never<ShopTools>() };
  const started = Date.now();
  const result = await runTurn(req(), deps, () => {});
  assert.ok(Date.now() - started < 1000);
  assert.equal(result.response_type, 'busy');
  assert.equal(result.message, BUSY);
});

test('toolsOpenedAfterTheDeadlineAreStillClosed', async () => {
  const { tools, closed } = setup([]);
  let release: (t: ShopTools) => void = () => {};
  const deps = { config: FAST, model: new ScriptedChatModel([]), openTools: () => new Promise<ShopTools>((r) => { release = r; }) };
  const result = await runTurn(req(), deps, () => {});
  assert.equal(result.response_type, 'busy');
  release(tools);
  await new Promise((r) => setTimeout(r, 20));
  assert.equal(closed(), 1, 'late-opened tools are closed');
  assert.equal(tools.calls.length, 0);
});

test('closeThatNeverResolvesDoesNotDelayTheReply', async () => {
  const { tools, deps } = setup(searchTurn);
  tools.close = () => never<void>();
  const started = Date.now();
  const result = await runTurn(req(), deps, () => {});
  assert.ok(Date.now() - started < 1000);
  assert.equal(result.response_type, 'final_answer');
});

test('toolInFlightAtDeadlineAnswersBusyWithoutUnhandledRejection', async () => {
  const unhandled: unknown[] = [];
  const spy = (reason: unknown): void => { unhandled.push(reason); };
  process.on('unhandledRejection', spy);
  try {
    let rejectPending: (e: Error) => void = () => {};
    const pending = new Promise<string>((_, reject) => { rejectPending = reject; });
    pending.catch(() => {});
    const hangingTool = tool(async () => pending, { name: 'search_products', description: 'hangs', schema: z.record(z.string(), z.unknown()) });
    let closeCalls = 0;
    const tools: ShopTools = {
      tools: [hangingTool],
      async call() { return pending as never; },
      // Like the real MCP client: closing rejects the requests still in flight.
      async close() { closeCalls += 1; rejectPending(new Error('connection closed')); },
    };
    const model = new ScriptedChatModel([ai('', [call('c1', 'search_products', { search: 'áo' })])]);
    const started = Date.now();
    const result = await runTurn(req(), { config: FAST, model, openTools: async () => tools }, () => {});
    assert.ok(Date.now() - started < 1000);
    assert.equal(result.response_type, 'busy');
    assert.equal(closeCalls, 1);
    await new Promise((r) => setTimeout(r, 100));
    assert.deepEqual(unhandled, []);
  } finally {
    process.off('unhandledRejection', spy);
  }
});

test('externalAbortAnswersBusyAndCancelsTheModelCall', async () => {
  const model = new TrackedModel(['hang']);
  const { deps } = setup([], CONFIG, model);
  const outside = new AbortController();
  setTimeout(() => outside.abort(), 30);
  const result = await runTurn(req(), { ...deps, signal: outside.signal }, () => {});
  assert.equal(result.response_type, 'busy');
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(model.settled, true);
});

test('theCompleteEventAndTheResultCarryPendingTicket', async () => {
  const draft = { type: 'doi_tra', order_id: 12, summary: 'Nhận size M thay vì L' };
  const run = async (steps: ScriptedStep[]) => {
    const tools = fakeShopTools({ draft_support_ticket: () => ({ status: 'draft', draft }), get_support_tickets: () => ({ tickets: [] }) });
    const events: TurnEvent[] = [];
    const result = await runTurn(req({ user_id: 7 }), { config: CONFIG, model: new ScriptedChatModel(steps), openTools: async () => tools }, (e) => events.push(e));
    return { result, complete: events[events.length - 1] as Extract<TurnEvent, { type: 'complete' }> };
  };

  const drafted = await run([ai('', [call('t1', 'draft_support_ticket', {})]), ai('Mình sẽ gửi yêu cầu đổi size.')]);
  assert.deepEqual(drafted.result.pending_ticket, draft);
  assert.deepEqual(drafted.complete.pending_ticket, draft);
  assert.equal(drafted.result.response_type, 'clarification');
  assert.equal(drafted.result.primary_intent, 'support_ticket');
  assert.equal(drafted.result.freeship_nudge, false);

  const plain = await run([ai('', [call('t1', 'get_support_tickets', {})]), ai('Bạn chưa có yêu cầu nào.')]);
  assert.equal(plain.result.pending_ticket, null);
  assert.equal(plain.complete.pending_ticket, null);
  assert.equal(plain.result.primary_intent, 'support_ticket');

  const busy = await runTurn(req(), { config: CONFIG, model: new ScriptedChatModel([new Error('boom')]), openTools: async () => fakeShopTools({}) }, () => {});
  assert.equal(busy.pending_ticket, null);
});

// ---- Answer streaming (spec 2026-11-04) ----

type TextEvent = Extract<TurnEvent, { type: 'delta' | 'replace' }>;
const isText = (e: TurnEvent): e is TextEvent => e.type === 'delta' || e.type === 'replace';
/** The text the customer sees: deltas append, a replace starts over. */
const textSeen = (events: TurnEvent[]): string => events.filter(isText).reduce((t, e) => (e.type === 'delta' ? t + e.text : e.text), '');

/** Runs a turn and notes, for each text event, whether a model call was still running when it was emitted. */
async function streamed(steps: ScriptedStep[], handlers: Parameters<typeof fakeShopTools>[0], over: Parameters<typeof req>[0] = {}, config: GraphConfig = CONFIG) {
  const model = new ScriptedChatModel(steps);
  const tools = fakeShopTools(handlers);
  const events: TurnEvent[] = [];
  const during: boolean[] = [];
  const result = await runTurn(req(over), { config, model, openTools: async () => tools }, (e) => {
    events.push(e);
    if (isText(e)) during.push(model.inFlight > 0);
  });
  return { result, events, during, texts: events.filter(isText) };
}

const shirtSearch = { search_products: () => ({ products: [{ id: 66, name: 'Áo Thun', price: 150000, stock: 3 }] }) };
const searchCall = ai('', [call('c1', 'search_products', { search: 'áo thun' })]);

test('answerDeltasArriveBeforeTheModelCallEnds', async () => {
  const { result, events, during } = await streamed([searchCall, ai('Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem nhé. Còn size M.')], shirtSearch);
  assert.equal(during[0], true, 'the first delta left while the answer was being written');
  assert.ok(!events.some((e) => e.type === 'replace'));
  assert.equal(textSeen(events), result.message);
});

test('leadInThenToolCallStreamsOnlyTheAnswer', async () => {
  const { result, events, texts } = await streamed([
    ai('Để mình tìm nhé. ', [call('c1', 'search_products', { search: 'áo thun' })]),
    ai('Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem nhé.'),
  ], shirtSearch);
  assert.ok(!texts.some((e) => (e.type === 'delta' ? e.text : e.text).includes('Để mình tìm')));
  assert.equal(textSeen(events), result.message);
  assert.equal(result.message, 'Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem nhé.');
});

test('ungroundedSentenceEndsWithReplace', async () => {
  const { result, events, texts } = await streamed([
    searchCall,
    ai('Shop có Áo Thun. Giá chỉ 99.000đ. Mua ngay.'),
    ai('Áo Thun mã 66 giá 150.000đ.'),
  ], shirtSearch);
  assert.equal(result.message, 'Áo Thun mã 66 giá 150.000đ.');
  assert.ok(texts.some((e) => e.type === 'delta' && e.text === 'Shop có Áo Thun.'), 'the grounded first sentence was streamed');
  assert.ok(!texts.some((e) => e.type === 'delta' && e.text.includes('99.000')), 'the ungrounded sentence never left');
  assert.deepEqual(texts.at(-1), { type: 'replace', text: result.message });
  assert.equal(textSeen(events), result.message);
});

test('soldOutTurnDoesNotStreamDuringTheGraph', async () => {
  const baggy = { id: 69, name: 'Quần Baggy Vải Tuyết Mưa', price: 350000, stock: 0 };
  const kaki = { id: 73, name: 'Quần Kaki Ống Rộng', price: 320000, stock: 5 };
  const { result, events, during } = await streamed([
    ai('', [call('c1', 'search_products', { search: 'quần baggy', in_stock: true })]),
    ai('Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng không? Mẫu này đẹp lắm.'),
  ], {
    search_products: () => ({ products: [], out_of_stock_ids: [69], out_of_stock_matches: 1 }),
    find_similar_in_stock: () => ({ source: { id: 69, name: baggy.name }, products: [kaki] }),
  }, { message: 'quần baggy còn hàng không' });
  assert.equal(result.response_type, 'clarification');
  assert.ok(during.length > 0);
  assert.ok(during.every((d) => !d), 'every text event came after the graph');
  assert.ok(!events.some((e) => e.type === 'replace'));
  assert.equal(textSeen(events), result.message);
});

test('ticketDraftTurnDoesNotStream', async () => {
  const draft = { type: 'doi_tra', order_id: 12, summary: 'Nhận size M thay vì L' };
  const { result, events, during } = await streamed([
    ai('', [call('t1', 'draft_support_ticket', {})]),
    ai('Mình sẽ gửi yêu cầu đổi size. Bạn chờ chút nhé. Cảm ơn bạn.'),
  ], { draft_support_ticket: () => ({ status: 'draft', draft }) }, { user_id: 7 });
  assert.deepEqual(result.pending_ticket, draft);
  assert.ok(during.every((d) => !d), 'every text event came after the graph');
  assert.ok(!events.some((e) => e.type === 'replace'));
  assert.equal(textSeen(events), result.message);
});

test('nudgeTextArrivesAsAClosingDelta', async () => {
  const freeship = { status: 'gap', gap: 50000, threshold: 500000, cart_fingerprint: 'abc', products: [{ id: 90, name: 'Túi Xách Mini Thời Trang', price: 450000, stock: 4 }] };
  const { result, events, during } = await streamed(
    [searchCall, ai('Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem nhé. Còn size M.')],
    { ...shirtSearch, suggest_freeship_items: () => freeship },
    { user_id: 5 },
  );
  assert.equal(result.freeship_nudge, true);
  assert.equal(during[0], true);
  assert.ok(!events.some((e) => e.type === 'replace'));
  assert.equal(textSeen(events), result.message);
});

test('deadlineAfterStreamedTextReplacesWithBusy', async () => {
  const config = { ...CONFIG, budget: { ...CONFIG.budget, turnTimeoutMs: 300 } } as GraphConfig;
  const { result, events, texts } = await streamed([searchCall, { hangAfter: 'Shop có Áo Thun. Bạn xem nhé. ' }], shirtSearch, {}, config);
  assert.equal(result.message, BUSY);
  assert.ok(texts.some((e) => e.type === 'delta'), 'text was streamed before the stall');
  assert.deepEqual(texts.at(-1), { type: 'replace', text: BUSY });
  assert.equal(textSeen(events), BUSY);
});

test('nonStreamingTurnEmitsNoTextBeforeTheEnd', async () => {
  const { result, events, during } = await streamed([searchCall, ai('Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem nhé. Còn size M.')], shirtSearch, { stream: false });
  assert.ok(during.every((d) => !d));
  assert.equal(textSeen(events), result.message);
});

test('closingTextEventsCases', () => {
  assert.deepEqual(closingTextEvents('', 'A. B.'), [{ type: 'delta', text: 'A. ' }, { type: 'delta', text: 'B.' }]);
  assert.deepEqual(closingTextEvents('A.', 'A. B. nudge'), [{ type: 'delta', text: ' B. ' }, { type: 'delta', text: 'nudge' }]);
  assert.deepEqual(closingTextEvents('A.', 'A.'), []);
  assert.deepEqual(closingTextEvents('A.', 'X.'), [{ type: 'replace', text: 'X.' }]);
});
