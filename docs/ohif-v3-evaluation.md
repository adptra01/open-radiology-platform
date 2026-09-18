# OHIF v3 Evaluation — Catatan Migrasi dari v2 (ohif/viewer) ke v3 (ohif/app)

> Status: **Evaluasi** — belum diimplementasikan. v2 (`ohif/viewer:latest`) masih dipakai di produksi saat ini.

---

## 1. Perbedaan Arsitektur v2 vs v3

| Aspek | OHIF v2 (`ohif/viewer`) | OHIF v3 (`ohif/app`) |
|-------|------------------------|---------------------|
| **Image Docker** | `ohif/viewer:latest` (nginx + static JS) | `ohif/app:latest` (nginx + static JS) — *atau build sendiri* |
| **Konfigurasi** | `app-config.js` (global `window.config`) | `static/config/default.js` + `extensions` |
| **Data Source** | `servers.dicomWeb[]` (array) | `dataSources[]` (array of objects dengan `type: 'dicomweb'`) |
| **Auth** | Header via nginx proxy (sama v3) | Sama — proxy nginx tetap menyuntikkan `Authorization` |
| **Extension** | Monolitik | Modular (package `@ohif/extension-*`) |
| **Build** | Pre-built image, config via mount | Bisa pakai pre-built atau custom build dengan extension sendiri |
| **Versi** | 2.x (maintenance) | 3.x (aktif, roadmap panjang) |

---

## 2. Konfigurasi v3 — Contoh `dataSources`

File: `platform/ohif-v3-config.js` (mount ke `/usr/share/nginx/html/config/default.js` di container v3)

```js
window.config = {
  // ... config lain ...
  dataSources: [
    {
      name: 'Orthanc PACS',
      type: 'dicomweb',
      wadoUriRoot: '/pacs/dicom-web/',           // relative ke origin (same-origin proxy)
      wadoRoot: '/pacs/dicom-web/',
      qidoRoot: '/pacs/dicom-web/',
      stowRoot: '/pacs/dicom-web/',
      // Auth: proxy nginx menyuntikkan Basic auth — tidak perlu config di sini
      // Bila butuh token custom: `headers: { Authorization: 'Bearer ...' }`
    },
  ],
  // Hotkeys, hanging protocols, dll tetap sama
};
```

> Catatan: v3 menggunakan `wadoUriRoot` untuk fallback WADO-URI (tidak dipakai), `wadoRoot/qidoRoot/stowRoot` untuk DICOMweb. Path relatif `/pacs/dicom-web/` cocok dengan proxy nginx yang sudah ada.

---

## 3. Docker Compose v3 (Perubahan Minimal)

```yaml
  ohif:
    image: ohif/app:latest   # ganti dari ohif/viewer:latest
    # ... env sama ...
    volumes:
      - ./platform/ohif-v3-config.js:/usr/share/nginx/html/config/default.js:ro
      - ./platform/ohif-nginx.conf.tmpl:/opt/orp/ohif-nginx.conf.tmpl:ro  # PROXY TETAP SAMA
      - ./platform/ohif-entrypoint.sh:/opt/orp/ohif-entrypoint.sh:ro
    entrypoint:
      - /bin/sh
      - -c
      - |
        /opt/orp/ohif-entrypoint.sh
        exec /usr/src/entrypoint.sh "$@"
      - sh
    command: ["nginx", "-g", "daemon off;"]
```

> **Proxy nginx (`/pacs/` → orthanc + Basic auth) TIDAK BERUBAH** — OHIF v3 tetap memanggil `/pacs/dicom-web/...` dari browser, nginx terus forward ke Orthanc dengan header `Authorization`. Browser tidak pernah tahu kredensial.

---

## 4. Migrasi Step-by-Step (Rencana)

| Tahap | Aksi | Verifikasi |
|-------|------|------------|
| 1 | Build image `ohif/app:latest` test di lokal (tanpa auth) | Viewer load, QIDO/WADO via proxy work |
| 2 | Tambah config v3 (`dataSources`) + mount | Studi muncul di viewer |
| 3 | Aktifkan Orthanc auth + proxy nginx (sudah ada) | Anonim 401, proxy 200 |
| 4 | Uji STOW/QIDO/WADO-RS rendered + rendered | Semua 200 |
| 5 | Ganti `ohif/viewer` → `ohif/app` di `docker-compose.prod.yml` | Deploy staging, smoke test |
| 6 | (Opsional) Custom extension: anotasi, MPR, SR | Build custom image jika perlu |

---

## 5. Catatan Penting

- **Tidak perlu ubah proxy** — arsitektur same-origin proxy sudah v3-compatible.
- **Auth** — tetap Basic auth via nginx proxy; v3 tidak punya built-in auth UI (same as v2).
- **Extension** — bila butuh fitur lanjut (MPR, SR, segmen), build custom image dengan `@ohif/extension-*` packages.
- **Rolling upgrade** — v2 dan v3 bisa berjalan berdampingan (port beda) untuk A/B test.

---

## 6. Keputusan Sementara

**Tetap di v2 (`ohif/viewer:latest`) untuk produksi pertama** karena:
1. Sudah terverifikasi end-to-end (STOW/QIDO/WADO via proxy, auth Orthanc, viewer embed di RIS).
2. v3 butuh validasi ulang semua alur (waktu terbatas).
3. v2 masih mendapat security patch dari OHIF team.

Migrasi v3 dijadwalkan **pasca-produksi v1** (backlog item terpisah).