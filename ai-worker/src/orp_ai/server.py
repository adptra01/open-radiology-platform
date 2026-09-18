"""FastAPI server exposing the CPU inference worker over HTTP.

Endpoints:
  GET  /health   — model & device status
  POST /infer    — multipart image/DICOM upload -> structured report
"""

from __future__ import annotations

import logging
import tempfile
from pathlib import Path

from fastapi import FastAPI, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from .config import DEFAULT_SETTINGS, Settings
from .inference import findings_above_threshold, predict
from .model import clear_cache, load_model, supported_models
from .tb import predict_tb, tb_status

log = logging.getLogger(__name__)

ALLOWED_SUFFIXES = {".png", ".jpg", ".jpeg", ".dcm"}

app = FastAPI(
    title="ORP AI Worker",
    version="0.1.0",
    description="CPU-only chest X-ray inference (TorchXRayVision) for ORP RIS/PACS.",
)


def _settings() -> Settings:
    return Settings.from_env()


@app.get("/health")
def health():
    settings = _settings()
    model = load_model(settings.model_name, settings)
    return {
        "status": "ok",
        "model": settings.model_name,
        "device": settings.device,
        "pathologies": list(model.pathologies),
        "available_models": supported_models(),
        "tb": tb_status(settings),
    }


@app.post("/infer")
async def infer(file: UploadFile):
    settings = _settings()
    suffix = Path(file.filename or "").suffix.lower()
    if suffix not in ALLOWED_SUFFIXES:
        raise HTTPException(
            status_code=415,
            detail=f"Unsupported file type '{suffix}'. Allowed: {sorted(ALLOWED_SUFFIXES)}",
        )

    data = await file.read()
    if len(data) > settings.max_upload_mb * 1024 * 1024:
        raise HTTPException(
            status_code=413,
            detail=f"File exceeds {settings.max_upload_mb} MB limit",
        )

    # Stream to a real file so xrv can inspect DICOM headers / image metadata.
    with tempfile.NamedTemporaryFile(
        suffix=suffix, prefix="orp-ai-", delete=True
    ) as tmp:
        tmp.write(data)
        tmp.flush()
        try:
            report = predict(tmp.name, settings)
        except Exception as exc:  # noqa: BLE001 — surface as HTTP error
            log.exception("Inference failed")
            raise HTTPException(status_code=422, detail=f"Inference failed: {exc}") from exc

    report.pop("all", None)  # keep the response lean; /health exposes the label list
    report["findings"] = findings_above_threshold(report)
    return JSONResponse(report)


@app.post("/infer/tb")
async def infer_tb(file: UploadFile):
    """TB screening (triase, bukan diagnostik) — graceful tanpa weights.

    Response selalu berisi ``available``:
      * True  -> probability = P(TB) di [0,1] (bobot fine-tune Montgomery+Shenzhen)
      * False -> penjelasan di ``note`` (jalankan notebook Colab dulu)
    """
    settings = _settings()
    suffix = Path(file.filename or "").suffix.lower()
    if suffix not in ALLOWED_SUFFIXES:
        raise HTTPException(
            status_code=415,
            detail=f"Unsupported file type '{suffix}'. Allowed: {sorted(ALLOWED_SUFFIXES)}",
        )

    data = await file.read()
    if len(data) > settings.max_upload_mb * 1024 * 1024:
        raise HTTPException(
            status_code=413,
            detail=f"File exceeds {settings.max_upload_mb} MB limit",
        )

    with tempfile.NamedTemporaryFile(
        suffix=suffix, prefix="orp-tb-", delete=True
    ) as tmp:
        tmp.write(data)
        tmp.flush()
        result = predict_tb(tmp.name, settings)

    return JSONResponse(result)