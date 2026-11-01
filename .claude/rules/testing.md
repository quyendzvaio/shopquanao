# Testing & CI gates

## Local

The host has no PHP; `scripts/dev/php` and `scripts/dev/composer` run it in Docker.

```bash
scripts/dev/php vendor/bin/phpunit --testsuite=Unit              # hermetic (SQLite fixtures)
scripts/dev/php vendor/bin/phpunit tests/unit/service/outfit/OutfitBuilderTest.php   # single file
scripts/dev/php vendor/bin/phpunit --filter=testName
scripts/dev/test-db.sh && scripts/dev/test-integration.sh         # shop_test in Compose MariaDB
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress
scripts/dev/composer dump-autoload                                # after adding a class under src/
npm --prefix mcp-server ci && npm --prefix mcp-server test && npm --prefix mcp-server run build
npm --prefix chat-graph ci && npm --prefix chat-graph test && npm --prefix chat-graph run build   # after mcp-server ci: chat-graph compiles ../mcp-server/src
docker compose config --quiet
scripts/dev/smoke.sh                                              # HTTP smoke incl. 404/403 on source paths
scripts/dev/web-snapshot.sh <label>                               # HTTP snapshot of storefront, admin and REST on shop_test
diff -r reports/web-snapshot/baseline reports/web-snapshot/<label> # must be empty for a pure refactor
scripts/dev/gate.sh                                               # the full local gate: autoload, Unit, PHPStan, integration, mcp-server tests, chat-graph tests, snapshot diff (SKIP_SNAPSHOT=1 skips the diff)
```

The snapshot baseline lives in gitignored `reports/web-snapshot/baseline`. Re-take it only for a deliberate, reviewed behaviour change (e.g. web checkout moving to `OrderService`), never to make a diff go away.

Chatbot eval: CI layer = the chat-graph node suite (scripted LLM); manual layer = `chat-graph/eval` with a real LLM. See `eval.md`.

## CI (.github/workflows/ci.yml)

Jobs: Code Quality (composer validate, PHP lint, PSR-12 advisory, PHPStan, MCP contract tests + build, chat-graph tests + build) → Unit Tests → Integration Tests (import schema, apply migrations first) → Security Scan (hardcoded-secret checks, Trivy fs) → Docker Build (app/reranker/chat-graph/rag-ml + image scans) → Deploy.

A change is green only when all gates pass; integration tests must run after migration, not against a stale schema.

## Conventions

- Unit tests live in `tests/unit` (mirrors `src/`), integration in `tests/integration`; the SQLite catalog fixture is `tests/support/SqliteShop.php`. `tests/unit/ArchitectureTest.php` enforces the `src/harness` component table and layer rules; a violation means fix the code, not the test.
- Web snapshot baseline (local only, CI does not run it): to compare a change against a reference commit such as `develop`, check that commit out and run `scripts/dev/web-snapshot.sh baseline` before making the change, then `scripts/dev/web-snapshot.sh current` and `diff -r reports/web-snapshot/baseline reports/web-snapshot/current`.
- Behavior changes in the chat path (graph, policies, tool contracts, outfit/free-shipping logic) require a matching unit test (chat-graph node test or PHP unit test) — the manual eval alone is not enough.
