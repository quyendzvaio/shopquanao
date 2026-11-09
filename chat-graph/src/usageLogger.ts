import { BaseCallbackHandler } from '@langchain/core/callbacks/base';
import type { Serialized } from '@langchain/core/load/serializable';
import type { BaseMessage } from '@langchain/core/messages';
import type { LLMResult } from '@langchain/core/outputs';

type Usage = { prompt_tokens?: number; completion_tokens?: number; total_tokens?: number };

/** The tokens and seconds one model call took, read at handleLLMEnd. */
export type ModelCallStat = {
  prompt_tokens: number;
  completion_tokens: number;
  total_tokens: number;
  ms: number;
  /** The prompt size in characters, for latency analysis without token fields. */
  prompt_chars: number;
};

function usageOf(output: LLMResult): Usage {
  const raw = (output.llmOutput ?? {}) as Usage;
  if (typeof raw.prompt_tokens === 'number') return raw;
  const gen = output.generations[0]?.[0] as { message?: { usage_metadata?: Usage } } | undefined;
  const meta = gen?.message?.usage_metadata;
  return meta ?? raw;
}

/**
 * Collects per-model-call token usage and latency for a turn (prompt-caching probe, plan 1.3).
 * One instance per turn; `stats()` returns a copy so mid-turn reads stay stable.
 */
export class UsageLogger extends BaseCallbackHandler {
  name = 'usage_logger';
  override awaitHandlers = true;
  private readonly calls: ModelCallStat[] = [];
  private readonly started = new Map<string, { at: number; chars: number }>();

  override handleLLMStart(_llm: Serialized, prompts: string[], runId: string): void {
    const chars = prompts.join('').length;
    this.started.set(runId, { at: Date.now(), chars });
  }

  override handleChatModelStart(_llm: Serialized, messages: BaseMessage[][], runId: string): void {
    let chars = 0;
    for (const list of messages) {
      for (const m of list ?? []) {
        chars += typeof m.content === 'string' ? m.content.length : 0;
      }
    }
    this.started.set(runId, { at: Date.now(), chars });
  }

  override handleLLMEnd(output: LLMResult, runId: string): void {
    const start = this.started.get(runId);
    this.started.delete(runId);
    const usage = usageOf(output);
    this.calls.push({
      prompt_tokens: usage.prompt_tokens ?? 0,
      completion_tokens: usage.completion_tokens ?? 0,
      total_tokens: usage.total_tokens ?? 0,
      ms: start === undefined ? 0 : Date.now() - start.at,
      prompt_chars: start?.chars ?? 0,
    });
  }

  override handleLLMError(_err: unknown, runId: string): void {
    this.started.delete(runId);
  }

  get stats(): ModelCallStat[] {
    return [...this.calls];
  }

  /** One line per model call: prompt/completion tokens, ms and prompt chars, plus a turn total. */
  log(): void {
    if (this.calls.length === 0) return;
    let prompt = 0;
    let completion = 0;
    let ms = 0;
    for (const [i, c] of this.calls.entries()) {
      prompt += c.prompt_tokens;
      completion += c.completion_tokens;
      ms += c.ms;
      console.log(`[chat-graph] llm_call ${i + 1}/${this.calls.length}: prompt_tokens=${c.prompt_tokens} completion_tokens=${c.completion_tokens} ms=${c.ms} prompt_chars=${c.prompt_chars}`);
    }
    console.log(`[chat-graph] llm_total: calls=${this.calls.length} prompt_tokens=${prompt} completion_tokens=${completion} ms=${ms}`);
  }
}
