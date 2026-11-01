import { AIMessage, type BaseMessage } from '@langchain/core/messages';
import { BaseChatModel } from '@langchain/core/language_models/chat_models';
import type { ChatResult } from '@langchain/core/outputs';

export type ScriptedStep = AIMessage | Error | 'hang';

/** Deterministic chat model for tests: replays `steps` and records every call's messages. */
export class ScriptedChatModel extends BaseChatModel {
  readonly calls: BaseMessage[][] = [];
  private index = 0;

  constructor(private readonly steps: ScriptedStep[]) {
    super({});
  }

  _llmType(): string {
    return 'scripted';
  }

  bindTools(): this {
    return this;
  }

  async _generate(messages: BaseMessage[], options: this['ParsedCallOptions']): Promise<ChatResult> {
    this.calls.push(messages);
    const step = this.steps[this.index++];
    if (step === undefined) throw new Error('ScriptedChatModel: no more steps');
    if (step === 'hang') {
      await new Promise<never>((_, reject) => {
        options.signal?.addEventListener('abort', () => reject(new Error('aborted')), { once: true });
      });
    }
    if (step instanceof Error) throw step;
    const message = step as AIMessage;
    return { generations: [{ text: typeof message.content === 'string' ? message.content : '', message }] };
  }
}
