import assert from 'node:assert/strict';
import test from 'node:test';
import type { GraphConfig } from '../src/config.js';
import { loadConfig } from '../src/config.js';
import { flushTracing, langfuseCallbacks, langfuseMask, redactContent } from '../src/tracing.js';

const base: GraphConfig['langfuse'] = { enabled: true, baseUrl: 'http://127.0.0.1:9', publicKey: 'pk', secretKey: 'sk', traceContent: false };

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

test('redactContent: no customer text survives, only a size', () => {
  const payload = { messages: [{ role: 'user', content: 'tôi tên Lan, địa chỉ 12 Nguyễn Trãi' }], tool: { args: { search: 'áo thun' } } };
  const masked = redactContent(payload);
  assert.ok(!JSON.stringify(masked).includes('Nguyễn Trãi'));
  assert.ok(!JSON.stringify(masked).includes('áo thun'));
  assert.equal(masked.redacted, true);
  assert.equal(masked.chars, JSON.stringify(payload).length);
  assert.deepEqual(redactContent('xin chào'), { redacted: true, chars: 8 });
  const circular: Record<string, unknown> = {};
  circular.self = circular;
  assert.deepEqual(redactContent(circular), { redacted: true, chars: 0 });
});

test('LANGFUSE_TRACE_CONTENT defaults to false', () => {
  const env = { CHAT_GRAPH_TOKEN: 'tok' };
  assert.equal(loadConfig(env).langfuse.traceContent, false);
  assert.equal(loadConfig({ ...env, LANGFUSE_TRACE_CONTENT: 'true' }).langfuse.traceContent, true);
  assert.equal(loadConfig({ ...env, LANGFUSE_TRACE_CONTENT: 'false' }).langfuse.traceContent, false);
});

test('langfuseMask: redacts when the content flag is off, absent when on', () => {
  const mask = langfuseMask(false);
  assert.ok(mask);
  const out = JSON.stringify(mask({ data: { input: 'áo thun màu đỏ cho Lan' } }));
  assert.ok(!out.includes('Lan') && !out.includes('áo thun'));
  assert.equal(langfuseMask(true), undefined);
});
