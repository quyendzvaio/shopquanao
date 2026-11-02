# Production image: Alpine-based PHP-FPM + Nginx. The chatbot runs in the separate chat-graph image.
ARG PHP_IMAGE=serversideup/php:8.2-fpm-nginx-alpine@sha256:45a19bc2818c3b56e10bf05b63a6361bb85a0081a16382cf04963b6f58124258
ARG COMPOSER_IMAGE=composer:2.8

FROM ${COMPOSER_IMAGE} AS php-deps
WORKDIR /build
COPY composer.json composer.lock ./
COPY src/ src/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
      --ignore-platform-reqs --classmap-authoritative

FROM ${PHP_IMAGE} AS runtime

ENV NGINX_HTTP_PORT=8080 \
    NGINX_WEBROOT=/var/www/html/public \
    PHP_OPCACHE_ENABLE=1 \
    SHOW_WELCOME_MESSAGE=false

WORKDIR /var/www/html

# Route REST paths to public/api/index.php while the image's standard Nginx config
# continues serving root PHP pages and static assets.
COPY docker/app-nginx-api.conf /etc/nginx/server-opts.d/api-router.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-shop.ini

COPY --from=php-deps --chown=www-data:www-data /build/vendor/ vendor/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data src/ src/
COPY --chown=www-data:www-data templates/ templates/
COPY --chown=www-data:www-data config/ config/
COPY --chown=www-data:www-data database/ database/
COPY --chown=www-data:www-data knowledge/ knowledge/
COPY --chown=www-data:www-data scripts/run_database_migrations.php scripts/ingest_knowledge.php \
     scripts/publish_langfuse_trace_outbox.php scripts/ensure_admin_user.php scripts/

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --silent --show-error --fail http://127.0.0.1:8080/api/products?limit=1 >/dev/null || exit 1

EXPOSE 8080
USER www-data
