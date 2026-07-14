import math
import os
import time
from functools import lru_cache
from typing import Any

import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from sentence_transformers import CrossEncoder, SentenceTransformer


EMBEDDING_MODEL = os.getenv(
    "EMBEDDING_MODEL",
    "bkai-foundation-models/vietnamese-bi-encoder",
)
RERANKER_MODEL = os.getenv("KNOWLEDGE_RERANKER_MODEL", "itdainb/PhoRanker")
DEVICE = os.getenv("RAG_ML_DEVICE", "cpu")
MAX_TEXTS = int(os.getenv("RAG_ML_MAX_TEXTS", "64"))
MAX_RERANK_TEXTS = int(os.getenv("RAG_ML_MAX_RERANK_TEXTS", "32"))
WARMUP_ON_START = os.getenv("RAG_ML_WARMUP_ON_START", "true").lower() in {"1", "true", "yes", "on"}

app = FastAPI(title="Knowledge RAG ML Service", version="1.0.0")
_start_time = time.time()
_warmup_elapsed_ms: int | None = None


class EmbedRequest(BaseModel):
    texts: list[str] = Field(default_factory=list)


class EmbedResponse(BaseModel):
    model: str
    dim: int
    embeddings: list[list[float]]
    elapsed_ms: int


class RerankRequest(BaseModel):
    query: str
    texts: list[str] = Field(default_factory=list)


class RerankResponse(BaseModel):
    model: str
    scores: list[float]
    sorted_indices: list[int]
    elapsed_ms: int


@lru_cache(maxsize=1)
def embedding_model() -> SentenceTransformer:
    return SentenceTransformer(EMBEDDING_MODEL, device=DEVICE)


@lru_cache(maxsize=1)
def reranker_model() -> CrossEncoder:
    return CrossEncoder(RERANKER_MODEL, device=DEVICE)


@app.on_event("startup")
def warmup_models() -> None:
    global _warmup_elapsed_ms
    if not WARMUP_ON_START:
        return

    started = time.perf_counter()
    embedding_model()
    reranker_model()
    _warmup_elapsed_ms = int((time.perf_counter() - started) * 1000)


def _clean_texts(texts: list[str], limit: int) -> list[str]:
    cleaned = [str(text or "").strip() for text in texts]
    cleaned = [text for text in cleaned if text]
    if len(cleaned) > limit:
        raise HTTPException(status_code=400, detail=f"Max {limit} texts")
    return cleaned


def _to_float(value: Any) -> float:
    if isinstance(value, (list, tuple, np.ndarray)):
        if len(value) == 0:
            return 0.0
        value = value[0]
    score = float(value)
    if math.isnan(score) or math.isinf(score):
        return 0.0
    return score


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "status": "ok",
        "embedding_model": EMBEDDING_MODEL,
        "reranker_model": RERANKER_MODEL,
        "device": DEVICE,
        "warmup_on_start": WARMUP_ON_START,
        "warmup_elapsed_ms": _warmup_elapsed_ms,
        "embedding_model_loaded": embedding_model.cache_info().currsize > 0,
        "reranker_model_loaded": reranker_model.cache_info().currsize > 0,
        "elapsed_s": round(time.time() - _start_time, 1),
    }


@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest) -> EmbedResponse:
    texts = _clean_texts(req.texts, MAX_TEXTS)
    if not texts:
        return EmbedResponse(model=EMBEDDING_MODEL, dim=0, embeddings=[], elapsed_ms=0)

    started = time.perf_counter()
    vectors = embedding_model().encode(
        texts,
        normalize_embeddings=True,
        convert_to_numpy=True,
        show_progress_bar=False,
    )
    elapsed_ms = int((time.perf_counter() - started) * 1000)
    embeddings = [[float(v) for v in row] for row in vectors]
    dim = len(embeddings[0]) if embeddings else 0
    return EmbedResponse(
        model=EMBEDDING_MODEL,
        dim=dim,
        embeddings=embeddings,
        elapsed_ms=elapsed_ms,
    )


@app.post("/rerank", response_model=RerankResponse)
def rerank(req: RerankRequest) -> RerankResponse:
    query = str(req.query or "").strip()
    texts = _clean_texts(req.texts, MAX_RERANK_TEXTS)
    if query == "":
        raise HTTPException(status_code=400, detail="query is required")
    if not texts:
        return RerankResponse(model=RERANKER_MODEL, scores=[], sorted_indices=[], elapsed_ms=0)

    started = time.perf_counter()
    pairs = [[query, text] for text in texts]
    raw_scores = reranker_model().predict(pairs, show_progress_bar=False)
    scores = [_to_float(score) for score in raw_scores]
    sorted_indices = sorted(range(len(scores)), key=lambda idx: scores[idx], reverse=True)
    elapsed_ms = int((time.perf_counter() - started) * 1000)
    return RerankResponse(
        model=RERANKER_MODEL,
        scores=scores,
        sorted_indices=sorted_indices,
        elapsed_ms=elapsed_ms,
    )
