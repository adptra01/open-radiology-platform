"""Versioned model registry — preprocessing locked to the model package.

On-disk layout (weights ``*.pt`` stay gitignored)::

    ai_gateway/models/<model_id>/<version>/
        metadata.json        model_id, version, task, framework, input spec
        preprocessing.json   locked training-equivalent preprocessing doc
        model.pt             artifact (NOT in git; Colab/Kaggle output)

Resolution order for weights (first hit wins):
  1. ``ORP_AI_TB_WEIGHTS`` env (explicit override, tests/ops)
  2. ``models/<model_id>/<version>/model.pt`` (registry home)
  3. legacy ``ai-worker/weights/tb_densenet121.pt`` (transitional fallback)
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

# ai-worker/src/ai_gateway/core/models.py -> parents[3] == <repo>/ai-worker
AI_WORKER_DIR = Path(__file__).resolve().parents[3]
GATEWAY_MODELS_DIR = Path(__file__).resolve().parents[1] / "models"
LEGACY_WEIGHTS_DIR = AI_WORKER_DIR / "weights"

# (model_id, version) -> weights filename inside the version dir.
MODEL_PACKAGES: dict[tuple[str, str], str] = {
    ("tb-densenet121", "1.0"): "model.pt",
}


@dataclass(frozen=True)
class ModelRecord:
    """Resolved model package: metadata + concrete weights path."""

    model_id: str
    version: str
    task_id: str
    weights_path: Path
    metadata: dict[str, Any] = field(default_factory=dict)


def package_dir(model_id: str, version: str) -> Path:
    return GATEWAY_MODELS_DIR / model_id / version


def load_metadata(model_id: str, version: str) -> dict[str, Any]:
    path = package_dir(model_id, version) / "metadata.json"
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))
def resolve_weights(
    model_id: str,
    version: str,
    env_override: str | None = None,
) -> Path | None:
    """Resolve the weights file; None when the model is not installed."""
    if env_override:
        p = Path(env_override)
        return p if p.exists() else None
    filename = MODEL_PACKAGES.get((model_id, version), "model.pt")
    candidate = package_dir(model_id, version) / filename
    if candidate.exists():
        return candidate
    # Transitional fallback: legacy flat weights dir (tb_densenet121.pt).
    legacy = LEGACY_WEIGHTS_DIR / "tb_densenet121.pt"
    if model_id == "tb-densenet121" and legacy.exists():
        return legacy
    return None


def get_model(
    model_id: str,
    version: str,
    task_id: str,
    env_override: str | None = None,
) -> ModelRecord | None:
    """Resolve a versioned model package; None when weights are absent."""
    weights = resolve_weights(model_id, version, env_override)
    if weights is None:
        return None
    override = env_override or os.environ.get("ORP_AI_TB_WEIGHTS")
    if override and weights == Path(override):
        pass  # explicit override already resolved above
    return ModelRecord(
        model_id=model_id,
        version=version,
        task_id=task_id,
        weights_path=weights,
        metadata=load_metadata(model_id, version),
    )
