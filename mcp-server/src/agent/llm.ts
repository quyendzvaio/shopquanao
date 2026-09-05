import { UnderstandingSchema, type Understanding } from './schemas.js';

type ChatMessage = { role: 'system' | 'user' | 'assistant'; content: string };

function env(name: string, fallback = ''): string {
  return process.env[name]?.trim() || fallback;
}

export async function completeJson(messages: ChatMessage[]): Promise<unknown> {
  const apiKey = env('LLM_API_KEY');
  if (!apiKey) throw new Error('LLM_API_KEY is required by the LangGraph orchestrator');
  const baseUrl = env('LLM_BASE_URL', 'https://api.deepseek.com').replace(/\/$/, '');
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), Number(env('LLM_TIMEOUT', '25')) * 1000);
  try {
    const response = await fetch(`${baseUrl}/chat/completions`, {
      method: 'POST',
      headers: { authorization: `Bearer ${apiKey}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        model: env('LLM_MODEL', 'deepseek-chat'), messages, temperature: 0,
        response_format: { type: 'json_object' },
      }),
      signal: controller.signal,
    });
    const rawBody = await response.text();
    const body = parseFirstJsonObject(rawBody) as { choices?: Array<{ message?: { content?: string } }>; error?: { message?: string } };
    if (!response.ok) throw new Error(body.error?.message || `LLM returned ${response.status}`);
    const content = body.choices?.[0]?.message?.content?.trim();
    if (!content) throw new Error('LLM returned empty structured output');
    return parseFirstJsonObject(content);
  } finally {
    clearTimeout(timeout);
  }
}

export function parseFirstJsonObject(content: string): unknown {
  let start = -1;
  let depth = 0;
  let inString = false;
  let escaped = false;
  for (let index = 0; index < content.length; index += 1) {
    const char = content[index];
    if (start < 0) {
      if (char === '{') {
        start = index;
        depth = 1;
      }
      continue;
    }
    if (escaped) {
      escaped = false;
      continue;
    }
    if (char === '\\') {
      escaped = inString;
      continue;
    }
    if (char === '"') {
      inString = !inString;
      continue;
    }
    if (inString) continue;
    if (char === '{') depth += 1;
    if (char === '}') depth -= 1;
    if (depth === 0) {
      return JSON.parse(content.slice(start, index + 1));
    }
  }
  throw new Error('LLM output is not a JSON object');
}

export async function understand(message: string, context: Record<string, unknown>): Promise<Understanding> {
  const output = await completeJson([
    {
      role: 'system',
      content: `Bạn là bộ hiểu ngôn ngữ cho shop thời trang. Trả về duy nhất JSON đúng schema được mô tả.
Không dùng đối chiếu từ khóa; suy luận toàn bộ ý nghĩa, tham chiếu và ngữ cảnh hội thoại.
Intent hợp lệ: product_search, product_detail, size_advice, return_exchange, shipping, policy,
order_status, list_cart, list_orders, suggest_complementary_products, occasion_styling,
add_to_cart, update_cart, remove_from_cart, create_order, unknown.
Phân biệt yêu cầu phối với một món cụ thể (suggest_complementary_products) và phối theo dịp (occasion_styling).
Chỉ đặt explicit_confirmation=true khi chính tin nhắn hiện tại xác nhận rõ thao tác thay đổi giỏ/đặt hàng.
Đặt is_hypothetical=true nếu số đo hoặc yêu cầu chỉ là giả định. stable_preferences chỉ chứa sở thích người dùng nói rõ là sở thích lâu dài.
Schema: {primary_intent,secondary_intents,confidence,entities:{product_id,product_query,category_id,color,size,height_cm,weight_kg,min_price,max_price,occasion,order_id,cart_id,quantity},requested_fields,missing_slots,refers_to_active_product,is_hypothetical,explicit_confirmation,stable_preferences}. Dùng null khi không có giá trị.`,
    },
    { role: 'user', content: JSON.stringify({ message, context }) },
  ]);
  return UnderstandingSchema.parse(output);
}
