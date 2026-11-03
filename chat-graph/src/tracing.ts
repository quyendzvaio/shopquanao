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

const REDACTED = '[redacted]';
const STATUS_MESSAGE = 'langfuse.observation.status_message';

/** Replaces any input/output/metadata payload with a size-only JSON string (the SDK expects strings). */
export function redactContent(data: unknown): string {
  let chars = 0;
  try {
    chars = typeof data === 'string' ? data.length : (JSON.stringify(data) ?? '').length;
  } catch {
    chars = 0;
  }
  return JSON.stringify({ redacted: true, chars });
}

/** The span-processor mask: redacts unless content tracing is on (then none, so payloads pass through). */
export function langfuseMask(traceContent: boolean): ((p: { data: unknown }) => unknown) | undefined {
  return traceContent ? undefined : ({ data }) => redactContent(data);
}

type MutableSpan = { attributes: Record<string, unknown>; status: { message?: string }; events: Array<{ attributes?: Record<string, unknown> }> };

/**
 * The mask only covers input/output/metadata. Error text can echo tool arguments or customer text
 * (the handler stores `err.toString()` in `status_message`), so without content tracing it is scrubbed too,
 * together with the OTel status message and any exception events.
 */
export class RedactingSpanProcessor extends LangfuseSpanProcessor {
  constructor(params: ConstructorParameters<typeof LangfuseSpanProcessor>[0], private readonly traceContent: boolean) {
    super({ ...params, mask: langfuseMask(traceContent) });
  }

  override onEnd(span: Parameters<LangfuseSpanProcessor['onEnd']>[0]): void {
    if (!this.traceContent) {
      const s = span as unknown as MutableSpan;
      if (STATUS_MESSAGE in s.attributes) s.attributes[STATUS_MESSAGE] = REDACTED;
      if (s.status.message) s.status.message = REDACTED;
      for (const event of s.events) {
        if (event.attributes) for (const key of Object.keys(event.attributes)) event.attributes[key] = REDACTED;
      }
    }
    super.onEnd(span);
  }
}

let processor: RedactingSpanProcessor | null = null;

/** Langfuse callback handlers for a turn: `[]` unless enabled with both keys; a setup failure logs once and returns `[]`. */
export function langfuseCallbacks(c: GraphConfig['langfuse']): unknown[] {
  if (!c.enabled || c.publicKey === '' || c.secretKey === '') return [];
  try {
    if (processor === null) {
      const created = new RedactingSpanProcessor({
        publicKey: c.publicKey,
        secretKey: c.secretKey,
        ...(c.baseUrl !== '' ? { baseUrl: c.baseUrl } : {}),
      }, c.traceContent);
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
