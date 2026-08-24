# ============================================================
# Fashion Shop — Production Image
# Multi-stage runtime with only production dependencies
# ============================================================
FROM node:22-bookworm-slim AS mcp-build
WORKDIR /build
COPY mcp-server/package.json mcp-server/package-lock.json ./
RUN npm ci
COPY mcp-server/tsconfig.json ./
COPY mcp-server/src/ src/
RUN npm run build

FROM node:22-bookworm-slim AS mcp-runtime
WORKDIR /opt/mcp-server
COPY mcp-server/package.json mcp-server/package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY --from=mcp-build /build/dist/src ./dist

FROM node:22-bookworm-slim AS findmine-build
ARG FINDMINE_MCP_SHA=28a15b86ac0a7b212336748005393f88bcbfdad1
WORKDIR /build/findmine-mcp
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends git ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && git clone --quiet https://github.com/findmine/findmine-mcp.git . \
    && git checkout --quiet "$FINDMINE_MCP_SHA"
COPY docker/findmine-mcp-shopquanao.patch /tmp/findmine-mcp-shopquanao.patch
RUN git apply --check /tmp/findmine-mcp-shopquanao.patch \
    && git apply /tmp/findmine-mcp-shopquanao.patch
RUN npm ci \
    && npm run build \
    && npm prune --omit=dev \
    && npm cache clean --force

FROM php:8.2-apache AS php-extensions

# Compile extensions in a throw-away stage. The runtime image receives only
# the resulting .so files and ini entries, not gcc/autoconf/PECL build tools.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS \
    && docker-php-ext-install -j1 pdo_mysql opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear /usr/src/php

FROM php:8.2-apache

# Install only required runtime extensions.
# Redis is the shared server-side cache. Cache still falls back to files if Redis
# is temporarily unavailable, but the extension is installed in the image.
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends curl ca-certificates \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /usr/share/doc/* /usr/share/man/*

# Copy only compiled PHP modules/configuration and the Node executable used by
# the private MCP stdio child process.
COPY --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-extensions /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=mcp-runtime /usr/local/bin/node /usr/bin/node

# Apache config
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && echo "ServerSignature Off" >> /etc/apache2/conf-available/servername.conf \
    && echo "ServerTokens Prod" >> /etc/apache2/conf-available/servername.conf

# Copy configs
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-shop.ini

# Working directory
WORKDIR /var/www/html

# Private MCP stdio runtime. No network listener is started.
COPY --from=mcp-runtime /opt/mcp-server /opt/mcp-server
COPY --from=findmine-build /build/findmine-mcp /opt/findmine-mcp

# Copy application files (chained to single layer)
COPY api/ api/
COPY config/ config/
COPY includes/ includes/
COPY css/ css/
COPY sql/ sql/
COPY knowledge/ knowledge/
COPY scripts/ingest_knowledge.php scripts/ingest_knowledge.php
COPY scripts/run_database_migrations.php scripts/run_database_migrations.php
COPY scripts/publish_fashion_outbox.php scripts/publish_fashion_outbox.php
COPY scripts/consume_fashion_events.php scripts/consume_fashion_events.php
COPY scripts/findmine_live_inspect.php scripts/findmine_live_inspect.php
COPY scripts/run_fashion_extraction_eval.php scripts/run_fashion_extraction_eval.php
COPY tests/fixtures/findmine/fashion-extraction-cases.php tests/fixtures/findmine/fashion-extraction-cases.php
COPY scripts/smoke_findmine_demo.php scripts/smoke_findmine_demo.php
COPY scripts/smoke_proactive_demo_live.php scripts/smoke_proactive_demo_live.php
COPY scripts/run_findmine_agent_eval.php scripts/run_findmine_agent_eval.php
COPY eval/findmine_agent_eval_cases.php eval/findmine_agent_eval_cases.php
COPY scripts/smoke_cart_event_pipeline.php scripts/smoke_cart_event_pipeline.php
COPY scripts/smoke_proactive_chat_turns.php scripts/smoke_proactive_chat_turns.php
COPY images/ images/
COPY *.php ./
COPY admin/*.php admin/

# Ownership + permissions (exclude writeable dirs from chmod for perf)
RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html \
    && chmod -R 644 /var/www/html/*.php /var/www/html/api/*.php \
    && chmod -R 755 /var/www/html/images

# Remove default Apache index
RUN rm -f /var/www/html/index.html

# Healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost/api/products?limit=1 || exit 1

EXPOSE 80

USER www-data
