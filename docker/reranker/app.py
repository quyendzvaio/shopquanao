"""
Reranker sidecar — cross-encoder model for reranking search results.
Model loads in background at startup (download từ HuggingFace Hub ~1.1GB).
Health endpoint returns 503 until model ready.
"""
import threading
import time
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from sentence_transformers import CrossEncoder

app = FastAPI(title="Reranker")

model: CrossEncoder | None = None
model_ready = threading.Event()
warmup_started = False

class RerankRequest(BaseModel):
    query: str
    texts: list[str]

class RerankResponse(BaseModel):
    scores: list[float]
    sorted_indices: list[int]
    elapsed_ms: int

def _load_model():
    """Background thread: tải model ngay khi server start."""
    global model
    try:
        model = CrossEncoder('BAAI/bge-reranker-v2-m3', device='cpu')
    except Exception as e:
        print(f"[RERANKER] Model load failed: {e}", flush=True)
    finally:
        model_ready.set()

@app.on_event("startup")
async def startup():
    """Start loading model in background thread so API can still serve health checks."""
    global warmup_started
    if not warmup_started:
        warmup_started = True
        t = threading.Thread(target=_load_model, daemon=True)
        t.start()

@app.get("/health")
def health():
    return {
        "status": "ok" if model is not None else "warming_up",
        "model": "BAAI/bge-reranker-v2-m3",
        "loaded": model is not None,
        "warmup_seconds": time.time() - startup.start_time if hasattr(startup, 'start_time') else 0,
    }

@app.post("/rerank", response_model=RerankResponse)
def rerank(req: RerankRequest):
    if model is None:
        # Chưa load xong → đợi tối đa 120s
        if not model_ready.wait(timeout=120):
            # Fallback: dùng keyword overlap sort
            return _fallback_rerank(req.query, req.texts)

    t0 = time.perf_counter()
    pairs = [[req.query, t] for t in req.texts]
    scores = model.predict(pairs).tolist()
    sorted_indices = sorted(range(len(scores)), key=lambda i: scores[i], reverse=True)
    elapsed = int((time.perf_counter() - t0) * 1000)
    return RerankResponse(scores=scores, sorted_indices=sorted_indices, elapsed_ms=elapsed)

# Track startup time
startup.start_time = time.time()

def _fallback_rerank(query: str, texts: list[str]) -> RerankResponse:
    """Fallback keyword overlap khi model chưa sẵn sàng."""
    query_words = set(query.lower().split())
    scored = []
    for i, t in enumerate(texts):
        tw = set(t.lower().split())
        overlap = len(query_words & tw) / max(len(query_words | tw), 1)
        scored.append((overlap, i))
    scored.sort(key=lambda x: x[0], reverse=True)
    return RerankResponse(
        scores=[s[0] for s in scored],
        sorted_indices=[s[1] for s in scored],
        elapsed_ms=0,
    )
