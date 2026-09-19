"""Smoke tests for the ORP AI worker (CPU). Run with: uv run pytest"""

from __future__ import annotations

import asyncio
from pathlib import Path

import pytest

from orp_ai.config import Settings
from orp_ai.inference import predict
from orp_ai.model import clear_cache, load_model, supported_models
from orp_ai.tb import clear_cache as clear_tb_cache
from orp_ai.tb import load_tb_model, predict_tb, tb_status

SAMPLE_CANDIDATES = [
    Path("/mnt/DiskD/Projects/DCM4CHE/sample-data/dicom/DX0000005 tes lagi/DX0000005 Chest PA/DX Chest PA/DX000000.png"),
    Path("/projects/DCM4CHE/sample-data/dicom/DX0000005 tes lagi/DX0000005 Chest PA/DX Chest PA/DX000000.png"),
]
SAMPLE = next((p for p in SAMPLE_CANDIDATES if p.exists()), None)


@pytest.fixture(autouse=True)
def _clean_models():
    yield
    clear_cache()
    clear_tb_cache()


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


def test_tb_weights_missing_degrades_gracefully(tmp_path):
    """Without the Colab checkpoint the TB blocker must NOT fail inference."""
    settings = Settings(tb_weights=str(tmp_path / "missing_tb.pt"))
    assert load_tb_model(settings) is None
    assert predict_tb("", settings)["available"] is False
    status = tb_status(settings)
    assert status["available"] is False
    assert "weights not found" in status["note"].lower()


def test_infer_tb_endpoint_degrades_without_weights(tmp_path, monkeypatch):
    """POST /infer/tb returns available=False (bukan 500) saat weights belum ada.
    
    Skips if dummy/real weights already present (uses separate env to isolate)."""
    import io
    import json
    import os

    from fastapi import HTTPException, UploadFile

    # Force weights path to non-existent file
    monkeypatch.setenv("ORP_AI_TB_WEIGHTS", str(tmp_path / "missing_tb.pt"))
    
    # Need to reload server module to pick up new env
    import importlib
    import orp_ai.server as server_module
    importlib.reload(server_module)

    from orp_ai.server import infer_tb

    file = UploadFile(filename="x.dcm", file=io.BytesIO(b"fake-dicom-bytes"))
    res = asyncio.run(infer_tb(file))
    assert res.status_code == 200
    body = json.loads(res.body)
    assert body["available"] is False
    assert "weights not found" in body["note"].lower()


def test_infer_tb_rejects_unsupported_type():
    import io

    from fastapi import HTTPException, UploadFile

    from orp_ai.server import infer_tb

    file = UploadFile(filename="x.exe", file=io.BytesIO(b"pe"))
    try:
        asyncio.run(infer_tb(file))
    except HTTPException as exc:
        assert exc.status_code == 415
    else:
        raise AssertionError("expected HTTPException 415 for unsupported type")