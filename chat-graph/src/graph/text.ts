import type { BaseMessage } from '@langchain/core/messages';

/** The plain text of a message, trimmed (string content or the text parts of a content array). */
export function textOf(message: BaseMessage | undefined): string {
  if (!message) return '';
  const content = message.content;
  if (typeof content === 'string') return content.trim();
  return content
    .map((part) => (typeof part === 'object' && part !== null && 'text' in part && typeof part.text === 'string' ? part.text : ''))
    .join('')
    .trim();
}
