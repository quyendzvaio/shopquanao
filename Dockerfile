# ============================================================
# Stage 1: Builder — install deps, build assets
# ============================================================
FROM php:8.2-apache AS builder

# System deps
RUN apt-get update -qq && apt-get install -y -qq \
    unzip \
    git \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mysqli \
    zip \
    gd \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy only composer files first (layer caching)
COPY composer.json composer.lock* ./
RUN mkdir -p api/cache api/controllers/chatbot && if [ -f composer.json ]; then composer install --no-dev --no-interaction --no-progress --optimize-autoloader; fi

# ============================================================
# Stage 2: Production image
# ============================================================
FROM php:8.2-apache

# PHP extensions (runtime only)
RUN docker-php-ext-install -j$(nproc) pdo_mysql mysqli opcache \
    && a2enmod rewrite

# Apache config
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Copy Apache vhost
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# PHP production config
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-shop.ini

# Working directory
WORKDIR /var/www/html

# Copy only what's needed for production
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /var/www/html/vendor /var/www/html/vendor

COPY api/              /var/www/html/api/
COPY config/           /var/www/html/config/
COPY includes/         /var/www/html/includes/
COPY css/              /var/www/html/css/
COPY sql/              /var/www/html/sql/
COPY docker/           /var/www/html/docker/
COPY knowledge/        /var/www/html/knowledge/

# Copy static images (optimized)
COPY images/           /var/www/html/images/

# Copy entry PHP files
COPY *.php             /var/www/html/
COPY admin/*.php       /var/www/html/admin/
# postman/ excluded — CI only

# Ownership
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Security: remove unnecessary tools
RUN rm -rf /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini 2>/dev/null || true

# Healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/api/products?limit=1 || exit 1

EXPOSE 80

USER www-data
