"""Shared helpers for RAGAS evaluation scripts."""
from __future__ import annotations

import math
import os
from typing import Any


def json_safe(value: Any) -> Any:
    """Convert RAGAS/pandas values into strict JSON-safe data."""
    if isinstance(value, float):
        return value if math.isfinite(value) else None
    if isinstance(value, dict):
        return {str(key): json_safe(item) for key, item in value.items()}
    if isinstance(value, list):
        return [json_safe(item) for item in value]
    if hasattr(value, "tolist"):
        return json_safe(value.tolist())
    if hasattr(value, "item"):
        try:
            return json_safe(value.item())
        except Exception:  # noqa: BLE001
            pass
    return value


def build_evaluator_llm(
    chat_openai_cls: type,
    langchain_llm_wrapper_cls: type,
    default_model: str = "deepseek-v4-flash",
) -> tuple[Any, str, list[str]]:
    """Build an explicit RAGAS judge LLM from env without falling back implicitly.

    DeepSeek-compatible endpoints currently support only n=1. RAGAS can request
    n>1 for some metrics, so the wrapper fans those requests out into repeated
    single-completion calls.
    """
    evaluator_model = os.getenv("OPENAI_EVAL_MODEL") or default_model
    prefer_llm_provider = evaluator_model.startswith("deepseek") or bool(os.getenv("LLM_BASE_URL"))

    if prefer_llm_provider:
        api_key = os.getenv("LLM_API_KEY") or os.getenv("OPENAI_API_KEY")
        base_url = os.getenv("LLM_BASE_URL") or os.getenv("OPENAI_BASE_URL")
    else:
        api_key = os.getenv("OPENAI_API_KEY") or os.getenv("LLM_API_KEY")
        base_url = os.getenv("OPENAI_BASE_URL") or os.getenv("LLM_BASE_URL")

    if not api_key:
        raise RuntimeError("RAGAS needs OPENAI_API_KEY or LLM_API_KEY for evaluator LLM.")

    if base_url:
        base_url = base_url.rstrip("/")
        if not base_url.endswith("/v1"):
            base_url += "/v1"
        os.environ.setdefault("OPENAI_API_BASE", base_url)
        os.environ.setdefault("OPENAI_BASE_URL", base_url)
    os.environ.setdefault("OPENAI_API_KEY", api_key)

    chat_kwargs: dict[str, Any] = {
        "api_key": api_key,
        "model": evaluator_model,
        "temperature": 0,
        "timeout": float(os.getenv("LLM_TIMEOUT") or 60),
    }
    if base_url:
        chat_kwargs["base_url"] = base_url

    raw_llm = chat_openai_cls(**chat_kwargs)
    notes: list[str] = []
    if not prefer_llm_provider:
        return raw_llm, evaluator_model, notes

    class SingleCompletionFanOutLLM(langchain_llm_wrapper_cls):
        """Emulate multiple completions for providers that only accept n=1."""

        def generate_text(self, prompt, n=1, temperature=None, stop=None, callbacks=None):
            if n <= 1:
                return super().generate_text(prompt, n, temperature, stop, callbacks)
            result = self.langchain_llm.generate_prompt(
                prompts=[prompt] * n,
                n=1,
                temperature=temperature,
                stop=stop,
                callbacks=callbacks,
            )
            result.generations = [[generation[0] for generation in result.generations]]
            return result

        async def agenerate_text(self, prompt, n=1, temperature=None, stop=None, callbacks=None):
            if n <= 1:
                return await super().agenerate_text(prompt, n, temperature, stop, callbacks)
            result = await self.langchain_llm.agenerate_prompt(
                prompts=[prompt] * n,
                n=1,
                temperature=temperature,
                stop=stop,
                callbacks=callbacks,
            )
            result.generations = [[generation[0] for generation in result.generations]]
            return result

    notes.append(f"DeepSeek n=1 fan-out is enabled for evaluator model {evaluator_model}.")
    return SingleCompletionFanOutLLM(raw_llm), evaluator_model, notes
