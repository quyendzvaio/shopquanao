import { CallbackHandler } from '@langfuse/langchain';
import { LangfuseSpanProcessor } from '@langfuse/otel';
import { NodeTracerProvider } from '@opentelemetry/sdk-trace-node';
import type { GraphConfig } from './config.js';

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
