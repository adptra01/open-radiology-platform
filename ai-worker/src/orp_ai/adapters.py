"""Generic AI adapter registry — model-agnostic capability layer (M8).

Why this exists
---------------
The RIS asks "what AI is available for this study?" and receives a *capability
list* — not a hardcoded TB endpoint. When DenseNet121 is swapped for
EfficientNet, or a CT lung-nodule task is added, the RIS code does not change.
The registry simply reports the new capabilities.

    RIS                              ai-worker
     |                                   |
     |  GET /capabilities                |
     | ---------------------------------->  task list per modality/body region
     |                                    tb-screening: CR/DX chest
     |                                    lung-nodule:  CT chest (if added)
     |                                   |
     |  POST /infer/{task}  (generic)    |
     | ---------------------------------->  adapter.predict(path)
     |                                    |
     |  POST /infer/tb      (alias)      |
     | ---------------------------------->  same TB adapter

Contract
--------
Every adapter ``predict()`` returns a dict that ALWAYS has an ``available``
key:

    available=True   -> real payload (e.g. ``probability`` for screening)
    available=False  -> ``reason_code`` (machine-readable, stable — e.g.
                        ``unsupported_modality``, ``weights_missing``) +
                        ``reason`` (user-facing, safe for the RIS UI) +
                        ``note`` (technical detail, audit log only)

The RIS renders ``reason`` in the main UI ("Unable to process this study.
Reason: ...") and keeps ``note`` behind "View technical details".
``status()`` MUST be lightweight — it resolves capability WITHOUT loading the
model, so the RIS can render "unavailable" without triggering inference.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Protocol

from .config import Settings
from . import tb as tb_module

__all__ = [
    "AiAdapter",
    "AdapterCapability",
    "TbAdapter",
    "register",
    "capabilities",
    "get_adapter",
    "run_task",
]


class AiAdapter(Protocol):
    """Minimal contract every task adapter must satisfy."""

    task_id: str
    name: str
    modalities: tuple[str, ...]
    body_regions: tuple[str, ...]
    input_type: str

    def status(self, settings: Settings | None = None) -> dict[str, Any]: ...

    def predict(
        self,
        file_path: str | Path,
        settings: Settings | None = None,
    ) -> dict[str, Any]: ...


@dataclass(frozen=True)
class AdapterCapability:
    """Immutable metadata the RIS uses to decide what it can offer."""

    task_id: str
    name: str
    modalities: tuple[str, ...]
    body_regions: tuple[str, ...]
    input_type: str


@dataclass
class TbAdapter:
    """TB screening for CR/DX chest X-rays — wraps tb.py.

    The RIS sees only ``tb-screening``; it never needs to know the backing
    model (DenseNet121 fine-tune today). This is triage/screening, NOT
    diagnosis — clinical decisions stay with the radiologist.
    """

    task_id: str = "tb-screening"
    name: str = "TB Screening"
    modalities: tuple[str, ...] = ("CR", "DX")
    body_regions: tuple[str, ...] = ("CHEST",)
    input_type: str = "2D_CXR"

    def capability(self) -> AdapterCapability:
        return AdapterCapability(
            task_id=self.task_id,
            name=self.name,
            modalities=self.modalities,
            body_regions=self.body_regions,
            input_type=self.input_type,
        )

    def status(self, settings: Settings | None = None) -> dict[str, Any]:
        return tb_module.tb_status(settings)

    def predict(
        self,
        file_path: str | Path,
        settings: Settings | None = None,
    ) -> dict[str, Any]:
        return tb_module.predict_tb(file_path, settings)


# ---------------------------------------------------------------------------
# Registry
# ---------------------------------------------------------------------------

_registry: dict[str, AiAdapter] = {}


def register(adapter: AiAdapter) -> AiAdapter:
    """Register an adapter by ``task_id`` (idempotent, last-writer-wins)."""
    _registry[adapter.task_id] = adapter
    return adapter


def _default_adapters() -> list[AiAdapter]:
    return [TbAdapter()]


def _ensure_defaults() -> None:
    for adapter in _default_adapters():
        if adapter.task_id not in _registry:
            _registry[adapter.task_id] = adapter


def capabilities(settings: Settings | None = None) -> list[dict[str, Any]]:
    """Lightweight capability discovery — NO model loading.

    Returns one entry per registered task with availability resolved via
    ``status()`` so the RIS can render disabled/queued states immediately.
    """
    _ensure_defaults()
    out: list[dict[str, Any]] = []
    for adapter in _registry.values():
        cap = adapter.capability()
        st = adapter.status(settings)
        available = bool(st.get("available", False))
        entry = {
            "task_id": cap.task_id,
            "name": cap.name,
            "modalities": list(cap.modalities),
            "body_regions": list(cap.body_regions),
            "input_type": cap.input_type,
            "available": available,
        }
        if not available:
            entry["note"] = st.get("note")
        out.append(entry)
    return out


def get_adapter(task_id: str) -> AiAdapter | None:
    _ensure_defaults()
    return _registry.get(task_id)


def run_task(
    task_id: str,
    file_path: str | Path,
    settings: Settings | None = None,
) -> dict[str, Any]:
    """Dispatch inference to the named task's adapter.

    Unknown task -> ``{'available': False, 'note': 'unknown task ...'}``
    (never raises; the server turns that into a 404 with a clean message).
    """
    adapter = get_adapter(task_id)
    if adapter is None:
        return {
            "available": False,
            "task_id": task_id,
            "note": f"unknown ai task '{task_id}' for study input",
        }
    return adapter.predict(file_path, settings)
