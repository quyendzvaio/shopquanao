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

  server.registerTool('find_similar_in_stock', {
    title: 'Find similar in-stock products',
    description: 'Use this when a product the customer asked about is sold out, to find in-stock products of the same category and similar style.',
    inputSchema: {
      product_id: z.number().int().positive(),
      limit: z.number().int().min(1).max(5).optional(),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('find_similar_in_stock', args, principal)));

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
    description: 'Use this whenever the user asks about their own orders (where an order is, its status, or an order to '
      + 'return, exchange, cancel or report a problem with); call it before asking for an order number. Without order_id '
      + 'it lists the customer\'s 5 most recent orders (id, status, total); with order_id it returns that one order with '
      + 'its items. For a guest it returns requires_login, so the user can be asked to log in.',
    inputSchema: {
      order_id: z.number().int().positive().optional()
        .describe('Order number, only when the user gives one; leave it out to list the recent orders.'),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('get_order_status', args, principal)));

  server.registerTool('draft_support_ticket', {
    title: 'Draft a support ticket',
    description: 'Soạn nháp yêu cầu hỗ trợ đơn hàng (đổi trả, hàng lỗi, huỷ đơn, đổi địa chỉ, khác) để hỏi khách xác nhận; không tạo yêu cầu.',
    inputSchema: {
      type: z.enum(['doi_tra', 'hang_loi', 'huy_don', 'doi_dia_chi', 'khac']),
      order_id: z.number().int().positive().optional(),
      summary: z.string().min(1).max(500),
    }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('draft_support_ticket', args, principal)));

  server.registerTool('get_support_tickets', {
    title: 'Get support tickets',
    description: 'Xem các yêu cầu hỗ trợ khách đã gửi và trạng thái xử lý.',
    inputSchema: { ticket_id: z.number().int().positive().optional() }, outputSchema, annotations: readOnly,
  }, async (args) => result(await callTool('get_support_tickets', args, principal)));

  return server;
}
