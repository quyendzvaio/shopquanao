import { AIMessage, HumanMessage, SystemMessage, type BaseMessage } from '@langchain/core/messages';
import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import type { RunnableConfig } from '@langchain/core/runnables';
import { Annotation, END, START, StateGraph } from '@langchain/langgraph';
import { createAgent } from 'langchain';
import type { GraphConfig } from '../config.js';
import type { Card, Offered, PendingTicket, ResponseType, ToolRecord, TurnRequest } from '../contract.js';
import { BUSY, TICKET_CONFIRM_QUESTION, askAlternatives, checkoutRefusal, nudgeText, soldOutCorrection, soldOutNoAlternatives, ticketDraftLine } from '../copy.js';
import type { TurnBudget } from '../policies/budget.js';
import { outfitsFrom, productToCard, selectCards } from '../policies/cards.js';
import { matchesCheckout } from '../policies/checkout.js';
import { hasToolCallMarkup, plainText, summaryFromCards, ungroundedClaims } from '../policies/grounding.js';
import type { ShopTools } from '../shopTools.js';
import { policyMiddleware, TurnRecorder } from './middleware.js';
import { systemPrompt } from './prompt.js';
import { answersSoldOut, type SoldOutState } from './soldOut.js';
import { textOf } from './text.js';

export type GraphOutcome = {
  message: string;
  response_type: ResponseType;
  cards: Card[];
  outfits: unknown[];
  offered: Offered[];
  tools_used: string[];
  freeship_nudge: boolean;
  freeship_fingerprint: string | null;
  pending_ticket: PendingTicket | null;
};

export type GraphDeps = {
  model: BaseChatModel;
  tools: ShopTools;
  /** A budget is per turn: pass a factory when one graph serves several `invoke` calls. */
  budget: TurnBudget | (() => TurnBudget);
  config: GraphConfig;
  onProgress?: (stage: string) => void;
  /** Callback handlers (e.g. Langfuse) passed to every model and agent run of a turn. */
  callbacks?: unknown[];
};

export type InvokeOptions = {
  /** Aborts in-flight model calls; the graph does not enforce the turn deadline itself. */
  signal?: AbortSignal;
};

const MAX_CARDS = 6;
const SHOPPING_TOOLS = ['search_products', 'get_product_detail', 'build_outfit', 'find_similar_in_stock', 'get_order_status'];

/** The last `draft_support_ticket` result of the turn that is a valid draft; refused drafts never count. */
function lastValidDraft(records: ToolRecord[]): PendingTicket | null {
  for (let i = records.length - 1; i >= 0; i--) {
    const r = records[i];
    if (!r || r.name !== 'draft_support_ticket' || r.result.status !== 'draft') continue;
    const d = r.result.draft;
    if (typeof d !== 'object' || d === null) continue;
    const { type, order_id: orderId, summary } = d as Record<string, unknown>;
    if (typeof type !== 'string' || typeof summary !== 'string' || summary.trim() === '') continue;
    const order = orderId === null || orderId === undefined ? null : Number(orderId);
    if (order !== null && !Number.isInteger(order)) continue;
    return { type, order_id: order, summary };
  }
  return null;
}

const last = <T>(_: T, b: T): T => b;

const State = Annotation.Root({
  req: Annotation<TurnRequest>(),
  refused: Annotation<boolean>({ reducer: last, default: () => false }),
  draft: Annotation<string>({ reducer: last, default: () => '' }),
  messages: Annotation<BaseMessage[]>({ reducer: last, default: () => [] }),
  records: Annotation<ToolRecord[]>({ reducer: last, default: () => [] }),
  soldOut: Annotation<SoldOutState | null>({ reducer: last, default: () => null }),
  /** The answer already tells the sold-out news and offers the alternatives, so no fixed question is appended. */
  soldOutAnswered: Annotation<boolean>({ reducer: last, default: () => false }),
  text: Annotation<string>({ reducer: last, default: () => '' }),
  responseType: Annotation<ResponseType>({ reducer: last, default: () => 'final_answer' }),
  cards: Annotation<Card[]>({ reducer: last, default: () => [] }),
  outfits: Annotation<unknown[]>({ reducer: last, default: () => [] }),
  offered: Annotation<Offered[]>({ reducer: last, default: () => [] }),
  toolsUsed: Annotation<string[]>({ reducer: last, default: () => [] }),
  nudged: Annotation<boolean>({ reducer: last, default: () => false }),
  fingerprint: Annotation<string | null>({ reducer: last, default: () => null }),
  pendingTicket: Annotation<PendingTicket | null>({ reducer: last, default: () => null }),
});

type S = typeof State.State;

/**
 * The model's answer: the latest non-empty AI message without tool calls. An empty continuation step (or a
 * budget stop) after an answer keeps that answer instead of erasing it.
 */
function finalAnswer(messages: BaseMessage[]): string {
  for (let i = messages.length - 1; i >= 0; i--) {
    const m = messages[i];
    if (!m || !AIMessage.isInstance(m) || (m.tool_calls?.length ?? 0) > 0) continue;
    const text = textOf(m);
    if (text !== '') return text;
  }
  return '';
}


function num(v: unknown): number {
  return typeof v === 'number' ? v : Number(v);
}

/** Builds the graph around one turn's budget and recorder; never reuse the result for a second turn. */
function compileTurnGraph(deps: GraphDeps, budget: TurnBudget, recorder: TurnRecorder) {
  const { model, tools, config, onProgress } = deps;

  const guard = (s: S): Partial<S> => {
    if (!matchesCheckout(s.req.message)) return {};
    return { refused: true, text: checkoutRefusal(s.req.message), responseType: 'refusal' };
  };

  const agentNode = async (s: S, runConfig: RunnableConfig): Promise<Partial<S>> => {
    const agent = createAgent({
      model,
      tools: tools.tools,
      systemPrompt: systemPrompt(s.req),
      middleware: [policyMiddleware({ tools, budget, recorder, onProgress })],
    });
    const input: BaseMessage[] = [
      ...s.req.history.map((m) => (m.role === 'user' ? new HumanMessage(m.content) : new AIMessage(m.content))),
      new HumanMessage(s.req.message),
    ];
    const result = await agent.invoke({ messages: input }, runConfig);
    const messages = result.messages as BaseMessage[];
    return {
      draft: finalAnswer(messages),
      messages,
      records: recorder.records,
      soldOut: recorder.soldOut,
    };
  };

  const policyOut = async (s: S, runConfig: RunnableConfig): Promise<Partial<S>> => {
    const records = s.records;
    const so = s.soldOut;
    // The alternatives the sold-out note told the model about ground its answer; they never become cards.
    const evidence: ToolRecord[] = so?.similar ? [...records, { name: 'find_similar_in_stock', args: {}, result: so.similar }] : records;
    let text = s.draft;
    // A draft is bad when it carries raw tool-call markup, claims no tool result backs, or (on a sold-out turn)
    // leaves out the sold-out news.
    const problems = (draft: string) => ({
      markup: hasToolCallMarkup(draft),
      claims: draft === '' ? [] : ungroundedClaims(draft, evidence),
      soldOut: so !== null && !answersSoldOut(draft, so),
    });
    let found = problems(text);
    if ((found.markup || found.claims.length > 0 || found.soldOut) && budget.takeModelCall()) {
      const corrections: string[] = [];
      if (found.markup) corrections.push('Câu trả lời chứa văn bản gọi công cụ thô (tool-call). Viết lại thành câu trả lời tiếng Việt bình thường, chỉ dùng dữ liệu từ công cụ.');
      if (found.claims.length > 0) corrections.push(`Câu trả lời có thông tin không có trong kết quả công cụ: ${found.claims.join(', ')}. Viết lại, chỉ dùng dữ liệu từ công cụ.`);
      if (found.soldOut && so) corrections.push(soldOutCorrection(so.source.name, so.alternatives));
      try {
        const retry = await model.invoke([
          new SystemMessage(systemPrompt(s.req)),
          ...s.messages,
          new HumanMessage(corrections.join(' ')),
        ], runConfig);
        text = textOf(retry);
        found = problems(text);
      } catch {
        // A failed retry leaves the first draft and its problems; the fallbacks below handle them.
      }
    }
    const soldIds = new Set(s.soldOut?.ids ?? []);
    if (found.markup || found.claims.length > 0) {
      // The summary must not name a sold-out product; the ask copy is the only place that does.
      const safe = selectCards('', records).filter((c) => !soldIds.has(c.id));
      text = safe.length === 0 ? '' : summaryFromCards(safe); // '' -> busy below (or the ask alone on a sold-out turn)
    }
    // The widget shows text as typed (textContent): markdown markers would reach the customer verbatim.
    text = plainText(text);
    const cards = selectCards(text, records).filter((c) => !soldIds.has(c.id));
    const toolsUsed = [...new Set(records.map((r) => r.name))];
    const soldOutAnswered = so !== null && !found.markup && found.claims.length === 0 && !found.soldOut;

    // A valid draft always ends in the fixed confirmation question; PHP stores the draft and creates the ticket on "yes".
    const pending = so === null ? lastValidDraft(records) : null;
    if (pending !== null) {
      if (text === '') text = `${ticketDraftLine(pending)} ${TICKET_CONFIRM_QUESTION}`;
      else if (!text.includes(TICKET_CONFIRM_QUESTION)) text = `${text} ${TICKET_CONFIRM_QUESTION}`;
      return { text, cards: [], outfits: outfitsFrom(records), toolsUsed, responseType: 'clarification', pendingTicket: pending };
    }

    if (text === '' && !s.soldOut) {
      text = cards.length > 0 ? summaryFromCards(cards) : BUSY;
      if (cards.length === 0) {
        return { text, cards, outfits: outfitsFrom(records), toolsUsed, responseType: 'busy' };
      }
    }
    return { text, cards, outfits: outfitsFrom(records), toolsUsed, responseType: 'final_answer', soldOutAnswered };
  };

  // The fixed sold-out branch: the alternatives were fetched in wrapToolCall, here they become the offer.
  const findAlternatives = (s: S): Partial<S> => ({
    offered: s.soldOut?.alternatives ?? [],
    toolsUsed: recorder.branchToolUsed ? [...new Set([...s.toolsUsed, 'find_similar_in_stock'])] : s.toolsUsed,
  });

  const askAlt = (s: S): Partial<S> => {
    const so = s.soldOut;
    if (!so) return {};
    if (s.soldOutAnswered) return { responseType: 'clarification' };
    // Fallback: the model's answer missed the sold-out news, so the fixed question is appended (§5.2a).
    const question = so.alternatives.length > 0
      ? askAlternatives(so.source.name, so.alternatives, s.text !== '')
      : soldOutNoAlternatives(so.count, so.search);
    return { text: s.text === '' ? question : `${s.text} ${question}`, responseType: 'clarification' };
  };

  const nudge = async (s: S): Promise<Partial<S>> => {
    if (s.req.user_id === null || s.responseType !== 'final_answer') return {};
    if (!s.toolsUsed.some((t) => SHOPPING_TOOLS.includes(t))) return {};
    try {
      const data = await tools.call('suggest_freeship_items', { limit: 1 });
      const product = Array.isArray(data.products) ? data.products[0] : undefined;
      const gap = num(data.gap);
      const threshold = num(data.threshold);
      const fingerprint = typeof data.cart_fingerprint === 'string' ? data.cart_fingerprint : '';
      if (data.status !== 'gap' || typeof product !== 'object' || product === null) return {};
      if (!(gap > 0 && gap <= config.freeship.maxGap) || fingerprint === '' || fingerprint === s.req.freeship_fingerprint) return {};
      const card = productToCard(product as Record<string, unknown>);
      if (card.id <= 0 || !(card.stock > 0)) return {};
      return {
        text: `${s.text} ${nudgeText(gap, threshold, card)}`,
        cards: s.cards.some((c) => c.id === card.id) || s.cards.length >= MAX_CARDS ? s.cards : [...s.cards, card],
        nudged: true,
        fingerprint,
      };
    } catch {
      return {};
    }
  };

  const compiled = new StateGraph(State)
    .addNode('guard', guard)
    .addNode('agent', agentNode)
    .addNode('policy_out', policyOut)
    .addNode('find_alternatives', findAlternatives)
    .addNode('ask_alternatives', askAlt)
    .addNode('nudge', nudge)
    .addEdge(START, 'guard')
    .addConditionalEdges('guard', (s: S) => (s.refused ? END : 'agent'), ['agent', END])
    .addEdge('agent', 'policy_out')
    .addConditionalEdges('policy_out', (s: S) => (s.soldOut ? 'find_alternatives' : 'nudge'), ['find_alternatives', 'nudge'])
    .addEdge('find_alternatives', 'ask_alternatives')
    .addEdge('ask_alternatives', END)
    .addEdge('nudge', END)
    .compile();

  return compiled;
}

export function buildTurnGraph(deps: GraphDeps): { invoke(req: TurnRequest, options?: InvokeOptions): Promise<GraphOutcome> } {
  return {
    async invoke(req, options = {}) {
      const budget = typeof deps.budget === 'function' ? deps.budget() : deps.budget;
      const s = await compileTurnGraph(deps, budget, new TurnRecorder()).invoke({ req }, {
        signal: options.signal,
        ...(deps.callbacks && deps.callbacks.length > 0 ? { callbacks: deps.callbacks as RunnableConfig['callbacks'] } : {}),
      });
      return {
        message: s.text,
        response_type: s.responseType,
        cards: s.cards,
        outfits: s.outfits,
        offered: s.offered,
        tools_used: s.toolsUsed,
        freeship_nudge: s.nudged,
        freeship_fingerprint: s.fingerprint ?? req.freeship_fingerprint,
        pending_ticket: s.pendingTicket,
      };
    },
  };
}
