#!/usr/bin/env node

// Latency smoke for the storefront chat stream (Server-Sent Events over POST).
const url = process.env.CHAT_STREAM_SMOKE_URL ?? 'http://127.0.0.1/api/chatbot/stream';
const origin = process.env.CHAT_STREAM_SMOKE_ORIGIN ?? new URL(url).origin;
const message = process.env.CHAT_STREAM_SMOKE_MESSAGE ?? 'tìm áo thun';
const firstDeltaBudgetMs = Number(process.env.CHAT_STREAM_FIRST_DELTA_BUDGET_MS ?? 10_000);
const totalBudgetMs = Number(process.env.CHAT_STREAM_TOTAL_BUDGET_MS ?? 30_000);
const hardTimeoutMs = Math.max(totalBudgetMs + 5_000, 35_000);

const startedAt = Date.now();
const events = [];

function report(code, result) {
  process.stdout.write(`${JSON.stringify(result)}\n`);
  process.exit(code);
}

async function readEvents(response) {
  const decoder = new TextDecoder();
  let buffer = '';
  for await (const chunk of response.body) {
    buffer += decoder.decode(chunk, { stream: true });
    let boundary;
    while ((boundary = buffer.indexOf('\n\n')) !== -1) {
      const frame = buffer.slice(0, boundary);
      buffer = buffer.slice(boundary + 2);
      const data = frame.split('\n').filter((line) => line.startsWith('data:')).map((line) => line.slice(5).trimStart()).join('\n');
      if (!data) continue;
      const event = JSON.parse(data);
      events.push({ ...event, received_at_ms: Date.now() - startedAt });
      if (event.type === 'chat.complete' || event.type === 'chat.error') return event;
    }
  }
  return null;
}

try {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'content-type': 'application/json', origin },
    body: JSON.stringify({ message, session_token: '' }),
    signal: AbortSignal.timeout(hardTimeoutMs),
  });
  if (!response.ok || !response.body) {
    report(1, { pass: false, reason: 'http_status', status: response.status, elapsed_ms: Date.now() - startedAt });
  }
  const terminal = await readEvents(response);
  const deltas = events.filter((item) => item.type === 'chat.delta');
  const cards = events.find((item) => item.type === 'chat.cards')?.products ?? [];
  const firstDeltaMs = deltas[0]?.received_at_ms ?? null;
  const totalMs = Date.now() - startedAt;
  const providerIdLeak = JSON.stringify(events).includes('provider_');
  const pass = terminal?.type === 'chat.complete'
    && deltas.length > 0
    && cards.length > 0
    && firstDeltaMs !== null
    && firstDeltaMs <= firstDeltaBudgetMs
    && totalMs <= totalBudgetMs
    && !providerIdLeak;

  report(pass ? 0 : 1, {
    pass,
    terminal: terminal?.type ?? 'stream_ended',
    first_delta_ms: firstDeltaMs,
    total_ms: totalMs,
    first_delta_budget_ms: firstDeltaBudgetMs,
    total_budget_ms: totalBudgetMs,
    delta_count: deltas.length,
    card_ids: cards.map((card) => card.id),
    provider_id_leak: providerIdLeak,
    server_latency: terminal?.latency ?? {},
  });
} catch (error) {
  report(1, {
    pass: false,
    reason: error?.name === 'TimeoutError' ? 'hard_timeout' : 'stream_error',
    message: error instanceof Error ? error.message : String(error),
    elapsed_ms: Date.now() - startedAt,
  });
}
