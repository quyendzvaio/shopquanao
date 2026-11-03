import type { Expect } from './scorer.js';

export type CartLine = { product_id: number; quantity: number; size: string };
export type EvalTurn = { message: string; expect: Expect };
export type OrderFixture = { total: number; status: string };
export type EvalCase = { id: string; group: string; user: boolean; cart?: CartLine[]; orders?: OrderFixture[]; turns: EvalTurn[] };

const EXPECT_KEYS = new Set<string>([
  'response_type', 'tools_include', 'no_tools', 'products_include', 'min_products', 'max_products',
  'offered_include', 'message_contains', 'message_contains_any', 'message_mentions_price', 'message_not_contains', 'nudge',
  'ticket_pending', 'ticket_created',
]);

/** Parses cases.jsonl and rejects typos (unknown expect keys, duplicate ids, empty turns). */
export function parseCases(text: string): EvalCase[] {
  const cases: EvalCase[] = [];
  const seen = new Set<string>();
  text.split('\n').forEach((raw, index) => {
    if (raw.trim() === '') return;
    const where = `cases.jsonl line ${index + 1}`;
    const c = JSON.parse(raw) as EvalCase;
    if (typeof c.id !== 'string' || typeof c.group !== 'string' || typeof c.user !== 'boolean') {
      throw new Error(`${where}: id, group and user are required`);
    }
    if (seen.has(c.id)) throw new Error(`${where}: duplicate id ${c.id}`);
    seen.add(c.id);
    if (!Array.isArray(c.turns) || c.turns.length === 0) throw new Error(`${where}: no turns`);
    if (c.cart !== undefined && !c.user) throw new Error(`${where}: a cart needs user=true`);
    if (c.orders !== undefined && !c.user) throw new Error(`${where}: orders need user=true`);
    for (const turn of c.turns) {
      if (typeof turn.message !== 'string' || turn.message === '') throw new Error(`${where}: empty message`);
      for (const key of Object.keys(turn.expect ?? {})) {
        if (!EXPECT_KEYS.has(key)) throw new Error(`${where}: unknown expect key ${key}`);
      }
    }
    cases.push(c);
  });
  return cases;
}

const ORDER_PLACEHOLDER = '{order1}';

/**
 * Replaces `{order1}` with the first order id the fixture created, in the turn's message and in the string
 * expectations (message_contains, message_contains_any, message_not_contains). Returns a copy.
 */
export function fillOrderPlaceholder(turn: EvalTurn, orderIds: readonly number[]): EvalTurn {
  const fill = (text: string): string => {
    if (!text.includes(ORDER_PLACEHOLDER)) return text;
    const id = orderIds[0];
    if (id === undefined) throw new Error(`${ORDER_PLACEHOLDER} used but the case has no order fixture`);
    return text.split(ORDER_PLACEHOLDER).join(String(id));
  };
  const expect: Expect = { ...turn.expect };
  if (expect.message_contains) expect.message_contains = expect.message_contains.map(fill);
  if (expect.message_contains_any) expect.message_contains_any = expect.message_contains_any.map((g) => g.map(fill));
  if (expect.message_not_contains) expect.message_not_contains = expect.message_not_contains.map(fill);
  return { message: fill(turn.message), expect };
}
