import assert from 'node:assert/strict';
import test from 'node:test';
import type { TurnRequest } from '../src/contract.js';
import { systemPrompt } from '../src/graph/prompt.js';

const req: TurnRequest = {
  message: 'Áo mã 61 phối với gì',
  user_id: null,
  session_id: 1,
  history: [],
  memory: { summary: '', slots: {}, profile: null },
  freeship_fingerprint: null,
  stream: false,
};

test('styling questions are routed to build_outfit with the anchor product id', () => {
  const prompt = systemPrompt(req);
  assert.match(prompt, /phối đồ[^\n]*`build_outfit`/);
  assert.match(prompt, /`product_id: N`/);
  assert.match(prompt, /không tự gợi ý món đồ không có trong kết quả công cụ/);
});

test('occasions map to the build_outfit style tags', () => {
  const prompt = systemPrompt(req);
  for (const pair of ['dự tiệc=dutiec', 'phỏng vấn=phongvan', 'công sở=congso', 'đi chơi=dichoi', 'đi biển=dibien', 'hẹn hò=hendo', 'thể thao/gym=thethao', 'trời lạnh=mualanh']) {
    assert.ok(prompt.includes(pair), `missing ${pair}`);
  }
  assert.match(prompt, /`budget_too_low`[^\n]*không đưa sản phẩm/);
});

test('order questions call get_order_status without asking for an order id', () => {
  const prompt = systemPrompt(req);
  assert.match(prompt, /đơn hàng[^\n]*`get_order_status`[^\n]*không cần mã đơn/);
  assert.match(prompt, /`requires_login`[^\n]*đăng nhập/);
});

test('every part of a multi-part question gets its own tool call', () => {
  assert.match(systemPrompt(req), /nhiều ý[^\n]*từng ý/);
});

test('a sold-out name match a search hid is offered as the other reading of the question', () => {
  assert.match(systemPrompt(req), /`out_of_stock_products`[^\n]*hết hàng/);
});

test('support requests go through draft_support_ticket and never claim a cancel or refund', () => {
  const prompt = systemPrompt(req);
  assert.match(prompt, /`draft_support_ticket`[^\n]*không bao giờ/i);
  assert.match(prompt, /`get_order_status`[^\n]*`draft_support_ticket`/);
  assert.match(prompt, /`get_support_tickets`/);
});
