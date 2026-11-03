import assert from 'node:assert/strict';
import test from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createMcpServer } from '../src/tools.js';

async function connect(userId: number | null = null) {
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  const server = createMcpServer({ userId, mode: userId ? 'service' : 'anonymous', scopes: ['shop.read', 'shop.write'] });
  const client = new Client({ name: 'contract-test', version: '1.0.0' });
  await server.connect(serverTransport);
  await client.connect(clientTransport);
  return { client, server };
}

test('initialize and tools/list expose the complete contract', async (t) => {
  const { client, server } = await connect();
  t.after(async () => { await client.close(); await server.close(); });
  const response = await client.listTools();
  const names = response.tools.map(tool => tool.name).sort();
  assert.deepEqual(names, [
    'build_outfit', 'get_categories', 'get_order_status', 'get_product_detail', 'retrieve_knowledge',
    'search_products', 'suggest_freeship_items', 'suggest_size',
  ]);
  assert.ok(response.tools.every(tool => tool.annotations?.readOnlyHint === true));
  const searchSchema = response.tools.find(tool => tool.name === 'search_products')?.inputSchema as {
    properties?: Record<string, { enum?: string[]; maximum?: number }>;
  };
  assert.equal(searchSchema.properties?.category_id?.maximum, 5);
  assert.deepEqual(searchSchema.properties?.subcategory?.enum, [
    'sneakers', 'dress_shoes', 'loafers', 'boots', 'sandals', 'other',
  ]);
});

test('tools/call rejects invalid schemas before reaching the backend', async (t) => {
  const { client, server } = await connect(10);
  t.after(async () => { await client.close(); await server.close(); });
  const response = await client.callTool({ name: 'get_product_detail', arguments: { product_id: -1 } });
  assert.equal(response.isError, true);
  assert.match(JSON.stringify(response.content), /Input validation error/);
});

test('cart and order tools are not exposed', async (t) => {
  const { client, server } = await connect(10);
  t.after(async () => { await client.close(); await server.close(); });
  for (const name of ['add_to_cart', 'update_cart', 'remove_from_cart', 'list_cart', 'list_orders', 'get_order_detail', 'create_order']) {
    const response = await client.callTool({ name, arguments: {} }).catch((error: unknown) => ({ isError: true, content: [{ type: 'text', text: String(error) }] }));
    assert.equal(response.isError, true, name);
    assert.match(JSON.stringify(response.content), /not found/i, name);
  }
});
