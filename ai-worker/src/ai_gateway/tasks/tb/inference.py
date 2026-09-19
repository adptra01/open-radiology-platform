"""TB inference entry — DICOM/PNG file -> raw payload dict (no envelope).

The dispatcher wraps the payload into the locked result envelope.
Returns ALWAYS a dict with ``available``::

    available=True   -> {probability, logit}
    available=False  -> {reason_code} (+ weights/note context)

DICOM validation failures surface as ``DicomValidationError`` with a stable
``code`` (mapped to API error codes by the dispatcher) — never silently.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

import torch
from PIL import Image

from ai_gateway.core.models import ModelRecord
from ai_gateway.dicom.reader import read_dicom_as_pil
from ai_gateway.dicom.validator import DicomValidationError
from ai_gateway.tasks.tb import preprocessing as tb_pre
from ai_gateway.tasks.tb.model import load_model

log = logging.getLogger(__name__)


def read_as_grayscale_pil(img_path: str | Path) -> Image.Image:
    """Open PNG/JPG via PIL, or DICOM via the gateway reader, as 8-bit L."""
    path = Path(img_path)
    if path.suffix.lower() == ".dcm":
        return read_dicom_as_pil(path)  # raises DicomValidationError w/ code
    return Image.open(path).convert("L")


def predict_file(
    img_path: str | Path,
    record: ModelRecord,
    device: str = "cpu",
) -> dict[str, Any]:
    """Run TB inference; may raise DicomValidationError / ValueError / OSError."""
    model, _meta = load_model(record)
    pil_img = read_as_grayscale_pil(img_path)
    tensor = tb_pre.pil_to_tensor(pil_img).to(device)
    with torch.no_grad():
        logit = model(tensor).squeeze(1).item()
    probability = float(torch.sigmoid(torch.tensor(logit)))
    return {"available": True, "probability": round(probability, 4), "logit": round(float(logit), 4)}
