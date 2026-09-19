"""Smoke tests for the legacy xrv path (orp_ai inference/model).

TB + gateway coverage lives in tests/test_ai_gateway.py (ai_gateway/ package).
Run with: uv run pytest
"""

from __future__ import annotations

from pathlib import Path

import pytest

from orp_ai.config import Settings
from orp_ai.inference import predict
from orp_ai.model import clear_cache, load_model, supported_models

SAMPLE_CANDIDATES = [
    Path("/mnt/DiskD/Projects/DCM4CHE/sample-data/dicom/DX0000005 tes lagi/DX0000005 Chest PA/DX Chest PA/DX000000.png"),
    Path("/projects/DCM4CHE/sample-data/dicom/DX0000005 tes lagi/DX0000005 Chest PA/DX Chest PA/DX000000.png"),
]
SAMPLE = next((p for p in SAMPLE_CANDIDATES if p.exists()), None)


@pytest.fixture(autouse=True)
def _clean_models():
    yield
    clear_cache()


def test_supported_models_nonempty():
    models = supported_models()
    assert models
    assert "densenet121-res224-all" in models


def test_lung_pathologies_present():
    """Verified on torchxrayvision 1.5.4: the 'all' checkpoint exposes 18 lung
    pathology labels (NIH/CXR + CheXpert + MIMIC sets)."""
    model = load_model("densenet121-res224-all", Settings())
    labels = [p.lower() for p in model.pathologies]
    for expected in ("pneumonia", "effusion", "infiltration", "nodule", "mass"):
        assert expected in labels
    assert len(model.pathologies) == 18


def test_no_tb_label_in_xrv():
    """Reality check: NO torchxrayvision checkpoint ships a Tuberculosis label
    (despite earlier research claims). TB detection needs an external model —
    see docs/plan. Keep this test as a guard so a future upgrade does not
    silently change that assumption without notice."""
    model = load_model("densenet121-res224-all", Settings())
    assert not any("tuberc" in p.lower() for p in model.pathologies)


@pytest.mark.skipif(not SAMPLE.exists(), reason="sample chest X-ray not present")
def test_predict_sample_chest():
    report = predict(SAMPLE, Settings(threshold=0.0))
    assert report["findings"]
    assert report["model"] == "densenet121-res224-all"
    assert report["focused"]["tuberculosis"] is not None
    # probabilities must be valid
    for f in report["findings"]:
        assert 0.0 <= f["probability"] <= 1.0