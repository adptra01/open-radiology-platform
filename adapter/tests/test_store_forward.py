"""Unit tests untuk C-STORE forward reliability (M12.2 B3/B5).

Kontrak: RIS unreachable/timeout (forward -> None) TIDAK BOLEH 0x0000 —
harus 0xA700 agar modality retry. Payload membawa storage_key relatif
disamping file_path absolut legacy.
"""

import json
import types

import pytest
from pydicom.dataset import Dataset
from pydicom.uid import ExplicitVRLittleEndian

from orp_adapter.handlers.store import _handle_c_store


class FakeResponse:
    def __init__(self, status_code=200):
        self.status_code = status_code
        self.text = json.dumps({"ok": status_code < 400})
        self.ok = status_code < 400


class FakeRis:
    def __init__(self, response):
        self._response = response

    def forward_study(self, payload):
        self.sent_payload = payload
        return self._response


class FakeSettings:
    def __init__(self, inbox_dir):
        self.inbox_dir = inbox_dir


def _dataset(**overrides):
    ds = Dataset()
    ds.file_meta = Dataset()
    ds.file_meta.TransferSyntaxUID = ExplicitVRLittleEndian
    ds.file_meta.MediaStorageSOPClassUID = "1.2.840.10008.5.1.4.1.1.2"
    ds.file_meta.MediaStorageSOPInstanceUID = "1.2.9.3"
    ds.file_meta.ImplementationClassUID = "1.2.3.4.5"
    ds.PatientID = "P1"
    ds.PatientName = "Tes^Pasien"
    ds.AccessionNumber = "ACC-260919-0001"
    ds.StudyInstanceUID = "1.2.9.1"
    ds.SeriesInstanceUID = "1.2.9.2"
    ds.SOPInstanceUID = "1.2.9.3"
    ds.SOPClassUID = "1.2.840.10008.5.1.4.1.1.2"
    ds.Modality = "CT"
    for k, v in overrides.items():
        setattr(ds, k, v)
    return types.SimpleNamespace(dataset=ds)


def test_forward_none_returns_a700_not_success(tmp_path):
    """M12.2 B3: RIS down -> 0xA700 (temporary failure), bukan 0x0000."""
    out = _handle_c_store(_dataset(), FakeRis(None), FakeSettings(str(tmp_path)))
    assert out["Status"] == 0xA700
    assert out["Status"] != 0x0000


def test_forward_http_error_returns_failure(tmp_path):
    out = _handle_c_store(_dataset(), FakeRis(FakeResponse(500)), FakeSettings(str(tmp_path)))
    assert out["Status"] == 0x0128


def test_forward_ok_returns_success_with_storage_key(tmp_path):
    """M12.2 B5: payload membawa storage_key relatif + file_path."""
    ris = FakeRis(FakeResponse(200))
    out = _handle_c_store(_dataset(), ris, FakeSettings(str(tmp_path)))
    assert out["Status"] == 0x0000
    assert "storage_key" in ris.sent_payload
    assert "/" not in ris.sent_payload["storage_key"]  # relatif, bukan absolut
    assert ris.sent_payload["storage_key"].endswith(".dcm")


def test_missing_dataset_rejected():
    out = _handle_c_store(types.SimpleNamespace(dataset=None), FakeRis(FakeResponse(200)), FakeSettings(""))
    assert out["Status"] == 0xC000
