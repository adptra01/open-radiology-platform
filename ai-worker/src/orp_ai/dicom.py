"""DICOM input adapter (M8 boundary) — torch-independent.

This module provides a single, deterministic path from raw DICOM bytes to the
8-bit grayscale PIL image that the TB model (trained on 8-bit JPEG/PNG) expects.

Contract
--------
* No torch, no torchvision, no torchxrayvision imports.
* Only stdlib + pydicom + numpy + Pillow.
* Pure functions — stateless, no global caches, no side effects.
* Raises ``DicomValidationError`` on any contract violation so callers can
  branch cleanly to ``available=False`` without try/except sprawl.

Validation performed (fail-fast, explicit messages):
1. Modality in allowed set (default: CR, DX for chest X-ray).
2. PhotometricInterpretation understood (MONOCHROME1/2, YBR_FULL_422).
3. BitsStored in {8, 10, 12, 14, 16}; HighBit == BitsStored-1.
4. PixelRepresentation handled (0=unsigned, 1=signed two's complement).
5. PlanarConfiguration == 0 (single plane, required for pixel_array).
6. RescaleSlope/RescaleIntercept applied before windowing.
7. VOI LUT applied if present (window center/width takes precedence).
8. Output is deterministic 8-bit L-mode PIL image, windowed to soft-tissue.

Usage
-----
    from orp_ai.dicom import read_dicom_as_pil, DicomValidationError

    try:
        pil_img = read_dicom_as_pil("study/image.dcm")
    except DicomValidationError as e:
        return {"available": False, "note": str(e)}

    # pil_img is ready for _TB_TRANSFORM
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Literal

import numpy as np
import pydicom
from pydicom.dataset import Dataset
from PIL import Image

log = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Public exceptions
# ---------------------------------------------------------------------------


class DicomValidationError(ValueError):
    """Raised when a DICOM file violates the M8 input contract.

    Catch this in adapters to return ``available=False`` with a clear note.

    Attributes:
        code: machine-readable reason (stable API surface for the RIS UI,
            e.g. ``unsupported_modality``). The RIS renders ``user_reason``
            and keeps the technical message in audit details only.
    """

    def __init__(self, message: str, code: str = "invalid_dicom") -> None:
        super().__init__(message)
        self.code = code


# ---------------------------------------------------------------------------
# User-facing reasons (safe to show in the RIS UI — no DICOM internals)
# ---------------------------------------------------------------------------

USER_REASONS: dict[str, str] = {
    "unsupported_modality": "This AI task only supports chest X-ray (CR/DX) images.",
    "unsupported_photometric": "Image encoding not supported by this AI task.",
    "unsupported_bits": "Bit depth not supported by this AI task.",
    "inconsistent_bits": "Inconsistent DICOM bit-depth metadata.",
    "unsupported_planar": "Pixel layout not supported by this AI task.",
    "missing_pixel_data": "No image data in this DICOM object.",
    "unreadable_dicom": "File could not be read as a DICOM object.",
    "invalid_dicom": "DICOM object not compatible with this AI task.",
}


def user_reason(code: str) -> str:
    """User-facing reason for a reason code (safe to show in the RIS UI)."""
    return USER_REASONS.get(code, USER_REASONS["invalid_dicom"])


# ---------------------------------------------------------------------------
# Configuration (can be overridden by callers for other tasks)
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class DicomInputConfig:
    """Immutable validation rules for a specific AI task.

    The TB task only accepts CR/DX chest. Other tasks (e.g. CT lung-nodule)
    would provide their own config.
    """

    allowed_modalities: tuple[str, ...] = ("CR", "DX")
    allowed_photometric: tuple[str, ...] = ("MONOCHROME1", "MONOCHROME2", "YBR_FULL_422")
    allowed_bits_stored: tuple[int, ...] = (8, 10, 12, 14, 16)
    require_planar_zero: bool = True
    target_bit_depth: int = 8  # model training bit depth


# Default config for TB screening (CR/DX chest)
TB_DICOM_CONFIG = DicomInputConfig()


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------


def _validate_modality(ds: Dataset, cfg: DicomInputConfig) -> None:
    modality = getattr(ds, "Modality", None)
    if modality not in cfg.allowed_modalities:
        raise DicomValidationError(
            f"Modality '{modality}' not allowed for this task. "
            f"Allowed: {cfg.allowed_modalities}",
            code="unsupported_modality",
        )


def _validate_photometric(ds: Dataset, cfg: DicomInputConfig) -> None:
    photometric = getattr(ds, "PhotometricInterpretation", None)
    if photometric not in cfg.allowed_photometric:
        raise DicomValidationError(
            f"PhotometricInterpretation '{photometric}' not supported. "
            f"Allowed: {cfg.allowed_photometric}",
            code="unsupported_photometric",
        )


def _validate_bits(ds: Dataset, cfg: DicomInputConfig) -> None:
    bits_stored = getattr(ds, "BitsStored", None)
    bits_alloc = getattr(ds, "BitsAllocated", None)
    high_bit = getattr(ds, "HighBit", None)

    if bits_stored not in cfg.allowed_bits_stored:
        raise DicomValidationError(
            f"BitsStored={bits_stored} not in allowed {cfg.allowed_bits_stored}",
            code="unsupported_bits",
        )
    if bits_alloc is not None and bits_stored > bits_alloc:
        raise DicomValidationError(
            f"BitsStored ({bits_stored}) > BitsAllocated ({bits_alloc})",
            code="inconsistent_bits",
        )
    if high_bit is not None and high_bit != bits_stored - 1:
        raise DicomValidationError(
            f"HighBit ({high_bit}) != BitsStored-1 ({bits_stored - 1})",
            code="inconsistent_bits",
        )


def _validate_planar(ds: Dataset, cfg: DicomInputConfig) -> None:
    if cfg.require_planar_zero:
        planar = getattr(ds, "PlanarConfiguration", 0)
        if planar != 0:
            raise DicomValidationError(
                f"PlanarConfiguration={planar} not supported (require 0 for pixel_array)",
                code="unsupported_planar",
            )


def _apply_rescale(arr: np.ndarray, ds: Dataset) -> np.ndarray:
    """Apply RescaleSlope and RescaleIntercept per DICOM PS3.3.

    Stored Value -> Modality Value:  modality = stored * slope + intercept
    """
    slope = getattr(ds, "RescaleSlope", 1.0)
    intercept = getattr(ds, "RescaleIntercept", 0.0)

    # pydicom may return strings; coerce safely
    try:
        slope = float(slope)
    except (TypeError, ValueError):
        slope = 1.0
    try:
        intercept = float(intercept)
    except (TypeError, ValueError):
        intercept = 0.0

    if slope != 1.0 or intercept != 0.0:
        arr = arr.astype(np.float32) * slope + intercept
    return arr


def _apply_voi_lut(arr: np.ndarray, ds: Dataset) -> np.ndarray:
    """Apply VOI LUT (window center/width) if present.

    Per PS3.3 C.11.2.1.2, window center/width takes precedence over
    VOI LUT Sequence. We implement the common linear windowing.
    """
    wc = getattr(ds, "WindowCenter", None)
    ww = getattr(ds, "WindowWidth", None)

    if wc is None or ww is None:
        return arr

    # Multi-value elements may be MultiValue or list; take first
    if isinstance(wc, (list, tuple, pydicom.multival.MultiValue)):
        wc = float(wc[0])
    else:
        wc = float(wc)
    if isinstance(ww, (list, tuple, pydicom.multival.MultiValue)):
        ww = float(ww[0])
    else:
        ww = float(ww)

    if ww <= 0:
        return arr

    # Linear window: output = (input - (wc - 0.5)) / ww + 0.5
    # Then clip to [0, 1]
    arr = (arr - (wc - 0.5)) / ww + 0.5
    return np.clip(arr, 0.0, 1.0)


def _normalize_monochrome1(arr: np.ndarray, ds: Dataset) -> np.ndarray:
    """Invert MONOCHROME1 so that higher values = denser tissue (bone=white).

    After this, both MONOCHROME1 and MONOCHROME2 have the same intensity
    semantics: larger value = more attenuation.
    """
    photometric = getattr(ds, "PhotometricInterpretation", "MONOCHROME2")
    if photometric == "MONOCHROME1":
        # Invert within the current data range
        arr = arr.max() - arr
    return arr


def _handle_pixel_representation(arr: np.ndarray, ds: Dataset) -> np.ndarray:
    """Convert signed pixel data (PixelRepresentation=1) to unsigned float.

    DICOM signed is two's complement. pydicom's pixel_array already returns
    the correct signed integer dtype (int16/int32). We just need to shift
    to unsigned range for windowing.
    """
    pixel_repr = getattr(ds, "PixelRepresentation", 0)
    if pixel_repr == 1:
        # Signed: shift by 2^(BitsStored-1) to make unsigned
        bits_stored = getattr(ds, "BitsStored", 16)
        shift = 1 << (bits_stored - 1)
        arr = arr.astype(np.int32) + shift  # now in [0, 2^BitsStored)
    return arr.astype(np.float32)


def _convert_ybr_to_monochrome2(arr: np.ndarray, ds: Dataset) -> np.ndarray:
    """Convert YBR_FULL_422 to MONOCHROME2 luminance.

    pydicom's pixel_array for YBR_FULL_422 returns the raw YBR planes.
    We only need Y (luminance) for grayscale AI input.
    """
    photometric = getattr(ds, "PhotometricInterpretation", "")
    if photometric == "YBR_FULL_422":
        # Y is first channel; subsampled 4:2:2
        # pixel_array shape: (rows, cols, 3) for YBR
        if arr.ndim == 3 and arr.shape[2] >= 1:
            arr = arr[:, :, 0]  # Y plane
    return arr


def _window_to_8bit(arr: np.ndarray, method: Literal["percentile", "voi"] = "percentile") -> np.ndarray:
    """Map float array to deterministic 8-bit [0, 255].

    Two methods:
    - "voi": assumes arr already in [0,1] from VOI LUT, just scale
    - "percentile": robust 1-99 percentile window (fallback when no VOI)
    """
    if method == "voi":
        arr = np.clip(arr, 0.0, 1.0)
    else:
        lo, hi = np.percentile(arr, (1, 99))
        if hi - lo < 1e-6:
            # Uniform image — avoid div by zero
            arr = np.zeros_like(arr)
        else:
            arr = np.clip((arr - lo) / (hi - lo), 0.0, 1.0)
    return (arr * 255.0).astype(np.uint8)


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------


def read_dicom_as_pil(
    path: str | Path,
    config: DicomInputConfig = TB_DICOM_CONFIG,
) -> Image.Image:
    """Read a DICOM file and return an 8-bit L-mode PIL image ready for the model.

    This is the single entry point for all DICOM→AI preprocessing. It performs
    full validation and raises ``DicomValidationError`` on any contract breach.

    Args:
        path: Path to the .dcm file.
        config: Validation rules for the specific AI task (default: TB screening).

    Returns:
        PIL.Image in mode "L" (8-bit grayscale), sized at native resolution.
        The caller applies Resize/Normalize via torchvision transforms.

    Raises:
        DicomValidationError: If the DICOM violates the input contract.
        FileNotFoundError: If the file does not exist.
        pydicom.errors.InvalidDicomError: If the file is not a valid DICOM.
    """
    path = Path(path)
    if not path.exists():
        raise FileNotFoundError(f"DICOM file not found: {path}")

    ds = pydicom.dcmread(path, force=True)

    # ---- Validation phase (fail-fast) ----
    _validate_modality(ds, config)
    _validate_photometric(ds, config)
    _validate_bits(ds, config)
    _validate_planar(ds, config)

    # ---- Pixel extraction ----
    # pydicom.pixel_array handles transfer syntax decompression, planar, etc.
    try:
        arr = ds.pixel_array  # type: ignore[attr-defined]
    except AttributeError as e:
        raise DicomValidationError(
            f"No PixelData in DICOM object: {e}", code="missing_pixel_data"
        ) from e

    # ---- Preprocessing pipeline (deterministic order) ----
    # 1. Pixel representation (signed -> unsigned)
    arr = _handle_pixel_representation(arr, ds)

    # 2. YBR_FULL_422 -> MONOCHROME2 (luminance only)
    arr = _convert_ybr_to_monochrome2(arr, ds)

    # 3. Rescale slope/intercept (stored -> modality values, e.g. HU)
    arr = _apply_rescale(arr, ds)

    # 4. MONOCHROME1 inversion (so bone=white in both photometrics)
    arr = _normalize_monochrome1(arr, ds)

    # 5. VOI LUT / Window Center/Width (preferred)
    #    If VOI present, arr ends up in [0,1]; else fallback to percentile
    has_voi = hasattr(ds, "WindowCenter") and hasattr(ds, "WindowWidth")
    arr = _apply_voi_lut(arr, ds)

    # 6. Final 8-bit mapping
    arr_8bit = _window_to_8bit(arr, method="voi" if has_voi else "percentile")

    return Image.fromarray(arr_8bit, mode="L")


def validate_dicom_only(path: str | Path, config: DicomInputConfig = TB_DICOM_CONFIG) -> dict[str, Any]:
    """Lightweight validation without full pixel processing.

    Used by ``status()`` endpoints to check capability without loading pixels.
    Returns a dict with ``valid`` bool and ``details`` for logging.
    """
    from typing import Any

    try:
        ds = pydicom.dcmread(Path(path), stop_before_pixels=True, force=True)
        _validate_modality(ds, config)
        _validate_photometric(ds, config)
        _validate_bits(ds, config)
        _validate_planar(ds, config)
        return {
            "valid": True,
            "modality": getattr(ds, "Modality", None),
            "photometric": getattr(ds, "PhotometricInterpretation", None),
            "bits_stored": getattr(ds, "BitsStored", None),
            "rows": getattr(ds, "Rows", None),
            "columns": getattr(ds, "Columns", None),
        }
    except DicomValidationError as e:
        return {
            "valid": False,
            "reason": str(e),
            "reason_code": e.code,
            "user_reason": user_reason(e.code),
        }
    except Exception as e:  # noqa: BLE001
        return {
            "valid": False,
            "reason": f"read error: {e}",
            "reason_code": "unreadable_dicom",
            "user_reason": user_reason("unreadable_dicom"),
        }


# ---------------------------------------------------------------------------
# Backward-compat shim for existing code (will be removed after migration)
# ---------------------------------------------------------------------------


def _read_as_grayscale_pil_legacy(img_path: str | Path) -> Image.Image:
    """Legacy implementation from tb.py — kept for reference during migration.

    DO NOT USE IN NEW CODE. This has the bugs documented in the M8 contract:
    - No RescaleSlope/Intercept
    - No VOI LUT
    - No PixelRepresentation handling
    - No PlanarConfiguration check
    - Percentile windowing only
    """
    import warnings

    warnings.warn(
        "_read_as_grayscale_pil_legacy is deprecated; use read_dicom_as_pil()",
        DeprecationWarning,
        stacklevel=2,
    )
    path = Path(img_path)
    if path.suffix.lower() == ".dcm":
        import pydicom as pdcm

        ds = pdcm.dcmread(path)
        arr = np.asarray(ds.pixel_array, dtype=np.float32)
        if ds.PhotometricInterpretation == "MONOCHROME1":
            arr = arr.max() - arr
        lo, hi = np.percentile(arr, (1, 99))
        arr = np.clip((arr - lo) / max(hi - lo, 1e-6), 0, 1) * 255.0
        return Image.fromarray(arr.astype(np.uint8), mode="L")
    return Image.open(path).convert("L")