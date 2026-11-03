import assert from 'node:assert/strict';
import test from 'node:test';
import { AIMessage, HumanMessage } from '@langchain/core/messages';
import { ScriptedChatModel } from './support/scriptedModel.js';
import { fakeShopTools } from './support/fakeTools.js';

test('ScriptedChatModel replays steps, records calls, throws Errors', async () => {
  const model = new ScriptedChatModel([new AIMessage('một'), new Error('boom')]);
  const first = await model.bindTools().invoke([new HumanMessage('hi')]);
  assert.equal(first.content, 'một');
  await assert.rejects(() => model.invoke([new HumanMessage('again')]), /boom/);
  assert.equal(model.calls.length, 2);
});

test('ScriptedChatModel hang is aborted by signal', async () => {
  const model = new ScriptedChatModel(['hang']);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 50);
  await assert.rejects(() => model.invoke([new HumanMessage('x')], { signal: ctrl.signal }));
  clearTimeout(timer);
});

test('fakeShopTools records calls from both entry points', async () => {
  const shop = fakeShopTools({ get_categories: () => ({ categories: [] }) });
  assert.deepEqual(await shop.call('get_categories', {}), { categories: [] });
  const out = await shop.tools[0]!.invoke({});
  assert.match(String(out), /categories/);
  assert.equal(shop.calls.length, 2);
  await assert.rejects(() => shop.call('nope', {}));
});
