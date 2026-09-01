#!/usr/bin/env python3
"""Publish a sanitized live Glance evaluation to self-hosted Langfuse.

This is intentionally an explicit operator command.  It never sends raw MCP
payloads, OAuth material, credentials, or full application responses.  The
dataset contains only the recommendation question, final answer, and the
private Product Search contexts used for grounding.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import uuid
from pathlib import Path
from typing import Any


DEFAULT_REPORT = Path("reports/eval/glance_agent_eval_50_live_after_fix_20260830.json")
DEFAULT_DATASET = "shopquanao-glance-live-20260830"
DEFAULT_EXPERIMENT = "shopquanao-glance-live-eval-20260830"


def load_recommendation_cases(path: Path) -> list[dict[str, Any]]:
    report = json.loads(path.read_text(encoding="utf-8"))
    if report.get("provider_mode") != "glance_live":
        raise ValueError("refusing to publish a non-live Glance report")
    cases = report.get("ragas_cases")
    if not isinstance(cases, list):
        raise ValueError("report has no sanitized ragas_cases list")

    sanitized: list[dict[str, Any]] = []
    for case in cases:
        if not isinstance(case, dict):
            continue
        case_id = str(case.get("case_id", "")).strip()
        question = str(case.get("question", "")).strip()
        answer = str(case.get("answer", "")).strip()
        contexts = case.get("contexts")
        if not case_id or not question or not answer or not isinstance(contexts, list):
            continue
        # Contexts are the private Product Search evidence, not provider data.
        safe_contexts = [str(item)[:2000] for item in contexts[:20] if str(item).strip()]
        sanitized.append(
            {
                "case_id": case_id,
                "question": question[:1000],
                "answer": answer[:4000],
                "contexts": safe_contexts,
            }
        )
    if not sanitized:
        raise ValueError("report contains no publishable recommendation cases")
    return sanitized


def stable_item_id(case_id: str) -> str:
    # UUID5 keeps reruns idempotent without persisting source identifiers.
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"shopquanao:glance:{case_id}"))


def publish(cases: list[dict[str, Any]], dataset_name: str, experiment_name: str) -> dict[str, Any]:
    try:
        from langfuse import get_client
    except Exception as exc:  # pragma: no cover - depends on operator env
        raise RuntimeError("install eval/requirements-eval.txt before publishing") from exc

    if not os.getenv("LANGFUSE_PUBLIC_KEY") or not os.getenv("LANGFUSE_SECRET_KEY"):
        raise RuntimeError("LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY are required")

    client = get_client()
    project = os.getenv("LANGFUSE_PROJECT", "fashion-shop-chatbot-eval")
    try:
        client.create_dataset(
            name=dataset_name,
            description="Sanitized live Glance recommendations grounded by private Product Search.",
            metadata={"source": "post_run_evaluation_report", "provider_mode": "glance_live", "project": project},
        )
    except Exception as exc:
        # A rerun commonly receives an already-existing dataset.  Dataset item
        # creation remains idempotent via stable UUIDs, so continue only for a
        # documented duplicate/existing error.
        if "exist" not in str(exc).lower() and "duplicate" not in str(exc).lower():
            raise RuntimeError("Langfuse dataset creation failed") from exc

    item_count = 0
    observation_count = 0
    for case in cases:
        item_input = {"question": case["question"], "contexts": case["contexts"]}
        item_output = {"answer": case["answer"]}
        try:
            client.create_dataset_item(
                id=stable_item_id(case["case_id"]),
                dataset_name=dataset_name,
                input=item_input,
                expected_output=item_output,
                metadata={
                    "source": "post_run_evaluation_report",
                    "provider_mode": "glance_live",
                    "case_id": case["case_id"],
                },
            )
            item_count += 1
        except Exception as exc:
            if "exist" not in str(exc).lower() and "duplicate" not in str(exc).lower():
                raise RuntimeError("Langfuse dataset item creation failed") from exc

        # Observations are the experiment run evidence.  Only bounded metadata
        # and the final answer are sent; no provider/MCP payload is included.
        with client.start_as_current_observation(
            as_type="chain",
            name="glance-live-evaluation",
            input={"question": case["question"]},
        ) as observation:
            observation.update(
                output=item_output,
                metadata={
                    "dataset": dataset_name,
                    "experiment": experiment_name,
                    "project": project,
                    "case_id": case["case_id"],
                    "provider_mode": "glance_live",
                    "grounding_context_count": len(case["contexts"]),
                },
            )
            observation_count += 1

    client.flush()
    return {
        "langfuse_base_url": os.getenv("LANGFUSE_BASE_URL", "http://localhost:3000"),
        "project": project,
        "dataset": dataset_name,
        "experiment": experiment_name,
        "dataset_items_created": item_count,
        "observations_created": observation_count,
        "source": "post_run_evaluation_report",
        "provider_mode": "glance_live",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--dataset-name", default=DEFAULT_DATASET)
    parser.add_argument("--experiment-name", default=DEFAULT_EXPERIMENT)
    parser.add_argument("--dry-run", action="store_true", help="validate/summarize without importing the SDK or sending data")
    args = parser.parse_args()

    cases = load_recommendation_cases(args.report)
    if args.dry_run:
        print(json.dumps({"provider_mode": "glance_live", "cases": len(cases), "dataset": args.dataset_name, "experiment": args.experiment_name}, indent=2))
        return 0

    try:
        result = publish(cases, args.dataset_name, args.experiment_name)
    except Exception as exc:  # keep operator output concise and secret-safe
        print(f"Langfuse publish failed: {type(exc).__name__}", file=sys.stderr)
        return 1
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
