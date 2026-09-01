import { createServer, type IncomingMessage, type Server } from 'node:http';
import { type AddressInfo } from 'node:net';
import { type Duplex } from 'node:stream';
import { pathToFileURL } from 'node:url';
import WebSocket, { WebSocketServer, type RawData } from 'ws';

type JsonRecord = Record<string, unknown>;

type FetchLike = typeof fetch;

type UpstreamStreamEvent = JsonRecord & { type: string };

export type ChatStreamGatewayOptions = {
  upstreamUrl: string;
  host?: string;
  port?: number;
  allowedOrigins?: string[];
  requestTimeoutMs?: number;
  maxMessageChars?: number;
  rateLimitPerMinute?: number;
  fetchFn?: FetchLike;
};

type ClientChatMessage = {
  type: 'chat.send';
  requestId: string;
  message: string;
  sessionToken: string;
  authorization: string | null;
};

type GatewayErrorCode =
  | 'INVALID_MESSAGE'
  | 'RATE_LIMITED'
  | 'UNAUTHORIZED'
  | 'UPSTREAM_TIMEOUT'
  | 'UPSTREAM_UNAVAILABLE'
  | 'INVALID_UPSTREAM_RESPONSE'
  | 'REQUEST_IN_PROGRESS';

class GatewayError extends Error {
  constructor(public readonly code: GatewayErrorCode, message: string) {
    super(message);
  }
}

class SlidingWindowRateLimiter {
  private readonly hits = new Map<string, number[]>();

  constructor(private readonly limit: number, private readonly windowMs = 60_000) {}

  allow(key: string): boolean {
    const now = Date.now();
    const threshold = now - this.windowMs;
    const active = (this.hits.get(key) ?? []).filter((timestamp) => timestamp > threshold);
    if (active.length >= this.limit) {
      this.hits.set(key, active);
      return false;
    }
    active.push(now);
    this.hits.set(key, active);
    return true;
  }
}

const safeProductFields = new Set([
  'id', 'name', 'price', 'stock', 'image', 'image_url', 'url', 'category_id',
  'subcategory_id', 'variant_id', 'available_sizes', 'available_colors',
]);

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function headerValue(value: string | string[] | undefined): string | null {
  if (Array.isArray(value)) return value[0] ?? null;
  return typeof value === 'string' ? value : null;
}

function json(socket: WebSocket, event: JsonRecord): boolean {
  if (socket.readyState !== WebSocket.OPEN || socket.bufferedAmount > 512 * 1024) {
    if (socket.bufferedAmount > 512 * 1024) socket.terminate();
    return false;
  }
  socket.send(JSON.stringify(event));
  return true;
}

function rejectUpgrade(socket: Duplex, status: number): void {
  socket.write(`HTTP/1.1 ${status} ${status === 403 ? 'Forbidden' : 'Bad Request'}\r\nConnection: close\r\n\r\n`);
  socket.destroy();
}

function requestIp(request: IncomingMessage): string {
  const forwarded = headerValue(request.headers['x-forwarded-for']);
  if (forwarded) return forwarded.split(',')[0]?.trim() || 'unknown';
  return request.socket.remoteAddress ?? 'unknown';
}

function originAllowed(request: IncomingMessage, allowedOrigins: readonly string[]): boolean {
  const origin = headerValue(request.headers.origin);
  if (!origin) return false;

  try {
    const originUrl = new URL(origin);
    if (allowedOrigins.length > 0) return allowedOrigins.includes(originUrl.origin);

    const host = headerValue(request.headers['x-forwarded-host']) ?? headerValue(request.headers.host);
    const protocol = (headerValue(request.headers['x-forwarded-proto']) ?? 'http').split(',')[0]?.trim();
    return Boolean(host && protocol && originUrl.origin === `${protocol}://${host}`);
  } catch {
    return false;
  }
}

function rawText(raw: RawData): string {
  if (Array.isArray(raw)) return Buffer.concat(raw).toString('utf8');
  if (Buffer.isBuffer(raw)) return raw.toString('utf8');
  if (raw instanceof ArrayBuffer) return Buffer.from(raw).toString('utf8');
  return Buffer.from(raw).toString('utf8');
}

function parseClientMessage(raw: RawData, maxMessageChars: number): ClientChatMessage {
  const text = rawText(raw);
  if (Buffer.byteLength(text, 'utf8') > 16 * 1024) {
    throw new GatewayError('INVALID_MESSAGE', 'Yêu cầu chat quá lớn.');
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(text);
  } catch {
    throw new GatewayError('INVALID_MESSAGE', 'Yêu cầu chat không hợp lệ.');
  }
  if (!isRecord(parsed) || parsed.type !== 'chat.send') {
    throw new GatewayError('INVALID_MESSAGE', 'Loại sự kiện chat không hợp lệ.');
  }

  const requestId = typeof parsed.request_id === 'string' ? parsed.request_id : '';
  const message = typeof parsed.message === 'string' ? parsed.message.trim() : '';
  const sessionToken = typeof parsed.session_token === 'string' ? parsed.session_token.trim() : '';
  const authorization = typeof parsed.authorization === 'string' ? parsed.authorization.trim() : null;

  if (!/^[A-Za-z0-9_-]{8,96}$/.test(requestId) || !message || message.length > maxMessageChars) {
    throw new GatewayError('INVALID_MESSAGE', 'Nội dung chat hoặc mã yêu cầu không hợp lệ.');
  }
  if (sessionToken !== '' && !/^[a-fA-F0-9]{16,64}$/.test(sessionToken)) {
    throw new GatewayError('INVALID_MESSAGE', 'Phiên chat không hợp lệ.');
  }
  if (authorization !== null && (!/^Bearer\s+[^\r\n]{8,512}$/.test(authorization))) {
    throw new GatewayError('INVALID_MESSAGE', 'Thông tin xác thực không hợp lệ.');
  }

  return { type: 'chat.send', requestId, message, sessionToken, authorization };
}

export function sanitizeProductCards(value: unknown): JsonRecord[] {
  if (!Array.isArray(value)) return [];
  const cards: JsonRecord[] = [];
  for (const candidate of value) {
    if (!isRecord(candidate) || !Number.isInteger(candidate.id) || Number(candidate.id) <= 0) continue;
    const card: JsonRecord = {};
    for (const [key, fieldValue] of Object.entries(candidate)) {
      if (safeProductFields.has(key)) card[key] = fieldValue;
    }
    cards.push(card);
  }
  return cards;
}

function safeCompletion(upstream: JsonRecord, cards: JsonRecord[]): JsonRecord {
  const completion: JsonRecord = {
    type: 'chat.complete',
    session_token: typeof upstream.session_token === 'string' ? upstream.session_token : '',
    session_id: Number.isInteger(upstream.session_id) ? upstream.session_id : null,
    response_type: typeof upstream.response_type === 'string' ? upstream.response_type : 'final_answer',
    primary_intent: typeof upstream.primary_intent === 'string' ? upstream.primary_intent : 'unknown',
    trace_id: typeof upstream.trace_id === 'string' ? upstream.trace_id : null,
    product_count: cards.length,
  };
  if (typeof upstream.proactive_styling === 'boolean') completion.proactive_styling = upstream.proactive_styling;
  if (isRecord(upstream.latency)) {
    completion.latency = Object.fromEntries(Object.entries(upstream.latency).filter(([, value]) => (
      typeof value === 'number' && Number.isFinite(value)
    ) || typeof value === 'boolean'));
  }
  return completion;
}

export class ChatStreamGateway {
  private readonly server: Server;
  private readonly sockets = new Set<WebSocket>();
  private readonly inFlight = new WeakSet<WebSocket>();
  private readonly rateLimiter: SlidingWindowRateLimiter;
  private readonly options: Required<Omit<ChatStreamGatewayOptions, 'fetchFn' | 'allowedOrigins'>> & {
    allowedOrigins: string[];
    fetchFn: FetchLike;
  };

  constructor(options: ChatStreamGatewayOptions) {
    const upstreamUrl = new URL(options.upstreamUrl);
    if (!['http:', 'https:'].includes(upstreamUrl.protocol)) {
      throw new Error('CHAT_STREAM_UPSTREAM_URL must use http or https');
    }

    this.options = {
      upstreamUrl: upstreamUrl.toString(),
      host: options.host ?? '0.0.0.0',
      port: options.port ?? 8090,
      allowedOrigins: options.allowedOrigins ?? [],
      requestTimeoutMs: options.requestTimeoutMs ?? 90_000,
      maxMessageChars: options.maxMessageChars ?? 2_000,
      rateLimitPerMinute: options.rateLimitPerMinute ?? 30,
      fetchFn: options.fetchFn ?? fetch,
    };
    this.rateLimiter = new SlidingWindowRateLimiter(this.options.rateLimitPerMinute);

    const websocketServer = new WebSocketServer({ noServer: true, maxPayload: 16 * 1024 });
    websocketServer.on('connection', (socket, request) => this.onConnection(socket, request));
    this.server = createServer((request, response) => {
      if (request.method === 'GET' && request.url === '/healthz') {
        response.writeHead(200, { 'content-type': 'application/json', 'cache-control': 'no-store' });
        response.end('{"status":"ok"}');
        return;
      }
      response.writeHead(426, { 'content-type': 'application/json', 'cache-control': 'no-store' });
      response.end('{"error":"websocket upgrade required"}');
    });
    this.server.on('upgrade', (request, socket, head) => {
      const path = new URL(request.url ?? '/', 'http://gateway.local').pathname;
      if (path !== '/ws/chatbot' || !originAllowed(request, this.options.allowedOrigins)) {
        rejectUpgrade(socket, path === '/ws/chatbot' ? 403 : 400);
        return;
      }
      websocketServer.handleUpgrade(request, socket, head, (client) => websocketServer.emit('connection', client, request));
    });
  }

  async listen(): Promise<number> {
    await new Promise<void>((resolve, reject) => {
      this.server.once('error', reject);
      this.server.listen(this.options.port, this.options.host, () => {
        this.server.off('error', reject);
        resolve();
      });
    });
    const address = this.server.address() as AddressInfo;
    return address.port;
  }

  async close(): Promise<void> {
    for (const socket of this.sockets) socket.terminate();
    await new Promise<void>((resolve, reject) => this.server.close((error) => error ? reject(error) : resolve()));
  }

  private onConnection(socket: WebSocket, request: IncomingMessage): void {
    this.sockets.add(socket);
    socket.on('close', () => this.sockets.delete(socket));
    socket.on('error', () => this.sockets.delete(socket));
    socket.on('message', (raw) => { void this.onMessage(socket, request, raw); });
  }

  private async onMessage(socket: WebSocket, request: IncomingMessage, raw: RawData): Promise<void> {
    let message: ClientChatMessage;
    try {
      message = parseClientMessage(raw, this.options.maxMessageChars);
    } catch (error) {
      this.emitError(socket, 'unknown', error);
      return;
    }
    if (this.inFlight.has(socket)) {
      this.emitError(socket, message.requestId, new GatewayError('REQUEST_IN_PROGRESS', 'Yêu cầu trước đó chưa hoàn tất.'));
      return;
    }
    if (!this.rateLimiter.allow(requestIp(request))) {
      this.emitError(socket, message.requestId, new GatewayError('RATE_LIMITED', 'Bạn gửi tin nhắn quá nhanh, vui lòng thử lại sau.'));
      return;
    }

    this.inFlight.add(socket);
    try {
      if (!json(socket, { type: 'chat.started', request_id: message.requestId })) return;
      if (!json(socket, { type: 'chat.progress', request_id: message.requestId, stage: 'processing' })) return;
      let streamedCards: JsonRecord[] = [];
      const upstream = await this.callUpstreamStream(message, (event) => {
        if (event.type === 'chat.progress') {
          return json(socket, { ...event, request_id: message.requestId });
        }
        if (event.type === 'chat.delta') {
          const delta = typeof event.delta === 'string' ? event.delta : '';
          if (!delta) throw new GatewayError('INVALID_UPSTREAM_RESPONSE', 'LLM stream trả về token rỗng.');
          return json(socket, { type: 'chat.delta', request_id: message.requestId, delta });
        }
        if (event.type === 'chat.cards') {
          const cards = sanitizeProductCards(event.products);
          streamedCards = cards;
          return cards.length === 0 || json(socket, { type: 'chat.cards', request_id: message.requestId, products: cards });
        }
        return true;
      });

      const cards = streamedCards.length > 0
        ? streamedCards
        : sanitizeProductCards(upstream.products ?? upstream.cards);
      const completion = safeCompletion(upstream, cards);
      completion.request_id = message.requestId;
      json(socket, completion);
    } catch (error) {
      this.emitError(socket, message.requestId, error);
    } finally {
      this.inFlight.delete(socket);
    }
  }

  private async callUpstreamStream(
    message: ClientChatMessage,
    onEvent: (event: UpstreamStreamEvent) => boolean,
  ): Promise<JsonRecord> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.options.requestTimeoutMs);
    try {
      const headers: Record<string, string> = { 'content-type': 'application/json' };
      if (message.authorization !== null) headers.authorization = message.authorization;
      const response = await this.options.fetchFn(this.options.upstreamUrl, {
        method: 'POST',
        headers,
        body: JSON.stringify({ message: message.message, session_token: message.sessionToken }),
        signal: controller.signal,
      });
      if (response.status === 401 || response.status === 403) {
        throw new GatewayError('UNAUTHORIZED', 'Phiên đăng nhập không hợp lệ.');
      }
      if (response.status === 429) {
        throw new GatewayError('RATE_LIMITED', 'Bạn gửi tin nhắn quá nhanh, vui lòng thử lại sau.');
      }
      if (!response.ok) {
        throw new GatewayError('UPSTREAM_UNAVAILABLE', 'Dịch vụ chat tạm thời không khả dụng.');
      }
      if (!response.body) throw new GatewayError('INVALID_UPSTREAM_RESPONSE', 'Dịch vụ chat không mở stream.');

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let pending = '';
      let completion: JsonRecord | null = null;
      let sawDelta = false;
      const consumeLine = (line: string): void => {
        const trimmed = line.trim();
        if (!trimmed) return;
        let event: unknown;
        try { event = JSON.parse(trimmed); } catch { throw new GatewayError('INVALID_UPSTREAM_RESPONSE', 'Dịch vụ chat trả về NDJSON không hợp lệ.'); }
        if (!isRecord(event) || typeof event.type !== 'string') {
          throw new GatewayError('INVALID_UPSTREAM_RESPONSE', 'Dịch vụ chat trả về sự kiện không hợp lệ.');
        }
        if (event.type === 'chat.error') {
          throw new GatewayError('UPSTREAM_UNAVAILABLE', typeof event.message === 'string' ? event.message : 'Dịch vụ chat tạm thời không khả dụng.');
        }
        if (event.type === 'chat.delta') sawDelta = true;
        if (event.type === 'chat.complete') completion = event;
        if (!onEvent(event as UpstreamStreamEvent)) throw new GatewayError('UPSTREAM_UNAVAILABLE', 'Kết nối WebSocket đã đóng.');
      };
      while (true) {
        const chunk = await reader.read();
        if (chunk.done) break;
        pending += decoder.decode(chunk.value, { stream: true });
        let newline: number;
        while ((newline = pending.indexOf('\n')) >= 0) {
          const line = pending.slice(0, newline);
          pending = pending.slice(newline + 1);
          consumeLine(line);
        }
      }
      pending += decoder.decode();
      if (pending.trim()) consumeLine(pending);
      if (!completion || !sawDelta) throw new GatewayError('INVALID_UPSTREAM_RESPONSE', 'Dịch vụ chat không trả về token hoặc completion.');
      return completion;
    } catch (error) {
      if (error instanceof GatewayError) throw error;
      if (error instanceof Error && error.name === 'AbortError') {
        throw new GatewayError('UPSTREAM_TIMEOUT', 'Dịch vụ chat phản hồi quá lâu, vui lòng thử lại.');
      }
      throw new GatewayError('UPSTREAM_UNAVAILABLE', 'Không thể kết nối dịch vụ chat, vui lòng thử lại sau.');
    } finally {
      clearTimeout(timeout);
    }
  }

  private emitError(socket: WebSocket, requestId: string, error: unknown): void {
    const safe = error instanceof GatewayError
      ? error
      : new GatewayError('UPSTREAM_UNAVAILABLE', 'Không thể kết nối dịch vụ chat, vui lòng thử lại sau.');
    json(socket, { type: 'chat.error', request_id: requestId, code: safe.code, message: safe.message });
  }
}

function numberEnv(name: string, fallback: number): number {
  const value = Number(process.env[name] ?? fallback);
  return Number.isFinite(value) && value > 0 ? Math.floor(value) : fallback;
}

export function gatewayOptionsFromEnv(): ChatStreamGatewayOptions {
  return {
    upstreamUrl: process.env.CHAT_STREAM_UPSTREAM_URL ?? 'http://127.0.0.1:8080/api/chatbot',
    host: process.env.CHAT_STREAM_HOST ?? '0.0.0.0',
    port: numberEnv('CHAT_STREAM_PORT', 8090),
    allowedOrigins: (process.env.CHAT_STREAM_ALLOWED_ORIGINS ?? '').split(',').map((value) => value.trim()).filter(Boolean),
    requestTimeoutMs: numberEnv('CHAT_STREAM_REQUEST_TIMEOUT_MS', 90_000),
    maxMessageChars: numberEnv('CHAT_STREAM_MAX_MESSAGE_CHARS', 2_000),
    rateLimitPerMinute: numberEnv('CHAT_STREAM_RATE_LIMIT_PER_MINUTE', 30),
  };
}

async function main(): Promise<void> {
  const gateway = new ChatStreamGateway(gatewayOptionsFromEnv());
  const port = await gateway.listen();
  process.stderr.write(`Chat stream gateway listening on ${port}\n`);
  const shutdown = () => { void gateway.close().finally(() => process.exit(0)); };
  process.once('SIGINT', shutdown);
  process.once('SIGTERM', shutdown);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  void main();
}
