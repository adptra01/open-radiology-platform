"""Task registry + run dispatcher — the gateway's routing core.

    RIS -> GET /capabilities      registry.list_capabilities()
    RIS -> POST /infer/{task}     dispatcher.run_task(task_id, file, ...)
                                   -> adapter.validate/preprocess/predict
                                   -> adapter.postprocess -> locked envelope

Adding a task = implement the adapter interface + ``register()``.
No if/elif chains, no server changes.
"""

from __future__ import annotations

import logging
import time
from pathlib import Path
from typing import Any, Protocol

from ai_gateway.core.envelope import failed_envelope
from ai_gateway.core.exceptions import UnknownTaskError
from ai_gateway.dicom.validator import DicomValidationError, user_reason
from ai_gateway.tasks.tb.adapter import TASK_ID as TB_TASK_ID
from ai_gateway.tasks.tb.adapter import TBScreeningAdapter

log = logging.getLogger(__name__)


class AiAdapter(Protocol):
    """Minimal contract every task adapter must satisfy."""

    task_id: str
    name: str
    modalities: tuple[str, ...]
    body_regions: tuple[str, ...]
    input_type: str
    model_id: str
    model_version: str

    def capability(self) -> dict[str, Any]: ...
    def status(self, settings: Any | None = None) -> dict[str, Any]: ...
    def validate_input(self, file_path: str | Path) -> dict[str, Any]: ...
    def predict(self, file_path: str | Path, settings: Any | None = None, device: str = "cpu") -> dict[str, Any]: ...
    def postprocess(self, payload: dict[str, Any], *, input_filename: str | None,
                    processing_ms: int, preprocessing: dict[str, Any] | None = None) -> dict[str, Any]: ...
    def task_ref(self) -> dict[str, Any]: ...
    def model_ref(self) -> dict[str, Any]: ...


_registry: dict[str, AiAdapter] = {}


def register(adapter: AiAdapter) -> AiAdapter:
    """Register an adapter by ``task_id`` (idempotent, last-writer-wins)."""
    _registry[adapter.task_id] = adapter
    return adapter


def _default_adapters() -> list[AiAdapter]:
    return [TBScreeningAdapter()]


def _ensure_defaults() -> None:
    for adapter in _default_adapters():
        if adapter.task_id not in _registry:
            _registry[adapter.task_id] = adapter


def get_adapter(task_id: str) -> AiAdapter | None:
    _ensure_defaults()
    return _registry.get(task_id)


def list_capabilities(settings: Any | None = None) -> list[dict[str, Any]]:
    """Lightweight capability discovery — NO model loading."""
    _ensure_defaults()
    return [adapter.capability() if hasattr(adapter, "capability") else {}
            for adapter in _registry.values()]


def _task_model_refs(adapter: AiAdapter) -> tuple[dict[str, Any], dict[str, Any]]:
    return adapter.task_ref(), adapter.model_ref()


def run_task(
    task_id: str,
    file_path: str | Path,
    settings: Any | None = None,
    *,
    input_filename: str | None = None,
) -> dict[str, Any]:
    """Dispatch a file to the named task and return the locked envelope.

    Unknown task -> raises ``UnknownTaskError`` (API turns it into 404).
    INVALID input / missing weights / inference errors -> ``status: failed``
    envelope (never raises, never a fake prediction).
    """
    adapter = get_adapter(task_id)
    if adapter is None:
        raise UnknownTaskError(task_id)
    task_ref, model_ref = _task_model_refs(adapter)
    start = time.perf_counter()

    def _ms() -> int:
        return int((time.perf_counter() - start) * 1000)

    try:
        payload = adapter.predict(file_path, settings)
    except DicomValidationError as exc:
        log.warning("AI NOT RUN for task %s: [%s] %s", task_id, exc.code, exc)
        return failed_envelope(
            task=task_ref, model=model_ref, reason_code=exc.code,
            user_message=user_reason(exc.code),
            technical_note=f"AI NOT RUN — DICOM validation failed: {exc}",
            input_filename=input_filename, processing_ms=_ms(),
        )
    except (ValueError, OSError) as exc:  # unreadable weights / files
        log.warning("AI NOT RUN for task %s (io/model): %s", task_id, exc)
        return failed_envelope(
            task=task_ref, model=model_ref, reason_code="inference_error",
            user_message="This AI task could not process the input.",
            technical_note=f"AI NOT RUN — {exc}",
            input_filename=input_filename, processing_ms=_ms(),
        )
    return adapter.postprocess(payload, input_filename=input_filename, processing_ms=_ms())


__all__ = [
    "AiAdapter",
    "TB_TASK_ID",
    "TBScreeningAdapter",
    "register",
    "get_adapter",
    "list_capabilities",
    "run_task",
]
