/** nginx allows 30 r/m on /api/chatbot (burst 6); the eval never goes faster than this. */
export const MIN_PACE_MS = 2300;

/** `--pace-ms=N`: slows the run for a rate-limited LLM tier; effective pace = max(MIN_PACE_MS, N). */
export function paceMs(argv: string[]): number {
  const prefix = '--pace-ms=';
  const raw = argv.find((a) => a.startsWith(prefix))?.slice(prefix.length);
  if (raw === undefined) return MIN_PACE_MS;
  const value = Number(raw);
  if (raw.trim() === '' || !Number.isFinite(value) || value < 0) {
    throw new Error(`--pace-ms must be a non-negative number of milliseconds, got "${raw}"`);
  }
  return Math.max(MIN_PACE_MS, value);
}
