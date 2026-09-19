"""DICOM reader — the single entry point for DICOM→AI ingestion.

Orchestration only: read -> validate (fail-fast) -> shared intensity
pipeline -> 8-bit PIL. Model-specific transforms (resize/normalize) are
the task's job (``tasks/<task>/preprocessing.py``), never the reader's.
"""

from __future__ import annotations

import logging
from pathlib import Path

import pydicom
from PIL import Image

from ai_gateway.dicom import preprocessing as ip
from ai_gateway.dicom.validator import (
    TB_DICOM_CONFIG,
    DicomInputConfig,
    DicomValidationError,
    validate_dataset,
)

log = logging.getLogger(__name__)


def read_dicom_as_pil(
    path: str | Path,
    config: DicomInputConfig = TB_DICOM_CONFIG,
) -> Image.Image:
    """Read a DICOM file and return an 8-bit L-mode PIL image for the model.

    Args:
        path: Path to the .dcm file.
        config: Validation rules for the specific AI task (default: TB).

    Returns:
        PIL.Image in mode "L" (8-bit grayscale), sized at native resolution.
        The caller applies Resize/Normalize via its locked task transform.

    Raises:
        DicomValidationError: If the DICOM violates the input contract
            (includes ``missing_pixel_data`` when PixelData is absent).
        FileNotFoundError: If the file does not exist.
        pydicom.errors.InvalidDicomError: If the file is not a valid DICOM.
    """
    path = Path(path)
    if not path.exists():
        raise FileNotFoundError(f"DICOM file not found: {path}")

    ds = pydicom.dcmread(path, force=True)

    # ---- Validation phase (fail-fast) ----
    validate_dataset(ds, config)

    # ---- Pixel extraction ----
    # pydicom.pixel_array handles transfer syntax decompression, planar, etc.
    try:
        arr = ds.pixel_array  # type: ignore[attr-defined]
    except AttributeError as e:
        raise DicomValidationError(
            f"No PixelData in DICOM object: {e}", code="missing_pixel_data"
        ) from e

    # ---- Shared intensity pipeline (deterministic order) ----
    arr = ip.handle_pixel_representation(arr, ds)   # 1. signed -> unsigned
    arr = ip.convert_ybr_to_monochrome2(arr, ds)    # 2. YBR -> MONOCHROME2
    arr = ip.apply_rescale(arr, ds)                 # 3. stored -> modality
    arr = ip.normalize_monochrome1(arr, ds)         # 4. MONO1 inversion

    # 5. VOI LUT / Window Center-Width (preferred)
    #    If VOI present, arr ends up in [0,1]; else fallback to percentile
    has_voi = hasattr(ds, "WindowCenter") and hasattr(ds, "WindowWidth")
    arr = ip.apply_voi_lut(arr, ds)

    # 6. Final 8-bit mapping
    arr_8bit = ip.window_to_8bit(arr, method="voi" if has_voi else "percentile")

    return Image.fromarray(arr_8bit, mode="L")
