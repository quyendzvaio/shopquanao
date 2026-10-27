#!/usr/bin/env bash
# HTTP snapshot of the storefront, admin pages and REST API.
# Usage: scripts/dev/web-snapshot.sh <label> [--output-buffering=N]
# Recreates shop_test (schema + migrations), seeds it, runs `php -S` inside the
# app image and writes reports/web-snapshot/<label>/NN-<scenario>.txt. Exits
# non-zero if any scenario could not run. Two runs must produce identical
# directories (diff -r); refactor tasks diff against reports/web-snapshot/baseline.
set -euo pipefail
cd "$(dirname "$0")/../.."
[ $# -ge 1 ] || { echo "usage: $0 <label> [--output-buffering=N]" >&2; exit 2; }
LABEL="$1"; shift
set -a; . ./.env; set +a
ROOT_PW="${MARIADB_ROOT_PASSWORD:-root_pass}"
scripts/dev/test-db.sh >/dev/null
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="$ROOT_PW" \
  scripts/dev/php scripts/dev/web_snapshot.php "reports/web-snapshot/$LABEL" "$@"
