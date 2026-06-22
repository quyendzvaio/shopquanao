# ============================================================
# Reranker Sidecar — Lightweight TF-IDF (scikit-learn)
# No PyTorch, no deep learning, < 300 MB total image
# Character n-gram TF-IDF for Vietnamese text matching
# ============================================================

FROM python:3.12-slim

# Tini for clean signal handling
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
    tini curl ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# Minimal deps — no torch, no sentence-transformers, no onnxruntime
RUN pip install --no-cache-dir \
    fastapi==0.115.6 \
    uvicorn==0.34.0 \
    scikit-learn==1.6.1 \
    numpy==1.26.4

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -sf http://localhost:8000/health | grep -q '"status":"ok"' || exit 1

ENTRYPOINT ["tini", "--"]
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
