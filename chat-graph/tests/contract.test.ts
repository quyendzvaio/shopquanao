import assert from 'node:assert/strict';
import test from 'node:test';
import { parseTurnRequest, RequestError } from '../src/contract.js';
import { openShopTools, withoutNulls } from '../src/shopTools.js';

const valid = {
  message: 'tìm áo sơ mi',
  user_id: 7,
  session_id: 3,
  history: [{ role: 'user', content: 'chào' }],
  memory: { summary: '', slots: { size: 'M' }, profile: null },
  freeship_fingerprint: null,
  stream: false,
};

test('a valid body parses', () => {
  const req = parseTurnRequest(valid);
  assert.equal(req.message, 'tìm áo sơ mi');
  assert.equal(req.user_id, 7);
});

test('null user_id is allowed', () => {
  assert.equal(parseTurnRequest({ ...valid, user_id: null }).user_id, null);
});

test('empty message throws RequestError', () => {
  assert.throws(() => parseTurnRequest({ ...valid, message: '' }), RequestError);
});

test('message over 2000 chars throws RequestError', () => {
  assert.throws(() => parseTurnRequest({ ...valid, message: 'a'.repeat(2001) }), RequestError);
});

test('7 history items throw RequestError', () => {
  const history = Array.from({ length: 7 }, () => ({ role: 'user', content: 'x' }));
  assert.throws(() => parseTurnRequest({ ...valid, history }), RequestError);
});

test('non-numeric user_id throws RequestError', () => {
  assert.throws(() => parseTurnRequest({ ...valid, user_id: 'x' }), RequestError);
});

test('non-object body throws RequestError', () => {
  assert.throws(() => parseTurnRequest(null), RequestError);
});

test('openShopTools exposes exactly the 9 read-only tools', async () => {
  const shop = await openShopTools(null);
  try {
    const names = shop.tools.map((t) => t.name).sort();
    assert.deepEqual(names, [
      'build_outfit', 'find_similar_in_stock', 'get_categories', 'get_order_status', 'get_product_detail',
      'retrieve_knowledge', 'search_products', 'suggest_freeship_items', 'suggest_size',
    ]);
  } finally {
    await shop.close();
  }
});

test('null tool arguments count as not given', () => {
  assert.deepEqual(withoutNulls({ order_id: null }), {});
  assert.deepEqual(withoutNulls({ query: 'mũ', in_stock: false, limit: 0, color: null }), { query: 'mũ', in_stock: false, limit: 0 });
});
