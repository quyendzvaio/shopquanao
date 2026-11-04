import { BaseCallbackHandler } from '@langchain/core/callbacks/base';
import type { Serialized } from '@langchain/core/load/serializable';
import { AIMessage, AIMessageChunk, type BaseMessage } from '@langchain/core/messages';
import type { LLMResult } from '@langchain/core/outputs';
import type { ToolRecord } from '../contract.js';
import { hasToolCallMarkup, plainText, ungroundedClaims } from '../policies/grounding.js';
import { asksTicketConfirmation } from '../policies/ticket.js';

/**
 * Complete units of `buffer`: a sentence (`.`, `?` or `!` followed by whitespace) or a line (`\n`), each with the
 * whitespace that follows it. `units.join('') + rest === buffer`; `rest` may still grow into a unit.
 */
export function takeUnits(buffer: string): { units: string[]; rest: string } {
  const units: string[] = [];
  let start = 0;
  let i = 0;
  while (i < buffer.length) {
    const ch = buffer[i];
    const ends = ch === '\n' || ((ch === '.' || ch === '?' || ch === '!') && i + 1 < buffer.length && /\s/u.test(buffer[i + 1] ?? ''));
    if (!ends) {
      i += 1;
      continue;
    }
    let stop = i + 1;
    while (stop < buffer.length && /\s/u.test(buffer[stop] ?? '')) stop += 1;
    // Trailing whitespace at the end of the buffer may continue in the next token: the unit still ends here.
    units.push(buffer.slice(start, stop));
    start = stop;
    i = stop;
  }
  return { units, rest: buffer.slice(start) };
}

export type AnswerStreamOptions = {
  emit: (text: string) => void;
  /** Replaces everything streamed so far with `text`: a lead-in already on screen when its run turns into a tool call. */
  replace: (text: string) => void;
  /** The tool results the answer must be grounded in, read when a unit is checked. */
  evidence: () => ToolRecord[];
  /** Read when a model run starts: false keeps that run silent (sold-out or ticket-draft turn, retry outside the agent). */
  allowed: () => boolean;
};

type Snapshot = { total: string; emittedAny: boolean; pendingSpace: string };
type Run = { buffer: string; held: string | null; emitted: number; dead: boolean; before: Snapshot };

/**
 * Streams the final answer of the agent's model runs, sentence by sentence (spec 2026-11-04 §4). Each unit passes the
 * same checks `policy_out` applies to the whole answer before it is emitted; a failed check stops streaming for the
 * rest of the turn, and `policy_out` stays the final word (the turn reconciles with a `replace`).
 * The first unit of a run is held until a second one is complete or the run ends without a tool call, so a lead-in
 * sentence written just before a tool call is never shown; a longer lead-in that did reach the screen is replaced
 * by the text before it as soon as the tool call arrives.
 */
export class AnswerStreamer extends BaseCallbackHandler {
  name = 'answer_streamer';
  lc_prefer_streaming = true;
  override awaitHandlers = true;
  stopped = false;
  private readonly runs = new Map<string, Run>();
  /** Whitespace after the last emitted unit, sent before the next one so the stream never ends in whitespace. */
  private pendingSpace = '';
  private emittedAny = false;
  /** Everything emitted so far, as the widget shows it. */
  private total = '';

  constructor(private readonly options: AnswerStreamOptions) {
    super();
  }

  override handleChatModelStart(_llm: Serialized, _messages: BaseMessage[][], runId: string): void {
    if (this.stopped || !this.options.allowed()) return;
    this.runs.set(runId, { buffer: '', held: null, emitted: 0, dead: false, before: { total: this.total, emittedAny: this.emittedAny, pendingSpace: this.pendingSpace } });
  }

  override handleLLMNewToken(token: string, _idx: unknown, runId: string, _parent?: string, _tags?: string[], fields?: { chunk?: unknown }): void {
    const run = this.runs.get(runId);
    if (!run || run.dead || this.stopped) return;
    // Tool calls are recognized only from the chunk (ChatOpenAI passes it with every token). Without one we could not
    // tell a lead-in from the answer, so the run stays silent and the turn sends its text at the end.
    const message = (fields?.chunk as { message?: unknown } | undefined)?.message;
    if (!AIMessageChunk.isInstance(message)) {
      this.kill(run);
      return;
    }
    if ((message.tool_call_chunks?.length ?? 0) > 0) {
      this.retract(run);
      return;
    }
    run.buffer += token;
    const { units, rest } = takeUnits(run.buffer);
    run.buffer = rest;
    for (const unit of units) {
      // Blank lines before the first sentence do not count as it, and a blank unit after it does not count as the
      // second one: the first sentence stays held until real text follows.
      if (run.emitted === 0 && (run.held === null || run.held.trim() === '' || unit.trim() === '')) {
        run.held = (run.held ?? '') + unit;
        continue;
      }
      if (run.held !== null) {
        const held = run.held;
        run.held = null;
        if (!this.send(run, held)) return;
      }
      if (!this.send(run, unit)) return;
    }
  }

  override handleLLMEnd(output: LLMResult, runId: string): void {
    const run = this.runs.get(runId);
    this.runs.delete(runId);
    if (!run || run.dead || this.stopped) return;
    const message = (output.generations[0]?.[0] as { message?: unknown } | undefined)?.message;
    if (AIMessage.isInstance(message) && (message.tool_calls?.length ?? 0) > 0) {
      this.retract(run);
      return;
    }
    if (run.held !== null && !this.send(run, run.held)) return;
    if (run.buffer !== '') this.send(run, run.buffer);
  }

  override handleLLMError(_err: unknown, runId: string): void {
    this.runs.delete(runId);
  }

  private kill(run: Run): void {
    run.dead = true;
    run.held = null;
    run.buffer = '';
  }

  /** The run turned into a tool call: what it already put on screen is taken back, the rest is dropped. */
  private retract(run: Run): void {
    this.kill(run);
    if (run.emitted === 0) return;
    this.total = run.before.total;
    this.emittedAny = run.before.emittedAny;
    this.pendingSpace = run.before.pendingSpace;
    run.emitted = 0;
    this.options.replace(this.total);
  }

  /** Checks one unit and emits it; false (and the whole turn stopped) when a check fails. */
  private send(run: Run, unit: string): boolean {
    const evidence = this.options.evidence();
    if (hasToolCallMarkup(unit) || ungroundedClaims(unit, evidence).length > 0 || asksTicketConfirmation(unit)) {
      this.stopped = true;
      this.kill(run);
      return false;
    }
    // plainText drops an emphasis pair inside the unit; a pair spanning two units leaves one `*` in each, which the
    // whole-answer plainText would have dropped too.
    const text = plainText(unit)
      .replace(/(^|[^\p{L}\p{N}*])\*(?=\S)/gu, '$1')
      .replace(/(?<=\S)\*(?![\p{L}\p{N}])/gu, '');
    const core = text.trimEnd();
    const space = text.slice(core.length);
    // The final answer is trimmed: nothing before its first character, nothing after its last.
    const lead = this.emittedAny ? this.pendingSpace : '';
    const body = this.emittedAny ? core : core.trimStart();
    if (body === '') {
      if (this.emittedAny) this.pendingSpace += space;
      return true;
    }
    this.options.emit(lead + body);
    this.total += lead + body;
    this.emittedAny = true;
    this.pendingSpace = space;
    run.emitted += 1;
    return true;
  }
}
