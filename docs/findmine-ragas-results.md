# Glance RAGAS and Langfuse — 2026-08-30

## RAGAS

The live run consumed successful recommendation answers from the balanced
50-case agent evaluation. Thirty recommendation cases were available; ten were
sampled with `--max-cases=10`. Contexts contain only real shop products returned
by Product Search. Glance prose and provider metadata are excluded from grounding
contexts.

```text
RAGAS_MODE=GLANCE_LIVE_REAL_SHOP_RETRIEVAL
RAGAS_STATUS=PASS
RAGAS_SUCCESSFUL_RECOMMENDATION_ANSWERS=30
RAGAS_UNIQUE_CASES_AVAILABLE=30
RAGAS_UNIQUE_EVALUATION_CASES=10
RAGAS_EVALUATOR=oc/mimo-v2.5-free
RAGAS_EMBEDDING=bkai-foundation-models/vietnamese-bi-encoder
RAGAS_FAITHFULNESS=0.3416666667
RAGAS_ANSWER_RELEVANCY=0.1230097772
RAGAS_JUDGE_CONCURRENCY=1
```

`context_precision` and `context_recall` are omitted because this corpus has no
reference answers or relevance labels. The low answer relevancy score is a
quality finding, not an execution failure.

Reproduce with:

```bash
RAGAS_EMBEDDING_URL="http://$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' shop_quan_ao_rag_ml):8000" \
OPENAI_EVAL_MODEL="$LLM_MODEL" \
LLM_TIMEOUT=120 \
python3 eval/run_findmine_ragas.py \
  --max-cases=10 \
  --agent-report reports/eval/glance_agent_eval_50_live_after_fix_20260830.json \
  --output reports/eval/glance_ragas_10_live_after_fix_20260830.json
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

The live evidence is published by `eval/publish_glance_langfuse.py` to dataset
`shopquanao-glance-live-20260830` (30 examples) and experiment
`shopquanao-glance-live-eval-20260830` (30 runs). The source is explicitly marked
`post_run_evaluation_report`. Traces contain only sanitized metadata and timing;
OAuth, provider payloads and secret keys are never persisted.
