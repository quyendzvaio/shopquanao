import { CallbackHandler } from '@langfuse/langchain';
import { LangfuseSpanProcessor } from '@langfuse/otel';
import { NodeTracerProvider } from '@opentelemetry/sdk-trace-node';
import type { GraphConfig } from './config.js';

// Version pins: @opentelemetry/api stays at 1.9.x because @opentelemetry/core 2.x peers `api <1.10`,
// while OTLP exporters >= 0.300 import `SeverityNumber` from api 1.10 and crash on import. The exporter
// packages are held at 0.223.0, the newest line that still works with api 1.9.x and clears the
// OTel advisories. Revisit when @langfuse/otel peers move to the api 1.10 line.

// Content gate: like the PHP outbox, prompts, history, customer messages, tool args/results and
// answers stay out of Langfuse unless LANGFUSE_TRACE_CONTENT=true. Only the size is exported.

/** Replaces any input/output/metadata payload with a size-only marker. */
export function redactContent(data: unknown): { redacted: true; chars: number } {
  let chars = 0;
  try {
    chars = typeof data === 'string' ? data.length : (JSON.stringify(data) ?? '').length;
  } catch {
    chars = 0;
  }
  return { redacted: true, chars };
}

/** The span-processor mask: redacts unless content tracing is on (then none, so payloads pass through). */
export function langfuseMask(traceContent: boolean): ((p: { data: unknown }) => unknown) | undefined {
  return traceContent ? undefined : ({ data }) => redactContent(data);
}

let processor: LangfuseSpanProcessor | null = null;

/** Langfuse callback handlers for a turn: `[]` unless enabled with both keys; a setup failure logs once and returns `[]`. */
export function langfuseCallbacks(c: GraphConfig['langfuse']): unknown[] {
  if (!c.enabled || c.publicKey === '' || c.secretKey === '') return [];
  try {
    if (processor === null) {
      const created = new LangfuseSpanProcessor({
        publicKey: c.publicKey,
        secretKey: c.secretKey,
        ...(c.baseUrl !== '' ? { baseUrl: c.baseUrl } : {}),
        mask: langfuseMask(c.traceContent),
      });
      new NodeTracerProvider({ spanProcessors: [created] }).register();
      processor = created;
    }
    return [new CallbackHandler()];
  } catch (err) {
    console.error('[chat-graph] Langfuse tracing disabled:', err instanceof Error ? err.message : String(err));
    processor = null;
    return [];
  }
}

/** Flushes pending Langfuse spans (call before exit). */
export async function flushTracing(): Promise<void> {
  try {
    await processor?.forceFlush();
  } catch {
    // Tracing must never block shutdown.
  }
}
