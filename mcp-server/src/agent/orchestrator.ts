import { randomBytes } from 'node:crypto';
import { Annotation, END, InMemoryStore, MemorySaver, START, StateGraph } from '@langchain/langgraph';
import { PostgresSaver } from '@langchain/langgraph-checkpoint-postgres';
import { PostgresStore } from '@langchain/langgraph-checkpoint-postgres/store';
import { advanceProactiveTurn, callTool, type Principal } from '../shop-client.js';
import { understand as defaultUnderstand } from './llm.js';
import { knowledgeSources, normalizeMeasurements, productCards } from './normalization.js';
import type { AgentTurnRequest, AgentTurnResult, ProductCard, ToolExecution, Understanding } from './schemas.js';

type ResolvedEntity = { value: unknown; source: 'current_turn' | 'pending_clarification' | 'active_context'; confidence: number; scope: 'turn' | 'thread'; expiresAtTurn?: number };
type PendingClarification = { intent: string; missingSlots: string[]; entities: Record<string, unknown>; expiresAtTurn: number } | null;
type HistoryItem = { role: 'user' | 'assistant'; content: string };
type ToolCall = { tool: string; arguments: Record<string, unknown> };

const State = Annotation.Root({
  threadId: Annotation<string>,
  userId: Annotation<number | null>,
  authContext: Annotation<{ authenticated: boolean; scopes: string[] }>,
  message: Annotation<string>,
  turn: Annotation<number>({ default: () => 0, reducer: (_left, right) => right }),
  history: Annotation<HistoryItem[]>({ default: () => [], reducer: (_left, right) => right }),
  longMemory: Annotation<Record<string, unknown>>({ default: () => ({}), reducer: (_left, right) => right }),
  understanding: Annotation<Understanding | null>({ default: () => null, reducer: (_left, right) => right }),
  resolvedEntities: Annotation<Record<string, ResolvedEntity>>({ default: () => ({}), reducer: (_left, right) => right }),
  activeProductId: Annotation<number | null>({ default: () => null, reducer: (_left, right) => right }),
  activeCategoryId: Annotation<number | null>({ default: () => null, reducer: (_left, right) => right }),
  pendingClarification: Annotation<PendingClarification>({ default: () => null, reducer: (_left, right) => right }),
  toolCalls: Annotation<ToolCall[]>({ default: () => [], reducer: (_left, right) => right }),
  toolExecutions: Annotation<ToolExecution[]>({ default: () => [], reducer: (_left, right) => right }),
  cards: Annotation<ProductCard[]>({ default: () => [], reducer: (_left, right) => right }),
  knowledgeSources: Annotation<Record<string, unknown>[]>({ default: () => [], reducer: (_left, right) => right }),
  response: Annotation<AgentTurnResult | null>({ default: () => null, reducer: (_left, right) => right }),
  startedAt: Annotation<number>,
});

type GraphState = typeof State.State;
type UnderstandFn = (message: string, context: Record<string, unknown>) => Promise<Understanding>;

export class AgentOrchestrator {
  private constructor(
    private graph: ReturnType<ReturnType<AgentOrchestrator['buildGraph']>['compile']>,
  ) {}

  static async create(understandFn: UnderstandFn = defaultUnderstand): Promise<AgentOrchestrator> {
    const databaseUrl = process.env.LANGGRAPH_DATABASE_URL?.trim();
    let checkpointer: MemorySaver | PostgresSaver;
    let store: InMemoryStore | PostgresStore;
    if (databaseUrl) {
      const postgresSaver = PostgresSaver.fromConnString(databaseUrl);
      const postgresStore = PostgresStore.fromConnString(databaseUrl);
      await Promise.all([postgresSaver.setup(), postgresStore.setup()]);
      checkpointer = postgresSaver;
      store = postgresStore;
    } else {
      checkpointer = new MemorySaver();
      store = new InMemoryStore();
    }
    const instance = Object.create(AgentOrchestrator.prototype) as AgentOrchestrator;
    const builder = instance.buildGraph(understandFn, store);
    instance.graph = builder.compile({ checkpointer, store });
    return instance;
  }

  async invokeAgentTurn(request: AgentTurnRequest): Promise<AgentTurnResult> {
    const output = await this.graph.invoke({
      threadId: request.threadId,
      userId: request.userId,
      authContext: request.authContext,
      message: request.message,
      startedAt: Date.now(),
      understanding: null,
      resolvedEntities: {},
      toolCalls: [],
      toolExecutions: [],
      cards: [],
      knowledgeSources: [],
      response: null,
    }, { configurable: { thread_id: request.threadId } });
    if (!output.response) throw new Error('LangGraph completed without a response');
    return output.response;
  }

  private buildGraph(understandFn: UnderstandFn, store: InMemoryStore | PostgresStore) {
    const loadContext = async (state: GraphState) => {
      const turn = state.turn + 1;
      const pending = state.pendingClarification && state.pendingClarification.expiresAtTurn >= turn
        ? state.pendingClarification : null;
      let longMemory: Record<string, unknown> = {};
      if (state.userId !== null) {
        const item = await store.get(['users', String(state.userId)], 'profile');
        longMemory = (item?.value as Record<string, unknown> | undefined) ?? {};
      }
      return { turn, pendingClarification: pending, longMemory };
    };

    const understandTurn = async (state: GraphState) => {
      try {
        const understanding = await understandFn(state.message, {
          recent_history: state.history.slice(-8),
          active_product_id: state.activeProductId,
          active_category_id: state.activeCategoryId,
          pending_clarification: state.pendingClarification,
          explicit_user_memory: state.longMemory,
        });
        return { understanding };
      } catch (error) {
        return { understanding: fallbackUnderstanding(), toolExecutions: [{
          tool: 'understand_turn', arguments: {}, result: null, duration_ms: 0, success: false,
          error: error instanceof Error ? error.message : 'structured_understanding_failed',
        }] };
      }
    };

    const normalizeStructuralValues = (state: GraphState) => {
      const sizeContext = state.pendingClarification?.intent === 'size_advice';
      return { understanding: normalizeMeasurements(state.message, state.understanding ?? fallbackUnderstanding(), sizeContext) };
    };

    const resolveReference = (state: GraphState) => {
      const understanding = state.understanding ?? fallbackUnderstanding();
      const pending = state.pendingClarification;
      const entities = { ...understanding.entities } as Record<string, unknown>;
      if (pending && pending.intent === understanding.primary_intent) {
        for (const [key, value] of Object.entries(pending.entities)) {
          if (entities[key] === null || entities[key] === undefined) entities[key] = value;
        }
      }
      if (understanding.refers_to_active_product && !entities.product_id) entities.product_id = state.activeProductId;
      if (understanding.primary_intent === 'size_advice' && !entities.category_id && pending?.intent === 'size_advice') {
        entities.category_id = pending.entities.category_id ?? null;
      }
      const merged = { ...understanding, entities: entities as Understanding['entities'] };
      const resolvedEntities: Record<string, ResolvedEntity> = {};
      for (const [key, value] of Object.entries(entities)) {
        if (value !== null && value !== undefined && value !== '') {
          resolvedEntities[key] = { value, source: 'current_turn', confidence: understanding.confidence, scope: 'turn' };
        }
      }
      return { understanding: merged, resolvedEntities };
    };

    const authorizePlan = (state: GraphState) => {
      const understanding = state.understanding ?? fallbackUnderstanding();
      const missing = understanding.missing_slots.filter(isSupportedMissingSlot);
      const requiredByIntent: Record<string, string[]> = {
        product_detail: ['product_id'],
        size_advice: ['height', 'weight'],
        add_to_cart: ['product_id', 'size'],
        update_cart: ['cart_id'],
        remove_from_cart: ['cart_id'],
      };
      const accountIntents = new Set(['list_cart', 'list_orders', 'add_to_cart', 'update_cart', 'remove_from_cart', 'create_order']);
      const mutations = new Set(['add_to_cart', 'update_cart', 'remove_from_cart', 'create_order']);
      for (const slot of requiredByIntent[understanding.primary_intent] ?? []) {
        if (!hasEntity(understanding, slot)) missing.push(slot);
      }
      if (understanding.primary_intent === 'update_cart' && !hasEntity(understanding, 'quantity') && !hasEntity(understanding, 'size')) {
        missing.push('quantity_or_size');
      }
      if (accountIntents.has(understanding.primary_intent) && !state.authContext.authenticated) missing.push('authentication');
      if (mutations.has(understanding.primary_intent) && !understanding.explicit_confirmation) missing.push('confirmation');
      return { understanding: { ...understanding, missing_slots: [...new Set(missing)] } };
    };

    const planTools = (state: GraphState) => {
      const u = state.understanding ?? fallbackUnderstanding();
      const e = u.entities;
      if (u.confidence < 0.55 || u.primary_intent === 'unknown' || u.missing_slots.length > 0) return { toolCalls: [] };
      const calls: ToolCall[] = [];
      const searchArgs = () => compact({ search: e.product_query || state.message, category_id: e.category_id, color: e.color, size: e.size, min_price: e.min_price, max_price: e.max_price, occasion: e.occasion, semantic_query: state.message, in_stock: true });
      switch (u.primary_intent) {
        case 'product_search': calls.push({ tool: 'search_products', arguments: searchArgs() }); break;
        case 'product_detail': if (e.product_id) calls.push({ tool: 'get_product_detail', arguments: { product_id: e.product_id } }); break;
        case 'size_advice': calls.push({ tool: 'suggest_size', arguments: compact({ height: e.height_cm, weight: e.weight_kg, category_id: e.category_id }) }); break;
        case 'return_exchange': calls.push({ tool: 'retrieve_knowledge', arguments: { query: state.message, category: 'return', limit: 5 } }); break;
        case 'shipping': calls.push({ tool: 'retrieve_knowledge', arguments: { query: state.message, category: 'shipping', limit: 5 } }); break;
        case 'policy': calls.push({ tool: 'retrieve_knowledge', arguments: { query: state.message, category: 'policy', limit: 5 } }); break;
        case 'order_status': calls.push({ tool: 'get_order_status', arguments: compact({ order_id: e.order_id }) }); break;
        case 'list_cart': calls.push({ tool: 'list_cart', arguments: {} }); break;
        case 'list_orders': calls.push({ tool: 'list_orders', arguments: {} }); break;
        case 'suggest_complementary_products': calls.push(e.product_id
          ? { tool: 'suggest_complementary_products', arguments: { product_id: e.product_id } }
          : { tool: 'search_products', arguments: searchArgs() }); break;
        case 'occasion_styling': {
          const shared = compact({ occasion: e.occasion, semantic_query: state.message, in_stock: true });
          calls.push(
            { tool: 'search_products', arguments: { ...shared, search: 'áo', category_id: 1 } },
            { tool: 'search_products', arguments: { ...shared, search: 'quần', category_id: 2 } },
            { tool: 'search_products', arguments: { ...shared, search: 'giày', category_id: 5 } },
          );
          break;
        }
        case 'add_to_cart': calls.push({ tool: 'add_to_cart', arguments: { product_id: e.product_id, quantity: e.quantity ?? 1, size: e.size, confirmed: true } }); break;
        case 'update_cart': calls.push({ tool: 'update_cart', arguments: compact({ cart_id: e.cart_id, quantity: e.quantity, size: e.size, confirmed: true }) }); break;
        case 'remove_from_cart': calls.push({ tool: 'remove_from_cart', arguments: { cart_id: e.cart_id, confirmed: true } }); break;
        case 'create_order': calls.push({ tool: 'create_order', arguments: { confirmed: true } }); break;
      }
      return { toolCalls: calls };
    };

    const executeTools = async (state: GraphState) => {
      const principal: Principal = { userId: state.userId, mode: state.userId === null ? 'anonymous' : 'service', scopes: state.authContext.scopes };
      const executions: ToolExecution[] = state.toolExecutions.filter(item => item.tool === 'understand_turn');
      executions.push(...await Promise.all(state.toolCalls.map(call => execute(call, principal))));
      const u = state.understanding ?? fallbackUnderstanding();
      if (u.primary_intent === 'product_search' && state.toolCalls.some(call => call.tool === 'search_products') && productCards(executions).length === 0) {
        executions.push(await execute({ tool: 'search_products', arguments: { search: state.message, semantic_query: state.message, in_stock: true } }, principal));
      }
      if (u.primary_intent === 'occasion_styling') {
        const currentCards = productCards(executions);
        const fallbackCalls: ToolCall[] = [];
        if (!hasCategory(currentCards, 1)) fallbackCalls.push({ tool: 'search_products', arguments: { search: 'áo thun', category_id: 1, in_stock: true } });
        if (!hasCategory(currentCards, 2)) fallbackCalls.push({ tool: 'search_products', arguments: { search: 'quần short', category_id: 2, in_stock: true } });
        if (!hasCategory(currentCards, 4) && !hasCategory(currentCards, 5)) fallbackCalls.push({ tool: 'search_products', arguments: { search: 'kính', category_id: 4, in_stock: true } });
        if (fallbackCalls.length > 0) executions.push(...await Promise.all(fallbackCalls.map(call => execute(call, principal))));
      }
      if (u.primary_intent === 'suggest_complementary_products' && state.toolCalls[0]?.tool === 'search_products') {
        const anchors = productCards(executions);
        if (anchors.length === 1) executions.push(await execute({ tool: 'suggest_complementary_products', arguments: { product_id: anchors[0]!.id } }, principal));
      }
      return { toolExecutions: executions };
    };

    const normalizeEvidence = (state: GraphState) => {
      const cards = productCards(state.toolExecutions);
      return {
        cards: state.understanding?.primary_intent === 'occasion_styling' ? occasionSet(cards) : cards,
        knowledgeSources: knowledgeSources(state.toolExecutions),
      };
    };

    const verifyEvidence = (state: GraphState) => {
      const verified = state.cards.filter(card => Number.isInteger(card.id) && card.id > 0);
      return { cards: verified };
    };

    const advanceProactiveState = async (state: GraphState) => {
      if (state.userId === null) return { response: null };
      const principal: Principal = { userId: state.userId, mode: 'service', scopes: state.authContext.scopes };
      try {
        const result = await advanceProactiveTurn(state.understanding?.primary_intent ?? 'unknown', state.threadId, principal);
        return { longMemory: { ...state.longMemory, __proactive: result } };
      } catch {
        return { longMemory: { ...state.longMemory, __proactive: { status: 'tool_retryable_failure' } } };
      }
    };

    const generateAnswer = (state: GraphState) => {
      const proactive = (state.longMemory.__proactive ?? {}) as Record<string, unknown>;
      const proactiveCards = Array.isArray(proactive.products) ? proactive.products.filter(item => item && typeof item === 'object') as ProductCard[] : [];
      const cards = dedupeCards([...state.cards, ...proactiveCards]);
      const response = makeResponse(state, cards, proactive);
      return { response, cards };
    };

    const persist = async (state: GraphState) => {
      const response = state.response!;
      const history = [...state.history, { role: 'user' as const, content: state.message }, { role: 'assistant' as const, content: response.message }].slice(-12);
      const u = state.understanding ?? fallbackUnderstanding();
      const activeProductId = state.cards.length === 1 ? state.cards[0]!.id : (u.entities.product_id ?? state.activeProductId);
      const activeCategoryId = u.entities.category_id ?? state.activeCategoryId;
      const pendingClarification: PendingClarification = response.response_type === 'clarification'
        ? { intent: u.primary_intent, missingSlots: u.missing_slots, entities: compact(u.entities), expiresAtTurn: state.turn + 3 }
        : null;
      if (state.userId !== null) {
        const profile = { ...state.longMemory };
        delete profile.__proactive;
        Object.assign(profile, u.stable_preferences);
        if (u.primary_intent === 'size_advice' && !u.is_hypothetical) {
          if (u.entities.height_cm) profile.height_cm = { value: u.entities.height_cm, source: 'explicit', confidence: 1 };
          if (u.entities.weight_kg) profile.weight_kg = { value: u.entities.weight_kg, source: 'explicit', confidence: 1 };
        }
        await store.put(['users', String(state.userId)], 'profile', profile, false);
      }
      return { history, activeProductId, activeCategoryId, pendingClarification };
    };

    return new StateGraph(State)
      .addNode('load_context', loadContext)
      .addNode('understand_turn', understandTurn)
      .addNode('normalize_structural_values', normalizeStructuralValues)
      .addNode('resolve_reference', resolveReference)
      .addNode('authorize_plan', authorizePlan)
      .addNode('plan_tools', planTools)
      .addNode('execute_tools', executeTools)
      .addNode('normalize_evidence', normalizeEvidence)
      .addNode('verify_evidence', verifyEvidence)
      .addNode('advance_proactive_state', advanceProactiveState)
      .addNode('generate_answer', generateAnswer)
      .addNode('persist', persist)
      .addEdge(START, 'load_context').addEdge('load_context', 'understand_turn')
      .addEdge('understand_turn', 'normalize_structural_values').addEdge('normalize_structural_values', 'resolve_reference')
      .addEdge('resolve_reference', 'authorize_plan').addEdge('authorize_plan', 'plan_tools')
      .addEdge('plan_tools', 'execute_tools').addEdge('execute_tools', 'normalize_evidence')
      .addEdge('normalize_evidence', 'verify_evidence').addEdge('verify_evidence', 'advance_proactive_state')
      .addEdge('advance_proactive_state', 'generate_answer').addEdge('generate_answer', 'persist').addEdge('persist', END);
  }
}

async function execute(call: ToolCall, principal: Principal): Promise<ToolExecution> {
  const started = Date.now();
  try {
    return { ...call, result: await callTool(call.tool, call.arguments, principal), duration_ms: Date.now() - started, success: true };
  } catch (error) {
    return { ...call, result: null, duration_ms: Date.now() - started, success: false, error: error instanceof Error ? error.message : 'tool_failed' };
  }
}

function compact(value: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(value).filter(([, item]) => item !== null && item !== undefined && item !== ''));
}

function fallbackUnderstanding(): Understanding {
  return { primary_intent: 'unknown', secondary_intents: [], confidence: 0, entities: { product_id: null, product_query: null, category_id: null, color: null, size: null, height_cm: null, weight_kg: null, min_price: null, max_price: null, occasion: null, order_id: null, cart_id: null, quantity: null }, requested_fields: [], missing_slots: [], refers_to_active_product: false, is_hypothetical: false, explicit_confirmation: false, stable_preferences: {} };
}

function hasEntity(understanding: Understanding, slot: string): boolean {
  const entityKey = slot === 'height' ? 'height_cm' : slot === 'weight' ? 'weight_kg' : slot;
  const value = understanding.entities[entityKey as keyof Understanding['entities']];
  return value !== null && value !== undefined && value !== '';
}

function dedupeCards(cards: ProductCard[]): ProductCard[] {
  return [...new Map(cards.filter(card => Number(card.id) > 0).map(card => [Number(card.id), { ...card, id: Number(card.id) }])).values()];
}

function hasCategory(cards: ProductCard[], categoryId: number): boolean {
  return cards.some(card => Number(card.category_id) === categoryId);
}

function occasionSet(cards: ProductCard[]): ProductCard[] {
  const set: ProductCard[] = [];
  const used = new Set<number>();
  const pick = (categoryIds: number[]) => {
    const card = cards.find(candidate => categoryIds.includes(Number(candidate.category_id)) && !used.has(Number(candidate.id)));
    if (card) {
      used.add(Number(card.id));
      set.push(card);
    }
  };
  pick([1]);
  pick([2]);
  pick([5, 4]);
  if (set.length >= 2) return set;
  return dedupeCards(cards).slice(0, 3);
}

function makeResponse(state: GraphState, cards: ProductCard[], proactive: Record<string, unknown>): AgentTurnResult {
  const u = state.understanding ?? fallbackUnderstanding();
  const traceId = randomBytes(16).toString('hex');
  let responseType: AgentTurnResult['response_type'] = 'final_answer';
  let message: string;
  if (u.confidence < 0.55 || u.primary_intent === 'unknown') {
    responseType = 'fallback';
    message = 'Mình chưa hiểu rõ nhu cầu. Bạn muốn tìm sản phẩm, xem chi tiết, hỏi size, phối đồ hay hỏi chính sách shop?';
  } else if (u.missing_slots.length > 0) {
    responseType = 'clarification';
    message = clarification(u.missing_slots);
  } else {
    message = groundedAnswer(u, state.toolExecutions, cards);
  }
  const proactiveStatus = typeof proactive.status === 'string' ? proactive.status : 'not_armed';
  if (proactiveStatus === 'shown' && cards.length > state.cards.length) {
    message += ' Mình cũng tìm thấy vài sản phẩm trong shop có thể phối cùng món bạn vừa thêm vào giỏ.';
  }
  return {
    message, answer: message, response_type: responseType, primary_intent: u.primary_intent,
    secondary_intents: u.secondary_intents, requested_fields: u.requested_fields, missing_slots: u.missing_slots,
    cards, products: cards, knowledge_sources: state.knowledgeSources, trace_id: traceId,
    latency: { pipeline: 'langgraph', total_ms: Date.now() - state.startedAt },
    proactive_styling: proactiveStatus === 'shown', proactive_status: proactiveStatus,
    tool_executions: state.toolExecutions,
  };
}

function clarification(missing: string[]): string {
  if (missing.includes('height_confirmation')) return 'Chiều cao bạn nhập có vẻ chưa hợp lý. Bạn xác nhận lại theo dạng 170cm hoặc 1m70 nhé.';
  if (missing.includes('height') || missing.includes('weight')) return 'Bạn cho mình xin chiều cao và cân nặng để tư vấn size chính xác hơn nhé.';
  if (missing.includes('authentication')) return 'Bạn vui lòng đăng nhập trước khi thực hiện thao tác này nhé.';
  if (missing.includes('confirmation')) return 'Bạn xác nhận rõ giúp mình trước khi thay đổi giỏ hàng hoặc đặt hàng nhé.';
  if (missing.includes('product_id')) return 'Bạn chọn giúp mình sản phẩm cụ thể trước nhé.';
  if (missing.includes('size')) return 'Bạn cho mình biết size muốn chọn nhé.';
  if (missing.includes('cart_id')) return 'Bạn cho mình biết sản phẩm nào trong giỏ hàng cần chỉnh nhé.';
  if (missing.includes('quantity_or_size')) return 'Bạn muốn đổi số lượng hay đổi size của sản phẩm trong giỏ hàng?';
  return 'Bạn bổ sung thêm thông tin còn thiếu để mình hỗ trợ chính xác hơn nhé.';
}

function groundedAnswer(u: Understanding, executions: ToolExecution[], cards: ProductCard[]): string {
  const result = executions.find(item => item.success && item.tool !== 'understand_turn')?.result ?? {};
  switch (u.primary_intent) {
    case 'product_search': return cards.length ? `Mình tìm thấy ${cards.length} sản phẩm phù hợp trong shop. Bạn xem các thẻ sản phẩm bên dưới nhé.` : 'Mình chưa tìm thấy sản phẩm phù hợp trong cửa hàng hiện tại.';
    case 'product_detail': return cards[0] ? `${String(cards[0].name ?? 'Sản phẩm')} (mã ${cards[0].id}) có giá ${money(cards[0].price)}, hiện còn ${Number(cards[0].stock ?? 0)} sản phẩm.` : 'Mình chưa tìm thấy sản phẩm này trong catalog của shop.';
    case 'return_exchange': case 'shipping': case 'policy': {
      const sources = Array.isArray(result.results) ? result.results as Record<string, unknown>[] : [];
      return sources[0]?.content ? `Theo chính sách của shop, ${String(sources[0].content)}` : 'Mình chưa tìm thấy thông tin phù hợp trong dữ liệu chính sách của shop.';
    }
    case 'size_advice': return sizeAnswer(u, result);
    case 'suggest_complementary_products': {
      const searched = executions.some(item => item.tool === 'search_products');
      const complemented = executions.some(item => item.tool === 'suggest_complementary_products' && item.success);
      if (searched && !complemented && cards.length === 0) return 'Mình chưa tìm thấy sản phẩm neo phù hợp trong catalog của shop để phối đồ.';
      if (searched && !complemented && cards.length > 1) return 'Mình tìm thấy nhiều sản phẩm có thể là món bạn nói tới. Bạn chọn một thẻ bên dưới để mình phối chính xác nhé.';
      return cards.length ? 'Mình đã tìm các món trong catalog của shop có thể phối cùng sản phẩm này. Bạn xem các thẻ bên dưới nhé.' : 'Mình chưa tìm thấy sản phẩm phối hợp phù hợp trong catalog của shop.';
    }
    case 'occasion_styling': return cards.length ? `Với dịp ${displayOccasion(u.entities.occasion)}, bạn có thể kết hợp các món trong shop ở bên dưới thành một set thoải mái và đồng bộ.` : 'Mình chưa tìm thấy đủ sản phẩm phù hợp cho dịp này trong catalog của shop.';
    case 'order_status': case 'list_orders': return Array.isArray(result.orders) && result.orders.length ? `Mình tìm thấy ${result.orders.length} đơn hàng của bạn.` : 'Mình chưa tìm thấy đơn hàng phù hợp.';
    case 'list_cart': return Array.isArray(result.cart) && result.cart.length ? `Giỏ hàng hiện có ${result.cart.length} sản phẩm, tổng cộng ${money(result.total)}.` : 'Giỏ hàng của bạn hiện đang trống.';
    case 'add_to_cart': case 'update_cart': case 'remove_from_cart': case 'create_order': return typeof result.message === 'string' ? result.message : 'Thao tác đã được thực hiện.';
    default: return 'Mình chưa tìm thấy đủ dữ liệu để trả lời chắc chắn.';
  }
}

function sizeAnswer(u: Understanding, result: Record<string, unknown>): string {
  const height = Number(u.entities.height_cm); const weight = Number(u.entities.weight_kg);
  const recommended = result.recommended as Record<string, unknown> | undefined;
  const rows = Array.isArray(result.sizes) ? result.sizes as Record<string, unknown>[] : [];
  const requested = String(u.entities.size ?? '').toUpperCase();
  const row = rows.find(item => String(item.size_name ?? '').toUpperCase() === requested);
  if (!row) {
    if (recommended?.size_name) return `Với chiều cao ${height}cm và cân nặng ${weight}kg, size ${recommended.size_name} phù hợp hơn theo bảng size của shop.`;
    const nearest = nearestSize(rows, height, weight);
    return nearest
      ? `Không có size khớp hoàn toàn với ${height}cm/${weight}kg. Size gần nhất là ${nearest.size_name}; bạn nên đối chiếu số đo chi tiết hoặc thử trực tiếp.`
      : 'Mình chưa có bảng size phù hợp để tư vấn chắc chắn cho sản phẩm này.';
  }
  const fit = classifySizeFit(height, weight, row);
  const reason = fit === 'boundary' ? 'cả hai số đo đều phù hợp nhưng đang sát biên size'
    : fit === 'fit' ? 'cả chiều cao và cân nặng đều nằm trong khoảng phù hợp'
      : fit === 'both_mismatch' ? 'cả chiều cao và cân nặng đều nằm ngoài khoảng của size này'
        : fit === 'weight_mismatch' ? 'chiều cao phù hợp nhưng cân nặng nằm ngoài khoảng'
          : 'cân nặng phù hợp nhưng chiều cao nằm ngoài khoảng';
  const alternative = recommended?.size_name && String(recommended.size_name).toUpperCase() !== requested
    ? ` Theo bảng hiện tại, size ${recommended.size_name} khớp cả hai số đo hơn.` : '';
  return `Size ${requested} phù hợp khoảng ${row.height_from}-${row.height_to}cm và ${row.weight_from}-${row.weight_to}kg. Với ${height}cm/${weight}kg, ${reason}.${alternative}`;
}

export function classifySizeFit(height: number, weight: number, row: Record<string, unknown>): 'fit' | 'boundary' | 'height_mismatch' | 'weight_mismatch' | 'both_mismatch' {
  const heightFits = height >= Number(row.height_from) && height <= Number(row.height_to);
  const weightFits = weight >= Number(row.weight_from) && weight <= Number(row.weight_to);
  if (!heightFits && !weightFits) return 'both_mismatch';
  if (!heightFits) return 'height_mismatch';
  if (!weightFits) return 'weight_mismatch';
  const nearBoundary = [
    Math.abs(height - Number(row.height_from)), Math.abs(height - Number(row.height_to)),
    Math.abs(weight - Number(row.weight_from)), Math.abs(weight - Number(row.weight_to)),
  ].some(distance => distance <= 2);
  return nearBoundary ? 'boundary' : 'fit';
}

function nearestSize(rows: Record<string, unknown>[], height: number, weight: number): Record<string, unknown> | null {
  const distance = (value: number, from: number, to: number) => value < from ? from - value : value > to ? value - to : 0;
  return rows
    .filter(row => row.size_name && Number.isFinite(Number(row.height_from)) && Number.isFinite(Number(row.weight_from)))
    .map(row => ({ row, score: distance(height, Number(row.height_from), Number(row.height_to)) + distance(weight, Number(row.weight_from), Number(row.weight_to)) }))
    .sort((a, b) => a.score - b.score)[0]?.row ?? null;
}

function money(value: unknown): string { return `${Number(value ?? 0).toLocaleString('vi-VN')}đ`; }

function displayOccasion(value: unknown): string {
  const raw = String(value ?? '').trim().toLowerCase();
  if (raw === 'beach' || raw === 'biển' || raw === 'di bien' || raw === 'đi biển') return 'đi biển';
  return raw || 'này';
}

function isSupportedMissingSlot(slot: string): boolean {
  return [
    'height', 'weight', 'height_confirmation', 'authentication', 'confirmation',
    'product_id', 'size', 'cart_id', 'quantity', 'quantity_or_size', 'order_id',
  ].includes(slot);
}
