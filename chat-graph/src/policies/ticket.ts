import type { PendingTicket, ToolRecord } from '../contract.js';

/** The last `draft_support_ticket` result of the turn that is a valid draft; refused drafts never count. */
export function lastValidDraft(records: ToolRecord[]): PendingTicket | null {
  for (let i = records.length - 1; i >= 0; i--) {
    const r = records[i];
    if (!r || r.name !== 'draft_support_ticket' || r.result.status !== 'draft') continue;
    const d = r.result.draft;
    if (typeof d !== 'object' || d === null) continue;
    const { type, order_id: orderId, summary } = d as Record<string, unknown>;
    if (typeof type !== 'string' || typeof summary !== 'string' || summary.trim() === '') continue;
    const order = orderId === null || orderId === undefined ? null : Number(orderId);
    if (order !== null && !(Number.isInteger(order) && order > 0)) continue;
    return { type, order_id: order, summary };
  }
  return null;
}

/**
 * A sentence that ends in "?": the text after the previous `.`, `!`, `?` or line break, up to the question mark.
 * A dot between digits ("85.000đ") is a thousands separator, not a sentence end.
 */
const QUESTION = /(?:[^.!?\n]|(?<=\d)\.(?=\d))*\?+/gu;

/** Confirm/agree + send/create + a ticket object: "đồng ý tạo outfit" or "xác nhận gửi hàng về..." is not a ticket. */
function isTicketConfirmation(sentence: string): boolean {
  const s = sentence.normalize('NFC').toLowerCase();
  return (s.includes('xác nhận') || s.includes('đồng ý'))
    && (s.includes('gửi') || s.includes('tạo'))
    && /yêu cầu|ticket|phiếu|cho shop/u.test(s);
}

/**
 * True when the text asks the customer to confirm or agree to sending / creating something ("Bạn có đồng ý để mình
 * gửi yêu cầu ... không?"). Only the shop system may ask that, and only after a real `draft_support_ticket`; an offer
 * such as "Bạn có muốn mình tạo yêu cầu ... không?" does not match.
 */
export function asksTicketConfirmation(text: string): boolean {
  return (text.match(QUESTION) ?? []).some(isTicketConfirmation);
}

/** The text without each question sentence for which `drop` is true, spaces and line breaks tidied. */
function dropQuestions(text: string, drop: (sentence: string) => boolean): string {
  return text
    .replace(QUESTION, (sentence) => (drop(sentence) ? '' : sentence))
    .replace(/[ \t]{2,}/gu, ' ')
    .replace(/[ \t]*\n[ \t]*/gu, '\n')
    .replace(/\n{3,}/gu, '\n\n')
    .trim();
}

/** The text without each question sentence that asks for a ticket confirmation. */
export function dropTicketConfirmations(text: string): string {
  return dropQuestions(text, isTicketConfirmation);
}

/**
 * The model's lead-in on a draft turn without any question: the fixed confirmation question that follows must be the
 * only one, and a model asks its own in words the confirmation check does not know ("Bạn có đồng ý với nội dung trên
 * không?"), so the customer was asked twice.
 */
export function draftLeadIn(text: string): string {
  return dropQuestions(text, () => true);
}
