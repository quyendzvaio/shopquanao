import { AIMessage, HumanMessage, ToolMessage } from '@langchain/core/messages';
import { createMiddleware } from 'langchain';
import type { Offered, ToolRecord } from '../contract.js';
import { soldOutCorrection, soldOutNote, type AlternativeFact } from '../copy.js';
import { isAllowedTool } from '../policies/allowList.js';
import { asksTicketConfirmation, lastValidDraft } from '../policies/ticket.js';
import type { TurnBudget } from '../policies/budget.js';
import { withoutNulls, type ShopTools } from '../shopTools.js';
import { answersSoldOut, soldOutFrom, type SoldOutState } from './soldOut.js';
import { textOf } from './text.js';

export const EMPTY_ANSWER_CORRECTION = 'Bạn chưa trả lời khách. Hãy trả lời đủ mọi ý trong câu của khách, gọi công cụ nếu cần.';

export const TICKET_NO_DRAFT_NOTE = 'Bạn vừa hỏi khách có xác nhận gửi yêu cầu hay không nhưng chưa gọi `draft_support_ticket`, nên hệ thống của shop chưa có bản nháp nào. '
  + 'Nếu khách đã nêu đơn hàng và vấn đề, gọi `draft_support_ticket` ngay: hệ thống của shop tự hiện bản nháp và hỏi xác nhận. '
  + 'Không bao giờ hỏi khách xác nhận gửi khi chưa có bản nháp. Nếu còn thiếu thông tin, hỏi khách thông tin đó.';

export const TOOL_REFUSED = 'Công cụ không được phép hoặc đã hết lượt gọi.';

export const REPEATED_TOOL_CALL = 'Công cụ này đã được gọi với đúng các tham số này trong câu hiện tại. Hãy dùng kết quả ở trên, không gọi lại.';

export const NO_MORE_TOOLS = 'Đã hết lượt gọi công cụ cho câu này. Hãy trả lời khách ngay bằng dữ liệu công cụ ở trên, không gọi thêm công cụ.';

/** One key per tool call: the name plus the arguments with nulls dropped and keys sorted at every level. */
function toolCallKey(name: string, args: Record<string, unknown>): string {
  const sorted = (v: unknown): unknown => {
    if (Array.isArray(v)) return v.map(sorted);
    if (typeof v !== 'object' || v === null) return v;
    return Object.fromEntries(Object.keys(v).sort().map((k) => [k, sorted((v as Record<string, unknown>)[k])]));
  };
  return `${name}:${JSON.stringify(sorted(withoutNulls(args)))}`;
}

/** The text of a reply made without tools: a tool call the model writes anyway cannot run, so it is dropped. */
function answerOnly(message: unknown): unknown {
  if (!AIMessage.isInstance(message) || (message.tool_calls?.length ?? 0) === 0) return message;
  return new AIMessage({ content: message.content, id: message.id });
}

/** What the policy middleware observed during one agent run. Only tool results the LLM asked for are kept in `records`. */
export class TurnRecorder {
  private readonly slots: Array<ToolRecord | undefined> = [];
  soldOut: SoldOutState | null = null;
  private soldOutClaimed = false;
  branchToolUsed = false;
  /** The agent loop was sent back to the model once for an incomplete answer. */
  continued = false;
  private readonly called = new Set<string>();

  /** False when the same tool call (name and arguments) already ran this turn; true marks it as running. */
  firstCall(key: string): boolean {
    if (this.called.has(key)) return false;
    this.called.add(key);
    return true;
  }

  /** A failed call may be tried again. */
  forgetCall(key: string): void {
    this.called.delete(key);
  }

  reserve(): number {
    this.slots.push(undefined);
    return this.slots.length - 1;
  }

  fill(index: number, record: ToolRecord): void {
    this.slots[index] = record;
  }

  /** True for the first caller only, so a second sold-out search in the same turn is left alone. */
  claimSoldOut(): boolean {
    if (this.soldOutClaimed) return false;
    this.soldOutClaimed = true;
    return true;
  }

  get records(): ToolRecord[] {
    return this.slots.filter((r): r is ToolRecord => r !== undefined);
  }
}

type Deps = {
  tools: ShopTools;
  budget: TurnBudget;
  recorder: TurnRecorder;
  onProgress?: (stage: string) => void;
};

function toSoldOutState(found: { ids: number[]; search: string }, record: ToolRecord, similar: Record<string, unknown> | null): SoldOutState {
  const sourceId = found.ids[0] ?? 0;
  const candidates: unknown[] = [
    ...(Array.isArray(record.result.products) ? record.result.products : []),
    record.result.product,
  ];
  const own = candidates.find((p): p is Record<string, unknown> => typeof p === 'object' && p !== null && Number((p as Record<string, unknown>).id) === sourceId);
  const fromSimilar = similar && typeof similar.source === 'object' && similar.source !== null
    ? (similar.source as Record<string, unknown>).name
    : undefined;
  const name = typeof fromSimilar === 'string' && fromSimilar !== ''
    ? fromSimilar
    : typeof own?.name === 'string' && own.name !== '' ? own.name : found.search;
  const alternatives: Offered[] = [];
  if (similar && Array.isArray(similar.products)) {
    for (const p of similar.products) {
      if (typeof p !== 'object' || p === null) continue;
      const row = p as Record<string, unknown>;
      const id = Number(row.id);
      if (Number.isInteger(id) && id > 0 && typeof row.name === 'string' && alternatives.length < 2) {
        alternatives.push({ id, name: row.name });
      }
    }
  }
  return { source: { id: sourceId, name }, alternatives, count: found.ids.length, search: found.search, ids: found.ids, similar };
}

/** The offered alternatives with their price from the find_similar_in_stock result, for the model's note. */
function alternativeFacts(so: SoldOutState): AlternativeFact[] {
  const rows = so.similar && Array.isArray(so.similar.products) ? so.similar.products : [];
  return so.alternatives.map((a) => {
    const row = rows.find((p): p is Record<string, unknown> => typeof p === 'object' && p !== null && Number((p as Record<string, unknown>).id) === a.id);
    const price = row !== undefined ? Number(row.price) : NaN;
    return Number.isFinite(price) ? { ...a, price } : { ...a };
  });
}

/** Policy middleware: budget, allow-list, recording, and the fixed sold-out branch (§5.2, §5.2a, §6). */
export function policyMiddleware(deps: Deps) {
  const { tools, budget, recorder, onProgress } = deps;

  return createMiddleware({
    name: 'ShopPolicy',

    // A final answer (no tool calls) that is empty, that misses the sold-out news on a sold-out turn, or that asks the
    // customer to confirm a ticket nobody drafted, sends the loop back to the model once, with its tools, so a part
    // of the question it skipped (or the missing draft) can still call a tool.
    afterModel: {
      canJumpTo: ['model'],
      hook: (state) => {
        const last = state.messages.at(-1);
        if (!last || !AIMessage.isInstance(last) || (last.tool_calls?.length ?? 0) > 0) return undefined;
        if (recorder.continued || budget.expired() || budget.modelCallsLeft() === 0) return undefined;
        const text = textOf(last);
        const so = recorder.soldOut;
        let correction: string | null = null;
        if (so && !answersSoldOut(text, so)) correction = `${soldOutCorrection(so.source.name, so.alternatives)} Gọi thêm công cụ nếu cần cho các ý khác.`;
        else if (text === '') correction = EMPTY_ANSWER_CORRECTION;
        else if (!so && asksTicketConfirmation(text) && lastValidDraft(recorder.records) === null) correction = TICKET_NO_DRAFT_NOTE;
        if (correction === null) return undefined;
        recorder.continued = true;
        return { messages: [new HumanMessage(correction)], jumpTo: 'model' as const };
      },
    },

    // An AI message without tool calls ends the agent loop.
    wrapModelCall: async (request, handler) => {
      if (budget.expired() || !budget.takeModelCall()) return new AIMessage('');
      // The last allowed model call, or one made after the tool budget is spent, could only ask for a tool
      // that would be refused and end in an empty draft: take the tools away, tell the model so, and drop a
      // tool call it writes anyway, so it has to write the answer.
      const call = budget.modelCallsLeft() === 0 || budget.toolCallsLeft() === 0
        ? async () => answerOnly(await handler({
          ...request,
          tools: [],
          toolChoice: undefined,
          messages: [...request.messages, new HumanMessage(NO_MORE_TOOLS)],
        })) as Awaited<ReturnType<typeof handler>>
        : () => handler(request);
      if (!recorder.continued) return call();
      // The continuation step is a second chance, not a requirement: if it fails, the answer so far goes to
      // policy_out and its fallbacks instead of turning the whole turn busy.
      try {
        return await call();
      } catch {
        return new AIMessage('');
      }
    },

    wrapToolCall: async (request) => {
      const { name, args, id } = request.toolCall;
      const reply = (content: string, status?: 'error') => new ToolMessage({ content, tool_call_id: id ?? '', name, status });

      if (!isAllowedTool(name)) return reply(TOOL_REFUSED);
      // The same call twice in one turn returns the same data: answer from the first result instead of
      // spending the budget on a loop.
      const key = toolCallKey(name, args as Record<string, unknown>);
      if (!recorder.firstCall(key)) return reply(REPEATED_TOOL_CALL);
      // Budget slots are taken synchronously so parallel calls in one AIMessage are counted in order.
      if (!budget.takeToolCall()) {
        recorder.forgetCall(key);
        return reply(TOOL_REFUSED);
      }
      const slot = recorder.reserve();

      let data: Record<string, unknown>;
      try {
        data = await tools.call(name, args as Record<string, unknown>);
      } catch {
        recorder.forgetCall(key);
        return reply('Công cụ gặp lỗi, không có dữ liệu.', 'error');
      }

      const record: ToolRecord = { name, args: args as Record<string, unknown>, result: data };
      recorder.fill(slot, record);
      onProgress?.(`tool:${name}`);

      const found = soldOutFrom(record);
      if (!found || !recorder.claimSoldOut()) return reply(JSON.stringify(data));

      // Not counted against the budget and never shown to the model.
      let similar: Record<string, unknown> | null = null;
      try {
        similar = await tools.call('find_similar_in_stock', { product_id: found.ids[0], limit: 2 });
        recorder.branchToolUsed = true;
      } catch {
        similar = null;
      }
      recorder.soldOut = toSoldOutState(found, record, similar);
      return reply(soldOutNote(recorder.soldOut.source.name, alternativeFacts(recorder.soldOut)));
    },
  });
}
