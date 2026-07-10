#!/usr/bin/env python3
"""Evaluate Fashion Shop chatbot latency, RAG grounding, and guardrails.

RAGAS and LangSmith are optional:
- If ragas is installed and RAGAS_ENABLE=1, the script runs RAGAS metrics.
- If LANGSMITH_API_KEY is set, calls are traced to LangSmith.
The deterministic checks always run.
"""
from __future__ import annotations

import argparse
import json
import math
import os
import statistics
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests


@dataclass
class EvalRow:
    case: dict[str, Any]
    answer: str
    products: list[dict[str, Any]]
    knowledge_sources: list[dict[str, Any]]
    contexts: list[str]
    latency_ms: int
    deterministic_pass: bool
    failures: list[str]


def load_cases(path: Path) -> list[dict[str, Any]]:
    with path.open("r", encoding="utf-8") as f:
        return [json.loads(line) for line in f if line.strip()]


def maybe_traceable(fn):
    if not os.getenv("LANGSMITH_API_KEY"):
        return fn
    try:
        from langsmith import traceable
    except Exception:
        return fn
    return traceable(name=fn.__name__)(fn)


@maybe_traceable
def call_chatbot(base_url: str, question: str, session_token: str | None, timeout: float) -> tuple[dict[str, Any], int]:
    payload: dict[str, Any] = {"message": question}
    if session_token:
        payload["session_token"] = session_token
    started = time.perf_counter()
    response = requests.post(f"{base_url.rstrip('/')}/api/chatbot", json=payload, timeout=timeout)
    latency_ms = int((time.perf_counter() - started) * 1000)
    response.raise_for_status()
    return response.json(), latency_ms


@maybe_traceable
def call_knowledge(base_url: str, question: str, category: str | None, timeout: float) -> list[str]:
    params = {"q": question, "limit": 5}
    if category:
        params["category"] = category
    try:
        response = requests.get(f"{base_url.rstrip('/')}/api/knowledge/search", params=params, timeout=timeout)
        response.raise_for_status()
        data = response.json()
    except Exception:
        return []
    return [str(item.get("content", "")) for item in data.get("results", []) if item.get("content")]


def infer_category(case: dict[str, Any]) -> str | None:
    text = (case.get("question", "") + " " + case.get("id", "")).lower()
    if "ship" in text or "giao" in text:
        return "shipping"
    if "đổi" in text or "trả" in text or "sale" in text:
        return "return"
    if "bảo hành" in text or "lỗi" in text:
        return "warranty"
    if "thanh toán" in text:
        return "payment"
    if "size" in text:
        return "size"
    return None


def deterministic_check(case: dict[str, Any], answer: str, products: list[dict[str, Any]], sources: list[dict[str, Any]]) -> list[str]:
    failures: list[str] = []
    answer_l = answer.lower()
    for kw in case.get("expected_keywords", []):
        if kw.lower() not in answer_l:
            failures.append(f"missing keyword: {kw}")
    for kw in case.get("forbidden_keywords", []):
        if kw.lower() in answer_l:
            failures.append(f"forbidden keyword present: {kw}")
    if len(products) < int(case.get("expect_products_min", 0)):
        failures.append(f"expected at least {case.get('expect_products_min')} products, got {len(products)}")
    if case.get("expect_knowledge") and not sources:
        failures.append("expected knowledge_sources in chatbot response")
    return failures


def run_cases(base_url: str, cases: list[dict[str, Any]], timeout: float) -> list[EvalRow]:
    rows: list[EvalRow] = []
    for case in cases:
        session_token: str | None = None
        response, latency_ms = call_chatbot(base_url, case["question"], session_token, timeout)
        session_token = response.get("session_token")
        answer = str(response.get("message", ""))
        products = response.get("products") or []
        sources = response.get("knowledge_sources") or []
        contexts = call_knowledge(base_url, case["question"], infer_category(case), timeout) if case.get("expect_knowledge") else []
        failures = deterministic_check(case, answer, products, sources)
        rows.append(EvalRow(
            case=case,
            answer=answer,
            products=products,
            knowledge_sources=sources,
            contexts=contexts,
            latency_ms=latency_ms,
            deterministic_pass=not failures,
            failures=failures,
        ))
    return rows


def summarize_latency(rows: list[EvalRow]) -> dict[str, Any]:
    latencies = [r.latency_ms for r in rows]
    if not latencies:
        return {}
    sorted_latencies = sorted(latencies)
    p95_index = min(len(sorted_latencies) - 1, int(round(0.95 * (len(sorted_latencies) - 1))))
    return {
        "count": len(latencies),
        "min_ms": min(latencies),
        "max_ms": max(latencies),
        "avg_ms": round(statistics.mean(latencies), 2),
        "p50_ms": int(statistics.median(latencies)),
        "p95_ms": sorted_latencies[p95_index],
    }


def run_ragas(rows: list[EvalRow]) -> dict[str, Any] | None:
    if os.getenv("RAGAS_ENABLE", "0") != "1":
        return {"skipped": "Set RAGAS_ENABLE=1 to run RAGAS metrics."}
    try:
        from datasets import Dataset
        from ragas import evaluate
        from ragas.metrics import answer_relevancy, context_precision, context_recall, faithfulness
        from langchain_openai import ChatOpenAI
    except Exception as exc:
        return {"skipped": f"RAGAS unavailable: {type(exc).__name__}: {exc}"}

    rag_rows = [
        {
            "question": r.case["question"],
            "answer": r.answer,
            "contexts": r.contexts,
            "ground_truth": r.case.get("ground_truth", ""),
        }
        for r in rows
        if r.contexts
    ]
    if not rag_rows:
        return {"skipped": "No rows with contexts for RAGAS."}
    try:
        dataset = Dataset.from_list(rag_rows)
        metrics = [faithfulness, context_precision, context_recall]
        kwargs: dict[str, Any] = {}

        if os.getenv("OPENAI_API_KEY"):
            metrics.insert(1, answer_relevancy)
        elif os.getenv("LLM_API_KEY"):
            base_url = (os.getenv("LLM_BASE_URL") or "https://api.deepseek.com").rstrip("/")
            if not base_url.endswith("/v1"):
                base_url += "/v1"
            kwargs["llm"] = ChatOpenAI(
                api_key=os.getenv("LLM_API_KEY"),
                base_url=base_url,
                model=os.getenv("LLM_MODEL") or "deepseek-chat",
                temperature=0,
                timeout=float(os.getenv("LLM_TIMEOUT") or 60),
            )
        else:
            return {"skipped": "RAGAS needs OPENAI_API_KEY or LLM_API_KEY for evaluator LLM."}

        result = evaluate(dataset, metrics=metrics, **kwargs)
        output = dict(result)
        if not os.getenv("OPENAI_API_KEY"):
            output["_note"] = "answer_relevancy skipped because no OPENAI_API_KEY/embedding evaluator was configured."
        return output
    except Exception as exc:
        return {"error": f"{type(exc).__name__}: {exc}"}


def write_report(rows: list[EvalRow], ragas_result: dict[str, Any] | None, output_path: Path) -> dict[str, Any]:
    def json_safe(value: Any) -> Any:
        if isinstance(value, float) and not math.isfinite(value):
            return None
        if isinstance(value, dict):
            return {str(k): json_safe(v) for k, v in value.items()}
        if isinstance(value, list):
            return [json_safe(item) for item in value]
        return value

    summary = {
        "total": len(rows),
        "deterministic_passed": sum(1 for r in rows if r.deterministic_pass),
        "deterministic_failed": sum(1 for r in rows if not r.deterministic_pass),
        "latency": summarize_latency(rows),
        "ragas": json_safe(ragas_result),
    }
    details = [
        {
            "id": r.case["id"],
            "type": r.case.get("type"),
            "question": r.case["question"],
            "answer": r.answer,
            "latency_ms": r.latency_ms,
            "products_count": len(r.products),
            "knowledge_sources_count": len(r.knowledge_sources),
            "contexts_count": len(r.contexts),
            "deterministic_pass": r.deterministic_pass,
            "failures": r.failures,
        }
        for r in rows
    ]
    output = {"summary": summary, "details": details}
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")
    return output


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default=os.getenv("CHATBOT_BASE_URL", "http://localhost:8092"))
    parser.add_argument("--cases", default="eval/chatbot_eval_cases.jsonl")
    parser.add_argument("--output", default="reports/chatbot_eval_report.json")
    parser.add_argument("--timeout", type=float, default=90.0)
    args = parser.parse_args()

    if os.getenv("LANGSMITH_API_KEY"):
        os.environ.setdefault("LANGSMITH_TRACING", "true")
        os.environ.setdefault("LANGSMITH_PROJECT", "fashion-shop-chatbot-eval")

    cases = load_cases(Path(args.cases))
    rows = run_cases(args.base_url, cases, args.timeout)
    ragas_result = run_ragas(rows)
    report = write_report(rows, ragas_result, Path(args.output))

    print(json.dumps(report["summary"], ensure_ascii=False, indent=2))
    for item in report["details"]:
        status = "PASS" if item["deterministic_pass"] else "FAIL"
        print(f"{status} {item['id']} latency={item['latency_ms']}ms failures={item['failures']}")
    return 0 if report["summary"]["deterministic_failed"] == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
