#!/usr/bin/env python3
"""
Evaluate Fashion Shop chatbot/RAG behavior.

What it measures:
- Retrieval latency and top-k context quality from /api/knowledge/search
- Chat latency and answer keyword coverage from /api/chatbot
- Optional RAGAS metrics when ragas + a valid evaluator LLM are configured
- Optional LangSmith traces when LANGSMITH_API_KEY is configured

Outputs JSON and CSV reports under reports/eval/.
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import statistics
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlencode

import requests


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CASES = ROOT / "tests" / "eval" / "rag_eval_cases.json"
DEFAULT_OUT = ROOT / "reports" / "eval"


@dataclass
class EvalCase:
    id: str
    type: str
    question: str
    category: str | None
    reference: str
    reference_context_keywords: list[str]
    expected_answer_keywords: list[str]


def load_cases(path: Path) -> list[EvalCase]:
    data = json.loads(path.read_text(encoding="utf-8"))
    return [EvalCase(**item) for item in data]


def timed_request(method: str, url: str, **kwargs: Any) -> tuple[float, requests.Response | None, str | None]:
    start = time.perf_counter()
    try:
        response = requests.request(method, url, timeout=kwargs.pop("timeout", 45), **kwargs)
        latency_ms = (time.perf_counter() - start) * 1000
        return latency_ms, response, None
    except Exception as exc:  # noqa: BLE001
        latency_ms = (time.perf_counter() - start) * 1000
        return latency_ms, None, str(exc)


def keyword_coverage(text: str, keywords: list[str]) -> float:
    if not keywords:
        return 1.0
    lower = text.lower()
    hits = sum(1 for kw in keywords if kw.lower() in lower)
    return hits / max(1, len(keywords))


def percentile(values: list[float], pct: float) -> float | None:
    if not values:
        return None
    sorted_values = sorted(values)
    idx = min(len(sorted_values) - 1, int(round((pct / 100) * (len(sorted_values) - 1))))
    return sorted_values[idx]


def get_json(response: requests.Response | None) -> dict[str, Any]:
    if response is None:
        return {}
    try:
        return response.json()
    except Exception:  # noqa: BLE001
        return {"raw": response.text[:1000]}


def run_case(base_url: str, case: EvalCase) -> dict[str, Any]:
    retrieval_url = f"{base_url.rstrip('/')}/api/knowledge/search"
    query = {"q": case.question, "limit": 5}
    if case.category:
        query["category"] = case.category
    retrieval_latency, retrieval_response, retrieval_error = timed_request(
        "GET",
        f"{retrieval_url}?{urlencode(query)}",
    )
    retrieval_payload = get_json(retrieval_response)
    contexts = [
        f"{item.get('title', '')}\n{item.get('content', '')}".strip()
        for item in retrieval_payload.get("results", [])
    ]

    chat_latency, chat_response, chat_error = timed_request(
        "POST",
        f"{base_url.rstrip('/')}/api/chatbot",
        json={"message": case.question},
    )
    chat_payload = get_json(chat_response)
    answer = str(chat_payload.get("message", ""))

    return {
        "id": case.id,
        "type": case.type,
        "question": case.question,
        "category": case.category,
        "reference": case.reference,
        "retrieval_status": retrieval_response.status_code if retrieval_response is not None else None,
        "retrieval_error": retrieval_error,
        "retrieval_latency_ms": round(retrieval_latency, 2),
        "retrieval_source": retrieval_payload.get("source"),
        "retrieved_contexts": contexts,
        "retrieved_count": len(contexts),
        "context_keyword_coverage": round(keyword_coverage("\n".join(contexts), case.reference_context_keywords), 4),
        "chat_status": chat_response.status_code if chat_response is not None else None,
        "chat_error": chat_error,
        "chat_latency_ms": round(chat_latency, 2),
        "answer": answer,
        "answer_keyword_coverage": round(keyword_coverage(answer, case.expected_answer_keywords), 4),
        "products_count": len(chat_payload.get("products", []) or []),
        "has_redirect_url": "redirect_url" in chat_payload,
    }


def summarize(rows: list[dict[str, Any]]) -> dict[str, Any]:
    retrieval_latencies = [r["retrieval_latency_ms"] for r in rows if r.get("retrieval_latency_ms") is not None]
    chat_latencies = [r["chat_latency_ms"] for r in rows if r.get("chat_latency_ms") is not None]
    return {
        "cases": len(rows),
        "retrieval_latency_ms": {
            "avg": round(statistics.mean(retrieval_latencies), 2) if retrieval_latencies else None,
            "p50": percentile(retrieval_latencies, 50),
            "p95": percentile(retrieval_latencies, 95),
            "max": max(retrieval_latencies) if retrieval_latencies else None,
        },
        "chat_latency_ms": {
            "avg": round(statistics.mean(chat_latencies), 2) if chat_latencies else None,
            "p50": percentile(chat_latencies, 50),
            "p95": percentile(chat_latencies, 95),
            "max": max(chat_latencies) if chat_latencies else None,
        },
        "avg_context_keyword_coverage": round(statistics.mean([r["context_keyword_coverage"] for r in rows]), 4),
        "avg_answer_keyword_coverage": round(statistics.mean([r["answer_keyword_coverage"] for r in rows]), 4),
        "retrieval_errors": [r["id"] for r in rows if r.get("retrieval_error") or r.get("retrieval_status") != 200],
        "chat_errors": [r["id"] for r in rows if r.get("chat_error") or r.get("chat_status") != 200],
        "unexpected_redirects": [r["id"] for r in rows if r.get("has_redirect_url")],
    }


def maybe_trace_langsmith(rows: list[dict[str, Any]], project_name: str) -> dict[str, Any]:
    if not os.getenv("LANGSMITH_API_KEY"):
        return {"enabled": False, "reason": "LANGSMITH_API_KEY is not set"}
    try:
        from langsmith import Client
    except Exception as exc:  # noqa: BLE001
        return {"enabled": False, "reason": f"langsmith import failed: {exc}"}

    client = Client()
    dataset_name = f"{project_name}-dataset"
    try:
        dataset = client.create_dataset(
            dataset_name=dataset_name,
            description="Fashion Shop chatbot RAG eval dataset",
        )
    except Exception:
        datasets = list(client.list_datasets(dataset_name=dataset_name))
        dataset = datasets[0] if datasets else None
    if dataset is None:
        return {"enabled": False, "reason": "could not create or find dataset"}

    created = 0
    for row in rows:
        try:
            client.create_example(
                inputs={"question": row["question"]},
                outputs={"answer": row["reference"]},
                metadata={
                    "case_id": row["id"],
                    "type": row["type"],
                    "actual_answer": row["answer"],
                    "retrieved_contexts": row["retrieved_contexts"],
                    "latency": {
                        "retrieval_ms": row["retrieval_latency_ms"],
                        "chat_ms": row["chat_latency_ms"],
                    },
                },
                dataset_id=dataset.id,
            )
            created += 1
        except Exception:
            continue
    return {"enabled": True, "dataset_name": dataset_name, "examples_created": created}


def maybe_run_ragas(rows: list[dict[str, Any]]) -> dict[str, Any]:
    """Best-effort RAGAS run.

    RAGAS LLM-based metrics need a valid judge model. In this project the current
    DeepSeek key can be invalid, so this function returns a clear skip reason
    instead of failing the whole latency/retrieval eval.
    """
    try:
        from datasets import Dataset
        from ragas import evaluate
        from ragas.metrics import answer_relevancy, context_precision, context_recall, faithfulness
    except Exception as exc:  # noqa: BLE001
        return {"enabled": False, "reason": f"ragas import failed: {exc}"}

    api_key = os.getenv("OPENAI_API_KEY") or os.getenv("LLM_API_KEY")
    base_url = os.getenv("OPENAI_BASE_URL") or os.getenv("LLM_BASE_URL")
    if not api_key:
        return {"enabled": False, "reason": "No evaluator API key found (OPENAI_API_KEY or LLM_API_KEY)"}

    if base_url:
        os.environ.setdefault("OPENAI_API_BASE", base_url)
        os.environ.setdefault("OPENAI_BASE_URL", base_url)
    os.environ.setdefault("OPENAI_API_KEY", api_key)

    dataset = Dataset.from_list([
        {
            "question": row["question"],
            "answer": row["answer"],
            "contexts": row["retrieved_contexts"],
            "ground_truth": row["reference"],
        }
        for row in rows
        if row["retrieved_contexts"] and row["answer"]
    ])

    try:
        result = evaluate(
            dataset,
            metrics=[faithfulness, answer_relevancy, context_precision, context_recall],
            raise_exceptions=False,
        )
        return {"enabled": True, "scores": result.to_pandas().to_dict(orient="records")}
    except Exception as exc:  # noqa: BLE001
        return {"enabled": False, "reason": f"ragas evaluate failed: {exc}"}


def write_reports(rows: list[dict[str, Any]], summary: dict[str, Any], ragas_result: dict[str, Any], langsmith_result: dict[str, Any], out_dir: Path) -> dict[str, str]:
    out_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    json_path = out_dir / f"rag_eval_{stamp}.json"
    csv_path = out_dir / f"rag_eval_{stamp}.csv"
    latest_path = out_dir / "rag_eval_latest.json"

    payload = {
        "generated_at": stamp,
        "summary": summary,
        "ragas": ragas_result,
        "langsmith": langsmith_result,
        "rows": rows,
    }
    json_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    latest_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    fieldnames = [
        "id",
        "type",
        "retrieval_status",
        "retrieval_latency_ms",
        "retrieval_source",
        "retrieved_count",
        "context_keyword_coverage",
        "chat_status",
        "chat_latency_ms",
        "answer_keyword_coverage",
        "products_count",
        "has_redirect_url",
    ]
    with csv_path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow({key: row.get(key) for key in fieldnames})

    return {"json": str(json_path), "csv": str(csv_path), "latest": str(latest_path)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default=os.getenv("EVAL_BASE_URL", "http://localhost:8092"))
    parser.add_argument("--cases", type=Path, default=DEFAULT_CASES)
    parser.add_argument("--out-dir", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--project-name", default=os.getenv("LANGSMITH_PROJECT", "fashion-shop-rag-eval"))
    parser.add_argument("--skip-ragas", action="store_true")
    parser.add_argument("--skip-langsmith", action="store_true")
    args = parser.parse_args()

    cases = load_cases(args.cases)
    rows = [run_case(args.base_url, case) for case in cases]
    summary = summarize(rows)
    ragas_result = {"enabled": False, "reason": "skipped by CLI"} if args.skip_ragas else maybe_run_ragas(rows)
    langsmith_result = {"enabled": False, "reason": "skipped by CLI"} if args.skip_langsmith else maybe_trace_langsmith(rows, args.project_name)
    paths = write_reports(rows, summary, ragas_result, langsmith_result, args.out_dir)

    print(json.dumps({
        "summary": summary,
        "ragas": ragas_result,
        "langsmith": langsmith_result,
        "reports": paths,
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
