"""Gateway package tests: registry, dispatcher, envelope, error codes (M10+).

Targets the NEW ai_gateway/ package (orp_ai/ stays untouched until cutover).
Run with: uv run pytest tests/test_ai_gateway.py
"""

from __future__ import annotations

import io
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient
from pydicom.dataset import Dataset
from pydicom.uid import ExplicitVRLittleEndian

from ai_gateway.core import envelope as env
from ai_gateway.core.envelope import ERROR_CODE_MAP
from ai_gateway.core.exceptions import UnknownTaskError
from ai_gateway.core.registry import get_adapter, list_capabilities, run_task
from ai_gateway.dicom.validator import USER_REASONS, user_reason, validate_dicom_only
from ai_gateway.tasks.tb.adapter import TBScreeningAdapter
from ai_gateway.tasks.tb.model import clear_cache

WEIGHTS = Path(__file__).resolve().parents[1] / "weights" / "tb_densenet121.pt"
needs_weights = pytest.mark.skipif(not WEIGHTS.exists(), reason="tb weights not present")


@pytest.fixture(autouse=True)
def _clean_tb():
    yield
    clear_cache()


def _make_dicom_bytes(**overrides) -> bytes:
    ds = Dataset()
    ds.file_meta = Dataset()
    ds.file_meta.TransferSyntaxUID = ExplicitVRLittleEndian
    ds.file_meta.MediaStorageSOPClassUID = "1.2.840.10008.5.1.4.1.1.1"
    ds.file_meta.MediaStorageSOPInstanceUID = "1.2.3.7.7.7"
    ds.file_meta.ImplementationClassUID = "1.2.3.4.5"
    ds.SOPClassUID = "1.2.840.10008.5.1.4.1.1.1"
    ds.SOPInstanceUID = "1.2.3.7.7.7"
    ds.Modality = "CR"
    ds.Rows = 64
    ds.Columns = 64
    ds.BitsAllocated = 16
    ds.BitsStored = 12
    ds.HighBit = 11
    ds.PixelRepresentation = 0
    ds.PhotometricInterpretation = "MONOCHROME2"
    ds.PlanarConfiguration = 0
    ds.SamplesPerPixel = 1
    arr = np.tile(np.arange(64, dtype=np.uint16), (64, 1))
    ds.PixelData = arr.tobytes()
    for k, v in overrides.items():
        setattr(ds, k, v)
    buf = io.BytesIO()
    ds.save_as(buf, write_like_original=False)
    return buf.getvalue()


def _client() -> TestClient:
    from ai_gateway.server import app

    return TestClient(app)


# ---------------------------------------------------------------------------
# Registry
# ---------------------------------------------------------------------------


def test_registry_lists_only_tb_screening():
    tasks = list_capabilities()
    assert [t["task_id"] for t in tasks] == ["tb-screening"]
    tb = tasks[0]
    assert tb["name"] == "TB Screening"
    assert set(tb["modalities"]) == {"CR", "DX"}
    assert tb["body_regions"] == ["CHEST"]
    assert tb["input_type"] == "2D_CXR"
    assert tb["model"] == {"id": "tb-densenet121", "version": "1.0"}
    assert "available" in tb


def test_get_adapter_unknown_returns_none():
    assert get_adapter("lung-nodule") is None


def test_run_task_unknown_raises():
    with pytest.raises(UnknownTaskError):
        run_task("lung-nodule", "/nonexistent/x.dcm")


# ---------------------------------------------------------------------------
# Envelope + error codes (locked surface)
# ---------------------------------------------------------------------------


def test_error_code_map_covers_all_reasons():
    assert set(ERROR_CODE_MAP) == set(USER_REASONS) | {"weights_missing", "inference_error"}
    assert env.error_code("unsupported_modality") == "UNSUPPORTED_MODALITY"
    assert env.error_code("typo-code") == "INVALID_DICOM"


def test_failed_envelope_shape():
    out = env.failed_envelope(
        task={"id": "tb-screening", "name": "TB Screening"},
        model={"id": "tb-densenet121", "version": "1.0"},
        reason_code="unsupported_modality",
        user_message="user msg",
        technical_note="tech detail",
        input_filename="x.dcm",
        processing_ms=3,
    )
    assert out["status"] == "failed"
    assert out["error"] == {"code": "UNSUPPORTED_MODALITY", "message": "user msg"}
    assert out["metadata"]["note"] == "tech detail"
    assert "diagnosis" in out["metadata"]["disclaimer"].lower()


# ---------------------------------------------------------------------------
# Dispatcher: VALID -> completed envelope, INVALID -> failed envelope
# ---------------------------------------------------------------------------


@needs_weights
def test_run_task_valid_cr_completes_with_label(tmp_path):
    p = tmp_path / "cr.dcm"
    p.write_bytes(_make_dicom_bytes())
    out = run_task("tb-screening", p, input_filename="cr.dcm")
    assert out["status"] == "completed"
    cls = out["result"]["classification"]
    assert cls["label"] in ("positive", "negative")
    assert 0.0 <= cls["score"] <= 1.0
    assert cls["threshold"] == 0.50
    assert out["result"]["calibration"] == {"status": "uncalibrated"}
    assert out["model"] == {"id": "tb-densenet121", "version": "1.0"}


@needs_weights
def test_run_task_invalid_ct_fails_with_code(tmp_path):
    p = tmp_path / "ct.dcm"
    p.write_bytes(_make_dicom_bytes(Modality="CT"))
    out = run_task("tb-screening", p, input_filename="ct.dcm")
    assert out["status"] == "failed"
    assert out["error"]["code"] == "UNSUPPORTED_MODALITY"
    assert "result" not in out  # never a fake prediction
    assert "Modality" in out["metadata"]["note"]  # tech detail in audit only
    assert "Modality" not in out["error"]["message"]


def test_validation_runs_before_weights_check(tmp_path):
    """INVALID input reports the input reason even when weights are missing.

    Validation precedes model loading: a CT reports UNSUPPORTED_MODALITY,
    not WEIGHTS_MISSING (verified live in container without weights).
    """
    from orp_ai.config import Settings

    p = tmp_path / "ct.dcm"
    p.write_bytes(_make_dicom_bytes(Modality="CT"))
    missing = Settings(tb_weights=str(tmp_path / "nope.pt"))
    out = run_task("tb-screening", p, missing, input_filename="ct.dcm")
    assert out["status"] == "failed"
    assert out["error"]["code"] == "UNSUPPORTED_MODALITY"


def test_validate_dicom_only_codes(tmp_path):
    p = tmp_path / "ct.dcm"
    p.write_bytes(_make_dicom_bytes(Modality="CT"))
    out = validate_dicom_only(p)
    assert out == {
        "valid": False,
        "reason": out["reason"],
        "reason_code": "unsupported_modality",
        "user_reason": USER_REASONS["unsupported_modality"],
    }


# ---------------------------------------------------------------------------
# HTTP: generic route, alias, 404, capabilities
# ---------------------------------------------------------------------------


def test_capabilities_endpoint():
    res = _client().get("/capabilities")
    assert res.status_code == 200
    body = res.json()
    assert body["count"] == 1
    assert body["tasks"][0]["task_id"] == "tb-screening"


def test_infer_unknown_task_404():
    res = _client().post(
        "/infer/lung-nodule", files={"file": ("x.dcm", b"data", "application/dicom")}
    )
    assert res.status_code == 404


def test_infer_rejects_unsupported_type():
    res = _client().post(
        "/infer/tb-screening", files={"file": ("x.exe", b"pe", "application/octet-stream")}
    )
    assert res.status_code == 415


@needs_weights
def test_infer_task_and_alias_agree(tmp_path):
    c = _client()
    payload = _make_dicom_bytes(Modality="CT")
    files = {"file": ("ct.dcm", payload, "application/dicom")}
    generic = c.post("/infer/tb-screening", files=files).json()
    files = {"file": ("ct.dcm", payload, "application/dicom")}
    alias = c.post("/infer/tb", files=files).json()
    for body in (generic, alias):
        assert body["status"] == "failed"
        assert body["error"]["code"] == "UNSUPPORTED_MODALITY"
    assert generic["task"] == alias["task"]


def test_adapter_threshold_is_explicitly_uncalibrated():
    a = TBScreeningAdapter()
    assert a.threshold == 0.50
    assert a.calibration_status == "uncalibrated"
    assert user_reason("missing_pixel_data") == USER_REASONS["missing_pixel_data"]


def _load_evaluate_module():
    import importlib.util

    path = Path(__file__).resolve().parents[1] / "scripts" / "tb_evaluate.py"
    spec = importlib.util.spec_from_file_location("tb_evaluate", path)
    assert spec is not None and spec.loader is not None
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def test_eval_roc_auc_known_values():
    import numpy as np

    mod = _load_evaluate_module()
    perfect = np.array([0.9, 0.8, 0.2, 0.1])
    labels = np.array([1, 1, 0, 0])
    assert mod.roc_auc(perfect, labels) == 1.0
    assert mod.roc_auc(1 - perfect, labels) == 0.0


def test_eval_sweep_shape_and_youden():
    import numpy as np

    mod = _load_evaluate_module()
    scores = np.array([0.9, 0.8, 0.2, 0.1])
    labels = np.array([1, 1, 0, 0])
    table = mod.sweep(scores, labels, grid=5)
    assert len(table) == 5
    assert all({"threshold", "sensitivity", "specificity", "youden"} <= set(r) for r in table)
    assert max(r["youden"] for r in table) == 1.0
