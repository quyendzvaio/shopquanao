/** The nine read-only shop tools. Cart, checkout and order-writing tools are never listed. */
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
];

export function isAllowedTool(name: string): boolean {
  return ALLOWED_TOOLS.includes(name);
}
