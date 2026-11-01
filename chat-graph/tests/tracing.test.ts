import assert from 'node:assert/strict';
import test from 'node:test';
import type { GraphConfig } from '../src/config.js';
import { flushTracing, langfuseCallbacks } from '../src/tracing.js';

const base: GraphConfig['langfuse'] = { enabled: true, baseUrl: 'http://127.0.0.1:9', publicKey: 'pk', secretKey: 'sk' };

test('langfuseCallbacks: empty unless enabled with both keys', () => {
  assert.deepEqual(langfuseCallbacks({ ...base, enabled: false }), []);
  assert.deepEqual(langfuseCallbacks({ ...base, publicKey: '' }), []);
  assert.deepEqual(langfuseCallbacks({ ...base, secretKey: '' }), []);
});

test('langfuseCallbacks: enabled builds one callback handler', async () => {
  const handlers = langfuseCallbacks(base);
  assert.equal(handlers.length, 1);
  assert.equal((handlers[0] as { name: string }).name, 'LangfuseCallbackHandler');
  await flushTracing();
});
