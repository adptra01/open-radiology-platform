# ORP RIS — Docker image produksi (nginx + PHP-FPM + built assets)
# Build:   docker build -t orp-ris:latest -f ris/Dockerfile ris/
# Run:     docker run -d --name orp-ris -p 8000:8000 -e APP_KEY=... orp-ris:latest
# Compose: see docker-compose.prod.yml di root project

# ============================================================
# Stage 1: Build frontend assets (Node 24)
# ============================================================
FROM node:24-alpine AS frontend-builder

WORKDIR /app

# Install build deps
RUN apk add --no-cache python3 make g++

# Copy package files first (layer cache)
COPY package*.json ./
RUN npm ci --prefer-offline --no-audit --progress=false

# Copy source & config
COPY resources ./resources
COPY public ./public
COPY vite.config.ts ./
COPY tsconfig.json ./
COPY tailwind.config.js ./
COPY postcss.config.js ./

# Build assets (output ke public/build)
RUN npm run build


# ============================================================
# Stage 2: PHP-FPM base (Alpine, PHP 8.3)
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
# Stage 3: App dependencies (composer install --no-dev)
# ============================================================
FROM php-base AS app-deps

COPY composer.json composer.lock ./
# Copy database dir for migrations (referenced by artisan)
COPY database ./database

# Install deps (no dev, optimize autoloader)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache


# ============================================================
# Stage 4: Production image
# ============================================================
FROM php-base AS production

# Copy PHP deps + app code
COPY --from=app-deps /var/www/html/vendor ./vendor
COPY --from=app-deps /var/www/html/database ./database
COPY . .

# Copy built assets from frontend-builder
COPY --from=frontend-builder /app/public/build ./public/build

# Nginx config (runs on port 8000, proxies / to php-fpm)
COPY platform/nginx-ris.conf /etc/nginx/nginx.conf

# Supervisor config (queue worker + scheduler)
COPY platform/supervisord.conf /etc/supervisor/conf.d/orp-ris.conf

# Entrypoint: run migrations, cache config, start supervisord
COPY platform/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Create required dirs
RUN mkdir -p /var/log/supervisor /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Non-root user for nginx master (worker runs as www-data via config)
USER www-data

EXPOSE 8000

# Healthcheck endpoint
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD wget -qO- http://localhost:8000/api/health || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]