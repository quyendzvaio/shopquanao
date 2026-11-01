import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parseCases } from '../eval/cases.js';

const shipped = readFileSync(new URL('../eval/cases.jsonl', import.meta.url), 'utf8');

test('the shipped case file parses and has at least 40 cases in the expected groups', () => {
  const cases = parseCases(shipped);
  assert.ok(cases.length >= 40, `only ${cases.length} cases`);
  const groups = new Set(cases.map((c) => c.group));
  for (const g of ['s1_regression', 'outfit', 'freeship', 'guardrail', 'unrelated', 'multi_intent', 'sold_out']) {
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
