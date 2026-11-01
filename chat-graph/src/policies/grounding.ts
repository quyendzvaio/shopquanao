import type { Card, ToolRecord } from '../contract.js';
import { BUSY, money } from '../copy.js';
import { productsOf } from './cards.js';

const MONEY = /(\d{1,3}(?:[.,]\d{3})+|\d+)\s*(k|nghìn|đ|₫|vnđ|vnd)(?![\p{L}])/giu;

function collectNumbers(value: unknown, into: Set<number>): void {
  if (typeof value === 'number' && Number.isFinite(value)) into.add(Math.round(value));
  else if (typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test(value.trim())) into.add(Math.round(Number(value)));
  else if (Array.isArray(value)) for (const v of value) collectNumbers(v, into);
  else if (typeof value === 'object' && value !== null) for (const v of Object.values(value)) collectNumbers(v, into);
}

function productIds(records: ToolRecord[]): Set<number> {
  const ids = new Set<number>();
  for (const record of records) {
    for (const f of productsOf(record)) {
      const id = Number(f.product.id);
      if (Number.isFinite(id)) ids.add(id);
    }
    const source = record.result.source;
    if (typeof source === 'object' && source !== null && Number.isFinite(Number((source as Record<string, unknown>).id))) {
      ids.add(Number((source as Record<string, unknown>).id));
    }
  }
  return ids;
}

/** Claims in the answer that no tool result backs: an unknown "mã N" or an unseen money amount. */
export function ungroundedClaims(answer: string, records: ToolRecord[]): string[] {
  const claims: string[] = [];
  const ids = productIds(records);
  for (const m of answer.matchAll(/mã\s*(\d+)/giu)) {
    if (!ids.has(Number(m[1]))) claims.push(m[0]);
  }
  const numbers = new Set<number>();
  for (const record of records) collectNumbers(record.result, numbers);
  for (const m of answer.matchAll(MONEY)) {
    const unit = (m[2] ?? '').toLowerCase();
    let value = Number((m[1] ?? '').replace(/[.,]/g, ''));
    if (unit === 'k' || unit === 'nghìn') value *= 1000;
    if (!numbers.has(Math.round(value))) claims.push(m[0]);
  }
  return claims;
}

export function summaryFromCards(cards: Card[]): string {
  if (cards.length === 0) return BUSY;
  const items = cards.map((c) => `${c.name} (mã ${c.id}) giá ${money(c.price)}`).join('; ');
  return `Mình tìm được các mẫu sau: ${items}. Bạn bấm vào thẻ sản phẩm để xem chi tiết nhé.`;
}
