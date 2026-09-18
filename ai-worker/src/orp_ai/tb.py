"""Tuberculosis detector — fine-tuned DenseNet121 trained in Google Colab.

The training pipeline lives in ``notebooks/tb_finetune_colab.ipynb`` (public
Montgomery + Shenzhen datasets only; see the notebook header for privacy
rules). This module mirrors that pipeline exactly so inference sees the same
image distribution the model was trained on:

    Grayscale(3) -> Resize((224,224)) -> ToTensor -> Normalize(ImageNet stats)

Note: NO CenterCrop here (the Colab training transform does not crop) and NO
torchxrayvision preprocessing (that pipeline belongs to the 18-pathology
model). Mixing preprocessors would skew the TB probability.

Graceful degradation:
    * Weights file missing  -> ``available=False``, no model load, no crash.
    * Unreadable/corrupt    -> logged, ``available=False``.
"""

from __future__ import annotations

import logging
import threading
from pathlib import Path
from typing import Any

import numpy as np
import torch
from PIL import Image
from torch import nn
from torchvision import models, transforms

from .config import Settings

log = logging.getLogger(__name__)

TB_CHECKPOINT_NAME = "tb_densenet121.pt"

# ai-worker/src/orp_ai/tb.py -> parents[2] == <repo>/ai-worker
DEFAULT_WEIGHTS_PATH = Path(__file__).resolve().parents[2] / "weights" / TB_CHECKPOINT_NAME

# Exactly the transform used in the Colab notebook (cell 5).
_TB_TRANSFORM = transforms.Compose(
    [
        transforms.Grayscale(num_output_channels=3),
        transforms.Resize((224, 224)),
        transforms.ToTensor(),
        transforms.Normalize(mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225]),
    ]
)

_LOCK = threading.Lock()
_LOADED: dict[str, tuple[nn.Module, dict[str, Any]]] = {}


def _weights_path(settings: Settings | None = None) -> Path:
    settings = settings or Settings.from_env()
    if settings.tb_weights:
        return Path(settings.tb_weights)
    return DEFAULT_WEIGHTS_PATH


def _read_as_grayscale_pil(img_path: str | Path) -> Image.Image:
    """Open PNG/JPG via PIL, or DICOM via pydicom, as an 8-bit L-mode PIL image."""
    path = Path(img_path)
    if path.suffix.lower() == ".dcm":
        import pydicom

        ds = pydicom.dcmread(path)
        arr = np.asarray(ds.pixel_array, dtype=np.float32)
        if ds.PhotometricInterpretation == "MONOCHROME1":  # invert (white=bone)
            arr = arr.max() - arr
        lo, hi = np.percentile(arr, (1, 99))  # simple windowing like the datasets
        arr = np.clip((arr - lo) / max(hi - lo, 1e-6), 0, 1) * 255.0
        return Image.fromarray(arr.astype(np.uint8), mode="L")
    return Image.open(path).convert("L")


def _build_model() -> nn.Module:
    """Same architecture as the Colab notebook (cell 6): DenseNet121 ImageNet
    with the classifier head replaced by a 1-output logit."""
    m = models.densenet121(weights=None)
    m.classifier = nn.Linear(m.classifier.in_features, 1)  # BCEWithLogits, logit
    return m


def load_tb_model(settings: Settings | None = None) -> tuple[nn.Module, dict[str, Any]] | None:
    """Load the fine-tuned TB checkpoint (cached). Returns (model, metadata) or
    None if the weights are not available / cannot be loaded.

    Checkpoint layout (saved by the Colab notebook):
        {"state_dict": {...}, "pathologies": ["Normal","Tuberculosis"],
         "val_auc": float, "dataset": str, "note": str}
    """
    settings = settings or Settings.from_env()
    path = _weights_path(settings)
    with _LOCK:
        if str(path) in _LOADED:
            return _LOADED[str(path)]
        if not path.exists():
            log.info("TB weights not found at %s — TB detection disabled", path)
            return None
        try:
            ckpt = torch.load(path, map_location="cpu", weights_only=True)
            state = ckpt.get("state_dict") or ckpt
            model = _build_model()
            model.load_state_dict(state)
            model.eval()
        except Exception as exc:  # noqa: BLE001 — degrade, don't crash the worker
            log.exception("Failed to load TB weights from %s", path)
            raise ValueError(f"TB weights unreadable at {path}: {exc}") from exc
        meta: dict[str, Any] = {
            "available": True,
            "weights": str(path),
            "val_auc": ckpt.get("val_auc") if isinstance(ckpt, dict) else None,
            "dataset": ckpt.get("dataset") if isinstance(ckpt, dict) else None,
            "note": ckpt.get("note") if isinstance(ckpt, dict) else None,
        }
        _LOADED[str(path)] = (model, meta)
        return _LOADED[str(path)]


def clear_cache() -> None:
    """Drop the cached TB model (called alongside orp_ai.model.clear_cache)."""
    with _LOCK:
        _LOADED.clear()


def predict_tb(img_path: str | Path, settings: Settings | None = None) -> dict[str, Any]:
    """Return the TB screening probability for one image.

    Result always contains ``available`` so callers can branch safely:
        * available=True  -> ``probability`` = P(TB) in [0,1], ``logit`` raw
        * available=False -> explanation in ``note``, no probability
    """
    settings = settings or Settings.from_env()
    loaded = load_tb_model(settings)
    if loaded is None:
        return {
            "available": False,
            "probability": None,
            "note": (
                f"TB weights not found ({DEFAULT_WEIGHTS_PATH}) — run the Colab "
                "fine-tune notebook (ai-worker/notebooks/tb_finetune_colab.ipynb) "
                "and place tb_densenet121.pt in ai-worker/weights/."
            ),
        }
    model, meta = loaded
    try:
        pil_img = _read_as_grayscale_pil(img_path)
        tensor = _TB_TRANSFORM(pil_img).unsqueeze(0).to(settings.device)
        with torch.no_grad():
            logit = model(tensor).squeeze(1).item()
    except Exception as exc:  # noqa: BLE001
        log.exception("TB inference failed for %s", img_path)
        return {"available": False, "probability": None, "note": f"inference error: {exc}"}

    return {
        "available": True,
        "probability": round(float(torch.sigmoid(torch.tensor(logit))), 4),
        "logit": round(float(logit), 4),
        "val_auc": meta.get("val_auc"),
        "note": meta.get("note") or "alat skrining/triase — bukan alat diagnostik",
    }


def tb_status(settings: Settings | None = None) -> dict[str, Any]:
    """Lightweight status for /health without forcing a full inference."""
    settings = settings or Settings.from_env()
    loaded = load_tb_model(settings)
    if loaded is None:
        return {
            "available": False,
            "weights": str(_weights_path(settings)),
            "note": "weights not found — TB detection disabled",
        }
    _, meta = loaded
    return {"available": True, **{k: v for k, v in meta.items() if k != "available"}}