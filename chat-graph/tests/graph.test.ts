import assert from 'node:assert/strict';
import test from 'node:test';
import { AIMessage, ToolMessage, type BaseMessage } from '@langchain/core/messages';
import type { GraphConfig } from '../src/config.js';
import type { TurnRequest } from '../src/contract.js';
import { askAlternatives, nudgeText, soldOutNote, soldOutNoAlternatives, checkoutRefusal } from '../src/copy.js';
import { TurnBudget } from '../src/policies/budget.js';
import { summaryFromCards } from '../src/policies/grounding.js';
import { productToCard } from '../src/policies/cards.js';
import { buildTurnGraph } from '../src/graph/buildGraph.js';
import { soldOutFrom } from '../src/graph/soldOut.js';
import { ScriptedChatModel, type ScriptedStep } from './support/scriptedModel.js';
import { fakeShopTools } from './support/fakeTools.js';

const BUDGET: GraphConfig['budget'] = { maxToolCalls: 4, maxModelCalls: 5, turnTimeoutMs: 45000 };
const CONFIG = { budget: BUDGET, freeship: { threshold: 500000, maxGap: 200000 } } as GraphConfig;

type Handlers = Parameters<typeof fakeShopTools>[0];

function req(over: Partial<TurnRequest> = {}): TurnRequest {
  return {
    message: 'quần baggy còn hàng không',
    user_id: null,
    session_id: 1,
    history: [],
    memory: { summary: '', slots: {}, profile: null },
    freeship_fingerprint: null,
    stream: false,
    ...over,
  };
}

const call = (id: string, name: string, args: Record<string, unknown>) => ({ id, name, args, type: 'tool_call' as const });
const ai = (text: string, calls: ReturnType<typeof call>[] = []) => new AIMessage({ content: text, tool_calls: calls });

function run(steps: ScriptedStep[], handlers: Handlers, request: TurnRequest = req(), onProgress?: (s: string) => void) {
  const model = new ScriptedChatModel(steps);
  const tools = fakeShopTools(handlers);
  const graph = buildTurnGraph({ model, tools, budget: new TurnBudget(BUDGET), config: CONFIG, onProgress });
  return { model, tools, outcome: graph.invoke(request) };
}

function toolMessages(messages: BaseMessage[]): ToolMessage[] {
  return messages.filter((m): m is ToolMessage => ToolMessage.isInstance(m));
}

const baggy = { id: 69, name: 'Quần Baggy Vải Tuyết Mưa', price: 350000, stock: 0 };
const kaki = { id: 73, name: 'Quần Kaki Ống Rộng', price: 320000, stock: 5 };
const bo = { id: 71, name: 'Quần Bò Rách Gối', price: 410000, stock: 3 };
const similar = { source: { id: 69, name: baggy.name, category_id: 2, style_tags: [] }, products: [kaki, bo] };
const soldOutSearch = { products: [], out_of_stock_ids: [69], out_of_stock_matches: 1 };
const searchCall = ai('', [call('c1', 'search_products', { search: 'quần baggy', in_stock: true })]);

test('soldOutFrom: non-null only for search_products with a non-empty out_of_stock_ids', () => {
  const rec = (name: string, args: Record<string, unknown>, result: Record<string, unknown>) => ({ name, args, result });
  assert.deepEqual(soldOutFrom(rec('search_products', { search: 'quần baggy' }, soldOutSearch)), { ids: [69], search: 'quần baggy' });
  assert.equal(soldOutFrom(rec('search_products', { search: 'x' }, { products: [] })), null);
  assert.equal(soldOutFrom(rec('search_products', { search: 'x' }, { out_of_stock_ids: [] })), null);
  assert.equal(soldOutFrom(rec('get_product_detail', {}, { out_of_stock_ids: [1] })), null);
});

test('checkoutRefusedWithoutModelOrTools', async () => {
  const { model, tools, outcome } = run([], { search_products: () => ({ products: [] }) }, req({ message: 'thêm áo này vào giỏ' }));
  const out = await outcome;
  assert.equal(out.response_type, 'refusal');
  assert.equal(out.message, checkoutRefusal('thêm áo này vào giỏ'));
  assert.equal(model.calls.length, 0);
  assert.equal(tools.calls.length, 0);
  assert.deepEqual(out.cards, []);
});

test('soldOutBranchAsksAboutAlternatives', async () => {
  const { model, tools, outcome } = run(
    [searchCall, ai('')],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, 'Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng hoặc Quần Bò Rách Gối không?');
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.cards, []);
  assert.deepEqual(out.offered.map((o) => o.id), [73, 71]);
  const similarCall = tools.calls.find((c) => c.name === 'find_similar_in_stock');
  assert.deepEqual(similarCall?.args, { product_id: 69, limit: 2 });
  const seen = toolMessages(model.calls[1] ?? []).map((m) => String(m.content));
  assert.equal(seen.length, 1);
  assert.equal(seen[0], soldOutNote('Quần Baggy Vải Tuyết Mưa'));
  assert.ok(!seen[0]!.includes('out_of_stock'));
});

test('soldOutFiresWithoutInStockArg', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy' })]), ai('')],
    { search_products: () => ({ products: [baggy], out_of_stock_ids: [69] }), find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.cards, []);
  assert.deepEqual(out.offered.map((o) => o.id), [73, 71]);
});

test('multiIntentAnswersOtherPartThenAsks', async () => {
  const { outcome } = run(
    [
      ai('', [call('c1', 'search_products', { search: 'quần baggy', in_stock: true }), call('c2', 'suggest_size', { height: 160, weight: 50 })]),
      ai('Với 160cm, 50kg bạn mặc size S.'),
    ],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar, suggest_size: () => ({ size: 'S' }) },
  );
  const out = await outcome;
  assert.equal(
    out.message,
    'Với 160cm, 50kg bạn mặc size S. Còn mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng hoặc Quần Bò Rách Gối không?',
  );
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.tools_used.slice(0, 2), ['search_products', 'suggest_size']);
});

test('soldOutWithoutAlternatives', async () => {
  const { outcome } = run(
    [searchCall, ai('')],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => ({ source: similar.source, products: [] }) },
  );
  const out = await outcome;
  assert.equal(out.message, 'Shop có 1 mẫu quần baggy nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.');
  assert.equal(out.message, soldOutNoAlternatives(1, 'quần baggy'));
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.offered, []);
});

test('soldOutWhenFindSimilarFails: falls back to the search result name, then no-alternatives copy', async () => {
  const { model, outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy' })]), ai('')],
    {
      search_products: () => ({ products: [baggy], out_of_stock_ids: [69] }),
      find_similar_in_stock: () => { throw new Error('down'); },
    },
  );
  const out = await outcome;
  assert.equal(out.message, soldOutNoAlternatives(1, 'quần baggy'));
  assert.equal(String(toolMessages(model.calls[1] ?? [])[0]?.content), soldOutNote('Quần Baggy Vải Tuyết Mưa'));
});

test('search without out_of_stock_ids key never branches', async () => {
  const { tools, outcome } = run(
    [ai('', [call('c1', 'search_products', { search: '#66' })]), ai('Đây là mã 66.')],
    { search_products: () => ({ products: [{ id: 66, name: 'Áo Thun', price: 150000, stock: 3 }] }) },
  );
  const out = await outcome;
  assert.equal(out.response_type, 'final_answer');
  assert.equal(tools.calls.some((c) => c.name === 'find_similar_in_stock'), false);
  assert.deepEqual(out.cards.map((c) => c.id), [66]);
});

test('toolBudgetStopsFifthCall', async () => {
  const five = Array.from({ length: 5 }, (_, i) => call(`t${i}`, 'get_categories', { n: i }));
  const { model, tools, outcome } = run([ai('', five), ai('Xong.')], { get_categories: () => ({ categories: [] }) });
  await outcome;
  assert.equal(tools.calls.filter((c) => c.name === 'get_categories').length, 4);
  const seen = toolMessages(model.calls[1] ?? []);
  assert.equal(seen.length, 5);
  assert.equal(String(seen[4]?.content), 'Công cụ không được phép hoặc đã hết lượt gọi.');
});

test('disallowedToolIsNotRun', async () => {
  const { model, tools, outcome } = run(
    [ai('', [call('c1', 'add_to_cart', { product_id: 1 })]), ai('Mình không thể thêm giỏ hàng.')],
    { add_to_cart: () => ({ ok: true }), get_categories: () => ({ categories: [] }) },
  );
  await outcome;
  assert.equal(tools.calls.some((c) => c.name === 'add_to_cart'), false);
  assert.equal(String(toolMessages(model.calls[1] ?? [])[0]?.content), 'Công cụ không được phép hoặc đã hết lượt gọi.');
});

test('ungroundedAnswerIsRetriedThenSummarized', async () => {
  const product = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { model, outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai('Bạn xem mã 999 nhé.'), ai('Mã 999 rất đẹp.')],
    { search_products: () => ({ products: [product] }) },
  );
  const out = await outcome;
  assert.equal(out.message, summaryFromCards([productToCard(product)]));
  assert.equal(model.calls.length, 3);
  assert.deepEqual(out.cards.map((c) => c.id), [66]);
  const retry = model.calls[2]!;
  assert.match(String(retry[retry.length - 1]?.content), /Câu trả lời có thông tin không có trong kết quả công cụ: mã 999/);
});

test('cardsComeFromToolResultsOnly', async () => {
  const product = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai('Mã 66 giá 99.000đ.'), ai('Mã 66 giá 150.000đ.')],
    { search_products: () => ({ products: [product] }) },
  );
  const out = await outcome;
  assert.equal(out.cards[0]?.price, 150000);
  assert.equal(out.message, 'Mã 66 giá 150.000đ.');
});

test('empty draft without records is BUSY', async () => {
  const { outcome } = run([ai('')], { get_categories: () => ({ categories: [] }) });
  const out = await outcome;
  assert.equal(out.response_type, 'busy');
  assert.equal(out.message, 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.');
});

const freeship = {
  status: 'gap', gap: 50000, threshold: 500000, cart_fingerprint: 'abc',
  products: [{ id: 90, name: 'Túi Xách Mini Thời Trang', price: 450000, stock: 4 }],
};
const shopping: Handlers = {
  search_products: () => ({ products: [{ id: 66, name: 'Áo Thun', price: 150000, stock: 3 }] }),
  suggest_freeship_items: () => freeship,
};
const shoppingSteps = (): ScriptedStep[] => [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai('Có mã 66.')];

test('noNudgeForGuest', async () => {
  const { tools, outcome } = run(shoppingSteps(), shopping, req({ user_id: null }));
  const out = await outcome;
  assert.equal(tools.calls.some((c) => c.name === 'suggest_freeship_items'), false);
  assert.equal(out.freeship_nudge, false);
  assert.equal(out.message, 'Có mã 66.');
});

test('nudgeOncePerFingerprint', async () => {
  const first = await run(shoppingSteps(), shopping, req({ user_id: 5, freeship_fingerprint: null })).outcome;
  assert.equal(first.freeship_nudge, true);
  assert.equal(first.freeship_fingerprint, 'abc');
  assert.equal(first.message, 'Có mã 66. ' + nudgeText(50000, 500000, { id: 90, name: 'Túi Xách Mini Thời Trang', price: 450000 }));
  assert.deepEqual(first.cards.map((c) => c.id), [66, 90]);

  const second = await run(shoppingSteps(), shopping, req({ user_id: 5, freeship_fingerprint: 'abc' })).outcome;
  assert.equal(second.freeship_nudge, false);
  assert.equal(second.message, 'Có mã 66.');
  assert.deepEqual(second.cards.map((c) => c.id), [66]);
});

test('nudge errors leave the turn unchanged; gap above max skips', async () => {
  const broken = await run(shoppingSteps(), { ...shopping, suggest_freeship_items: () => { throw new Error('x'); } }, req({ user_id: 5 })).outcome;
  assert.equal(broken.freeship_nudge, false);
  assert.equal(broken.message, 'Có mã 66.');
  const far = await run(shoppingSteps(), { ...shopping, suggest_freeship_items: () => ({ ...freeship, gap: 300000 }) }, req({ user_id: 5 })).outcome;
  assert.equal(far.freeship_nudge, false);
});

test('memoryBlockInPrompt', async () => {
  const { model, outcome } = run(
    [ai('Chào bạn.')],
    { get_categories: () => ({ categories: [] }) },
    req({ message: 'chào', memory: { summary: 'khách thích áo thun', slots: { height_cm: 160 }, profile: null }, history: [{ role: 'user', content: 'hi' }, { role: 'assistant', content: 'chào' }] }),
  );
  await outcome;
  const first = model.calls[0]!;
  const system = first[0]!.text;
  assert.match(system, /Thông tin đã biết về khách \(có thể đã cũ\)/);
  assert.match(system, /160/);
  assert.match(system, /Câu hiện tại của khách luôn được ưu tiên hơn thông tin đã biết\./);
  assert.match(system, /mã N/);
  assert.match(system, /in_stock/);
  assert.equal(first.length, 4);
});

test('onProgress reports tool names', async () => {
  const seen: string[] = [];
  const { outcome } = run(shoppingSteps(), shopping, req(), (s) => seen.push(s));
  await outcome;
  assert.deepEqual(seen, ['tool:search_products']);
});

test('askAlternatives copy is what the graph appends', () => {
  assert.equal(askAlternatives('A', [{ id: 1, name: 'B' }], true), 'Còn mẫu A đang hết hàng. Bạn có muốn xem B không?');
});

test('secondInvokeOnSameGraphStartsFresh', async () => {
  const model = new ScriptedChatModel([
    searchCall, ai(''),
    ai('', [call('c2', 'search_products', { search: 'áo' })]), ai('Có mã 66.'),
  ]);
  let sold = true;
  const tools = fakeShopTools({
    search_products: () => (sold ? soldOutSearch : { products: [{ id: 66, name: 'Áo Thun', price: 150000, stock: 3 }] }),
    find_similar_in_stock: () => similar,
  });
  const graph = buildTurnGraph({ model, tools, budget: () => new TurnBudget(BUDGET), config: CONFIG });
  const first = await graph.invoke(req());
  assert.equal(first.response_type, 'clarification');
  sold = false;
  const second = await graph.invoke(req({ message: 'áo thun' }));
  assert.equal(second.response_type, 'final_answer');
  assert.equal(second.message, 'Có mã 66.');
  assert.deepEqual(second.cards.map((c) => c.id), [66]);
  assert.deepEqual(second.offered, []);
  assert.deepEqual(second.tools_used, ['search_products']);
});

test('ungroundedSummaryNeverNamesSoldOutProduct', async () => {
  const shirt = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy' })]), ai('Bạn xem mã 999 nhé.'), ai('Mã 999 rất đẹp.')],
    { search_products: () => ({ products: [baggy, shirt], out_of_stock_ids: [69] }), find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  const summary = summaryFromCards([productToCard(shirt)]);
  assert.ok(out.message.startsWith(summary + ' '));
  assert.ok(!summary.includes(baggy.name));
  assert.ok(!out.message.slice(0, summary.length).includes(baggy.name));
  assert.deepEqual(out.cards.map((c) => c.id), [66]);
  assert.equal(out.response_type, 'clarification');
});

test('ungroundedSummaryWithOnlySoldOutProductIsJustTheAsk', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy' })]), ai('Mã 999.'), ai('Mã 999.')],
    { search_products: () => ({ products: [baggy], out_of_stock_ids: [69] }), find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, 'Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng hoặc Quần Bò Rách Gối không?');
});

test('nudgeKeepsTotalAtSixCards', async () => {
  const six = Array.from({ length: 6 }, (_, i) => ({ id: 10 + i, name: `Áo ${i}`, price: 100000, stock: 2 }));
  const out = await run(
    [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai('Có nhiều mẫu áo.')],
    { search_products: () => ({ products: six }), suggest_freeship_items: () => freeship },
    req({ user_id: 5 }),
  ).outcome;
  assert.equal(out.cards.length, 6);
  assert.ok(!out.cards.some((c) => c.id === 90));
  assert.equal(out.freeship_nudge, true);
  assert.ok(out.message.endsWith(nudgeText(50000, 500000, { id: 90, name: 'Túi Xách Mini Thời Trang', price: 450000 })));
});
