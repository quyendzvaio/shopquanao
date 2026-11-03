import { AIMessage, type BaseMessage } from '@langchain/core/messages';
import type { ChatResult } from '@langchain/core/outputs';
import type { GraphConfig } from '../../src/config.js';
import type { TurnRequest } from '../../src/contract.js';
import { ScriptedChatModel, type ScriptedStep } from './scriptedModel.js';
import { fakeShopTools, type FakeShopTools } from './fakeTools.js';

export const CONFIG = {
  budget: { maxToolCalls: 4, maxModelCalls: 5, turnTimeoutMs: 45000 },
  freeship: { threshold: 500000, maxGap: 200000 },
} as GraphConfig;

export function req(over: Partial<TurnRequest> = {}): TurnRequest {
  return {
    message: 'có áo thun nào không',
    user_id: null,
    session_id: 1,
    history: [],
    memory: { summary: '', slots: {}, profile: null },
    freeship_fingerprint: null,
    stream: true,
    ...over,
  };
}

export const call = (id: string, name: string, args: Record<string, unknown>) => ({ id, name, args, type: 'tool_call' as const });
export const ai = (text: string, calls: ReturnType<typeof call>[] = []) => new AIMessage({ content: text, tool_calls: calls });

const shirt = { id: 66, name: 'Áo Thun', price: 150000, stock: 3 };
export const searchTurn: ScriptedStep[] = [
  ai('', [call('c1', 'search_products', { search: 'áo thun' })]),
  ai('Shop có Áo Thun mã 66 giá 150.000đ. Bạn xem thử nhé! Còn size S, M không?'),
];

export function setup(steps: ScriptedStep[], config: GraphConfig = CONFIG, model = new ScriptedChatModel(steps)) {
  const tools: FakeShopTools = fakeShopTools({ search_products: () => ({ products: [shirt] }) });
  let closed = 0;
  const original = tools.close;
  tools.close = async () => { closed += 1; await original(); };
  const deps = { config, model, openTools: async () => tools };
  return { model, tools, deps, closed: () => closed };
}


/** ScriptedChatModel that records when a model call has settled (returned, thrown or aborted). */
export class TrackedModel extends ScriptedChatModel {
  settled = false;
  override async _generate(messages: BaseMessage[], options: this['ParsedCallOptions']): Promise<ChatResult> {
    try {
      return await super._generate(messages, options);
    } finally {
      this.settled = true;
    }
  }
}
