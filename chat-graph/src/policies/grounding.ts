import type { Card, ToolRecord } from '../contract.js';
import { BUSY, money } from '../copy.js';
import { productsOf } from './cards.js';

// Decimal (`1,5k`) before thousands groups (`490.000`, `490 000`) so each keeps its meaning; a group separator is
// `.`, `,` or a space (plain, no-break or narrow no-break), the same one throughout the amount. The leading guard
// stops a match from starting in the middle of a number.
const MONEY = new RegExp(
  '(?<![\\d.,])(?<amount>\\d+[.,]\\d{1,2}|\\d{1,3}(?<sep>[., \u00a0\u202f])\\d{3}(?:\\k<sep>\\d{3})*|\\d+)\\s*'
  + '(?<unit>vnđ|vnd|đồng|dong|triệu|trieu|nghìn|nghin|ngàn|ngan|tr|k|đ|₫)(?![\\p{L}])',
  'giu',
);
const DECIMAL = /^\d+[.,]\d{1,2}$/;
const MULTIPLIER_UNITS = ['triệu', 'trieu', 'tr', 'k', 'nghìn', 'nghin', 'ngàn', 'ngan'];

/**
 * The amount a money match states. A space never groups digits before a multiplier unit: "2 150k" is "2 × 150k",
 * so only the last number counts.
 */
function moneyValue(amount: string, separator: string | undefined, unit: string): number {
  const spaced = separator !== undefined && /\s/u.test(separator);
  const digits = spaced && MULTIPLIER_UNITS.includes(unit) ? amount.split(separator).at(-1) ?? amount : amount;
  const base = DECIMAL.test(digits) ? Number(digits.replace(',', '.')) : Number(digits.replace(/[.,\s]/gu, ''));
  if (['triệu', 'trieu', 'tr'].includes(unit)) return base * 1_000_000;
  if (['k', 'nghìn', 'nghin', 'ngàn', 'ngan'].includes(unit)) return base * 1000;
  return base;
}

function amountOf(m: RegExpMatchArray): number {
  const g = m.groups ?? {};
  return moneyValue(g.amount ?? '', g.sep, (g.unit ?? '').toLowerCase());
}

function moneyAmounts(text: string): number[] {
  return [...text.matchAll(MONEY)].map((m) => Math.round(amountOf(m)));
}

function collectNumbers(value: unknown, into: Set<number>): void {
  if (typeof value === 'number' && Number.isFinite(value)) into.add(Math.round(value));
  else if (typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test(value.trim())) into.add(Math.round(Number(value)));
  // Amounts written inside tool text (a policy passage: "phí ship 30,000đ") back the answer too.
  else if (typeof value === 'string') for (const amount of moneyAmounts(value)) into.add(amount);
  else if (Array.isArray(value)) for (const v of value) collectNumbers(v, into);
  else if (typeof value === 'object' && value !== null) for (const v of Object.values(value)) collectNumbers(v, into);
}

function addId(ids: Set<number>, value: unknown): void {
  if (value === null || value === undefined || value === '') return;
  const id = Number(value);
  if (Number.isFinite(id)) ids.add(id);
}

function productIds(records: ToolRecord[]): Set<number> {
  const ids = new Set<number>();
  for (const record of records) {
    for (const f of productsOf(record)) {
      const id = Number(f.product.id);
      if (Number.isFinite(id)) ids.add(id);
    }
    // A "còn hàng" search lists the sold-out name matches it hid: the answer may name them, they never get a card.
    const hidden = record.result.out_of_stock_products;
    if (Array.isArray(hidden)) {
      for (const p of hidden) {
        const id = typeof p === 'object' && p !== null ? Number((p as Record<string, unknown>).id) : NaN;
        if (Number.isFinite(id)) ids.add(id);
      }
    }
    // Order ids ("đơn mã 15") from get_order_status are as real as product ids, and so are the products on the
    // order's lines ("Áo Len Cổ Tròn Xám (mã 53)"); a line's own id is not a product and grounds nothing.
    const orders = record.result.orders;
    if (Array.isArray(orders)) {
      for (const o of orders) {
        if (typeof o !== 'object' || o === null) continue;
        addId(ids, (o as Record<string, unknown>).id);
        const items = (o as Record<string, unknown>).items;
        if (!Array.isArray(items)) continue;
        for (const item of items) {
          if (typeof item === 'object' && item !== null) addId(ids, (item as Record<string, unknown>).product_id);
        }
      }
    }
    // A drafted ticket's order, a refused draft's existing ticket and the customer's tickets (id, order_id).
    const draft = record.result.draft;
    if (typeof draft === 'object' && draft !== null) addId(ids, (draft as Record<string, unknown>).order_id);
    addId(ids, record.result.ticket_id);
    const tickets = record.result.tickets;
    if (Array.isArray(tickets)) {
      for (const t of tickets) {
        if (typeof t !== 'object' || t === null) continue;
        addId(ids, (t as Record<string, unknown>).id);
        addId(ids, (t as Record<string, unknown>).order_id);
      }
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
    if (!numbers.has(Math.round(amountOf(m)))) claims.push(m[0]);
  }
  return claims;
}

// `<|…`, `<｜…` and `</｜…` open the special tokens every provider's raw tool-call text uses (`<|tool_calls_begin|>`,
// `<|im_start|>`, DeepSeek's `<｜｜DSML｜｜ calls>`); a shop answer never puts `|` right after `<`.
const TOOL_CALL_MARKUP = /<\/?tool_call|<\/?\s*[|｜]|<function=/i;

/** Raw tool-call markup in a plain-text draft: the model "called" a tool as text instead of structurally. */
export function hasToolCallMarkup(text: string): boolean {
  return TOOL_CALL_MARKUP.test(text);
}

/**
 * The answer as plain text: the chat widget renders `textContent`, so markdown markers would show verbatim.
 * Drops heading marks, emphasis (`**`, `__`, `*x*`), inline code backticks and link syntax; `*`/`+` bullets become `-`.
 * Line breaks and `-` bullets stay.
 */
export function plainText(text: string): string {
  return text
    .replace(/^[ \t]*#{1,6}[ \t]+/gmu, '')
    .replace(/^([ \t]*)[*+][ \t]+/gmu, '$1- ')
    .replace(/\[([^\]\n]+)\]\([^)\s]+\)/gu, '$1')
    .replace(/\*\*|__|`/gu, '')
    .replace(/(^|[^\p{L}\p{N}*])\*(?=\S)([^*\n]+?)\*(?![\p{L}\p{N}])/gu, '$1$2')
    // A lone marker (an emphasis pair spanning a newline or, when streaming, two sentences) is dropped too, so the
    // streamed sentences and the whole answer agree; `2 * 3` and `M*L` keep their star.
    .replace(/(^|[^\p{L}\p{N}*])\*(?=\S)/gu, '$1')
    .replace(/(?<=\S)\*(?![\p{L}\p{N}])/gu, '');
}

export function summaryFromCards(cards: Card[]): string {
  if (cards.length === 0) return BUSY;
  const items = cards.map((c) => `${c.name} (mã ${c.id}) giá ${money(c.price)}`).join('; ');
  return `Mình tìm được các mẫu sau: ${items}. Bạn bấm vào thẻ sản phẩm để xem chi tiết nhé.`;
}
