import { config } from './config.js';

export type Principal = {
  userId: number | null;
  mode: 'anonymous' | 'service';
  scopes: string[];
};

type InternalResponse = Record<string, unknown>;

export class ShopApiError extends Error {
  constructor(message: string, public readonly status: number) {
    super(message);
  }
}

export async function internalCall(payload: Record<string, unknown>, attempts = 1): Promise<InternalResponse> {
  let lastError: unknown;
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), config.requestTimeoutMs);
    try {
      const response = await fetch(config.shopInternalUrl, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'x-mcp-service-token': config.serviceToken,
        },
        body: JSON.stringify(payload),
        signal: controller.signal,
      });
      const body = await response.json().catch(() => ({})) as InternalResponse;
      if (!response.ok) {
        const message = typeof body.message === 'string' ? body.message : `Shop API returned ${response.status}`;
        throw new ShopApiError(message, response.status);
      }
      return body;
    } catch (error) {
      lastError = error;
      if (error instanceof ShopApiError || attempt === attempts) throw error;
    } finally {
      clearTimeout(timeout);
    }
  }
  throw lastError;
}

export async function callTool(tool: string, arguments_: Record<string, unknown>, principal: Principal): Promise<Record<string, unknown>> {
  const retryable = new Set([
    'search_products', 'get_product_detail', 'suggest_size', 'retrieve_knowledge', 'get_categories',
    'get_order_status', 'list_cart', 'list_orders', 'get_order_detail',
  ]);
  const body = await internalCall({
    operation: 'tool.call',
    tool,
    arguments: arguments_,
    user_id: principal.userId,
  }, retryable.has(tool) ? 2 : 1);
  if (!body.result || typeof body.result !== 'object' || Array.isArray(body.result)) {
    throw new ShopApiError('Invalid tool response from shop application', 502);
  }
  return body.result as Record<string, unknown>;
}
