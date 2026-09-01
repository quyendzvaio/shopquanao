#!/usr/bin/env node

import WebSocket from '../mcp-server/node_modules/ws/index.js';

const message = process.argv[2] ?? '';
const url = process.env.UC2_WS_URL ?? 'ws://nginx/ws/chatbot';
const origin = process.env.UC2_WS_ORIGIN ?? 'http://nginx';
const sessionToken = process.env.UC2_SESSION_TOKEN ?? '';
const authorization = process.env.UC2_AUTHORIZATION ?? '';

if (!message || !sessionToken || !authorization) {
  process.stderr.write('UC2 WebSocket smoke configuration is incomplete\n');
  process.exit(2);
}

const requestId = `uc2-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
const socket = new WebSocket(url, { origin });
const events = [];
let settled = false;

function finish(code, result) {
  if (settled) return;
  settled = true;
  clearTimeout(timeout);
  process.stdout.write(`${JSON.stringify(result)}\n`);
  socket.close();
  process.exitCode = code;
}

const timeout = setTimeout(() => finish(1, {
  status: 'FAIL', reason: 'timeout', elapsed_ms: 95_000,
}), 95_000);

socket.on('open', () => socket.send(JSON.stringify({
  type: 'chat.send', request_id: requestId, message, session_token: sessionToken, authorization,
})));

socket.on('message', (raw) => {
  const event = JSON.parse(raw.toString());
  events.push(event);
  if (event.type !== 'chat.complete' && event.type !== 'chat.error') return;
  const cards = events.find((item) => item.type === 'chat.cards')?.products ?? [];
  const encoded = JSON.stringify(events);
  finish(event.type === 'chat.complete' ? 0 : 1, {
    status: event.type === 'chat.complete' ? 'PASS' : 'FAIL',
    terminal: event.type,
    primary_intent: event.primary_intent ?? null,
    proactive_styling: event.proactive_styling === true,
    product_ids: cards.map((card) => card.id).filter(Number.isInteger),
    delta_count: events.filter((item) => item.type === 'chat.delta').length,
    provider_id_leak: encoded.includes('provider_'),
    error_code: event.type === 'chat.error' ? event.code ?? null : null,
  });
});

socket.on('error', (error) => finish(1, {
  status: 'FAIL', reason: 'websocket_error', message: error.message,
}));
