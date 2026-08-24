import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod/v4';
import { callTool, type Principal } from './shop-client.js';

const readOnly = { readOnlyHint: true, destructiveHint: false, openWorldHint: false, idempotentHint: true };
const writeReversible = { readOnlyHint: false, destructiveHint: false, openWorldHint: false, idempotentHint: false };
const writeDestructive = { readOnlyHint: false, destructiveHint: true, openWorldHint: false, idempotentHint: false };
const outputSchema = { data: z.record(z.string(), z.unknown()) };

function result(data: Record<string, unknown>) {
  return {
    structuredContent: { data },
    content: [{ type: 'text' as const, text: JSON.stringify(data) }],
  };
}

function requireUser(principal: Principal): void {
  if (principal.userId === null) {
    throw new Error('Authentication required for this PHP chatbot session.');
  }
}

function requireConfirmation(confirmed: true): void {
  if (confirmed !== true) throw new Error('Explicit user confirmation is required');
}

export function createMcpServer(principal: Principal): McpServer {
  const server = new McpServer(
    { name: 'fashion-shop', version: '1.0.0' },
    { instructions: 'Use product and knowledge tools for shop questions. Account tools require an authenticated PHP chatbot session. Mutating cart/order tools require confirmed=true only after explicit user confirmation. Outfit advice and external payment are unsupported.' },
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

  server.registerTool('suggest_complementary_products', {
    title: 'Suggest complementary products',
    description: 'Use this when the user asks what would coordinate with a specific shop product. Recommendations are grounded in the shop catalog after styling analysis.',
    inputSchema: {
      product_id: z.number().int().positive(),
      variant_id: z.number().int().positive().optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('suggest_complementary_products', args, principal)));

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
  }, async (args) => {
    if (principal.userId !== null) requireUser(principal);
    return result(await callTool('get_order_status', args, principal));
  });

  server.registerTool('list_cart', {
    title: 'List cart',
    description: 'Use this when an authenticated user wants to review their current cart and total.',
    outputSchema, annotations: readOnly,
  }, async () => { requireUser(principal); return result(await callTool('list_cart', {}, principal)); });

  server.registerTool('add_to_cart', {
    title: 'Add product to cart',
    description: 'Use this only after the authenticated user explicitly confirms adding a product, quantity, and size to their cart.',
    inputSchema: {
      product_id: z.number().int().positive(), quantity: z.number().int().min(1).max(99).default(1),
      size: z.string().min(1).max(20).default('S'), confirmed: z.literal(true),
    }, outputSchema, annotations: writeReversible,
  }, async ({ confirmed, ...args }) => { requireUser(principal); requireConfirmation(confirmed); return result(await callTool('add_to_cart', args, principal)); });

  server.registerTool('update_cart', {
    title: 'Update cart item',
    description: 'Use this only after the authenticated user explicitly confirms changing a cart item quantity or size.',
    inputSchema: {
      cart_id: z.number().int().positive(), quantity: z.number().int().min(1).max(99).optional(),
      size: z.string().min(1).max(20).optional(), confirmed: z.literal(true),
    }, outputSchema, annotations: writeReversible,
  }, async ({ confirmed, ...args }) => { requireUser(principal); requireConfirmation(confirmed); return result(await callTool('update_cart', args, principal)); });

  server.registerTool('remove_from_cart', {
    title: 'Remove cart item',
    description: 'Use this only after the authenticated user explicitly confirms removing a specific cart item.',
    inputSchema: { cart_id: z.number().int().positive(), confirmed: z.literal(true) }, outputSchema, annotations: writeDestructive,
  }, async ({ confirmed, ...args }) => { requireUser(principal); requireConfirmation(confirmed); return result(await callTool('remove_from_cart', args, principal)); });

  server.registerTool('list_orders', {
    title: 'List orders',
    description: 'Use this when an authenticated user wants to review their orders, optionally filtered by status.',
    inputSchema: { status: z.string().max(50).optional() }, outputSchema, annotations: readOnly,
  }, async (args) => { requireUser(principal); return result(await callTool('list_orders', args, principal)); });

  server.registerTool('get_order_detail', {
    title: 'Get order detail',
    description: 'Use this when an authenticated user asks for items and totals in one of their orders.',
    inputSchema: { order_id: z.number().int().positive() }, outputSchema, annotations: readOnly,
  }, async (args) => { requireUser(principal); return result(await callTool('get_order_detail', args, principal)); });

  server.registerTool('create_order', {
    title: 'Create order from cart',
    description: 'Use this only after the authenticated user explicitly confirms placing an order from their current cart. This does not process external payment.',
    inputSchema: { confirmed: z.literal(true) }, outputSchema, annotations: writeDestructive,
  }, async ({ confirmed }) => { requireUser(principal); requireConfirmation(confirmed); return result(await callTool('create_order', {}, principal)); });

  return server;
}
