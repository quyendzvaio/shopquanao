import assert from 'node:assert/strict';
import test from 'node:test';
import { MIN_PACE_MS, paceMs } from '../eval/args.js';

test('paceMs defaults to the nginx floor when the flag is absent', () => {
  assert.equal(MIN_PACE_MS, 2300);
  assert.equal(paceMs([]), 2300);
  assert.equal(paceMs(['--group=guardrail']), 2300);
});

test('paceMs may slow the run but never go below the nginx floor', () => {
  assert.equal(paceMs(['--pace-ms=30000']), 30000);
  assert.equal(paceMs(['--only=S01', '--pace-ms=30000']), 30000);
  assert.equal(paceMs(['--pace-ms=100']), 2300);
  assert.equal(paceMs(['--pace-ms=0']), 2300);
});

test('paceMs rejects a non-numeric value with a clear message', () => {
  assert.throws(() => paceMs(['--pace-ms=abc']), /--pace-ms must be a non-negative number of milliseconds, got "abc"/);
  assert.throws(() => paceMs(['--pace-ms=']), /--pace-ms/);
  assert.throws(() => paceMs(['--pace-ms=-5']), /--pace-ms/);
});
