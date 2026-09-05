import { z } from 'zod/v4';

export const intentNames = [
  'product_search', 'product_detail', 'size_advice', 'return_exchange', 'shipping', 'policy',
  'order_status', 'list_cart', 'list_orders', 'suggest_complementary_products',
  'occasion_styling', 'add_to_cart', 'update_cart', 'remove_from_cart', 'create_order', 'unknown',
] as const;

export const UnderstandingSchema = z.object({
  primary_intent: z.enum(intentNames),
  secondary_intents: z.preprocess(toOptionalArray, z.array(z.enum(intentNames))).default([]),
  confidence: z.number().min(0).max(1),
  entities: z.object({
    product_id: z.number().int().positive().nullable().default(null),
    product_query: z.string().max(300).nullable().default(null),
    category_id: z.number().int().min(1).max(5).nullable().default(null),
    color: z.string().max(50).nullable().default(null),
    size: z.string().max(20).nullable().default(null),
    height_cm: z.number().int().min(80).max(250).nullable().default(null),
    weight_kg: z.number().int().min(20).max(300).nullable().default(null),
    min_price: z.number().nonnegative().nullable().default(null),
    max_price: z.number().nonnegative().nullable().default(null),
    occasion: z.string().max(100).nullable().default(null),
    order_id: z.number().int().positive().nullable().default(null),
    cart_id: z.number().int().positive().nullable().default(null),
    quantity: z.number().int().min(1).max(99).nullable().default(null),
  }),
  requested_fields: z.preprocess(toOptionalArray, z.array(z.string())).default([]),
  missing_slots: z.preprocess(toOptionalArray, z.array(z.string())).default([]),
  refers_to_active_product: z.boolean().default(false),
  is_hypothetical: z.boolean().default(false),
  explicit_confirmation: z.boolean().default(false),
  stable_preferences: z.preprocess(toOptionalRecord, z.record(z.string(), z.union([z.string(), z.number(), z.boolean()]))).default({}),
});

function toOptionalArray(value: unknown): unknown {
  return Array.isArray(value) ? value : [];
}

function toOptionalRecord(value: unknown): unknown {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

export type Understanding = z.infer<typeof UnderstandingSchema>;

export const AgentTurnRequestSchema = z.object({
  threadId: z.string().min(1).max(255),
  userId: z.number().int().positive().nullable(),
  message: z.string().min(1).max(2000),
  authContext: z.object({
    authenticated: z.boolean(),
    scopes: z.array(z.string()).default(['shop.read']),
  }),
});

export type AgentTurnRequest = z.infer<typeof AgentTurnRequestSchema>;

export type ToolExecution = {
  tool: string;
  arguments: Record<string, unknown>;
  result: Record<string, unknown> | null;
  duration_ms: number;
  success: boolean;
  error?: string;
};

export type ProductCard = Record<string, unknown> & { id: number };

export type AgentTurnResult = {
  message: string;
  answer: string;
  response_type: 'final_answer' | 'clarification' | 'fallback';
  primary_intent: string;
  secondary_intents: string[];
  requested_fields: string[];
  missing_slots: string[];
  cards: ProductCard[];
  products: ProductCard[];
  knowledge_sources: Record<string, unknown>[];
  trace_id: string;
  latency: Record<string, number | string | boolean>;
  proactive_styling?: boolean;
  proactive_status?: string;
  tool_executions: ToolExecution[];
};
