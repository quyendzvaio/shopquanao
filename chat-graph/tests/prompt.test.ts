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
