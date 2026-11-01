/**
 * Manual live eval: real LLM, full stack (nginx -> app -> chat-graph), not part of CI.
 *
 *   npm --prefix chat-graph run eval [-- --cases=eval/cases.jsonl] [--only=S01,O02] [--group=guardrail] [--pace-ms=30000] [--output=reports/eval/chat_graph_eval.json]
 *
 * Users and carts are created through PHP (fixtures.php, run inside the app container); graph code runs no SQL.
 * Exit code 0 iff rate >= 0.9.
 */
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { paceMs } from './args.js';
import { parseCases, type EvalCase } from './cases.js';
import { scoreTurn, summarize, type TurnObservation } from './scorer.js';

const THRESHOLD = 0.9;
const MAX_429_RETRIES = 6;
const REQUEST_TIMEOUT_MS = 150_000;

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');

function arg(name: string): string | undefined {
  const prefix = `--${name}=`;
  return process.argv.find((a) => a.startsWith(prefix))?.slice(prefix.length);
}

const PACE_MS = paceMs(process.argv); // >= 2300 (nginx 30 r/m); raise it for a 10 req/min LLM tier

const baseUrl = process.env.EVAL_BASE_URL ?? `http://localhost:${process.env.NGINX_HTTP_PORT ?? '80'}`;

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/** Runs fixtures.php in the app container: `php /dev/stdin '<json>'` with the script on stdin. */
function fixture(request: Record<string, unknown>): Promise<Record<string, unknown>> {
  const script = readFileSync(path.join(here, 'fixtures.php'));
  return new Promise((resolve, reject) => {
    const child = spawn('docker', ['compose', 'exec', '-T', 'app', 'php', '/dev/stdin', JSON.stringify(request)], { cwd: repoRoot });
    let out = '';
    let err = '';
    child.stdout.on('data', (d: Buffer) => { out += d.toString(); });
    child.stderr.on('data', (d: Buffer) => { err += d.toString(); });
    child.on('error', reject);
    child.on('close', (code) => {
      if (code !== 0) return reject(new Error(`fixtures exit ${code}: ${err.trim() || out.trim()}`));
      try {
        resolve(JSON.parse(out) as Record<string, unknown>);
      } catch {
        reject(new Error(`fixtures returned non-JSON: ${out.slice(0, 200)}`));
      }
    });
    child.stdin.end(script);
  });
}

let lastRequestAt = 0;

async function chat(message: string, sessionToken: string, bearer: string | null): Promise<Record<string, unknown>> {
  for (let attempt = 0; ; attempt++) {
    const wait = lastRequestAt + PACE_MS - Date.now();
    if (wait > 0) await sleep(wait);
    lastRequestAt = Date.now();
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (bearer) headers.Authorization = `Bearer ${bearer}`;
    const res = await fetch(`${baseUrl}/api/chatbot`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ message, session_token: sessionToken }),
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    });
    if (res.status === 429 && attempt < MAX_429_RETRIES) {
      await sleep(5000 * (attempt + 1));
      continue;
    }
    if (!res.ok) throw new Error(`HTTP ${res.status} from /api/chatbot`);
    return (await res.json()) as Record<string, unknown>;
  }
}

function observe(message: string, body: Record<string, unknown>): TurnObservation {
  const products = Array.isArray(body.products) ? (body.products as Array<{ id?: unknown }>) : [];
  const offered = Array.isArray(body.offered) ? (body.offered as Array<{ id?: unknown }>) : [];
  const ids = (rows: Array<{ id?: unknown }>) => rows.map((r) => Number(r.id)).filter((n) => Number.isFinite(n));
  return {
    message,
    answer: String(body.answer ?? body.message ?? ''),
    response_type: String(body.response_type ?? ''),
    product_ids: ids(products),
    tools: Array.isArray(body.tools_used) ? (body.tools_used as unknown[]).map(String) : [],
    offered_ids: ids(offered),
    nudge: body.freeship_nudge === true,
  };
}

async function runCase(c: EvalCase, runId: string, slot: number, createdUsers: number[]) {
  const failures: string[] = [];
  const turns: Array<Omit<TurnObservation, 'nudge' | 'offered_ids'> & { offered_ids: number[]; nudge: boolean }> = [];
  let bearer: string | null = null;
  try {
    if (c.user) {
      const made = await fixture({ action: 'prepare', name: `eval_user_${runId}_${slot}`, cart: c.cart ?? [] });
      createdUsers.push(Number(made.user_id));
      bearer = String(made.token);
    }
    let sessionToken = '';
    for (const [index, turn] of c.turns.entries()) {
      const body = await chat(turn.message, sessionToken, bearer);
      sessionToken = typeof body.session_token === 'string' ? body.session_token : sessionToken;
      const obs = observe(turn.message, body);
      turns.push(obs);
      for (const f of scoreTurn(turn.expect, obs)) failures.push(`turn ${index + 1}: ${f}`);
    }
  } catch (error) {
    failures.push(`error: ${error instanceof Error ? error.message : String(error)}`);
  }
  return {
    id: c.id,
    group: c.group,
    failures,
    turns: turns.map((t) => ({
      message: t.message, answer: t.answer, response_type: t.response_type,
      product_ids: t.product_ids, tools: t.tools, offered_ids: t.offered_ids, nudge: t.nudge,
    })),
  };
}

async function main(): Promise<number> {
  const casesPath = path.resolve(process.cwd(), arg('cases') ?? path.join(here, 'cases.jsonl'));
  const outputPath = path.resolve(repoRoot, arg('output') ?? 'reports/eval/chat_graph_eval.json');
  const only = arg('only')?.split(',').filter(Boolean);
  const group = arg('group');
  let cases = parseCases(readFileSync(casesPath, 'utf8'));
  if (only) cases = cases.filter((c) => only.includes(c.id));
  if (group) cases = cases.filter((c) => c.group === group);
  if (cases.length === 0) throw new Error('no cases selected');

  const runId = Date.now().toString(36);
  const createdUsers: number[] = [];
  const results = [];
  try {
    for (const [i, c] of cases.entries()) {
      const result = await runCase(c, runId, i + 1, createdUsers);
      results.push(result);
      console.log(`${result.failures.length === 0 ? 'PASS' : 'FAIL'} ${c.id} (${c.group})${result.failures.length ? ` - ${result.failures.join('; ')}` : ''}`);
    }
  } finally {
    if (createdUsers.length > 0) {
      try {
        await fixture({ action: 'cleanup', user_ids: createdUsers });
      } catch (error) {
        console.error(`cart cleanup failed: ${error instanceof Error ? error.message : String(error)}`);
      }
    }
  }

  const summary = summarize(results);
  mkdirSync(path.dirname(outputPath), { recursive: true });
  writeFileSync(outputPath, JSON.stringify({ ...summary, cases: results }, null, 2));
  console.log(`\n${summary.passed}/${results.length} passed (${(summary.rate * 100).toFixed(1)}%), threshold ${THRESHOLD * 100}% -> ${outputPath}`);
  return summary.rate >= THRESHOLD ? 0 : 1;
}

main().then((code) => process.exit(code), (error: unknown) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(2);
});
