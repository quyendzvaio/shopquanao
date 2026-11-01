import { timingSafeEqual } from 'node:crypto';
import http from 'node:http';
import { pathToFileURL } from 'node:url';
import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import { loadConfig, type GraphConfig } from './config.js';
import { parseTurnRequest, RequestError, type TurnEvent } from './contract.js';
import { createChatModel } from './model.js';
import { openShopTools, type ShopTools } from './shopTools.js';
import { flushTracing, langfuseCallbacks } from './tracing.js';
import { runTurn } from './turn.js';

export type ServerDeps = {
  config: GraphConfig;
  /** Called once per turn, so a stateful model (tests) can be fresh each time. */
  makeModel: () => BaseChatModel;
  openTools: (userId: number | null) => Promise<ShopTools>;
  callbacks?: unknown[];
};

const MAX_BODY_BYTES = 256 * 1024;

function tokenMatches(given: string | undefined, expected: string): boolean {
  if (given === undefined) return false;
  const a = Buffer.from(given);
  const b = Buffer.from(expected);
  return a.length === b.length && timingSafeEqual(a, b);
}

function sendJson(res: http.ServerResponse, status: number, body: unknown): void {
  const payload = JSON.stringify(body);
  res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(payload) });
  res.end(payload);
}

async function readBody(req: http.IncomingMessage): Promise<string> {
  const chunks: Buffer[] = [];
  let size = 0;
  for await (const chunk of req) {
    const buf = chunk as Buffer;
    size += buf.length;
    if (size > MAX_BODY_BYTES) throw new RequestError('Invalid request: body too large');
    chunks.push(buf);
  }
  return Buffer.concat(chunks).toString('utf8');
}

async function handleTurn(deps: ServerDeps, req: http.IncomingMessage, res: http.ServerResponse): Promise<void> {
  const header = req.headers['x-chat-graph-token'];
  if (!tokenMatches(Array.isArray(header) ? header[0] : header, deps.config.token)) {
    sendJson(res, 401, { error: 'Unauthorized' });
    return;
  }
  let turn;
  try {
    let body: unknown;
    try {
      body = JSON.parse(await readBody(req));
    } catch (err) {
      if (err instanceof RequestError) throw err;
      throw new RequestError('Invalid request: body is not valid JSON');
    }
    turn = parseTurnRequest(body);
  } catch (err) {
    if (err instanceof RequestError) {
      sendJson(res, 400, { error: err.message });
      return;
    }
    throw err;
  }

  const turnDeps = { config: deps.config, model: deps.makeModel(), openTools: deps.openTools, callbacks: deps.callbacks };
  if (!turn.stream) {
    sendJson(res, 200, await runTurn(turn, turnDeps, () => {}));
    return;
  }
  res.writeHead(200, { 'content-type': 'application/x-ndjson', 'cache-control': 'no-cache', 'x-accel-buffering': 'no' });
  const write = (e: TurnEvent): void => {
    if (!res.destroyed && !res.writableEnded) res.write(`${JSON.stringify(e)}\n`);
  };
  await runTurn(turn, turnDeps, write);
  res.end();
}

export function createServer(deps: ServerDeps): http.Server {
  return http.createServer((req, res) => {
    const path = (req.url ?? '').split('?')[0];
    const route = async (): Promise<void> => {
      if (req.method === 'GET' && path === '/health') {
        sendJson(res, 200, { ok: true });
      } else if (req.method === 'POST' && path === '/turn') {
        await handleTurn(deps, req, res);
      } else {
        sendJson(res, 404, { error: 'Not found' });
      }
    };
    route().catch((err: unknown) => {
      console.error('[chat-graph] request failed:', err instanceof Error ? err.message : String(err));
      if (!res.headersSent) sendJson(res, 500, { error: 'Internal error' });
      else res.end();
    });
  });
}

function main(): void {
  const config = loadConfig();
  const model = createChatModel(config.llm);
  const server = createServer({
    config,
    makeModel: () => model,
    openTools: openShopTools,
    callbacks: langfuseCallbacks(config.langfuse),
  });
  server.listen(config.port, () => console.error(`[chat-graph] listening on :${config.port}`));
  const stop = (): void => {
    server.close();
    void flushTracing().finally(() => process.exit(0));
  };
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) main();
