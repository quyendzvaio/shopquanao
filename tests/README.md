# Unit Tests & Integration Tests

## Test Suites

```
tests/
├── README.md
├── bootstrap.php          # Test bootstrap + SQLite fallback
├── Unit/
│   ├── CacheTest.php      # Cache set/get/delete/flush/buildKey
│   └── ChatbotEngineTest.php  # Rule-based engine (intent, search, FAQ, size)
└── Integration/
    └── ChatbotAPITest.php  # AgenticOrchestrator (tool calls, history, sessions)
```

## Running Tests

```bash
# Install PHPUnit
composer install

# Run all tests
composer test

# Run only unit tests (no DB needed — uses SQLite)
composer test:unit

# Run only integration tests (needs MySQL/SQLite)
composer test:integration

# Direct PHPUnit
vendor/bin/phpunit --colors=always
```

## Test Coverage

| Suite | File | Tests | Coverage |
|---|---|---|---|
| Unit | `CacheTest.php` | 14 | Cache CRUD, TTL, key consistency, search/FAQ/category shortcuts |
| Unit | `ChatbotEngineTest.php` | 16 | Intent, search with prices, size advice, FAQ, unknown intent |
| Integration | `ChatbotAPITest.php` | 10 | Orchestrator response, history loading, session, tool logs |

## CI Integration

Tests run automatically on every push via GitHub Actions:

1. **Unit Tests** — SQLite in-memory, no external deps needed
2. **Integration Tests** — MariaDB service container
3. **Security Scan** — Secret detection + Trivy
4. **Docker Build** — Multi-stage build + Trivy scan
