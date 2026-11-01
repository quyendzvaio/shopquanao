import { randomBytes } from 'node:crypto';
import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import type { GraphConfig } from './config.js';
import type { TurnEvent, TurnRequest, TurnResult, TurnSummary } from './contract.js';
import { BUSY, PRIMARY_INTENT } from './copy.js';
import { buildTurnGraph, type GraphOutcome } from './graph/buildGraph.js';
import { TurnBudget } from './policies/budget.js';
import type { ShopTools } from './shopTools.js';

export type TurnDeps = {
  config: GraphConfig;
  model: BaseChatModel;
  openTools: (userId: number | null) => Promise<ShopTools>;
  /** Callback handlers (Langfuse) for the turn's model and agent runs. */
  callbacks?: unknown[];
};

class TurnDeadline extends Error {}

const SENTENCE_END = /(?<=[.?!] )/;

/** Splits after `.`, `?`, `!` followed by a space; the pieces concatenate back to `text`. */
export function sentenceChunks(text: string): string[] {
  return text.split(SENTENCE_END).filter((c) => c !== '');
}

function busyOutcome(): GraphOutcome {
  return {
    message: BUSY,
    response_type: 'busy',
    cards: [],
    outfits: [],
    offered: [],
    tools_used: [],
    freeship_nudge: false,
    freeship_fingerprint: null,
  };
}

function primaryIntent(outcome: GraphOutcome): string {
  if (outcome.response_type === 'refusal') return 'unsupported_checkout';
  const first = outcome.tools_used[0];
  return (first !== undefined ? PRIMARY_INTENT[first] : undefined) ?? 'unknown';
}

/**
 * Runs one chat turn. Never throws: any error, model failure or deadline answers the busy result.
 * Progress events stream while tools run; the text and cards are emitted only after the graph returned.
 */
export async function runTurn(
  req: TurnRequest,
  deps: TurnDeps,
  emit: (e: TurnEvent) => void,
): Promise<TurnResult> {
  const startedAt = Date.now();
  const budget = new TurnBudget(deps.config.budget);
  const abort = new AbortController();
  let finished = false;
  let timer: NodeJS.Timeout | undefined;
  let tools: ShopTools | null = null;
  let outcome: GraphOutcome;

  try {
    tools = await deps.openTools(req.user_id);
    const graph = buildTurnGraph({
      model: deps.model,
      tools,
      budget: () => budget,
      config: deps.config,
      callbacks: deps.callbacks,
      onProgress: (stage) => {
        if (!finished) emit({ type: 'progress', stage });
      },
    });
    const deadline = new Promise<never>((_, reject) => {
      timer = setTimeout(() => {
        abort.abort();
        reject(new TurnDeadline('turn deadline exceeded'));
      }, deps.config.budget.turnTimeoutMs);
    });
    const run = graph.invoke(req, { signal: abort.signal });
    run.catch(() => {}); // A run abandoned at the deadline must not become an unhandled rejection.
    outcome = await Promise.race([run, deadline]);
  } catch (err) {
    console.error('[chat-graph] turn failed:', err instanceof Error ? err.message : String(err));
    outcome = busyOutcome();
  } finally {
    finished = true;
    if (timer !== undefined) clearTimeout(timer);
    if (tools !== null) {
      try {
        await tools.close();
      } catch {
        // Closing is best effort.
      }
    }
  }

  const counts = budget.counts();
  const summary: TurnSummary = {
    response_type: outcome.response_type,
    primary_intent: primaryIntent(outcome),
    tools_used: outcome.tools_used,
    outfits: outcome.outfits,
    offered: outcome.offered,
    freeship_nudge: outcome.freeship_nudge,
    freeship_fingerprint: outcome.freeship_fingerprint,
    trace_id: randomBytes(16).toString('hex'),
    latency: { total_ms: Date.now() - startedAt, tool_calls: counts.tool_calls, model_calls: counts.model_calls },
  };

  for (const text of sentenceChunks(outcome.message)) emit({ type: 'delta', text });
  if (outcome.cards.length > 0) emit({ type: 'cards', products: outcome.cards });
  emit({ type: 'complete', ...summary });

  return { ...summary, message: outcome.message, products: outcome.cards };
}
