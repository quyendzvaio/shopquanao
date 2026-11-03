/**
 * Port of src/harness/intent/CheckoutDetector.php (same alternatives, same order).
 * PHP `/u` makes `\b` Unicode aware but JavaScript's `\b` is ASCII only, so the trailing
 * `\b` after "giỏ" is a Unicode lookahead: no letter, digit or underscore may follow.
 */
const NOT_WORD_AFTER = '(?![\\p{L}\\p{N}_])';

const CHECKOUT = new RegExp(
  'thêm vào giỏ|them vao gio'
  + `|(?:thêm|them)\\s+.{1,80}?\\s+(?:vào|vao)\\s+(?:giỏ|gio)${NOT_WORD_AFTER}`
  + '|checkout|thanh toán giúp|thanh toan giup|mua .*giúp|mua .*giup'
  + '|đặt hàng giúp|dat hang giup|chốt đơn|chot don',
  'iu',
);

export function matchesCheckout(message: string): boolean {
  return CHECKOUT.test(message.toLowerCase());
}
