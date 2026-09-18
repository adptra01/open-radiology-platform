#!/bin/sh
# ORP RIS — Entrypoint container produksi
# Tugas: migrasi DB, cache config, generate key bila belum ada, start supervisord

set -eu

cd /var/www/html

# 1. Generate APP_KEY jika belum ada (ENV bisa override)
if [ -z "${APP_KEY:-}" ]; then
    echo "ORP: APP_KEY tidak diset — generate baru..."
    php artisan key:generate --force --no-interaction
fi

# 2. Tunggu DB siap (PostgreSQL) — retry loop
echo "ORP: menunggu database..."
until php artisan db:monitor --timeout=30 2>/dev/null; do
    echo "  DB belum siap, retry 3s..."
    sleep 3
done

# 3. Migrasi (force untuk produksi — hati-hati!)
echo "ORP: menjalankan migrasi..."
php artisan migrate --force --no-interaction

# 4. Cache config, route, view (optimasi produksi)
echo "ORP: cache konfigurasi..."
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# 5. Storage link (public/storage -> storage/app/public)
if [ ! -L public/storage ]; then
    php artisan storage:link --no-interaction 2>/dev/null || true
fi

# 6. Jalankan Supervisord (nginx + php-fpm + queue worker + scheduler)
echo "ORP: memulai supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/orp-ris.conf