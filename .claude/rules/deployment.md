# Deployment

- CI/CD: GitHub Actions deploys over SSH on pushes to `main`/`master`.
- Deploy ordering is a hard gate: DB and Redis must be healthy and migrations PASS **before** the app starts.
- App uses the `${APP_IMAGE:-shop_quan_ao-app:latest}` tag — keep that contract when editing `docker-compose.yml`.
- Langfuse runs in the separate `observability` profile; do not add its services to the default compose dependency graph.
- Port 80 conflict locally: `NGINX_HTTP_PORT=8092 docker compose up -d --build`.
- Post-deploy smoke: `curl -fsS http://localhost/nginx-health` and `curl -fsS 'http://localhost/api/products?limit=1'`; fuller check: `scripts/dev/smoke.sh` (also asserts source paths such as `/sql/shop_db.sql` return 403/404).
- `chat-graph` has its own image (`chat-graph/Dockerfile`, build context = repo root because it compiles `../mcp-server/src`): `${CHAT_GRAPH_IMAGE:-shop_quan_ao-chat-graph:latest}`. It is private (`expose: 8090`, no port mapping, not routed by nginx), depends on `app` healthy; `app` does not depend on it. `app` and `chat-graph` must receive the same `CHAT_GRAPH_TOKEN` (one `${CHAT_GRAPH_TOKEN:-local-dev-...}` default in both; set the real secret as the `CHAT_GRAPH_TOKEN` deploy secret). Local rebuild without touching the rest: `docker compose build app chat-graph && docker compose up -d --no-deps app chat-graph` (plain `up --build` also rebuilds and recreates reranker/rag-ml).
- Deploy builds `reranker`, `rag-ml`, `chat-graph` and `app` before migrations; the CI Docker Build job also builds and (advisory) Trivy-scans the chat-graph image.
- Never `docker compose down` / `--remove-orphans` on a dev host with data volumes you care about.
- `rag-ml` keeps both models resident with a tiny embed + rerank every `RAG_ML_KEEPWARM_SECONDS` (default 60, 0 disables; `/health` shows `keepwarm_last_ms`): on a host under memory pressure an idle model was swapped out and the next knowledge query paid ~8 s.
- PHP curl calls to sibling services start from `InternalHttp::CURL_OPTIONS` (IPv4-only resolution): an AAAA lookup of a service name goes through Docker DNS to the upstream resolver (measured 25-480 ms). `tests/unit/core/http/InternalHttpTest.php` checks every `curl_init` in `src/`.
