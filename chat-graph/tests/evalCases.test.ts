import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parseCases } from '../eval/cases.js';

const shipped = readFileSync(new URL('../eval/cases.jsonl', import.meta.url), 'utf8');

test('the shipped case file parses and has at least 50 cases in the expected groups', () => {
  const cases = parseCases(shipped);
  assert.ok(cases.length >= 50, `only ${cases.length} cases`);
  const groups = new Set(cases.map((c) => c.group));
  for (const g of ['s1_regression', 'outfit', 'freeship', 'guardrail', 'unrelated', 'multi_intent', 'sold_out', 'catalog', 'knowledge', 'order', 'memory']) {
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
