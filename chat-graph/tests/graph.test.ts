import assert from 'node:assert/strict';
import test from 'node:test';
import { AIMessage, ToolMessage, type BaseMessage } from '@langchain/core/messages';
import type { GraphConfig } from '../src/config.js';
import type { TurnRequest } from '../src/contract.js';
import { BUSY, TICKET_CONFIRM_QUESTION, TICKET_NEED_DETAILS, askAlternatives, nudgeText, soldOutNote, soldOutNoAlternatives, checkoutRefusal, ticketDraftLine } from '../src/copy.js';
import { TurnBudget } from '../src/policies/budget.js';
import { summaryFromCards } from '../src/policies/grounding.js';
import { productToCard } from '../src/policies/cards.js';
import { buildTurnGraph } from '../src/graph/buildGraph.js';
import { EMPTY_ANSWER_CORRECTION, NO_MORE_TOOLS, REPEATED_TOOL_CALL, TICKET_NO_DRAFT_NOTE, TOOL_MARKUP_CORRECTION } from '../src/graph/middleware.js';
import { answersSoldOut, soldOutFrom } from '../src/graph/soldOut.js';
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
  assert.equal(seen[0], soldOutNote('Quần Baggy Vải Tuyết Mưa', [{ id: 73, name: kaki.name, price: 320000 }, { id: 71, name: bo.name, price: 410000 }]));
  assert.ok(!seen[0]!.includes('out_of_stock'));
});

const fullSoldOutAnswer = 'Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng (mã 73) giá 320.000đ hoặc Quần Bò Rách Gối không?';

test('soldOutAnswerWrittenByTheModelIsKeptWithoutTheFixedQuestion', async () => {
  const { model, outcome } = run(
    [searchCall, ai(fullSoldOutAnswer)],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, fullSoldOutAnswer);
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.offered.map((o) => o.id), [73, 71]);
  assert.deepEqual(out.cards, [], 'neither the sold-out item nor the alternatives get a card');
  assert.equal(model.calls.length, 2, 'no retry: the alternative price is grounded by the find_similar result');
});

test('soldOutAnswerCheckIgnoresMarkdownAndSpacing', () => {
  const so = { source: { id: 69, name: baggy.name }, alternatives: [{ id: 73, name: kaki.name }], count: 1, search: 'quần baggy', ids: [69], similar };
  assert.ok(answersSoldOut('Quần **Baggy Vải Tuyết Mưa** hiện đang **hết hàng**. Xem `mã\u202f73` nhé?', so));
  assert.ok(answersSoldOut('quần baggy vải  tuyết mưa đã hết, bạn xem **Quần Kaki Ống Rộng** không?', so));
  assert.ok(!answersSoldOut('Quần Baggy Vải Tuyết Mưa đang hết hàng.', so), 'no alternative named');
  assert.ok(!answersSoldOut('Bạn xem Quần Kaki Ống Rộng (mã 73) nhé, quần baggy hết hàng rồi.', so), 'sold-out item not named');
  assert.ok(!answersSoldOut('Quần Baggy Vải Tuyết Mưa rất đẹp, xem mã 73 nhé.', so), 'not said to be sold out');
});

test('multiIntentAnswerWrittenByTheModelKeepsEveryPart', async () => {
  const answer = 'Với 160cm, 50kg bạn mặc size S. Mẫu Quần Baggy Vải Tuyết Mưa thì đang hết hàng, bạn có muốn xem Quần Kaki Ống Rộng không?';
  const { outcome } = run(
    [
      ai('', [call('c1', 'search_products', { search: 'quần baggy', in_stock: true }), call('c2', 'suggest_size', { height: 160, weight: 50 })]),
      ai(answer),
    ],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar, suggest_size: () => ({ size: 'S' }) },
  );
  const out = await outcome;
  assert.equal(out.message, answer);
  assert.equal(out.response_type, 'clarification');
});

test('soldOutAnswerMissingTheNewsIsRetriedOnce', async () => {
  const { model, outcome } = run(
    [searchCall, ai('Shop có nhiều mẫu quần đẹp lắm.'), ai(fullSoldOutAnswer)],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, fullSoldOutAnswer);
  assert.equal(out.response_type, 'clarification');
  assert.equal(model.calls.length, 3);
  const correction = String(model.calls[2]?.at(-1)?.content);
  assert.ok(correction.includes('Quần Baggy Vải Tuyết Mưa đang hết hàng'), correction);
});

test('soldOutRetryStillMissingFallsBackToTheFixedQuestion', async () => {
  const { outcome } = run(
    [searchCall, ai('Shop có nhiều mẫu quần đẹp lắm.'), ai('Shop có nhiều mẫu quần đẹp lắm.')],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, 'Shop có nhiều mẫu quần đẹp lắm. ' + askAlternatives(baggy.name, [kaki, bo], true));
  assert.equal(out.response_type, 'clarification');
});

test('soldOutRetryErrorFallsBackToTheFixedQuestionNotBusy', async () => {
  const { outcome } = run(
    [searchCall, ai(''), new Error('429')],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, askAlternatives(baggy.name, [kaki, bo], false));
  assert.equal(out.response_type, 'clarification');
});

test('a sold-out answer that skipped another part goes back to the model with its tools', async () => {
  const answer = 'Với 160cm, 50kg bạn mặc size S. Còn Quần Baggy Vải Tuyết Mưa đang hết hàng, bạn xem Quần Kaki Ống Rộng nhé?';
  const { model, outcome } = run(
    [searchCall, ai(''), ai('', [call('c2', 'suggest_size', { height: 160, weight: 50 })]), ai(answer)],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar, suggest_size: () => ({ size: 'S' }) },
    req({ message: 'quần baggy còn hàng không, mình cao 160 nặng 50 mặc size gì' }),
  );
  const out = await outcome;
  assert.equal(out.message, answer);
  assert.equal(out.response_type, 'clarification');
  assert.ok(out.tools_used.includes('suggest_size'));
  assert.equal(model.toolCounts[2], 3, 'the continuation step still has every tool (the 3 fakes)');
  assert.ok(String(model.calls[2]?.at(-1)?.content).includes('Gọi thêm công cụ'));
  assert.equal(model.calls.length, 4);
});

test('an empty answer goes back to the model once, then the normal fallback applies', async () => {
  const shirt = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const first = run(
    [ai('', [call('c1', 'search_products', { search: 'áo thun' })]), ai(''), ai('Có Áo Thun (mã 66) giá 150.000đ.')],
    { search_products: () => ({ products: [shirt] }) },
  );
  const answered = await first.outcome;
  assert.equal(answered.message, 'Có Áo Thun (mã 66) giá 150.000đ.');
  assert.equal(String(first.model.calls[2]?.at(-1)?.content), EMPTY_ANSWER_CORRECTION);

  const twice = run(
    [ai('', [call('c1', 'search_products', { search: 'áo thun' })]), ai(''), ai('')],
    { search_products: () => ({ products: [shirt] }) },
  );
  const out = await twice.outcome;
  assert.equal(twice.model.calls.length, 3, 'only one continuation per turn');
  assert.equal(out.message, summaryFromCards([productToCard(shirt)]));
});

test('a failed continuation step keeps the answer already written', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy', in_stock: true }), call('c2', 'suggest_size', { height: 160, weight: 50 })]),
      ai('Với 160cm, 50kg bạn mặc size S.'), new Error('429'), new Error('429')],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar, suggest_size: () => ({ size: 'S' }) },
  );
  const out = await outcome;
  assert.equal(out.message, 'Với 160cm, 50kg bạn mặc size S. ' + askAlternatives(baggy.name, [kaki, bo], true));
  assert.equal(out.response_type, 'clarification');
});

test('answers reach the customer without markdown markers', async () => {
  const shirt = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'áo thun' })]), ai('### Gợi ý\n* **Áo Thun** (mã **66**) – `150.000đ`')],
    { search_products: () => ({ products: [shirt] }) },
  );
  const out = await outcome;
  assert.equal(out.message, 'Gợi ý\n- Áo Thun (mã 66) – 150.000đ');
  assert.deepEqual(out.cards.map((c) => c.id), [66]);
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
  assert.equal(String(toolMessages(model.calls[1] ?? [])[0]?.content), soldOutNote('Quần Baggy Vải Tuyết Mưa', []));
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
    searchCall, ai(''), ai(''), ai(''), // empty sold-out draft: one continuation step, one policy_out retry, then the fixed question
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

const MARKUP = 'Để mình kiểm tra ngay nhé!<tool_call>getg_product';

test('toolCallMarkupDraftGetsOneContinuationWithToolsAndCleanTextWins', async () => {
  const product = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { model, outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai(MARKUP), ai('Mình tìm được Áo Thun mã 66 giá 150.000đ.')],
    { search_products: () => ({ products: [product] }) },
  );
  const out = await outcome;
  assert.equal(out.message, 'Mình tìm được Áo Thun mã 66 giá 150.000đ.');
  assert.equal(out.response_type, 'final_answer');
  assert.equal(model.calls.length, 3);
  assert.equal(String(model.calls[2]?.at(-1)?.content), TOOL_MARKUP_CORRECTION);
  assert.ok((model.toolCounts[2] ?? 0) > 0, 'the continuation step still has its tools');
});

test('toolCallMarkupStillPresentAfterRetryFallsBackToCardSummary', async () => {
  const product = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { model, outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai(MARKUP), ai(MARKUP), ai('<|tool_call_begin|>search_products')],
    { search_products: () => ({ products: [product] }) },
  );
  const out = await outcome;
  assert.equal(out.message, summaryFromCards([productToCard(product)]));
  assert.ok(!/<\s*[|｜]?\s*tool/i.test(out.message));
  assert.equal(out.response_type, 'final_answer');
  assert.equal(model.calls.length, 4);
  assert.deepEqual(out.cards.map((c) => c.id), [66]);
});

test('toolCallMarkupWithoutToolsAndStillMalformedIsBusy', async () => {
  const { model, outcome } = run([ai(MARKUP), ai(MARKUP), ai(MARKUP)], { get_categories: () => ({ categories: [] }) });
  const out = await outcome;
  assert.equal(out.response_type, 'busy');
  assert.equal(out.message, BUSY);
  assert.equal(model.calls.length, 3);
});

test('toolCallMarkupAndUngroundedClaimShareTheSingleRetry', async () => {
  const product = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { model, outcome } = run(
    // The agent's continuation step writes the same markup, so policy_out's retry gets both corrections at once.
    [ai('', [call('c1', 'search_products', { search: 'áo' })]), ai(`${MARKUP} mã 999`), ai(`${MARKUP} mã 999`), ai('Mã 999 vẫn sai.')],
    { search_products: () => ({ products: [product] }) },
  );
  const out = await outcome;
  assert.equal(model.calls.length, 4);
  const retry = String(model.calls[3]?.at(-1)?.content);
  assert.match(retry, /tool-call/);
  assert.match(retry, /mã 999/);
  assert.equal(out.message, summaryFromCards([productToCard(product)]));
});

test('toolCallMarkupWithOnlySoldOutProductIsJustTheAsk', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy' })]), ai(MARKUP), ai(MARKUP)],
    { search_products: () => ({ products: [baggy], out_of_stock_ids: [69] }), find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(out.message, 'Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng hoặc Quần Bò Rách Gối không?');
});

const detail83 = { id: 83, name: 'Váy Hai Dây Lụa Satin', price: 390000, stock: 0 };
const detailCall = ai('', [call('c1', 'get_product_detail', { product_id: 83 })]);
const similar83 = { source: { id: 83, name: detail83.name, category_id: 3, style_tags: [] }, products: [kaki, bo] };

test('soldOutProductDetailTriggersTheSoldOutAsk', async () => {
  const { tools, outcome } = run(
    [detailCall, ai('')],
    { get_product_detail: () => ({ product: detail83 }), find_similar_in_stock: () => similar83 },
  );
  const out = await outcome;
  assert.equal(out.response_type, 'clarification');
  assert.equal(out.message, 'Mẫu Váy Hai Dây Lụa Satin đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng hoặc Quần Bò Rách Gối không?');
  assert.deepEqual(out.offered.map((o) => o.id), [73, 71]);
  assert.ok(!out.cards.some((c) => c.id === 83));
  assert.deepEqual(out.cards, []);
  assert.ok(out.tools_used.includes('find_similar_in_stock'));
  assert.deepEqual(tools.calls.find((c) => c.name === 'find_similar_in_stock')?.args, { product_id: 83, limit: 2 });
});

test('inStockProductDetailStaysAFinalAnswerWithItsCard', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'get_product_detail', { product_id: 73 })]), ai('Quần Kaki Ống Rộng (mã 73) còn hàng, giá 320.000đ.')],
    { get_product_detail: () => ({ product: kaki }) },
  );
  const out = await outcome;
  assert.equal(out.response_type, 'final_answer');
  assert.deepEqual(out.cards.map((c) => c.id), [73]);
  assert.deepEqual(out.offered, []);
});

test('soldOutFrom: get_product_detail with stock <= 0 is sold out, in-stock or malformed is not', () => {
  const rec = (result: Record<string, unknown>) => ({ name: 'get_product_detail', args: { product_id: 83 }, result });
  assert.deepEqual(soldOutFrom(rec({ product: detail83 })), { ids: [83], search: '' });
  assert.deepEqual(soldOutFrom(rec({ product: { id: 83, stock: '0' } })), { ids: [83], search: '' });
  assert.equal(soldOutFrom(rec({ product: kaki })), null);
  assert.equal(soldOutFrom(rec({ error: 'Product not found' })), null);
  assert.equal(soldOutFrom(rec({ product: { name: 'x', stock: 0 } })), null);
});

function runWith(budget: GraphConfig['budget'], steps: ScriptedStep[], handlers: Handlers, request: TurnRequest = req()) {
  const model = new ScriptedChatModel(steps);
  const tools = fakeShopTools(handlers);
  const graph = buildTurnGraph({ model, tools, budget: new TurnBudget(budget), config: { ...CONFIG, budget } });
  return { model, tools, outcome: graph.invoke(request) };
}

const catHandlers = { get_categories: () => ({ categories: [{ id: 1, name: 'Áo' }] }), get_order_status: () => ({ orders: [] }) };
const catCall = (id: string) => ai('', [call(id, 'get_categories', {})]);

test('lastAllowedModelCallGetsNoToolsSoItMustAnswer', async () => {
  const { model, outcome } = runWith(
    { maxToolCalls: 8, maxModelCalls: 3, turnTimeoutMs: 45000 },
    [catCall('c1'), catCall('c2'), ai('Shop có nhóm Áo.')],
    catHandlers,
  );
  const out = await outcome;
  assert.deepEqual(model.toolCounts, [2, 2, 0]);
  assert.equal(out.message, 'Shop có nhóm Áo.');
  assert.equal(out.response_type, 'final_answer');
});

test('theLastModelCallIsToldToAnswerAndAToolCallItWritesAnywayIsDropped', async () => {
  const { model, tools, outcome } = runWith(
    { maxToolCalls: 8, maxModelCalls: 2, turnTimeoutMs: 45000 },
    [catCall('c1'), ai('Shop có nhóm Áo.', [call('c2', 'get_categories', { page: 2 })])],
    catHandlers,
  );
  const out = await outcome;
  assert.equal(tools.calls.length, 1, 'the dropped tool call never runs');
  assert.equal(model.calls[1]?.at(-1)?.content, NO_MORE_TOOLS);
  assert.equal(out.message, 'Shop có nhóm Áo.');
  assert.equal(out.response_type, 'final_answer');
});

test('aRepeatedToolCallIsNotRunAgainAndDoesNotSpendTheBudget', async () => {
  const search = (id: string, args: Record<string, unknown>) => ai('', [call(id, 'search_products', args)]);
  const { model, tools, outcome } = runWith(
    { maxToolCalls: 2, maxModelCalls: 5, turnTimeoutMs: 45000 },
    [
      search('c1', { max_price: 500000, in_stock: true }),
      search('c2', { in_stock: true, max_price: 500000, color: null }),
      search('c3', { search: 'áo', max_price: 500000 }),
      ai('Có Áo Thun (mã 1).'),
    ],
    { search_products: () => ({ products: [{ id: 1, name: 'Áo Thun', price: 100000, stock: 3 }] }) },
  );
  const out = await outcome;
  assert.equal(tools.calls.length, 2, 'c1 and c3 run; c2 repeats c1');
  const repeated = toolMessages(model.calls[2] ?? []).find((m) => m.tool_call_id === 'c2');
  assert.equal(repeated?.content, REPEATED_TOOL_CALL);
  assert.deepEqual(model.toolCounts, [1, 1, 1, 0], 'the repeat left a tool call for c3; then the tool budget is spent');
  assert.equal(out.message, 'Có Áo Thun (mã 1).');
});

test('modelCallAfterTheToolBudgetIsSpentGetsNoTools', async () => {
  const { model, outcome } = runWith(
    { maxToolCalls: 1, maxModelCalls: 5, turnTimeoutMs: 45000 },
    [catCall('c1'), ai('Shop có nhóm Áo.')],
    catHandlers,
  );
  const out = await outcome;
  assert.deepEqual(model.toolCounts, [2, 0]);
  assert.equal(out.message, 'Shop có nhóm Áo.');
});

test('firstModelCallStillGetsEveryTool', async () => {
  const { model, outcome } = run([ai('Xin chào!')], catHandlers);
  await outcome;
  assert.deepEqual(model.toolCounts, [2]);
});

test('modelCallBeyondTheBudgetStillStopsWithAnEmptyMessage', async () => {
  const { model, outcome } = runWith(
    { maxToolCalls: 8, maxModelCalls: 1, turnTimeoutMs: 45000 },
    [catCall('c1')],
    catHandlers,
  );
  const out = await outcome;
  assert.equal(model.calls.length, 1);
  assert.equal(out.response_type, 'busy');
});

const ticketDraft = { type: 'doi_tra', order_id: 12, summary: 'Nhận size M thay vì L' };
const draftResult = { status: 'draft', draft: ticketDraft };
const draftCall = (id = 't1') => ai('', [call(id, 'draft_support_ticket', { type: 'doi_tra', order_id: 12, summary: ticketDraft.summary })]);
const ticketReq = () => req({ message: 'tôi muốn đổi size đơn 12', user_id: 7 });
const ticketQuestionAnswer = `Mình sẽ gửi yêu cầu đổi size cho đơn #12. ${TICKET_CONFIRM_QUESTION}`;
const countOf = (text: string, part: string) => text.split(part).length - 1;

test('aValidDraftSetsPendingTicketAndAsksToConfirm', async () => {
  const { outcome } = run([draftCall(), ai(ticketQuestionAnswer)], { draft_support_ticket: () => draftResult }, ticketReq());
  const out = await outcome;
  assert.deepEqual(out.pending_ticket, ticketDraft);
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.cards, []);
  assert.equal(countOf(out.message, TICKET_CONFIRM_QUESTION), 1);
  assert.equal(out.message, `Mình sẽ gửi yêu cầu đổi size cho đơn #12.\n${ticketDraftLine(ticketDraft)} ${TICKET_CONFIRM_QUESTION}`);
  assert.equal(out.freeship_nudge, false);
});

test('theConfirmationQuestionIsAppendedWhenTheModelLeavesItOut', async () => {
  const { outcome } = run(
    [draftCall(), ai('Mình sẽ gửi yêu cầu đổi size cho đơn #12.')],
    { draft_support_ticket: () => draftResult },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(out.message, `Mình sẽ gửi yêu cầu đổi size cho đơn #12.\n${ticketDraftLine(ticketDraft)} ${TICKET_CONFIRM_QUESTION}`);
  assert.deepEqual(out.pending_ticket, ticketDraft);
});

test('aShortModelAnswerOnADraftTurnStillShowsTheTypeOrderAndSummary', async () => {
  const { outcome } = run([draftCall(), ai('Ok.')], { draft_support_ticket: () => draftResult }, ticketReq());
  const out = await outcome;
  assert.ok(out.message.includes('Đổi size / đổi trả'));
  assert.ok(out.message.includes('đơn #12'));
  assert.ok(out.message.includes('Nhận size M thay vì L'));
  assert.ok(out.message.endsWith(TICKET_CONFIRM_QUESTION));
  assert.equal(countOf(out.message, TICKET_CONFIRM_QUESTION), 1);
  assert.deepEqual(out.pending_ticket, ticketDraft);
});

test('aModelThatAlreadyWroteTheQuestionDoesNotRepeatIt', async () => {
  const { outcome } = run([draftCall(), ai(`Ok. ${TICKET_CONFIRM_QUESTION}`)], { draft_support_ticket: () => draftResult }, ticketReq());
  const out = await outcome;
  assert.equal(countOf(out.message, TICKET_CONFIRM_QUESTION), 1);
  assert.ok(out.message.endsWith(TICKET_CONFIRM_QUESTION));
  assert.ok(out.message.includes(ticketDraftLine(ticketDraft)));
});

test('anEmptyAnswerOnADraftTurnGetsTheDraftLineAndTheQuestion', async () => {
  const { outcome } = run([draftCall(), ai(''), ai('')], { draft_support_ticket: () => draftResult }, ticketReq());
  const out = await outcome;
  assert.equal(ticketDraftLine(ticketDraft), 'Mình sẽ gửi yêu cầu sau cho shop: Đổi size / đổi trả, đơn #12: Nhận size M thay vì L.');
  assert.equal(out.message, `${ticketDraftLine(ticketDraft)} ${TICKET_CONFIRM_QUESTION}`);
  assert.equal(out.response_type, 'clarification');
  assert.deepEqual(out.pending_ticket, ticketDraft);
  assert.equal(ticketDraftLine({ type: 'khac', order_id: null, summary: 'Hỏi thêm' }), 'Mình sẽ gửi yêu cầu sau cho shop: Khác: Hỏi thêm.');
});

test('refusedDraftsSetNoPendingTicket', async () => {
  for (const status of ['requires_login', 'duplicate_open', 'order_required']) {
    const { outcome } = run(
      [draftCall(), ai('Mình chưa tạo được yêu cầu lúc này.')],
      { draft_support_ticket: () => ({ status, message: 'Không thể tạo yêu cầu.' }) },
      ticketReq(),
    );
    const out = await outcome;
    assert.equal(out.pending_ticket, null, status);
    assert.equal(out.response_type, 'final_answer', status);
    assert.ok(!out.message.includes(TICKET_CONFIRM_QUESTION), status);
  }
});

test('theLastValidDraftOfTheTurnWins', async () => {
  const second = { type: 'hang_loi', order_id: 12, summary: 'Áo bị rách' };
  let n = 0;
  const { outcome } = run(
    [draftCall('t1'), ai('', [call('t2', 'draft_support_ticket', { type: 'hang_loi', order_id: 12, summary: second.summary })]), ai('Mình sẽ gửi yêu cầu báo hàng lỗi.')],
    { draft_support_ticket: () => ({ status: 'draft', draft: n++ === 0 ? ticketDraft : second }) },
    ticketReq(),
  );
  const out = await outcome;
  assert.deepEqual(out.pending_ticket, second);
  assert.ok(out.message.endsWith(TICKET_CONFIRM_QUESTION));
});

test('aSoldOutTurnSetsNoPendingTicket', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'quần baggy', in_stock: true }), call('t1', 'draft_support_ticket', { type: 'doi_tra', order_id: 12, summary: ticketDraft.summary })]), ai('')],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar, draft_support_ticket: () => draftResult },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(out.pending_ticket, null);
  assert.equal(out.response_type, 'clarification');
  assert.ok(!out.message.includes(TICKET_CONFIRM_QUESTION));
  assert.deepEqual(out.offered.map((o) => o.id), [73, 71]);
});

test('aTurnWithoutADraftHasNullPendingTicket', async () => {
  const { outcome } = run(
    [ai('', [call('c1', 'get_support_tickets', {})]), ai('Bạn chưa có yêu cầu nào.')],
    { get_support_tickets: () => ({ tickets: [] }) },
    ticketReq(),
  );
  assert.equal((await outcome).pending_ticket, null);
});

test('anUngroundedDraftAnswerIsReplacedByTheDraftLine', async () => {
  const bad = 'Phí đổi là 99.000đ. Mình sẽ gửi yêu cầu cho đơn #12.';
  const { outcome } = run([draftCall(), ai(bad), ai('Phí đổi chỉ 88.000đ nhé.')], { draft_support_ticket: () => draftResult }, ticketReq());
  const out = await outcome;
  assert.equal(out.message, `${ticketDraftLine(ticketDraft)} ${TICKET_CONFIRM_QUESTION}`);
  assert.deepEqual(out.pending_ticket, ticketDraft);
  assert.equal(out.response_type, 'clarification');
});

test('anUngroundedDraftAnswerNeverBecomesACardSummaryEvenWhenAProductToolRan', async () => {
  const shirt = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
  const { outcome } = run(
    [ai('', [call('c1', 'search_products', { search: 'áo thun' }), call('t1', 'draft_support_ticket', { type: 'doi_tra', order_id: 12, summary: ticketDraft.summary })]),
      ai('Phí đổi là 99.000đ.'), ai('Phí đổi chỉ 88.000đ nhé.')],
    { search_products: () => ({ products: [shirt] }), draft_support_ticket: () => draftResult },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(out.message, `${ticketDraftLine(ticketDraft)} ${TICKET_CONFIRM_QUESTION}`);
  assert.ok(!out.message.includes('thẻ sản phẩm'));
  assert.deepEqual(out.cards, []);
});

test('aDraftWithoutAPositiveIntegerOrderIdIsNoDraft', async () => {
  for (const order_id of [0, -3, 1.5, 'abc']) {
    const { outcome } = run([draftCall(), ai('Ok.')], { draft_support_ticket: () => ({ status: 'draft', draft: { ...ticketDraft, order_id } }) }, ticketReq());
    const out = await outcome;
    assert.equal(out.pending_ticket, null, String(order_id));
    assert.equal(out.response_type, 'final_answer', String(order_id));
  }
});

// A model-written confirmation question without a draft: the shop system never saw a draft, so a "yes" would file nothing.
const orderCall = () => ai('', [call('o1', 'get_order_status', { order_id: 12 })]);
const orderResult = { orders: [{ id: 12, status: 'Đã giao' }] };
const ownQuestion = 'Bạn có đồng ý để mình gửi yêu cầu đổi trả cho đơn #12 không?';

// DeepSeek writing draft_support_ticket as text (2026-11-04 ticket eval): the call never ran, so no draft existed.
const DSML_CALL = '<｜｜DSML｜｜ calls> <｜｜DSML｜｜ invoke name="draft_support_ticket"> <｜｜DSML｜｜ parameter name="order_id" string="false">12</｜｜DSML｜｜ parameter>';

test('aToolCallWrittenAsTextGetsOneContinuationWithToolsThenTheRealDraft', async () => {
  const { model, outcome } = run(
    [orderCall(), ai(DSML_CALL), draftCall(), ai('Mình đã soạn yêu cầu đổi size cho đơn #12.')],
    { get_order_status: () => orderResult, draft_support_ticket: () => draftResult },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(model.calls.length, 4);
  assert.equal(String(model.calls[2]?.at(-1)?.content), TOOL_MARKUP_CORRECTION);
  assert.ok((model.toolCounts[2] ?? 0) > 0, 'the continuation step still has its tools');
  assert.deepEqual(out.pending_ticket, ticketDraft);
  assert.equal(out.response_type, 'clarification');
  assert.ok(!out.message.includes('DSML'));
});

test('aModelWrittenConfirmationWithoutADraftGetsOneContinuationThenTheRealDraft', async () => {
  const { model, outcome } = run(
    [orderCall(), ai(ownQuestion), draftCall(), ai('Mình đã soạn yêu cầu đổi size cho đơn #12.')],
    { get_order_status: () => orderResult, draft_support_ticket: () => draftResult },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(model.calls.length, 4);
  assert.equal(String(model.calls[2]?.at(-1)?.content), TICKET_NO_DRAFT_NOTE);
  assert.ok((model.toolCounts[2] ?? 0) > 0, 'the continuation step still has its tools');
  assert.deepEqual(out.pending_ticket, ticketDraft);
  assert.equal(out.response_type, 'clarification');
  assert.equal(out.message, `Mình đã soạn yêu cầu đổi size cho đơn #12.\n${ticketDraftLine(ticketDraft)} ${TICKET_CONFIRM_QUESTION}`);
  assert.ok(!out.message.includes(ownQuestion));
});

test('aModelThatAsksItsOwnConfirmationAgainAfterTheContinuationIsDroppedToTheFixedCopy', async () => {
  const { model, outcome } = run(
    [orderCall(), ai(ownQuestion), ai(ownQuestion)],
    { get_order_status: () => orderResult, draft_support_ticket: () => draftResult },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(model.calls.length, 3, 'one continuation only');
  assert.equal(out.message, TICKET_NEED_DETAILS);
  assert.equal(out.pending_ticket, null);
  assert.equal(out.response_type, 'final_answer');
  assert.deepEqual(out.cards, []);

  const partial = run(
    [orderCall(), ai('Đơn #12 đã giao. Bạn xác nhận gửi yêu cầu này cho shop không?'), ai('Đơn #12 đã giao. Bạn xác nhận gửi yêu cầu này cho shop không?')],
    { get_order_status: () => orderResult },
    ticketReq(),
  );
  const kept = await partial.outcome;
  assert.equal(kept.message, 'Đơn #12 đã giao.');
  assert.equal(kept.pending_ticket, null);
});

test('theOfferQuestionPassesUntouchedWithNoExtraModelCall', async () => {
  const offer = 'Đơn #12 đã giao. Bạn có muốn mình tạo yêu cầu đổi trả không?';
  const { model, outcome } = run([orderCall(), ai(offer)], { get_order_status: () => orderResult }, ticketReq());
  const out = await outcome;
  assert.equal(model.calls.length, 2);
  assert.equal(out.message, offer);
  assert.equal(out.pending_ticket, null);
  assert.equal(out.response_type, 'final_answer');
});

test('aRealDraftTurnIsNotContinuedEvenWhenTheModelWritesTheConfirmationQuestion', async () => {
  const { model, outcome } = run([draftCall(), ai(ticketQuestionAnswer)], { draft_support_ticket: () => draftResult }, ticketReq());
  const out = await outcome;
  assert.equal(model.calls.length, 2);
  assert.deepEqual(out.pending_ticket, ticketDraft);
  assert.equal(countOf(out.message, TICKET_CONFIRM_QUESTION), 1);
});

test('theConfirmationGuardLeavesASoldOutTurnAlone', async () => {
  const answer = 'Quần Baggy Vải Tuyết Mưa đang hết hàng, bạn xem Quần Kaki Ống Rộng nhé? Bạn xác nhận gửi yêu cầu cho shop không?';
  const { model, outcome } = run(
    [searchCall, ai(answer)],
    { search_products: () => soldOutSearch, find_similar_in_stock: () => similar },
  );
  const out = await outcome;
  assert.equal(model.calls.length, 2, 'no continuation on a sold-out turn that already names the news');
  assert.equal(out.message, answer);
});

test('aRefusedDraftDoesNotCountAsADraftForTheGuard', async () => {
  const { model, outcome } = run(
    [draftCall(), ai(ownQuestion), ai(ownQuestion)],
    { draft_support_ticket: () => ({ status: 'duplicate_open', message: 'Đã có yêu cầu.', ticket_id: 9 }) },
    ticketReq(),
  );
  const out = await outcome;
  assert.equal(model.calls.length, 3);
  assert.equal(out.message, TICKET_NEED_DETAILS);
  assert.equal(out.pending_ticket, null);
});

test('theContinuationRespectsTheModelBudget', async () => {
  const tight = { maxToolCalls: 4, maxModelCalls: 2, turnTimeoutMs: 45000 };
  const model = new ScriptedChatModel([orderCall(), ai(ownQuestion)]);
  const graph = buildTurnGraph({ model, tools: fakeShopTools({ get_order_status: () => orderResult }), budget: new TurnBudget(tight), config: CONFIG });
  const out = await graph.invoke(ticketReq());
  assert.equal(model.calls.length, 2, 'no model call is left for a continuation');
  assert.equal(out.message, TICKET_NEED_DETAILS);
});
