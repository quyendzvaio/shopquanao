import type { Card, ToolRecord } from '../contract.js';

const PRODUCT_TOOLS = ['search_products', 'get_product_detail', 'find_similar_in_stock', 'build_outfit'];
const MAX_CARDS = 6;

type Raw = Record<string, unknown>;
type Found = { product: Raw; outfitIndex?: number };

function isRecord(v: unknown): v is Raw {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function num(v: unknown): number | null {
  const n = typeof v === 'string' && v.trim() !== '' ? Number(v) : v;
  return typeof n === 'number' && Number.isFinite(n) ? n : null;
}

function strings(v: unknown): string[] {
  return Array.isArray(v) ? v.filter((s): s is string => typeof s === 'string' && s !== '') : [];
}

/** Port of EvidenceNormalizer::productImageUrl. */
function imageUrl(image: string, fallback: string): string | null {
  const raw = (image !== '' ? image : fallback).trim();
  if (raw === '') return null;
  let path = raw;
  try {
    path = new URL(raw, 'http://x').pathname || raw;
  } catch { /* keep raw */ }
  path = path.replace(/^\/+/, '');
  if (path.startsWith('assets/images/')) return `/${path}`;
  if (path.startsWith('images/')) path = path.slice('images/'.length);
  return `/assets/images/${path}`;
}

function colorsOf(p: Raw): string[] {
  const direct = strings(p.available_colors);
  if (direct.length > 0) return direct;
  const out: string[] = [];
  if (Array.isArray(p.variants)) {
    for (const v of p.variants) {
      const c = isRecord(v) ? (v.color ?? v.colour) : undefined;
      if (typeof c === 'string' && c !== '' && !out.includes(c)) out.push(c);
    }
  }
  return out;
}

function sizesOf(p: Raw): string[] {
  const direct = strings(p.available_sizes);
  if (direct.length > 0) return direct;
  const out: string[] = [];
  if (Array.isArray(p.variants)) {
    for (const v of p.variants) {
      const s = isRecord(v) ? v.size : undefined;
      if (typeof s === 'string' && s !== '' && !out.includes(s)) out.push(s);
    }
  }
  return out;
}

export function productToCard(p: Raw, outfitIndex?: number): Card {
  const id = Math.trunc(num(p.id) ?? 0);
  const image = typeof p.image === 'string' ? p.image : '';
  const card: Card = {
    id,
    name: typeof p.name === 'string' ? p.name : '',
    price: num(p.price) ?? 0,
    stock: Math.trunc(num(p.stock) ?? 0),
    image: image !== '' ? image : null,
    image_url: imageUrl(image, typeof p.image_url === 'string' ? p.image_url : ''),
    url: id > 0 ? `/product.php?id=${id}` : '',
    category_id: num(p.category_id),
    available_sizes: sizesOf(p),
    available_colors: colorsOf(p),
  };
  const index = outfitIndex ?? num(p.outfit_index);
  if (index !== null && index !== undefined) card.outfit_index = index;
  return card;
}

export function verifyCard(card: Card, args: Record<string, unknown>): boolean {
  if (args.in_stock === true && !(card.stock > 0)) return false;
  const min = num(args.min_price);
  if (min !== null && card.price < min) return false;
  const max = num(args.max_price);
  if (max !== null && card.price > max) return false;
  const category = num(args.category_id);
  if (category !== null && card.category_id !== category) return false;
  return true;
}

/**
 * Products of one record: `result.products[]`, `result.product` and `result.outfits[].items[]`.
 * Note: PHP's build_outfit returns `outfits[]` with `product_ids` and its items in `result.products`
 * (each with `outfit_index`); `outfits[].items[]` is read too when a result carries it.
 */
export function productsOf(record: ToolRecord): Found[] {
  const found: Found[] = [];
  const result = record.result;
  if (Array.isArray(result.products)) {
    for (const p of result.products) if (isRecord(p)) found.push({ product: p });
  }
  if (isRecord(result.product)) found.push({ product: result.product });
  if (Array.isArray(result.outfits)) {
    result.outfits.forEach((outfit, i) => {
      if (!isRecord(outfit) || !Array.isArray(outfit.items)) return;
      const index = num(outfit.outfit_index) ?? i + 1;
      for (const p of outfit.items) if (isRecord(p)) found.push({ product: p, outfitIndex: index });
    });
  }
  return found;
}

function cardsOf(record: ToolRecord): Card[] {
  return productsOf(record)
    .filter((f) => num(f.product.id) !== null)
    .map((f) => productToCard(f.product, f.outfitIndex))
    .filter((c) => c.id > 0 && verifyCard(c, record.args));
}

function mentionedIds(answer: string): number[] {
  const ids: number[] = [];
  for (const m of answer.matchAll(/mã\s*(\d+)/giu)) {
    const id = Number(m[1]);
    if (!ids.includes(id)) ids.push(id);
  }
  return ids;
}

function dedupe(cards: Card[]): Card[] {
  const seen = new Set<number>();
  const out: Card[] = [];
  for (const c of cards) {
    if (seen.has(c.id)) continue;
    seen.add(c.id);
    out.push(c);
  }
  return out.slice(0, MAX_CARDS);
}

/** §5.3: cards named by "mã N" in the answer, else the products of the last product tool. */
export function selectCards(answer: string, records: ToolRecord[]): Card[] {
  const mentioned = mentionedIds(answer);
  if (mentioned.length > 0) {
    const verified = records.flatMap(cardsOf);
    const picked: Card[] = [];
    for (const id of mentioned) {
      const card = verified.find((c) => c.id === id);
      if (card) picked.push(card);
    }
    if (picked.length > 0) return dedupe(picked);
  }
  for (let i = records.length - 1; i >= 0; i--) {
    const record = records[i];
    if (record && PRODUCT_TOOLS.includes(record.name)) return dedupe(cardsOf(record));
  }
  return [];
}

export function outfitsFrom(records: ToolRecord[]): unknown[] {
  for (let i = records.length - 1; i >= 0; i--) {
    const record = records[i];
    if (record && record.name === 'build_outfit') {
      return Array.isArray(record.result.outfits) ? record.result.outfits : [];
    }
  }
  return [];
}
