"""Optional, failure-isolated Langfuse tracing helpers for evaluations."""
from __future__ import annotations

import os
from functools import wraps
from typing import Any, Callable, TypeVar

F = TypeVar("F", bound=Callable[..., Any])


def tracing_enabled() -> bool:
    return bool(os.getenv("LANGFUSE_PUBLIC_KEY") and os.getenv("LANGFUSE_SECRET_KEY"))


def maybe_traceable(fn: F) -> F:
    """Wrap a function in a Langfuse observation when credentials are present.

    Import and network failures never affect the evaluation itself. Inputs and
    outputs are intentionally reduced to metadata-safe summaries by callers.
    """
    if not tracing_enabled():
        return fn
    try:
        from langfuse import get_client
    except Exception:
        return fn

    @wraps(fn)
    def wrapped(*args: Any, **kwargs: Any) -> Any:
        # Execute the evaluated operation exactly once.  Tracing is attached
        # after the result is available so a Langfuse outage can never replay
        # a chatbot HTTP request or another side effect.
        result = fn(*args, **kwargs)
        try:
            client = get_client()
            with client.start_as_current_observation(as_type="tool", name=fn.__name__) as observation:
                observation.update(output=safe_output(result))
        except Exception:
            # Langfuse is observability-only and must not change evaluator truth.
            pass
        return result

    return wrapped  # type: ignore[return-value]


def safe_output(value: Any) -> Any:
    if isinstance(value, dict):
        return {
            key: safe_output(item)
            for key, item in value.items()
            if not any(marker in str(key).lower() for marker in ("authorization", "token", "secret", "api_key", "password", "cookie"))
        }
    if isinstance(value, list):
        return [safe_output(item) for item in value[:20]]
    if isinstance(value, (str, int, float, bool)) or value is None:
        return value if not isinstance(value, str) or len(value) <= 500 else value[:500]
    return str(value)[:500]


def flush() -> None:
    if not tracing_enabled():
        return
    try:
        from langfuse import get_client

        get_client().flush()
    except Exception:
        return
