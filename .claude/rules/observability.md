# Observability (Langfuse)

Self-hosted Langfuse behind an opt-in Compose profile `observability` — the default `docker compose up` never pulls ClickHouse/MinIO.

```bash
docker compose --profile observability up -d langfuse-postgres langfuse-clickhouse \
  langfuse-minio langfuse-redis langfuse-web langfuse-worker
docker compose --profile observability ps
curl -fsS http://localhost:3000/ >/dev/null && echo "Langfuse UI is ready"
```

Before starting on a NEW host, validate the Postgres filesystem: `scripts/langfuse-storage-check.sh`. Storage layout, persistence, backup/recovery, guarded reset: `docs/langfuse-observability.md`. Reset only via `scripts/langfuse-reset.sh`.

## Setup

1. Open `http://localhost:3000`, create a project.
2. Put generated keys in the ignored `.env` only: `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`, `LANGFUSE_ENABLED=true`, `LANGFUSE_BASE_URL=http://localhost:3000`, `LANGFUSE_INGESTION_URL=http://langfuse-web:3000`, `LANGFUSE_PROJECT=fashion-shop-chatbot-eval`.
3. Replace every `local-only-change-me` value in `.env` before sharing the stack beyond localhost. Encryption key: `openssl rand -hex 32`.

Never commit Langfuse values or bake them into an image.

## Trace publishing

- `chat-graph` sends its own traces to Langfuse (`chat-graph/src/tracing.ts`: Langfuse LangChain callback handler + OTel span processor, enabled only with `APP_ENV=production`, `LANGFUSE_ENABLED=true` and both keys, otherwise it logs `[chat-graph] Langfuse tracing off: APP_ENV is not production` once at startup when the flag is on; message/answer content stays out unless `LANGFUSE_TRACE_CONTENT=true`). Tracing failures never affect the answer.
- The old PHP trace outbox (table, publisher service, PHP classes) was removed; a drop migration (2026-11-03) removes the table. `APP_ENV` is `development` unless the deploy `.env` sets `production`.
