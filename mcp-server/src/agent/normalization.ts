import type { ProductCard, ToolExecution, Understanding } from './schemas.js';

export type HeightResult = { original: string; centimeters: number | null; confidence: number; ambiguous: boolean };

export function normalizeVietnameseHeight(text: string): HeightResult | null {
  const cm = text.match(/(?:^|\s)(\d{2,3})\s*cm\b/iu);
  if (cm) {
    const value = Number(cm[1]);
    return { original: cm[0].trim(), centimeters: value >= 80 && value <= 250 ? value : null, confidence: 1, ambiguous: value < 80 || value > 250 };
  }
  const decimal = text.match(/(?:^|\s)([12])[,.](\d{1,2})\s*m\b/iu);
  if (decimal) {
    const fraction = decimal[2]!.padEnd(2, '0');
    const value = Number(decimal[1]) * 100 + Number(fraction);
    return { original: decimal[0].trim(), centimeters: value >= 80 && value <= 250 ? value : null, confidence: 1, ambiguous: value < 80 || value > 250 };
  }
  const compact = text.match(/(?:^|\s)([12])\s*m\s*(\d{1,2})\b/iu);
  if (compact) {
    const fraction = compact[2]!.length === 1 ? Number(compact[2]) * 10 : Number(compact[2]);
    const value = Number(compact[1]) * 100 + fraction;
    return { original: compact[0].trim(), centimeters: value >= 80 && value <= 250 ? value : null, confidence: 0.98, ambiguous: value < 80 || value > 250 };
  }
  return null;
}

export function normalizeMeasurements(message: string, understanding: Understanding, sizeContext: boolean): Understanding {
  if (understanding.primary_intent !== 'size_advice' && !sizeContext) return understanding;
  const height = normalizeVietnameseHeight(message);
  const weightMatch = message.match(/(?:^|\s)(\d{2,3})\s*kg\b/iu);
  const weight = weightMatch ? Number(weightMatch[1]) : understanding.entities.weight_kg;
  const hasMeasurement = height?.centimeters !== null && height?.centimeters !== undefined || weight !== null;
  return {
    ...understanding,
    primary_intent: sizeContext && understanding.primary_intent === 'unknown' ? 'size_advice' : understanding.primary_intent,
    confidence: sizeContext && hasMeasurement ? Math.max(understanding.confidence, 0.9) : understanding.confidence,
    entities: {
      ...understanding.entities,
      height_cm: height?.centimeters ?? understanding.entities.height_cm,
      weight_kg: weight !== null && weight >= 20 && weight <= 300 ? weight : null,
    },
    missing_slots: height?.ambiguous
      ? ['height_confirmation']
      : ['height', 'weight'].filter(key => key === 'height'
        ? (height?.centimeters ?? understanding.entities.height_cm) === null
        : weight === null || weight < 20 || weight > 300),
  };
}

function productsFrom(result: Record<string, unknown> | null): Record<string, unknown>[] {
  if (!result) return [];
  if (Array.isArray(result.products)) return result.products.filter(item => item && typeof item === 'object') as Record<string, unknown>[];
  if (result.product && typeof result.product === 'object') return [result.product as Record<string, unknown>];
  if (Array.isArray(result.groups)) {
    return result.groups.flatMap(group => group && typeof group === 'object' && Array.isArray((group as Record<string, unknown>).products)
      ? (group as { products: Record<string, unknown>[] }).products : []);
  }
  return [];
}

export function productCards(executions: ToolExecution[]): ProductCard[] {
  const byId = new Map<number, ProductCard>();
  for (const execution of executions) {
    for (const item of productsFrom(execution.result)) {
      const id = Number(item.id ?? 0);
      if (!Number.isInteger(id) || id <= 0) continue;
      const card: ProductCard = {
        id,
        name: String(item.name ?? ''),
        description: String(item.description ?? ''),
        price: Number(item.price ?? 0),
        stock: Number(item.stock ?? 0),
        image: String(item.image ?? ''),
        url: String(item.url ?? `/product.php?id=${id}`),
      };
      if (!card.image_url) card.image_url = normalizeImageUrl(card.image);
      for (const field of ['category_id', 'category_name', 'available_sizes', 'available_colors', 'canonical_colors', 'colors', 'variants', 'subcategory', 'subcategory_name', 'url', 'image_url']) {
        if (item[field] !== undefined) card[field] = sanitizeCatalogValue(item[field]);
      }
      byId.set(id, card);
    }
  }
  return [...byId.values()];
}

function sanitizeCatalogValue(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(sanitizeCatalogValue);
  if (value && typeof value === 'object') {
    const cleaned: Record<string, unknown> = {};
    for (const [key, item] of Object.entries(value)) {
      if (/provider|external/i.test(key)) continue;
      cleaned[key] = sanitizeCatalogValue(item);
    }
    return cleaned;
  }
  return value;
}

function normalizeImageUrl(image: unknown): string {
  const raw = String(image ?? '').trim();
  if (raw === '') return '';
  if (raw.startsWith('http://') || raw.startsWith('https://') || raw.startsWith('/')) return raw;
  return `/images/products/${raw}`;
}

export function knowledgeSources(executions: ToolExecution[]): Record<string, unknown>[] {
  const execution = executions.find(item => item.tool === 'retrieve_knowledge' && item.success);
  const results = execution?.result?.results;
  return Array.isArray(results) ? results.filter(item => item && typeof item === 'object') as Record<string, unknown>[] : [];
}
