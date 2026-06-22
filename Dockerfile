# ============================================================
# Fashion Shop — Production Image
# Single-stage, minimal deps, optimized for limited disk space
# ============================================================
FROM php:8.2-apache

# Install PHP extensions (runtime only — no dev headers needed)
# Combine all apt + docker-php-ext into ONE RUN to minimize layer size
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends curl ca-certificates \
    && docker-php-ext-install -j$(nproc) pdo_mysql mysqli opcache 2>&1 | tail -3 \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /usr/share/doc/* /usr/share/man/*

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

# Copy application files (chained to single layer)
COPY api/ api/
COPY config/ config/
COPY includes/ includes/
COPY css/ css/
COPY sql/ sql/
COPY docker/ docker/
COPY knowledge/ knowledge/
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
