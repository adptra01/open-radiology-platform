"""GET /capabilities — task-level discovery (model-agnostic, no model loading)."""

from __future__ import annotations

from fastapi import APIRouter

from ai_gateway.core.registry import list_capabilities

router = APIRouter()


def _settings():
    from orp_ai.config import Settings  # legacy config reuse

    return Settings.from_env()


@router.get("/capabilities")
def capabilities_endpoint():
    """What AI is available for a study? — never a hardcoded task name.

    The RIS renders Run buttons from this list. Availability resolves WITHOUT
    loading models, so disabled states render without triggering inference.
    """
    tasks = list_capabilities(_settings())
    return {"tasks": tasks, "count": len(tasks)}
