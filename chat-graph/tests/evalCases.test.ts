import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fillOrderPlaceholder, parseCases } from '../eval/cases.js';

const shipped = readFileSync(new URL('../eval/cases.jsonl', import.meta.url), 'utf8');

test('the shipped case file parses and has at least 50 cases in the expected groups', () => {
  const cases = parseCases(shipped);
  assert.ok(cases.length >= 50, `only ${cases.length} cases`);
  const groups = new Set(cases.map((c) => c.group));
  for (const g of ['s1_regression', 'outfit', 'freeship', 'guardrail', 'unrelated', 'multi_intent', 'sold_out', 'catalog', 'knowledge', 'order', 'memory', 'ticket']) {
    assert.ok(groups.has(g), `missing group ${g}`);
  }
});

test('parseCases rejects unknown expect keys, duplicate ids and carts without a user', () => {
  const ok = '{"id":"A","group":"g","user":false,"turns":[{"message":"hi","expect":{"nudge":false}}]}';
  assert.equal(parseCases(ok).length, 1);
  assert.throws(() => parseCases(ok.replace('"nudge"', '"nudges"')), /unknown expect key/);
  assert.throws(() => parseCases(`${ok}\n${ok}`), /duplicate id/);
  assert.throws(() => parseCases(ok.replace('"turns"', '"cart":[],"turns"')), /cart needs user/);
});

test('no shipped case asserts exact sold-out, login or price wording', () => {
  for (const c of parseCases(shipped)) {
    for (const t of c.turns) {
      for (const f of t.expect.message_contains ?? []) {
        assert.ok(!/hết hàng|đăng nhập|\d\.\d{3}\s*đ/i.test(f), `${c.id}: fragile fragment "${f}"`);
      }
    }
  }
});

test('the ticket group has 6 cases, T02 is the guest, the rest have an order fixture', () => {
  const tickets = parseCases(shipped).filter((c) => c.group === 'ticket');
  assert.deepEqual(tickets.map((c) => c.id), ['T01', 'T02', 'T03', 'T04', 'T05', 'T06']);
  for (const c of tickets) {
    assert.equal(c.user, c.id !== 'T02', c.id);
    assert.equal((c.orders?.length ?? 0) > 0, c.id !== 'T02', c.id);
  }
});

test('every ticket order fixture has its items, and the items add up to the order total', () => {
  // Product 53 "Áo Len Cổ Tròn Xám" costs 415000 in shop_db and shop_test: the áo the ticket messages talk about.
  for (const c of parseCases(shipped).filter((x) => x.group === 'ticket' && x.orders !== undefined)) {
    for (const order of c.orders ?? []) {
      assert.deepEqual(order.items, [{ product_id: 53, quantity: 1 }], c.id);
      assert.equal(order.total, 415000, c.id);
    }
  }
});

test('parseCases rejects order items without a positive product_id and quantity', () => {
  const base = (items: string) => `{"id":"A","group":"g","user":true,"orders":[{"total":415000,"status":"Đang giao","items":${items}}],"turns":[{"message":"hi","expect":{}}]}`;
  assert.deepEqual(parseCases(base('[{"product_id":53,"quantity":1}]'))[0]?.orders?.[0]?.items, [{ product_id: 53, quantity: 1 }]);
  assert.throws(() => parseCases(base('[{"product_id":0,"quantity":1}]')), /order items need/);
  assert.throws(() => parseCases(base('[{"product_id":53,"quantity":0}]')), /order items need/);
  assert.throws(() => parseCases(base('[{"product_id":53}]')), /order items need/);
  assert.throws(() => parseCases(base('{}')), /order items need/);
});

test('parseCases accepts the ticket expect keys and the orders fixture, orders need a user', () => {
  const ok = '{"id":"A","group":"g","user":true,"orders":[{"total":415000,"status":"Đang giao"}],"turns":[{"message":"hi","expect":{"ticket_pending":true,"ticket_created":false}}]}';
  assert.equal(parseCases(ok)[0]?.orders?.[0]?.total, 415000);
  assert.throws(() => parseCases(ok.replace('"user":true', '"user":false')), /orders need user/);
});

test('fillOrderPlaceholder replaces {order1} in the message and in the string expectations', () => {
  const turn = {
    message: 'đơn {order1} giao sai',
    expect: {
      message_contains: ['#{order1}', 'Đã gửi'],
      message_contains_any: [['{order1}', 'đơn'], ['x']],
      message_not_contains: ['{order1} bị huỷ'],
      tools_include: ['get_order_status'],
      ticket_pending: true,
    },
  };
  const out = fillOrderPlaceholder(turn, [88, 89]);
  assert.equal(out.message, 'đơn 88 giao sai');
  assert.deepEqual(out.expect.message_contains, ['#88', 'Đã gửi']);
  assert.deepEqual(out.expect.message_contains_any, [['88', 'đơn'], ['x']]);
  assert.deepEqual(out.expect.message_not_contains, ['88 bị huỷ']);
  assert.deepEqual(out.expect.tools_include, ['get_order_status']);
  assert.equal(out.expect.ticket_pending, true);
  assert.equal(turn.message, 'đơn {order1} giao sai', 'the input is not mutated');
});

test('fillOrderPlaceholder leaves a turn without a placeholder alone and rejects one with no order', () => {
  const plain = { message: 'hi', expect: { no_tools: true } };
  assert.deepEqual(fillOrderPlaceholder(plain, []), plain);
  assert.throws(() => fillOrderPlaceholder({ message: '{order1}', expect: {} }, []), /no order fixture/);
});
