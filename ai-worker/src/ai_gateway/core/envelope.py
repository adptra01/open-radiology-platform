"""Generic AI result envelope (locked API surface for Laravel/React).

Success (classification task)::

    {
      "task": {"id": "tb-screening", "name": "TB Screening"},
      "model": {"id": "tb-densenet121", "version": "1.0"},
      "status": "completed",
      "input": {"filename": "study.dcm"},
      "result": {"type": "classification",
                 "classification": {"label": "positive", "score": 0.87,
                                    "threshold": 0.50},
                 "calibration": {"status": "uncalibrated"}},
      "metadata": {"processing_time_ms": 842, "disclaimer": "...",
                   "preprocessing": {...}}
    }

Failure (AI NOT RUN — never a fake prediction)::

    {
      "task": {...}, "model": {...}, "status": "failed",
      "error": {"code": "UNSUPPORTED_MODALITY", "message": "<user reason>"},
      "metadata": {"processing_time_ms": 3, "note": "<technical detail>"}
    }

Future result types (``detection``, ``segmentation``) plug into ``result``
without breaking the envelope.
"""

from __future__ import annotations

from typing import Any

DISCLAIMER = (
    "AI screening support only — not a diagnosis. "
    "Clinical interpretation is required. / "
    "Hasil AI hanya bantuan skrining, bukan diagnosis. "
    "Interpretasi klinis/radiologis tetap diperlukan."
)

# Stable mapping: lowercase reason_code (pipeline) -> UPPER error code (API).
# Locked by test — adding a code requires an explicit UI/contract update.
ERROR_CODE_MAP: dict[str, str] = {
    "unsupported_modality": "UNSUPPORTED_MODALITY",
    "unsupported_photometric": "UNSUPPORTED_PHOTOMETRIC",
    "unsupported_bits": "UNSUPPORTED_BITS",
    "inconsistent_bits": "INCONSISTENT_BITS",
    "unsupported_planar": "UNSUPPORTED_PLANAR",
    "missing_pixel_data": "MISSING_PIXEL_DATA",
    "unreadable_dicom": "UNREADABLE_DICOM",
    "invalid_dicom": "INVALID_DICOM",
    "weights_missing": "WEIGHTS_MISSING",
    "inference_error": "INFERENCE_ERROR",
}


def error_code(reason_code: str) -> str:
    """Map a pipeline reason_code to the stable API error code."""
    return ERROR_CODE_MAP.get(reason_code, "INVALID_DICOM")


def classification_envelope(
    *,
    task: dict[str, Any],
    model: dict[str, Any],
    label: str,
    score: float,
    threshold: float,
    calibration_status: str,
    input_filename: str | None,
    processing_ms: int,
    preprocessing: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Build the success envelope for a classification task."""
    return {
        "task": task,
        "model": model,
        "status": "completed",
        "input": {"filename": input_filename},
        "result": {
            "type": "classification",
            "classification": {
                "label": label,
                "score": round(float(score), 4),
                "threshold": threshold,
            },
            "calibration": {"status": calibration_status},
        },
        "metadata": {
            "processing_time_ms": processing_ms,
            "disclaimer": DISCLAIMER,
            "preprocessing": preprocessing or {},
        },
    }


def failed_envelope(
    *,
    task: dict[str, Any],
    model: dict[str, Any],
    reason_code: str,
    user_message: str,
    technical_note: str,
    input_filename: str | None = None,
    processing_ms: int = 0,
) -> dict[str, Any]:
    """Build the AI-NOT-RUN envelope (status failed, never a prediction)."""
    return {
        "task": task,
        "model": model,
        "status": "failed",
        "input": {"filename": input_filename},
        "error": {"code": error_code(reason_code), "message": user_message},
        "metadata": {
            "processing_time_ms": processing_ms,
            "disclaimer": DISCLAIMER,
            "reason_code": reason_code,
            "note": technical_note,
        },
    }
