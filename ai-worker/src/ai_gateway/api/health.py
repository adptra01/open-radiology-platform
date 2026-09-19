"""GET /health — gateway + model status (lightweight where possible)."""

from __future__ import annotations

from fastapi import APIRouter

from ai_gateway.core.registry import get_adapter

router = APIRouter()


def _settings():
    from orp_ai.config import Settings  # legacy config reuse (xrv path untouched)

    return Settings.from_env()


@router.get("/health")
def health():
    from orp_ai.inference import predict as _  # noqa: F401  (keep xrv import lazy)
    from orp_ai.model import load_model, supported_models

    settings = _settings()
    model = load_model(settings.model_name, settings)
    tb = get_adapter("tb-screening")
    return {
        "status": "ok",
        "model": settings.model_name,
        "device": settings.device,
        "pathologies": list(model.pathologies),
        "available_models": supported_models(),
        "tb": tb.status(settings) if tb is not None else {"available": False},
        "gateway": "ai_gateway/0.2.0",
    }
