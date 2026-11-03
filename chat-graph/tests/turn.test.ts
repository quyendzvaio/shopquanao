import assert from 'node:assert/strict';
import test from 'node:test';
import { tool } from '@langchain/core/tools';
import { z } from 'zod/v4';
import type { ShopTools } from '../src/shopTools.js';
import type { GraphConfig } from '../src/config.js';
import type { TurnEvent } from '../src/contract.js';
import { BUSY } from '../src/copy.js';
import { runTurn } from '../src/turn.js';
import { ScriptedChatModel } from './support/scriptedModel.js';
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
