# RIS Completion Audit — Gap Analysis M1–M10 (2026-09-19)

Audit berbasis kode nyata (bukan klaim): backend Laravel, frontend React,
integrasi DICOM/PACS/viewer. AI di-freeze (kecuali evaluasi GPU).

Legenda: ✅ DONE · 🟡 PARTIAL · 🔴 MISSING · ⛔ OUT-OF-SCOPE

## Ringkasan DoD

```text
RIS
├── Core (workflow utama)      🟡  berjalan, 2 bug kritis (B1, B2)
├── Clinical (exam → report)   🟡  reporting + viewer ada, lubang verifikasi
├── Operational (workload)     🟡  dashboard dasar, tanpa notifikasi proaktif
└── Governance (RBAC/audit)    🟡  framework ada, 1 lubang otorisasi (B1)
```

**RIS baseline BELUM selesai.** Lima bug harus ditutup dulu (B1–B5).

## Bug kritis (perbaiki pertama)

| ID | Masalah | Bukti | Dampak |
|----|---------|-------|--------|
| B1 | `ReportController` **tanpa otorisasi** — 6 endpoint terbuka untuk semua user login, padahal seeder sediakan `reports.view/create/edit/sign` | `ReportController.php` (tanpa `AuthorizesPermissions`), `RolesAndPermissionsSeeder` | Radiographer/operator bisa finalisasi + hapus laporan FINAL |
| B2 | `ProcessTransmission` STOW **tanpa Basic auth** → 401 permanen → FAILED ×5 | `ProcessTransmission.php:63-68` vs `PacsClient::stowFile()` (yang ber-auth, tak dipakai) | Kirim ke Orthanc ber-auth SELALU gagal |
| B3 | C-STORE return **sukses palsu `0x0000`** saat RIS down/timeout (file inbox ada, DB kosong, tanpa retry) | `adapter/.../store.py:82-90` (`None` lolos cek) | Modalitas mengira terkirim — data hilang diam-diam |
| B4 | `destroy` boleh hapus report **FINAL**; `awaitingReport` hitung DRAFT/CANCELLED sebagai selesai | `ReportController:106-112,133` | Final tidak terkunci dari hapus; worklist verifikasi salah hitung |
| B5 | `file_path` menunjuk disk adapter (`inbox_dir`), belum tentu terlihat dari container RIS/queue | `store.py:93-100`, `Study.php:70-73` | Transmisi/AI gagal `file tidak terbaca` di deploy terpisah |

## 1. Patient / Registration — 🟡 PARTIAL

- ✅ Registrasi + MRN unik auto (`MRN-YYYYMMDD-XXXX`), demografi lengkap, soft delete + guard, merge manual + audit (`patient.merged`), pencarian nama/MRN/identitas.
- ❌ Tanpa halaman history pasien (order/study/report per pasien); `identity_number` tak unique; tanpa dedupe otomatis; `facility_id` tak dipakai.

## 2. Order / Examination — 🟡 PARTIAL

- ✅ Create + dokter perujuk (nullable), prioritas STAT/URGENT/ROUTINE, modalitas/prosedur, `scheduled_at`, cancel via `transitionTo(Cancelled)`, accession/order number 15-char (VR SH aman).
- ❌ Tanpa `clinical_indication`/`body_part` di orders; `REJECTED` unreachable (tanpa endpoint); transisi status **tak diaudit**; order `Completed` masih bisa diedit.

## 3. Scheduling — 🟡 PARTIAL

- ✅ CRUD appointment + transisi terkunci (CONFIRMED/CHECKED_IN/COMPLETED/NO_SHOW/CANCELLED) + audit status; filter tanggal/status.
- ❌ Tanpa kalender/slot view; tanpa cek konflik kapasitas modality/ruang; tanpa model Room; state machine duplikat UI↔backend; create UI tak kirim `modality_id`; DELETE tanpa validasi status.

## 4. Worklist / State machine — 🟡 PARTIAL

- ✅ Enum `OrderStatus` + `allowedTransitions` dikunci backend (`transitionTo`/`walkTo`); UI tak bisa set status bebas; MWL hanya order aktif.
- ❌ UI worklist kios statis (tanpa filter/aksi per status); `walkTo` melompati status tanpa jejak per-step; `NoShow/Rejected` tanpa pemicu; `Acquired→Completed` perlu verifikasi lanjutan.

## 5. DICOM — 🟡 PARTIAL

- ✅ C-STORE ingest + matching accession, UID pass-through verbatim (tanpa regenerate), MPPS lifecycle + resolve N-SET, MWL C-FIND + filter, QIDO/WADO/STOW + proxy anti-CORS, retry transmisi + backoff, idempoten per SOP UID.
- ❌ Tabel `studies` = per-instance (bukan per-study; tanpa tabel Series); PatientID/Name hilang bila unmatched; tanpa C-MOVE/C-GET retrieve; MWL tanpa wildcard/rentang tanggal + limit 200 diam-diam; bug B2/B3/B5; tanpa UI khusus MPPS unresolved.

## 6. Viewer (OHIF v2, final) — 🟡 PARTIAL

- ✅ Embed iframe + tab baru via `StudyInstanceUIDs` benar, handling study tak tersedia, return ke RIS, auth via proxy.
- ❌ Tanpa series selection, tanpa prior studies, tanpa fallback QIDO-by-accession; mati total bila Orthanc down (tanpa preview lokal).

## 7. Reporting — 🟡 PARTIAL (+B1, +B4 kritis)

- ✅ Enum `DRAFT→DICTATED→VERIFIED→FINAL`, `isEditable` dikunci, timestamp per tahap, radiologist identity, audit transisi, UI tombol berantai + save draft.
- ❌ B1: tanpa otorisasi backend. B4: FINAL bisa dihapus; tanpa endpoint amendment post-FINAL (addendum hanya saat editable); UI tanpa edit konten (PUT), assign radiologist, tolak/kembalikan, banner locked-final, print/export; `awaitingReport` salah hitung.

## 8. User / RBAC — 🟡 PARTIAL

- ✅ 9 role + matriks permission rinci; granular di patients/orders/scheduling/pacs/transmission/audit; menu guard + NoAccess UX; DICOM/FHIR by-design terpisah.
- ❌ B1 (reports terbuka); Procedure/Study numpang `orders.view` (kasar); tanpa permission `users.*`; guard UI UX-only (URL manual mount lalu NoAccess, tanpa redirect).

## 9. Audit — 🟡 PARTIAL

- ✅ Framework polimorfik + halaman `/audit` + filter action/user/tanggal; 29+ actions termasuk `ai_run.*` (created/started/completed/failed/viewed).
- ❌ Tanpa login/logout; tanpa `order.cancel`/transisi status; tanpa verify/final granular; tanpa akses study/viewer; tanpa password/2FA events.

## 10. Dashboard operasional — 🟡 PARTIAL

- ✅ Kartu: menunggu report, study hari ini, belum cocok, pasien, jadwal, draft saya, transmisi; breakdown status; order terbaru.
- ❌ Tanpa kartu urgent/STAT, failed, overdue/SLA eksplisit; tanpa workload per-modality/dokter.

## 11. Search & history — 🟡 PARTIAL

- ✅ Filter per endpoint (pasien/order/study/appointment/transmisi/audit); debounce UI.
- ❌ Report search termiskin (tanpa accession/nomor/radiolog/tanggal); order tanpa rentang tanggal/UID/multi-status; `like` vs `ilike` campur (risiko MySQL); tanpa patient history view.

## 12. Notification / exception — 🔴 MISSING

- Tanpa engine notifikasi (tabel/job/mail/broadcast nihil); STAT/urgent/failed/overdue hanya nilai tersimpan + badge reaktif, tanpa alert/eskalasi/SLA job. Yang ada: retry transmisi manual + count unmatched + `AiRun failed`.

## 13. Infrastruktur / health — ✅ DONE (minor)

- ✅ `/api/health` publik: DB+queue kritikal, AI/Orthanc non-kritikal (degraded by design — RIS jalan tanpa PACS/AI); compose lengkap.
- Minor: tanpa cek storage/disk, tanpa threshold pending_jobs, tanpa cek STOW/QIDO generik, potensi leakage pesan error.

## OUT-OF-SCOPE (disepakati)

⛔ Model AI selain TB · segmentasi · LLM report · auto-diagnosis · OHIF v3 · C-MOVE DIMSE retrieve (DICOMweb cukup untuk MVP) · notifikasi real-time push (polling/badge cukup untuk MVP bila agregat ditambah).

## Urutan perbaikan yang disarankan

```text
Batch 1 (kritis):  B1 otorisasi ReportController + B4 (lock FINAL, amendment, hitung awaitingReport)
Batch 2 (kritis):  B2 STOW auth + B3 sukses-palsu + B5 volume file_path
Batch 3 (klinik):  amendment UI + assign/verify flow + viewer priors/series + order clinical_indication + REJECTED path
Batch 4 (govern):  audit login/logout + transisi order/report + akses study
Batch 5 (operasi): dashboard urgent/failed/overdue + agregat exception + report search + patient history
```
