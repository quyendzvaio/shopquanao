/** Pure scoring for the live eval: no I/O, importable from unit tests. */

export type Expect = {
  response_type?: string | string[];
  tools_include?: string[];
  no_tools?: boolean;
  products_include?: number[];
  min_products?: number;
  max_products?: number;
  offered_include?: number[];
  message_contains?: string[];
  message_contains_any?: string[][];
  message_mentions_price?: number;
  message_not_contains?: string[];
  nudge?: boolean;
};

export type TurnObservation = {
  message: string;
  answer: string;
  response_type: string;
  product_ids: number[];
  tools: string[];
  offered_ids: number[];
  nudge: boolean;
};

export type CaseSummary = { id: string; group: string; failures: string[] };

export type Summary = {
  passed: number;
  failed: number;
  rate: number;
  by_group: Record<string, { passed: number; total: number }>;
};

/**
 * Every price-like amount in the text, in VND: "85.000đ", "85,000 đ", "85.000 vnđ", "85k", "85 nghìn", "85 ngàn".
 * A bare number only counts when it carries a currency or magnitude suffix.
 */
export function extractPrices(text: string): number[] {
  const prices: number[] = [];
  const re = /(\d{1,3}(?:[.,]\d{3})+|\d+(?:[.,]\d+)?)\s*(k\b|nghìn|ngàn|vnđ|vnd|đ|₫)/giu;
  for (const m of text.matchAll(re)) {
    const raw = m[1] ?? '';
    const unit = (m[2] ?? '').toLowerCase();
    const thousands = /^\d{1,3}(?:[.,]\d{3})+$/.test(raw);
    const value = thousands ? Number(raw.replace(/[.,]/g, '')) : Number(raw.replace(',', '.'));
    if (Number.isNaN(value)) continue;
    prices.push(Math.round(unit === 'k' || unit === 'nghìn' || unit === 'ngàn' ? value * 1000 : value));
  }
  return prices;
}

function missing<T>(wanted: readonly T[], have: readonly T[]): T[] {
  return wanted.filter((w) => !have.includes(w));
}

/** Returns one failure line per unmet expectation (empty array = the turn passes). */
export function scoreTurn(expect: Expect, obs: TurnObservation): string[] {
  const failures: string[] = [];
  const answer = obs.answer.toLowerCase();

  if (expect.response_type !== undefined) {
    const allowed = Array.isArray(expect.response_type) ? expect.response_type : [expect.response_type];
    if (!allowed.includes(obs.response_type)) {
      failures.push(`response_type ${obs.response_type} not in [${allowed.join(', ')}]`);
    }
  } else if (obs.response_type === 'busy') {
    // The busy text answers nothing: it fails every case that does not ask for it by name.
    failures.push('response_type busy (the case does not expect busy)');
  }
  if (expect.tools_include !== undefined) {
    const lacking = missing(expect.tools_include, obs.tools);
    if (lacking.length > 0) failures.push(`tools missing: ${lacking.join(', ')} (used: ${obs.tools.join(', ') || 'none'})`);
  }
  if (expect.no_tools === true && obs.tools.length > 0) {
    failures.push(`expected no tools, used: ${obs.tools.join(', ')}`);
  }
  if (expect.products_include !== undefined) {
    const lacking = missing(expect.products_include, obs.product_ids);
    if (lacking.length > 0) failures.push(`cards missing ids: ${lacking.join(', ')} (cards: ${obs.product_ids.join(', ') || 'none'})`);
  }
  if (expect.min_products !== undefined && obs.product_ids.length < expect.min_products) {
    failures.push(`${obs.product_ids.length} cards < min_products ${expect.min_products}`);
  }
  if (expect.max_products !== undefined && obs.product_ids.length > expect.max_products) {
    failures.push(`${obs.product_ids.length} cards > max_products ${expect.max_products}`);
  }
  if (expect.offered_include !== undefined) {
    const lacking = missing(expect.offered_include, obs.offered_ids);
    if (lacking.length > 0) failures.push(`offered missing ids: ${lacking.join(', ')} (offered: ${obs.offered_ids.join(', ') || 'none'})`);
  }
  for (const fragment of expect.message_contains ?? []) {
    if (!answer.includes(fragment.toLowerCase())) failures.push(`answer lacks "${fragment}"`);
  }
  for (const alternatives of expect.message_contains_any ?? []) {
    if (!alternatives.some((a) => answer.includes(a.toLowerCase()))) {
      failures.push(`answer has none of [${alternatives.join(' | ')}]`);
    }
  }
  if (expect.message_mentions_price !== undefined && !extractPrices(obs.answer).includes(expect.message_mentions_price)) {
    failures.push(`answer does not mention the price ${expect.message_mentions_price}`);
  }
  for (const fragment of expect.message_not_contains ?? []) {
    if (answer.includes(fragment.toLowerCase())) failures.push(`answer contains forbidden "${fragment}"`);
  }
  if (expect.nudge !== undefined && obs.nudge !== expect.nudge) {
    failures.push(`nudge ${obs.nudge}, expected ${expect.nudge}`);
  }
  return failures;
}

export function summarize(cases: readonly CaseSummary[]): Summary {
  const by_group: Summary['by_group'] = {};
  let passed = 0;
  for (const c of cases) {
    const group = (by_group[c.group] ??= { passed: 0, total: 0 });
    group.total += 1;
    if (c.failures.length === 0) {
      group.passed += 1;
      passed += 1;
    }
  }
  return { passed, failed: cases.length - passed, rate: cases.length === 0 ? 0 : passed / cases.length, by_group };
}
