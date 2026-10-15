#!/usr/bin/env bash
# Recreate shop_test inside the Compose MariaDB, import the base schema and
# apply every migration, mirroring the CI integration job.
set -euo pipefail
cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a
ROOT_PW="${MARIADB_ROOT_PASSWORD:-root_pass}"
SCHEMA_FILE="${SCHEMA_FILE:-$( [ -f database/schema.sql ] && echo database/schema.sql || echo sql/shop_db.sql )}"
docker compose exec -T db mariadb -uroot -p"$ROOT_PW" \
  -e 'DROP DATABASE IF EXISTS shop_test; CREATE DATABASE shop_test CHARACTER SET utf8mb4;'
sed '/^CREATE DATABASE/d;/^USE /d' "$SCHEMA_FILE" | docker compose exec -T db mariadb -uroot -p"$ROOT_PW" shop_test
DEV_DOCKER_NETWORK=shop_quan_ao_default DB_HOST=db DB_NAME=shop_test DB_USER=root DB_PASS="$ROOT_PW" \
  scripts/dev/php scripts/run_database_migrations.php
