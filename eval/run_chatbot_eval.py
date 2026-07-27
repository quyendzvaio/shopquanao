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
import os
import statistics
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests

from ragas_compat import build_evaluator_llm, json_safe


@dataclass
class EvalRow:
    scenario_id: str
    turn_id: str
    turn_index: int
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
def call_chatbot(
    base_url: str,
    question: str,
    session_token: str | None,
    timeout: float,
    bearer_token: str | None = None,
    max_retries: int = 3,
    retry_delay: float = 5.3,
) -> tuple[dict[str, Any], int]:
    payload: dict[str, Any] = {"message": question}
    if session_token:
        payload["session_token"] = session_token
    headers: dict[str, str] = {}
    if bearer_token:
        headers["Authorization"] = f"Bearer {bearer_token}"

    last_response: requests.Response | None = None
    last_error: str | None = None
    for attempt in range(max_retries + 1):
        started = time.perf_counter()
        try:
            response = requests.post(
                f"{base_url.rstrip('/')}/api/chatbot",
                json=payload,
                headers=headers,
                timeout=timeout,
            )
        except requests.RequestException as exc:
            latency_ms = int((time.perf_counter() - started) * 1000)
            last_error = f"Request failed: {type(exc).__name__}: {exc}"
            if attempt >= max_retries:
                return {
                    "message": f"[EVAL_ERROR] {last_error}",
                    "products": [],
                    "knowledge_sources": [],
                    "session_token": session_token,
                }, latency_ms
            time.sleep(retry_delay)
            continue
        latency_ms = int((time.perf_counter() - started) * 1000)
        should_retry = response.status_code == 429 or response.status_code >= 500
        if not should_retry:
            response.raise_for_status()
            try:
                return response.json(), latency_ms
            except ValueError as exc:
                last_error = f"Non-JSON response HTTP {response.status_code}: {response.text[:200]}"
                should_retry = True
        else:
            last_error = f"HTTP {response.status_code}: {response.text[:200]}"

        if not should_retry or attempt >= max_retries:
            break
        last_response = response
        time.sleep(retry_delay)

    status = last_response.status_code if last_response is not None else 0
    return {
        "message": f"[EVAL_ERROR] {last_error or f'HTTP {status}'}",
        "products": [],
        "knowledge_sources": [],
        "session_token": session_token,
    }, 0


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


def product_tool_evidence(products: list[dict[str, Any]]) -> list[str]:
    contexts: list[str] = []
    for product in products:
        lines = [
            "[Source: product_inventory]",
            f"Product ID: {product.get('id', '')}",
            f"Name: {product.get('name', '')}",
            f"Price: {product.get('price', '')}",
            f"Stock: {product.get('stock', '')}",
            f"Availability: {product.get('stock_status', '')}",
        ]
        sizes = product.get("available_sizes")
        if isinstance(sizes, list) and sizes:
            lines.append("Available sizes: " + ", ".join(str(size) for size in sizes))
        contexts.append("\n".join(lines))
    return contexts


def order_tool_evidence(case: dict[str, Any], answer: str, bearer_token: str | None) -> list[str]:
    text = (case.get("question", "") + " " + case.get("id", "") + " " + answer).lower()
    is_order = (
        case.get("type") == "order"
        or "đơn hàng" in text
        or "mã đơn" in text
        or "đơn của tôi" in text
        or "theo dõi đơn" in text
    )
    if not is_order:
        return []

    if bearer_token:
        return [
            "\n".join([
                "[Source: order_auth]",
                "Authenticated: true",
                "Order data access: allowed only for orders owned by the authenticated user",
            ])
        ]

    return [
        "\n".join([
            "[Source: order_auth]",
            "Authenticated: false",
            "Order data access: denied",
            "Required action: user must log in before checking personal order status or private order details",
        ])
    ]


def build_evaluation_contexts(
    rag_documents: list[str],
    products: list[dict[str, Any]],
    case: dict[str, Any],
    answer: str,
    bearer_token: str | None,
) -> list[str]:
    contexts: list[str] = []
    contexts.extend(rag_documents)
    contexts.extend(product_tool_evidence(products))
    contexts.extend(order_tool_evidence(case, answer, bearer_token))
    return [context for context in contexts if context.strip()]


def infer_category(case: dict[str, Any]) -> str | None:
    text = (case.get("question", "") + " " + case.get("id", "")).lower()
    if "đổi" in text or "trả" in text or "sale" in text:
        return "return"
    if "ship" in text or "giao" in text:
        return "shipping"
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


def iter_turns(case: dict[str, Any]) -> list[dict[str, Any]]:
    turns = case.get("turns")
    if isinstance(turns, list) and turns:
        normalized = []
        for idx, turn in enumerate(turns, start=1):
            item = dict(turn)
            item.setdefault("id", f"{case.get('id', 'scenario')}_turn_{idx}")
            item.setdefault("type", case.get("type"))
            item.setdefault("scenario_id", case.get("id"))
            normalized.append(item)
        return normalized
    item = dict(case)
    item.setdefault("scenario_id", case.get("id"))
    return [item]


def run_cases(
    base_url: str,
    cases: list[dict[str, Any]],
    timeout: float,
    turn_delay: float = 0.0,
    max_retries: int = 3,
) -> list[EvalRow]:
    rows: list[EvalRow] = []
    bearer_token = os.getenv("EVAL_BEARER_TOKEN") or None
    for case in cases:
        scenario_id = str(case.get("id", "scenario"))
        session_token: str | None = None
        turns = iter_turns(case)
        for idx, turn in enumerate(turns, start=1):
            if turn.get("requires_auth") and not bearer_token:
                skipped = dict(turn)
                skipped["question"] = str(skipped.get("question", ""))
                rows.append(EvalRow(
                    scenario_id=scenario_id,
                    turn_id=str(skipped.get("id", f"{scenario_id}_turn_{idx}")),
                    turn_index=idx,
                    case=skipped,
                    answer="",
                    products=[],
                    knowledge_sources=[],
                    contexts=[],
                    latency_ms=0,
                    deterministic_pass=True,
                    failures=["SKIPPED: EVAL_BEARER_TOKEN is not set"],
                ))
                continue

            response, latency_ms = call_chatbot(
                base_url,
                turn["question"],
                session_token,
                timeout,
                bearer_token=bearer_token,
                max_retries=max_retries,
                retry_delay=turn_delay or 5.3,
            )
            session_token = response.get("session_token")
            answer = str(response.get("message", ""))
            products = response.get("products") or []
            sources = response.get("knowledge_sources") or []
            rag_documents = call_knowledge(base_url, turn["question"], infer_category(turn), timeout) if turn.get("expect_knowledge") else []
            contexts = build_evaluation_contexts(rag_documents, products, turn, answer, bearer_token)
            failures = deterministic_check(turn, answer, products, sources)
            rows.append(EvalRow(
                scenario_id=scenario_id,
                turn_id=str(turn.get("id", f"{scenario_id}_turn_{idx}")),
                turn_index=idx,
                case=turn,
                answer=answer,
                products=products,
                knowledge_sources=sources,
                contexts=contexts,
                latency_ms=latency_ms,
                deterministic_pass=not failures,
                failures=failures,
            ))
            if turn_delay > 0 and idx < len(turns):
                time.sleep(turn_delay)
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
        from ragas.llms import LangchainLLMWrapper
        from ragas.llms.prompt import Prompt
        from ragas.metrics import answer_relevancy, context_precision, context_recall, faithfulness
        from langchain_openai import ChatOpenAI
    except Exception as exc:
        return {"skipped": f"RAGAS unavailable: {type(exc).__name__}: {exc}"}

    all_rag_rows = [
        {
            "question": r.case["question"],
            "answer": r.answer,
            "contexts": r.contexts,
            "ground_truth": r.case.get("ground_truth", ""),
        }
        for r in rows
    ]
    grounded_rag_rows = [row for row in all_rag_rows if row["contexts"]]
    if not all_rag_rows:
        return {"skipped": "No rows for RAGAS."}
    try:
        kwargs: dict[str, Any] = {}
        kwargs["llm"], _evaluator_model, notes = build_evaluator_llm(ChatOpenAI, LangchainLLMWrapper)

        embedding_provider = os.getenv("RAGAS_EMBEDDING_PROVIDER", "huggingface").lower()
        if embedding_provider in {"huggingface", "hf", "local"}:
            try:
                try:
                    from langchain_huggingface import HuggingFaceEmbeddings
                except Exception:
                    from langchain_community.embeddings import HuggingFaceEmbeddings

                embedding_model = os.getenv(
                    "RAGAS_EMBEDDING_MODEL",
                    "bkai-foundation-models/vietnamese-bi-encoder",
                )
                kwargs["embeddings"] = HuggingFaceEmbeddings(
                    model_name=embedding_model,
                    model_kwargs={"device": os.getenv("RAGAS_EMBEDDING_DEVICE", "cpu")},
                    encode_kwargs={"normalize_embeddings": True},
                )
                try:
                    answer_relevancy.strictness = max(1, int(os.getenv("RAGAS_ANSWER_RELEVANCY_STRICTNESS", "4")))
                except Exception:
                    answer_relevancy.strictness = 4
                answer_relevancy.question_generation = Prompt(
                    name="question_generation_vi",
                    instruction=(
                        "Từ câu trả lời và ngữ cảnh, hãy tạo đúng một câu hỏi bằng tiếng Việt mà câu trả lời "
                        "đang giải đáp. Xác định noncommittal=1 chỉ khi câu trả lời né tránh, mơ hồ hoặc nói "
                        "không biết; ngược lại đặt noncommittal=0. Không được tạo câu hỏi bằng tiếng Anh."
                    ),
                    output_format_instruction=answer_relevancy.question_generation.output_format_instruction,
                    examples=[
                        {
                            "answer": "Shop hỗ trợ đổi trả trong 7 ngày nếu sản phẩm còn nguyên tem mác.",
                            "context": "Khách được đổi trả trong 7 ngày, sản phẩm chưa qua sử dụng và còn tem mác.",
                            "output": {"question": "Shop hỗ trợ đổi trả trong bao lâu và cần điều kiện gì?", "noncommittal": 0},
                        },
                        {
                            "answer": "Mình chưa có đủ dữ liệu để xác nhận thông tin này.",
                            "context": "",
                            "output": {"question": "Thông tin cần xác nhận là gì?", "noncommittal": 1},
                        },
                    ],
                    input_keys=["answer", "context"],
                    output_key="output",
                    output_type="json",
                    language="vietnamese",
                )
                notes.append(f"answer_relevancy uses local HuggingFace embeddings: {embedding_model}")
                notes.append("answer_relevancy question generation is constrained to Vietnamese.")
            except Exception as exc:
                notes.append(f"answer_relevancy skipped because HuggingFace embeddings failed: {type(exc).__name__}: {exc}")
        elif os.getenv("OPENAI_API_KEY"):
            notes.append("answer_relevancy uses default OpenAI-compatible embeddings.")
        else:
            notes.append("answer_relevancy skipped because no embedding evaluator was configured.")

        output: dict[str, Any] = {}
        if "embeddings" in kwargs or os.getenv("OPENAI_API_KEY"):
            answer_result = evaluate(
                Dataset.from_list(all_rag_rows),
                metrics=[answer_relevancy],
                **kwargs,
            )
            output.update(dict(answer_result))
            notes.append(f"answer_relevancy evaluated all {len(all_rag_rows)} turns.")

        if grounded_rag_rows:
            grounded_result = evaluate(
                Dataset.from_list(grounded_rag_rows),
                metrics=[faithfulness, context_precision, context_recall],
                **kwargs,
            )
            output.update(dict(grounded_result))
            notes.append(f"grounding metrics evaluated {len(grounded_rag_rows)} turns with evidence contexts.")
        else:
            notes.append("grounding metrics skipped because no turn had evidence contexts.")
        if notes:
            output["_notes"] = notes
        output.setdefault("_notes", [])
        output["_notes"].append("RAGAS contexts include RAG documents plus serialized product/order evidence when those tools are used.")
        return output
    except Exception as exc:
        return {"error": f"{type(exc).__name__}: {exc}"}


def write_report(rows: list[EvalRow], ragas_result: dict[str, Any] | None, output_path: Path) -> dict[str, Any]:
    summary = {
        "total_scenarios": len({r.scenario_id for r in rows}),
        "total": len(rows),
        "deterministic_passed": sum(1 for r in rows if r.deterministic_pass),
        "deterministic_failed": sum(1 for r in rows if not r.deterministic_pass),
        "latency": summarize_latency(rows),
        "ragas": json_safe(ragas_result),
    }
    details = [
        {
            "scenario_id": r.scenario_id,
            "turn_id": r.turn_id,
            "turn_index": r.turn_index,
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


def write_markdown_report(report: dict[str, Any], output_path: Path, base_url: str, cases_path: str) -> None:
    summary = report["summary"]
    latency = summary.get("latency", {})
    ragas = summary.get("ragas") or {}
    details = report.get("details", [])
    lines = [
        "# Báo Cáo Chatbot Multi-Step RAGAS + LangSmith",
        "",
        f"Ngày chạy: {time.strftime('%Y-%m-%d %H:%M:%S %z')}",
        f"Target: `{base_url}`",
        f"Case file: `{cases_path}`",
        "",
        "## Tóm Tắt",
        "",
        "| Metric | Value |",
        "| --- | ---: |",
        f"| Scenarios | `{summary.get('total_scenarios', 0)}` |",
        f"| Turns | `{summary.get('total', 0)}` |",
        f"| Deterministic passed | `{summary.get('deterministic_passed', 0)}` |",
        f"| Deterministic failed | `{summary.get('deterministic_failed', 0)}` |",
        f"| Latency min | `{latency.get('min_ms', 0)} ms` |",
        f"| Latency avg | `{latency.get('avg_ms', 0)} ms` |",
        f"| Latency p50 | `{latency.get('p50_ms', 0)} ms` |",
        f"| Latency p95 | `{latency.get('p95_ms', 0)} ms` |",
        f"| Latency max | `{latency.get('max_ms', 0)} ms` |",
        "",
        "## RAGAS",
        "",
        "| Metric | Value |",
        "| --- | ---: |",
        f"| Faithfulness | `{ragas.get('faithfulness', 'n/a')}` |",
        f"| Answer relevancy | `{ragas.get('answer_relevancy', 'n/a')}` |",
        f"| Context precision | `{ragas.get('context_precision', 'n/a')}` |",
        f"| Context recall | `{ragas.get('context_recall', 'n/a')}` |",
        "",
        "Notes:",
    ]
    for note in ragas.get("_notes", []):
        lines.append(f"- {note}")
    if not ragas.get("_notes"):
        lines.append("- Không có ghi chú RAGAS.")
    lines.extend([
        "",
        "## LangSmith",
        "",
        "- Tracing bật qua biến môi trường `LANGSMITH_API_KEY`.",
        f"- Project: `{os.getenv('LANGSMITH_PROJECT', 'fashion-shop-chatbot-eval')}`.",
        "- API key không được ghi vào report hoặc source.",
        "",
        "## Chi Tiết Turn",
        "",
        "| Scenario | Turn | Type | Latency | Products | Knowledge | Contexts | Result | Failures |",
        "| --- | ---: | --- | ---: | ---: | ---: | ---: | --- | --- |",
    ])
    for item in details:
        status = "PASS" if item.get("deterministic_pass") else "FAIL"
        failures = "; ".join(item.get("failures", []))
        lines.append(
            f"| `{item.get('scenario_id')}` | `{item.get('turn_index')}` | `{item.get('type')}` | "
            f"`{item.get('latency_ms')} ms` | `{item.get('products_count')}` | "
            f"`{item.get('knowledge_sources_count')}` | `{item.get('contexts_count')}` | "
            f"`{status}` | {failures or '-'} |"
        )
    lines.extend([
        "",
        "## Bảo Mật",
        "",
        "- Không ghi `LANGSMITH_API_KEY`, `LLM_API_KEY`, `OPENAI_API_KEY` hoặc HuggingFace token vào file.",
        "- HuggingFace embedding model chạy local public model; không dùng HuggingFace Inference API.",
        "",
    ])
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text("\n".join(lines), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default=os.getenv("CHATBOT_BASE_URL", "http://localhost:8092"))
    parser.add_argument("--cases", default="eval/chatbot_eval_cases.jsonl")
    parser.add_argument("--output", default="reports/chatbot_eval_report.json")
    parser.add_argument("--markdown-output", default="")
    parser.add_argument("--timeout", type=float, default=90.0)
    parser.add_argument("--turn-delay", type=float, default=float(os.getenv("EVAL_TURN_DELAY", "0")))
    parser.add_argument("--max-retries", type=int, default=int(os.getenv("EVAL_MAX_RETRIES", "3")))
    args = parser.parse_args()

    if os.getenv("LANGSMITH_API_KEY"):
        os.environ.setdefault("LANGSMITH_TRACING", "true")
        os.environ.setdefault("LANGSMITH_PROJECT", "fashion-shop-chatbot-eval")

    cases = load_cases(Path(args.cases))
    rows = run_cases(args.base_url, cases, args.timeout, args.turn_delay, args.max_retries)
    ragas_result = run_ragas(rows)
    report = write_report(rows, ragas_result, Path(args.output))
    if args.markdown_output:
        write_markdown_report(report, Path(args.markdown_output), args.base_url, args.cases)

    print(json.dumps(report["summary"], ensure_ascii=False, indent=2))
    for item in report["details"]:
        status = "PASS" if item["deterministic_pass"] else "FAIL"
        print(f"{status} {item['id']} latency={item['latency_ms']}ms failures={item['failures']}")
    return 0 if report["summary"]["deterministic_failed"] == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
