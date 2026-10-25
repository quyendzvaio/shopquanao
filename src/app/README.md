# app: entry points

Receives a request, calls a service (or `agent` for the chatbot) and returns the result. No SQL, no business rules.

- `api/`: `routes.php` table, `controllers/` (REST), `support/ChatStreamProtocol` (SSE framing, origin guard).
- `web/`: one handler per storefront page, `web/admin/` for admin pages, `WebRequest` and `PageResult` as the I/O types.
- May use: `agent`, `service`, `model`, `core`. Never `repository`; no `getenv`/`$_ENV` (use `core/config`).

**Add a page:** a handler in `src/app/web/` that returns a `PageResult`, a view in `templates/pages/`, and a thin entry file in `public/` (bootstrap, `handle(WebRequest::fromGlobals(), $_SESSION)`, `sendIfFinal()`, then header, view, footer). Run `composer dump-autoload`.
**Add an endpoint:** a route in `routes.php`, a controller in `api/controllers/`, and the logic in a `src/service/` class. Controllers only parse input and map errors (`ApiError`) to status codes.
