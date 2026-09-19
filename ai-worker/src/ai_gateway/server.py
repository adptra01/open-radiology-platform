"""AI Gateway FastAPI application — mounts api/ routers.

Cutover: point uvicorn/Docker CMD at ``ai_gateway.server:app``.
The legacy ``orp_ai.server:app`` stays untouched until then.
"""

from __future__ import annotations

from fastapi import FastAPI

from ai_gateway.api import capabilities, health, runs


def create_app() -> FastAPI:
    app = FastAPI(
        title="ORP AI Gateway",
        version="0.2.0",
        description="Model-agnostic DICOM AI gateway (TB Screening as the only live task).",
    )
    app.include_router(health.router)
    app.include_router(capabilities.router)
    app.include_router(runs.router)
    return app


app = create_app()
