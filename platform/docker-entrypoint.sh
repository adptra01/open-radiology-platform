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

# 2-3. Tunggu DB siap + migrasi (force untuk produksi).
# `migrate --force` dipakai sebagai readiness probe sekaligus: gagal bila DB
# down (retry), sukses + idempotent bila sudah migrasi. (Dulu: `db:monitor
# --timeout=30` — opsi --timeout tidak ada di Laravel 13 → loop selamanya.)
echo "ORP: menunggu database + menjalankan migrasi..."
until php artisan migrate --force --no-interaction; do
    echo "  DB belum siap, retry 3s..."
    sleep 3
done

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