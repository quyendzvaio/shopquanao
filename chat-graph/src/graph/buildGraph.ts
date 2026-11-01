import { AIMessage, HumanMessage, SystemMessage, type BaseMessage } from '@langchain/core/messages';
import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import { Annotation, END, START, StateGraph } from '@langchain/langgraph';
import { createAgent } from 'langchain';
import type { GraphConfig } from '../config.js';
import type { Card, Offered, ResponseType, ToolRecord, TurnRequest } from '../contract.js';
import { BUSY, askAlternatives, checkoutRefusal, nudgeText, soldOutNoAlternatives } from '../copy.js';
import type { TurnBudget } from '../policies/budget.js';
import { outfitsFrom, productToCard, selectCards } from '../policies/cards.js';
import { matchesCheckout } from '../policies/checkout.js';
import { summaryFromCards, ungroundedClaims } from '../policies/grounding.js';
import type { ShopTools } from '../shopTools.js';
import { policyMiddleware, TurnRecorder } from './middleware.js';
import { systemPrompt } from './prompt.js';
import type { SoldOutState } from './soldOut.js';

export type GraphOutcome = {
  message: string;
  response_type: ResponseType;
  cards: Card[];
  outfits: unknown[];
  offered: Offered[];
  tools_used: string[];
  freeship_nudge: boolean;
  freeship_fingerprint: string | null;
};

export type GraphDeps = {
  model: BaseChatModel;
  tools: ShopTools;
  budget: TurnBudget;
  config: GraphConfig;
  onProgress?: (stage: string) => void;
};

const SHOPPING_TOOLS = ['search_products', 'get_product_detail', 'build_outfit', 'find_similar_in_stock', 'get_order_status'];

const last = <T>(_: T, b: T): T => b;

const State = Annotation.Root({
  req: Annotation<TurnRequest>(),
  refused: Annotation<boolean>({ reducer: last, default: () => false }),
  draft: Annotation<string>({ reducer: last, default: () => '' }),
  messages: Annotation<BaseMessage[]>({ reducer: last, default: () => [] }),
  records: Annotation<ToolRecord[]>({ reducer: last, default: () => [] }),
  soldOut: Annotation<SoldOutState | null>({ reducer: last, default: () => null }),
  text: Annotation<string>({ reducer: last, default: () => '' }),
  responseType: Annotation<ResponseType>({ reducer: last, default: () => 'final_answer' }),
  cards: Annotation<Card[]>({ reducer: last, default: () => [] }),
  outfits: Annotation<unknown[]>({ reducer: last, default: () => [] }),
  offered: Annotation<Offered[]>({ reducer: last, default: () => [] }),
  toolsUsed: Annotation<string[]>({ reducer: last, default: () => [] }),
  nudged: Annotation<boolean>({ reducer: last, default: () => false }),
  fingerprint: Annotation<string | null>({ reducer: last, default: () => null }),
});

type S = typeof State.State;

function textOf(message: BaseMessage | undefined): string {
  if (!message) return '';
  const content = message.content;
  if (typeof content === 'string') return content.trim();
  return content
    .map((part) => (typeof part === 'object' && part !== null && 'text' in part && typeof part.text === 'string' ? part.text : ''))
    .join('')
    .trim();
}

function num(v: unknown): number {
  return typeof v === 'number' ? v : Number(v);
}

export function buildTurnGraph(deps: GraphDeps): { invoke(req: TurnRequest): Promise<GraphOutcome> } {
  const { model, tools, budget, config, onProgress } = deps;
  const recorder = new TurnRecorder();

  const guard = (s: S): Partial<S> => {
    if (!matchesCheckout(s.req.message)) return {};
    return { refused: true, text: checkoutRefusal(s.req.message), responseType: 'refusal' };
  };

  const agentNode = async (s: S): Promise<Partial<S>> => {
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
    const result = await agent.invoke({ messages: input });
    const messages = result.messages as BaseMessage[];
    const finalMessage = messages[messages.length - 1];
    return {
      draft: finalMessage && AIMessage.isInstance(finalMessage) ? textOf(finalMessage) : '',
      messages,
      records: recorder.records,
      soldOut: recorder.soldOut,
    };
  };

  const policyOut = async (s: S): Promise<Partial<S>> => {
    const records = s.records;
    let text = s.draft;
    let claims = text === '' ? [] : ungroundedClaims(text, records);
    if (claims.length > 0 && budget.takeModelCall()) {
      const retry = await model.invoke([
        new SystemMessage(systemPrompt(s.req)),
        ...s.messages,
        new HumanMessage(`Câu trả lời có thông tin không có trong kết quả công cụ: ${claims.join(', ')}. Viết lại, chỉ dùng dữ liệu từ công cụ.`),
      ]);
      text = textOf(retry);
      claims = text === '' ? [] : ungroundedClaims(text, records);
    }
    if (claims.length > 0) text = summaryFromCards(selectCards('', records));

    const soldIds = new Set(s.soldOut?.ids ?? []);
    const cards = selectCards(text, records).filter((c) => !soldIds.has(c.id));
    const toolsUsed = [...new Set(records.map((r) => r.name))];

    if (text === '' && !s.soldOut) {
      text = cards.length > 0 ? summaryFromCards(cards) : BUSY;
      if (cards.length === 0) {
        return { text, cards, outfits: outfitsFrom(records), toolsUsed, responseType: 'busy' };
      }
    }
    return { text, cards, outfits: outfitsFrom(records), toolsUsed, responseType: 'final_answer' };
  };

  // The fixed sold-out branch: the alternatives were fetched in wrapToolCall, here they become the offer.
  const findAlternatives = (s: S): Partial<S> => ({
    offered: s.soldOut?.alternatives ?? [],
    toolsUsed: recorder.branchToolUsed ? [...new Set([...s.toolsUsed, 'find_similar_in_stock'])] : s.toolsUsed,
  });

  const askAlt = (s: S): Partial<S> => {
    const so = s.soldOut;
    if (!so) return {};
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
        cards: s.cards.some((c) => c.id === card.id) ? s.cards : [...s.cards, card],
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

  return {
    async invoke(req) {
      const s = await compiled.invoke({ req });
      return {
        message: s.text,
        response_type: s.responseType,
        cards: s.cards,
        outfits: s.outfits,
        offered: s.offered,
        tools_used: s.toolsUsed,
        freeship_nudge: s.nudged,
        freeship_fingerprint: s.fingerprint ?? req.freeship_fingerprint,
      };
    },
  };
}
