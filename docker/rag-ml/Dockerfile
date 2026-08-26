# ============================================================
# rag-ml — Knowledge RAG ML Service
# Embedding (sentence-transformers) + CrossEncoder reranker
# Managed by uv — no pip, no virtualenv overhead
# ============================================================

# ── Stage 1: Install Python dependencies with uv ───────────
FROM python:3.12-slim AS python-deps

# Copy uv binary from the official image — fastest way to get uv in Docker
COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv

ENV UV_SYSTEM_PYTHON=1 \
    UV_NO_CACHE=1 \
    UV_COMPILE_BYTECODE=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /build

# Copy only the dependency manifests so Docker caches this layer
# when only app.py changes.
COPY pyproject.toml ./

# Install to a prefix directory so the runtime stage can copy
# just the installed packages without bringing in uv itself.
RUN uv pip install \
    --prefix=/install \
    --extra-index-url https://download.pytorch.org/whl/cpu \
    "torch==2.5.1+cpu" \
    "fastapi==0.115.6" \
    "uvicorn==0.34.0" \
    "sentence-transformers==3.3.1"

# ── Stage 2: Minimal runtime image ────────────────────────
FROM python:3.12-slim

ENV HF_HOME=/models/huggingface \
    SENTENCE_TRANSFORMERS_HOME=/models/sentence-transformers \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=0

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Copy installed packages from the build stage (no uv in runtime)
COPY --from=python-deps /install /usr/local

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=5 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health', timeout=5).read()"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
