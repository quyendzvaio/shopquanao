import assert from 'node:assert/strict';
import test from 'node:test';
import { AIMessage, AIMessageChunk } from '@langchain/core/messages';
import { ChatGenerationChunk, type LLMResult } from '@langchain/core/outputs';
import type { ToolRecord } from '../src/contract.js';
import { AnswerStreamer, takeUnits } from '../src/graph/answerStream.js';

const SHIRT: ToolRecord = { name: 'search_products', args: {}, result: { products: [{ id: 66, name: 'Áo Thun', price: 150000, stock: 3 }] } };

function streamer(over: { allowed?: () => boolean; records?: ToolRecord[] } = {}) {
  const emitted: string[] = [];
  const s = new AnswerStreamer({
    emit: (t) => emitted.push(t),
    evidence: () => over.records ?? [SHIRT],
    allowed: over.allowed ?? (() => true),
  });
  return { s, emitted };
}

const fields = (text: string, toolCall = false) => ({
  chunk: new ChatGenerationChunk({
    text,
    message: new AIMessageChunk({
      content: text,
      tool_call_chunks: toolCall ? [{ name: 'search_products', args: '{}', id: 'c1', index: 0, type: 'tool_call_chunk' as const }] : [],
    }),
  }),
});

const end = (toolCalls = false): LLMResult => ({
  generations: [[{
    text: '',
    message: new AIMessage({ content: '', tool_calls: toolCalls ? [{ id: 'c1', name: 'search_products', args: {}, type: 'tool_call' }] : [] }),
  } as never]],
});

/** One model run: start, the given tokens, then end (with or without tool calls). */
async function run(s: AnswerStreamer, runId: string, tokens: Array<string | { tool: true }>, endsWithTools = false, finish = true) {
  await s.handleChatModelStart({} as never, [[]], runId);
  for (const t of tokens) {
    if (typeof t === 'string') await s.handleLLMNewToken(t, { prompt: 0, completion: 0 }, runId, undefined, undefined, fields(t));
    else await s.handleLLMNewToken('', { prompt: 0, completion: 0 }, runId, undefined, undefined, fields('', true));
  }
  if (finish) await s.handleLLMEnd(end(endsWithTools), runId);
}

test('takeUnitsSplitsSentencesAndLines', () => {
  assert.deepEqual(takeUnits('Chào bạn. Shop có áo!\nGiá'), { units: ['Chào bạn. ', 'Shop có áo!\n'], rest: 'Giá' });
});

test('takeUnitsKeepsThousandsSeparator', () => {
  assert.deepEqual(takeUnits('Giá 150.'), { units: [], rest: 'Giá 150.' });
  assert.deepEqual(takeUnits('Giá 150.000đ. Còn'), { units: ['Giá 150.000đ. '], rest: 'Còn' });
});

test('takeUnitsConcatenatesBackToTheBuffer', () => {
  const buffer = 'A? B!  C.\n\nD 1.5k. E';
  const { units, rest } = takeUnits(buffer);
  assert.equal(units.join('') + rest, buffer);
});

test('firstUnitIsHeldUntilTheSecond', async () => {
  const { s, emitted } = streamer();
  await s.handleChatModelStart({} as never, [[]], 'r1');
  await s.handleLLMNewToken('Một. ', { prompt: 0, completion: 0 }, 'r1', undefined, undefined, fields('Một. '));
  assert.deepEqual(emitted, []);
  await s.handleLLMNewToken('Hai. ', { prompt: 0, completion: 0 }, 'r1', undefined, undefined, fields('Hai. '));
  assert.deepEqual(emitted, ['Một.', ' Hai.']);
});

test('leadInBeforeToolCallIsNeverEmitted', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['Để mình ', 'xem. ', { tool: true }], true);
  assert.deepEqual(emitted, []);
  assert.equal(s.stopped, false);
});

test('leadInAfterBlankLinesIsNeverEmitted', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['\n\n', 'Để mình xem. ', { tool: true }], true);
  assert.deepEqual(emitted, []);
});

test('runEndFlushesHeldAndTail', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['Một. ', 'không ', 'chấm']);
  assert.deepEqual(emitted, ['Một.', ' không chấm']);
});

test('ungroundedPriceStopsTheTurn', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['A. ', 'Giá 99.000đ. ', 'C. ']);
  assert.deepEqual(emitted, ['A.']);
  assert.equal(s.stopped, true);
});

test('groundedPriceIsEmitted', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['Áo Thun mã 66 ', 'giá 150.000đ. ', 'Bạn xem nhé.']);
  assert.deepEqual(emitted, ['Áo Thun mã 66 giá 150.000đ.', ' Bạn xem nhé.']);
});

test('ticketConfirmationQuestionStopsTheTurn', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['Ok. ', 'Bạn xác nhận gửi yêu cầu này cho shop không? ', 'Cảm ơn.']);
  assert.ok(!emitted.some((t) => t.includes('xác nhận')));
  assert.equal(s.stopped, true);
});

test('toolCallMarkupStopsTheTurn', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['Ok. ', '<tool_call>search</tool_call>. ', 'Hết.']);
  assert.ok(!emitted.some((t) => t.includes('tool_call')));
  assert.equal(s.stopped, true);
});

test('markdownIsStrippedPerUnit', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['**Áo** đẹp. ', '- *mới* nhé. ']);
  assert.deepEqual(emitted, ['Áo đẹp.', ' - mới nhé.']);
});

test('notAllowedRunEmitsNothing', async () => {
  const { s, emitted } = streamer({ allowed: () => false });
  await run(s, 'r1', ['Một. ', 'Hai. ', 'Ba.']);
  assert.deepEqual(emitted, []);
});

test('stoppedStreamerIgnoresLaterRuns', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['A. ', 'Giá 99.000đ. ']);
  await run(s, 'r2', ['Một. ', 'Hai. ', 'Ba.']);
  assert.deepEqual(emitted, ['A.']);
});

test('erroredRunIsDropped', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['Một. '], false, false);
  await s.handleLLMError(new Error('boom'), 'r1');
  await s.handleLLMEnd(end(), 'r1');
  assert.deepEqual(emitted, []);
});

test('streamNeverStartsOrEndsWithWhitespace', async () => {
  const { s, emitted } = streamer();
  await run(s, 'r1', ['\n\nMột.\n', '\nHai. ', 'Ba.  ']);
  assert.deepEqual(emitted, ['Một.', '\n\nHai.', ' Ba.']);
});

test('streamerPrefersStreamingAndAwaitsHandlers', () => {
  const { s } = streamer();
  assert.equal(s.lc_prefer_streaming, true);
  assert.equal(s.awaitHandlers, true);
});
