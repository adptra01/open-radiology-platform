# Rencana Pembangunan — ORP RIS

## 1. Visi & Prinsip

- **Vendor-neutral**: bekerja dengan Orthanc, DCM4CHEE, atau PACS apapun melalui standar DICOM (QIDO/WADO/STOW).
- **DICOM-native**: seluruh workflow modality → RIS → PACS melewati wire-protocol; bukan CRUD sederhana Laravel.
- **Modular**: Laravel (workflow & UI) + adapter Python tipis (hanya penerjemah protokol DICOM); komunikasi REST + X-API-Key; adapter tidak menyentuh DB langsung.
- **Scope MVP**: Core RIS + DICOM inti (MWL SCP, MPPS SCP, C-ECHO, C-STORE SCP, reconciliation, transmisi antrean). Reporting/Billing/AI/FHIR/HL7 ditunda (tapi struktur enum/event menyisakan ruang untuk F3–F6).

## 2. Stack & Layout

| Elemen | Keputusan |
|--------|-----------|
| Framework | Laravel 13 + Inertia v2 + React 19 + Tailwind v4 + Vite |
| UI | shadcn/ui-style (sudah terpasang via Laravel React starter) + Radix UI + TypeScript |
| DICOM layer | Adapter Python tipis (pynetdicom) — MWL SCP :4243, MPPS SCP :4244, C-STORE SCP :4245, C-ECHO SCU |
| DB | PostgreSQL (produksi); SQLite dev fallback (sudah diuji) |
| Repo layout | `ris/` (Laravel) + `adapter/` (Python) + `docker-compose.yml` (root) |
| Auth & RBAC | spatie/laravel-permission + enum Role (super-admin, admin, radiographer, radiologist, referring-doctor, registration, scheduler, pacs-admin, auditor) + AuditLog |

## 3. Struktur Repositori (D:\Projects\DCM4CHE)

```
DCM4CHE/
├─ ris/                    ← Laravel 13 + Inertia + React + Tailwind (sudah scaffold)
│  ├─ app/
│  ├─ database/
│  ├─ resources/js/        ← pages, components, routes (Inertia + React) — + pages/reports/ (M4)
│  ├─ .env                 ← DB_CONNECTION=pgsql via ddev (dev), produksi target PostgreSQL
│  └─ .env.example         ← DB target pgsql (produksi)
├─ adapter/                ← DICOM gateway Python (scaffold dalam tahap ini)
│  ├─ pyproject.toml
│  ├─ src/orp_adapter/
│  │  ├─ __init__.py
│  │  ├─ config.py         ← Settings dari .env (AE title, port, API URL, inbox dir)
│  │  ├─ ris_client.py     ← HTTP client ke Laravel /api/dicom/… (RisClient)
│  │  ├─ handlers/
│  │  │  ├─ mwl.py         ← C-FIND MWL SCP handler (yield dataset)
│  │  │  ├─ mpps.py        ← N-CREATE/N-SET/N-Action MPPS SCP handler
│  │  │  └─ store.py       ← C-STORE SCP handler + opsi persist ke inbox
│  │  ├─ application.py   ← Class Adapter: start_forever() start 3 SCPs (MWL/MPPS/STORE)
│  │  ├─ services/
│  │  │  ├─ echo_scu.py    ← C-ECHO SCU helper
│  │  │  └─ store_scu.py   ← C-STORE SCU helper (file path → peer)
│  │  └─ cli.py           ← Subcommands: run, ping, store
│  ├─ tests/
│  │  └─ sim_aet.py       ← Simulator SCU: C-ECHO, MWL query, C-STORE file
│  └─ .env.example        ← ORP_AE_TITLE, ORP_MWL_PORT, ORP_MPPS_PORT, ORP_STORE_PORT, ORP_RIS_API_URL, ORP_RIS_API_KEY, ORP_INBOX_DIR
├─ ai-worker/               ← AI inference CPU-only (BARU — selesai & terverifikasi)
│  ├─ pyproject.toml       ← torch 2.14.0+cpu (index CPU saja), torchxrayvision 1.5.4, fastapi
│  ├─ src/orp_ai/
│  │  ├─ config.py         ← ORP_AI_* env (device, model, threshold, top_k)
│  │  ├─ model.py          ← loader cached; daftar model dari model_urls (kebenaran terverifikasi)
│  │  ├─ inference.py      ← PNG/JPG/DICOM → 18 patologi paru (DenseNet121 "all")
│  │  ├─ batch.py          ← infer folder → JSON/CSV
│  │  ├─ server.py         ← FastAPI: GET /health, POST /infer, POST /infer/tb (M6)
│  │  └─ cli.py            ← Subcommands: models, infer, batch, serve
│  ├─ tests/test_smoke.py  ← 7 test hijau (pathologies, non-TB guard, sample CXR, TB degrade, /infer/tb)
│  └─ .env.example
├─ docker-compose.yml      ← Selesai (M5, terverifikasi): postgres:16 + orthanc + ohif + ai-worker + adapter
├─ platform/               ← Selesai (M5, terverifikasi): orthanc.json (DICOMweb+users, config dir sendiri),
│                            ohif-config.js (schema v2 + root same-origin), ohif-nginx.conf (proxy /pacs/)
├─ sample-data/            ← Dataset uji DICOM (DX chest, CT, MR knee, studi unknown)
├─ data/orthanc/           ← Volume Orthanc (dipakai saat `docker compose up`)
└─ .opencode/ & .agents/   ← Skills & hookify config ECC
```

## 4. Roadmap Fasa

| Fasa | Tanggal/Target | Fokus |
|------|----------------|-------|
| **M1** | Selesai | Scaffold ris/, konfigurasi .env, auth + RBAC, adapter skeleton (import test), verifikasi composer/artisan serve & npm build/dev |
| **M2** | **Selesai (2026-09-17)** | Domain: Patient, Doctor, Procedure Catalog, Scheduling, Modality Registry, PACS onboarding; migrasi + seeder — 7 tabel, 4 enums, IdentifierService, 7 model, 5 seeder, 46 test hijau |
| **M3** | **Selesai (2026-09-17)** | E2E MWL+C-ECHO + MPPS e2e (adapter ↔ Laravel endpoints); verifikasi C-STORE matching accession; test reconciliasi — **API DICOM Laravel (4 endpoint ber-key), MPPS lifecycle (N-CREATE→IN_PROGRESS, N-SET→ACQUIRED), C-STORE matching→COMPLETED, E2E penuh terverifikasi (C-ECHO/MWL/MPPS/C-STORE), 58 test Laravel + 12 test adapter hijau** |
| **M4** | **Selesai (2026-09-17)** | Reporting skeleton, enum/event Struktur (DICTATED→VERIFIED→FINAL), antrean transmission (database driver) — **ReportStatus enum+model+controller+routes, 10 test hijau (6 Reporting + 4 Transmission), UI skeleton Inertia `/reports` (tsc OK), auto-queue STOW-RS saat C-STORE** |
| **M5** | **Selesai + stack terverifikasi (2026-09-17)** | PACS integration lengkap (QIDO/WADO/STOW URLs), FHIR stub **ber-token (Sanctum)**, vendor stack nyata (postgres+orthanc+ohif) — **PacsClient 7 test, FHIR 8 test, docker-compose.yml + platform/{orthanc.json,ohif-config.js,ohif-nginx.conf}. Bukti: STOW-RS 200 → QIDO-RS (AccessionNumber VR SH) → WADO-RS 200 multipart/dicom; `orp-orthanc` healthy** |
| **M6** | **Selesai + E2E terverifikasi (2026-09-17)** | Alur inferensi: C-STORE → queue → ai-worker → hasil JSON → Laravel (→ OHIF readiness). Skrining TB (model terpisah, lihat §6). — **ai_results table+model+Job RunAiInference+AiClient, POST /infer/tb di ai-worker, 7 test Laravel + 7 pytest. E2E nyata: worker uvicorn di pydev, inferensi DICOM DX Chest PA 833 ms (5 findings), job penuh COMPLETED 985 ms. Alur Redis→worker diganti DB queue Laravel + HTTP call ke ai-worker (sederhana, non-fatal)** |

| **M7** | **Selesai (2026-09-17)** | REST API SPA + UI ORP lengkap — **23 endpoint/controller baru (pasien+merge, dokter, modalitas, prosedur, jadwal+transisi, study, audit, user+role, dashboard, order+awaiting-report, pacs CRUD+ping, transmisi retry), semua ber-RBAC (`authorizePermission`) + audit; 13 halaman Inertia (dashboard, worklist, patients, orders, orders/show, appointments, studies, viewer (embed OHIF), modalities, pacs, transmissions, audit, users) dengan tabel/pagination/filter/dialog bersama. Bukti: 14 endpoint → 200 via sesi nyata, 12 halaman Inertia → 200 komponen benar (termasuk `/viewer` embed OHIF), tsc+vp bersih, 119 test/487 assertion hijau** |
| **M8** | **Selesai (2026-09-17)** | Pengerasan produksi: Orthanc auth + kredensial klien — **AuthenticationEnabled: true**, `RegisteredUsers` lewat entrypoint template (platform/orthanc.json.tmpl + platform/orthanc-entrypoint.sh); proxy OHIF menyuntikkan Basic auth (platform/ohif-nginx.conf.tmpl + platform/ohif-entrypoint.sh). `pacs_sources` + kolom `username`/`password` (encrypted cast) + PacsClient mengirim Basic auth bila kredensial ada. UI PACS: field username/password + badge `has_credentials`. Bukti: anonim direct 401, via proxy 200, STOW/QIDO/WADO-RS proxy sukses, PacsClient ping+QIDO live vs Orthanc ber-auth ✅, 9 test PacsClient baru hijau (total 121 test / 495 assertions)** |
| **M9** | **Selesai (2026-09-19)** | Stabilisasi fork AqQkIio1 → DCM4CHE: root `.gitignore`, hapus `*.bak`, VR SH 0-warning, verifikasi penuh **127/526 + 12 + 7, tsc 0, build 7.42s, compose dev+prod valid**. Penutup: `data/` 0 tracked, ORD-vs-SH tertutup (15 char), OHIF **v2 final (bukan v3)**, image **`orp-ris:latest` 1.4GB built** (compose prod, exit 0). Ditunda tanpa batas waktu: bobot TB real (Colab dilewati) |
| **M10** | **Selesai (2026-09-19)** | Big-bang AI Gateway foundation (keputusan final): paket **`ai_gateway/`** (api/core/dicom/tasks-tb/models + envelope terkunci + threshold 0.50 uncalibrated), **`ai_runs` canonical** (run_id AIR-YYMMDD-XXXX, API capabilities/runs 202, Job idempotent + dual-write legacy), **`AiAssistancePanel`** generik (tsc 0, build 9.72s). Bukti: **pytest 57 + phpunit 135 hijau**, E2E live CR→completed / CT→failed `UNSUPPORTED_MODALITY` / unknown→404, tsc 0, build 9.72s, terverifikasi ulang + commit 2026-09-19 |
| **M11** | **Backlog (Phase 2-5)** | E2E bobot real + kalibrasi threshold; hapus shim `orp_ai` + card legacy (cutover entrypoint ke `ai_gateway.server` SELESAI 2026-09-19, flag `--legacy` tersedia); integrasi penuh; auditability. Out-of-scope tetap: model CT/MR/US, LLM report, auto-diagnosis |

> **Catatan roadmap**: `ai-worker` M0 sudah selesai dan terverifikasi (lihat §5). M6 menautkannya ke alur produksi.

## 5. Prasyarat (sudah selesai)

- ✅ Scaffold Laravel 13.17 (`ris/`), Inertia+React preset, Dokumen struktur tidak perlu — pakai
- ✅ PostgreSQL driver `pdo_pgsql`; **dev kini PostgreSQL 16 via ddev** (`ddev artisan migrate:fresh --seed`; SQLite hanya untuk test suite `:memory:`)
- ✅ Auth scaffolding Fortify + login 3 pengguna (super-admin, radiographer, radiologist)
- ✅ RBAC: 34 permission, 9 role dengan `can()` working (sudah divalidasi di tinker)
- ✅ Adapter Python skeleton terinstall (`uv sync`); modul import lancar: `orp_adapter`, `handlers`, `services`, `ris_client`
- ✅ Sample-data DICOM tersedia untuk tes manual
- ✅ **AI worker CPU-only selesai & terverifikasi (M0)**: torch 2.14.0+cpu (tanpa CUDA) + torchxrayvision 1.5.4 di `.venv` proyek; CLI (`orp-ai infer|batch|serve`) dan FastAPI (`GET /health`, `POST /infer`) diuji hijau; 4 pytest passed; inferensi berjalan pada sample DX chest PA (PNG & DICOM), ~semua 18 patologi paru dilaporkan.
- ✅ Container `pydev` mendapat bind-mount `/mnt/DiskD/Projects/DCM4CHE:/projects/DCM4CHE` (ditambahkan ke `Workspace/Docker/Python/compose.yml`) agar `uv`/`pytest` jalan di dalam container.

## 6. Risiko & Catatan

### 6a. Riset AI CPU — fakta terverifikasi (2026-09-17)

Keputusan: **AI berjalan CPU/RAM saja** (GTX 1650 4GB tanpa nvidia-container-toolkit; runtime `runc`).

- ✅ **TorchXRayVision DenseNet121 `densenet121-res224-all`** = siap pakai tanpa GPU; mencakup **18 patologi paru**: Atelectasis, Consolidation, Infiltration, Pneumothorax, Edema, Emphysema, Fibrosis, Effusion, Pneumonia, Pleural_Thickening, Cardiomegaly, Nodule, Mass, Hernia, Lung Lesion, Fracture, Lung Opacity, Enlarged Cardiomediastinum.
- ❌ **Klaim riset awal SALAH**: TorchXRayVision **tidak** menyertakan label Tuberculosis/PulmonaryTuberculosis/ActiveTuberculosis pada semua checkpoint pretrained-nya (diperiksa langsung pada 7 checkpoint, v1.5.4). TBX11K & NLM (Montgomery/Shenzhen) hanya tersedia sebagai **dataset** untuk training (`xrv.datasets.TBX11K_Dataset`, `xrv.datasets.NLMTuberculosisDataset`) — bukan bobot model.
- Model HF bertema TB mayoritas ber-unduhan 0–80 (belum teruji klinis) — **tidak layak produksi** tanpa validasi.
- Opsi pengembangan TB (M6):
  1. **Fine-tune sendiri** DenseNet121 (pretrained ImageNet torchvision) pada Shenzhen (662) + Montgomery (138) + TBX11K — paling sesuai semangat "versi kita sendiri"; feasible di CPU: 800 gambar, 224px, 10 epoch ≈ 30–60 menit, RAM < 8GB. Hasilnya **alat skrining/bantuan triase**, bukan alat diagnostik — perlu validasi klinis & batas penggunaan eksplisit.
     - **Status 2026-09-19**: training Colab **sedang berjalan** di session `tb-train8` (T4 GPU, free-tier; notebook `ai-worker/notebooks/tb_finetune_colab.ipynb`). Artifact target `/content/tb_densenet121.pt` dipantau **poller background** (`ai-worker/scripts/tb_poll_download.sh`, PID 205191, polling 30s×60) → begitu terdeteksi langsung download + **backup ganda (.bak1/.bak2) + SHA-256 + load-test**; verifikasi & load-test di `ai-worker/scripts/tb_secure_artifact.sh`. Lihat changelog 2026-09-19.
     - **Fallback GPU**: notebook **Kaggle** `ai-worker/notebooks/tb_finetune_kaggle.ipynb` (commit `9da0be7`, 2026-09-19) — sama-sama dataset publik, output `.pt` baca balik. Dipakai otomatis bila Colab kena kuota/auto-stop lagi.
  2. **mednet** (Idiap, open-source, PyPI `mednet`): baseline DenseNet TB (Shenzhen & montgomery-shenzhen-indian-tbx11k) bisa diunduh dari GitLab experiment page; dataset tetap harus diunduh terpisah (NLM/OpenI).
  3. Gabung 1+2: evaluasi baseline mednet, lalu fine-tune lanjut dengan data lokal.
- **Pilihan eksekusi (2026-09-17)**: bridging **Google Colab** disetujui untuk fase training — notebook `ai-worker/notebooks/tb_finetune_colab.ipynb` siap pakai. Data publik diunduh ke runtime Colab; hanya bobot (±35MB) yang diambil balik. **Dilarang**: mengirim data pasien/institusi ke Colab (UU PDP). Penyimpanan lokal diringankan: `qure_images.tar` (49GB duplikat blobs) sudah dihapus.

### 6b. Catatan teknis lain

- Commit `2534fb5c` menghapus seluruh app lama; proyek benar-benar dari nol (tidak ada code turun-temurun).
- Folder `platform/` belum ada di disk — perlu dibuat ulang orthanc.json + ohif config (dari tree `68d72f72`).
- pynetdicom versi 3.x API berbeda versi 2.x; handler menggunakan `evt_handlers` list + `start_server(block=False, evt_handlers=...)`.
- Lokal dev: **PostgreSQL 16 via ddev** (`ris.ddev.site:8443`, DB `db/db@db:5432`); SQLite hanya dipakai test suite `:memory:`. Produksi target PostgreSQL.
- Queue driver `database` sudah aktif; PCNTL tidak ada di Windows → driver database dipakai (migrasi sudah jalan).
- `STORE_SCU` butuh transfer syntax JPEG Lossless — adaptor sekarang support standar umum; kasus spesifik konfigurasi Orthanc perlu dicek.

## 7. Keputusan Final M2

### Identifikasi Pasien & Order

- **MRN (Medical Record Number)**: otomatis `MRN-YYYYMMDD-XXXX` (contoh: `MRN-20260916-0001`). Di-generate saat pembuatan pasien baru, pastikan unik per hari + counter random 4 digit.
- **Accession Number**: `ACC-YYMMDD-XXXX` (contoh: `ACC-260916-0001`) — **15 karakter**. Di-assign saat Order dibuat, terkait dengan Study yang akan dibuat di PACS. ⚠️ VR DICOM **SH = maks 16 karakter**, jadi format sengaja dipendekkan (format lama `ACC-YYYYMMDD-XXXX` = 17 karakter melanggar batas ini dan memicu peringatan pydicom / berisiko ditolak modalitas produksi). Kapasitas 10.000/hari, kolom unik DB + retry sebagai pengaman tabrakan.
- **Facility**: belum ada tabel khusus. Cukup kolom `facility_id` nullable di tabel-tabel penting (siap multi-site nanti). Tidak dipakai di MVP pertama tapi struktur sudah siap.

### Priority & Status

- **Priority**: hanya 3 level: `STAT`, `URGENT`, `ROUTINE`.
- **Soft Delete**: ya, dipakai di hampir semua tabel master & transaksi penting. **Bukan dari script PACS** — ini bagian dari kode RIS sendiri.

### Desain MWL (Critical)

- **MWL berasal dari adapter Python RIS sendiri** (port 4243), **bukan** dari Orthanc maupun DCM4CHEE.
- Alur: Modality `C-FIND` → Adapter Python (MWL SCP) → Laravel RIS (`/api/dicom/worklist`) → Query database (Order + Patient + Procedure + Appointment) → Kembalikan Worklist ke Modality.
- **Bukan** script di dalam Orthanc, **bukan** MWL bawaan DCM4CHEE, **bukan** hardcode.
- Ini sesuai prinsip awal: **RIS harus independen terhadap PACS**.

### Antrean & Transmisi (M4 reference)

- Driver queue tetap `database` (karena PCNTL tidak ada di Windows). Implementasi M4: `jobs` table + `ProcessTransmission` (STOW-RS) & `RunAiInference` (HTTP ke ai-worker).
- Status Order alur: `REQUESTED → SCHEDULED → ARRIVED → IN_PROGRESS (MPPS N-CREATE) → ACQUIRED (MPPS N-SET COMPLETED) → COMPLETED (C-STORE match)`. Terminal: `CANCELLED/NO_SHOW/REJECTED`.
- Fase report (M4, sudah diimplementasi): `DRAFT → DICTATED → VERIFIED → FINAL` (+CANCELLED) via `ReportStatus::allowedTransitions()` + audit trail.

### AI Pipeline (M6 reference)

- Alur aktual: C-STORE → `Study` tersimpan → `AiResult` (PENDING) dibuat + `RunAiInference` di-dispatch → `AiClient::infer()` POST multipart ke ai-worker `/infer` → findings disimpan; bila `/health.tb.available` → tambah `/infer/tb` ke `raw_report['tb']` (graceful bila bobot TB belum ada).
- Auto-dispatch dikendalikan `ORP_AI_AUTO_INFER` (default true; `false` di phpunit).
- Re-run manual: `POST /api/ai-results/run/{study}`.
- OHIF: `platform/ohif-config.js` menunjuk root DICOMweb **same-origin** `http://localhost:3000/pacs/dicom-web` (proxy nginx `/pacs/` → `orthanc:8042` di `platform/ohif-nginx.conf`) — bukan `:8042` langsung, karena Orthanc tidak mendukung CORS. Viewer siap saat stack compose dinyalakan.

### Vendor Stack — konfigurasi yang WAJIB (M5 reference, terverifikasi 2026-09-17)

Beberapa setelan di bawah ini bukan preferensi, tapi syarat agar stack benar-benar hidup (semuanya sudah diterapkan di `docker-compose.yml` + `platform/`):

- **Orthanc memakai direktori config sendiri**: `command: ["/etc/orthanc-orp/"]` + mount `platform/orthanc.json` → `/etc/orthanc-orp/orthanc.json`. Image `jodogne/orthanc-plugins` juga memuat `/etc/orthanc/advanced.json`; menimpa `orthanc.json` saja → `Bad file format: section "KeepAlive" is defined in 2 different configuration files` (crash-loop).
- **`RemoteAccessAllowed: true`**: tanpa ini Orthanc menjawab **401 untuk semua request non-loopback** (pesan log "remote access is not allowed"), termasuk dari host/browser.
- **`Plugins: ["/usr/local/share/orthanc/plugins/"]`**: path `/usr/share/orthanc/plugins/` tidak ada di image ini, jadi plugin (stone-webviewer, OHIF, Gdcm, …) tidak akan dimuat.
- **Tidak ada CORS di Orthanc** (sengaja, lihat Orthanc Book FAQ "Same-origin policy") — section `Cors` di `orthanc.json` diabaikan diam-diam. Akses browser diselesaikan dengan proxy same-origin `/pacs/` di nginx container OHIF; ini juga menghindari `Access-Control-Allow-Origin: *` pada endpoint ber-PHI.
- **Healthcheck Orthanc** memakai `/system` (mainline), bukan `/orthanc/system` (404).
- **OHIF = lini v2**: schema config `servers.dicomWeb` (array), bukan `servers.<nama>` / `dataSources` (v3). Untuk produksi pertimbangkan `ohif/app` (v3) + auth Orthanc (token/proxy).
- **Dev auth Orthanc nonaktif** (`AuthenticationEnabled: false`) agar OHIF bisa membaca DICOMweb tanpa header; produksi: aktifkan auth + `RegisteredUsers` dan sesuaikan klien (adapter sudah mendukung kredensial).
- Verifikasi nyata: STOW-RS 200 → QIDO-RS menemukan study (AccessionNumber VR **SH**) → WADO-RS 200 `multipart/related; type="application/dicom"`; container `orp-orthanc` **healthy**.