// Control for tracingExport.test.ts (own process: one global tracer provider per file).
// With LANGFUSE_TRACE_CONTENT on, the same probe must reach the exporter, so the redaction test can really see leaks.
import assert from 'node:assert/strict';
import test from 'node:test';
import { CallbackHandler } from '@langfuse/langchain';
import { tool } from '@langchain/core/tools';
import { ExportResultCode } from '@opentelemetry/core';
import type { ReadableSpan, SpanExporter } from '@opentelemetry/sdk-trace-base';
import { NodeTracerProvider } from '@opentelemetry/sdk-trace-node';
import { z } from 'zod/v4';
import { RedactingSpanProcessor } from '../src/tracing.js';

const SECRET = 'SECRET-Lan-12-Nguyen-Trai';
const spans: ReadableSpan[] = [];
const exporter: SpanExporter = {
  export(batch, done) {
    spans.push(...batch);
    done({ code: ExportResultCode.SUCCESS });
  },
  async shutdown() {},
};
const processor = new RedactingSpanProcessor({ publicKey: 'pk', secretKey: 'sk', exporter, flushAt: 1 }, true);
new NodeTracerProvider({ spanProcessors: [processor] }).register();

test('content tracing on: input and error text are exported', async () => {
  const failingTool = tool(
    async (args: { search: string }) => { throw new Error(`boom search=${args.search}`); },
    { name: 'search_products', description: 'fails', schema: z.object({ search: z.string() }) },
  );
  await failingTool.invoke({ search: SECRET }, { callbacks: [new CallbackHandler()] }).catch(() => {});
  await processor.forceFlush();
  const status = spans.map((s) => String(s.attributes['langfuse.observation.status_message'] ?? ''));
  assert.ok(status.some((m) => m.includes(SECRET)), `status messages: ${status.join('|')}`);
  assert.ok(spans.some((s) => JSON.stringify(s.attributes).includes(SECRET)));
});
