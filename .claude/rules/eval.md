# Evaluation (agent eval, RAGAS, latency)

## Agent evaluation — balanced 50 cases from the 70-case source corpus

15 UC1 explicit + 15 UC2 proactive + 10 suppression + 10 unrelated.

```bash
set -a; . ./.env; set +a
php scripts/run_stylitics_agent_eval.php \
  --cases=50 --anchor-product-id=57 \
  --output=reports/eval/stylitics_agent_eval_50.json
```

HTTP-level chatbot eval:

```bash
RAGAS_ENABLE=0 LANGFUSE_PUBLIC_KEY="$LANGFUSE_PUBLIC_KEY" \
LANGFUSE_SECRET_KEY="$LANGFUSE_SECRET_KEY" \
LANGFUSE_BASE_URL="${LANGFUSE_BASE_URL:-http://localhost:3000}" \
python3 eval/run_chatbot_eval.py --base-url http://localhost \
  --output reports/eval/chatbot_http_50.json \
  --csv-output reports/eval/chatbot_http_50.csv \
  --markdown-output reports/eval/chatbot_http_50.md
```

## RAGAS (recommendation answers)

```bash
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
OPENAI_EVAL_MODEL="$LLM_MODEL" LLM_TIMEOUT=120 \
python3 eval/run_findmine_ragas.py --max-cases=10 \
  --agent-report reports/eval/stylitics_agent_eval_50.json \
  --output reports/eval/stylitics_ragas_10.json
```

Mode `RAGAS_MODE=STYLITICS_LIVE_REAL_SHOP_RETRIEVAL`; evaluator `oc/mimo-v2.5-free`; embedding `bkai-foundation-models/vietnamese-bi-encoder` via `rag-ml`; judge concurrency 1. `context_precision`/`context_recall` omitted — corpus has no reference labels. Scores are a quality baseline, NOT a production SLA.

## Reading results

- Report JSON `server_latency` holds per-stage spans; styling stages: reference provider → LLM extraction → normalization → parallel Product Search (the bottleneck).
- Suppression/unrelated cases measure gate latency (~0.05 ms), recommendation cases measure full pipeline — never average the two boundaries together.
- Last recorded run (2026-09-07, deterministic pipeline): 50/50 PASS, 0 hallucinated products, 0 provider-ID leaks. Run `eval/run_chatbot_eval.py` để tái tạo.

Reports go to `reports/eval/` — gitignored, never commit.
