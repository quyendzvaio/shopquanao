import assert from 'node:assert/strict';
import test from 'node:test';
import type { ToolRecord } from '../src/contract.js';
import { ALLOWED_TOOLS, isAllowedTool } from '../src/policies/allowList.js';
import { TurnBudget } from '../src/policies/budget.js';
import { outfitsFrom, productToCard, selectCards, verifyCard } from '../src/policies/cards.js';
import { matchesCheckout } from '../src/policies/checkout.js';
import { asksTicketConfirmation, dropTicketConfirmations, lastValidDraft } from '../src/policies/ticket.js';
import { TICKET_CONFIRM_QUESTION } from '../src/copy.js';
import { hasToolCallMarkup, plainText, summaryFromCards, ungroundedClaims } from '../src/policies/grounding.js';

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

test('allowListHasElevenNonWritingTools', () => {
  assert.equal(ALLOWED_TOOLS.length, 11);
  assert.equal(isAllowedTool('draft_support_ticket'), true);
  assert.equal(isAllowedTool('get_support_tickets'), true);
  assert.equal(isAllowedTool('create_support_ticket'), false);
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

test('plainText drops markdown markers and keeps lines, dashes and prices', () => {
  assert.equal(plainText('## Kết quả\n* **Quần Kaki** – __mã 73__\n+ `560.000đ`'), 'Kết quả\n- Quần Kaki – mã 73\n- 560.000đ');
  assert.equal(plainText('Mẫu *Váy Xòe* rất *đẹp*, xem [chi tiết](/product.php?id=78) nhé'), 'Mẫu Váy Xòe rất đẹp, xem chi tiết nhé');
  assert.equal(plainText('- Size S\n- giá < 300k, 2 * 3 = 6'), '- Size S\n- giá < 300k, 2 * 3 = 6');
  assert.equal(plainText('Áo_thun snake_case giữ nguyên'), 'Áo_thun snake_case giữ nguyên');
});

test('groundingAcceptsPriceSpellings', () => {
  const rec: ToolRecord = { name: 'search_products', args: {}, result: { products: [product(1, 490000)] } };
  for (const a of ['mã 1 giá 490.000đ', 'mã 1 giá 490k', 'mã 1 giá 490.000 đ', 'mã 1 giá 490,000 VND', 'mã 1 giá 490 nghìn']) {
    assert.deepEqual(ungroundedClaims(a, [rec]), [], a);
  }
});

test('grounding reads thousands grouped by spaces', () => {
  const rec: ToolRecord = { name: 'build_outfit', args: {}, result: { budget: 500000, cheapest_total: 710000, products: [] } };
  for (const a of ['ngân sách 500 000 ₫, set rẻ nhất 710 000 ₫', 'giá 710\u00a0000\u00a0đ', 'giá 710\u202f000 đ', 'tổng 710.000 đ, ngân sách 500,000 đ']) {
    assert.deepEqual(ungroundedClaims(a, [rec]), [], a);
  }
  assert.deepEqual(ungroundedClaims('giá 690 000 ₫', [rec]), ['690 000 ₫']);
  assert.deepEqual(ungroundedClaims('giá 1 200 000 đ', [priced(1200000)]), []);
  // Mixed separators are not one amount, and a space before a multiplier unit is "N × amount".
  assert.deepEqual(ungroundedClaims('mua 3 500.000đ', [rec]), []);
  assert.deepEqual(ungroundedClaims('mua 2 500k', [rec]), []);
  assert.deepEqual(ungroundedClaims('mua 2 690k', [rec]), ['2 690k']);
});

test('grounding accepts amounts written inside tool text and order ids', () => {
  const policy: ToolRecord = { name: 'retrieve_knowledge', args: {}, result: { items: [{ content: 'Miễn phí ship cho đơn từ 500,000đ. Dưới 500,000đ: 30,000đ nội thành, 50,000đ ngoại tỉnh.' }] } };
  assert.deepEqual(ungroundedClaims('Phí ship 30.000đ nội thành, 50k ngoại tỉnh; đơn từ 500.000 đ được miễn phí.', [policy]), []);
  assert.deepEqual(ungroundedClaims('Phí ship 40.000đ', [policy]), ['40.000đ']);
  const orders: ToolRecord = { name: 'get_order_status', args: {}, result: { orders: [{ id: 15, status: 'Chờ xử lý', total: 415000 }] } };
  assert.deepEqual(ungroundedClaims('Đơn mã 15 đang chờ xử lý, tổng 415.000đ', [orders]), []);
  assert.deepEqual(ungroundedClaims('Đơn mã 16 đang giao', [orders]), ['mã 16']);
});

test('grounding accepts the product id of an order line, never the line id', () => {
  // get_order_status with order_id returns the order's lines; the model names the product as "mã 53".
  const order: ToolRecord = { name: 'get_order_status', args: { order_id: 68 }, result: { orders: [{ id: 68, status: 'Đang giao', total_price: 415000,
    items: [{ id: 35, product_id: 53, quantity: 1, price: 415000, name: 'Áo Len Cổ Tròn Xám' }] }] } };
  assert.deepEqual(ungroundedClaims('Đơn #68 gồm Áo Len Cổ Tròn Xám (mã 53) - 415.000đ', [order]), []);
  assert.deepEqual(ungroundedClaims('Đơn #68 gồm mã 35', [order]), ['mã 35']);
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
  for (const marker of ['<tool_call>', '</tool_call>', '<|tool_calls_begin|>', '<｜tool▁calls▁begin｜>', '<function=search_products>', '<|im_start|>assistant', '<|python_tag|>', '<TOOL_CALL>', '<tool_call>search_products<arg_key>category</arg_key><arg_value>mũ</arg_value>',
    // DeepSeek (deepseek-v4-flash) writing its call as text, seen in the 2026-11-04 ticket eval.
    '<｜｜DSML｜｜ calls> <｜｜DSML｜｜ invoke name="draft_support_ticket">', '</｜｜DSML｜｜ parameter>', '<| DSML | calls>']) {
    assert.equal(hasToolCallMarkup(`Để mình kiểm tra nhé ${marker} x`), true, marker);
  }
});

test('hasToolCallMarkup ignores ordinary text containing angle brackets', () => {
  for (const text of ['giá < 300k', 'Áo thun giá <300k, size M > L', 'Mình tìm được 3 mẫu.', 'Size S | M | L đều còn', 'size M|L', '']) {
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

test('groundingKnowsTicketAndDraftOrderIds', () => {
  const draftRecord: ToolRecord = {
    name: 'draft_support_ticket', args: {}, result: { status: 'draft', draft: { type: 'doi_tra', order_id: 12, summary: 'Nhận size M' } },
  };
  assert.deepEqual(ungroundedClaims('đơn mã 12', [draftRecord]), []);
  assert.deepEqual(ungroundedClaims('đơn mã 13', [draftRecord]), ['mã 13']);
  const tickets: ToolRecord = {
    name: 'get_support_tickets', args: {},
    result: { tickets: [{ id: 5, order_id: 21, type: 'huy_don', summary: 'x', status: 'moi' }, { id: 6, order_id: null }] },
  };
  assert.deepEqual(ungroundedClaims('yêu cầu mã 5 cho đơn mã 21, mã 6', [tickets]), []);
  assert.deepEqual(ungroundedClaims('mã 7', [tickets]), ['mã 7']);
  const refused: ToolRecord = { name: 'draft_support_ticket', args: {}, result: { status: 'duplicate_open', message: 'm', ticket_id: 9 } };
  assert.deepEqual(ungroundedClaims('yêu cầu mã 9', [refused]), []);
});

test('asksTicketConfirmation: a question that asks the customer to confirm or agree to sending or creating', () => {
  for (const t of [
    'Bạn có đồng ý để mình gửi yêu cầu đổi trả cho đơn #12 không?',
    TICKET_CONFIRM_QUESTION,
    'Đơn #12 đã giao. BẠN XÁC NHẬN TẠO yêu cầu này nhé?',
    'Mình soạn xong rồi.\nBạn đồng ý gửi cho shop chứ?',
    'Bạn có xác nhận gửi yêu cầu đổi trả đơn 85.000đ không?',
  ]) assert.equal(asksTicketConfirmation(t), true, t);
});

test('asksTicketConfirmation: offers, statements and unrelated questions pass', () => {
  for (const t of [
    'Bạn có muốn mình tạo yêu cầu đổi trả không?',
    'Có phải đơn #12 không?',
    'Mình sẽ gửi yêu cầu đổi size cho đơn #12. Bạn đồng ý gửi cho shop.',
    'Bạn xác nhận gửi yêu cầu này cho shop',
    'Bạn đồng ý với phí ship 30.000đ không?',
    'Bạn xác nhận size M không?',
    'Bạn đồng ý tạo outfit này không?',
    'Bạn xác nhận để mình tạo outfit dưới 500.000đ nhé?',
    'Bạn có đồng ý nhận hàng gửi về quận khác không?',
    '',
  ]) assert.equal(asksTicketConfirmation(t), false, t);
});

test('dropTicketConfirmations removes only the matching question sentences', () => {
  assert.equal(
    dropTicketConfirmations('Đơn #12 đã giao. Bạn xác nhận gửi yêu cầu này cho shop không? Bạn muốn đổi size nào?'),
    'Đơn #12 đã giao. Bạn muốn đổi size nào?',
  );
  assert.equal(dropTicketConfirmations(`Ok.\n${TICKET_CONFIRM_QUESTION}`), 'Ok.');
  assert.equal(dropTicketConfirmations(TICKET_CONFIRM_QUESTION), '');
  assert.equal(dropTicketConfirmations('Không có câu hỏi nào.'), 'Không có câu hỏi nào.');
  // A price's thousands dot is not a sentence end: the whole question goes, the price sentence stays intact.
  assert.equal(
    dropTicketConfirmations('Đơn #12 giá 120.000đ. Bạn có đồng ý để mình gửi yêu cầu 85.000đ không? Cảm ơn bạn.'),
    'Đơn #12 giá 120.000đ. Cảm ơn bạn.',
  );
});

test('lastValidDraft ignores refused and malformed drafts and returns the latest valid one', () => {
  const rec = (result: Record<string, unknown>): ToolRecord => ({ name: 'draft_support_ticket', args: {}, result });
  assert.equal(lastValidDraft([]), null);
  assert.equal(lastValidDraft([rec({ status: 'duplicate_open' })]), null);
  assert.equal(lastValidDraft([rec({ status: 'draft', draft: { type: 'khac', order_id: null, summary: ' ' } })]), null);
  assert.deepEqual(lastValidDraft([rec({ status: 'draft', draft: { type: 'khac', order_id: null, summary: 'x' } })]), { type: 'khac', order_id: null, summary: 'x' });
});
