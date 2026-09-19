# M8 Contract: DICOM → AI Gateway → AI Result

**Version:** 1.0  
**Status:** Active  
**Owner:** AI Worker team  
**Last Updated:** 2026-09-19

---

## 1. Purpose

This document defines the **M8 boundary** — the single, deterministic contract between
raw DICOM input and the AI inference pipeline. It exists because:

> A TB model trained on 8-bit public JPEG/PNG (Montgomery/Shenzhen) receives raw
> 16-bit DICOM in production. Without a contract, the same pixel values produce
> different model inputs → **"normal in OHIF but white in AI"**.

The M8 layer guarantees that **every DICOM file that passes validation produces
exactly the same 8-bit grayscale distribution the model was trained on**.

---

## 2. Scope

| In Scope | Out of Scope |
|----------|--------------|
| CR/DX chest X-ray DICOM (TB screening task) | CT, MR, US, PET modalities |
| MONOCHROME1, MONOCHROME2, YBR_FULL_422 | RGB, PALETTE COLOR, YBR_FULL |
| BitsStored ∈ {8,10,12,14,16} | BitsStored > 16 or < 8 |
| Single-frame, PlanarConfiguration=0 | Multi-frame, planar > 0 |
| RescaleSlope/Intercept, VOI LUT | Modality LUT, Presentation LUT |
| Deterministic 8-bit L-mode PIL output | Color output, 16-bit output |

**Future tasks** (CT lung-nodule, MR prostate, etc.) create their own
`DicomInputConfig` — the M8 code path stays identical.

---

## 3. Contract Definition

### 3.1 Input Requirements (Validation Gate)

A DICOM file **must** satisfy all of the following to enter the pipeline:

| Check | Rule | Error if Violated |
|-------|------|-------------------|
| **Modality** | `Modality ∈ {CR, DX}` | `DicomValidationError: Modality 'CT' not allowed...` |
| **PhotometricInterpretation** | `∈ {MONOCHROME1, MONOCHROME2, YBR_FULL_422}` | `DicomValidationError: PhotometricInterpretation 'RGB' not supported...` |
| **BitsStored** | `∈ {8,10,12,14,16}` AND `HighBit = BitsStored-1` | `DicomValidationError: BitsStored=20 not in allowed...` |
| **PixelRepresentation** | `0` (unsigned) or `1` (signed two's complement) | Handled automatically |
| **PlanarConfiguration** | `0` (required for `pixel_array`) | `DicomValidationError: PlanarConfiguration=1 not supported...` |
| **SamplesPerPixel** | `1` (grayscale) or `3` (YBR only) | Implicit via photometric check |

**Failure mode:** Any validation failure → `DicomValidationError` raised →
adapter returns `{"available": False, "note": "<exact error>"}`.  
**No silent fallbacks, no partial processing.**

### 3.2 Preprocessing Pipeline (Deterministic Order)

The following steps execute **in exact order** for every validated DICOM:

```
Raw PixelData (stored values)
         │
         ▼
1. PixelRepresentation handling
   • PR=1 (signed): shift by 2^(BitsStored-1) → unsigned [0, 2^BitsStored)
   • PR=0 (unsigned): no-op
         │
         ▼
2. YBR_FULL_422 → MONOCHROME2 (luminance only)
   • Extract Y plane (arr[:,:,0]) if 3-channel
   • Other photometrics: no-op
         │
         ▼
3. RescaleSlope / RescaleIntercept
   • modality_value = stored × slope + intercept
   • Default: slope=1.0, intercept=0.0 (no-op)
         │
         ▼
4. MONOCHROME1 inversion
   • If PhotometricInterpretation == MONOCHROME1: arr = max(arr) - arr
   • Result: bone=white (high values) for both MONOCHROME1/2
         │
         ▼
5. VOI LUT (Window Center/Width) — PREFERRED PATH
   • If WindowCenter & WindowWidth present:
       output = (input - (wc - 0.5)) / ww + 0.5  → clip [0,1]
   • This maps modality values (e.g. HU) to normalized [0,1]
         │
         ▼
6. Final 8-bit mapping
   • If VOI applied (method="voi"):  clip [0,1] × 255 → uint8
   • Else (fallback): 1-99 percentile window → [0,1] × 255 → uint8
         │
         ▼
Output: PIL.Image(mode="L", size=(Rows, Columns), dtype=uint8)
```

**Critical invariants:**
- Same input DICOM → **bitwise identical** output PIL image (deterministic)
- No randomness, no external state, no filesystem side effects
- Pipeline is **torch-independent** (stdlib + pydicom + numpy + Pillow only)

### 3.3 Output Contract

| Property | Value |
|----------|-------|
| **Type** | `PIL.Image.Image` |
| **Mode** | `"L"` (8-bit grayscale) |
| **Size** | `(ds.Columns, ds.Rows)` — native resolution |
| **Dynamic range** | Full 0–255 used (soft-tissue windowed) |
| **Semantics** | Higher value = more attenuation (bone=white) |

This output is fed directly into the model's torchvision transform:

```python
_TB_TRANSFORM = transforms.Compose([
    transforms.Grayscale(num_output_channels=3),  # L → RGB (3 identical channels)
    transforms.Resize((224, 224)),
    transforms.ToTensor(),                        # [0,1] float32
    transforms.Normalize(mean=[0.485,0.456,0.406], std=[0.229,0.224,0.225]),
])
```

---

## 4. Error Handling Contract

### 4.1 Validation Errors (DicomValidationError)

Raised **before** any pixel processing. Adapter catches and returns:

```python
{
    "available": False,
    "probability": None,
    "note": "Modality 'CT' not allowed for this task. Allowed: ('CR', 'DX')"
}
```

### 4.2 Processing Errors (pydicom, PIL, numpy)

Any exception during pixel extraction/preprocessing → logged + degraded:

```python
{
    "available": False,
    "probability": None,
    "note": "inference error: <exception message>"
}
```

### 4.3 Model Unavailable (weights missing)

Separate from DICOM contract — handled by `load_tb_model()`:

```python
{
    "available": False,
    "probability": None,
    "note": "TB weights not found — run Colab fine-tune notebook..."
}
```

---

## 5. Testing Requirements

### 5.1 Contract Tests (Mandatory)

Every PR touching `dicom.py` **must** pass:

| Test Category | Coverage |
|---------------|----------|
| **Validation** | All 6 validation rules (modality, photometric, bits, highbit, planar, pixelrepr) |
| **Preprocessing** | Rescale, VOI, MONOCHROME1, signed PR, YBR extraction |
| **Edge cases** | Uniform image, missing VOI (percentile fallback), deterministic output |
| **Extensibility** | Custom `DicomInputConfig` for non-TB tasks |

Current test file: `tests/test_dicom.py` (26 tests)

### 5.2 Regression Tests (Mandatory)

| Scenario | Expected |
|----------|----------|
| Public DICOM (Montgomery/Shenzhen) | `available=True`, probability ∈ [0,1] |
| MONOCHROME1 DICOM | Inverted correctly (bone=white) |
| DICOM with RescaleSlope≠1 | Modality values applied before windowing |
| DICOM with WindowCenter/Width | VOI path taken, not percentile |
| Signed pixel data (PR=1) | Shifted to unsigned, no clipping artifacts |

---

## 6. Implementation Map

| File | Responsibility |
|------|----------------|
| `src/orp_ai/dicom.py` | **Single source of truth** — all validation + preprocessing |
| `src/orp_ai/tb.py` | Calls `read_dicom_as_pil()` via `_read_as_grayscale_pil()` shim |
| `src/orp_ai/adapters.py` | `TbAdapter.predict()` → `tb.predict_tb()` → `dicom.read_dicom_as_pil()` |
| `tests/test_dicom.py` | Contract tests (26) |
| `tests/test_smoke.py` | Integration tests (7) |

---

## 7. Migration Checklist (Completed)

- [x] Create `dicom.py` with full validation + preprocessing
- [x] Update `tb.py` to delegate to `dicom.read_dicom_as_pil()`
- [x] Remove legacy `_read_as_grayscale_pil` logic (kept as deprecated shim)
- [x] Add 26 contract tests in `tests/test_dicom.py`
- [x] All 33 tests pass (26 dicom + 7 smoke)
- [x] This contract document created

---

## 8. Future Extensions

| Task | Config Change | Code Change |
|------|---------------|-------------|
| CT lung-nodule | `DicomInputConfig(allowed_modalities=("CT",), allowed_photometric=("MONOCHROME2",))` | None — same `read_dicom_as_pil()` |
| MR prostate | Add `allowed_photometric=("MONOCHROME2",)` + custom windowing | Optional: task-specific `_window_to_8bit` override |
| US thyroid | Add `allowed_modalities=("US",)` | May need multi-frame handling |

**Rule:** Never duplicate DICOM loading logic. Every new task uses `read_dicom_as_pil()`
with its own `DicomInputConfig`.

---

## 9. Appendix: Why This Matters

### The "White in AI" Problem

| Source | Pixel Range | Windowing | Model Input |
|--------|-------------|-----------|-------------|
| JPEG (training) | 0–255 | None (already 8-bit) | Correct distribution |
| DICOM (production) | 0–4095 (12-bit) | **Missing** Rescale + VOI | All pixels → 255 (white) |

**Before M8:** `_read_as_grayscale_pil` used simple 1-99 percentile on raw stored
values → ignored RescaleSlope/Intercept → ignored WindowCenter/Width →
**model sees washed-out or inverted images**.

**After M8:** Full DICOM pipeline applied → model sees **exact same distribution**
as training data (8-bit soft-tissue windowed).

---

## 10. Sign-Off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| AI Worker Lead | — | 2026-09-19 | ✓ |
| RIS Integration | — | — | ☐ |
| Radiology (Clinical) | — | — | ☐ |

---

*This contract is version-controlled. Any change to `dicom.py` preprocessing order
or validation rules requires updating this document and all contract tests.*