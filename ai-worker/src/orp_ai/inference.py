"""Single-image inference: load image/DICOM -> normalized tensor -> probabilities."""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

import torch
import torchxrayvision as xrv

from .config import Settings
from .model import load_model

log = logging.getLogger(__name__)

# Condition groups used for the "focused" summary in the report.
_TB_KEYWORDS = ("tubercul", "tbc", "tb")
_LUNG_KEYWORDS = (
    "pneumonia",
    "infiltration",
    "effusion",
    "atelectasis",
    "nodule",
    "mass",
    "opacit",
    "pleural",
    "emphysema",
    "fibrosis",
    "consolidation",
    "pulmonary",
)


def load_image_tensor(
    img_path: str | Path, device: str = "cpu", img_size: int = 224
) -> torch.Tensor:
    """Load PNG/JPG/DICOM and return a batched normalized tensor (1,1,H,W).

    torchxrayvision >= 1.5: ``load_image(fname)`` returns (1,H,W) already
    grayscale/normalized (DICOM auto-detected via the DICM magic bytes);
    ``XRayResizer`` then resizes to the model input size.
    """
    img = xrv.utils.load_image(str(img_path))  # (1, H, W) float32
    img = xrv.datasets.XRayResizer(img_size)(img)  # (1, img_size, img_size)
    return torch.from_numpy(img).unsqueeze(0).to(device)


def _matches(name: str, keywords: tuple[str, ...]) -> bool:
    lower = name.lower()
    return any(k in lower for k in keywords)


def _tb_report_compat(img_path: str | Path, settings: Settings | None = None) -> dict[str, Any]:
    """TB block for the legacy xrv report — via the gateway dispatcher.

    Returns the legacy shape ``{available, probability, note}`` so old
    consumers (RunAiInference, card) keep working. New code must use the
    gateway envelope (``POST /infer/tb-screening``) instead.
    """
    from ai_gateway.core.registry import run_task

    try:
        env = run_task("tb-screening", img_path, settings, input_filename=Path(img_path).name)
    except Exception as exc:  # noqa: BLE001 — never fail xrv inference for TB
        log.warning("TB compat block skipped for %s: %s", img_path, exc)
        return {"available": False, "probability": None, "note": f"TB unavailable: {exc}"}
    if env.get("status") == "completed":
        cls = env["result"]["classification"]
        return {
            "available": True,
            "probability": cls["score"],
            "label": cls["label"],
            "model": env.get("model"),
            "note": env["metadata"]["disclaimer"],
        }
    err = env.get("error", {})
    return {
        "available": False,
        "probability": None,
        "reason_code": env["metadata"].get("reason_code"),
        "reason": err.get("message"),
        "note": env["metadata"].get("note") or err.get("message"),
    }


def predict(img_path: str | Path, settings: Settings | None = None) -> dict[str, Any]:
    """Run inference on one image and return a structured report."""
    settings = settings or Settings.from_env()
    model = load_model(settings.model_name, settings)
    tensor = load_image_tensor(img_path, device=settings.device)

    with torch.no_grad():
        logits = model(tensor)
    probs = torch.sigmoid(logits).cpu().numpy()[0]

    pathologies = {
        name: round(float(p), 4) for name, p in zip(model.pathologies, probs)
    }
    ranked = sorted(pathologies.items(), key=lambda kv: kv[1], reverse=True)
    findings = [{"name": n, "probability": p} for n, p in ranked[: settings.top_k]]

    tb = {n: p for n, p in pathologies.items() if _matches(n, _TB_KEYWORDS)}
    lung = {
        n: p
        for n, p in pathologies.items()
        if _matches(n, _LUNG_KEYWORDS) and not _matches(n, _TB_KEYWORDS)
    }
    tb_report = _tb_report_compat(img_path, settings)  # gateway TB adapter, legacy shape

    return {
        "source": str(img_path),
        "model": settings.model_name,
        "device": settings.device,
        "threshold": settings.threshold,
        "findings": findings,
        "tb": tb_report,
        "focused": {
            "tuberculosis": tb,
            "lung_disease": lung,
        },
        "all": pathologies,
    }


def findings_above_threshold(
    report: dict[str, Any], threshold: float | None = None
) -> list[dict[str, Any]]:
    """Filter findings to those above the report threshold."""
    threshold = threshold if threshold is not None else report["threshold"]
    return [f for f in report["findings"] if f["probability"] >= threshold]