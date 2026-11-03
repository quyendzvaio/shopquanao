import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import { ChatOpenAI } from '@langchain/openai';
import type { GraphConfig } from './config.js';

/** An OpenAI-compatible chat model (DeepSeek by default). No retries: the turn deadline owns the time budget. */
export function createChatModel(c: GraphConfig['llm']): BaseChatModel {
  return new ChatOpenAI({
    model: c.model,
    apiKey: c.apiKey,
    temperature: 0,
    timeout: c.timeoutMs,
    maxRetries: 0,
    configuration: { baseURL: c.baseUrl },
  });
}
