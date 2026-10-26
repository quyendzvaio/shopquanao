#!/usr/bin/env bash
# The local green gate: every step must pass before a change is done.
# Usage: scripts/dev/gate.sh            (SKIP_SNAPSHOT=1 skips the HTTP snapshot diff)
# The snapshot step diffs reports/web-snapshot/current against reports/web-snapshot/baseline;
# a task that changes page output re-baselines deliberately, never to hide a diff.
set -euo pipefail
cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a
ROOT_PW="${MARIADB_ROOT_PASSWORD:-root_pass}"

step() { printf '\n=== %s\n' "$1"; }

step "composer dump-autoload"
scripts/dev/composer dump-autoload -q

step "phpunit Unit"
scripts/dev/php vendor/bin/phpunit --testsuite=Unit

step "phpstan level 1"
scripts/dev/php vendor/bin/phpstan analyse --level=1 config/ src/ --no-progress

step "shop_test + integration tests"
scripts/dev/test-db.sh && scripts/dev/test-integration.sh

step "agent eval (40 cases)"
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="$ROOT_PW" \
  scripts/dev/php scripts/run_agent_eval.php

step "mcp-server tests"
npm --prefix mcp-server test

if [ "${SKIP_SNAPSHOT:-0}" != "1" ]; then
  step "web snapshot diff against baseline"
  rm -rf reports/web-snapshot/current
  scripts/dev/web-snapshot.sh current
  diff -r reports/web-snapshot/baseline reports/web-snapshot/current
fi

printf '\n=== GATE PASSED\n'
