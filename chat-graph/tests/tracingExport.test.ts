// Own file = own process: the OTel tracer provider is registered globally, once.
import assert from 'node:assert/strict';
import test from 'node:test';
import { CallbackHandler } from '@langfuse/langchain';
import { AIMessage, HumanMessage, SystemMessage } from '@langchain/core/messages';
import { tool } from '@langchain/core/tools';
import { ExportResultCode } from '@opentelemetry/core';
import type { ReadableSpan, SpanExporter } from '@opentelemetry/sdk-trace-base';
import { NodeTracerProvider } from '@opentelemetry/sdk-trace-node';
import { z } from 'zod/v4';
import { RedactingSpanProcessor } from '../src/tracing.js';
import { ScriptedChatModel } from './support/scriptedModel.js';

const SECRET = 'SECRET-Lan-12-Nguyen-Trai';

class CapturingExporter implements SpanExporter {
  readonly spans: ReadableSpan[] = [];
  export(spans: ReadableSpan[], done: (r: { code: ExportResultCode }) => void): void {
    this.spans.push(...spans);
    done({ code: ExportResultCode.SUCCESS });
  }
  async shutdown(): Promise<void> {}
}

const exporter = new CapturingExporter();
const processor = new RedactingSpanProcessor({ publicKey: 'pk', secretKey: 'sk', exporter, flushAt: 1 }, false);
new NodeTracerProvider({ spanProcessors: [processor] }).register();

/** Everything an exporter would ship for a span, as one string. */
const everything = (s: ReadableSpan): string => JSON.stringify({ name: s.name, attributes: s.attributes, status: s.status, events: s.events });

test('no customer text in ANY exported attribute, including error messages', async () => {
  const handler = new CallbackHandler();
  const failingTool = tool(
    async (args: { search: string }) => { throw new Error(`boom search=${args.search}`); },
    { name: 'search_products', description: 'fails', schema: z.object({ search: z.string() }) },
  );
  const okModel = new ScriptedChatModel([new AIMessage(`Chào ${SECRET}`)]);
  const failingModel = new ScriptedChatModel([new Error(`upstream echoed ${SECRET}`)]);
  const messages = [new SystemMessage(`memory: ${SECRET}`), new HumanMessage(`tôi là ${SECRET}`)];

  await okModel.invoke(messages, { callbacks: [handler] });
  await failingModel.invoke(messages, { callbacks: [handler] }).catch(() => {});
  await failingTool.invoke({ search: SECRET }, { callbacks: [handler] }).catch(() => {});
  await processor.forceFlush();

  assert.ok(exporter.spans.length >= 3, `spans exported: ${exporter.spans.length}`);
  const statusMessages = exporter.spans.map((s) => s.attributes['langfuse.observation.status_message']).filter((v) => v !== undefined);
  assert.ok(statusMessages.length >= 2, 'the error spans carry a status message');
  for (const span of exporter.spans) {
    assert.ok(!everything(span).includes(SECRET), `leak in span ${span.name}: ${everything(span)}`);
    assert.ok(!everything(span).includes('boom'), `error text leaked in span ${span.name}`);
    for (const [key, value] of Object.entries(span.attributes)) {
      assert.ok(typeof value !== 'object' || value === null || Array.isArray(value), `non-primitive attribute ${key}`);
    }
  }
});
