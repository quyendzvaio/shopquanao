# ============================================================
# Fashion Shop — Production Image
# Multi-stage runtime with only production dependencies
# ============================================================

# ── Stage 1: Build private MCP server ──────────────────────
FROM node:22-bookworm-slim AS mcp-build
WORKDIR /build
COPY mcp-server/package.json mcp-server/package-lock.json ./
RUN npm ci
COPY mcp-server/tsconfig.json ./
COPY mcp-server/src/ src/
RUN npm run build

# ── Stage 2: MCP runtime (prod deps only) ──────────────────
FROM node:22-bookworm-slim AS mcp-runtime
WORKDIR /opt/mcp-server
COPY mcp-server/package.json mcp-server/package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY --from=mcp-build /build/dist/src ./dist

# ── Stage 3: Clone + patch FindMine MCP ────────────────────
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

# ── Stage 4: Compile PHP extensions ────────────────────────
# Build tools stay in this throwaway stage only.
FROM php:8.2-apache AS php-extensions
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS \
    && docker-php-ext-install -j1 pdo_mysql opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear /usr/src/php

# ── Stage 5: Runtime image ─────────────────────────────────
FROM php:8.2-apache

# Runtime system packages (curl for healthcheck, ca-certificates for HTTPS)
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends curl ca-certificates \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /usr/share/doc/* /usr/share/man/*

# Copy compiled PHP extensions and config from the throwaway build stage
COPY --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-extensions /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/

# Copy Node binary (used to spawn MCP stdio child process — no network listener)
COPY --from=mcp-runtime /usr/local/bin/node /usr/bin/node

# Apache + PHP config
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && echo "ServerSignature Off" >> /etc/apache2/conf-available/servername.conf \
    && echo "ServerTokens Prod" >> /etc/apache2/conf-available/servername.conf
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-shop.ini

WORKDIR /var/www/html

# MCP runtimes (private stdio — no external port)
COPY --from=mcp-runtime /opt/mcp-server /opt/mcp-server
COPY --from=findmine-build /build/findmine-mcp /opt/findmine-mcp

# ── Application source ─────────────────────────────────────
# Copy directories first (large, change less often → better layer caching)
COPY api/       api/
COPY config/    config/
COPY includes/  includes/
COPY css/       css/
COPY sql/       sql/
COPY images/    images/
COPY knowledge/ knowledge/

# Root PHP entry-points and admin panel
COPY *.php    ./
COPY admin/   admin/

# CLI scripts needed inside the container at runtime
# eval/ contains the 70-case agent evaluation corpus
COPY scripts/ scripts/
COPY eval/findmine_agent_eval_cases.php eval/findmine_agent_eval_cases.php
COPY tests/fixtures/findmine/fashion-extraction-cases.php \
     tests/fixtures/findmine/fashion-extraction-cases.php

# ── Ownership + permissions ────────────────────────────────
RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html \
    && find /var/www/html -name "*.php" -exec chmod 644 {} + \
    && chmod -R 755 /var/www/html/images \
    && rm -f /var/www/html/index.html

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost/api/products?limit=1 || exit 1

EXPOSE 80
USER www-data
