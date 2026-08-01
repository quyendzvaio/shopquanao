# Unit Tests & Integration Tests

## Test Suites

```
tests/
├── README.md
├── bootstrap.php          # Test bootstrap + SQLite fallback
├── Unit/
│   ├── CacheTest.php
│   ├── ProductionPipelineTest.php  # Deterministic routing + LLM boundary
│   └── ToolRegistryTest.php
└── Integration/
    └── ChatbotAPITest.php  # Production service, tools, memory, persistence
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
| Unit | `ProductionPipelineTest.php` | Characterization | Parser, planner, evidence, constraints, LLM isolation |
| Unit | `ToolRegistryTest.php` | Contract | Tool contracts, product constraints, policy retrieval |
| Integration | `ChatbotAPITest.php` | End-to-end service | Response, memory, session, tool logs and metadata |

## CI Integration

Tests run automatically on every push via GitHub Actions:

1. **Unit Tests** — SQLite in-memory, no external deps needed
2. **Integration Tests** — MariaDB service container
3. **Security Scan** — Secret detection + Trivy
4. **Docker Build** — Multi-stage build + Trivy scan
