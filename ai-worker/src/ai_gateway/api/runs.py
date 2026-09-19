"""Run endpoints — generic task dispatch + compat aliases + legacy xrv path."""

from __future__ import annotations

import contextlib
import tempfile
from pathlib import Path

from fastapi import APIRouter, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from ai_gateway.core.exceptions import UnknownTaskError
from ai_gateway.core.registry import get_adapter, run_task

router = APIRouter()

ALLOWED_SUFFIXES = {".png", ".jpg", ".jpeg", ".dcm"}


def _settings():
    from orp_ai.config import Settings  # legacy config reuse (xrv path untouched)

    return Settings.from_env()


async def _load_upload_bytes(file: UploadFile, settings) -> tuple[bytes, str]:
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


@router.post("/infer")
async def infer(file: UploadFile):
    """Legacy xrv 18-pathology endpoint (unchanged behavior, gateway-hosted)."""
    from ai_gateway.api.health import _settings as _s  # reuse
    from orp_ai.inference import findings_above_threshold, predict

    settings = _s()
    data, suffix = await _load_upload_bytes(file, settings)
    with _staged_upload(data, suffix, "orp-ai-") as tmp:
        try:
            report = predict(tmp, settings)
        except Exception as exc:  # noqa: BLE001 — surface as HTTP error
            raise HTTPException(status_code=422, detail=f"Inference failed: {exc}") from exc
    report.pop("all", None)
    report["findings"] = findings_above_threshold(report)
    return JSONResponse(report)


@router.post("/infer/tb")
async def infer_tb(file: UploadFile):
    """Compat alias — dispatches through the registry (TB stays a plugin)."""
    settings = _settings()
    data, suffix = await _load_upload_bytes(file, settings)
    with _staged_upload(data, suffix, "orp-tb-") as tmp:
        result = run_task("tb-screening", tmp, settings, input_filename=file.filename)
    return JSONResponse(result)


@router.post("/infer/{task_id}")
async def infer_task(task_id: str, file: UploadFile):
    """Generic task dispatch. Unknown tasks get a clean 404, never a 500."""
    settings = _settings()
    if get_adapter(task_id) is None:
        raise HTTPException(
            status_code=404,
            detail=f"Unknown AI task '{task_id}'. See GET /capabilities.",
        )
    data, suffix = await _load_upload_bytes(file, settings)
    with _staged_upload(data, suffix, f"orp-{task_id}-") as tmp:
        try:
            result = run_task(task_id, tmp, settings, input_filename=file.filename)
        except UnknownTaskError as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
    return JSONResponse(result)
