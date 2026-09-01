#!/usr/bin/env python3
"""RAGAS evaluation for grounded styling recommendation answers."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
from typing import Any

from datasets import Dataset
from langchain_openai import ChatOpenAI
from ragas import evaluate
from ragas.llms import LangchainLLMWrapper
from ragas.metrics import answer_relevancy, faithfulness
from ragas.run_config import RunConfig

from ragas_compat import build_evaluator_embeddings, build_evaluator_llm, json_safe


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent-report", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--max-cases", type=int, default=0, help="Bound judge calls; 0 evaluates every unique case")
    args = parser.parse_args()

    report = json.loads(args.agent_report.read_text(encoding="utf-8"))
    successful = [case for case in report.get("ragas_cases", []) if valid_case(case)]
    provider_mode = str(report.get("provider_mode", "unknown"))

    # Avoid spending judge tokens if a future corpus happens to contain an exact
    # duplicate while retaining each original case in the agent report.
    unique: dict[str, dict[str, Any]] = {}
    for case in successful:
        fingerprint = json.dumps(
            [case["question"], case["answer"], case["contexts"]],
            ensure_ascii=False,
            sort_keys=True,
        )
        unique.setdefault(fingerprint, case)
    if args.max_cases > 0:
        unique = dict(list(unique.items())[:args.max_cases])

    result: dict[str, Any] = {
        "mode": (
            "GLANCE_LIVE_REAL_SHOP_RETRIEVAL"
            if provider_mode == "glance_live"
            else "MIXED_REAL_SHOP_RETRIEVAL"
        ),
        "provider_mode": provider_mode,
        "status": "NOT_APPLICABLE",
        "successful_recommendation_answers": len(successful),
        "unique_evaluation_cases": len(unique),
        "unique_cases_available": len({json.dumps([case.get('question'), case.get('answer'), case.get('contexts')], ensure_ascii=False, sort_keys=True) for case in successful}),
        "metrics": {},
        "notes": [
            "Contexts contain Product Search products only.",
            "FindMine suggestions and extraction output are excluded from grounding contexts.",
            "context_precision and context_recall are omitted because no reference answers or relevance labels exist.",
        ],
    }
    if not unique:
        result["reason"] = "No successful grounded recommendation answers were available."
        write_result(args.output, result)
        return 0

    try:
        llm, model, llm_notes = build_evaluator_llm(ChatOpenAI, LangchainLLMWrapper)
        embeddings, embedding_model, embedding_notes = build_evaluator_embeddings()
        answer_relevancy.strictness = 1
        rows = [
            {"question": case["question"], "answer": case["answer"], "contexts": case["contexts"]}
            for case in unique.values()
        ]
        scores = evaluate(
            Dataset.from_list(rows),
            metrics=[faithfulness, answer_relevancy],
            llm=llm,
            embeddings=embeddings,
            run_config=RunConfig(
                timeout=int(os.getenv("RAGAS_TIMEOUT", "180")),
                max_retries=int(os.getenv("RAGAS_MAX_RETRIES", "8")),
                max_wait=int(os.getenv("RAGAS_MAX_WAIT", "300")),
                max_workers=int(os.getenv("RAGAS_MAX_WORKERS", "1")),
                log_tenacity=True,
            ),
            raise_exceptions=True,
        )
        score_dict = scores.to_pandas()[["faithfulness", "answer_relevancy"]].mean().to_dict()
        result.update({
            "status": "PASS",
            "evaluator_model": model,
            "embedding_model": embedding_model,
            "metrics": json_safe(score_dict),
        })
        result["notes"].extend(llm_notes + embedding_notes)
        result["notes"].append(
            f"RAGAS judge concurrency: {os.getenv('RAGAS_MAX_WORKERS', '1')} worker(s)."
        )
    except Exception as exc:  # surfaced as a truthful execution failure
        result.update({"status": "FAIL", "error": f"{type(exc).__name__}: {exc}"})

    write_result(args.output, result)
    return 0 if result["status"] in {"PASS", "NOT_APPLICABLE"} else 1


def valid_case(case: Any) -> bool:
    return (
        isinstance(case, dict)
        and bool(str(case.get("question", "")).strip())
        and bool(str(case.get("answer", "")).strip())
        and isinstance(case.get("contexts"), list)
        and bool(case["contexts"])
    )


def write_result(path: Path, result: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    raise SystemExit(main())
