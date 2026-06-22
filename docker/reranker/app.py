"""
Reranker sidecar — TF-IDF with character n-grams (scikit-learn).
Lightweight Vietnamese text reranker, < 300 MB total image.

API contract (backward-compatible):
  POST /rerank  { query, texts[] } → { scores[], sorted_indices[], elapsed_ms }
  GET  /health                      → { status, model, loaded }
"""
import time
import re

import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from sklearn.feature_extraction.text import TfidfVectorizer

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
MAX_TEXTS = 100
VECTORIZER_KWARGS = dict(
    analyzer="char",
    ngram_range=(2, 4),
    max_features=50000,
    lowercase=True,
    sublinear_tf=True,       # use 1 + log(tf)
)

app = FastAPI(title="Reranker", version="3.0-tfidf")

# Global vectorizer (fit on each request — small corpus, fast)
# No model loading needed, so we're ready instantly
_ready = True
_start_time = time.time()


# ---------------------------------------------------------------------------
# Pydantic schemas
# ---------------------------------------------------------------------------
class RerankRequest(BaseModel):
    query: str
    texts: list[str]

class RerankResponse(BaseModel):
    scores: list[float]
    sorted_indices: list[int]
    elapsed_ms: int


# ---------------------------------------------------------------------------
# Vietnamese-aware text preprocessing
# ---------------------------------------------------------------------------
def _preprocess(text: str) -> str:
    """Normalize Vietnamese text: lowercase, collapse whitespace."""
    text = text.lower().strip()
    # Remove special chars but keep Vietnamese letters
    text = re.sub(r"[^\w\sàáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------
@app.get("/health")
def health():
    return {
        "status": "ok",
        "model": "TF-IDF char-ngram (2-4)",
        "loaded": True,
        "elapsed_s": round(time.time() - _start_time, 1),
    }


# ---------------------------------------------------------------------------
# Rerank endpoint
# ---------------------------------------------------------------------------
@app.post("/rerank", response_model=RerankResponse)
def rerank(req: RerankRequest) -> RerankResponse:
    n = len(req.texts)
    if n == 0:
        return RerankResponse(scores=[], sorted_indices=[], elapsed_ms=0)
    if n > MAX_TEXTS:
        raise HTTPException(status_code=400, detail=f"Max {MAX_TEXTS} texts")

    t0 = time.perf_counter()

    # Preprocess
    query_pp = _preprocess(req.query)
    texts_pp = [_preprocess(t) for t in req.texts]

    # Build TF-IDF on the fly (small corpus, fast)
    all_docs = [query_pp] + texts_pp
    vectorizer = TfidfVectorizer(**VECTORIZER_KWARGS)
    tfidf_matrix = vectorizer.fit_transform(all_docs)

    # Cosine similarity: query (row 0) vs each text (rows 1..n)
    query_vec = tfidf_matrix[0:1]
    doc_vecs = tfidf_matrix[1:]
    similarities = (query_vec @ doc_vecs.T).toarray().flatten()

    # Fallback: if all zeros (no common n-grams), use exact word overlap
    if similarities.max() < 1e-9:
        scores = _word_overlap(req.query, req.texts)
    else:
        scores = similarities.tolist()

    # Sort descending
    sorted_indices = sorted(range(n), key=lambda i: scores[i], reverse=True)

    elapsed = int((time.perf_counter() - t0) * 1000)
    return RerankResponse(scores=scores, sorted_indices=sorted_indices, elapsed_ms=elapsed)


# ---------------------------------------------------------------------------
# Word overlap fallback (for short queries with no common n-grams)
# ---------------------------------------------------------------------------
def _word_overlap(query: str, texts: list[str]) -> list[float]:
    """Jaccard-like word overlap."""
    q_words = set(query.lower().split())
    scores = []
    for t in texts:
        tw = set(t.lower().split())
        if not q_words and not tw:
            scores.append(0.0)
        elif not q_words or not tw:
            scores.append(0.0)
        else:
            scores.append(len(q_words & tw) / max(len(q_words | tw), 1))
    return scores


# ---------------------------------------------------------------------------
# Entry
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
