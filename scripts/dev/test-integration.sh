#!/usr/bin/env bash
# Run the Integration suite against shop_test (call scripts/dev/test-db.sh first).
set -euo pipefail
cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a
DEV_DOCKER_NETWORK=shop_quan_ao_default APP_ENV=test CHATBOT_TOOL_TRANSPORT=internal \
  DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="${MARIADB_ROOT_PASSWORD:-root_pass}" \
  scripts/dev/php vendor/bin/phpunit --testsuite=Integration "$@"
