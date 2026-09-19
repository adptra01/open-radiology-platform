# M10 — AI Gateway: Task Registry + Structured AI-NOT-RUN (Kontrak)

Melanjutkan M8 (kontrak DICOM `dicom.py`) sesuai visi arsitektur AI Gateway:
**TB adalah capability plugin, bukan core** — RIS bertanya "AI apa yang tersedia?",
bukan hardcode endpoint TB. Model menerima tensor; DICOM tidak pernah menjadi JPEG.

## 1. Endpoint

```text
GET  /health         status model + tb
GET  /capabilities   daftar task registry (ringan, tanpa load model)
POST /infer          xrv 18 patologi (jalur lama, tidak berubah)
POST /infer/tb       ALIAS -> run_task("tb-screening") via registry
POST /infer/{task}   dispatch generik via registry; task tak dikenal -> 404
```

`/infer/tb` dideklarasikan SEBELUM `/infer/{task}` agar alias eksplisit menang.

## 2. Kontrak respons adapter (adapters.py)

```text
available=True   -> payload nyata (mis. probability, logit)
available=False  -> reason_code (stabil, machine-readable)
                  + reason      (user-facing, aman untuk UI utama)
                  + note        (teknis, audit/details saja)
```

UI RIS: tampilkan `reason` ("Unable to process this study. Reason: ..."),
sembunyikan `note` di balik "View technical details". Jangan tampilkan
Bits Stored / Rescale / Photometric di UI utama.

## 3. Reason code stabil (dicom.USER_REASONS)

```text
unsupported_modality | unsupported_photometric | unsupported_bits
inconsistent_bits    | unsupported_planar      | missing_pixel_data
unreadable_dicom     | invalid_dicom           | weights_missing
inference_error
```

Reason code adalah **API surface** — test `test_reason_codes_are_stable_surface`
mengunci daftarnya. Menambah kode = update test + kolum UI secara eksplisit.

## 4. Alur VALID / INVALID (per task)

```text
DICOM -> validate (modality, photometric, bits, planar, pixel)
  -> VALID   -> preprocess (rescale, VOI LUT, MONO1, 8-bit)
             -> tensor (task transform) -> model -> result
  -> INVALID -> AI NOT RUN (available=False + reason_code, tanpa exception ke UI)
```

## 5. Menambah task baru (contoh: CT lung-nodule)

1. `DicomInputConfig` baru (modality CT, dst.) + adapter `predict()`/`status()`.
2. `register()` dengan `task_id` baru — RIS otomatis melihatnya di `/capabilities`.
3. Endpoint generik langsung bisa dipakai: `POST /infer/lung-nodule`.
4. Tidak ada perubahan di RIS/Laravel/React untuk mengenali task baru.

## 6. Batas tanggung jawab (tetap)

```text
Laravel = system of record (workflow, RBAC, audit, AI run records)
React   = UI (via Laravel API, tidak langsung ke Python)
Python  = inference engine (DICOM -> tensor -> model -> result)
```

Gateway berdiri sendiri (tanpa Laravel); model bisa diganti tanpa ubah RIS.
