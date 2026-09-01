#!/usr/bin/env python3
"""Build the optional legacy 70-case combined report.

The release gate is now ``run_findmine_agent_eval.php --cases=50``; this
utility remains for historical comparisons that combine an HTTP report with
20 selected styling rows and is not used by the production runtime.
"""
from __future__ import annotations

import argparse
import json
import math
import statistics
from pathlib import Path
from typing import Any


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--chatbot-report", required=True, type=Path)
    parser.add_argument("--styling-report", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()

    chatbot = read_json(args.chatbot_report)
    styling = read_json(args.styling_report)
    chatbot_rows = chatbot.get("details", [])
    if len(chatbot_rows) != 50:
        raise SystemExit(f"Expected 50 chatbot HTTP turns, found {len(chatbot_rows)}")

    styling_rows = styling.get("results", [])
    selected_styling = [
        row for row in styling_rows
        if row.get("case_id", "").startswith(("explicit-", "proactive-"))
        and int(str(row["case_id"]).split("-")[-1]) <= 10
    ]
    if len(selected_styling) != 20:
        raise SystemExit(f"Expected 20 styling cases, found {len(selected_styling)}")

    normalized_chatbot = [
        {
            "case_id": row.get("id"),
            "use_case": row.get("evaluation_group"),
            "question": row.get("question"),
            "boundary": "HTTP_NGINX_CHATBOT_SERVICE",
            "latency_ms": row.get("latency_ms"),
            "passed": bool(row.get("deterministic_pass")),
            "failures": row.get("failures", []),
        }
        for row in chatbot_rows
    ]
    normalized_styling = [
        {
            "case_id": row.get("case_id"),
            "use_case": row.get("use_case"),
            "question": row.get("question"),
            "boundary": "STYLING_PROVIDER_TO_PRODUCT_SEARCH",
            "latency_ms": row.get("pipeline", {}).get("total_case_ms"),
            "passed": bool(row.get("passed")),
            "failures": row.get("failures", []),
        }
        for row in selected_styling
    ]
    styling_latencies: dict[str, list[float]] = {"explicit": [], "proactive": []}
    stage_latencies: dict[str, list[float]] = {}
    for row in selected_styling:
        class_name = str(row.get("pipeline", {}).get("class", ""))
        duration = row.get("pipeline", {}).get("total_case_ms")
        if class_name in styling_latencies and isinstance(duration, (int, float)):
            styling_latencies[class_name].append(float(duration))
        for stage, value in row.get("pipeline", {}).get("stage_latency_ms", {}).items():
            if isinstance(value, (int, float)):
                stage_latencies.setdefault(str(stage), []).append(float(value))
    results = normalized_chatbot + normalized_styling
    passed = sum(bool(row["passed"]) for row in results)
    payload: dict[str, Any] = {
        "status": "PASS" if passed == 70 else "FAIL",
        "cases": 70,
        "passed": passed,
        "failed": 70 - passed,
        "coverage": sorted({str(row["use_case"]) for row in results}),
        "latency_ms": {
            "chatbot_http_50": chatbot.get("summary", {}).get("latency", {}),
            "styling_pipeline_20": {
                "explicit": latency_summary(styling_latencies["explicit"]),
                "proactive": latency_summary(styling_latencies["proactive"]),
                "pipeline_stages": {stage: latency_summary(values) for stage, values in stage_latencies.items()},
            },
            "note": "Boundaries are reported separately; HTTP and direct styling timings are not averaged together.",
        },
        "sources": {
            "chatbot_http": str(args.chatbot_report),
            "styling": str(args.styling_report),
        },
        "results": results,
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({key: payload[key] for key in ("status", "cases", "passed", "failed", "coverage", "latency_ms")}, ensure_ascii=False, indent=2))
    return 0 if payload["status"] == "PASS" else 1


def read_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise SystemExit(f"Expected JSON object in {path}")
    return value


def latency_summary(values: list[float]) -> dict[str, float | int]:
    ordered = sorted(values)
    if not ordered:
        return {"count": 0, "min": 0, "avg": 0, "p50": 0, "p95": 0, "max": 0}
    p95_index = max(0, math.ceil(len(ordered) * 0.95) - 1)
    return {
        "count": len(ordered),
        "min": round(ordered[0], 2),
        "avg": round(statistics.fmean(ordered), 2),
        "p50": round(statistics.median(ordered), 2),
        "p95": round(ordered[p95_index], 2),
        "max": round(ordered[-1], 2),
    }


if __name__ == "__main__":
    raise SystemExit(main())
