# repository: the only home of SQL

Every `->prepare/query/exec` lives here, one class per table or table group (`ProductRepository`, `OrderRepository`, `CartRepository`, ...).

- May use: `model`, `core`. Returns arrays or `model/` objects; no business decisions, no request/session access, no env reads.
- Permanent exceptions (infrastructure that owns its own tables): `core/database/MigrationRunner`, `core/observability/LangfuseTraceOutbox`, `core/observability/LangfuseOtlpTracePublisher`.
- Driver differences (`FOR UPDATE`, `ON CONFLICT` vs `ON DUPLICATE KEY`, `NOW()` vs `CURRENT_TIMESTAMP`) stay inside the repository method so the SQLite unit fixtures and MariaDB both work.

**Add a query:** a method on the repository of that table (or a new `<Table>Repository` taking a `PDO`), bound parameters only, called from a `src/service/` class. Cover it with a unit test on the SQLite fixture and, for MariaDB-only SQL, an integration test. `ArchitectureTest` fails on SQL anywhere else.
