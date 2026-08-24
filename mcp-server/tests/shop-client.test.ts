import assert from 'node:assert/strict';
import test from 'node:test';
import { config } from '../src/config.js';
import { internalCall } from '../src/shop-client.js';

test('internal read transport times out and retries the configured number of attempts', async () => {
  const originalFetch = globalThis.fetch;
  const originalTimeout = config.requestTimeoutMs;
  let calls = 0;
  config.requestTimeoutMs = 5;
  globalThis.fetch = ((_url: string | URL | Request, options?: RequestInit) => {
    calls += 1;
    return new Promise((_resolve, reject) => {
      options?.signal?.addEventListener('abort', () => reject(new DOMException('Timed out', 'AbortError')));
    });
  }) as typeof fetch;
  try {
    await assert.rejects(() => internalCall({ operation: 'tool.call' }, 2), /Timed out/);
    assert.equal(calls, 2);
  } finally {
    globalThis.fetch = originalFetch;
    config.requestTimeoutMs = originalTimeout;
  }
});
