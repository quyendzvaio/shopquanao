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
  /** Aborts the turn from outside (e.g. the client went away): in-flight model calls are cancelled and the result is busy. */
  signal?: AbortSignal;
};

class TurnDeadline extends Error {}

const SENTENCE_END = /(?<=[.?!] )/;

/** Splits after `.`, `?`, `!` followed by a space; the pieces concatenate back to `text`. */
export function sentenceChunks(text: string): string[] {
  return text.split(SENTENCE_END).filter((c) => c !== '');
}

/**
 * The text events that end a turn, given what was already streamed: the rest of the final answer as deltas when the
 * stream is its beginning, otherwise one replace with the whole final answer (spec 2026-11-04 §4.4).
 */
export function closingTextEvents(streamed: string, message: string): TurnEvent[] {
  if (streamed === '') return sentenceChunks(message).map((text) => ({ type: 'delta', text }));
  if (message.startsWith(streamed)) return sentenceChunks(message.slice(streamed.length)).map((text) => ({ type: 'delta', text }));
  return [{ type: 'replace', text: message }];
}

/** Fire-and-forget: a slow or failing close() must never delay or break the reply. */
function closeQuietly(tools: ShopTools): void {
  try {
    tools.close().catch(() => {});
  } catch {
    // close() threw synchronously: nothing more to do.
  }
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
    pending_ticket: null,
  };
}

function primaryIntent(outcome: GraphOutcome): string {
  if (outcome.response_type === 'refusal') return 'unsupported_checkout';
  const first = outcome.tools_used[0];
  return (first !== undefined ? PRIMARY_INTENT[first] : undefined) ?? 'unknown';
}

/**
 * Runs one chat turn. Never throws: any error, model failure or deadline answers the busy result.
 * Progress events stream while tools run. On a streaming request the agent's answer streams sentence by sentence as
 * it is written (each sentence already checked, AnswerStreamer); the rest of the text, or a replace, and the cards are
 * emitted after the graph returned.
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
  // What the customer has already been sent of the answer while the graph ran.
  let streamed = '';

  // The deadline covers opening the tools, the graph run and (by not awaiting it) closing them.
  const deadline = new Promise<never>((_, reject) => {
    const stop = (reason: string): void => {
      abort.abort();
      reject(new TurnDeadline(reason));
    };
    timer = setTimeout(() => stop('turn deadline exceeded'), deps.config.budget.turnTimeoutMs);
    if (deps.signal?.aborted) stop('turn aborted');
    else deps.signal?.addEventListener('abort', () => stop('turn aborted'), { once: true });
  });
  deadline.catch(() => {});

  try {
    const opening = deps.openTools(req.user_id);
    // Tools that finish opening after the turn ended must still be closed.
    opening.then((late) => { if (finished) closeQuietly(late); }, () => {});
    tools = await Promise.race([opening, deadline]);
    const graph = buildTurnGraph({
      model: deps.model,
      tools,
      budget: () => budget,
      config: deps.config,
      callbacks: deps.callbacks,
      onProgress: (stage) => {
        if (!finished) emit({ type: 'progress', stage });
      },
      ...(req.stream ? {
        onAnswerText: (text: string) => {
          if (finished) return;
          streamed += text;
          emit({ type: 'delta', text });
        },
        onAnswerReplace: (text: string) => {
          if (finished) return;
          streamed = text;
          emit({ type: 'replace', text });
        },
      } : {}),
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
    abort.abort(); // Nothing of this turn may keep running (or spending tokens) after the reply.
    if (tools !== null) closeQuietly(tools);
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
    pending_ticket: outcome.pending_ticket,
    trace_id: randomBytes(16).toString('hex'),
    latency: { total_ms: Date.now() - startedAt, tool_calls: counts.tool_calls, model_calls: counts.model_calls },
  };

  for (const e of closingTextEvents(streamed, outcome.message)) emit(e);
  if (outcome.cards.length > 0) emit({ type: 'cards', products: outcome.cards });
  emit({ type: 'complete', ...summary });

  return { ...summary, message: outcome.message, products: outcome.cards };
}
