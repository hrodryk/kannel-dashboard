# syntax=docker/dockerfile:1.7

# ======
# Stage 1: frontend assets
FROM node:22-alpine AS node

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ======
# Stage 2: Composer deps
FROM composer:2.8 AS composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-progress

# ======
# Stage 3: runtime application
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache \
    acl \
    bash \
    curl \
    fcgi \
    file \
    gettext \
    git \
    gmp \
    icu-dev \
    icu-libs \
    libpq-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    linux-headers \
    oniguruma-dev \
    postgresql-client \
    postgresql-dev \
    shadow \
    supervisor \
    unzip \
    zip \
    nginx

RUN docker-php-ext-configure pcntl --enable-pcntl && \
    docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY .

# copy composer
COPY --chown=www-data:www-data composer.json composer.lock* ./
COPY --from=composer /app/vendor ./vendor

# copy built assets
COPY --from=node --chown=www-data:www-data /app/public/build ./public/build
COPY --from=node --chown=www-data:www-data /app/public/manifest.json ./public/manifest.json 2>/dev/null || true
COPY --from=node --chown=www-data:www-data /app/public/ssr ./public/ssr 2>/dev/null || true

# source code
COPY --chown=www-data:www-data . .

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY ./.docker/php/opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini
COPY ./.docker/nginx.conf /etc/nginx/http.d/default.conf
COPY ./.docker/supervisord.conf /etc/supervisord.conf
COPY ./.docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --retries=3 --start-period=30s \
  CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
