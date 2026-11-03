/** The eleven shop tools, none of which writes (a support ticket is only drafted; PHP creates it after the customer confirms). Cart, checkout and order-writing tools are never listed. */
export const ALLOWED_TOOLS: readonly string[] = [
  'search_products',
  'get_product_detail',
  'build_outfit',
  'find_similar_in_stock',
  'suggest_freeship_items',
  'suggest_size',
  'retrieve_knowledge',
  'get_categories',
  'get_order_status',
  'draft_support_ticket',
  'get_support_tickets',
];

export function isAllowedTool(name: string): boolean {
  return ALLOWED_TOOLS.includes(name);
}
