import type { Offered, ToolRecord } from '../contract.js';

export type SoldOutState = {
  source: { id: number; name: string };
  alternatives: Offered[];
  count: number;
  search: string;
  /** Ids the search reported as sold out; their cards are never shown. */
  ids: number[];
};

/**
 * A sold-out hit: a `search_products` record whose `out_of_stock_ids` is non-empty (a missing key counts as empty),
 * or a `get_product_detail` record whose product has `stock` <= 0.
 */
export function soldOutFrom(record: ToolRecord): { ids: number[]; search: string } | null {
  if (record.name === 'get_product_detail') {
    const product = record.result.product;
    if (typeof product !== 'object' || product === null) return null;
    const row = product as Record<string, unknown>;
    const id = Number(row.id);
    if (!Number.isInteger(id) || id <= 0 || row.stock === undefined || row.stock === null || row.stock === '') return null;
    const stock = Number(row.stock);
    return Number.isFinite(stock) && stock <= 0 ? { ids: [id], search: '' } : null;
  }
  if (record.name !== 'search_products') return null;
  const raw = record.result.out_of_stock_ids;
  if (!Array.isArray(raw)) return null;
  const ids = raw.map(Number).filter((n) => Number.isInteger(n) && n > 0);
  if (ids.length === 0) return null;
  const search = typeof record.args.search === 'string' ? record.args.search : '';
  return { ids, search };
}
