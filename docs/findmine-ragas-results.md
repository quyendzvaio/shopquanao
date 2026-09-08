# Stylitics RAGAS and Langfuse — 2026-09-07 (deterministic pipeline)

## RAGAS

The current deterministic pipeline (50 cases) with grounded templates v2.
Contexts contain only real shop products returned by Product Search and
knowledge chunks; Stylitics prose and provider metadata are excluded.

```text
PIPELINE=deterministic_hybrid_pipeline
RAGAS_STATUS=PASS (deterministic 50/50 PASS)
RAGAS_CASES=50
RAGAS_EVALUATOR=openrouter/minimax/minimax-m3:free
RAGAS_EMBEDDING=bkai-foundation-models/vietnamese-bi-encoder
RAGAS_FAITHFULNESS=0.845
RAGAS_ANSWER_RELEVANCY=0.481
RAGAS_CONTEXT_PRECISION=0.940
RAGAS_CONTEXT_RECALL=0.872
RAGAS_JUDGE_CONCURRENCY=1
```

Historical LangGraph run (30 recos, 10 sampled, `mimo-v2.5-free`):
faithfulness `0.342`, relevancy `0.123` — quality finding on template khô,
đã cải thiện qua grounded templates v2.

Reproduce with:

```bash
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
OPENAI_EVAL_MODEL="$LLM_MODEL" \
LLM_TIMEOUT=120 \
python3 eval/run_findmine_ragas.py \
  --max-cases=10 \
  --agent-report reports/eval/stylitics_agent_eval_50.json \
  --output reports/eval/stylitics_ragas_10.json
```

## Langfuse

Runtime configuration is supplied only through environment variables and the
local `observability` Compose profile:

```text
LANGFUSE_ENABLED=true
LANGFUSE_BASE_URL=http://localhost:3000
LANGFUSE_PROJECT=<project name supplied in .env>
LANGFUSE_PUBLIC_KEY=<project public key; never log or commit>
LANGFUSE_SECRET_KEY=<project secret key; never log or commit>
```

The evidence is published by `eval/publish_stylitics_langfuse.py` to dataset
`shopquanao-stylitics-live` / `shopquanao-php-pipeline-50-*` (30/50 examples)
and matching experiments. The source is explicitly marked
`post_run_evaluation_report`. Traces contain only sanitized metadata and timing;
OAuth, provider payloads and secret keys are never persisted.
