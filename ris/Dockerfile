# ORP RIS — Docker image produksi (nginx + PHP-FPM + built assets)
# Build (dari ROOT proyek, karena butuh platform/ + ris/):
#   docker build -t orp-ris:latest -f ris/Dockerfile .
# Atau via compose produksi (sudah ada blok build di ris service):
#   docker compose -f docker-compose.prod.yml build ris
# Run:     docker run -d --name orp-ris -p 8000:8000 -e APP_KEY=... orp-ris:latest
# Compose: see docker-compose.prod.yml di root project

# ============================================================
# Stage 1: PHP-FPM base (Alpine, PHP 8.3)
# ============================================================
FROM php:8.3-fpm-alpine AS php-base

# System deps untuk ekstensi PHP + nginx + supervisor
RUN apk add --no-cache \
    nginx \
    supervisor \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    oniguruma-dev \
    libxml2-dev \
    icu-dev \
    postgresql-dev \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        ctype \
        fileinfo \
        gd \
        mbstring \
        pdo \
        pdo_pgsql \
        xml \
        intl \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && rm -rf /tmp/pear /var/cache/apk/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html


# ============================================================
# Stage 2: App builder (composer + npm).
# Digabung dalam satu stage karena `npm run build` menjalankan
# `php artisan wayfinder:generate` (butuh PHP + vendor terinstall).
# Node 24 tersedia dari apk Alpine (sama dengan dev ddev).
# ============================================================
FROM php-base AS app-builder

# Node.js untuk build frontend (Alpine 3.24 → nodejs 24.x)
RUN apk add --no-cache nodejs npm python3 make g++

COPY ris/composer.json ris/composer.lock ris/package.json ris/package-lock.json ./
# Copy database dir for migrations (referenced by artisan)
COPY ris/database ./database

# Install deps (no dev, optimize autoloader) + npm (layer cache).
# --no-scripts: artisan belum bisa jalan (source penuh belum di-COPY).
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache \
    && npm ci --prefer-offline --no-audit --progress=false

# Copy sisa source lalu discover packages + build
# (php artisan tersedia untuk wayfinder & package:discover;
#  bootstrap/cache + storage dikecualikan .dockerignore → buat dulu)
COPY ris/ .
RUN mkdir -p bootstrap/cache storage/logs storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views \
    && php artisan package:discover --ansi --no-interaction \
    && npm run build


# ============================================================
# Stage 3: Production image
# ============================================================
FROM php-base AS production

# Copy PHP deps + built assets + app code (konteks root → prefix ris/;
# vendor/node_modules/build/fresh-env dikecualikan via .dockerignore root)
COPY --from=app-builder /var/www/html/vendor ./vendor
COPY --from=app-builder /var/www/html/public/build ./public/build
COPY ris/ .

# Nginx config (runs on port 8000, proxies / to php-fpm)
COPY platform/nginx-ris.conf /etc/nginx/nginx.conf

# Supervisor config (queue worker + scheduler)
COPY platform/supervisord.conf /etc/supervisor/conf.d/orp-ris.conf

# Entrypoint: run migrations, cache config, start supervisord
COPY platform/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Create required dirs
RUN mkdir -p /var/log/supervisor /var/www/html/storage/app/public /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/storage/logs /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Non-root user for nginx master (worker runs as www-data via config)
USER www-data

EXPOSE 8000

# Healthcheck endpoint
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD wget -qO- http://localhost:8000/api/health || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]