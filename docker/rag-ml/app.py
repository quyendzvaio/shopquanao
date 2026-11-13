"""Knowledge RAG ML service — ONNX Runtime, FP16 weights, no PyTorch at runtime.

The models are exported at image build time (`export_onnx_fp16.py`) into $MODELS_DIR:
  bi-encoder-fp16/{model.onnx, tokenizer files}
  phoranker-fp16/{model.onnx, tokenizer files}

API is unchanged from the PyTorch version:
  POST /embed   {texts[]}          -> {model, dim, embeddings[][], elapsed_ms}
  POST /rerank  {query, texts[]}   -> {model, scores[], sorted_indices[], elapsed_ms}
  GET  /health                     -> readiness + model info + warmup/keepwarm timings

Behaviour kept from the previous implementation: warmup on start, an optional keep-warm loop
(weights are mmap'd by ORT, but a periodic tiny inference keeps the pages hot), normalised
embeddings, and NaN/Inf scores coerced to 0.0.
"""
import math
import os
import threading
import time
from functools import lru_cache
from typing import Any

import numpy as np
import onnxruntime as ort
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from transformers import AutoTokenizer

MODELS_DIR = os.getenv("MODELS_DIR", "/models/onnx")
EMBEDDING_DIR = os.path.join(MODELS_DIR, os.getenv("EMBEDDING_ONNX_DIR", "bi-encoder-fp16"))
RERANKER_DIR = os.path.join(MODELS_DIR, os.getenv("RERANKER_ONNX_DIR", "phoranker-fp16"))
EMBEDDING_MODEL = os.getenv("EMBEDDING_MODEL", "bkai-foundation-models/vietnamese-bi-encoder")
RERANKER_MODEL = os.getenv("KNOWLEDGE_RERANKER_MODEL", "itdainb/PhoRanker")
MAX_TEXTS = int(os.getenv("RAG_ML_MAX_TEXTS", "64"))
MAX_RERANK_TEXTS = int(os.getenv("RAG_ML_MAX_RERANK_TEXTS", "32"))
EMBEDDING_DIM = int(os.getenv("EMBEDDING_DIM", "768"))
MAX_SEQ_EMBED = int(os.getenv("RAG_ML_MAX_SEQ_EMBED", "128"))
MAX_SEQ_RERANK = int(os.getenv("RAG_ML_MAX_SEQ_RERANK", "192"))
THREADS = int(os.getenv("RAG_ML_THREADS", "0"))  # 0 = let ORT decide from the host CPU count
WARMUP_ON_START = os.getenv("RAG_ML_WARMUP_ON_START", "true").lower() in {"1", "true", "yes", "on"}
KEEPWARM_SECONDS = int(os.getenv("RAG_ML_KEEPWARM_SECONDS", "60"))

app = FastAPI(title="Knowledge RAG ML Service", version="2.0-onnx-fp16")
_start_time = time.time()
_warmup_elapsed_ms: int | None = None
_keepwarm_last_ms: int | None = None


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


def _session_options() -> ort.SessionOptions:
    so = ort.SessionOptions()
    if THREADS > 0:
        so.intra_op_num_threads = THREADS
        so.inter_op_num_threads = 1
    so.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    # ORT memory settings that matter on a small VPS: reuse buffers instead of growing arenas.
    so.enable_cpu_mem_arena = False
    return so


@lru_cache(maxsize=1)
def embedding_session() -> ort.InferenceSession:
    return ort.InferenceSession(os.path.join(EMBEDDING_DIR, "model.onnx"), _session_options())


@lru_cache(maxsize=1)
def reranker_session() -> ort.InferenceSession:
    return ort.InferenceSession(os.path.join(RERANKER_DIR, "model.onnx"), _session_options())


@lru_cache(maxsize=1)
def embedding_tokenizer():
    return AutoTokenizer.from_pretrained(EMBEDDING_DIR)


@lru_cache(maxsize=1)
def reranker_tokenizer():
    return AutoTokenizer.from_pretrained(RERANKER_DIR)


def _feed(session: ort.InferenceSession, enc: dict[str, np.ndarray]) -> dict[str, np.ndarray]:
    """Only the inputs the ONNX graph declares: PhoBERT has no token_type_ids, NLI heads do."""
    wanted = {i.name for i in session.get_inputs()}
    return {k: v.astype(np.int64) for k, v in enc.items() if k in wanted}


def _mean_pool(hidden: np.ndarray, mask: np.ndarray) -> np.ndarray:
    m = mask[..., None].astype(np.float32)
    pooled = (hidden * m).sum(axis=1) / np.clip(m.sum(axis=1), 1e-9, None)
    return pooled / np.clip(np.linalg.norm(pooled, axis=1, keepdims=True), 1e-12, None)


def embed_texts(texts: list[str]) -> np.ndarray:
    enc = embedding_tokenizer()(texts, return_tensors="np", padding=True, truncation=True, max_length=MAX_SEQ_EMBED)
    hidden = embedding_session().run(["last_hidden_state"], _feed(embedding_session(), enc))[0]
    return _mean_pool(hidden, enc["attention_mask"])


def rerank_pairs(query: str, texts: list[str]) -> np.ndarray:
    enc = reranker_tokenizer()(
        [query] * len(texts), texts, return_tensors="np", padding=True, truncation=True, max_length=MAX_SEQ_RERANK
    )
    logits = reranker_session().run(["logits"], _feed(reranker_session(), enc))[0]
    return logits.reshape(len(texts), -1)[:, 0]


@app.on_event("startup")
def warmup_models() -> None:
    global _warmup_elapsed_ms
    if not WARMUP_ON_START:
        return

    started = time.perf_counter()
    embed_texts(["giữ model sẵn sàng"])
    rerank_pairs("giữ model", ["sẵn sàng"])
    _warmup_elapsed_ms = int((time.perf_counter() - started) * 1000)
    if KEEPWARM_SECONDS > 0:
        threading.Thread(target=_keep_warm_loop, name="keep-warm", daemon=True).start()


def _keep_warm_loop() -> None:
    global _keepwarm_last_ms
    while True:
        time.sleep(KEEPWARM_SECONDS)
        try:
            started = time.perf_counter()
            embed_texts(["giữ model sẵn sàng"])
            rerank_pairs("giữ model", ["sẵn sàng"])
            _keepwarm_last_ms = int((time.perf_counter() - started) * 1000)
        except Exception:  # never let the keep-warm loop take the service down
            _keepwarm_last_ms = -1


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
        "runtime": "onnxruntime",
        "precision": "fp16",
        "embedding_model": EMBEDDING_MODEL,
        "reranker_model": RERANKER_MODEL,
        "models_dir": MODELS_DIR,
        "threads": THREADS or "auto",
        "warmup_on_start": WARMUP_ON_START,
        "warmup_elapsed_ms": _warmup_elapsed_ms,
        "keepwarm_seconds": KEEPWARM_SECONDS,
        "keepwarm_last_ms": _keepwarm_last_ms,
        "embedding_model_loaded": embedding_session.cache_info().currsize > 0,
        "reranker_model_loaded": reranker_session.cache_info().currsize > 0,
        "elapsed_s": round(time.time() - _start_time, 1),
    }


@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest) -> EmbedResponse:
    texts = _clean_texts(req.texts, MAX_TEXTS)
    if not texts:
        return EmbedResponse(model=EMBEDDING_MODEL, dim=0, embeddings=[], elapsed_ms=0)

    started = time.perf_counter()
    vectors = embed_texts(texts)
    elapsed_ms = int((time.perf_counter() - started) * 1000)
    embeddings = [[float(v) for v in row] for row in vectors]
    return EmbedResponse(
        model=EMBEDDING_MODEL,
        dim=len(embeddings[0]) if embeddings else 0,
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
    scores = [_to_float(s) for s in rerank_pairs(query, texts)]
    sorted_indices = sorted(range(len(scores)), key=lambda idx: scores[idx], reverse=True)
    elapsed_ms = int((time.perf_counter() - started) * 1000)
    return RerankResponse(
        model=RERANKER_MODEL,
        scores=scores,
        sorted_indices=sorted_indices,
        elapsed_ms=elapsed_ms,
    )
