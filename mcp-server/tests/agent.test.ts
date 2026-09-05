import assert from 'node:assert/strict';
import test from 'node:test';
import { AgentOrchestrator, classifySizeFit } from '../src/agent/orchestrator.js';
import { parseFirstJsonObject } from '../src/agent/llm.js';
import { normalizeMeasurements, normalizeVietnameseHeight, productCards } from '../src/agent/normalization.js';
import { UnderstandingSchema, type Understanding } from '../src/agent/schemas.js';

test('Vietnamese height aliases normalize to 170cm', () => {
  for (const value of ['1m7', '1m70', '1,70m', '1.70 m', '170cm']) {
    assert.equal(normalizeVietnameseHeight(`cao ${value}`)?.centimeters, 170, value);
  }
});

test('structured output parser accepts extra provider text after the JSON object', () => {
  assert.deepEqual(parseFirstJsonObject('```json\n{"a":{"b":"}"},"c":1}\n```\nextra'), { a: { b: '}' }, c: 1 });
});

test('understanding schema treats null optional collections as empty values', () => {
  const parsed = UnderstandingSchema.parse({
    primary_intent: 'product_search',
    confidence: 0.9,
    entities: {},
    secondary_intents: null,
    requested_fields: null,
    missing_slots: null,
    stable_preferences: null,
  });
  assert.deepEqual(parsed.secondary_intents, []);
  assert.deepEqual(parsed.requested_fields, []);
  assert.deepEqual(parsed.missing_slots, []);
  assert.deepEqual(parsed.stable_preferences, {});
  assert.deepEqual(UnderstandingSchema.parse({
    primary_intent: 'product_search',
    confidence: 0.9,
    entities: {},
    stable_preferences: [],
  }).stable_preferences, {});
});

test('implausible heights are marked ambiguous instead of persisted as measurements', () => {
  assert.deepEqual(normalizeVietnameseHeight('cao 3m2'), null);
  const short = normalizeVietnameseHeight('cao 50cm');
  assert.equal(short?.centimeters, null);
  assert.equal(short?.ambiguous, true);
});

test('measurement-only reply resumes a pending size clarification', () => {
  const unknown: Understanding = {
    primary_intent: 'unknown', secondary_intents: [], confidence: 0.2,
    entities: { product_id: null, product_query: null, category_id: null, color: null, size: null, height_cm: null, weight_kg: null, min_price: null, max_price: null, occasion: null, order_id: null, cart_id: null, quantity: null },
    requested_fields: [], missing_slots: [], refers_to_active_product: false,
    is_hypothetical: false, explicit_confirmation: false, stable_preferences: {},
  };
  const normalized = normalizeMeasurements('mình nặng 49kg và cao 1m7', unknown, true);
  assert.equal(normalized.primary_intent, 'size_advice');
  assert.equal(normalized.confidence, 0.9);
  assert.equal(normalized.entities.height_cm, 170);
  assert.equal(normalized.entities.weight_kg, 49);
  assert.deepEqual(normalized.missing_slots, []);
});

test('size fit classification covers fit, boundaries, and each mismatch', () => {
  const row = { height_from: 160, height_to: 170, weight_from: 50, weight_to: 60 };
  assert.equal(classifySizeFit(165, 55, row), 'fit');
  assert.equal(classifySizeFit(169, 55, row), 'boundary');
  assert.equal(classifySizeFit(180, 55, row), 'height_mismatch');
  assert.equal(classifySizeFit(165, 70, row), 'weight_mismatch');
  assert.equal(classifySizeFit(180, 70, row), 'both_mismatch');
});

test('private cards whitelist fields and never expose provider identifiers', () => {
  const cards = productCards([{ tool: 'suggest_complementary_products', arguments: {}, duration_ms: 1, success: true, result: {
    products: [{ id: 54, name: 'Áo polo', price: 290000, stock: 3, image: 'polo.jpg', provider_product_id: 'external-1', sku: 'provider-sku', variants: [{ size: 'S', provider_variant_id: 'pv-1' }] }],
  } }]);
  assert.equal(cards[0]?.id, 54);
  assert.equal(cards[0]?.url, '/product.php?id=54');
  assert.equal(cards[0]?.image_url, '/images/products/polo.jpg');
  assert.equal('provider_product_id' in cards[0]!, false);
  assert.equal('sku' in cards[0]!, false);
  assert.deepEqual(cards[0]?.variants, [{ size: 'S' }]);
});

test('graph returns the compatible response contract and isolates thread history', async () => {
  const classify = async (): Promise<Understanding> => ({
    primary_intent: 'unknown', secondary_intents: [], confidence: 0.2,
    entities: { product_id: null, product_query: null, category_id: null, color: null, size: null, height_cm: null, weight_kg: null, min_price: null, max_price: null, occasion: null, order_id: null, cart_id: null, quantity: null },
    requested_fields: [], missing_slots: [], refers_to_active_product: false,
    is_hypothetical: false, explicit_confirmation: false, stable_preferences: {},
  });
  const agent = await AgentOrchestrator.create(classify);
  const request = { userId: null, message: 'xin chào', authContext: { authenticated: false, scopes: ['shop.read'] } };
  const first = await agent.invokeAgentTurn({ ...request, threadId: 'thread-a' });
  const second = await agent.invokeAgentTurn({ ...request, threadId: 'thread-b' });
  assert.equal(first.response_type, 'fallback');
  assert.equal(first.primary_intent, 'unknown');
  assert.deepEqual(first.products, []);
  assert.notEqual(first.trace_id, second.trace_id);
  assert.equal(first.latency.pipeline, 'langgraph');
});

test('graph validates account and mutation slots before planning tools', async () => {
  const classify = async (): Promise<Understanding> => ({
    primary_intent: 'add_to_cart', secondary_intents: [], confidence: 0.92,
    entities: { product_id: 54, product_query: null, category_id: null, color: null, size: null, height_cm: null, weight_kg: null, min_price: null, max_price: null, occasion: null, order_id: null, cart_id: null, quantity: 1 },
    requested_fields: [], missing_slots: [], refers_to_active_product: false, is_hypothetical: false, explicit_confirmation: true, stable_preferences: {},
  });
  const agent = await AgentOrchestrator.create(classify);
  const result = await agent.invokeAgentTurn({ threadId: 'mutation-slots', userId: null, message: 'thêm vào giỏ', authContext: { authenticated: false, scopes: ['shop.read'] } });
  assert.equal(result.response_type, 'clarification');
  assert.deepEqual(result.missing_slots.sort(), ['authentication', 'size']);
  assert.equal(result.tool_executions.length, 0);
});
