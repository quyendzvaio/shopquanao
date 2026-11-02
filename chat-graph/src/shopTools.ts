import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { loadMcpTools } from '@langchain/mcp-adapters';
import type { StructuredToolInterface } from '@langchain/core/tools';
import { createMcpServer } from '../../mcp-server/src/tools.js';

export type ShopToolsHandle = {
  tools: StructuredToolInterface[];
  call(name: string, args: Record<string, unknown>): Promise<Record<string, unknown>>;
  close(): Promise<void>;
};

/**
 * Tool arguments without the ones set to `null`: models write `order_id: null` for "not given", while every optional
 * shop tool argument is `.optional()` (absent), so a null would fail the tool's input validation.
 */
export function withoutNulls(args: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(args).filter(([, v]) => v !== null));
}

/**
 * Opens the shop's read-only MCP tools in-process (no child process, no network for listing).
 * `tools` are LangChain tools for the model; `call` runs a tool directly (policies, graph nodes).
 */
export async function openShopTools(userId: number | null): Promise<ShopToolsHandle> {
  const server = createMcpServer({ userId, mode: 'service', scopes: ['shop.read'] });
  const client = new Client({ name: 'chat-graph', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await Promise.all([server.connect(serverTransport), client.connect(clientTransport)]);
  const tools = await loadMcpTools('fashion-shop', client);

  return {
    tools,
    async call(name, args) {
      const res = await client.callTool({ name, arguments: withoutNulls(args) });
      const structured = res.structuredContent as { data?: unknown } | undefined;
      if (res.isError || !structured || typeof structured.data !== 'object' || structured.data === null) {
        const text = Array.isArray(res.content) ? JSON.stringify(res.content) : '';
        throw new Error(`Tool ${name} failed: ${text}`);
      }
      return structured.data as Record<string, unknown>;
    },
    async close() {
      await client.close();
      await server.close();
    },
  };
}

export type ShopTools = Awaited<ReturnType<typeof openShopTools>>;
