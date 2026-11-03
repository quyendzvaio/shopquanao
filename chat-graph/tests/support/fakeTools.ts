import { tool } from '@langchain/core/tools';
import { z } from 'zod/v4';
import type { ShopTools } from '../../src/shopTools.js';

export type FakeShopTools = ShopTools & { calls: Array<{ name: string; args: Record<string, unknown> }> };

/** Same shape as openShopTools(): LangChain tools for the model plus `call` for direct use. Every call is recorded. */
export function fakeShopTools(handlers: Record<string, (args: Record<string, unknown>) => Record<string, unknown>>): FakeShopTools {
  const calls: FakeShopTools['calls'] = [];

  const run = (name: string, args: Record<string, unknown>): Record<string, unknown> => {
    calls.push({ name, args });
    const handler = handlers[name];
    if (!handler) throw new Error(`fakeShopTools: no handler for ${name}`);
    return handler(args);
  };

  const tools = Object.keys(handlers).map((name) => tool(
    async (args: Record<string, unknown>) => JSON.stringify(run(name, args)),
    { name, description: `fake ${name}`, schema: z.record(z.string(), z.unknown()) },
  ));

  return {
    tools,
    calls,
    async call(name, args) {
      return run(name, args);
    },
    async close() {},
  };
}
