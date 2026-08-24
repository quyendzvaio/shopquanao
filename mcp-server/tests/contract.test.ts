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
    'add_to_cart', 'create_order', 'get_categories', 'get_order_detail', 'get_order_status',
    'get_product_detail', 'list_cart', 'list_orders', 'remove_from_cart', 'retrieve_knowledge',
    'search_products', 'suggest_size', 'update_cart',
  ]);
  assert.equal(response.tools.find(tool => tool.name === 'create_order')?.annotations?.destructiveHint, true);
  assert.equal(response.tools.find(tool => tool.name === 'search_products')?.annotations?.readOnlyHint, true);
});

test('tools/call rejects invalid schemas before reaching the backend', async (t) => {
  const { client, server } = await connect(10);
  t.after(async () => { await client.close(); await server.close(); });
  const response = await client.callTool({ name: 'add_to_cart', arguments: { product_id: 1, quantity: 1, size: 'M' } });
  assert.equal(response.isError, true);
});

test('guest cannot call account tools', async (t) => {
  const { client, server } = await connect();
  t.after(async () => { await client.close(); await server.close(); });
  const response = await client.callTool({ name: 'list_cart', arguments: {} });
  assert.equal(response.isError, true);
  assert.match(JSON.stringify(response.content), /Authentication required/);
});
