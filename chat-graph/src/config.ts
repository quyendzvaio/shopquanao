export type GraphConfig = {
  appEnv: 'production' | 'development';
  port: number;
  token: string;
  llm: { baseUrl: string; apiKey: string; model: string; timeoutMs: number };
  budget: { maxToolCalls: number; maxModelCalls: number; turnTimeoutMs: number };
  freeship: { threshold: number; maxGap: number };
  langfuse: { enabled: boolean; baseUrl: string; publicKey: string; secretKey: string; traceContent: boolean };
};

type Env = Record<string, string | undefined>;

function num(env: Env, key: string, fallback: number): number {
  const raw = env[key];
  if (raw === undefined || raw.trim() === '') return fallback;
  const value = Number(raw);
  if (!Number.isFinite(value)) throw new Error(`${key} must be a number`);
  return value;
}

function str(env: Env, key: string, fallback = ''): string {
  const raw = env[key];
  return raw === undefined || raw === '' ? fallback : raw;
}

const TRACING_OFF_LINE = '[chat-graph] Langfuse tracing off: APP_ENV is not production';

/**
 * The only place in chat-graph that reads process.env.
 * Langfuse tracing is on only when APP_ENV=production AND LANGFUSE_ENABLED=true; with the flag on in any
 * other environment `log` gets one explanatory line (loadConfig runs once, at startup).
 */
export function loadConfig(env: Env = process.env, log: (message: string) => void = console.error): GraphConfig {
  const token = str(env, 'CHAT_GRAPH_TOKEN');
  if (token === '' || token === 'change-me') {
    throw new Error('CHAT_GRAPH_TOKEN must be configured with a non-default value');
  }
  const appEnv = str(env, 'APP_ENV').toLowerCase() === 'production' ? 'production' : 'development';
  const langfuseRequested = str(env, 'LANGFUSE_ENABLED').toLowerCase() === 'true';
  if (langfuseRequested && appEnv !== 'production') log(TRACING_OFF_LINE);
  return {
    appEnv,
    port: num(env, 'CHAT_GRAPH_PORT', 8090),
    token,
    llm: {
      baseUrl: str(env, 'LLM_BASE_URL', 'https://api.deepseek.com'),
      apiKey: str(env, 'LLM_API_KEY'),
      model: str(env, 'LLM_MODEL', 'deepseek-chat'),
      timeoutMs: num(env, 'LLM_TIMEOUT', 25) * 1000,
    },
    budget: {
      maxToolCalls: num(env, 'CHAT_GRAPH_MAX_TOOL_CALLS', 4),
      maxModelCalls: num(env, 'CHAT_GRAPH_MAX_MODEL_CALLS', 5),
      turnTimeoutMs: num(env, 'CHAT_GRAPH_TURN_TIMEOUT_MS', 45000),
    },
    freeship: {
      threshold: num(env, 'FREE_SHIPPING_THRESHOLD', 500000),
      maxGap: num(env, 'FREESHIP_NUDGE_MAX_GAP', 200000),
    },
    langfuse: {
      enabled: langfuseRequested && appEnv === 'production',
      baseUrl: str(env, 'LANGFUSE_INGESTION_URL', str(env, 'LANGFUSE_BASE_URL')),
      publicKey: str(env, 'LANGFUSE_PUBLIC_KEY'),
      secretKey: str(env, 'LANGFUSE_SECRET_KEY'),
      traceContent: ['true', '1', 'yes', 'on'].includes(str(env, 'LANGFUSE_TRACE_CONTENT').toLowerCase()),
    },
  };
}
