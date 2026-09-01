import assert from 'node:assert/strict';
import { createServer, type Server } from 'node:http';
import { once } from 'node:events';
import test from 'node:test';
import WebSocket from 'ws';
import { ChatStreamGateway, sanitizeProductCards } from '../src/chat-stream.js';

async function listen(server: Server): Promise<number> {
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const address = server.address();
  assert(address && typeof address !== 'string');
  return address.port;
}

async function close(server: Server): Promise<void> {
  await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
}

async function collectUntilComplete(socket: WebSocket): Promise<Record<string, unknown>[]> {
  return new Promise((resolve, reject) => {
    const events: Record<string, unknown>[] = [];
    const timeout = setTimeout(() => reject(new Error('timed out waiting for chat stream')), 3_000);
    socket.on('message', (raw) => {
      const event = JSON.parse(raw.toString()) as Record<string, unknown>;
      events.push(event);
      if (event.type === 'chat.complete' || event.type === 'chat.error') {
        clearTimeout(timeout);
        resolve(events);
      }
    });
    socket.on('error', reject);
  });
}

test('forwards native upstream token deltas and safe private cards', async () => {
  let receivedAuthorization = '';
  let receivedBody: Record<string, unknown> = {};
  const upstream = createServer((request, response) => {
    let body = '';
    request.on('data', (chunk: Buffer) => { body += chunk.toString('utf8'); });
    request.on('end', () => {
      receivedAuthorization = request.headers.authorization ?? '';
      receivedBody = JSON.parse(body) as Record<string, unknown>;
      response.writeHead(200, { 'content-type': 'application/x-ndjson' });
      response.write(JSON.stringify({ type: 'chat.progress', stage: 'pipeline' }) + '\n');
      response.write(JSON.stringify({ type: 'chat.delta', delta: 'Mình tìm được ' }) + '\n');
      response.write(JSON.stringify({ type: 'chat.delta', delta: 'hai sản phẩm phù hợp.' }) + '\n');
      response.write(JSON.stringify({
        type: 'chat.cards',
        products: [
          { id: 12, name: 'Quần kaki', price: 390000, stock: 3, provider_product_id: 'must-not-leak' },
          { id: 0, name: 'Invalid card' },
        ],
      }) + '\n');
      response.end(JSON.stringify({
        type: 'chat.complete',
        session_token: 'a'.repeat(64),
        session_id: 42,
        response_type: 'final_answer',
        primary_intent: 'suggest_complementary_products',
        trace_id: 'trace-safe',
        product_count: 1,
      }) + '\n');
    });
  });
  const upstreamPort = await listen(upstream);
  const gateway = new ChatStreamGateway({
    upstreamUrl: `http://127.0.0.1:${upstreamPort}/api/chatbot`,
    host: '127.0.0.1',
    port: 0,
    allowedOrigins: ['http://shop.test'],
  });
  const gatewayPort = await gateway.listen();

  const socket = new WebSocket(`ws://127.0.0.1:${gatewayPort}/ws/chatbot`, { origin: 'http://shop.test' });
  await once(socket, 'open');
  const eventsPromise = collectUntilComplete(socket);
  socket.send(JSON.stringify({
    type: 'chat.send',
    request_id: 'request-1234',
    message: 'Áo này phối với gì?',
    session_token: 'b'.repeat(32),
    authorization: 'Bearer user-token-safe',
  }));
  const events = await eventsPromise;

  assert.equal(events[0]?.type, 'chat.started');
  assert.equal(events[1]?.type, 'chat.progress');
  const answer = events.filter((event) => event.type === 'chat.delta').map((event) => event.delta).join('');
  assert.equal(answer, 'Mình tìm được hai sản phẩm phù hợp.');
  const cards = events.find((event) => event.type === 'chat.cards')?.products as Array<Record<string, unknown>>;
  assert.deepEqual(cards, [{ id: 12, name: 'Quần kaki', price: 390000, stock: 3 }]);
  assert.equal(events.at(-1)?.type, 'chat.complete');
  assert.equal(events.at(-1)?.product_count, 1);
  assert.equal(receivedAuthorization, 'Bearer user-token-safe');
  assert.deepEqual(receivedBody, { message: 'Áo này phối với gì?', session_token: 'b'.repeat(32) });

  socket.close();
  await gateway.close();
  await close(upstream);
});

test('rejects disallowed origins before any upstream request', async () => {
  const gateway = new ChatStreamGateway({
    upstreamUrl: 'http://127.0.0.1:1/api/chatbot',
    host: '127.0.0.1',
    port: 0,
    allowedOrigins: ['http://shop.test'],
  });
  const port = await gateway.listen();
  const socket = new WebSocket(`ws://127.0.0.1:${port}/ws/chatbot`, { origin: 'http://untrusted.test' });
  const [error] = await once(socket, 'error');
  assert(error instanceof Error);
  await gateway.close();
});

test('cards never expose provider identifiers', () => {
  assert.deepEqual(sanitizeProductCards([{ id: 7, name: 'Giày', provider_sku: 'glance-7', provider_product_id: 'x' }]), [{ id: 7, name: 'Giày' }]);
});
