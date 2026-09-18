#!/bin/sh
# ORP RIS — entrypoint Orthanc.
#
# Orthanc tidak mendukung substitusi env di file konfigurasi JSON, jadi
# placeholder di platform/orthanc.json.tmpl disulih di sini dengan nilai dari
# environment (ORP_ORTHANC_USERNAME / ORP_ORTHANC_PASSWORD) sebelum Orthanc
# dijalankan. Hasilnya ditulis ke /tmp (bukan ke mount read-only).
#
# Batasan yang disengaja: password hanya boleh berisi [A-Za-z0-9._~-] agar aman
# dipakai sebagai delimiter sed (tidak mengandung '#', '&', '\'). Validasi ini
# membuat container gagal cepat dengan pesan jelas, bukan diam-diam salah.
set -eu

TEMPLATE="${ORP_ORTHANC_TEMPLATE:-/etc/orthanc-orp/orthanc.json.tmpl}"
OUTPUT="${ORP_ORTHANC_CONFIG:-/tmp/orthanc-orp/orthanc.json}"

: "${ORP_ORTHANC_USERNAME:?ORP_ORTHANC_USERNAME wajib diisi}"
: "${ORP_ORTHANC_PASSWORD:?ORP_ORTHANC_PASSWORD wajib diisi}"

case "$ORP_ORTHANC_USERNAME$ORP_ORTHANC_PASSWORD" in
    *[!A-Za-z0-9._~-]*)
        echo "ORP: kredensial Orthanc hanya boleh berisi A-Z a-z 0-9 . _ ~ -" >&2
        exit 1
        ;;
esac

mkdir -p "$(dirname "$OUTPUT")"

sed -e "s#__ORP_ORTHANC_USERNAME__#${ORP_ORTHANC_USERNAME}#g" \
    -e "s#__ORP_ORTHANC_PASSWORD__#${ORP_ORTHANC_PASSWORD}#g" \
    "$TEMPLATE" > "$OUTPUT"

echo "ORP: konfigurasi Orthanc dihasilkan dari template (auth aktif, user=${ORP_ORTHANC_USERNAME})" >&2

exec Orthanc "$(dirname "$OUTPUT")"
