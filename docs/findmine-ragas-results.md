# Final FindMine RAGAS and LangSmith — 2026-08-25

RAGAS evaluated all 40 successful recommendation answers from the wider styling suite. Contexts contain real shop products returned by Product Search only; provider prose and extraction output are excluded from grounding evidence.

```text
RAGAS_STATUS=PASS
RAGAS_CASES=40
RAGAS_UNIQUE_CASES=40
RAGAS_EVALUATOR=oc/mimo-v2.5-free
RAGAS_EMBEDDING=bkai-foundation-models/vietnamese-bi-encoder
RAGAS_FAITHFULNESS=0.7625
RAGAS_ANSWER_RELEVANCY=0.16925982730601613
```

`context_precision` and `context_recall` are not reported because this corpus has no reference answers or context relevance labels. The low answer-relevancy score is retained as a real quality finding: response templates are long and similar across diverse question phrasing.

LangSmith project `fashion-shop-findmine-70-final-20260825` contains successful trace `893391a9-8dfa-4563-b88d-dfa2e500ba46`:

```text
LANGSMITH_RUNS=241
LANGSMITH_LLM_RUNS=120
LANGSMITH_CHAIN_RUNS=121
LANGSMITH_ERROR_RUNS=0
LANGSMITH_PENDING_RUNS=0
```

The final 50-turn HTTP rerun is in project `fashion-shop-chatbot-http-50-final-rerun-20260825`: 76 runs (50 chatbot + 26 knowledge), zero errors and zero pending runs.

Artifact: `reports/eval/findmine_ragas_latest.json`.
