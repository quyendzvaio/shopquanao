import assert from 'node:assert/strict';
import test from 'node:test';
import {
  BUSY, PRIMARY_INTENT, askAlternatives, checkoutRefusal, money, nudgeText, soldOutCorrection, soldOutNoAlternatives, soldOutNote, TICKET_CONFIRM_QUESTION, ticketDraftLine,
} from '../src/copy.js';

const TAIL = ', mình không thể tự thêm giỏ hàng hoặc thanh toán giúp bạn. Bạn vui lòng bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán.';

test('money formats vi-VN', () => {
  assert.equal(money(490000), '490.000đ');
  assert.equal(money(0), '0đ');
});

test('BUSY copy', () => {
  assert.equal(BUSY, 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.');
});

test('checkoutRefusal collapses whitespace', () => {
  assert.equal(checkoutRefusal('thêm   vào giỏ giúp mình'), `Với yêu cầu "thêm vào giỏ giúp mình"${TAIL}`);
});

test('checkoutRefusal cuts a 130-char message to 117 chars + ellipsis', () => {
  const msg = 'a'.repeat(130);
  assert.equal(checkoutRefusal(msg), `Với yêu cầu "${'a'.repeat(117)}…"${TAIL}`);
});

test('checkoutRefusal keeps a 120-char message whole', () => {
  const msg = 'b'.repeat(120);
  assert.equal(checkoutRefusal(msg), `Với yêu cầu "${msg}"${TAIL}`);
});

test('askAlternatives with two alternatives', () => {
  assert.equal(
    askAlternatives('Quần Baggy Vải Tuyết Mưa', [{ id: 73, name: 'Quần Kaki Ống Rộng Màu Xanh Rêu' }, { id: 71, name: 'Quần Bò Rách Gối Phong Cách' }], false),
    'Mẫu Quần Baggy Vải Tuyết Mưa đang hết hàng. Bạn có muốn xem Quần Kaki Ống Rộng Màu Xanh Rêu hoặc Quần Bò Rách Gối Phong Cách không?',
  );
});

test('askAlternatives after another answer starts with Còn mẫu', () => {
  const s = askAlternatives('A', [{ id: 1, name: 'B' }, { id: 2, name: 'C' }], true);
  assert.ok(s.startsWith('Còn mẫu A đang hết hàng.'));
});

test('askAlternatives with one alternative has no hoặc', () => {
  const s = askAlternatives('A', [{ id: 1, name: 'B' }], false);
  assert.equal(s, 'Mẫu A đang hết hàng. Bạn có muốn xem B không?');
  assert.ok(!s.includes(' hoặc '));
});

test('soldOutNote gives the model the facts and asks it to write the whole answer', () => {
  assert.equal(
    soldOutNote('A', [{ id: 1, name: 'B', price: 320000 }, { id: 2, name: 'C' }]),
    'Mẫu A đã hết hàng. Mẫu thay thế còn hàng: B (mã 1) giá 320.000đ; C (mã 2). '
      + 'Trả lời các ý khác của khách trước (nếu có), sau đó báo mẫu A đang hết hàng và hỏi khách có muốn xem B hoặc C không. '
      + 'Viết đúng tên các mẫu, không gợi ý mẫu nào khác.',
  );
  assert.equal(
    soldOutNote('A', []),
    'Mẫu A đã hết hàng và chưa có mẫu thay thế. Trả lời các ý khác của khách trước (nếu có), sau đó báo mẫu A đang hết hàng.',
  );
});

test('soldOutCorrection names the sold-out item and the alternatives', () => {
  assert.equal(
    soldOutCorrection('A', [{ name: 'B' }, { name: 'C' }]),
    'Câu trả lời phải báo mẫu A đang hết hàng và hỏi khách có muốn xem B hoặc C không, đồng thời vẫn trả lời các ý khác của khách.',
  );
});

test('soldOutNoAlternatives', () => {
  assert.equal(
    soldOutNoAlternatives(3, 'áo thun'),
    'Shop có 3 mẫu áo thun nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.',
  );
});

test('nudgeText', () => {
  assert.equal(
    nudgeText(120000, 500000, { id: 9, name: 'Tất cổ cao', price: 125000 }),
    'Giỏ hàng của bạn còn thiếu 120.000đ để được miễn phí ship (đơn từ 500.000đ); bạn có thể tham khảo thêm Tất cổ cao (mã 9) giá 125.000đ.',
  );
});

test('PRIMARY_INTENT map is pinned', () => {
  assert.deepEqual(PRIMARY_INTENT, {
    search_products: 'product_search', find_similar_in_stock: 'product_search', get_categories: 'product_search',
    get_product_detail: 'product_detail', build_outfit: 'build_outfit', suggest_freeship_items: 'freeship_gap',
    suggest_size: 'size_advice', retrieve_knowledge: 'policy', get_order_status: 'order_status',
    draft_support_ticket: 'support_ticket', get_support_tickets: 'support_ticket',
  });
});

test('the ticket confirmation question and draft line', () => {
  assert.equal(TICKET_CONFIRM_QUESTION, 'Bạn xác nhận gửi yêu cầu này cho shop không?');
  const labels: Record<string, string> = {
    doi_tra: 'Đổi size / đổi trả', hang_loi: 'Hàng lỗi / giao sai', huy_don: 'Huỷ đơn', doi_dia_chi: 'Đổi địa chỉ giao', khac: 'Khác',
  };
  for (const [type, label] of Object.entries(labels)) {
    assert.equal(ticketDraftLine({ type, order_id: 3, summary: 'abc' }), `Mình sẽ gửi yêu cầu sau cho shop: ${label}, đơn #3: abc.`);
  }
  assert.equal(ticketDraftLine({ type: 'khac', order_id: null, summary: 'abc' }), 'Mình sẽ gửi yêu cầu sau cho shop: Khác: abc.');
});
