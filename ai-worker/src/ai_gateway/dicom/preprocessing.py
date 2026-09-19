"""Shared DICOM intensity pipeline (moved from orp_ai.dicom).

Deterministic order — mirrors what the TB training data saw as PNG::

    pixel_representation (signed -> unsigned)
      -> ybr_to_monochrome (luminance only)
      -> rescale slope/intercept (stored -> modality values)
      -> monochrome1 inversion (bone=white in both photometrics)
      -> VOI LUT window (preferred) or percentile fallback
      -> 8-bit mapping

Task-specific model transforms (resize/normalize) live in
``tasks/<task>/preprocessing.py`` — NOT here.
"""

from __future__ import annotations

from typing import Literal

import numpy as np
import pydicom
from pydicom.dataset import Dataset


def apply_rescale(arr: np.ndarray, ds: Dataset) -> np.ndarray:
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


def apply_voi_lut(arr: np.ndarray, ds: Dataset) -> np.ndarray:
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


def normalize_monochrome1(arr: np.ndarray, ds: Dataset) -> np.ndarray:
    """Invert MONOCHROME1 so that higher values = denser tissue (bone=white).

    After this, both MONOCHROME1 and MONOCHROME2 have the same intensity
    semantics: larger value = more attenuation.
    """
    photometric = getattr(ds, "PhotometricInterpretation", "MONOCHROME2")
    if photometric == "MONOCHROME1":
        # Invert within the current data range
        arr = arr.max() - arr
    return arr


def handle_pixel_representation(arr: np.ndarray, ds: Dataset) -> np.ndarray:
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


def convert_ybr_to_monochrome2(arr: np.ndarray, ds: Dataset) -> np.ndarray:
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


def window_to_8bit(arr: np.ndarray, method: Literal["percentile", "voi"] = "percentile") -> np.ndarray:
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
