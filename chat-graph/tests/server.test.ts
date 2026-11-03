import assert from 'node:assert/strict';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import test from 'node:test';
import type { GraphConfig } from '../src/config.js';
import { createServer } from '../src/server.js';
import { ScriptedChatModel } from './support/scriptedModel.js';
import { CONFIG, req, searchTurn, setup, TrackedModel } from './support/turnHarness.js';

const TOKEN = 'secret-token';

async function withServer(fn: (base: string) => Promise<void>): Promise<void> {
  const { deps } = setup([]);
  const config = { ...CONFIG, token: TOKEN } as GraphConfig;
  // A fresh scripted model per turn, as in production where the model is stateless.
  const server = createServer({
    config,
    makeModel: () => new ScriptedChatModel(searchTurn),
    openTools: deps.openTools,
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  try {
    await fn(`http://127.0.0.1:${(server.address() as AddressInfo).port}`);
  } finally {
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
}

const post = (base: string, body: unknown, headers: Record<string, string> = { 'X-CHAT-GRAPH-TOKEN': TOKEN }) =>
  fetch(`${base}/turn`, { method: 'POST', headers: { 'content-type': 'application/json', ...headers }, body: JSON.stringify(body) });

test('healthOk', async () => {
  await withServer(async (base) => {
    const res = await fetch(`${base}/health`);
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { ok: true });
  });
});

test('serverRejectsBadToken', async () => {
  await withServer(async (base) => {
    assert.equal((await post(base, req(), { 'X-CHAT-GRAPH-TOKEN': 'x' })).status, 401);
    assert.equal((await post(base, req(), { 'X-CHAT-GRAPH-TOKEN': `${TOKEN}y` })).status, 401);
    assert.equal((await post(base, req(), {})).status, 401);
  });
});

test('serverRejectsBadBody', async () => {
  await withServer(async (base) => {
    const res = await post(base, { ...req(), message: '' });
    assert.equal(res.status, 400);
    assert.equal(typeof ((await res.json()) as { error: string }).error, 'string');
    const notJson = await fetch(`${base}/turn`, { method: 'POST', headers: { 'X-CHAT-GRAPH-TOKEN': TOKEN }, body: '{' });
    assert.equal(notJson.status, 400);
  });
});

test('serverNonStreamReturnsJson', async () => {
  await withServer(async (base) => {
    const res = await post(base, req({ stream: false }));
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type') ?? '', /application\/json/);
    const body = (await res.json()) as Record<string, unknown>;
    for (const key of ['message', 'products', 'response_type', 'trace_id', 'pending_ticket']) assert.ok(key in body, key);
    assert.equal(body.response_type, 'final_answer');
  });
});

test('serverStreamsNdjson', async () => {
  await withServer(async (base) => {
    const res = await post(base, req({ stream: true }));
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type') ?? '', /application\/x-ndjson/);
    const lines = (await res.text()).trim().split('\n').map((l) => JSON.parse(l) as { type: string });
    assert.equal(lines[0]?.type, 'progress');
    assert.equal(lines[lines.length - 1]?.type, 'complete');
  });
});

test('unknownPathIs404', async () => {
  await withServer(async (base) => {
    assert.equal((await fetch(`${base}/nope`)).status, 404);
    assert.equal((await fetch(`${base}/turn`)).status, 404);
  });
});

test('clientDisconnectAbortsTheTurn', async () => {
  const model = new TrackedModel(['hang']);
  const { deps } = setup([]);
  const server = createServer({ config: { ...CONFIG, token: TOKEN } as GraphConfig, makeModel: () => model, openTools: deps.openTools });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  try {
    const port = (server.address() as AddressInfo).port;
    const body = JSON.stringify(req({ stream: true }));
    const request = http.request({
      host: '127.0.0.1', port, path: '/turn', method: 'POST',
      headers: { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body), 'X-CHAT-GRAPH-TOKEN': TOKEN },
    });
    request.on('error', () => {});
    request.end(body);
    const until = async (cond: () => boolean, what: string): Promise<void> => {
      const deadline = Date.now() + 3000;
      while (!cond()) {
        if (Date.now() > deadline) throw new Error(`timed out waiting for ${what}`);
        await new Promise((r) => setTimeout(r, 10));
      }
    };
    await until(() => model.calls.length > 0, 'the model call to start');
    assert.equal(model.settled, false);
    request.destroy();
    await until(() => model.settled, 'the model call to be aborted');
  } finally {
    await new Promise<void>((resolve) => { server.close(() => resolve()); server.closeAllConnections(); });
  }
});
