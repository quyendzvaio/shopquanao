# service: business logic

Catalog, cart, order, size, outfit, freeship, knowledge, user, review and admin rules. No SQL, no `$_POST`/`$_SESSION`/`$_SERVER`, no env reads.

- Takes plain arguments and returns arrays or `model/` objects, so `app/api`, `app/web` and the chatbot tools share one implementation.
- May use: `repository`, `model`, `core` (config comes from `core/config`: `ServiceConfig`, `AppConfig`).
- Used by: `app`, `agent`, `harness`.

**Add one:** a class under `src/service/<area>/` whose constructor takes the `PDO` (or repositories) it needs and passes it to repositories; put each rule in one method, and throw `ApiError` or a domain exception instead of printing. Add a unit test in `tests/unit/service/` (SQLite fixture `tests/support/SqliteShop.php`), then `composer dump-autoload`.
