"""TB DenseNet121 model package — architecture + versioned checkpoint load.

Architecture mirrors the fine-tune notebook (cell 6): DenseNet121
(ImageNet stem) with the classifier head replaced by a 1-output logit
(BCEWithLogits). Checkpoint layout::

    {"state_dict": {...}, "pathologies": ["Normal","Tuberculosis"],
     "val_auc": float, "dataset": str, "note": str}
"""

from __future__ import annotations

import logging
import threading
from pathlib import Path
from typing import Any

import torch
from torch import nn
from torchvision import models

from ai_gateway.core.models import ModelRecord

log = logging.getLogger(__name__)

MODEL_ID = "tb-densenet121"
MODEL_VERSION = "1.0"

_LOCK = threading.Lock()
_LOADED: dict[str, tuple[nn.Module, dict[str, Any]]] = {}


def build_model() -> nn.Module:
    """Same architecture as training: DenseNet121 + 1-output logit head."""
    m = models.densenet121(weights=None)
    m.classifier = nn.Linear(m.classifier.in_features, 1)
    return m


def load_model(record: ModelRecord) -> tuple[nn.Module, dict[str, Any]]:
    """Load (cached) the versioned checkpoint. Raises ValueError if unreadable."""
    key = str(record.weights_path)
    with _LOCK:
        if key in _LOADED:
            return _LOADED[key]
        try:
            ckpt = torch.load(record.weights_path, map_location="cpu", weights_only=True)
            state = ckpt.get("state_dict") or ckpt
            model = build_model()
            model.load_state_dict(state)
            model.eval()
        except Exception as exc:  # noqa: BLE001 — caller maps to INFERENCE_ERROR
            log.exception("Failed to load TB weights from %s", record.weights_path)
            raise ValueError(f"TB weights unreadable at {record.weights_path}: {exc}") from exc
        meta: dict[str, Any] = {
            "val_auc": ckpt.get("val_auc") if isinstance(ckpt, dict) else None,
            "dataset": ckpt.get("dataset") if isinstance(ckpt, dict) else None,
            "note": ckpt.get("note") if isinstance(ckpt, dict) else None,
        }
        _LOADED[key] = (model, meta)
        return _LOADED[key]


def clear_cache() -> None:
    """Drop cached TB models (tests)."""
    with _LOCK:
        _LOADED.clear()


def weights_display_path(record: ModelRecord) -> str:
    return str(record.weights_path)


def default_weights_hint() -> Path:
    """Transitional hint used in WEIGHTS_MISSING notes (legacy flat dir)."""
    from ai_gateway.core.models import LEGACY_WEIGHTS_DIR

    return LEGACY_WEIGHTS_DIR / "tb_densenet121.pt"
