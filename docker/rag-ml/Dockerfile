FROM python:3.12-slim

ENV HF_HOME=/models/huggingface \
    SENTENCE_TRANSFORMERS_HOME=/models/sentence-transformers \
    PYTHONUNBUFFERED=1

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends tini curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu \
    torch==2.5.1+cpu

RUN pip install --no-cache-dir \
    fastapi==0.115.6 \
    uvicorn==0.34.0 \
    sentence-transformers==3.3.1 \
    transformers==4.47.1 \
    numpy==1.26.4

COPY app.py /app/app.py
WORKDIR /app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=5 \
    CMD curl -sf http://localhost:8000/health >/dev/null || exit 1

ENTRYPOINT ["tini", "--"]
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
