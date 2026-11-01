import { z } from 'zod/v4';

export type HistoryMessage = { role: 'user' | 'assistant'; content: string };

export type MemoryContext = {
  summary: string;
  slots: Record<string, string | number>;
  profile: Record<string, unknown> | null;
};

export type TurnRequest = {
  message: string;
  user_id: number | null;
  session_id: number;
  history: HistoryMessage[];
  memory: MemoryContext;
  freeship_fingerprint: string | null;
  stream: boolean;
};

export type Card = {
  id: number;
  name: string;
  price: number;
  stock: number;
  image: string | null;
  image_url: string | null;
  url: string;
  category_id: number | null;
  available_sizes: string[];
  available_colors: string[];
  outfit_index?: number;
};

export type ResponseType = 'final_answer' | 'clarification' | 'refusal' | 'busy';

export type Offered = { id: number; name: string };

export type TurnSummary = {
  response_type: ResponseType;
  primary_intent: string;
  tools_used: string[];
  outfits: unknown[];
  offered: Offered[];
  freeship_nudge: boolean;
  freeship_fingerprint: string | null;
  trace_id: string;
  latency: Record<string, number | boolean>;
};

export type TurnResult = TurnSummary & { message: string; products: Card[] };

export type TurnEvent =
  | { type: 'progress'; stage: string }
  | { type: 'delta'; text: string }
  | { type: 'cards'; products: Card[] }
  | ({ type: 'complete' } & TurnSummary);

export type ToolRecord = {
  name: string;
  args: Record<string, unknown>;
  result: Record<string, unknown>;
};

export class RequestError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'RequestError';
  }
}

const turnRequestSchema = z.object({
  message: z.string().min(1).max(2000),
  user_id: z.number().int().positive().nullable(),
  session_id: z.number().int(),
  history: z.array(z.object({
    role: z.enum(['user', 'assistant']),
    content: z.string(),
  })).max(6),
  memory: z.object({
    summary: z.string(),
    slots: z.record(z.string(), z.union([z.string(), z.number()])),
    profile: z.record(z.string(), z.unknown()).nullable(),
  }),
  freeship_fingerprint: z.string().nullable(),
  stream: z.boolean(),
});

export function parseTurnRequest(body: unknown): TurnRequest {
  const parsed = turnRequestSchema.safeParse(body);
  if (!parsed.success) {
    const issue = parsed.error.issues[0];
    const path = issue && issue.path.length > 0 ? issue.path.join('.') : 'body';
    throw new RequestError(`Invalid request: ${path}: ${issue?.message ?? 'invalid'}`);
  }
  return parsed.data;
}
