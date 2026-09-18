import logging

from pynetdicom import events
from pynetdicom.events import Event
from pynetdicom.sop_class import ModalityPerformedProcedureStep

from ..ris_client import RisClient

logger = logging.getLogger(__name__)

# Cache lokal Performed Procedure Step: SOPInstanceUID -> accession_number.
# N-SET/N-ACTION tidak wajib membawa AccessionNumber dalam payload DICOM-nya,
# jadi adapter menyisipkannya bila tahu. Ini **hanya cache**: sumber kebenaran
# kini di Laravel (tabel `mpps_records`, diisi dari N-CREATE dan diresolusi
# lewat SOPInstanceUID), sehingga N-SET tetap tertaut ke order meskipun proses
# adapter restart di tengah prosedur atau ada beberapa instance adapter.
_pps_state: dict[str, str] = {}

SUPPORTED_CONTEXTS = [ModalityPerformedProcedureStep]


def handlers(ris_client: RisClient) -> list:
    return [
        (events.EVT_N_CREATE, lambda event: _handle_mpps(event, ris_client, "N-CREATE")),
        (events.EVT_N_SET, lambda event: _handle_mpps(event, ris_client, "N-SET")),
        (events.EVT_N_ACTION, lambda event: _handle_mpps(event, ris_client, "N-ACTION")),
    ]


def _handle_mpps(event: Event, ris: RisClient, operation: str) -> tuple:
    """Return (status_int, response_dataset) — pynetdicom 3.x N-CREATE/N-SET
    callback: status boleh int (0x0000 sukses) atau Dataset ber-Status; dataset
    response opsional."""
    try:
        # pynetdicom 3.x: C-STORE memakai event.dataset; N-CREATE/N-SET/N-ACTION
        # membawa materi di event.attribute_list.
        dataset = getattr(event, "attribute_list", None) or event.dataset
        payload = {
            "type": operation,
            **(_dataset_to_dict(dataset) if dataset is not None else {}),
        }
        # SOP Instance UID ada di primitive request; N-CREATE memakai
        # AffectedSOPInstanceUID, N-SET/N-ACTION memakai RequestedSOPInstanceUID.
        sop = (
            getattr(event.request, "AffectedSOPInstanceUID", None)
            or getattr(event.request, "RequestedSOPInstanceUID", None)
        )
        if sop:
            payload.setdefault("SOPInstanceUID", str(sop))
        _attach_accession(payload, operation)
        response = ris.forward_mpps(payload)
        if response is not None and not response.ok:
            logger.warning("mpps forward HTTP %s: %s", response.status_code, response.text[:200])
            return (0xC300, None)
        logger.debug("mpps %s forwarded (accession=%s)", operation, payload.get("AccessionNumber"))
        return (0x0000, None)
    except Exception as exc:
        logger.exception("mpps handler error")
        return (0xA700, None)
    return (0x0000, None)


def _attach_accession(payload: dict, operation: str) -> None:
    """Tambahkan AccessionNumber dari state MPPS bila payload belum memilikinya."""
    if payload.get("AccessionNumber"):
        return
    accession = _extract_accession(payload)
    if accession:
        payload.setdefault("AccessionNumber", accession)
        if operation == "N-CREATE":
            _pps_state[str(payload.get("SOPInstanceUID", ""))] = accession
        return

    # N-SET/N-ACTION: cari dari state N-CREATE sebelumnya (SOPInstanceUID kini
    # dijamin ada — ditambahkan dari event.request)
    previous = _pps_state.get(str(payload.get("SOPInstanceUID", "")))
    if previous:
        payload["AccessionNumber"] = previous


def _extract_accession(payload: dict) -> str:
    """Ambil AccessionNumber dari top-level atau ScheduledStepAttributesSequence."""
    value = payload.get("AccessionNumber")
    if value:
        return str(value)
    for step in payload.get("ScheduledStepAttributesSequence") or []:
        if isinstance(step, dict) and step.get("AccessionNumber"):
            return str(step["AccessionNumber"])
    return ""


def _dataset_to_dict(dataset) -> dict:
    result: dict = {}
    for element in dataset:
        result[element.keyword or str(element.tag)] = _json_safe(element.value)
    return result


def _json_safe(value):
    if value is None:
        return None
    if isinstance(value, bytes):
        return value.decode(errors="replace")
    if isinstance(value, str):
        return value
    # Dataset di dalam sequence (mis. ScheduledStepAttributesSequence) →
    # konversi rekursif ke dict agar extractAccession Laravel bisa membaca
    # AccessionNumber dari dalam sequence.
    from pydicom.dataset import Dataset
    from pydicom.sequence import Sequence

    if isinstance(value, Dataset):
        return _dataset_to_dict(value)
    if isinstance(value, Sequence):
        return [_json_safe(item) for item in value]
    if isinstance(value, (list, tuple)):
        return [_json_safe(item) for item in value]
    return str(value)