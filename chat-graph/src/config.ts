export type GraphConfig = {
  port: number;
  token: string;
  llm: { baseUrl: string; apiKey: string; model: string; timeoutMs: number };
  budget: { maxToolCalls: number; maxModelCalls: number; turnTimeoutMs: number };
  freeship: { threshold: number; maxGap: number };
  langfuse: { enabled: boolean; baseUrl: string; publicKey: string; secretKey: string };
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

/** The only place in chat-graph that reads process.env. */
export function loadConfig(env: Env = process.env): GraphConfig {
  const token = str(env, 'CHAT_GRAPH_TOKEN');
  if (token === '' || token === 'change-me') {
    throw new Error('CHAT_GRAPH_TOKEN must be configured with a non-default value');
  }
  return {
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
      enabled: str(env, 'LANGFUSE_ENABLED').toLowerCase() === 'true',
      baseUrl: str(env, 'LANGFUSE_INGESTION_URL', str(env, 'LANGFUSE_BASE_URL')),
      publicKey: str(env, 'LANGFUSE_PUBLIC_KEY'),
      secretKey: str(env, 'LANGFUSE_SECRET_KEY'),
    },
  };
}
