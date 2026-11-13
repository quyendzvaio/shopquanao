# ============================================================
# rag-ml — Knowledge RAG ML Service (ONNX Runtime, FP16 weights)
#
# Stage 1 exports both models to ONNX FP16 and runs a quality gate
# (fp16 vs fp32 cosine / rerank order). Stage 2 keeps ONLY onnxruntime +
# tokenizers: no torch, no transformers model code, ~1.5 GB smaller image
# and a much smaller resident set than the previous PyTorch runtime.
#
# The models live in a volume ($MODELS_DIR) so a restart does not re-export.
# ============================================================

# ── Stage 1: export models to ONNX FP16 (needs torch) ──────
FROM python:3.12-slim AS exporter

COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv

ENV UV_SYSTEM_PYTHON=1 \
    UV_NO_CACHE=1 \
    UV_COMPILE_BYTECODE=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    HF_HOME=/models/huggingface

WORKDIR /build
COPY pyproject.toml app.py export_onnx_fp16.py ./

RUN uv pip install \
      --extra-index-url https://download.pytorch.org/whl/cpu \
      "torch==2.5.1+cpu" \
      "transformers==4.46.3" \
      "onnx==1.17.0" \
      "onnxruntime==1.20.1" \
      "onnxconverter-common==1.14.0" \
      "numpy<2.0"

# Build-time export with the quality gate; a failing gate fails the image build.
RUN mkdir -p /models/onnx && python export_onnx_fp16.py /models/onnx

# ── Stage 2: runtime — ONNX Runtime only (no torch) ────────
FROM python:3.12-slim AS runtime

COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv

ENV UV_SYSTEM_PYTHON=1 \
    UV_NO_CACHE=1 \
    UV_COMPILE_BYTECODE=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONUNBUFFERED=1 \
    MODELS_DIR=/models/onnx

WORKDIR /build
COPY pyproject.toml ./

# Runtime dependencies only: tokenizers + ORT + web server.
RUN uv pip install \
      "fastapi==0.115.6" \
      "uvicorn==0.34.0" \
      "onnxruntime==1.20.1" \
      "transformers==4.46.3" \
      "numpy<2.0"

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Models exported in stage 1; the volume mounted at /models/onnx keeps them across restarts.
COPY --from=exporter /models/onnx /models/onnx

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=5 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health', timeout=5).read()"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
