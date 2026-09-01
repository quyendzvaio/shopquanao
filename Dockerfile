# Production image: Alpine-based PHP-FPM + Nginx, with a small Node runtime
# used only for the private MCP and authenticated Glance MCP stdio bridges.
ARG NODE_IMAGE=node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32
ARG PHP_IMAGE=serversideup/php:8.2-fpm-nginx-alpine@sha256:45a19bc2818c3b56e10bf05b63a6361bb85a0081a16382cf04963b6f58124258

FROM ${NODE_IMAGE} AS mcp-build
WORKDIR /build
COPY mcp-server/package.json mcp-server/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY mcp-server/tsconfig.json ./
COPY mcp-server/src/ src/
RUN npm run build

FROM ${NODE_IMAGE} AS mcp-runtime
WORKDIR /opt/mcp-server
COPY mcp-server/package.json mcp-server/package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    && npm cache clean --force
COPY --from=mcp-build /build/dist/src ./dist

FROM ${PHP_IMAGE} AS runtime

ENV NGINX_HTTP_PORT=8080 \
    NGINX_WEBROOT=/var/www/html \
    PHP_OPCACHE_ENABLE=1 \
    SHOW_WELCOME_MESSAGE=false

WORKDIR /var/www/html

# node:alpine and the PHP runtime share musl. Copy only Node and its two small
# runtime libraries; npm and the full Node base image are not retained.
COPY --from=mcp-runtime /usr/local/bin/node /usr/bin/node
COPY --from=mcp-runtime /usr/lib/libstdc++.so.6* /usr/lib/
COPY --from=mcp-runtime /usr/lib/libgcc_s.so.1 /usr/lib/libgcc_s.so.1
COPY --from=mcp-runtime /opt/mcp-server /opt/mcp-server

# Route REST paths to api/index.php while the image's standard Nginx config
# continues serving root PHP pages and static assets.
COPY docker/app-nginx-api.conf /etc/nginx/server-opts.d/api-router.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-shop.ini

COPY --chown=www-data:www-data api/ api/
COPY --chown=www-data:www-data config/ config/
COPY --chown=www-data:www-data includes/ includes/
COPY --chown=www-data:www-data css/ css/
COPY --chown=www-data:www-data sql/ sql/
COPY --chown=www-data:www-data images/ images/
COPY --chown=www-data:www-data knowledge/ knowledge/
COPY --chown=www-data:www-data admin/ admin/
COPY --chown=www-data:www-data *.php ./

# Only runtime and operational scripts belong in the production image.
COPY --chown=www-data:www-data scripts/publish_fashion_outbox.php scripts/publish_fashion_outbox.php
COPY --chown=www-data:www-data scripts/consume_fashion_events.php scripts/consume_fashion_events.php
COPY --chown=www-data:www-data scripts/run_database_migrations.php scripts/run_database_migrations.php
COPY --chown=www-data:www-data scripts/ingest_knowledge.php scripts/ingest_knowledge.php
# Read-only operational probe: emits only Glance stage timings and response
# shape counts, never OAuth headers/tokens or provider payloads.
COPY --chown=www-data:www-data scripts/diagnose_glance_latency.php scripts/diagnose_glance_latency.php

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --silent --show-error --fail http://127.0.0.1:8080/api/products?limit=1 >/dev/null || exit 1

EXPOSE 8080
USER www-data
