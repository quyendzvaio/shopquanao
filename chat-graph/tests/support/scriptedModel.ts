import { AIMessage, AIMessageChunk, type BaseMessage } from '@langchain/core/messages';
import { BaseChatModel } from '@langchain/core/language_models/chat_models';
import type { CallbackManagerForLLMRun } from '@langchain/core/callbacks/manager';
import { ChatGenerationChunk, type ChatResult } from '@langchain/core/outputs';

/** `hangAfter`: streams this text, then waits until the call is aborted (a model that stalls mid-answer). */
export type ScriptedStep = AIMessage | Error | 'hang' | { hangAfter: string };

const waitForAbort = (signal: AbortSignal | undefined): Promise<never> => new Promise<never>((_, reject) => {
  signal?.addEventListener('abort', () => reject(new Error('aborted')), { once: true });
});

/**
 * Deterministic chat model for tests: replays `steps` and records every call's messages. When a callback handler
 * prefers streaming (the answer streamer), calls go through `_streamResponseChunks`, which yields the content word by
 * word and then the tool calls as `tool_call_chunks`, like the real provider.
 */
export class ScriptedChatModel extends BaseChatModel {
  readonly calls: BaseMessage[][] = [];
  /** How many tools were bound for each call (0 when the call was made without bindTools). */
  readonly toolCounts: number[] = [];
  /** Model calls currently running (started, not yet returned or thrown). */
  inFlight = 0;
  private bound = 0;
  private index = 0;

  constructor(private readonly steps: ScriptedStep[]) {
    super({});
  }

  _llmType(): string {
    return 'scripted';
  }

  bindTools(tools: readonly unknown[] = []): this {
    this.bound = tools.length;
    return this;
  }

  private next(messages: BaseMessage[]): ScriptedStep {
    this.calls.push(messages);
    this.toolCounts.push(this.bound);
    this.bound = 0;
    const step = this.steps[this.index++];
    if (step === undefined) throw new Error('ScriptedChatModel: no more steps');
    return step;
  }

  async _generate(messages: BaseMessage[], options: this['ParsedCallOptions']): Promise<ChatResult> {
    this.inFlight += 1;
    try {
      const step = this.next(messages);
      if (step === 'hang' || (typeof step === 'object' && 'hangAfter' in step)) await waitForAbort(options.signal);
      if (step instanceof Error) throw step;
      const message = step as AIMessage;
      return { generations: [{ text: typeof message.content === 'string' ? message.content : '', message }] };
    } finally {
      this.inFlight -= 1;
    }
  }

  async *_streamResponseChunks(messages: BaseMessage[], options: this['ParsedCallOptions'], runManager?: CallbackManagerForLLMRun): AsyncGenerator<ChatGenerationChunk> {
    this.inFlight += 1;
    try {
      const step = this.next(messages);
      if (step === 'hang') await waitForAbort(options.signal);
      if (step instanceof Error) throw step;
      const isHang = typeof step === 'object' && 'hangAfter' in step;
      const content = isHang ? step.hangAfter : typeof (step as AIMessage).content === 'string' ? (step as AIMessage).content as string : '';
      for (const piece of content.match(/\S+\s*|\s+/gu) ?? []) {
        const chunk = new ChatGenerationChunk({ text: piece, message: new AIMessageChunk({ content: piece }) });
        yield chunk;
        await runManager?.handleLLMNewToken(piece, undefined, undefined, undefined, undefined, { chunk });
      }
      if (isHang) await waitForAbort(options.signal);
      const toolCalls = (step as AIMessage).tool_calls ?? [];
      if (toolCalls.length === 0 && content === '') {
        // An empty answer is still one (empty) chunk: the base model rejects a stream with none.
        yield new ChatGenerationChunk({ text: '', message: new AIMessageChunk({ content: '' }) });
      } else if (toolCalls.length > 0) {
        const chunk = new ChatGenerationChunk({
          text: '',
          message: new AIMessageChunk({
            content: '',
            tool_call_chunks: toolCalls.map((c, index) => ({ name: c.name, args: JSON.stringify(c.args), id: c.id, index, type: 'tool_call_chunk' as const })),
          }),
        });
        yield chunk;
        await runManager?.handleLLMNewToken('', undefined, undefined, undefined, undefined, { chunk });
      }
    } finally {
      this.inFlight -= 1;
    }
  }
}
