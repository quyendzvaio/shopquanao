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
check 200 GET /css/style.css
check 200 GET /images/at_basic_01.jpg
check 200 GET '/api/products?limit=1'
check 200 GET /api/products/50
check 200 GET /api/categories
check 200 GET /api/faq
check 200 GET '/api/size-guide?height=170&weight=65'
check 200 GET /api/outfit
check 404 POST /api/internal/mcp '{}'
check 200 POST /api/chatbot '{"message":"áo thun dưới 300k"}'
exit $fail
