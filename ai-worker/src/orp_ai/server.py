"""FastAPI server exposing the CPU inference worker over HTTP.

Endpoints:
  GET  /health      — model & device status
  GET  /capabilities — task-level AI capabilities (registry, model-agnostic)
  POST /infer       — multipart image/DICOM upload -> structured report (xrv)
  POST /infer/tb    — alias for POST /infer/tb-screening (back-compat)
  POST /infer/{task} — generic task dispatch via the adapter registry
"""

from __future__ import annotations

import contextlib
import logging
import tempfile
from pathlib import Path

from fastapi import FastAPI, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from .config import DEFAULT_SETTINGS, Settings
from .adapters import capabilities, get_adapter, run_task
from .inference import findings_above_threshold, predict
from .model import clear_cache, load_model, supported_models
from .tb import tb_status

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


@app.get("/capabilities")
def capabilities_endpoint():
    """Task-level AI capabilities — generic adapter registry (M8).

    RIS memakai ini utk menjawab "AI apa yg tersedia utk study ini?",
    BUKAN hardcode ``tb``. Registry idempotent; availability di-resolve
    TANPA memuat model (``Adapter.status()`` = lightweight), jadi RIS bisa
    render disabled state tanpa memicu inferensi.

    Response: ``{"tasks": [...], "count": N}```
    """
    settings = _settings()

    return {"tasks": capabilities(settings), "count": len(capabilities(settings))}


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


async def _load_upload_bytes(file: UploadFile, settings: Settings) -> tuple[bytes, str]:
    """Validate + read an upload (shared by all /infer/* endpoints)."""
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
    return data, suffix


@contextlib.contextmanager
def _staged_upload(data: bytes, suffix: str, prefix: str):
    """Stage upload bytes as a real file so adapters can inspect headers."""
    with tempfile.NamedTemporaryFile(suffix=suffix, prefix=prefix, delete=True) as tmp:
        tmp.write(data)
        tmp.flush()
        yield tmp.name


@app.post("/infer/tb")
async def infer_tb(file: UploadFile):
    """TB screening (triase, bukan diagnostik) — alias via adapter registry.

    Dispatches through ``run_task("tb-screening", ...)`` so TB stays a
    *capability plugin*, not server core. Response always contains
    ``available`` (plus ``reason_code``/``reason`` when False).
    """
    settings = _settings()
    data, suffix = await _load_upload_bytes(file, settings)
    with _staged_upload(data, suffix, "orp-tb-") as tmp:
        result = run_task("tb-screening", tmp, settings)
    return JSONResponse(result)


@app.post("/infer/{task_id}")
async def infer_task(task_id: str, file: UploadFile):
    """Generic task dispatch — the RIS calls capabilities, not hardcoded paths.

    ``GET /capabilities`` tells the RIS which ``task_id`` values exist
    (today: ``tb-screening``); unknown tasks get a clean 404, never a 500.
    Declared AFTER /infer/tb so the explicit alias keeps precedence.
    """
    settings = _settings()
    if get_adapter(task_id) is None:
        raise HTTPException(
            status_code=404,
            detail=f"Unknown AI task '{task_id}'. See GET /capabilities.",
        )
    data, suffix = await _load_upload_bytes(file, settings)
    with _staged_upload(data, suffix, f"orp-{task_id}-") as tmp:
        result = run_task(task_id, tmp, settings)
    return JSONResponse(result)