#!/usr/bin/env python3
"""Evaluate Fashion Shop chatbot latency, RAG grounding, and guardrails.

RAGAS and Langfuse are optional:
- If ragas is installed and RAGAS_ENABLE=1, the script runs RAGAS metrics.
- If Langfuse keys are set, calls are traced to the configured Langfuse host.
The deterministic checks always run.
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import statistics
import time
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests

from ragas_compat import build_evaluator_embeddings, build_evaluator_llm, json_safe
from langfuse_tracing import maybe_traceable, flush as flush_langfuse


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
    request_meta: dict[str, Any]
    server_latency: dict[str, Any]
    selected_tools: list[str]
    tool_calls: int
    execution_trace: list[dict[str, Any]]
    evaluation_group: str
    deterministic_pass: bool
    failures: list[str]


def load_cases(paths: list[Path]) -> list[dict[str, Any]]:
    cases: list[dict[str, Any]] = []
    for path in paths:
        with path.open("r", encoding="utf-8") as f:
            cases.extend(json.loads(line) for line in f if line.strip())
    return cases


@maybe_traceable
def call_chatbot(
    base_url: str,
    question: str,
    session_token: str | None,
    timeout: float,
    bearer_token: str | None = None,
    max_retries: int = 3,
    retry_delay: float = 5.3,
) -> tuple[dict[str, Any], int, dict[str, Any]]:
    payload: dict[str, Any] = {"message": question}
    if session_token:
        payload["session_token"] = session_token
    headers: dict[str, str] = {}
    if bearer_token:
        headers["Authorization"] = f"Bearer {bearer_token}"

    last_response: requests.Response | None = None
    last_error: str | None = None
    attempt_latencies: list[int] = []
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
            attempt_latencies.append(latency_ms)
            last_error = f"Request failed: {type(exc).__name__}: {exc}"
            if attempt >= max_retries:
                return {
                    "message": f"[EVAL_ERROR] {last_error}",
                    "products": [],
                    "knowledge_sources": [],
                    "session_token": session_token,
                }, latency_ms, {
                    "status_code": None,
                    "error": last_error,
                    "attempts": attempt + 1,
                    "attempt_latencies_ms": attempt_latencies,
                    "timed_out": isinstance(exc, requests.Timeout),
                }
            time.sleep(retry_delay)
            continue
        latency_ms = int((time.perf_counter() - started) * 1000)
        attempt_latencies.append(latency_ms)
        should_retry = response.status_code == 429 or response.status_code >= 500
        if not should_retry:
            try:
                payload = response.json()
                if response.status_code >= 400:
                    last_error = f"HTTP {response.status_code}: {response.text[:200]}"
                    return {
                        "message": f"[EVAL_ERROR] {last_error}",
                        "products": [],
                        "knowledge_sources": [],
                        "session_token": session_token,
                    }, latency_ms, {
                        "status_code": response.status_code,
                        "error": last_error,
                        "attempts": attempt + 1,
                        "attempt_latencies_ms": attempt_latencies,
                        "timed_out": False,
                    }
                return payload, latency_ms, {
                    "status_code": response.status_code,
                    "error": None,
                    "attempts": attempt + 1,
                    "attempt_latencies_ms": attempt_latencies,
                    "timed_out": False,
                }
            except ValueError:
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
    }, attempt_latencies[-1] if attempt_latencies else 0, {
        "status_code": status or None,
        "error": last_error,
        "attempts": len(attempt_latencies),
        "attempt_latencies_ms": attempt_latencies,
        "timed_out": False,
    }


def response_diagnostics(response: dict[str, Any]) -> tuple[dict[str, Any], list[str], int, list[dict[str, Any]]]:
    """Extract server-side timing and tool coordination without assuming one trace shape."""
    latency = response.get("latency")
    if not isinstance(latency, dict):
        return {}, [], 0, []
    routing = latency.get("routing") if isinstance(latency.get("routing"), dict) else {}
    trace = routing.get("execution_trace")
    if not isinstance(trace, list):
        trace = []
    tools: list[str] = []
    fingerprint_count = 0
    selected = routing.get("selected_tools")
    if isinstance(selected, list):
        tools.extend(str(tool) for tool in selected)
    for step in trace:
        if isinstance(step, dict):
            step_tools = step.get("selected_tools")
            if isinstance(step_tools, list):
                tools.extend(str(tool) for tool in step_tools)
            for fingerprint in step.get("tool_fingerprints", []) or []:
                if isinstance(fingerprint, dict) and fingerprint.get("tool"):
                    tools.append(str(fingerprint["tool"]))
                    fingerprint_count += 1
    selected_tools = sorted(set(tools))
    return latency, selected_tools, fingerprint_count or len(selected_tools), [step for step in trace if isinstance(step, dict)]


def evaluation_group(case: dict[str, Any]) -> str:
    explicit = case.get("evaluation_group")
    if explicit:
        return str(explicit)
    kind = str(case.get("type", "")).lower()
    if "guardrail" in kind:
        return "guardrail/non-rag"
    if "order" in kind:
        return "order/auth"
    if "mixed" in kind:
        return "mixed multi-tool"
    if kind in {"product", "size"}:
        return "product evidence"
    return "policy/rag"


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
    if "expect_products_max" in case and len(products) > int(case["expect_products_max"]):
        failures.append(f"expected at most {case['expect_products_max']} products, got {len(products)}")
    if case.get("expect_response_type") and case["expect_response_type"] != case.get("actual_response_type"):
        failures.append(f"expected response_type {case['expect_response_type']}")
    for field in case.get("required_product_fields", []):
        if products and any(not product.get(field) for product in products):
            failures.append(f"product evidence missing field: {field}")
    if case.get("expect_no_products") and products:
        failures.append(f"expected no products, got {len(products)}")
    if case.get("expect_no_knowledge") and sources:
        failures.append("expected no knowledge sources")
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
                    request_meta={"skipped": True, "reason": "EVAL_BEARER_TOKEN is not set"},
                    server_latency={},
                    selected_tools=[],
                    tool_calls=0,
                    execution_trace=[],
                    evaluation_group=evaluation_group(skipped),
                    deterministic_pass=True,
                    failures=["SKIPPED: EVAL_BEARER_TOKEN is not set"],
                ))
                continue

            response, latency_ms, request_meta = call_chatbot(
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
            turn = dict(turn)
            turn["actual_response_type"] = response.get("response_type")
            turn["actual_intent"] = response.get("primary_intent")
            failures = deterministic_check(turn, answer, products, sources)
            if request_meta.get("status_code") != 200:
                failures.append(f"HTTP request failed: {request_meta.get('status_code') or request_meta.get('error')}")
            server_latency, selected_tools, tool_calls, execution_trace = response_diagnostics(response)
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
                request_meta=request_meta,
                server_latency=server_latency,
                selected_tools=selected_tools,
                tool_calls=tool_calls,
                execution_trace=execution_trace,
                evaluation_group=evaluation_group(turn),
                deterministic_pass=not failures,
                failures=failures,
            ))
            if turn_delay > 0 and idx < len(turns):
                time.sleep(turn_delay)
    return rows


def rows_from_report(path: Path) -> list[EvalRow]:
    """Reload a completed chatbot run so RAGAS can be retried without new HTTP calls."""
    payload = json.loads(path.read_text(encoding="utf-8"))
    rows: list[EvalRow] = []
    for detail in payload.get("details", []):
        case = {
            "id": detail.get("id"),
            "type": detail.get("type"),
            "question": detail.get("question", ""),
            "ground_truth": detail.get("ground_truth", ""),
            "actual_response_type": detail.get("response_type"),
            "actual_intent": detail.get("intent"),
        }
        rows.append(EvalRow(
            scenario_id=str(detail.get("scenario_id", "scenario")),
            turn_id=str(detail.get("turn_id", detail.get("id", "turn"))),
            turn_index=int(detail.get("turn_index", 1)),
            case=case,
            answer=str(detail.get("answer", "")),
            products=detail.get("products", []) or [],
            knowledge_sources=detail.get("knowledge_sources", []) or [],
            contexts=detail.get("contexts", []) or [],
            latency_ms=int(detail.get("latency_ms", 0)),
            request_meta=detail.get("request_meta", {}) or {},
            server_latency=detail.get("server_latency", {}) or {},
            selected_tools=detail.get("selected_tools", []) or [],
            tool_calls=int(detail.get("tool_calls", 0)),
            execution_trace=detail.get("execution_trace", []) or [],
            evaluation_group=str(detail.get("evaluation_group", "policy/rag")),
            deterministic_pass=bool(detail.get("deterministic_pass", False)),
            failures=detail.get("failures", []) or [],
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
        "p99_ms": sorted_latencies[min(len(sorted_latencies) - 1, int(round(0.99 * (len(sorted_latencies) - 1))))],
    }


def summarize_server_latency(rows: list[EvalRow]) -> dict[str, Any]:
    keys = sorted({key for row in rows for key, value in row.server_latency.items() if isinstance(value, (int, float))})
    return {
        key: {
            "count": len(values),
            "avg_ms": round(statistics.mean(values), 2),
            "p50_ms": percentile(values, 50),
            "p95_ms": percentile(values, 95),
            "p99_ms": percentile(values, 99),
            "max_ms": max(values),
        }
        for key in keys
        for values in [[float(row.server_latency[key]) for row in rows if isinstance(row.server_latency.get(key), (int, float))]]
        if values
    }


def summarize_groups(rows: list[EvalRow]) -> dict[str, Any]:
    grouped: dict[str, list[EvalRow]] = defaultdict(list)
    for row in rows:
        grouped[row.evaluation_group].append(row)
    return {
        group: {
            "turns": len(group_rows),
            "scenarios": len({row.scenario_id for row in group_rows}),
            "passed": sum(row.deterministic_pass for row in group_rows),
            "failed": sum(not row.deterministic_pass for row in group_rows),
            "latency": summarize_latency(group_rows),
            "tool_calls": sum(row.tool_calls for row in group_rows),
            "tools": sorted({tool for row in group_rows for tool in row.selected_tools}),
        }
        for group, group_rows in sorted(grouped.items())
    }


def percentile(values: list[float], pct: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    index = min(len(ordered) - 1, int(round((pct / 100) * (len(ordered) - 1))))
    return round(ordered[index], 2)


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

    def dataset_row(row: EvalRow) -> dict[str, Any]:
        return {
            "question": row.case["question"],
            "answer": row.answer,
            "contexts": row.contexts,
            "ground_truth": row.case.get("ground_truth", ""),
        }

    all_rag_rows = [dataset_row(row) for row in rows if row.answer.strip()]
    if not all_rag_rows:
        return {"skipped": "No rows for RAGAS."}
    try:
        kwargs: dict[str, Any] = {}
        kwargs["llm"], _evaluator_model, notes = build_evaluator_llm(ChatOpenAI, LangchainLLMWrapper)

        embedding_provider = os.getenv("RAGAS_EMBEDDING_PROVIDER", "rag_ml").lower()
        if embedding_provider in {"rag_ml", "rag-ml", "service"}:
            try:
                kwargs["embeddings"], embedding_model, embedding_notes = build_evaluator_embeddings()
                notes.extend(embedding_notes)
            except Exception as exc:
                notes.append(f"answer_relevancy skipped because rag-ml embeddings failed: {type(exc).__name__}: {exc}")
        elif embedding_provider in {"huggingface", "hf", "local"}:
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
                notes.append(f"answer_relevancy uses local HuggingFace embeddings: {embedding_model}")
            except Exception as exc:
                notes.append(f"answer_relevancy skipped because HuggingFace embeddings failed: {type(exc).__name__}: {exc}")
        else:
            notes.append(f"answer_relevancy skipped because embedding provider {embedding_provider!r} is unsupported.")

        if "embeddings" in kwargs:
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
            notes.append("answer_relevancy question generation is constrained to Vietnamese.")

        def evaluate_slice(slice_rows: list[EvalRow]) -> dict[str, Any]:
            answer_rows = [dataset_row(row) for row in slice_rows if row.answer.strip()]
            grounded_rows = [row for row in answer_rows if row["contexts"]]
            result: dict[str, Any] = {
                "samples": len(answer_rows),
                "grounded_samples": len(grounded_rows),
                "valid_metrics": {},
            }
            if not answer_rows:
                return result
            if "embeddings" in kwargs:
                answer_result = evaluate(Dataset.from_list(answer_rows), metrics=[answer_relevancy], **kwargs)
                answer_values = dict(answer_result)
                result.update({key: value for key, value in answer_values.items() if not key.startswith("_" )})
                result["valid_metrics"]["answer_relevancy"] = len(answer_rows)
            if grounded_rows:
                grounded_result = evaluate(
                    Dataset.from_list(grounded_rows),
                    metrics=[faithfulness, context_precision, context_recall],
                    **kwargs,
                )
                grounded_values = dict(grounded_result)
                result.update({key: value for key, value in grounded_values.items() if not key.startswith("_")})
                for metric in ("faithfulness", "context_precision", "context_recall"):
                    result["valid_metrics"][metric] = len(grounded_rows)
            metric_names = ["answer_relevancy", "faithfulness", "context_precision", "context_recall"]
            scored_metrics = [metric for metric in metric_names if metric in result]
            if scored_metrics and all(result.get(metric) is None for metric in scored_metrics):
                result["errors"] = "All RAGAS judge calls returned no score; inspect evaluator provider response."
            return result

        output: dict[str, Any] = evaluate_slice(rows)
        grouped_rows: dict[str, list[EvalRow]] = defaultdict(list)
        for row in rows:
            grouped_rows[row.evaluation_group].append(row)
        output["groups"] = {group: evaluate_slice(group_rows) for group, group_rows in sorted(grouped_rows.items())}
        notes.append(f"answer_relevancy evaluated {len(all_rag_rows)} non-empty turns when embeddings were available.")
        notes.append(f"Grounding metrics used only turns with non-empty evidence contexts.")
        if notes:
            output["_notes"] = notes
        output.setdefault("_notes", [])
        output["_notes"].append("RAGAS contexts include RAG documents plus serialized product/order evidence when those tools are used.")
        return output
    except Exception as exc:
        return {"error": f"{type(exc).__name__}: {exc}"}


def write_report(rows: list[EvalRow], ragas_result: dict[str, Any] | None, output_path: Path, csv_path: Path | None = None) -> dict[str, Any]:
    summary = {
        "total_scenarios": len({r.scenario_id for r in rows}),
        "total": len(rows),
        "deterministic_passed": sum(1 for r in rows if r.deterministic_pass and not r.request_meta.get("skipped")),
        "deterministic_failed": sum(1 for r in rows if not r.deterministic_pass and not r.request_meta.get("skipped")),
        "skipped": sum(1 for r in rows if r.request_meta.get("skipped")),
        "latency": summarize_latency(rows),
        "server_latency": summarize_server_latency(rows),
        "groups": summarize_groups(rows),
        "tool_coverage": {
            "calls": sum(r.tool_calls for r in rows),
            "tools": sorted({tool for r in rows for tool in r.selected_tools}),
            "by_tool": dict(Counter(tool for r in rows for tool in r.selected_tools)),
        },
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
            "ground_truth": r.case.get("ground_truth", ""),
            "answer": r.answer,
            "contexts": r.contexts,
            "products": r.products,
            "knowledge_sources": r.knowledge_sources,
            "latency_ms": r.latency_ms,
            "request_meta": r.request_meta,
            "server_latency": r.server_latency,
            "selected_tools": r.selected_tools,
            "tool_calls": r.tool_calls,
            "execution_trace": r.execution_trace,
            "evaluation_group": r.evaluation_group,
            "response_type": r.case.get("actual_response_type"),
            "intent": r.case.get("actual_intent"),
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
    if csv_path:
        csv_path.parent.mkdir(parents=True, exist_ok=True)
        fields = [
            "scenario_id", "turn_id", "turn_index", "id", "type", "evaluation_group",
            "question", "latency_ms", "server_total_ms", "status_code", "attempts",
            "tool_calls", "selected_tools", "products_count", "knowledge_sources_count",
            "contexts_count", "response_type", "intent", "deterministic_pass", "failures",
        ]
        with csv_path.open("w", encoding="utf-8", newline="") as fh:
            writer = csv.DictWriter(fh, fieldnames=fields)
            writer.writeheader()
            for row in details:
                writer.writerow({
                    "scenario_id": row["scenario_id"], "turn_id": row["turn_id"],
                    "turn_index": row["turn_index"], "id": row["id"], "type": row["type"],
                    "evaluation_group": row["evaluation_group"], "question": row["question"],
                    "latency_ms": row["latency_ms"],
                    "server_total_ms": row["server_latency"].get("total_ms"),
                    "status_code": row["request_meta"].get("status_code"),
                    "attempts": row["request_meta"].get("attempts"),
                    "tool_calls": row["tool_calls"],
                    "selected_tools": ",".join(row["selected_tools"]),
                    "products_count": row["products_count"],
                    "knowledge_sources_count": row["knowledge_sources_count"],
                    "contexts_count": row["contexts_count"],
                    "response_type": row["response_type"], "intent": row["intent"],
                    "deterministic_pass": row["deterministic_pass"],
                    "failures": "; ".join(row["failures"]),
                })
    return output


def write_markdown_report(report: dict[str, Any], output_path: Path, base_url: str, cases_path: str) -> None:
    summary = report["summary"]
    latency = summary.get("latency", {})
    ragas = summary.get("ragas") or {}
    details = report.get("details", [])
    lines = [
        "# Báo Cáo Chatbot Multi-Step RAGAS + Langfuse",
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
        f"| Skipped | `{summary.get('skipped', 0)}` |",
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
        "### RAGAS theo nhóm",
        "",
        "| Group | Samples | Grounded | Answer relevancy | Faithfulness | Context precision | Context recall |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for group, values in (ragas.get("groups") or {}).items():
        lines.append(
            f"| `{group}` | `{values.get('samples', 0)}` | `{values.get('grounded_samples', 0)}` | "
            f"`{values.get('answer_relevancy', 'n/a')}` | `{values.get('faithfulness', 'n/a')}` | "
            f"`{values.get('context_precision', 'n/a')}` | `{values.get('context_recall', 'n/a')}` |"
        )
    lines.extend([
        "",
        "Notes:",
    ])
    for note in ragas.get("_notes", []):
        lines.append(f"- {note}")
    if not ragas.get("_notes"):
        lines.append("- Không có ghi chú RAGAS.")
    lines.extend([
        "",
        "## Langfuse",
        "",
        "- Tracing bật khi `LANGFUSE_PUBLIC_KEY` và `LANGFUSE_SECRET_KEY` được cấu hình.",
        f"- Host: `{os.getenv('LANGFUSE_BASE_URL', 'http://localhost:3000')}`.",
        f"- Project: `{os.getenv('LANGFUSE_PROJECT', 'fashion-shop-chatbot-eval')}`.",
        "- Keys không được ghi vào report hoặc source.",
        "",
        "## Chi Tiết Turn",
        "",
        "| Scenario | Turn | Type | HTTP latency | Server total | Tools | Products | Knowledge | Result | Failures |",
        "| --- | ---: | --- | ---: | ---: | --- | ---: | ---: | --- | --- |",
    ])
    for item in details:
        status = "PASS" if item.get("deterministic_pass") else "FAIL"
        failures = "; ".join(item.get("failures", []))
        lines.append(
            f"| `{item.get('scenario_id')}` | `{item.get('turn_index')}` | `{item.get('type')}` | "
            f"`{item.get('latency_ms')} ms` | `{item.get('server_latency', {}).get('total_ms', 'n/a')} ms` | "
            f"`{','.join(item.get('selected_tools', [])) or '-'}` | `{item.get('products_count')}` | "
            f"`{item.get('knowledge_sources_count')}` | "
            f"`{status}` | {failures or '-'} |"
        )
    lines.extend([
        "",
        "## Bảo Mật",
        "",
        "- Không ghi `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`, `LLM_API_KEY`, `OPENAI_API_KEY` hoặc HuggingFace token vào file.",
        "- HuggingFace embedding model chạy local public model; không dùng HuggingFace Inference API.",
        "",
    ])
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text("\n".join(lines), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default=os.getenv("CHATBOT_BASE_URL", "http://localhost"))
    parser.add_argument("--cases", action="append", default=None, help="JSONL case file; repeat for multiple files")
    parser.add_argument("--output", default="reports/chatbot_eval_report.json")
    parser.add_argument("--csv-output", default="reports/chatbot_eval_report.csv")
    parser.add_argument("--input-report", default="", help="Reuse a completed JSON report for offline RAGAS")
    parser.add_argument("--markdown-output", default="")
    parser.add_argument("--timeout", type=float, default=90.0)
    parser.add_argument("--turn-delay", type=float, default=float(os.getenv("EVAL_TURN_DELAY", "0")))
    parser.add_argument("--max-retries", type=int, default=int(os.getenv("EVAL_MAX_RETRIES", "3")))
    parser.add_argument("--max-turns", type=int, default=0, help="Run only the first N turns (keeps scenario order)")
    args = parser.parse_args()

    if args.input_report:
        case_paths = []
        rows = rows_from_report(Path(args.input_report))
        if len(rows) != 50:
            raise SystemExit(f"Expected exactly 50 rows in input report, found {len(rows)}")
    else:
        case_paths = [Path(path) for path in (args.cases or [
            "eval/chatbot_multistep_eval_cases.jsonl",
            "eval/chatbot_positive_eval_cases.jsonl",
        ])]
        cases = load_cases(case_paths)
        expected_turns = sum(len(iter_turns(case)) for case in cases)
        target_turns = args.max_turns or 50
        if expected_turns < target_turns:
            raise SystemExit(f"Requested {target_turns} turns, but case files contain only {expected_turns}")
        if args.max_turns:
            selected_cases = []
            remaining = args.max_turns
            for case in cases:
                if remaining <= 0:
                    break
                turns = iter_turns(case)
                selected = dict(case)
                selected["turns"] = turns[:remaining]
                selected_cases.append(selected)
                remaining -= len(selected["turns"])
            cases = selected_cases
        else:
            if expected_turns != 50:
                raise SystemExit(f"Expected exactly 50 turns, found {expected_turns} in {len(case_paths)} case files")
        rows = run_cases(args.base_url, cases, args.timeout, args.turn_delay, args.max_retries)
    ragas_result = run_ragas(rows)
    report = write_report(rows, ragas_result, Path(args.output), Path(args.csv_output))
    flush_langfuse()
    if args.markdown_output:
        source = args.input_report or ", ".join(str(path) for path in case_paths)
        write_markdown_report(report, Path(args.markdown_output), args.base_url, source)

    print(json.dumps(report["summary"], ensure_ascii=False, indent=2))
    for item in report["details"]:
        status = "PASS" if item["deterministic_pass"] else "FAIL"
        print(f"{status} {item['id']} latency={item['latency_ms']}ms failures={item['failures']}")
    return 0 if report["summary"]["deterministic_failed"] == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
