#!/bin/sh
# ORP RIS — penolong entrypoint container OHIF (nginx).
#
# Tugas: menghasilkan /etc/nginx/conf.d/default.conf dari
# platform/ohif-nginx.conf.tmpl, dengan header Basic auth Orthanc disuntikkan
# ke proxy /pacs/. Orthanc kini mewajibkan autentikasi HTTP, dan kita TIDAK
# ingin browser memegang kredensial — hanya container ini yang tahu.
#
# Mengapa bukan /docker-entrypoint.d/20-envsubst-on-templates.sh:
# skrip itu hanya bisa menyulih variabel environment, sedangkan header
# Authorization butuh base64 dari "user:password" (komputasi shell). Karena
# image ini menjalankan skrip di /docker-entrypoint.d/ sebagai proses
# terpisah (bukan `source`), hasil `export` tidak menyeberang — jadi template
# di-render di sini secara eksplisit.
set -eu

: "${ORP_ORTHANC_USERNAME:?ORP_ORTHANC_USERNAME wajib diisi untuk proxy /pacs/}"
: "${ORP_ORTHANC_PASSWORD:?ORP_ORTHANC_PASSWORD wajib diisi untuk proxy /pacs/}"

TEMPLATE="${ORP_NGINX_TEMPLATE:-/opt/orp/ohif-nginx.conf.tmpl}"
OUTPUT="${ORP_NGINX_OUTPUT:-/etc/nginx/conf.d/default.conf}"

AUTH_B64=$(printf '%s' "${ORP_ORTHANC_USERNAME}:${ORP_ORTHANC_PASSWORD}" | base64 | tr -d '\n')

sed "s#__ORP_ORTHANC_AUTH_B64__#${AUTH_B64}#g" "$TEMPLATE" > "$OUTPUT"

echo "ORP: ${OUTPUT} dibuat dari template; proxy /pacs/ memakai Basic auth Orthanc (user=${ORP_ORTHANC_USERNAME})"
