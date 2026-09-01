#!/usr/bin/env node

import WebSocket from '../mcp-server/node_modules/ws/index.js';

const url = process.env.CHAT_STREAM_SMOKE_URL ?? 'ws://127.0.0.1/ws/chatbot';
const origin = process.env.CHAT_STREAM_SMOKE_ORIGIN ?? 'http://127.0.0.1';
const message = process.env.CHAT_STREAM_SMOKE_MESSAGE ?? 'tìm áo thun';
const firstDeltaBudgetMs = Number(process.env.CHAT_STREAM_FIRST_DELTA_BUDGET_MS ?? 10_000);
const totalBudgetMs = Number(process.env.CHAT_STREAM_TOTAL_BUDGET_MS ?? 30_000);
const hardTimeoutMs = Math.max(totalBudgetMs + 5_000, 35_000);

const socket = new WebSocket(url, { origin });
const startedAt = Date.now();
const events = [];
let settled = false;

function finish(code, result) {
  if (settled) return;
  settled = true;
  clearTimeout(hardTimeout);
  process.stdout.write(`${JSON.stringify(result)}\n`);
  process.exitCode = code;
  socket.close();
}

const hardTimeout = setTimeout(() => finish(1, {
  pass: false,
  reason: 'hard_timeout',
  elapsed_ms: Date.now() - startedAt,
}), hardTimeoutMs);

socket.on('open', () => {
  socket.send(JSON.stringify({
    type: 'chat.send',
    request_id: `latency-${Date.now()}`,
    message,
    session_token: '',
    authorization: null,
  }));
});

socket.on('message', (raw) => {
  const event = JSON.parse(raw.toString());
  events.push({ ...event, received_at_ms: Date.now() - startedAt });
  if (event.type !== 'chat.complete' && event.type !== 'chat.error') return;

  const deltas = events.filter((item) => item.type === 'chat.delta');
  const cards = events.find((item) => item.type === 'chat.cards')?.products ?? [];
  const firstDeltaMs = deltas[0]?.received_at_ms ?? null;
  const totalMs = Date.now() - startedAt;
  const providerIdLeak = JSON.stringify(events).includes('provider_');
  const pass = event.type === 'chat.complete'
    && deltas.length > 0
    && cards.length > 0
    && firstDeltaMs !== null
    && firstDeltaMs <= firstDeltaBudgetMs
    && totalMs <= totalBudgetMs
    && !providerIdLeak;

  finish(pass ? 0 : 1, {
    pass,
    terminal: event.type,
    first_delta_ms: firstDeltaMs,
    total_ms: totalMs,
    first_delta_budget_ms: firstDeltaBudgetMs,
    total_budget_ms: totalBudgetMs,
    delta_count: deltas.length,
    card_ids: cards.map((card) => card.id),
    provider_id_leak: providerIdLeak,
    server_latency: event.latency ?? {},
  });
});

socket.on('error', (error) => finish(1, {
  pass: false,
  reason: 'websocket_error',
  message: error instanceof Error ? error.message : String(error),
  elapsed_ms: Date.now() - startedAt,
}));
