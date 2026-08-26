# ============================================================
# Reranker Sidecar — Lightweight TF-IDF (scikit-learn)
# No PyTorch, no deep learning, < 300 MB total image
# Character n-gram TF-IDF for Vietnamese text matching
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

# Copy only the dependency manifest — layer cached until pyproject.toml changes
COPY pyproject.toml ./

# Install minimal deps into /install prefix (no torch, no sentence-transformers)
RUN uv pip install \
    --prefix=/install \
    "fastapi==0.115.6" \
    "uvicorn==0.34.0" \
    "scikit-learn==1.6.1" \
    "numpy==1.26.4"

# ── Stage 2: Minimal runtime image ────────────────────────
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=0

# Runtime certificates only; healthcheck uses Python stdlib to avoid curl/tini
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Copy installed packages from the build stage (no uv in runtime)
COPY --from=python-deps /install /usr/local

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health', timeout=3).read()" || exit 1

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
