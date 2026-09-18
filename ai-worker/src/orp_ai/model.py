"""Model loading with a module-level cache (one model in memory at a time)."""

from __future__ import annotations

import threading
from typing import Any

import torch
import torchxrayvision as xrv
from torch import nn

from .config import Settings

_LOCK = threading.Lock()
_LOADED: dict[str, nn.Module] = {}


def supported_models() -> list[str]:
    """Names of DenseNet checkpoints available in the installed torchxrayvision.

    Source of truth: ``xrv.models.model_urls`` keys (the exact set the package
    accepts). Verified on torchxrayvision 1.5.4 — none of the checkpoints expose
    a Tuberculosis label; TB detection needs an external model (see docs).
    """
    urls = getattr(xrv.models, "model_urls", None)
    if isinstance(urls, dict):
        return sorted(k for k in urls if k.startswith("densenet121-res224"))
    return [
        "densenet121-res224-all",
        "densenet121-res224-nih",
        "densenet121-res224-chex",
        "densenet121-res224-mimic_nb",
        "densenet121-res224-pc",
        "densenet121-res224-rsna",
        "densenet121-res224-mimic_ch",
    ]


def default_model() -> str:
    """First usable DenseNet checkpoint (prefers the 'all' checkpoint)."""
    available = set(supported_models())
    for name in (Settings.from_env().model_name, "densenet121-res224-all", "densenet121-res224-pc"):
        if name in available:
            return name
    return available[0]


def load_model(model_name: str, settings: Settings | None = None) -> nn.Module:
    """Load (or return cached) DenseNet121 checkpoint, pinned to CPU."""
    settings = settings or Settings.from_env()
    with _LOCK:
        if model_name in _LOADED:
            return _LOADED[model_name]
        model = xrv.models.DenseNet(weights=model_name)
        model.eval()
        model = model.to(settings.device)
        _LOADED[model_name] = model
        return model


def clear_cache() -> None:
    """Drop loaded models (useful before shutdown / tests)."""
    from .tb import clear_cache as _clear_tb_cache

    with _LOCK:
        _LOADED.clear()
    _clear_tb_cache()