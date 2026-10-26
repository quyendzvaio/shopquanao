# Deployment

- CI/CD: GitHub Actions deploys over SSH on pushes to `main`/`master`.
- Deploy ordering is a hard gate: DB and Redis must be healthy and migrations PASS **before** the app starts.
- App uses the `${APP_IMAGE:-shop_quan_ao-app:latest}` tag — keep that contract when editing `docker-compose.yml`.
- Langfuse runs in the separate `observability` profile; do not add its services to the default compose dependency graph.
- Port 80 conflict locally: `NGINX_HTTP_PORT=8092 docker compose up -d --build`.
- Post-deploy smoke: `curl -fsS http://localhost/nginx-health` and `curl -fsS 'http://localhost/api/products?limit=1'`; fuller check: `scripts/dev/smoke.sh` (also asserts source paths such as `/sql/shop_db.sql` return 403/404).
