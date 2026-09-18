"""Unit tests untuk handler MPPS (N-CREATE/N-SET state tracking, payload JSON-safe)."""
import pytest
from pydicom.dataset import Dataset
from pydicom.sequence import Sequence

from orp_adapter.handlers import mpps


class _FakeEvent:
    def __init__(self, dataset=None, sop_uid=None, requested=False):
        self._dataset = dataset
        self._sop = sop_uid
        self._requested = requested

    @property
    def attribute_list(self):
        return self._dataset

    @property
    def request(self):
        class _Req:
            pass

        r = _Req()
        if self._requested:
            r.RequestedSOPInstanceUID = self._sop
        else:
            r.AffectedSOPInstanceUID = self._sop
        return r


@pytest.fixture(autouse=True)
def _reset_state():
    mpps._pps_state.clear()
    yield
    mpps._pps_state.clear()


def _ncreate_ds(accession="ACC-260917-0001"):
    ds = Dataset()
    ds.SOPClassUID = "1.2.840.10008.3.1.2.3.3"
    ds.SOPInstanceUID = "1.2.3.4"
    ds.PerformedProcedureStepStatus = "IN PROGRESS"
    step = Dataset()
    step.AccessionNumber = accession
    step.ReferencedSOPClassUID = "1.2.840.10008.3.1.2.3.3"
    step.ReferencedSOPInstanceUID = "1.2.3.4"
    ds.ScheduledStepAttributesSequence = [step]
    return ds


def test_ncreate_result_has_status_and_dataset():
    """Handler mengembalikan tuple (status_int, response_dataset) pynetdicom 3.x."""
    class _Ris:
        def forward_mpps(self, payload):
            assert payload["type"] == "N-CREATE"
            return None  # simulate no HTTP response; still success

    ds = _ncreate_ds()
    result = mpps._handle_mpps(_FakeEvent(ds, "1.2.3.4", requested=False), _Ris(), "N-CREATE")
    assert isinstance(result, tuple) and len(result) == 2
    assert result[0] == 0x0000


def test_accession_extracted_from_sequence():
    """AccessionNumber di dalam ScheduledStepAttributesSequence terbaca (regresi Sequence->dict)."""
    ds = _ncreate_ds("ACC-XYZ-123")
    event = _FakeEvent(ds, "1.2.3.4", requested=False)
    captured = {}

    class _Ris:
        def forward_mpps(self, payload):
            captured.update(payload)
            return None

    mpps._handle_mpps(event, _Ris(), "N-CREATE")
    assert captured["AccessionNumber"] == "ACC-XYZ-123"
    assert isinstance(captured["ScheduledStepAttributesSequence"], list)
    assert captured["ScheduledStepAttributesSequence"][0]["AccessionNumber"] == "ACC-XYZ-123"


def test_nset_gets_accession_from_state():
    """N-SET tanpa AccessionNumber memakai state N-CREATE (regresi SOP UID tracking)."""
    # N-CREATE: dataset membawa SOPInstanceUID (sama dengan AffectedSOPInstanceUID)
    ds = _ncreate_ds("ACC-260917-0007")  # SOPInstanceUID = 1.2.3.4
    mpps._handle_mpps(_FakeEvent(ds, "1.2.3.4", requested=False), _RisNone(), "N-CREATE")

    nset_ds = Dataset()
    nset_ds.PerformedProcedureStepStatus = "COMPLETED"
    captured = {}

    class _Ris:
        def forward_mpps(self, payload):
            captured.update(payload)
            return None

    mpps._handle_mpps(_FakeEvent(nset_ds, "1.2.3.4", requested=True), _Ris(), "N-SET")
    assert captured["AccessionNumber"] == "ACC-260917-0007"
    assert captured["SOPInstanceUID"] == "1.2.3.4"


def test_nset_unknown_state_no_accession():
    """N-SET untuk PPS yang tidak pernah N-CREATE: jangan tambah accession kosong."""
    nset_ds = Dataset()
    nset_ds.PerformedProcedureStepStatus = "COMPLETED"
    captured = {}

    class _Ris:
        def forward_mpps(self, payload):
            captured.update(payload)
            return None

    mpps._handle_mpps(_FakeEvent(nset_ds, "1.1.1.1", requested=True), _Ris(), "N-SET")
    assert "AccessionNumber" not in captured
    # SOPInstanceUID tetap dikirim → Laravel meresolusi accession dari tabel
    # mpps_records (tahan restart adapter; state in-memory bukan lagi satu-satunya sumber).
    assert captured["SOPInstanceUID"] == "1.1.1.1"


def test_dataset_to_dict_json_safe():
    """_json_safe menangani Sequence, Dataset, list, bytes, str (regresi API pydicom 3.x)."""
    inner = Dataset()
    inner.AccessionNumber = "ACC-1"
    ds = Sequence([inner])
    payload = mpps._dataset_to_dict(ds[0])
    assert payload["AccessionNumber"] == "ACC-1"

    assert mpps._json_safe(None) is None
    assert mpps._json_safe(b"abc") == "abc"
    assert mpps._json_safe("str") == "str"
    assert mpps._json_safe(Sequence().__class__ == Sequence)  # smoke


class _RisNone:
    def forward_mpps(self, payload):
        return None