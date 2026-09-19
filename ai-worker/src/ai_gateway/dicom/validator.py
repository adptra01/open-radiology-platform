"""DICOM input validation — fail-fast contract per AI task (moved from orp_ai.dicom).

Validation checklist (per task config):
    Modality compatible? Photometric supported? Bits stored supported?
    Bit metadata consistent? Planar layout supported? Pixel data exists?

INVALID -> ``DicomValidationError`` carrying a stable machine-readable
``code``. Adapters turn that into AI-NOT-RUN (never a fake prediction).
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pydicom
from pydicom.dataset import Dataset

log = logging.getLogger(__name__)


class DicomValidationError(ValueError):
    """Raised when a DICOM file violates the task input contract.

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
# Configuration (per task — CT/US tasks provide their own)
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class DicomInputConfig:
    """Immutable validation rules for a specific AI task."""

    allowed_modalities: tuple[str, ...] = ("CR", "DX")
    allowed_photometric: tuple[str, ...] = ("MONOCHROME1", "MONOCHROME2", "YBR_FULL_422")
    allowed_bits_stored: tuple[int, ...] = (8, 10, 12, 14, 16)
    require_planar_zero: bool = True
    target_bit_depth: int = 8  # model training bit depth


# Default config for TB screening (CR/DX chest)
TB_DICOM_CONFIG = DicomInputConfig()


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


def validate_dataset(ds: Dataset, config: DicomInputConfig = TB_DICOM_CONFIG) -> None:
    """Run all configured checks (fail-fast); raises DicomValidationError."""
    _validate_modality(ds, config)
    _validate_photometric(ds, config)
    _validate_bits(ds, config)
    _validate_planar(ds, config)


def validate_dicom_only(path: str | Path, config: DicomInputConfig = TB_DICOM_CONFIG) -> dict[str, Any]:
    """Lightweight validation without full pixel processing.

    Used to check capability without loading pixels. Returns a dict with
    ``valid`` bool plus machine-readable ``reason_code`` and UI-safe
    ``user_reason`` on failure.
    """
    try:
        ds = pydicom.dcmread(Path(path), stop_before_pixels=True, force=True)
        validate_dataset(ds, config)
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
