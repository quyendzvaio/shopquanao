# Langfuse observability

The previous hosted evaluator integration has been removed from the project.
Evaluation tracing now uses the Langfuse Python SDK, and the self-hosted Langfuse runtime is an opt-in Compose
profile. It is intentionally outside the application image and outside the
default `docker compose up` path.

## Start the local stack

```bash
cp .env.example .env # only if .env does not exist
docker compose --profile observability up -d \
  langfuse-postgres langfuse-clickhouse langfuse-minio \
  langfuse-redis langfuse-web langfuse-worker
docker compose --profile observability ps
curl -fsS http://localhost:3000/ >/dev/null
```

The stack uses the pinned Langfuse `3.114.0` images, ClickHouse
`24.8-alpine`, PostgreSQL `17-alpine`, Redis `7-alpine`, and a pinned MinIO
release. The web UI is exposed on `LANGFUSE_PORT` (default `3000`); storage
services remain on the internal Compose network except the optional MinIO
console/API ports.

The local Docker host for this project uses a filesystem that does not preserve
Unix ownership for Docker named volumes. Therefore the Postgres data directory
defaults to `./.docker-data/langfuse-postgres` on the host filesystem. Set
`LANGFUSE_POSTGRES_DATA_DIR` to a durable ext4/XFS path or a server-managed
mount when deploying elsewhere. Existing application volumes are untouched.

## Storage layout and filesystem safety

Langfuse has four storage roles in this Compose profile:

| Service | Storage | Persistence | Reset target |
| --- | --- | --- | --- |
| `langfuse-postgres` | bind mount `${LANGFUSE_POSTGRES_DATA_DIR}` → `/var/lib/postgresql/data` | durable relational metadata, projects, users, and settings | the exact bind directory |
| `langfuse-clickhouse` | named volume `shop_quan_ao_langfuse_clickhouse_data` plus `shop_quan_ao_langfuse_clickhouse_logs` | observations and analytics data/logs | the two exact named volumes |
| `langfuse-minio` | named volume `shop_quan_ao_langfuse_minio_data` | event/media objects | the exact named volume |
| `langfuse-redis` | no volume in this project | cache/queue only; intentionally ephemeral | nothing |

The current project path and Postgres bind directory are on Linux `ext4`. The
Docker root is `/data/docker`, whose host filesystem is `fuseblk`; this is why
Postgres is not stored in a Docker named volume on this machine. PostgreSQL
changes ownership during initialization and requires a filesystem that honors
Unix UID/GID operations. The current bind path is safe because it is on ext4;
no data migration is necessary. On another host, run the preflight before
starting the profile:

```bash
scripts/langfuse-storage-check.sh
```

The preflight refuses `fuseblk`, NTFS, exFAT, CIFS, NFS, overlay, or another
unsupported filesystem and reports the Postgres image owner without printing
credentials.

If preflight fails on a deployment host, do not start PostgreSQL on that path.
Stop the profile, create the Linux-native destination, copy the old directory
with ownership/permissions preserved, point `LANGFUSE_POSTGRES_DATA_DIR` at the
destination, and then run the preflight again before starting:

```bash
docker compose --profile observability stop langfuse-web langfuse-worker \
  langfuse-postgres langfuse-clickhouse langfuse-redis langfuse-minio
sudo install -d -m 700 /var/lib/shopquanao/langfuse/postgres
sudo rsync -aHAX --numeric-ids .docker-data/langfuse-postgres/ \
  /var/lib/shopquanao/langfuse/postgres/
sudo chown -R 70:70 /var/lib/shopquanao/langfuse/postgres
LANGFUSE_POSTGRES_DATA_DIR=/var/lib/shopquanao/langfuse/postgres \
  scripts/langfuse-storage-check.sh
```

Keep the old directory until a successful start, health check, and backup have
been confirmed. The migration is an offline storage operation; it is never
performed on the chat/request path.

## Persistence, shutdown, and restart

`docker compose --profile observability down` removes the profile containers
and network but preserves both the Postgres bind directory and all three named
storage volumes. `docker compose --profile observability down -v` removes
**all** Compose-managed named volumes in the project, including shop
application volumes such as MariaDB/Qdrant caches when those services are
running. It still does **not** remove the Postgres bind directory. Do not use
`down -v` as a Langfuse-only cleanup command; use
`scripts/langfuse-reset.sh` instead. The Postgres bind directory must be
removed explicitly and only after a verified backup.

Use a normal restart when applying a configuration change:

```bash
APP_IMAGE=shop_quan_ao-app:stylitics-goal1 \
  docker compose --profile observability up -d
docker compose --profile observability ps
curl -fsS http://localhost:3000/api/public/health
```

The safe reset helper is deliberately opt-in:

```bash
scripts/langfuse-reset.sh --dry-run
scripts/langfuse-reset.sh                  # read-only plan; no deletion
scripts/langfuse-reset.sh --confirm         # irreversible data reset
scripts/langfuse-reset.sh --all --confirm   # explicit whole observability reset
```

The helper stops only the six Langfuse services, validates the exact allowed
Postgres path, refuses symlink traversal and unexpected paths, and removes only
the active ClickHouse/MinIO volumes plus the Postgres bind directory. An old
unreferenced `shop_quan_ao_langfuse_postgres_data` named volume, if present,
is reported but is not targeted unless `--all --confirm` is supplied. It never
runs `docker system prune`, never touches shop/MariaDB/Qdrant volumes, and
never sources `.env` into logs. Do not run `--confirm` until backups have been
verified.

## Backup and recovery

For a consistent backup, stop the profile first, then archive the Postgres
bind directory and export the named volumes using your host backup policy:

```bash
docker compose --profile observability stop \
  langfuse-web langfuse-worker langfuse-postgres langfuse-clickhouse \
  langfuse-redis langfuse-minio
tar --xattrs --acls -czf langfuse-postgres-backup.tgz \
  .docker-data/langfuse-postgres
```

Restore with all six services stopped, restore the directory to the exact
configured path, and ensure its owner uses the `postgres:17-alpine` image UID
(UID 70; the pinned image's nominal UID/GID is 70:70) with mode 700 or stricter.
The host group may differ when the directory is bind-mounted, provided the
effective UID matches and group/world permissions do not expose the data. If
the path is under
`/var/lib/shopquanao`, use the host administrator's `sudo chown`/backup tooling
as required; the application does not log or manage host secrets.

Data that survives `down`: Postgres bind data, ClickHouse named data/logs, and
MinIO objects. Data that survives a profile `down -v`: the Postgres bind data
only; every Compose-managed named volume in the project is removed, including
non-Langfuse shop volumes if they are part of the active project. Redis
cache/queue data is never durable in this Compose profile.

## Create credentials

Open `http://localhost:3000` and create an organization/project. Put only the
project keys in the ignored `.env`:

```text
LANGFUSE_ENABLED=true
LANGFUSE_BASE_URL=http://localhost:3000
LANGFUSE_PUBLIC_URL=http://localhost:3000
LANGFUSE_INGESTION_URL=http://langfuse-web:3000
LANGFUSE_PROJECT=fashion-shop-chatbot-eval
LANGFUSE_PUBLIC_KEY=<project public key>
LANGFUSE_SECRET_KEY=<project secret key>
```

Generate infrastructure secrets before sharing the stack:

```bash
openssl rand -hex 32  # LANGFUSE_ENCRYPTION_KEY
openssl rand -base64 32  # SALT/NEXTAUTH_SECRET/password values as appropriate
```

Do not put these values in source, Dockerfiles, reports, or CI logs. Replace
all `local-only-change-me` values from `.env.example` for any shared or
internet-facing deployment.

## Trace evaluations

Install the optional evaluator dependencies outside the application image:

```bash
python3 -m venv .venv-eval
. .venv-eval/bin/activate
pip install -r eval/requirements-eval.txt
```

The 50-case live Stylitics report can be published after the project keys are set:

```bash
python3 eval/publish_stylitics_langfuse.py \
  --report reports/eval/stylitics_agent_eval_50_live_after_fix_20260830.json
```

The publisher sends only sanitized questions, final answers, private
Product-Search contexts, bounded metadata, and timing. It does not send raw
MCP responses, Stylitics OAuth material, cookies, or secret headers. Dataset item
IDs are stable so rerunning the command does not create a new item per run.

`eval/run_chatbot_eval.py` and `scripts/eval_rag_chatbot.py` also emit optional
Langfuse observations when both project keys are present. Without keys, the
evaluation remains fully functional and deterministic checks still run.

## Production agent tracing: fail-open by design

When `LANGFUSE_ENABLED=true` and valid project keys are configured, completed
chatbot requests are recorded to the local `langfuse_trace_outbox` table. The
request and WebSocket streaming paths make **no Langfuse HTTP request**. A
separate `langfuse-trace-publisher` container exports completed OTLP/HTTP spans
to `LANGFUSE_INGESTION_URL/api/public/otel/v1/traces` with a 1.5-second default
timeout and bounded retry/backoff.

Start the full observability profile, including the exporter, with:

```bash
docker compose --profile observability up -d
```

The publisher deliberately has no Compose dependency on `langfuse-web` or
`langfuse-worker`. If Langfuse is stopped, unhealthy, misconfigured, or times
out, the publisher retains/retries its outbox row while the agent continues to
answer and stream normally. A failed export is never returned to a shopper.

Traces include the deterministic agent decision, selected tool names, loop
count, stage latency, response type, and only private Product Search IDs. Raw
Stylitics/MCP payloads and credentials are never exported. `LANGFUSE_TRACE_CONTENT`
defaults to `false`; enable it only after approving the privacy policy if
sanitized user/assistant text is required for debugging.

The exporter uses the supported OTLP/HTTP endpoint and Basic project-key
authentication, plus `x-langfuse-ingestion-version: 4` for real-time v4
ingestion. The legacy trace ingestion APIs are not used.

`LANGFUSE_BASE_URL`/`LANGFUSE_PUBLIC_URL` is the browser-facing URL used by the
Langfuse UI and host-side evaluation scripts. `LANGFUSE_INGESTION_URL` is the
Docker-internal URL used only by `app` and `langfuse-trace-publisher`; its
normal Compose value is `http://langfuse-web:3000`. This separation prevents a
container resolving `localhost:3000` to itself instead of the Langfuse service.

For CI/CD, set these repository environment secrets before setting
`LANGFUSE_ENABLED=true`: `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`,
`LANGFUSE_POSTGRES_PASSWORD`, `LANGFUSE_REDIS_PASSWORD`,
`LANGFUSE_CLICKHOUSE_PASSWORD`, `LANGFUSE_MINIO_SECRET_KEY`, `LANGFUSE_SALT`,
`LANGFUSE_ENCRYPTION_KEY`, and `LANGFUSE_NEXTAUTH_SECRET`. The deployment job
fails closed on missing observability secrets, while a running Langfuse outage
continues to fail open for the agent via the outbox worker.
