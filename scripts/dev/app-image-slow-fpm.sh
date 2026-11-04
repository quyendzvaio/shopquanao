#!/usr/bin/env bash
# Checks that the app image still serves when php-fpm is slow to start (a host under load after a reboot).
#
# Runs the image with php-fpm delayed by $DELAY seconds (a wrapper in front of the real binary, the image's own
# s6 run script untouched) and expects nginx to answer /nginx-health once php-fpm is up. The base image's
# readiness check used to give up after 7 seconds, which left nginx never started (docker/app-php-fpm-run).
#
#   scripts/dev/app-image-slow-fpm.sh [image]        default image: shop_quan_ao-app:latest
set -euo pipefail

IMAGE="${1:-shop_quan_ao-app:latest}"
DELAY="${DELAY:-15}"
WAIT="${WAIT:-45}"
NAME="app-slow-fpm-$$"
WORK="$(mktemp -d)"
trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$WORK"' EXIT

# The real php-fpm binary, taken from the image, so the wrapper can exec it after the delay.
docker create --name "$NAME" "$IMAGE" >/dev/null
docker cp "$NAME:/usr/local/sbin/php-fpm" "$WORK/php-fpm-real"
docker rm -f "$NAME" >/dev/null
printf '#!/bin/sh\nsleep %s\nexec /usr/local/sbin/php-fpm-real "$@"\n' "$DELAY" > "$WORK/php-fpm"
chmod 755 "$WORK/php-fpm" "$WORK/php-fpm-real"

docker run -d --name "$NAME" \
  -v "$WORK/php-fpm:/usr/local/sbin/php-fpm:ro" \
  -v "$WORK/php-fpm-real:/usr/local/sbin/php-fpm-real:ro" \
  "$IMAGE" >/dev/null

echo "php-fpm delayed by ${DELAY}s; waiting up to ${WAIT}s for nginx..."
for ((i = 0; i < WAIT; i++)); do
  if docker exec "$NAME" wget -qO- --timeout=2 http://127.0.0.1:8080/nginx-health >/dev/null 2>&1; then
    echo "OK: nginx answered after ${i}s with php-fpm delayed ${DELAY}s"
    exit 0
  fi
  sleep 1
done

echo "FAIL: nginx never answered within ${WAIT}s (php-fpm delayed ${DELAY}s)" >&2
docker logs "$NAME" 2>&1 | tail -20 >&2
exit 1
