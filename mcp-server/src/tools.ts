import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod/v4';
import { callTool, type Principal } from './shop-client.js';

const readOnly = { readOnlyHint: true, destructiveHint: false, openWorldHint: false, idempotentHint: true };
const outputSchema = { data: z.record(z.string(), z.unknown()) };

function result(data: Record<string, unknown>) {
  return {
    structuredContent: { data },
    content: [{ type: 'text' as const, text: JSON.stringify(data) }],
  };
}

export function createMcpServer(principal: Principal): McpServer {
  const server = new McpServer(
    { name: 'fashion-shop', version: '1.0.0' },
    { instructions: 'Use product and knowledge tools for shop questions. The shop cannot add to cart, place orders or take payment through these tools.' },
  );

  server.registerTool('search_products', {
    title: 'Search products',
    description: 'Use this when the user wants to find clothing products by name, category, price, color, size, stock, occasion, or style.',
    inputSchema: {
      search: z.string().min(1).max(200),
      category_id: z.number().int().min(1).max(5).optional(),
      category: z.enum(['tops', 'bottoms', 'dresses_skirts', 'accessories', 'footwear']).optional(),
      subcategory: z.enum(['sneakers', 'dress_shoes', 'loafers', 'boots', 'sandals', 'other']).optional(),
      min_price: z.number().nonnegative().optional(),
      max_price: z.number().nonnegative().optional(),
      color: z.string().max(50).optional(),
      size: z.string().max(20).optional(),
      in_stock: z.boolean().optional(),
      occasion: z.string().max(100).optional(),
      style: z.union([z.string(), z.array(z.string())]).optional(),
      avoid: z.union([z.string(), z.array(z.string())]).optional(),
      semantic_query: z.string().max(500).optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('search_products', args, principal)));

  server.registerTool('get_product_detail', {
    title: 'Get product detail',
    description: 'Use this when the user asks about a specific product ID, including price, stock, sizes, colors, description, or reviews.',
    inputSchema: { product_id: z.number().int().positive() }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('get_product_detail', args, principal)));

  const styleTags = ['congso', 'phongvan', 'dichoi', 'dibien', 'hendo', 'dutiec', 'thethao', 'streetstyle', 'basic', 'mualanh'] as const;

  server.registerTool('build_outfit', {
    title: 'Build outfit',
    description: 'Use this when the user wants a complete outfit for an occasion or style, optionally with a budget, size or anchor product. Returns in-stock shop products only.',
    inputSchema: {
      style_tags: z.array(z.enum(styleTags)).max(2).optional(),
      product_id: z.number().int().positive().optional(),
      budget: z.number().int().positive().optional(),
      size: z.string().max(5).optional(),
      height: z.number().int().min(80).max(230).optional(),
      weight: z.number().int().min(20).max(220).optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('build_outfit', args, principal)));

  server.registerTool('suggest_freeship_items', {
    title: 'Suggest items to reach free shipping',
    description: 'Use this when the user asks how much more to buy, or what to add, to get free shipping. Read-only: it never changes the cart. Guests receive the threshold and a login requirement.',
    inputSchema: { limit: z.number().int().min(1).max(5).optional() }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('suggest_freeship_items', args, principal)));

  server.registerTool('suggest_size', {
    title: 'Suggest a size',
    description: 'Use this when the user provides height and weight and wants a clothing size recommendation.',
    inputSchema: {
      height: z.number().int().min(80).max(250),
      weight: z.number().int().min(20).max(300),
      category_id: z.number().int().min(1).max(3).optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('suggest_size', args, principal)));

  server.registerTool('retrieve_knowledge', {
    title: 'Retrieve shop knowledge',
    description: 'Use this when the user asks about shipping, returns, payment, warranty, wholesale, sizing, shop information, or another shop policy.',
    inputSchema: {
      query: z.string().min(1).max(1000),
      category: z.enum(['shipping', 'return', 'payment', 'warranty', 'wholesale', 'general', 'order', 'size', 'shop_info', 'policy']).optional(),
      limit: z.number().int().min(1).max(10).optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('retrieve_knowledge', args, principal)));

  server.registerTool('get_categories', {
    title: 'List product categories',
    description: 'Use this when the user wants to browse the available product categories.',
    outputSchema, annotations: readOnly,
  }, async () => result(await callTool('get_categories', {}, principal)));

  server.registerTool('get_order_status', {
    title: 'Get order status',
    description: 'Use this when an authenticated user asks for recent order status or provides a specific order ID.',
    inputSchema: { order_id: z.number().int().positive().optional() }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('get_order_status', args, principal)));

  return server;
}
