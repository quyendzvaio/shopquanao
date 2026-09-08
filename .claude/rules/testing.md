# Testing & CI gates

## Local

```bash
vendor/bin/phpstan analyse --level=1 api/ config/ --no-progress
vendor/bin/phpcs --standard=PSR12 .            # via composer lint
vendor/bin/phpunit --testsuite=Unit            # hermetic, no DB
vendor/bin/phpunit --testsuite=Integration     # needs shop_test DB
vendor/bin/phpunit --filter=testName           # single test
npm --prefix mcp-server ci && npm --prefix mcp-server test && npm --prefix mcp-server run build
php scripts/run_findmine_offline_eval.php      # 70-case deterministic corpus gate (historical name, provider-agnostic)
docker compose config --quiet
```

## CI (.github/workflows/ci.yml)

Jobs: Code Quality (composer validate, PHP lint, PSR-12 advisory, PHPStan, Python syntax, MCP contract tests + build) → Unit Tests (+ 70-question corpus validation) → Integration Tests (import schema, apply migrations first) → Security Scan (hardcoded-secret checks, Trivy fs) → Docker Build (app/reranker/rag-ml + image scans) → Deploy.

A change is green only when all gates pass; integration tests must run after migration, not against a stale schema.

## Conventions

- Unit tests live in `tests/Unit`, integration in `tests/Integration`; fixtures in `tests/fixtures`.
- Pipeline behavior changes (intent, evidence loop, constraint verifier, provider mapping) require a matching unit test — the corpus gate alone is not enough.
