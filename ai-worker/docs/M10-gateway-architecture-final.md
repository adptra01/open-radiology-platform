# Arsitektur AI Gateway — Rekaman Keputusan Final (M10+, 2026-09-19)

Keputusan final (user): **big-bang refactor fondasi; implementasi nyata hanya TB.**
Dokumen ini menggantikan `M10-gateway-task-registry.md` sebagai kontrak berjalan
(file lama dipertahankan sebagai riwayat).

## 1. Batas tanggung jawab

```text
Laravel = system of record (workflow, RBAC, audit, AI runs canonical)
React   = UI (via Laravel API, tidak langsung ke Python)
Python  = inference engine stateless (DICOM -> tensor -> model -> result)
```

## 2. Tiga konsep terpisah: Modality / AI Task / AI Model

```text
Modality (CR) -> AI Task (tb-screening) -> Model (tb-densenet121 v1.0)
```

Tanpa `if modality == DX -> runTb()` di RIS. Tanpa database pasien di Python.

## 3. Paket Python: ai_gateway/ (canonical), orp_ai/ (legacy sampai cutover)

```text
ai_gateway/
├── server.py            app (cutover: uvicorn/Docker CMD ke sini)
├── api/health.py        GET /health
├── api/capabilities.py  GET /capabilities
├── api/runs.py          POST /infer/{task} + POST /infer/tb (alias) + POST /infer (xrv)
├── core/registry.py     register/get/list_capabilities
├── core/dispatcher.py   run_task: validate->predict->postprocess->envelope
├── core/envelope.py     envelope terkunci + ERROR_CODE_MAP (dikunci test)
├── core/models.py       model registry + resolusi bobot per versi
├── core/exceptions.py   UnknownTaskError (-> 404)
├── dicom/reader.py      dcmread + orkestrasi pipeline
├── dicom/validator.py   checks + reason_code + USER_REASONS
├── dicom/preprocessing.py  pipeline intensitas bersama (rescale/VOI/MONO1/8-bit)
├── tasks/tb/adapter.py  TBScreeningAdapter (threshold 0.50, uncalibrated)
├── tasks/tb/preprocessing.py  transform terkunci cermin training
├── tasks/tb/model.py    arsitektur + load/cache checkpoint
├── tasks/tb/inference.py  predict_file -> payload mentah
└── models/tb-densenet121/1.0/{metadata.json, preprocessing.json}
```

## 4. Envelope generik (kontrak stabil)

Sukses: `task{id,name}, model{id,version}, status:"completed", input{filename},
result{type:"classification", classification{label,score,threshold},
calibration{status:"uncalibrated"}}, metadata{processing_time_ms, disclaimer, preprocessing}`.
Gagal: `status:"failed" + error{code,message}` + detail teknis di metadata.
Threshold 0.50 eksplisit BUKAN nilai klinis tervalidasi (Phase 3 mengkalibrasi).

## 5. Laravel: ai_runs canonical, ai_results legacy

`ai_runs(run_id AIR-YYMMDD-XXXX, study_id, series_id, task_id, model_id,
model_version, threshold, status queued/running/completed/failed/cancelled,
input_reference, result, metadata, error_code/message, created_by, timestamps)`.
API: `GET /api/ai/capabilities`, `POST /api/ai/runs` (202), `GET /api/ai/runs`,
`GET /api/ai/runs/{runId}`. `AiClient::inferTask()` + fallback legacy `/infer/tb`
untuk worker lama. Job `RunAiRun` idempotent + dual-write legacy `raw_report['tb']`.

## 6. React: AiAssistancePanel generik, AiTbCard fallback

Panel capability-driven (Run manual per task, polling aktif, renderer per
`result.type`, disclaimer, technical-details toggle, riwayat). Card lama utuh.

## 7. Prinsip yang dikunci

- DICOM source of truth; model terima tensor; tanpa jalur DICOM->JPEG->model.
- Preprocessing production = training-equivalent, dikunci di paket model.
- INVALID -> AI NOT RUN (failed + kode), bukan prediksi palsu; null != negative.
- Trigger manual (tidak ada auto-run baru); auto-dispatch C-STORE tak berubah.
- Gateway berdiri sendiri (tanpa Laravel); model diganti tanpa ubah RIS.
- Out-of-scope: model CT/MR/US, nodule/ICH, segmentasi, LLM report, auto-diagnosis.

## 8. Roadmap lanjutan (Phase 2-5, M11+)

Phase 2: E2E bobot real (Kaggle/Colab saat GPU ada) — cutover `orp_ai` -> `ai_gateway`,
hapus shim + card legacy. Phase 3: evaluasi (sens/spec/ROC) + threshold terkalibrasi.
Phase 4: integrasi RIS penuh. Phase 5: auditability.
