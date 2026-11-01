import assert from 'node:assert/strict';
import test from 'node:test';
import { scoreTurn, summarize, type TurnObservation } from '../eval/scorer.js';

const base: TurnObservation = {
  message: 'q',
  answer: 'Mẫu Quần Baggy Vải Tuyết Mưa đã hết hàng, bạn xem Quần Kaki nhé.',
  response_type: 'clarification',
  product_ids: [73, 58],
  tools: ['search_products', 'find_similar_in_stock'],
  offered_ids: [73],
  nudge: false,
};

test('an empty expectation always passes', () => {
  assert.deepEqual(scoreTurn({}, base), []);
});

test('response_type: string and list forms', () => {
  assert.deepEqual(scoreTurn({ response_type: 'clarification' }, base), []);
  assert.deepEqual(scoreTurn({ response_type: ['refusal', 'clarification'] }, base), []);
  assert.equal(scoreTurn({ response_type: 'final_answer' }, base).length, 1);
  assert.equal(scoreTurn({ response_type: ['refusal', 'final_answer'] }, base).length, 1);
});

test('tools_include needs every named tool', () => {
  assert.deepEqual(scoreTurn({ tools_include: ['search_products'] }, base), []);
  assert.equal(scoreTurn({ tools_include: ['search_products', 'build_outfit'] }, base).length, 1);
});

test('no_tools', () => {
  assert.deepEqual(scoreTurn({ no_tools: true }, { ...base, tools: [] }), []);
  assert.equal(scoreTurn({ no_tools: true }, base).length, 1);
  assert.deepEqual(scoreTurn({ no_tools: false }, base), []);
});

test('products_include needs every id among the cards', () => {
  assert.deepEqual(scoreTurn({ products_include: [73] }, base), []);
  assert.equal(scoreTurn({ products_include: [73, 99] }, base).length, 1);
});

test('min_products and max_products bound the card count', () => {
  assert.deepEqual(scoreTurn({ min_products: 2, max_products: 2 }, base), []);
  assert.equal(scoreTurn({ min_products: 3 }, base).length, 1);
  assert.equal(scoreTurn({ max_products: 1 }, base).length, 1);
  assert.deepEqual(scoreTurn({ max_products: 0 }, { ...base, product_ids: [] }), []);
});

test('offered_include needs every id offered', () => {
  assert.deepEqual(scoreTurn({ offered_include: [73] }, base), []);
  assert.equal(scoreTurn({ offered_include: [73, 71] }, base).length, 1);
});

test('message_contains needs every fragment, case-insensitively', () => {
  assert.deepEqual(scoreTurn({ message_contains: ['Quần Baggy Vải Tuyết Mưa', 'HẾT HÀNG'] }, base), []);
  assert.equal(scoreTurn({ message_contains: ['hết hàng', 'miễn phí ship'] }, base).length, 1);
});

test('message_not_contains rejects any fragment', () => {
  assert.deepEqual(scoreTurn({ message_not_contains: ['thanh toán xong'] }, base), []);
  assert.equal(scoreTurn({ message_not_contains: ['kaki'] }, base).length, 1);
});

test('nudge compares the boolean', () => {
  assert.deepEqual(scoreTurn({ nudge: false }, base), []);
  assert.equal(scoreTurn({ nudge: true }, base).length, 1);
  assert.deepEqual(scoreTurn({ nudge: true }, { ...base, nudge: true }), []);
});

test('every unmet key reports its own failure', () => {
  const failures = scoreTurn({ response_type: 'refusal', no_tools: true, min_products: 5 }, base);
  assert.equal(failures.length, 3);
});

test('summarize counts cases, groups and the rate', () => {
  const s = summarize([
    { id: 'a', group: 'outfit', failures: [] },
    { id: 'b', group: 'outfit', failures: ['x'] },
    { id: 'c', group: 'guardrail', failures: [] },
    { id: 'd', group: 'guardrail', failures: [] },
  ]);
  assert.equal(s.passed, 3);
  assert.equal(s.failed, 1);
  assert.equal(s.rate, 0.75);
  assert.deepEqual(s.by_group, { outfit: { passed: 1, total: 2 }, guardrail: { passed: 2, total: 2 } });
});

test('summarize of nothing is rate 0', () => {
  assert.equal(summarize([]).rate, 0);
});
