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
  assert.equal(typeof masked, 'string', 'the SDK expects a string attribute');
  assert.ok(!masked.includes('Nguyễn Trãi'));
  assert.ok(!masked.includes('áo thun'));
  assert.deepEqual(JSON.parse(masked), { redacted: true, chars: JSON.stringify(payload).length });
  assert.deepEqual(JSON.parse(redactContent('xin chào')), { redacted: true, chars: 8 });
  const circular: Record<string, unknown> = {};
  circular.self = circular;
  assert.deepEqual(JSON.parse(redactContent(circular)), { redacted: true, chars: 0 });
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

const gateEnv = { CHAT_GRAPH_TOKEN: 'tok', LANGFUSE_ENABLED: 'true', LANGFUSE_PUBLIC_KEY: 'pk', LANGFUSE_SECRET_KEY: 'sk' };

test('APP_ENV gate: tracing is on only for production with LANGFUSE_ENABLED=true', () => {
  const silent = (): void => {};
  const unset = loadConfig(gateEnv, silent);
  assert.equal(unset.appEnv, 'development');
  assert.equal(unset.langfuse.enabled, false);
  assert.equal(loadConfig({ ...gateEnv, APP_ENV: '' }, silent).langfuse.enabled, false);
  assert.equal(loadConfig({ ...gateEnv, APP_ENV: 'development' }, silent).langfuse.enabled, false);
  assert.equal(loadConfig({ ...gateEnv, APP_ENV: 'staging' }, silent).langfuse.enabled, false);
  const prod = loadConfig({ ...gateEnv, APP_ENV: 'production' }, silent);
  assert.equal(prod.appEnv, 'production');
  assert.equal(prod.langfuse.enabled, true);
  assert.equal(loadConfig({ ...gateEnv, APP_ENV: 'production', LANGFUSE_ENABLED: 'false' }, silent).langfuse.enabled, false);
});

test('APP_ENV gate: logs once at startup when tracing is requested outside production', () => {
  const line = '[chat-graph] Langfuse tracing off: APP_ENV is not production';
  const lines: string[] = [];
  const log = (m: string): void => void lines.push(m);
  loadConfig(gateEnv, log);
  assert.deepEqual(lines, [line]);
  loadConfig({ ...gateEnv, APP_ENV: 'production' }, log);
  loadConfig({ ...gateEnv, LANGFUSE_ENABLED: 'false' }, log);
  loadConfig({ CHAT_GRAPH_TOKEN: 'tok' }, log);
  assert.deepEqual(lines, [line], 'no line in production, with tracing off, or with no Langfuse env');
});
