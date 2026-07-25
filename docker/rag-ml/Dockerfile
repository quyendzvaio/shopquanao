FROM python:3.12-slim AS python-deps

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN pip install --prefix=/install --extra-index-url https://download.pytorch.org/whl/cpu \
    torch==2.5.1+cpu \
    fastapi==0.115.6 \
    uvicorn==0.34.0 \
    sentence-transformers==3.3.1

FROM python:3.12-slim

ENV HF_HOME=/models/huggingface \
    SENTENCE_TRANSFORMERS_HOME=/models/sentence-transformers \
    PYTHONUNBUFFERED=1

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY --from=python-deps /install /usr/local

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=5 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health', timeout=5).read()"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
