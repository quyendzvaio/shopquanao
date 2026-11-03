import type { Offered } from './contract.js';

export const BUSY = 'Hệ thống đang bận, bạn thử lại sau ít phút nhé.';

/** number_format(v, 0, ',', '.') . 'đ' */
export function money(v: number): string {
  const rounded = Math.round(v);
  const sign = rounded < 0 ? '-' : '';
  const digits = String(Math.abs(rounded)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${sign}${digits}đ`;
}

/** Port of ResponseGenerator::echoQuote. */
function echoQuote(message: string): string {
  let text = message.trim().replace(/\s+/gu, ' ');
  const chars = Array.from(text);
  if (chars.length > 120) text = chars.slice(0, 117).join('') + '…';
  return text === '' ? '' : `"${text}", `;
}

export function checkoutRefusal(message: string): string {
  return 'Với yêu cầu ' + echoQuote(message)
    + 'mình không thể tự thêm giỏ hàng hoặc thanh toán giúp bạn. '
    + 'Bạn vui lòng bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán.';
}

export type AlternativeFact = { id: number; name: string; price?: number };

/** What the model sees instead of the sold-out tool result: the facts, so it writes the whole answer itself (§5.2a). */
export function soldOutNote(sourceName: string, alts: AlternativeFact[]): string {
  if (alts.length === 0) {
    return `Mẫu ${sourceName} đã hết hàng và chưa có mẫu thay thế. `
      + `Trả lời các ý khác của khách trước (nếu có), sau đó báo mẫu ${sourceName} đang hết hàng.`;
  }
  const facts = alts.map((a) => `${a.name} (mã ${a.id})${a.price !== undefined ? ` giá ${money(a.price)}` : ''}`).join('; ');
  const names = alts.map((a) => a.name).join(' hoặc ');
  return `Mẫu ${sourceName} đã hết hàng. Mẫu thay thế còn hàng: ${facts}. `
    + `Trả lời các ý khác của khách trước (nếu có), sau đó báo mẫu ${sourceName} đang hết hàng `
    + `và hỏi khách có muốn xem ${names} không. Viết đúng tên các mẫu, không gợi ý mẫu nào khác.`;
}

/** The retry instruction when an answer on a sold-out turn left out the sold-out item or its alternatives. */
export function soldOutCorrection(sourceName: string, alts: Array<{ name: string }>): string {
  const ask = alts.length > 0 ? ` và hỏi khách có muốn xem ${alts.map((a) => a.name).join(' hoặc ')} không` : '';
  return `Câu trả lời phải báo mẫu ${sourceName} đang hết hàng${ask}, đồng thời vẫn trả lời các ý khác của khách.`;
}

export function askAlternatives(sourceName: string, alts: Offered[], afterOtherAnswer: boolean): string {
  const names = alts.slice(0, 2).map((a) => a.name).join(' hoặc ');
  return `${afterOtherAnswer ? 'Còn mẫu' : 'Mẫu'} ${sourceName} đang hết hàng. Bạn có muốn xem ${names} không?`;
}

export function soldOutNoAlternatives(count: number, search: string): string {
  return `Shop có ${count} mẫu ${search} nhưng hiện đang hết hàng. Bạn thử màu hoặc mẫu khác giúp mình nhé.`;
}

export function nudgeText(gap: number, threshold: number, product: { id: number; name: string; price: number }): string {
  return `Giỏ hàng của bạn còn thiếu ${money(gap)} để được miễn phí ship (đơn từ ${money(threshold)}); `
    + `bạn có thể tham khảo thêm ${product.name} (mã ${product.id}) giá ${money(product.price)}.`;
}

export const PRIMARY_INTENT: Record<string, string> = {
  search_products: 'product_search',
  find_similar_in_stock: 'product_search',
  get_categories: 'product_search',
  get_product_detail: 'product_detail',
  build_outfit: 'build_outfit',
  suggest_freeship_items: 'freeship_gap',
  suggest_size: 'size_advice',
  retrieve_knowledge: 'policy',
  get_order_status: 'order_status',
};
