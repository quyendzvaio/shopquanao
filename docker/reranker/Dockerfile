# ============================================================
# Reranker Sidecar — Lightweight TF-IDF (scikit-learn)
# No PyTorch, no deep learning, < 300 MB total image
# Character n-gram TF-IDF for Vietnamese text matching
# ============================================================

FROM python:3.12-slim AS python-deps

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# Minimal deps — no torch, no sentence-transformers, no onnxruntime.
# Install into /install so the final image can copy only runtime packages.
RUN pip install --prefix=/install \
    fastapi==0.115.6 \
    uvicorn==0.34.0 \
    scikit-learn==1.6.1 \
    numpy==1.26.4

FROM python:3.12-slim

# Runtime certificates only; healthcheck uses Python stdlib to avoid curl/tini.
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
    ca-certificates && \
    rm -rf /var/lib/apt/lists/*

COPY --from=python-deps /install /usr/local

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health', timeout=3).read()" || exit 1

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
