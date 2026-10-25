#!/usr/bin/env bash
# HTTP smoke for storefront pages, admin, REST API, assets and blocked paths.
# Usage: BASE_URL=http://localhost:8092 scripts/dev/smoke.sh
set -uo pipefail
BASE="${BASE_URL:-http://localhost}"
fail=0
check() { # expected_status method path [json_body]
  local want="$1" method="$2" path="$3" body="${4:-}" got
  if [ -n "$body" ]; then
    got=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" -H 'Content-Type: application/json' --data "$body" "$BASE$path")
  else
    got=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" "$BASE$path")
  fi
  if [ "$got" = "$want" ]; then echo "ok   $got $method $path"; else echo "FAIL $method $path: want $want got $got"; fail=1; fi
}
check 200 GET /nginx-health
check 200 GET /
check 200 GET '/product.php?id=50'
check 200 GET /login.php
check 200 GET /register.php
check 200 GET /admin/login.php
check 200 GET /assets/css/style.css
check 200 GET /assets/images/at_basic_01.jpg
# image security.conf denies *.sql by extension (403); the file is not under the web root either
check 403 GET /sql/shop_db.sql
check 404 GET /config/db.php
check 404 GET /src/bootstrap.php
check 404 GET /scripts/run_database_migrations.php
check 404 GET /templates/partials/header.php
check 200 GET '/api/products?limit=1'
check 200 GET /api/products/50
check 200 GET /api/categories
check 200 GET /api/faq
check 200 GET '/api/size-guide?height=170&weight=65'
check 200 GET /api/outfit
check 404 POST /api/internal/mcp '{}'
check 200 POST /api/chatbot '{"message":"áo thun dưới 300k"}'
check 200 POST /api/chatbot/stream '{"message":"áo thun dưới 300k"}'
# The WebSocket gateway was removed: an upgrade request must not be accepted.
got=$(curl -s -o /dev/null -w '%{http_code}' -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' "$BASE/ws/chatbot")
if [ "$got" != "101" ]; then echo "ok   $got WS /ws/chatbot (no upgrade)"; else echo "FAIL WS /ws/chatbot: upgrade accepted"; fail=1; fi
exit $fail
