"""TB Screening adapter — the only real AI implementation.

Implements the gateway adapter interface::

    task_id / name / modalities / body_regions / input_type
    model_id / model_version / threshold / calibration_status
    capability()   -> immutable metadata for the RIS
    status()       -> lightweight availability (NO model loading)
    validate_input(path) -> raises DicomValidationError w/ stable code
    preprocess(path)     -> 8-bit L PIL (DICOM reader / PIL for PNG-JPG)
    predict(path)        -> raw payload dict (raises on invalid/unreadable)
    postprocess(...)     -> locked result envelope (classification)

Triage/screening, NOT diagnosis — clinical decisions stay with the
radiologist. Threshold 0.50 is EXPLICITLY UNCALIBRATED until Phase 3
evaluation sets a calibrated value (see ``calibration.status``).
"""

from __future__ import annotations

import logging
import os
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from PIL import Image

from ai_gateway.core import envelope as env
from ai_gateway.core.models import ModelRecord, get_model
from ai_gateway.dicom.reader import read_dicom_as_pil
from ai_gateway.dicom.validator import (
    TB_DICOM_CONFIG,
    DicomValidationError,
    user_reason,
    validate_dicom_only,
)
from ai_gateway.tasks.tb import inference as tb_inference
from ai_gateway.tasks.tb.model import MODEL_ID, MODEL_VERSION, clear_cache

log = logging.getLogger(__name__)

TASK_ID = "tb-screening"
TASK_NAME = "TB Screening"

# Classification threshold — UNCALIBRATED default (Phase 3 sets the real one).
# Env override exists for experiments; production default stays 0.50.
TB_THRESHOLD = float(os.environ.get("ORP_AI_TB_THRESHOLD", "0.50"))
CALIBRATION_STATUS = "uncalibrated"

WEIGHTS_MISSING_REASON = "TB screening is not available — model weights are not installed."
WEIGHTS_MISSING_NOTE = (
    "Fine-tune training is currently skipped (Colab unavailable). When a GPU is "
    "available, use ai-worker/notebooks/tb_finetune_kaggle.ipynb (needs Internet ON) "
    "or tb_finetune_colab.ipynb, and place the artifact as "
    "ai_gateway/models/tb-densenet121/1.0/model.pt "
    "(legacy fallback: ai-worker/weights/tb_densenet121.pt)."
)


def _resolve_record(tb_weights_env: str | None) -> ModelRecord | None:
    return get_model(MODEL_ID, MODEL_VERSION, TASK_ID, env_override=tb_weights_env)


@dataclass
class TBScreeningAdapter:
    """Adapter wiring the TB model package into the gateway dispatcher."""

    task_id: str = TASK_ID
    name: str = TASK_NAME
    modalities: tuple[str, ...] = ("CR", "DX")
    body_regions: tuple[str, ...] = ("CHEST",)
    input_type: str = "2D_CXR"
    model_id: str = MODEL_ID
    model_version: str = MODEL_VERSION
    threshold: float = TB_THRESHOLD
    calibration_status: str = CALIBRATION_STATUS
    tb_weights_env: str | None = None

    def task_ref(self) -> dict[str, Any]:
        return {"id": self.task_id, "name": self.name}

    def model_ref(self) -> dict[str, Any]:
        return {"id": self.model_id, "version": self.model_version}

    def capability(self) -> dict[str, Any]:
        st = self.status()
        entry: dict[str, Any] = {
            "task_id": self.task_id,
            "name": self.name,
            "modalities": list(self.modalities),
            "body_regions": list(self.body_regions),
            "input_type": self.input_type,
            "model": self.model_ref(),
            "available": bool(st.get("available", False)),
        }
        if not entry["available"]:
            entry["note"] = st.get("note")
        return entry

    def status(self, settings: Any | None = None) -> dict[str, Any]:
        """Lightweight availability — checks weights presence, loads nothing."""
        env_override = self.tb_weights_env or (
            settings.tb_weights if settings is not None and getattr(settings, "tb_weights", None) else None
        )
        record = _resolve_record(env_override)
        if record is None:
            return {"available": False, "note": "weights not found — TB detection disabled"}
        return {"available": True, "weights": str(record.weights_path)}

    def validate_input(self, file_path: str | Path) -> dict[str, Any]:
        """Validate without inference. PNG/JPG pass through (pixel check at predict)."""
        path = Path(file_path)
        if path.suffix.lower() == ".dcm":
            return validate_dicom_only(path, TB_DICOM_CONFIG)
        if not path.exists():
            return {"valid": False, "reason_code": "unreadable_dicom",
                    "user_reason": user_reason("unreadable_dicom")}
        return {"valid": True}

    def preprocess(self, file_path: str | Path) -> Image.Image:
        """DICOM -> 8-bit L PIL via the gateway reader (raises w/ code)."""
        return tb_inference.read_as_grayscale_pil(file_path)

    def predict(
        self,
        file_path: str | Path,
        settings: Any | None = None,
        device: str = "cpu",
    ) -> dict[str, Any]:
        """Raw payload (no envelope). Raises DicomValidationError / ValueError.

        Order is deliberate: input validation FIRST (INVALID -> AI NOT RUN
        with the input reason even when weights are missing), weights second.
        """
        path = Path(file_path)
        if path.suffix.lower() == ".dcm":
            verdict = validate_dicom_only(path, TB_DICOM_CONFIG)
            if not verdict["valid"]:
                raise DicomValidationError(
                    str(verdict["reason"]), code=str(verdict["reason_code"])
                )
        env_override = self.tb_weights_env or (
            settings.tb_weights if settings is not None and getattr(settings, "tb_weights", None) else None
        )
        record = _resolve_record(env_override)
        if record is None:
            return {"available": False, "reason_code": "weights_missing"}
        eff_device = getattr(settings, "device", device) if settings is not None else device
        return tb_inference.predict_file(file_path, record, device=eff_device)

    def postprocess(
        self,
        payload: dict[str, Any],
        *,
        input_filename: str | None,
        processing_ms: int,
        preprocessing: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        """Wrap a raw payload into the locked classification envelope."""
        if payload.get("available"):
            score = float(payload["probability"])
            label = "positive" if score >= self.threshold else "negative"
            return env.classification_envelope(
                task=self.task_ref(),
                model=self.model_ref(),
                label=label,
                score=score,
                threshold=self.threshold,
                calibration_status=self.calibration_status,
                input_filename=input_filename,
                processing_ms=processing_ms,
                preprocessing=preprocessing
                or {"transform": "Grayscale(3)->Resize224->ToTensor->Normalize(ImageNet)",
                    "source": "models/tb-densenet121/1.0/preprocessing.json"},
            )
        code = str(payload.get("reason_code", "inference_error"))
        if code == "weights_missing":
            return env.failed_envelope(
                task=self.task_ref(), model=self.model_ref(), reason_code=code,
                user_message=WEIGHTS_MISSING_REASON, technical_note=WEIGHTS_MISSING_NOTE,
                input_filename=input_filename, processing_ms=processing_ms,
            )
        # Validation / inference failures carry their own context when present.
        return env.failed_envelope(
            task=self.task_ref(), model=self.model_ref(), reason_code=code,
            user_message=str(payload.get("reason", user_reason(code))),
            technical_note=str(payload.get("note", f"AI NOT RUN [{code}]")),
            input_filename=input_filename, processing_ms=processing_ms,
        )


__all__ = [
    "TASK_ID",
    "TBScreeningAdapter",
    "CALIBRATION_STATUS",
    "TB_THRESHOLD",
    "clear_cache",
]
