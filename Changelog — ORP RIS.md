# Changelog — ORP RIS Development

All notable changes to this project will be documented in this file.

### Verified — Big-bang gateway terverifikasi penuh + commit (2026-09-19)
- **Suite**: pytest **57 passed**, phpunit **135 passed** (552 assertions), `tsc --noEmit` 0 error, `npm run build` OK via ddev. Satu regresi worker-legacy tertangkap & diperbaiki sebelum commit (fallback `/infer/tb` + normalisasi payload).
- **E2E live** `ai_gateway.server` (uvicorn, bobot dummy): capabilities 1 task available; CR→completed positive/0.53/threshold 0.5/uncalibrated; CT→failed `UNSUPPORTED_MODALITY` + pesan UI bersih; unknown task→404. Pelajaran: tunggu server >6s (import torch) sebelum curl.
- **Commit**: fondasi M10 (paket `ai_gateway/`, `ai_runs`, panel) siap push ke `main`.

### Added — Big-bang AI Gateway foundation: ai_gateway/ + ai_runs + AI Assistance (2026-09-19)
- **Keputusan arsitektur final terkunci**: framework generik, implementasi nyata hanya TB; `ai_runs` canonical, `ai_results`/`raw_report['tb']` legacy; threshold 0.50 + `calibration.status:"uncalibrated"`; trigger manual; Python stateless tanpa DB.
- **Python `ai-worker/src/ai_gateway/`** (paket baru, `orp_ai/` utuh sampai cutover): `api/{health,capabilities,runs}`, `core/{registry,dispatcher,envelope,models,exceptions}`, `dicom/{reader,validator,preprocessing}`, `tasks/tb/{adapter,preprocessing,model,inference}`, `models/tb-densenet121/1.0/{metadata.json,preprocessing.json}`. Envelope terkunci: classification{label,score,threshold}+calibration / failed{error{code,message}} + disclaimer ID+EN. `POST /infer/{task}` generik, `/infer/tb` alias, unknown→404.
- **Laravel**: migrasi `ai_runs` (run_id `AIR-YYMMDD-XXXX` 15-char + index study/series/task/status/created) + model `AiRun` + `IdentifierService::nextRunId()`; `AiRunsController` (`GET /api/ai/capabilities`, `POST /api/ai/runs`→202, show/index); `AiClient::inferTask()/capabilities()` + fallback legacy `/infer/tb` (worker lama) + normalisasi envelope; Job `RunAiRun` (idempotent, dual-write legacy). `POST /infer/tb` tetap kompatibel.
- **React**: `AiAssistancePanel` capability-driven (Run per task, polling aktif, renderer classification + calibration badge, failed + technical-details toggle, riwayat runs, disclaimer, seksi legacy) mounted di orders/show di atas `AiTbCard` (fallback, tidak diubah). `tsc` bersih, `npm run build` 9.72s via ddev.
- **Bukti**: pytest **57 passed** (26 dicom + 7 smoke + 11 gateway lama + 13 paket baru); phpunit **135 passed** (127+8); E2E live `ai_gateway.server`: CR→completed positive/0.5299, CT→failed `UNSUPPORTED_MODALITY`, unknown→404. Regresi legacy worker (`/infer/tb` tanpa envelope) diperbaiki via fallback + normalisasi.

### Added — M10 AI Gateway: task registry + structured AI-NOT-RUN (2026-09-19)
- **`POST /infer/{task}` generik** (`ai-worker/src/orp_ai/server.py`): dispatch via `adapters.run_task()`; task tak dikenal → 404 bersih (lihat `GET /capabilities` dulu), bukan 500. `/infer/tb` kini **alias via registry** (`run_task("tb-screening")`) — TB benar-benar plugin, bukan core server.
- **Reason code terstruktur** (`dicom.DicomValidationError.code` + `USER_REASONS`): `unsupported_modality/photometric/bits`, `inconsistent_bits`, `unsupported_planar`, `missing_pixel_data`, `unreadable_dicom`, `weights_missing`, `inference_error`. Respons `available=False` membawa `reason_code` + `reason` (UI) + `note` (audit). `validate_dicom_only()` ikut mengembalikan ketiganya.
- **Test**: `tests/test_gateway.py` 11 test (registry shape, dispatch generik, 404, alias, reason codes, stabilitas surface). Suite penuh **44 passed** (26 dicom + 7 smoke + 11 gateway), tanpa regresi.
- **Dok**: `ai-worker/docs/M10-gateway-task-registry.md` (kontrak endpoint + reason codes + cara tambah task baru). Colab tetap dilewati; OHIF v2 final.

### Changed — M9 ditutup: OHIF v2 tetap + image orp-ris:latest (2026-09-19)
- **Keputusan OHIF FINAL (user, 2026-09-19)**: pakai lini v2 (`ohif/viewer` + `servers.dicomWeb` array, proxy Basic-auth terverifikasi jalan) — **BUKAN v3**. `docs/ohif-v3-evaluation.md` dinyatakan arsip referensi; tidak ada rencana migrasi ke `ohif/app` (v3, schema `dataSources`).
- **Colab DILEWATI untuk saat ini** (user, 2026-09-19): free-tier `Service Unavailable`; training bobot TB real ditunda tanpa batas waktu. Fallback Kaggle (`ai-worker/notebooks/tb_finetune_kaggle.ipynb`, butuh `Internet: ON` manual di browser) tetap tersedia saat dibutuhkan.
- **Sisa M9 tertutup**: `data/` = 0 tracked (ter-ignore root `.gitignore`, `git rm --cached` tidak perlu); isu ORD-vs-SH tertutup (`ORD-YYMMDD-XXXX` 15 char, aman VR SH ≤16).
- **Build produksi**: `docker compose -f docker-compose.prod.yml build ris` → `orp-ris:latest` **1.4GB** sukses (exit 0, export+unpack ok). Konteks aman berkat `.dockerignore` root (`data/` 315M + `weights/*.pt` + vendor/node_modules dikecualikan). Development & verifikasi tetap lewat **ddev** (`ris/.ddev`, stack sehat: PHP 8.3 + Postgres 16, migrasi 0 pending).
- **Ditunda tanpa batas waktu**: bobot TB real (Colab dilewati; Kaggle fallback tersedia; dummy `tb_densenet121.pt` tetap untuk loop verifikasi M8).

### Updated — Sesi Colab training TB DenseNet121 + poller background (2026-09-19)
- **Session `tb-train8`** (T4 GPU, variant GPU, free-tier) dijalankan via Colab CLI 0.6.0 dengan shim `sitecustomize.py` (koreksi `KernelClient` → `JupyterKernelClient`) + sintaks benar: `colab exec -s tb-train8 --timeout 3600 -f ai-worker/scripts/tb_finetune_run.py`. Training fine-tune DenseNet121 (Montgomery+Shenzhen, dataset publik) berjalan di `/content`, artifact disimpan sebagai `tb_densenet121.pt` (cwd `/content`).
- **Poller background terpisah** (`ai-worker/scripts/tb_poll_download.sh`, via `nohup` PID 205191): polling `colab ls -s tb-train8 /content/tb_densenet121.pt` tiap 30s × 40 iterasi; begitu file terdeteksi → `colab download` bermultiple attempt (retry 5×8s) → backup ganda `.bak1`+`.bak2` → `sha256sum` → exit code bersih (0=sukses, 2=download gagal, 3=polling habis).
- **Koreksi sintaks Colab CLI yang benar (temuan penting)**: `colab exec` **tidak punya opsi `--keep`** (hanya `-s/--session`, `-f/--file`, `--timeout`, `--output-image`); `colab ls`/`colab download` menerima **path sebagai argumen POSITIONAL**, bukan flag `-p`. Poller lama gagal karena memakai `-p` yang tidak ada → harus `colab ls -s <session> <path>`.
- **Nama bobot konsisten**: trainer `tb_finetune_run.py` default `--out tb_densenet121.pt`; poller memakai path yang sama (`/content/tb_densenet121.pt`) → deteksi akurat, tanpa false-positive grep.
- **Catatan**: Colab free tier **auto-stop** begitu training selesai (kernel di-recycle) → file `/content/...` hilang jika tidak sempat download. Strategy saat itu: picu `colab exec` dengan `--timeout 3600` (batas bash tool tidak perlu menunggu lama; kernel tetap hidup, training jalan), lalu jalankan **poller di mesin lokal** (bukan menunggu output bash yang timeout) supaya download/backup tetap terjadi walau tool bash dikanal timeout.
- `ai-worker/scripts/tb_secure_artifact.sh`: verifikasi artifact setelah download — checksum SHA-256 + backup ganda + load-test `torch.load` (bobot ~35MB; bukan data pasien, patuh UU PDP).

### Fixed — image ris tidak serve HTTP + supervisord permission (2026-09-18, temuan deploy mini_pacs)
- **nginx + php-fpm tidak pernah jalan**: `supervisord.conf` hanya berisi queue/scheduler/monitor. Ditambah `[program:nginx]` + `[program:php-fpm]`; upstream nginx → `127.0.0.1:9000` (default pool www.conf; hindari unix socket yang butuh root).
- **Permission**: supervisord pidfile `/var/run/…` → `/tmp/`; nginx pid → `/tmp/nginx.pid`; temp dirs (`client_body`, `proxy`, `fastcgi`, …) → `/tmp/*` (prefix Alpine `/var/lib/nginx` milik root); Dockerfile `chown www-data` untuk `/var/log/supervisor` + `/var/log/nginx`.
- **Bukti lokal**: `nginx` + `php-fpm` RUNNING di supervisord, `GET :18002/api/health` merespons JSON (503 ekspektasi dgn sqlite `:memory:` tanpa tabel jobs — artifact test; di pgsql prod tabel ada via migrasi entrypoint).
- Catatan deploy: bind-mount `./logs/*` dibuat root oleh docker → sekali `chown -R 82:82 logs/` (uid www-data Alpine) di server.

### Added — Dockerfile adapter & ai-worker (2026-09-18)
- Repo sebelumnya **tidak punya** Dockerfile untuk keduanya (compose `build: context: ./adapter` selalu gagal `Dockerfile: no such file` — terbukti saat deploy mini_pacs). Kini: `adapter/Dockerfile` (python:3.13-slim, uv sync, non-root `app`, EXPOSE 4243-4245) + `ai-worker/Dockerfile` (python:3.12-slim sesuai `requires-python`, torch CPU via uv index, non-root, EXPOSE 8000) + `wget` untuk healthcheck compose.
- **Konteks diseragamkan ke root** (`context: .` + `dockerfile:`) di compose dev + prod; `.dockerignore` diperbaiki (sebelumnya mengecualikan `adapter/`+`ai-worker/` sehingga COPY gagal; kini hanya data/secrets/artifacts + `weights/*.pt`).
- **Bukti lokal**: build adapter 7s + ai-worker 122s sukses; smoke adapter online (MWL/MPPS/STORE) dan ai-worker `/health` ok 18 patologi + `tb.available=false` (ekspektasi tanpa bobot).

### Fixed — Colab CLI 0.6.0 `KernelClient` AttributeError (2026-09-18)
- Akar: `google-colab-cli` 0.6.0 memanggil `jupyter_kernel_client.KernelClient`, tapi dependensi git-nya me-rename kelas menjadi `JupyterKernelClient` (permukaan API sama: ctor kwargs + `.start()/.execute()/.id/_own_kernel`). Semua perintah eksekusi (`install`, `exec`) gagal; `new`/`stop` tidak terpengaruh.
- Perbaikan host-lokal (bukan repo): shim `sitecustomize.py` di venv uv tool (`~/.local/share/uv/tools/google-colab-cli/.../site-packages/`) berisi alias satu baris. **Wajib dipasang ulang setiap `colab update`** (reinstall menghapusnya). Terverifikasi E2E: `new` → `exec print` → output → `stop`.
- Pelajaran path: `colab exec -f` membaca file **lokal** relatif terhadap cwd — jalankan dari root repo (`/mnt/DiskD/Projects/DCM4CHE`), bukan dari `ai-worker/notebooks/`.

### Fixed — Colab session quota limit (2026-09-18)
- `colab new` / `colab run` error: `Precondition Failed (412)` — batasan kuota Google Colab free tier. Terjadi setiap kali membuat session GPU T4 berurutan terlalu cepat tanpa jeda. Tidak menjadi bug code, melimit sisi Google Colab API.
- Solusi practical: tutup session lama (`colab stop -s <nama>`), tunggu 30-60 detik antar pembuatan session, atau gunakan `colab run --keep` agar session tetap aktif untuk eksperimen berulang. Saat ini `tb.available=false` karena bobot belum berhasil didownload dari Colab.

### Added — deploy trial mini_pacs (2026-09-18)
- Server `mini_pacs` (Ubuntu 22.04, 15G RAM, 43G disk, Docker 29.7): clone publik ke `~/projects/orp-ris`, `.env.prod` di-generate di server (`chmod 600`, secret tak pernah keluar), port trial 8002/3001/8042/4246/8001 (bentrok portainer/waha/mcu-gateway di 8000/3000/4242).
- **Bug ditemukan saat deploy & diperbaiki**: (1) `db:monitor --timeout` tak ada di L13 → probe via `migrate --force` loop; (2) supervisord tanpa nginx/php-fpm + pid/run + temp root-owned → program lengkap, pid/temp di `/tmp`, upstream TCP 9000, chown log; (3) `schedule:run` one-shot → loop 60s, `queue-monitor` CLI invalid dihapus; (4) log bind-mount root-owned tanpa sudo → named volumes; (5) seed butuh faker (dev-only) → command baru `orp:create-user` + `ADMIN_PASSWORD` env; (6) `--env-file` tak masuk kontainer → `ADMIN_PASSWORD` didaftarkan eksplisit.
- **Bukti trial**: `ris:8002` login 200 + `/api/health` healthy (db ok, queue 0, ai ok `tb_available:false`, orthanc ok); orthanc anon 401/auth 200; ohif 200; ai `/health` 18 patologi; adapter healthy; 34 permissions + 3 user + 3 pasien/order demo. Kredensial demo: `admin@testing.com`/`radio@`/`dr@` + password di `ADMIN_PASSWORD` server (minta ke admin server).
- OHIF **tetap v2** (keputusan user).

## [Unreleased] — 2026-09-18 (ORD 15-char + Colab CLI + port prod override)

### Changed — Order Number dipendekkan (opsi a, diputuskan user)
- `IdentifierService::nextOrderNumber()`: `ORD-YYYYMMDDHHMMSS-XXXX` (23 char) → `ORD-YYMMDD-XXXX` (**15 char**, 10.000/hari + retry tabrakan) — kini aman untuk `RequestedProcedureID` MWL (VR SH ≤16). Isu ORD-vs-SH **tertutup**.
- `RadiologyDomainTest`: regex + assert regresi SH untuk order_number. Suite: RadiologyDomain 7/26, DicomApi 17/76, Workflow 10/64, MasterData 13/69 — hijau.
- Catatan: baris dev lama berformat 23-char tetap valid di DB (kolom string, tanpa constraint panjang); RPT tidak diubah (tidak lewat wire DICOM).

### Added — fine-tune TB via Colab CLI (headless)
- `ai-worker/scripts/tb_finetune_run.py`: cermin 1:1 notebook (dataset HF publik Montgomery+Shenzhen, seed 42, DenseNet121→BCE, 20 epoch, split stratify, evaluasi, checkpoint format `tb.py`). Cara pakai: `colab new -s tb-train --gpu T4` → `colab install …` → `colab exec -f …` → `colab download … ai-worker/weights/` → `colab stop`. CLI 0.6.0 terpasang via `uv tool` (butuh login Google OAuth sekali — interaktif oleh user).
- **Catatan kuota**: `colab new` / `colab run` gagal `Precondition Failed (412)` jika membuat session berurutan tanpa jeda — batasan API Google Colab free tier. Solusi: `colab stop -s <nama>` sebelum session baru, atau tunggu 30-60 detik. Jika gagal terus, gunakan `colab run --keep` atau jalankan manual di Colab Web UI (buka `https://colab.research.google.com/`).
- OHIF **tetap v2** (diputuskan user — tidak ada kendala yang memaksa migrasi; evaluasi v3 tetap di `docs/` untuk pasca-v1).

### Changed — port host compose prod bisa di-override env
- `docker-compose.prod.yml`: `ORP_RIS_PORT/ORP_OHIF_PORT/ORP_ORTHANC_PORT/ORP_DICOM_PORT/ORP_AI_PORT` (default = standar). Alasan: trial mini_pacs (8000→portainer, 3000→waha, 4242→mcu-dicom-gateway). Terverifikasi `config` dengan port trial 8002/3001/8042/4246/8001.

## [Unreleased] — 2026-09-18 (M9 stabilisasi: fork session AqQkIio1 → DCM4CHE)

### Added — root `.gitignore` + backup pre-M9
- **`.gitignore` root baru**: `.env*`/secrets, `data/`/`logs/` (PHI + binary Orthanc), `ai-worker/weights/*.pt`, `__pycache__`/`.venv`/`public/build`, `backups/*.bak|*.sql*`. `ris/.gitignore` sudah menutup `.env` — root kini menutup sisanya.
- **Backup** `backups/pre-m9-2026090918-1122/` (Changelog, Rencana, compose dev+prod, env examples, platform tmpl) sebelum verifikasi massal.
- **Fork session** `https://opncd.ai/share/AqQkIio1` (M1–M8) dilanjutkan di folder ini (`/mnt/DiskD/Projects/DCM4CHE`, origin `open-radiology-platform.git@main`).

### Fixed — VR SH warnings di adapter tests
- `test_mwl_dataset.py` + `test_mpps_handler.py`: accession `ACC-20260917-XXXX` (17 char) → `ACC-260917-XXXX` (15 char, format produksi `ACC-YYMMDD-XXXX`); `RequestedProcedureID` `ORD-20260917-0001` → `ORD-260917-0001`. Hasil: **12 passed, 0 warnings** (sebelumnya 3 warnings).
- Hapus `platform/orthanc.json.bak` (pola `*.bak` dilarang FILESYSTEM rules; isi sudah ter-cover `orthanc.json.tmpl` + backup pre-M9).

### Verified — suite penuh pasca-fork (tanpa mock, 2026-09-18)
- Laravel (ddev `ris`, pgsql): **OK 127 tests / 526 assertions** (+6 vs M8: reporting lifecycle, profile, security, transmission queue).
- Adapter (pydev): **12 passed, 0 warnings**.
- AI worker (pydev): **7 passed**.
- Frontend: `tsc --noEmit` exit 0; `ddev exec npm run build` **7.42s sukses**.
- Compose: `docker compose config -q` **valid** (dev); `docker-compose.prod.yml config -q` **valid** (dengan warning env kosong yang wajar tanpa `--env-file .env.prod`).

### Known Issues (update)
- `Order::nextOrderNumber()` (`ORD-YYYYMMDDHHMMSS-XXXX`, 23 char) melebihi VR SH 16 untuk `RequestedProcedureID` MWL — test memakai bentuk pendek 15 char; perlu keputusan: potong saat kirim MWL vs ganti format order_no (non-blocking, DICOM tetap terkirim dengan warning).
- `data/` (Orthanc binaries, `tb-datasets/montgomery.zip`), `backups/`, `*.log`, `__pycache__`, `adapter/.env` **tidak masuk** commit `17162c6` (terverifikasi `git ls-files` bersih) — isu binary-churn dari history lama tertutup oleh rewrite.
- TB: `tb_densenet121.pt` belum ada (`available=false`); inference pipeline graceful, OHIF v2 tetap (evaluasi v3 di `docs/ohif-v3-evaluation.md` untuk pasca-v1).
- **Colab CLI**: `colab new` / `colab run` gagal `Precondition Failed (412)` saat membuat session GPU berurutan terlalu cepat — batasan kuota free tier Google Colab. Shim `KernelClient` sudah dipasang (`sitecustomize.py`) tapi `colab run` butuh jeda antar session (~30 detik) atau tutup session lama sebelum buat baru. Catat di SOP: `colab stop -s <nama>` sebelum session baru.

### Fixed — `ris/Dockerfile` konteks ganda + stage node tanpa PHP (2026-09-18)

### Fixed — `ris/Dockerfile` konteks ganda + stage node tanpa PHP (2026-09-18)
- **Konteks**: `COPY composer.json` (konteks `ris/`) vs `COPY platform/…` (konteks root) — build lama gagal checksum. Kini konteks = **root** (`.dockerignore` baru: hanya `ris/` + `platform/`, sisanya + `vendor`/`node_modules`/`.env` dikecualikan).
- **Stage node murni gagal**: `npm run build` memanggil `php artisan wayfinder:generate` (`php: not found`) — builder digabung (`php-base` + apk `nodejs` 24) dengan `composer install --no-scripts` lalu `package:discover` setelah COPY penuh.
- **Basi**: `tailwind.config.js`/`postcss.config.js` tidak ada di repo (Tailwind v4) — baris COPY dihapus; `storage/logs`+`bootstrap/cache` dibuat di builder.
- **Bukti**: `docker build -t orp-ris:latest -f ris/Dockerfile .` **sukses 70s**; smoke: Laravel 13.32.0, `public/build/manifest.json` ada, `.env` tidak terbakar, `api/health` terdaftar; `docker-compose.prod.yml` (+blok `build:`) `config -q` valid.

### Security — rotasi `ORP_RIS_API_KEY` pasca-push (2026-09-18)
- Push pertama **ditolak GitHub**: `data/tb-datasets/montgomery.zip` (310MB) + `adapter/.env` (bawa API key asli) ikut di 2 commit lokal. Perbaikan **tanpa force-push**: `git reset origin/main` (history lokal saja, remote belum punya) → `.gitignore` diperketat → 1 commit bersih `17162c6` (344 file) → push **sukses** `2534fb5c..17162c65`.
- Objek lama berisi secret di-purge lokal (`reflog expire + gc`); key tidak pernah sampai ke remote.
- Key dirotasi (`openssl rand -hex 32`) di `ris/.env` + `adapter/.env` (hanya 2 file yang memegangnya).
- Verifikasi E2E dengan key baru: C-ECHO 0, MWL status 0, `GET /api/dicom/worklists` key-baru → **200**, key-salah → **401**.

## [Unreleased] — 2026-09-17 (Pengerasan produksi: Orthanc auth + kredensial klien)

### Added — Orthanc HTTP autentikasi + kredensial PACS
- **Orthanc ber-`AuthenticationEnabled: true`** — template `platform/orthanc.json.tmpl` + entrypoint `platform/orthanc-entrypoint.sh` yang menyulih placeholder `__ORP_ORTHANC_USERNAME__`/`__ORP_ORTHANC_PASSWORD__` dari env saat container start. Kredensial hanya boleh berisi `[A-Za-z0-9._~-]` (validasi aman di entrypoint).
- **Proxy OHIF same-origin menyuntikkan Basic auth** — `platform/ohif-nginx.conf.tmpl` + wrapper entrypoint `platform/ohif-entrypoint.sh` (dijalankan sebelum `/usr/src/entrypoint.sh` bawaan) menghitung `Authorization: Basic <base64>` dari env dan merender config nginx. Browser **tidak pernah** menerima kredensial Orthanc.
- **Compose env** `ORP_ORTHANC_USERNAME`/`ORP_ORTHANC_PASSWORD` (default `orp`/`orp-orthanc-dev`) dipakai Orthanc + OHIF proxy + RIS seeder (config/ris.php → `orthanc.username/password`).
- **`pacs_sources` migration** `2026_09_17_163500_add_credentials_to_pacs_sources_table`: kolom `username` (string, nullable) + `password` (text, cast `encrypted`, hidden).
- **`PacsClient`** sekarang mengirim Basic auth otomatis bila `PacsSource::hasCredentials()` (username+password diisi).
- **UI PACS** (`pages/pacs/index.tsx`): field `username` + `password` (write-only, kosong = jangan ubah), kolom "Kredensial" dengan badge nama user atau "tanpa auth". Dialog generik mendukung `type: 'password'`.
- **Seeder `DemoRadiologySeeder`** membaca base URL & kredensial dari `config('ris.orthanc.*')` → dev → `host.docker.internal:8042`, produksi → `http://orthanc:8042`.

### Changed
- **Orthanc healthcheck** kini pakai kredensial (`wget --user=... --password=... /system`).
- **OHIF entrypoint** diganti wrapper `entrypoint: ["/bin/sh","-c","/opt/orp/ohif-entrypoint.sh && exec /usr/src/entrypoint.sh "$@"","sh"]` + `command: ["nginx","-g","daemon off;"]` karena image ini tidak memakai entrypoint nginx resmi.

### Verified — smoke test nyata
- `curl http://localhost:8042/system` anon → **401**; `-u orp:orp-orthanc-dev` → **200**.
- `curl http://localhost:3000/pacs/system` via proxy → **200** (tanpa kredensial browser).
- QIDO/STOW/WADO-RS via proxy: **200** (STOW multipart/related 18 MB, WADO rendered 1 MB JPEG, QIDO 1 study).
- **PacsClient live vs Orthanc ber-auth**: `ping=true`, `qido_studies=1` (accession `DX0000005` yang baru di-STOW) via `host.docker.internal:8042` dari ddev.
- **Test suite**: 121 test / 495 assertions OK (2 test baru: kredensial Basic auth + tanpa kredensial).

## [Unreleased] — 2026-09-17 (REST API SPA + UI ORP lengkap)

### Added — REST API untuk SPA (semua resource, RBAC + audit)
- **Trait `app/Http/Controllers/Api/Concerns/AuthorizesPermissions.php`** — `authorizePermission($request, $permission)` → `403` dengan pesan "Butuh izin: …" (tanpa `Gate::before`, jadi murni `$user->can()`).
- **Controller baru** (`app/Http/Controllers/Api/`): `PatientController` (index/store/show/update/destroy + `POST /patients/{patient}/merge` — memindahkan order & study ke target lalu soft-delete sumber, menolak hapus bila masih ada order), `DoctorController`, `ModalityController` (tolak hapus bila ada order), `ProcedureController` (read-only, izin `orders.view`), `AppointmentController` (+`transition` dengan validasi `canTransition()` dan izin per target: `scheduling.confirm` / `scheduling.check-in` / `scheduling.reschedule` / `scheduling.edit`), `StudyController` (filter `search/modality/from/to/order_id/unmatched`), `AuditLogController`, `UserController` (Admin/SuperAdmin: list, `GET /roles`, `PUT /users/{user}/roles` — menolak mengubah role akun sendiri), `DashboardController` (hanya section yang boleh dilihat; auditor → `{"data":[]}`).
- **Controller diperluas**: `OrderController` (+index/store/show/update/cancel + `GET /orders/awaiting-report`), `PacsController` (+store/update/destroy/ping), `TransmissionController` (+show/retry: hanya FAILED/CANCELLED, reset `attempts`, audit `transmission.retried` + `previous_status`).
- **Model**: relasi `Modality::orders()/appointments()`, `PacsSource::transmissions()`.
- **Test**: `tests/Feature/MasterDataApiTest.php` + `tests/Feature/WorkflowApiTest.php` → **23 test / 133 assertion**; suite penuh **119 test / 487 assertion OK**.
- **`config/ris.php`** + `ORP_OHIF_URL` (`.env`, `.env.example`) — URL viewer OHIF untuk UI.
- **`HandleInertiaRequests`**: share `auth.user` dipersempit ke `id/name/email` + `roles` + `permissions` (dipakai gating UI), plus `ohif_url`.

### Added — UI ORP (Inertia + React, gating per permission)
- **Fondasi**: `types/orp.ts` (tipe semua resource + `Paginated<T>`), `lib/orp-format.ts` (label/warna status, `formatDate/formatDateTime`, `ohifStudyUrl()`, `toIsoFromLocal()`), `hooks/use-api-list.ts` (pagination + debounce 300 ms + guard race), `hooks/use-permissions.ts` (`can/canAny`, Admin/SuperAdmin bypass).
- **Komponen bersama**: `components/orp/data-table.tsx` (`DataTable` + `TablePagination`), `page-header.tsx` (`PageHeader` + `NoAccess`), `resource-dialog.tsx` (form generik berbasis `FieldDef`), `confirm-button.tsx`, `components/nav-orp.tsx` (menu bergrup difilter izin, dipasang di `app-sidebar.tsx`).
- **Halaman**: `patients` (CRUD + dialog merge), `doctors`, `modalities`, `pacs` (CRUD + ping per baris), `transmissions` (filter status + retry), `audit` (filter action/tanggal/search), `users` (penetapan role per checkbox, akun sendiri tidak bisa diubah), `studies` (filter + tombol Viewer), `appointments` (buat + transisi + reschedule), `orders` (filter + buat + cancel), `orders/show` (pasien, pemeriksaan, study + viewer, report, jadwal), `worklist` (menunggu report, order terbaru, study belum cocok), `dashboard` (API-driven dari `/api/dashboard`), **`viewer` (embed OHIF dalam RIS)**.
- **Viewer tertanam** (`pages/viewer/index.tsx` + route Inertia `/viewer`): memilih study lewat query `?study=<id>` (karena `Route::inertia` tidak mengoper param route), memuat detail study dari `GET /api/studies/{id}` untuk header (pasien/MRN/accession/modalitas/tanggal), lalu merender `<iframe>` ke `ohifStudyUrl(ohif_url, StudyInstanceUID)` (prop bersama `ohif_url`); tersedia "Muat Ulang", "Tab Baru", dan "Ke Order". Tombol di `studies` dan `orders/show`: **Viewer** (di dalam RIS) + ikon buka tab baru.
- **`routes/web.php`**: +12 route Inertia (`/worklist`, `/orders`, `/orders/{order}`, `/appointments`, `/patients`, `/studies`, `/viewer`, `/modalities`, `/pacs`, `/transmissions`, `/audit`, `/users`).

### Changed
- `PacsController::index` kini menyertakan soft-deleted (`withTrashed()`) dan **tidak** melakukan ping ke Orthanc kecuali diminta eksplisit (`?ping=1`); tanpa itu `reachable` = `null` (list tidak lagi lambat karena timeout per sumber).
- Halaman ORP baru memakai URL string literal ke `apiGet/apiPost/...` (bukan Wayfinder) agar tidak bergantung urutan generate aksi; `reports/index.tsx` tetap memakai Wayfinder.

### Fixed (bug ditemukan saat implementasi UI)
- **`Route::inertia` tidak menggabungkan route parameter ke props** — `orders/show.tsx` mengambil `id` dari `window.location.pathname` (bukan prop yang tidak pernah dikirim).
- **Relasi Eloquent diserialisasi snake_case** (`ai_results`, `referring_doctor`) — verifikasi lewat `HasAttributes::relationsToArray()`; tipe TS disesuaikan.
- `formatDateTime`/`Link`/`can` yang tidak terpakai dibersihkan; `vp check` + `tsc --noEmit` bersih (86 file).

### Verified — smoke test HTTP nyata (bukan mock)
- Login sesi nyata + cookie jar, lalu **14 endpoint API → 200** (`/api/dashboard`, `/api/orders/awaiting-report`, `/api/orders`, `/api/studies?unmatched=1`, `/api/patients`, `/api/users`, `/api/roles`, `/api/audit-logs`, `/api/modalities`, `/api/pacs`, `/api/transmissions`, `/api/appointments`, `/api/procedures`, `/api/doctors`).
- **11 halaman Inertia → 200** dengan komponen yang benar (`worklist/index`, `dashboard`, `patients/index`, …); shared props terbaca: `roles: ["super-admin"]`, **34 permission**, `ohif_url: http://localhost:3000`.
- **Viewer tertanam**: `/viewer?study=1` → 200 (HTML) dan 200 (`X-Inertia`) dengan komponen `viewer/index`; OHIF hidup — `GET http://localhost:3000/` **200**, `/viewer?StudyInstanceUIDs=…` **200**, dan `GET /pacs/dicom-web/studies` (QIDO-RS via proxy same-origin) **200**.
- Catatan: Orthanc saat ini kosong (`/pacs/dicom-web/studies` → `[]`) karena container dibuat ulang saat perbaikan config; data uji `ACC-VERIFY-0001` tidak lagi ada (bukan regresi — tinggal STOW ulang bila perlu).
- `ddev exec npm run build` sukses (chunk `worklist`, `dashboard`, … terbentuk); `ddev exec php vendor/bin/phpunit` → **OK (119 tests, 487 assertions)**.

## [Unreleased] — 2026-09-17 (Penutupan known issues + verifikasi vendor stack nyata)

### Added — persist state MPPS ke Laravel
- **Migrasi `2026_09_17_150001_create_mpps_records_table`**: `sop_instance_uid` (unique), `accession_number` (16), `order_id` (nullable FK), `status`, `modality`, `performed_started_at`, `performed_ended_at`, `last_seen_at`, softDeletes.
- **`App\Models\MppsRecord`** (`resolvedAccession()`) + relasi **`Order::mppsRecords()`**.
- **`DicomController::mpps()`**: accession di-resolve dari tabel `mpps_records` via `SOPInstanceUID` bila payload MPPS tidak membawa `AccessionNumber`; `recordMpps()` melakukan upsert (baris dibuat hanya jika ada order/accession — tidak ada baris sampah); audit `dicom.mpps` (+`accession_source`, `sop_instance_uid`) / `dicom.mpps.unresolved` / `dicom.mpps.unknown`; respons menyertakan `sop_instance_uid`.
- Komentar `_pps_state` di adapter diperbarui: kini hanya cache di memori, **Laravel adalah sumber kebenaran** + test adapter menambah assert `SOPInstanceUID` ikut terkirim.
- `DicomApiTest` **17 test** (+5 baru: MPPS start/update, resolusi accession dari SOP UID, audit unresolved, tanpa order).

### Added — FHIR OAuth/token (produksi-ready)
- **`laravel/sanctum ^4.3` (v4.3.3)** + `config/sanctum.php` + migrasi `2026_09_17_151956_create_personal_access_tokens_table`; `HasApiTokens` di `App\Models\User`.
- **`app/Console/Commands/IssueApiToken.php`** → `php artisan orp:issue-token {email} --name= --ability=* --expires=`.
- Route `/api/fhir/*` kini `auth:sanctum` (Bearer token), bukan sesi web. `FhirStubTest` **8 test** (+3: 401 tanpa token, Bearer token nyata via `createToken()`, command artisan).

### Added — integrasi SPA: sesi + CSRF + helper fetch
- **`resources/js/lib/api.ts`**: `ApiError`, `apiGet/apiPost/apiPut/apiDelete` — membaca cookie `XSRF-TOKEN` → header `X-XSRF-TOKEN`, `Accept: application/json`, `credentials: 'same-origin'`, 401 → redirect `/login`.
- **`resources/js/pages/reports/index.tsx`** ditulis ulang memakai helper Wayfinder (`ReportController`/`OrderController` dari `@/actions/...`), debounce pencarian 300 ms, state loading/saving/pendingId, tombol transisi DRAFT→DICTATED→VERIFIED→FINAL.
- **`platform/ohif-nginx.conf`**: proxy `/pacs/` → `orthanc:8042` di dalam container OHIF.

### Fixed — bug integrasi nyata yang ditemukan saat verifikasi
- **SPA selalu 401**: grup middleware `api` bersifat stateless (tanpa `StartSession`/cookie), sehingga `auth` tak pernah bekerja untuk request browser → endpoint reports/orders/pacs/transmissions/ai-results sekarang memakai `$spa = ['web', 'auth']` (dengan komentar skema auth di `routes/api.php`).
- **Orthanc crash-loop** (`Bad file format: section "KeepAlive" defined in 2 configuration files`): image `jodogne/orthanc-plugins` juga memuat `/etc/orthanc/advanced.json`, jadi menimpa `orthanc.json` saja menabrak section → Orthanc kini dijalankan dengan direktori config sendiri (`/etc/orthanc-orp/` + `command: ["/etc/orthanc-orp/"]`).
- **Plugin tidak termuat**: path `/usr/share/orthanc/plugins/` tidak ada di image → `Plugins: ["/usr/local/share/orthanc/plugins/"]` (stone-webviewer, OHIF, Gdcm, … sekarang terdaftar).
- **Semua request dari host dijawab 401** meski auth nonaktif: Orthanc menolak request non-loopback bila `RemoteAccessAllowed` belum `true` (log: "remote access is not allowed").
- **Healthcheck salah path**: `/orthanc/system` → 404 di Orthanc mainline → diganti `/system` (container kini `healthy`).
- **Section `Cors` di `orthanc.json` tidak berefek apa pun** — Orthanc memang sengaja tidak mendukung CORS (Orthanc Book, FAQ same-origin); section dihapus dan diganti proxy same-origin `/pacs/` di nginx OHIF (juga lebih aman untuk PHI: bukan `Access-Control-Allow-Origin: *`).
- **Config OHIF salah schema**: image `ohif/viewer` adalah lini **v2** yang mengharapkan `servers.dicomWeb` sebagai *array*; bentuk lama (`servers.orthanc`) membuat viewer tampil tanpa data source (study list kosong). Root DICOMweb juga diarahkan ke same-origin `http://localhost:3000/pacs/dicom-web`.
- **Accession ≤16 char (VR SH)**: `IdentifierService::nextAccession()` → `ACC-YYMMDD-XXXX` (**15 char**, 10.000/hari) + konstanta `ACCESSION_MAX_LENGTH = 16`; regex test disesuaikan; dibuktikan dengan pydicom dua-proses (15 char tanpa warning, 17 char warning).
- **Build frontend harus lewat ddev**: `npm run build` di host gagal (`wayfinder:generate` butuh `php` → `RolldownError: php: command not found`). Perintah benar: `ddev exec npm run build` (6.16s).

### Verified — sesi browser, CSRF, dan vendor stack (bukan mock)
- **Bukti curl** (cookie jar nyata): login 200; `GET /api/reports` **200**; `GET /api/orders/awaiting-report` **200**; `POST /api/reports` tanpa `X-XSRF-TOKEN` → **419**, dengan header → **201**; `GET /api/fhir/metadata` tanpa token → **401**; `GET /api/dicom/worklists` tanpa key → **401**.
  - Catatan implementasi: cookie `XSRF-TOKEN` Laravel terenkripsi — kirim hanya header `X-XSRF-TOKEN` (jangan `_token`), dan baca ulang cookie setelah login (token di-regenerate saat session regeneration).
- **Vendor stack (postgres + orthanc + ohif) benar-benar dijalankan** dan diverifikasi end-to-end lewat proxy same-origin:
  - `POST /pacs/dicom-web/studies` (STOW-RS, multipart/related) → **200** + `RetrieveURL`;
  - `GET /pacs/dicom-web/studies?AccessionNumber=ACC-VERIFY-0001` (QIDO-RS) → study ditemukan, AccessionNumber terbaca sebagai **VR SH**;
  - `GET /pacs/dicom-web/studies/<uid>` (WADO-RS) → **200, 9.400 byte** `multipart/related; type="application/dicom"`;
  - OHIF `/` 200, `/app-config.js` 200, Orthanc `/system` 200 & status **healthy**.
  - Instance sintetis uji + file temp dihapus setelah verifikasi (`/studies` → `[]`).
- **Suite akhir**: Laravel **96 test / 354 assertions hijau**; adapter **12 passed**; ai-worker **7 passed**; `docker compose config -q` valid.
- **Catatan**: verifikasi UI di browser nyata tidak dapat dijalankan di lingkungan ini (Chrome gagal launch: crashpad `recvmsg: Koneksi direset oleh peer`, juga dengan `--no-sandbox`) — sebagai gantinya perilaku endpoint SPA dikunci test baru `test_reports_api_requires_web_session` (401 JSON untuk anonim) + bukti curl di atas.

## [Unreleased] — 2026-09-17 (M4–M6: Reporting, Transmisi, PACS integrations, AI pipeline)

### Added — M4: Reporting & antrean transmisi ke PACS eksternal
- **`app/Enums/ReportStatus.php`**: DRAFT→DICTATED→VERIFIED→FINAL (+CANCELLED), `allowedTransitions()`; report auto-`report_number` `RPT-…` via IdentifierService.
- **`app/Models/Report.php`** + migrasi `2026_09_17_130001_create_reports_table` (relasi Order/Study, `transitionTo()` + audit di model `booted()`/created & transition).
- **`ReportController`** (`/api/reports`: CRUD + `POST {report}/transition`; edit diblokir setelah VERIFIED/FINAL) + `OrderController::awaitingReport()` (`/api/orders/awaiting-report`).
- **Antrean transmisi STOW-RS**: `TransmissionStatus` enum, migrasi `130002_create_transmissions_table` (+softDeletes), `Transmission` model (`registerAttempt` backoff `min(300, 5·2^attempts)`, markSent/markFailed), `App\Jobs\ProcessTransmission` (multipart STOW-RS ke PacsSource, retry→release, habis→FAILED), `Study::queueTransmission()` + hook otomatis di `DicomController::storeStudy`, `TransmissionController` (`/api/transmissions`).
- **UI skeleton reporting**: `resources/js/pages/reports/index.tsx` + komponen `textarea.tsx`, route Inertia `/reports`; divalidasi `npx tsc --noEmit` bersih (npm build host rusak: binding `vite-plus`).

### Added — M5: Integrasi PACS (DICOMweb + FHIR stub) + vendor stack
- **`PacsSource` lengkap** (seeder demo: Orthanc Dev, AE ORTHANC:4242, base `http://orthanc:8042`, QIDO/WADO/STOW `/dicom-web`) + `app/Services/PacsClient.php`: ping, qidoStudies/qidoSeries/qidoInstances, wadoRendered, stowFile.
- **`PacsController`** (`/api/pacs` index+studies+series+instances+rendered proxy) — 7 test hijau (url building, fallback, HTTP errors).
- **FHIR R4 stub** (`FhirController`): `/api/fhir/metadata` CapabilityStatement, Patient, ServiceRequest (Order), ImagingStudy (Study), DiagnosticReport (Report) — search+read; Content-Type `application/fhir+json`.
  - *Bug seri 500 ditemukan & diperbaiki*: return type `JsonResponse` vs `Illuminate\Http\Response` (TypeError) — semua method yang melewati `fhir()` kini bertipe `Response`; `metadata()` dialihkan lewat `fhir()` agar header FHIR benar. 5 test hijau.
- **Vendor stack** (`docker-compose.yml` root + `platform/orthanc.json` + `platform/ohif-config.js`): postgres:16, orthanc (DICOMweb+CORS+user orp), OHIF viewer (3000), ai-worker (FastAPI :8000, CPU), adapter (:4243–4245). Data orthanc di `data/orthanc` (folder already tracked).
  - *Known issue lama S0: "folder platform/ belum ada" kini TELAH dibuat.*

### Added — M6: AI inference pipeline (C-STORE → ai-worker → hasil tersimpan)
- **Migrasi `2026_09_17_140001_create_ai_results_table`** (+`deleted_at`; ditambahkan manual di dev pgsql karena migrasi awal tereksekusi tanpa kolom) + **`AiResult` model** (status PENDING→PROCESSING→COMPLETED|FAILED, `pathologies`/`findings`/`raw_report` JSON, `inference_ms`).
- **`app/Services/AiClient.php`**: ping (`/health`), infer (`POST /infer` multipart DICOM, timeout 120s), inferTb (`POST /infer/tb`), `tbAvailable()`; base URL dari `config/services.php` `ai_worker.url` (env `ORP_AI_URL`), auto-dispatch config-gated `ORP_AI_AUTO_INFER` (off di phpunit.xml agar test tidak menembak worker nyata).
- **`App\Jobs\RunAiInference`**: study tersimpan → ambil `file_path` → infer → COMPLETED+findings; bila worker `/health` melaporkan TB tersedia → skrining TB ditambahkan ke `raw_report['tb']`; non-fatal (FAILED tercatat bila worker mati/error; re-dispatch via API).
- **`AiResultsController`** (`/api/ai-results` index/show + `POST run/{study}` re-dispatch) + hook otomatis di `DicomController::storeStudy` (return `ai_result_id`).
- **ai-worker**: endpoint baru `POST /infer/tb` (graceful tanpa bobot: `available=false` + note) + 2 test endpoint; total **7 pytest hijau**.
- **Test**: `tests/Feature/AiInferenceTest.php` 7 test (Http::fake): job COMPLETED, FAILED worker error, FAILED tanpa file, TB available path, API list/show, re-dispatch, auto-create saat C-STORE.

### Changed
- `routes/api.php`: +`/api/reports`, `/api/orders/awaiting-report`, `/api/transmissions`, `/api/pacs/*`, `/api/fhir/*`, `/api/ai-results*` (semua di belakang auth; dicom tetap api-key).
- `config/services.php` + `phpunit.xml` (env `ORP_AI_AUTO_INFER=false`).
- `DemoRadiologySeeder` → seed PacsSource Orthanc Dev.

### Fixed
- **FHIR 500 (TypeError type-hint)** — `fhir()` & method turunannya: `JsonResponse` → `Response`.
- **FHIR Content-Type `application/json`** — `metadata()` memakai `response()->json()`; sekarang lewat `fhir()` → `application/fhir+json; charset=UTF-8`.
- **`ai_results.deleted_at` "no such column"** — `softDeletes()` ditambahkan ke migrasi; dev pgsql di-ALTER manual.
- **Test helpers**: file DICOM palsu ditulis ke temp (AiClient menolak path tidak terbaca); `makeStudy(filePath: null)` vs fallback temp dibedakan via sentinel `'__TEMP__'`.
- **`npm run build` rusak di Linux** — akar masalah: `optionalDependencies` hanya memuat binding **win32** (`@voidzero-dev/vite-plus-win32-x64-msvc`), binding Linux tidak pernah terpasang → `Cannot find module './vite-plus.linux-x64-gnu.node'`. Ditambahkan `@voidzero-dev/vite-plus-linux-x64-gnu@^0.3.0` ke `optionalDependencies` + `npm install`; build kembali normal (aset `reports-*.js` ter-generate). Known issue "npm build rusak" **tertutup**.

### Verified — E2E AI nyata (bukan mock), 2026-09-17
- ai-worker dijalankan nyata di container pydev (`uvicorn orp_ai.server:app`, port 8000) + `docker network connect ddev-ris_default pydev`; `ORP_AI_URL=http://pydev:8000` di `.env` ris (ditambahkan juga ke `.env.example` beserta `ORP_AI_AUTO_INFER`).
- `AiClient::ping()` → **ok**, model `densenet121-res224-all` termuat, `tb.available=false` (bobot TB belum ada) — dari `ddev-ris-web` ke `http://pydev:8000/health`.
- `AiClient::infer()` DICOM DX Chest PA nyata → **ok, 833 ms, 5 findings**.
- **Job penuh** (`RunAiInference::dispatchSync`) pada Study + file DICOM nyata → `status=COMPLETED`, `inference_ms=985`, findings top-3 `Lung Opacity:0.692 / Effusion:0.6874 / Cardiomegaly:0.6869`, `tb.available=false`, tanpa error; dijalankan dalam transaksi + rollback sehingga DB dev tidak berubah.
- **Kontrak nyata terkonfirmasi**: `findings = [{name, probability}]` (bukan `score`), top-level `source/model/device/threshold/findings/tb/focused`; **`tb` sudah disertakan `predict()`**, jadi job hanya memakai fallback `/infer/tb` untuk worker versi lama. Test `AiInferenceTest` disesuaikan agar memakai bentuk nyata ini (mock lama ber-`score` dihapus) + `Http::assertSentCount(1)` memastikan tidak ada panggilan ganda.
- **Rantai penuh lewat produksi-path** (`QUEUE_CONNECTION=database`): `POST /api/dicom/studies` (C-STORE, `X-API-Key`) → Study + `AiResult(PENDING)` dibuat otomatis → `php artisan queue:work --stop-when-empty` menjalankan `RunAiInference` (**905 ms DONE**) → `AiResult` **COMPLETED**, `inference_ms=846`, findings top-3 `Lung Opacity:0.692 / Effusion:0.6874 / Cardiomegaly:0.6869`, `tb.available=false`, `error=NULL`.
  - Dijalankan dengan accession tak-terdaftar (`matched=false`) sehingga **tidak mengubah data order**; setelah verifikasi baris Study/AiResult uji dan file DICOM sementara dihapus → DB dev kembali ke keadaan semula (`ai_results=0`, `studies=2`).

### Verified (setelah M6 selesai)
- Laravel suite ddev: **87 test / 315 assertions hijau** (termasuk 6 Reporting + 4 Transmission + 5 FHIR + 7 PACS + 7 AI).
- Adapter (pydev): **12 passed**. AI worker (pydev): **7 passed**.
- Frontend: `npm run build` **sukses** (6.7s; aset `reports-*.js` ter-generate) — binding Linux terpasang.
- docker-compose: `docker compose config -q` **valid**.

### Known Issues (kondisi saat ini)
- ~~`npm run build` di host gagal~~ → **SELESAI** (binding Linux ditambahkan; build 6.7s sukses).
- ~~FHIR stub memakai auth web (bukan token OAuth)~~ → **SELESAI**: `/api/fhir/*` memakai `auth:sanctum` (Bearer token) + `php artisan orp:issue-token` (lihat blok terbaru di atas).
- TB: bobot `tb_densenet121.pt` belum ada → `available=false` di `/health` & `/infer/tb` (terverifikasi E2E); jalankan notebook Colab + letakkan di `ai-worker/weights/`.
- ai-worker dev berjalan manual di pydev (`uvicorn ... :8000`) — mati saat container stop; komando: `docker exec -d pydev bash -c "cd /projects/DCM4CHE/ai-worker && nohup uv run uvicorn orp_ai.server:app --host 0.0.0.0 --port 8000 > /tmp/ai-worker.log 2>&1 &"`. Produksi: service `ai-worker` di `docker-compose.yml`.
- DicomController worklist masih memakai `ilike` (SQLite test tidak terpengaruh; di pgsql benar).
- ~~Accession 18-karakter > VR SH 16~~ → **SELESAI**: accession kini `ACC-YYMMDD-XXXX` (15 char, `IdentifierService::ACCESSION_MAX_LENGTH = 16`), aman untuk VR SH (dibuktikan dengan pydicom).
- ~~State MPPS adapter in-memory~~ → **SELESAI**: tabel `mpps_records` + `MppsRecord`/`Order::mppsRecords()`; cache adapter hanya optimisasi (Laravel = sumber kebenaran).
- OHIF: image `ohif/viewer` adalah lini **v2** (di atas nginx) — untuk produksi pertimbangkan migrasi ke `ohif/app` (v3, schema `dataSources`) dan aktifkan auth Orthanc + token/proxy.

## [Unreleased] — 2026-09-17

### Added — M3: DICOM API Laravel ↔ Adapter E2E (MWL + C-ECHO + MPPS + C-STORE)
- **REST API DICOM di Laravel** (`routes/api.php`, middleware `dicom.api-key` terdaftar di `bootstrap/app.php`, alias ke `DicomApiKey`):
  - `GET /api/dicom/worklists` — worklist MWL (order aktif REQUESTED/SCHEDULED/ARRIVED), filter 7 key C-FIND (AccessionNumber, PatientID, PatientsName, Modality, ScheduledStationAETitle, ScheduledProcedureStepStartDate, dsb).
  - `POST /api/dicom/mpps` — MPPS: N-CREATE→IN_PROGRESS, N-SET COMPLETED→ACQUIRED, DISCONTINUED/N-ACTION→CANCELLED; AccessionNumber dibaca dari top-level atau `ScheduledStepAttributesSequence`.
  - `POST /api/dicom/studies` — C-STORE forward: match AccessionNumber → `Order::walkTo(Completed)` + simpan `Study` (matched, order_id, SOP UID).
  - `POST /api/dicom/adapters/online` — heartbeat adapter → `AuditLog` `dicom.adapter.online`.
- **Keamanan**: `config/dicom.php` (api_key dari env `ORP_RIS_API_KEY`); `DicomApiKey` membandingkan header `X-API-Key` secara timing-safe; 401 bila tidak cocok.
- **`Study` model + migrasi `2026_09_17_120001_create_studies_table`** (accession_number unique, matched bool, order FK, UIDs, SOP class).
- **`Order::walkTo()` + helper `pathBetween()` (BFS)**: transisi multi-langkah legal sekali panggil (mis. REQUESTED→COMPLETED melewati SCHEDULED→ARRIVED→IN_PROGRESS→ACQUIRED).
- **Test `tests/Feature/DicomApiTest.php`** (12 test, 52 assertions): auth key, worklist filter/hasil, MPPS lifecycle, studi match, heartbeat.
- **Adapter tests**: `tests/test_ris_client.py` (5) + `tests/test_mwl_dataset.py` (2) + `tests/test_mpps_handler.py` (5) → **12 passed** di dalam pydev (venv Linux Python 3.12, pynetdicom 3.0.4); `pyproject.toml` + `[dependency-groups] dev` (pytest, pytest-mock); `.env` adapter ditulis + masuk `.gitignore`.
- **E2E terverifikasi penuh** (sim `sim_aet.py`/`mpps_scu.py` ↔ adapter ↔ Laravel via `docker network connect ddev-ris_default pydev`, adapter akses `http://web:80/api`):
  - C-ECHO status 0; **MWL C-FIND memfilter AccessionNumber dengan benar** (query terkirim, hasil sesuai order aktif).
  - **MPPS lifecycle**: N-CREATE → order IN_PROGRESS, N-SET COMPLETED → order ACQUIRED.
  - **C-STORE** file DICOM → order jadi COMPLETED, `Study` matched=yes, AuditLog `dicom.study.matched` tercatat.
- **Hasil suite**: Laravel **58 test / 210 assertions hijau** (ddev), adapter **12 passed**.

### Fixed
- **MWL filter kosong (`{}`) — bug pydicom 3.x API**: `Dataset.get(keyword)` di pydicom ≥3 mengembalikan **nilai string langsung**, bukan `DataElement` seperti pydicom 2.x → `elem.value` melempar AttributeError yang di-swallow try/except lama → semua filter MWL terkirim kosong (worklist mengembalikan order aktif pertama, bukan yang dicari). `_string()` ditulis ulang mendukung kedua bentuk (str/bytes langsung atau DataElement).
- **MPPS handler N-CREATE/N-SET error "has no 'Data Set' parameter"** — pynetdicom 3.x: materi N-CREATE/N-SET ada di `event.attribute_list`, bukan `event.dataset` (dataset hanya untuk C-STORE).
- **MPPS callback return type**: pynetdicom 3.x `EVT_N_CREATE`/`EVT_N_SET` mengharuskan tuple `(status, dataset)` dengan **status bertipe int atau pydicom Dataset** — dict `{"Status": ...}` ditolak ("Invalid status returned by callback"). Kini mengembalikan `(0x0000, None)` / `(0xC300, None)` / `(0xA700, None)`.
- **`ScheduledStepAttributesSequence` terkirim sebagai string repr** — `_json_safe()` hanya mengenali `list/tuple`, padahal pydicom menyerahkan sequence sebagai `pydicom.sequence.Sequence` (bukan subclass list) → ditambahkan penanganan `Sequence` + konversi rekursif `Dataset→dict` agar `extractAccession` Laravel bisa membaca AccessionNumber di dalam sequence.
- **N-SET/N-ACTION kehilangan AccessionNumber** — payload DICOM N-SET tidak wajib membawa accession; adapter kini melacak state MPPS (`SOPInstanceUID → accession_number`) saat N-CREATE dan melekatkannya pada N-SET/N-ACTION berikut. SOP instance diambil dari `event.request.RequestedSOPInstanceUID` (N-SET/N-ACTION) vs `AffectedSOPInstanceUID` (N-CREATE).
- Dua instance adapter bisa saling rebut port (bind gagal `Address already in use`) — prosedur restart adapter: bunuh semua proses `orp-adapter` dulu (via python /proc scan, bukan `pkill` yang bisa match cmdline shell sendiri), verifikasi port bebas, baru start; log adapter diarahkan ke file (`/tmp/adapter.log`) karena `docker exec -d` membuang stdout.

### Known Issues
- ~~Accession melebihi VR SH~~ → **SELESAI**. Format `ACC-YYYYMMDD-XXXX` (17 karakter) melanggar VR **SH (maks 16)** → peringatan pydicom saat membangun dataset MWL; modalitas produksi bisa menolak. **Diperbaiki** menjadi `ACC-YYMMDD-XXXX` (**15 karakter**: 3 prefix + 1 + 6 tanggal + 1 + 4 acak; 10.000/hari, 1 karakter headroom). Konstanta `IdentifierService::ACCESSION_MAX_LENGTH = 16` + assert regresi di test. Bukti dua-proses pydicom: nilai 15 char **tanpa warning**, nilai 17 char tetap warning (kontrol negatif). MRN tidak berubah (VR LO ≤64).
- C-STORE dataset minimal demo menghasilkan status **49154 (0xC002 coercion warning)** — instance tetap tersimpan; bukan error.
- **State MPPS in-memory di adapter** (dict `SOPInstanceUID→accession`): hilang bila adapter restart di tengah prosedur — N-SET berikutnya tanpa accession akan ditolak Laravel. Cukup untuk 1 instance; bila perlu persistensi (multi-instance/restart-safe), pindahkan ke tabel Laravel.
- Test suite Laravel memakai sqlite `:memory:` (phpunit.xml); E2E DICOM diuji manual via ddev postgres.

## [Unreleased] — 2026-09-17

### Added — Modul TB (`ai-worker/src/orp_ai/tb.py`) + integrasi VSCode↔Colab
- **`tb.py`**: detektor TB (fine-tuned DenseNet121, bobot dari Colab). Pipeline **identik** dengan notebook training (Grayscale(3)→Resize 224→ToTensor→Normalize ImageNet; TANPA CenterCrop — jangan campur preprocess xrv). Degradasi halus: bobot hilang/corrupt → `available=False`, inferensi 18 patologi tetap jalan.
- **Wiring**: `predict()` kini menambahkan blok `"tb"` (available, probability, logit, val_auc, note); `batch.py` per-gambar; `GET /health` menambah `tb.status`; `Settings.tb_weights` + env `ORP_AI_TB_WEIGHTS`; `clear_cache()` membersihkan cache xrv + TB.
- **`weights/`** folder + README (cara menaruh `tb_densenet121.pt` dari Drive; catatan "bukan alat diagnostik"; bobot TIDAK masuk git).
- **Ekstensi VSCode resmi `google.colab` 0.9.5 terpasang** — notebook Colab (fine-tune TB) bisa dijalankan langsung dari VSCode: Select Kernel → Colab → Auto Connect (login Google interaktif). Notebook publik CVPR-2021 & gifsplanation **diverifikasi tidak berguna untuk TB** (xrv 0.0.26 API usang / 18 label tanpa TB) — tetap pakai notebook kita.
- **Test baru**: `test_tb_weights_missing_degrades_gracefully` → 5 pytest hijau.

### Changed
- VSCode 1.136.1: terhubung jembatan Colab via ekstensi `google.colab` + `ms-toolsai.jupyter`; `.venv` worker hanya berlaku di dalam container pydev (`/root/.local/share/uv/python/...`) — host VSCode tidak bisa memakainya langsung.

## [Unreleased] — 2026-09-17

### Added — Colab bridge untuk fine-tune TB (opsi penyimpanan ringan)
- **`ai-worker/notebooks/tb_finetune_colab.ipynb`** (11 sel): fine-tune DenseNet121 (ImageNet) → 2 kelas (Normal/TB) pada dataset **publik** Montgomery+Shenzhen. Data diunduh ke runtime Colab (tidak menyentuh penyimpanan lokal); HANYA bobot model (±35MB) yang keluar Colab via Google Drive. Seed 42 → reproducible; evaluasi acc/precision/recall/F1/AUC/confusion; simpan `tb_densenet121.pt` ke `MyDrive/orp-ai/`.
- Batasan tertulis di notebook & rencana §6a: data publik saja — data pasien/institusi TIDAK boleh ke Colab (UU PDP). Hasil = alat skrining/triase, bukan diagnosis.

### Changed
- Keputusan penyimpanan (2026-09-17): **`qure_images.tar` (49GB) dihapus** — isinya duplikat dari `pacs/blobs/` (43GB). Disk `/mnt/DiskD` pulih 17GB → 66GB bebas (92% → 68%).

## [Unreleased] — 2026-09-17

### Added — AI worker (CPU-only)
- **`ai-worker/` baru**: inferensi chest X-ray berjalan CPU/RAM saja — keputusan arahan user (tanpa GPU).
  - `pyproject.toml`: Python 3.12 (uv-managed), torch 2.14.0+cpu dari index CPU saja (menghindari unduh CUDA ~2GB), torchxrayvision 1.5.4, fastapi, uvicorn, pydicom, pydantic.
  - `src/orp_ai/config.py` — Settings dari env `ORP_AI_*` (device, model, threshold, top_k, max_upload_mb).
  - `src/orp_ai/model.py` — loader model cached; daftar model bersumber `xrv.models.model_urls` (kebenaran terverifikasi; 7 checkpoint DenseNet121).
  - `src/orp_ai/inference.py` — PNG/JPG/DICOM → 18 patologi paru (DenseNet121 `densenet121-res224-all`); DICOM terdeteksi otomatis via magic bytes `DICM`; resize via `XRayResizer` (API v1.5.4).
  - `src/orp_ai/batch.py` — infer folder → JSON/CSV (rglob untuk .png/.jpg/.jpeg/.dcm).
  - `src/orp_ai/server.py` — FastAPI: `GET /health`, `POST /infer` multipart (limit 25MB), response difilter threshold.
  - `src/orp_ai/cli.py` — subcommands `models | infer | batch | serve`, entry point `orp-ai`.
  - `tests/test_smoke.py` — 4 test hijau: models nonempty, 18 pathologies (+Pneumonia/Effusion/Infiltration/Nodule/Mass), guard "no TB label di torchxrayvision", inferensi sample DX chest PA.
- **Riset AI CPU terverifikasi** (tercatat di Rencana §6a): klaim "TorchXRayVision punya output Tuberculosis/PulmonaryTuberculosis" **SALAH** — dicek langsung 7 checkpoint v1.5.4, tidak ada label TB; TBX11K/NLM hanyalah dataset training. Model HF TB belum layak produksi (downloads 0–80). Opsi M6 TB: fine-tune sendiri (Shenzhen+Montgomery+TBX11K) atau baseline mednet (Idiap).

### Changed
- `Workspace/Docker/Python/compose.yml`: volume baru `/mnt/DiskD/Projects/DCM4CHE:/projects/DCM4CHE` agar `uv`/`pytest` pydev container menjangkau proyek (container hanya mount `/home/adptra01/Workspace` sebelumnya).
- `Rencana Pembangunan — ORP RIS.md`: repo layout + `ai-worker/`, roadmap + M6 (AI pipeline), prasyarat + AI worker verified, §6a riset AI, §6b catatan teknis.
- `ai-worker pyproject.toml`: `[[tool.uv.index]]` pytorch-cpu + `[tool.uv.sources]` agar torch/torchvision selamanya dari index CPU.

### Fixed
- **API torchxrayvision 1.5.4**: `load_image()` tidak lagi menerima `img_size` → pakai `load_image(fname)` + `xrv.datasets.XRayResizer(224)`. Ditemukan saat inferensi pertama (TypeError).
- **`model.py` daftar model**: hapus `densenet121-res224-tb` fiktif (bukan weight valid); sumber kebenaran = `xrv.models.model_urls` keys.

## [Unreleased] — 2026-09-17

### Added
- **ddev project ris/**: `.ddev/config.yaml` → laravel, docroot `public`, PHP 8.3.31, **postgres:16**, timezone Asia/Jakarta, nodejs 24. URL dev: `https://ris.ddev.site:8443`. DB ddev: `db/db` @ `db:5432`, database `db` (host map `127.0.0.1:32783`). `.env` → `DB_CONNECTION=pgsql` (backup lama di `backups/ris-env-sqlite-20260917.bak`). Semua command PHP via `ddev artisan` / `ddev exec php` (PHP tidak di PATH host).
- **phpunit/phpunit ditambahkan** ke `require-dev` (hilang dari scaffold) — `ddev exec php vendor/bin/phpunit` berjalan penuh.
- **M2 Domain — Enums** (`app/Enums/`): `OrderStatus` (REQUESTED→SCHEDULED→ARRIVED→IN_PROGRESS→ACQUIRED→COMPLETED, terminal CANCELLED/NO_SHOW/REJECTED, `allowedTransitions()` + `canTransitionTo()`), `OrderPriority` (STAT/URGENT/ROUTINE), `AppointmentStatus`, `Gender`.
- **M2 Domain — IdentifierService** (`app/Services/IdentifierService.php`): `nextMrn()` → `MRN-YYYYMMDD-XXXX`, `nextAccession()` → `ACC-YYMMDD-XXXX` (15 char, batas VR SH 16 — lihat Known Issues), `nextOrderNumber()` → `ORD-YYYYMMDDHHMMSS-XXXX`; retry saat tabrakan acak (unik DB sebagai pengaman akhir).
- **M2 Domain — Models**: `Patient` (MRN auto di `creating`), `Doctor` (incl. `is_radiologist`), `Procedure` (katalog prosedur), `Modality` (AE title unik), `Order` (accession + order_no + priority/status default + `transitionTo()` validasi alur + `completed_at` saat COMPLETED), `Appointment` (penjadwalan per order), `PacsSource` (onboarding PACS: base_url + QIDO/WADO/STOW endpoint). Semua dengan `SoftDeletes`, enum casts, relasi lengkap.
- **M2 Domain — Migrations (7)**: `patients`, `doctors`, `procedures`, `modalities`, `orders` (unique order_number + accession_number, FK patient/procedure/doctor/modality, index status+scheduled_at), `appointments` (index scheduled_at+status), `pacs_sources`.
- **M2 Domain — Seeders**: `ProcedureSeeder` (15 prosedur radiologi dasar), `ModalitySeeder` (4 modalitas demo dgn AE title ORPCR1/ORPCT1/ORPMR1/ORPUS1), `DoctorSeeder` (3 dokter), `DemoRadiologySeeder` (3 pasien + 3 order REQUESTED/SCHEDULED/COMPLETED + 1 appointment). Dipanggil dari `DatabaseSeeder`.
- **Feature test `tests/Feature/RadiologyDomainTest.php`** (7 test): MRN/accession auto-generate, nilai eksplisit dipertahankan, lifecycle status legal, transisi ilegal ditolak, unik di level DB, rantai appointment.

### Changed
- `DatabaseSeeder` — `WithoutModelEvents` dihapus: trait tsb mematikan hook `creating` model sehingga MRN/accession tidak ter-generate saat seed. Semua seeder data master dipanggil berurutan.
- `RolesAndPermissionsSeeder` — ditambahkan `forgetCachedPermissions()` **setelah** loop `findOrCreate` permission: perbaiki "There is no permission named `patients.view`" karena cache `PermissionRegistrar` terisi parsial saat seeder berjalan (bug 2026-09-16).
- DB dev berubah dari SQLite → **PostgreSQL 16 via ddev** (keputusan §7: produksi target pgsql, ddev menyediakan postgres lokal).

### Fixed
- `laravel artisan test` (`collision` `SebastianBergmann\Environment\Console` not found) — phpunit tidak terinstall di require-dev; selesai dengan `ddev composer require --dev phpunit/phpunit`.
- Seeder M2 tidak memberi MRN default — akar: `WithoutModelEvents` menonaktifkan model events di seeder.

### Known Issues
- Test suite memakai sqlite `:memory:` (phpunit.xml) — konsisten dengan env testing Laravel default; TCK postgres jalan via `ddev artisan migrate:fresh --seed`.

## [Unreleased] — 2026-09-16

### Added
- **Scaffold ris/**: Laravel 13 + Inertia v2 + React 19 + Tailwind v4 + Vite preset. Instalasi via `laravel new ris --react --database=pgsql --pest --no-interaction`. Struktur modern termasuk auth (Fortify), TypeScript, Vite build.
- **.env & .env.example**: Dikonfigurasi `APP_NAME="ORP RIS"`, `APP_LOCALE=id`. DB dev pakai SQLite (`DB_CONNECTION=sqlite`), produksi target PostgreSQL (`orp_ris` database). `pdo_pgsql` diaktifkan di php.ini.
- **Database migrations & seeding**: Migrasi dasar users/cache/jobs/passkeys + two-factor, migrasi audit_logs, seeder roles+permissions (9 role: super-admin, admin, radiographer, radiologist, referring-doctor, registration, scheduler, pacs-admin, auditor) + 3 pengguna demo (admin@testing.com, radio@testing.com, dr@testing.com) dengan `can()` permission check bekerja.
- **Adapter Python skeleton**: Dibuat `adapter/` dengan `pyproject.toml`, `src/orp_adapter/` berisi:
  - `config.py` — Settings dari .env (AE title, port, API URL, inbox dir)
  - `ris_client.py` — HTTP client ke Laravel `/api/dicom/…` (RisClient: fetch_worklist, forward_mpps, forward_study, announce_online)
  - `handlers/mwl.py` — C-FIND MWL SCP handler (generator yield dataset dari ris_client.fetch_worklist)
  - `handlers/mpps.py` — N-CREATE/N-SET/N-Action MPPS SCP handler (forward ke ris_client.forward_mpps)
  - `handlers/store.py` — C-STORE SCP handler (forward ke ris_client.forward_study, opsi persist ke inbox dir)
  - `services/echo_scu.py` — C-ECHO SCU helper (`ae.associate → send_c_echo`)
  - `services/store_scu.py` — C-STORE SCU helper (dcmread file → send_c_store)
  - `application.py` — Class Adapter: start_forever() menjalankan 3 SCPs bersamaan (MWL:4243, MPPS:4244, STORE:4245) via `start_server(block=False, evt_handlers=...)` (pynetdicom 3.x API)
  - `cli.py` — Subcommands: `run` (start servers), `ping` (C-ECHO ke peer), `store` (C-STORE file ke peer)
  - `tests/sim_aet.py` — Simulator SCU: C-ECHO, MWL query (AccessionNumber), C-STORE file ke peer
  - `uv sync` sukses: pynetdicom 3.0.4, pydicom 3.0.2, requests, python-dotenv terinstall.
- **Changelog & Plan documents**: Dibuat `Rencana Pembangunan — ORP RIS.md` dan `Changelog — ORP RIS.md` di root proyek.
- **Toolchain verifikasi**: PHP 8.3.28 (pdo_pgsql diaktifkan, redis warning tidak mengganggu), Composer 2.4.1, Node v22.21.1, Python 3.10.6, uv 0.11.6; Docker belum terinstall; psql/pg_isready tidak ada di PATH; PostgreSQL server lokal belum tersedia (dev menggunakan SQLite).

### Changed
- `APP_NAME` di `.env` & `.env.example` diberi tanda kutip `"ORP RIS"` agar parse .env sukses.
- `DB_CONNECTION` di `.env` diganti dari `pgsql` ke `sqlite` untuk dev lokal (sesuai keputusan user fallback jika Postgres belum ada).
- `DB_CONNECTION` di `.env.example` tetap `pgsql` sebagai target produksi (`orp_ris` database).
- `RolesAndPermissionsSeeder` ditulis ulang dengan ekspansi wildcard `module.*` → permission konkret; diproses via `Permission::findOrCreate` lalu `Role::syncPermissions` / `givePermissionTo`.
- `DatabaseSeeder` email dummy diganti `@testing.com` domain.
- `php.ini` diaktifkan `extension=pdo_pgsql` (dari komentar `;extension=pdo_pgsql` menjadi `extension=pdo_pgsql`).
- `User` model diperluas dengan trait `HasRoles` dari spatie/laravel-permission.
- `audit_logs` table dibuat dengan kolom: id, user_id (FK nullable), action, auditable_type, auditable_id, changes (json), ip_address, user_agent, timestamps.
- `mwl.py` `_string()` ditambah try/except untuk handling nilai element yang sudah berupa string (kompatibel pynetdicom 3.x).
- `mpps.py` `install()` mengembalikan list tuple `(evt, handler, [args...])` untuk `evt_handlers` pynetdicom 3.x (alih dari atribut `on_*`.
- `store.py` `install()` mengembalikan list tuple `(events.EVT_C_STORE, handler, [ris, settings])` dan menambahkan konteks SOP kelas penyimpanan terdaftar (`SUPPORTED_CONTEXTS`).
- `application.py` `run_forever()` meredefinisikan alur server MWL/MPPS/STORE menggunakan `start_server(block=False, evt_handlers=...)` dan menambahkan konteks SOP yang sesuai per service (MWL → ModalityWorklistInformationFind, MPPS → ModalityPerformedProcedureStep, STORE → 15 SOP kelas penyimpanan terpilih).
- `sim_aet.py` `send_c_find()` ditambah argumen `query_model=ModalityWorklistInformationFind` (API pynetdicom 3.x).
- `sim_aet.py` `send_c_store()` error handling ditambahkan pesan ValueError mengenai transfer syntax.

### Fixed
- Masalah `laravel new ris` aborted tapi tetap menghasilkan folder ris lengkap (vendor, node_modules, .env, artisan, dll) tanpa `.git` nested (karena repo induk `DCM4CHE` sudah ada).
- Kesalahan `_string` Accessor di `mwl.py` ketika `element.value` bukan Dataset melainkan string — ditambahkan try/except.
- `send_c_find` di `sim_aet.py` memerlukan `query_model` argumen pynetdicom 3.x (`ModalityWorklistInformationFind`).
- `send_c_store` di `sim_aet.py` menangkap ValueError tentang transfer syntax dan menampilkannya dengan jelas.
- `pdo_pgsql` diaktifkan di php.ini sehingga `php -m` menunjukkan `pdo_pgsql` terdaftar bersama sqlite dan redis.

### Deprecated
- Atribut `on_n_create`, `on_n_set`, `on_n_action`, `on_c_echo`, `on_c_find`, `on_c_store` dari pynetdicom versi lama — digantikan oleh `evt_handlers` list + `start_server(block=False, evt_handlers=...)`.

### Security
- `ORP_RIS_API_KEY` di `.env.example` disediakan (nilai kosong) untuk enkripsi request antar adapter ↔ Laravel via header `X-API-Key`.
- `AuditLog` mencatat `ip_address` dan `user_agent` untuk audit trail semua aksi DICOM.
- Packages spatie/laravel-permission di-install dengan `composer require --no-interaction`; migration tables dibuat via `vendor` publish.

### Known Issues
- PostgreSQL server lokal belum tersedia — dev menggunakan SQLite; production target tetap pgsql (`orp_ris` database).
- Folder `platform/` belum ada di disk — perlu dibuat ulang konfig Orthanc + OHIF (dari tree `68d72f72`).
- pynetdicom 3.x API perbedaan versi 2.x — beberapa handler butuh penyesuaian jika library di-update.
- `STORE_SCU` butuh transfer syntax JPEG Lossless untuk orthanc; adaptor sekarang support standar umum (15 SOP kelas).
- Queue driver `database` memerlukan migrasi jobs table (sudah ada dari scaffold Laravel default).