import assert from 'node:assert/strict';
import test from 'node:test';
import type { BaseMessage } from '@langchain/core/messages';
import type { ChatResult } from '@langchain/core/outputs';
import type { GraphConfig } from '../src/config.js';
import type { TurnEvent } from '../src/contract.js';
import { BUSY } from '../src/copy.js';
import { runTurn } from '../src/turn.js';
import { ScriptedChatModel } from './support/scriptedModel.js';
import { CONFIG, req, searchTurn, setup } from './support/turnHarness.js';

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

class TrackedModel extends ScriptedChatModel {
  settled = false;
  override async _generate(messages: BaseMessage[], options: this['ParsedCallOptions']): Promise<ChatResult> {
    try {
      return await super._generate(messages, options);
    } finally {
      this.settled = true;
    }
  }
}

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
