import assert from 'node:assert/strict';
import test from 'node:test';
import type { ToolRecord } from '../src/contract.js';
import { ALLOWED_TOOLS, isAllowedTool } from '../src/policies/allowList.js';
import { TurnBudget } from '../src/policies/budget.js';
import { outfitsFrom, productToCard, selectCards, verifyCard } from '../src/policies/cards.js';
import { matchesCheckout } from '../src/policies/checkout.js';
import { hasToolCallMarkup, summaryFromCards, ungroundedClaims } from '../src/policies/grounding.js';

const product = (id: number, price = 100000, stock = 5, extra: Record<string, unknown> = {}) => ({
  id, name: `SP ${id}`, price, stock, image: `p${id}.jpg`, category_id: 1, ...extra,
});
const search = (ids: number[], args: Record<string, unknown> = {}, extra: Record<string, unknown> = {}): ToolRecord => ({
  name: 'search_products', args, result: { products: ids.map((i) => product(i)), ...extra },
});

test('checkoutMatchesLegacyPhrases', () => {
  for (const m of ['thêm vào giỏ', 'thêm áo này vào giỏ', 'Thêm áo này vào GIỎ hàng', 'thanh toán giúp mình', 'chốt đơn', 'đặt hàng giúp', 'them ao nay vao gio', 'checkout']) {
    assert.equal(matchesCheckout(m), true, m);
  }
  for (const m of ['áo phông còn hàng', 'phí ship bao nhiêu']) assert.equal(matchesCheckout(m), false, m);
});

test('checkout trailing word boundary is Unicode aware', () => {
  // PHP `(?:giỏ|gio)\b` + /u: "giỏi" continues with a letter, so the long alternative fails.
  assert.equal(matchesCheckout('thêm áo vào giỏi'), false);
  assert.equal(matchesCheckout('thêm áo vào giỏ.'), true);
});

test('allowListHasNineReadOnlyTools', () => {
  assert.equal(ALLOWED_TOOLS.length, 9);
  assert.equal(isAllowedTool('add_to_cart'), false);
  assert.equal(isAllowedTool('search_products'), true);
});

test('budgetStopsAtLimits', () => {
  let t = 1000;
  const b = new TurnBudget({ maxToolCalls: 4, maxModelCalls: 2, turnTimeoutMs: 500 }, () => t);
  for (let i = 0; i < 4; i++) assert.equal(b.takeToolCall(), true);
  assert.equal(b.takeToolCall(), false);
  assert.equal(b.takeModelCall(), true);
  assert.equal(b.takeModelCall(), true);
  assert.equal(b.takeModelCall(), false);
  assert.deepEqual(b.counts(), { tool_calls: 4, model_calls: 2 });
  assert.equal(b.expired(), false);
  assert.equal(b.remainingMs(), 500);
  t = 1600;
  assert.equal(b.expired(), true);
  assert.equal(b.remainingMs(), 0);
});

test('selectCardsPrefersMentionedIds', () => {
  const cards = selectCards('Gợi ý mã 67 và mã 66 nhé', [search([66, 72, 67])]);
  assert.deepEqual(cards.map((c) => c.id), [67, 66]);
});

test('selectCardsFallsBackToLastProductTool', () => {
  const records: ToolRecord[] = [
    search([1, 2]),
    { name: 'retrieve_knowledge', args: {}, result: { chunks: [] } },
    search([10, 11, 12, 13, 14, 15, 16, 17]),
    { name: 'get_categories', args: {}, result: { categories: [] } },
  ];
  assert.deepEqual(selectCards('Đây là gợi ý', records).map((c) => c.id), [10, 11, 12, 13, 14, 15]);
});

test('selectCardsFindsProductsInEveryShape', () => {
  const detail: ToolRecord = { name: 'get_product_detail', args: {}, result: { product: product(5) } };
  assert.deepEqual(selectCards('mã 5', [detail]).map((c) => c.id), [5]);
  const outfit: ToolRecord = {
    name: 'build_outfit', args: {},
    result: { outfits: [{ items: [product(7), product(8)] }], products: [product(7, 1, 1, { outfit_index: 1 })] },
  };
  assert.deepEqual(selectCards('mã 8', [outfit]).map((c) => c.id), [8]);
  const similar: ToolRecord = { name: 'find_similar_in_stock', args: {}, result: { source: product(1), products: [product(2)] } };
  assert.deepEqual(selectCards('không nhắc gì', [similar]).map((c) => c.id), [2]);
});

test('selectCardsDropsCardsFailingTheirArgs', () => {
  const rec: ToolRecord = {
    name: 'search_products', args: { in_stock: true },
    result: { products: [product(1, 100000, 0), product(2, 100000, 3)] },
  };
  assert.deepEqual(selectCards('hết', [rec]).map((c) => c.id), [2]);
});

test('selectCards ignores unknown mentioned ids and dedupes', () => {
  const cards = selectCards('mã 999, mã 3, mã 3', [search([3, 4])]);
  assert.deepEqual(cards.map((c) => c.id), [3]);
});

test('verifyCard rules', () => {
  const card = productToCard(product(1, 200000, 2));
  assert.equal(verifyCard(card, { in_stock: true }), true);
  assert.equal(verifyCard({ ...card, stock: 0 }, { in_stock: true }), false);
  assert.equal(verifyCard(card, { min_price: 300000 }), false);
  assert.equal(verifyCard(card, { max_price: 100000 }), false);
  assert.equal(verifyCard(card, { min_price: 100000, max_price: 200000 }), true);
  assert.equal(verifyCard(card, { category_id: 2 }), false);
  assert.equal(verifyCard(card, { category_id: 1 }), true);
});

test('productToCard normalises fields', () => {
  const card = productToCard(product(9, 1, 1, { available_sizes: ['M'], variants: [{ color: 'Đen' }] }), 2);
  assert.equal(card.url, '/product.php?id=9');
  assert.equal(card.image_url, '/assets/images/p9.jpg');
  assert.equal(card.outfit_index, 2);
  assert.deepEqual(card.available_sizes, ['M']);
});

test('outfitsFrom returns the last build_outfit outfits', () => {
  const a: ToolRecord = { name: 'build_outfit', args: {}, result: { outfits: [{ n: 1 }] } };
  const b: ToolRecord = { name: 'build_outfit', args: {}, result: { outfits: [{ n: 2 }] } };
  assert.deepEqual(outfitsFrom([a, search([1]), b]), [{ n: 2 }]);
  assert.deepEqual(outfitsFrom([search([1])]), []);
});

test('groundingFlagsInventedIdAndPrice', () => {
  assert.equal(ungroundedClaims('mã 999 giá 123.000đ', [search([1, 2])]).length, 2);
});

test('a sold-out name match a search hid is grounded but never a card', () => {
  const hidden = search([62], { search: 'mũ', in_stock: true }, {
    out_of_stock_products: [{ id: 91, name: 'Mũ Lưỡi Trai Thêu Logo', price: 190000 }],
  });
  assert.deepEqual(ungroundedClaims('Mũ Lưỡi Trai Thêu Logo (mã 91) giá 190.000đ đang hết hàng', [hidden]), []);
  assert.deepEqual(selectCards('mã 91 hết, còn mã 62', [hidden]).map((c) => c.id), [62]);
});

test('groundingAcceptsPriceSpellings', () => {
  const rec: ToolRecord = { name: 'search_products', args: {}, result: { products: [product(1, 490000)] } };
  for (const a of ['mã 1 giá 490.000đ', 'mã 1 giá 490k', 'mã 1 giá 490.000 đ', 'mã 1 giá 490,000 VND', 'mã 1 giá 490 nghìn']) {
    assert.deepEqual(ungroundedClaims(a, [rec]), [], a);
  }
});

test('grounding ignores unit-less numbers and letters after unit', () => {
  assert.deepEqual(ungroundedClaims('size 42 và 2kg đẹp', [search([1])]), []);
});

test('summaryFromCardsCopy', () => {
  const card = productToCard({ id: 73, name: 'Quần Kaki Ống Rộng Màu Xanh Rêu', price: 560000, stock: 1 });
  assert.equal(
    summaryFromCards([card]),
    'Mình tìm được các mẫu sau: Quần Kaki Ống Rộng Màu Xanh Rêu (mã 73) giá 560.000đ. Bạn bấm vào thẻ sản phẩm để xem chi tiết nhé.',
  );
  assert.equal(summaryFromCards([]), 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.');
});

const priced = (price: number): ToolRecord => ({ name: 'search_products', args: {}, result: { products: [product(1, price)] } });

test('grounding flags invented prices written with longer units', () => {
  assert.equal(ungroundedClaims('giá 123.000 đồng', [priced(560000)]).length, 1);
  assert.equal(ungroundedClaims('giá 490.000đồng', [priced(560000)]).length, 1);
  assert.equal(ungroundedClaims('giá 2 triệu', [priced(560000)]).length, 1);
  assert.equal(ungroundedClaims('giá 490 ngàn', [priced(560000)]).length, 1);
});

test('grounding accepts longer unit spellings of a real price', () => {
  assert.deepEqual(ungroundedClaims('giá 490 ngàn', [priced(490000)]), []);
  assert.deepEqual(ungroundedClaims('giá 490.000 đồng', [priced(490000)]), []);
  assert.deepEqual(ungroundedClaims('giá 1,2 triệu', [priced(1200000)]), []);
});

test('grounding parses decimals as floats, not as a tail', () => {
  assert.deepEqual(ungroundedClaims('giá 1,5k', [priced(1500)]), []);
  assert.deepEqual(ungroundedClaims('giá 1,5k', [priced(490000)]), ['1,5k']);
});

test('grounding ignores non-money words after a number', () => {
  assert.deepEqual(ungroundedClaims('nặng 2kg, cao 170cm, 3 đơn, 2 trắng', [priced(490000)]), []);
});

test('hasToolCallMarkup flags every raw tool-call marker, case-insensitively', () => {
  for (const marker of ['<tool_call>', '</tool_call>', '<|tool_calls_begin|>', '<｜tool▁calls▁begin｜>', '<function=search_products>', '<|im_start|>assistant', '<|python_tag|>', '<TOOL_CALL>', '<tool_call>search_products<arg_key>category</arg_key><arg_value>mũ</arg_value>']) {
    assert.equal(hasToolCallMarkup(`Để mình kiểm tra nhé ${marker} x`), true, marker);
  }
});

test('hasToolCallMarkup ignores ordinary text containing angle brackets', () => {
  for (const text of ['giá < 300k', 'Áo thun giá <300k, size M > L', 'Mình tìm được 3 mẫu.', '']) {
    assert.equal(hasToolCallMarkup(text), false, text);
  }
});

test('budget reports the model and tool calls it has left', () => {
  const b = new TurnBudget({ maxToolCalls: 2, maxModelCalls: 2, turnTimeoutMs: 500 });
  assert.equal(b.modelCallsLeft(), 2);
  assert.equal(b.toolCallsLeft(), 2);
  b.takeModelCall();
  b.takeToolCall();
  b.takeToolCall();
  assert.equal(b.modelCallsLeft(), 1);
  assert.equal(b.toolCallsLeft(), 0);
  b.takeToolCall();
  assert.equal(b.toolCallsLeft(), 0);
});
