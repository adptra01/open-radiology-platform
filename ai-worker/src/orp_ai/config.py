"""Runtime configuration for the ORP AI worker.

Values are read from environment variables (with sane defaults) so the same
package runs in CLI, HTTP server, and Docker contexts.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field


DEFAULT_MODEL = "densenet121-res224-all"


def _env_bool(name: str, default: bool) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


@dataclass(frozen=True)
class Settings:
    """Runtime settings. Frozen so accidental mutation cannot corrupt state."""

    device: str = "cpu"
    model_name: str = DEFAULT_MODEL
    threshold: float = 0.15  # minimum probability to include a finding
    top_k: int = 5
    max_upload_mb: int = 25
    cache_dir: str | None = None  # optional torch.hub weight cache override
    tb_weights: str | None = None  # optional path to the fine-tuned TB checkpoint

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            device=os.environ.get("ORP_AI_DEVICE", "cpu").lower(),
            model_name=os.environ.get("ORP_AI_MODEL", DEFAULT_MODEL),
            threshold=float(os.environ.get("ORP_AI_THRESHOLD", "0.15")),
            top_k=int(os.environ.get("ORP_AI_TOP_K", "5")),
            max_upload_mb=int(os.environ.get("ORP_AI_MAX_UPLOAD_MB", "25")),
            cache_dir=os.environ.get("ORP_AI_CACHE_DIR") or None,
            tb_weights=os.environ.get("ORP_AI_TB_WEIGHTS") or None,
        )


DEFAULT_SETTINGS = Settings.from_env()