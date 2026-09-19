"""Gateway tests: task-registry dispatch + structured AI-NOT-RUN reasons (M10).

Covers the architecture contract from the AI Gateway vision doc:
  * TB is a *capability plugin* — /infer/tb dispatches via the registry,
    and POST /infer/{task} works generically (unknown task -> 404).
  * INVALID DICOM -> AI NOT RUN with a stable machine-readable
    ``reason_code`` + user-facing ``reason`` (no DICOM internals in UI).

Run with: uv run pytest tests/test_gateway.py
"""

from __future__ import annotations

import asyncio
import io
import json
from pathlib import Path

import numpy as np
import pytest
from fastapi import HTTPException, UploadFile
from pydicom.dataset import Dataset
from pydicom.uid import ExplicitVRLittleEndian

from orp_ai.adapters import capabilities, get_adapter, run_task
from orp_ai.config import Settings
from orp_ai.dicom import USER_REASONS, user_reason, validate_dicom_only
from orp_ai.tb import clear_cache as clear_tb_cache
from orp_ai.tb import predict_tb

WEIGHTS = Path(__file__).resolve().parents[1] / "weights" / "tb_densenet121.pt"
needs_weights = pytest.mark.skipif(not WEIGHTS.exists(), reason="tb weights not present")


@pytest.fixture(autouse=True)
def _clean_tb():
    yield
    clear_tb_cache()


def _make_dicom_bytes(**overrides) -> bytes:
    """Minimal synthetic DICOM (default: valid CR chest)."""
    ds = Dataset()
    ds.file_meta = Dataset()
    ds.file_meta.TransferSyntaxUID = ExplicitVRLittleEndian
    ds.file_meta.MediaStorageSOPClassUID = "1.2.840.10008.5.1.4.1.1.1"
    ds.file_meta.MediaStorageSOPInstanceUID = "1.2.3.9.9.9"
    ds.file_meta.ImplementationClassUID = "1.2.3.4.5"
    ds.SOPClassUID = "1.2.840.10008.5.1.4.1.1.1"
    ds.SOPInstanceUID = "1.2.3.9.9.9"
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


def _upload(name: str, payload: bytes) -> UploadFile:
    return UploadFile(filename=name, file=io.BytesIO(payload))


# ---------------------------------------------------------------------------
# Registry
# ---------------------------------------------------------------------------


def test_registry_lists_tb_screening_capability():
    tasks = capabilities(Settings())
    tb = next(t for t in tasks if t["task_id"] == "tb-screening")
    assert tb["name"] == "TB Screening"
    assert set(tb["modalities"]) == {"CR", "DX"}
    assert tb["body_regions"] == ["CHEST"]
    assert tb["input_type"] == "2D_CXR"
    assert "available" in tb


def test_get_adapter_unknown_returns_none():
    assert get_adapter("lung-nodule") is None


def test_run_task_unknown_never_raises():
    res = run_task("lung-nodule", "/nonexistent/x.dcm", Settings())
    assert res["available"] is False
    assert "unknown ai task" in res["note"]


# ---------------------------------------------------------------------------
# Generic endpoint dispatch
# ---------------------------------------------------------------------------


def test_infer_task_unknown_returns_404():
    from orp_ai.server import infer_task

    with pytest.raises(HTTPException) as exc_info:
        asyncio.run(infer_task("lung-nodule", _upload("x.dcm", b"data")))
    assert exc_info.value.status_code == 404
    assert "capabilities" in exc_info.value.detail.lower()


def test_infer_task_rejects_unsupported_type():
    from orp_ai.server import infer_task

    with pytest.raises(HTTPException) as exc_info:
        asyncio.run(infer_task("tb-screening", _upload("x.exe", b"pe")))
    assert exc_info.value.status_code == 415


@needs_weights
def test_infer_task_dispatches_tb_invalid_dicom(tmp_path):
    """Generic route reaches the TB adapter: CT -> AI NOT RUN + reason_code."""
    from orp_ai.server import infer_task

    payload = _make_dicom_bytes(Modality="CT")
    res = asyncio.run(infer_task("tb-screening", _upload("ct.dcm", payload)))
    assert res.status_code == 200
    body = json.loads(res.body)
    assert body["available"] is False
    assert body["reason_code"] == "unsupported_modality"
    assert "probability" not in body or body.get("probability") is None
    assert isinstance(body["reason"], str) and body["reason"]


@needs_weights
def test_infer_tb_alias_goes_through_registry(tmp_path):
    """POST /infer/tb keeps working but now dispatches via run_task."""
    from orp_ai.server import infer_tb

    payload = _make_dicom_bytes(Modality="CT")
    res = asyncio.run(infer_tb(_upload("ct.dcm", payload)))
    assert res.status_code == 200
    body = json.loads(res.body)
    assert body["available"] is False
    assert body["reason_code"] == "unsupported_modality"


# ---------------------------------------------------------------------------
# Structured AI-NOT-RUN reasons
# ---------------------------------------------------------------------------


def test_validate_dicom_only_returns_reason_code(tmp_path):
    p = tmp_path / "ct.dcm"
    p.write_bytes(_make_dicom_bytes(Modality="CT"))
    out = validate_dicom_only(p)
    assert out["valid"] is False
    assert out["reason_code"] == "unsupported_modality"
    assert out["user_reason"] == USER_REASONS["unsupported_modality"]


def test_validate_dicom_only_valid_has_no_reason_code(tmp_path):
    p = tmp_path / "cr.dcm"
    p.write_bytes(_make_dicom_bytes())
    out = validate_dicom_only(p)
    assert out["valid"] is True
    assert "reason_code" not in out


@needs_weights
def test_predict_tb_invalid_dicom_returns_structured_not_run(tmp_path):
    p = tmp_path / "ct.dcm"
    p.write_bytes(_make_dicom_bytes(Modality="CT"))
    res = predict_tb(p, Settings())
    assert res["available"] is False
    assert res["probability"] is None
    assert res["reason_code"] == "unsupported_modality"
    assert res["reason"] == USER_REASONS["unsupported_modality"]
    # technical detail stays in note (audit), user reason has no DICOM jargon
    assert "Modality" in res["note"]
    assert "Modality" not in res["reason"]


def test_reason_codes_are_stable_surface():
    """Reason codes are API surface — changing them breaks the RIS UI."""
    assert set(USER_REASONS) == {
        "unsupported_modality",
        "unsupported_photometric",
        "unsupported_bits",
        "inconsistent_bits",
        "unsupported_planar",
        "missing_pixel_data",
        "unreadable_dicom",
        "invalid_dicom",
    }
    assert user_reason("nope-typo-code") == USER_REASONS["invalid_dicom"]
