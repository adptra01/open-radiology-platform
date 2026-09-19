import logging
from pathlib import Path

from pydicom.dataset import Dataset
from pydicom.uid import generate_uid
from pynetdicom.events import Event
from pynetdicom.sop_class import (
    CTImageStorage,
    ComputedRadiographyImageStorage,
    DigitalMammographyXRayImageStorageForPresentation,
    DigitalMammographyXRayImageStorageForProcessing,
    DigitalXRayImageStorageForPresentation,
    DigitalXRayImageStorageForProcessing,
    MRImageStorage,
    NuclearMedicineImageStorage,
    PositronEmissionTomographyImageStorage,
    RTImageStorage,
    SecondaryCaptureImageStorage,
    UltrasoundImageStorage,
    UltrasoundMultiFrameImageStorage,
    VideoPhotographicImageStorage,
    XRayAngiographicImageStorage,
    XRayRadiofluoroscopicImageStorage,
)

from ..ris_client import RisClient

logger = logging.getLogger(__name__)

SUPPORTED_CONTEXTS = [
    CTImageStorage,
    MRImageStorage,
    ComputedRadiographyImageStorage,
    DigitalXRayImageStorageForPresentation,
    DigitalXRayImageStorageForProcessing,
    DigitalMammographyXRayImageStorageForPresentation,
    DigitalMammographyXRayImageStorageForProcessing,
    NuclearMedicineImageStorage,
    PositronEmissionTomographyImageStorage,
    RTImageStorage,
    SecondaryCaptureImageStorage,
    UltrasoundImageStorage,
    UltrasoundMultiFrameImageStorage,
    VideoPhotographicImageStorage,
    XRayAngiographicImageStorage,
    XRayRadiofluoroscopicImageStorage,
]


def handlers(ris_client: RisClient, settings) -> list:
    from pynetdicom import events

    return [
        (events.EVT_C_STORE, lambda event: _handle_c_store(event, ris_client, settings)),
    ]


def _handle_c_store(event: Event, ris: RisClient, settings) -> dict:
    dataset: Dataset = event.dataset
    if dataset is None:
        logger.warning("c-store with missing dataset, rejected")
        return {"Status": 0xC000}

    try:
        payload = {
            "patient_id": getattr(dataset, "PatientID", ""),
            "patient_name": str(getattr(dataset, "PatientName", "")),
            "accession_number": getattr(dataset, "AccessionNumber", ""),
            "study_instance_uid": getattr(dataset, "StudyInstanceUID", ""),
            "series_instance_uid": getattr(dataset, "SeriesInstanceUID", ""),
            "sop_instance_uid": getattr(dataset, "SOPInstanceUID", ""),
            "sop_class_uid": getattr(dataset, "SOPClassUID", ""),
            "modality": getattr(dataset, "Modality", ""),
            "study_description": str(getattr(dataset, "StudyDescription", "")),
            "study_date": getattr(dataset, "StudyDate", ""),
            "study_time": getattr(dataset, "StudyTime", ""),
        }

        if settings.inbox_dir:
            # M12.2 B5: kirim storage reference (key relatif) + file_path absolut
            # legacy. RIS resolve via disk root-nya sendiri (ORP_INBOX_PATH) bila
            # file_path tak terbaca lintas-container (local volume/NFS/S3 nanti).
            saved = _persist(dataset, settings.inbox_dir)
            payload["file_path"] = saved
            try:
                payload["storage_key"] = str(Path(saved).relative_to(settings.inbox_dir))
            except ValueError:
                payload["storage_key"] = Path(saved).name

        response = ris.forward_study(payload)
        if response is None:
            # M12.2 B3: RIS unreachable/timeout — JANGAN sukses palsu.
            # 0xA700 (Refused: Out of Resources) = temporary failure;
            # modality harus retry, bukan menganggap terkirim.
            logger.error("RIS unreachable — refusing C-STORE so modality retries")
            return {"Status": 0xA700, "ErrorComment": "RIS unreachable, retry later"}
        if not response.ok:
            logger.warning("study forward HTTP %s: %s", response.status_code, response.text[:200])
            return {"Status": 0x0128}
    except Exception as exc:
        logger.exception("c-store handler error")
        return {"Status": 0xC000, "ErrorComment": str(exc)[:200]}

    return {"Status": 0x0000}


def _persist(dataset: Dataset, inbox_dir: str) -> str:
    directory = Path(inbox_dir)
    directory.mkdir(parents=True, exist_ok=True)
    study = getattr(dataset, "StudyInstanceUID", "") or "unknown"
    instance = getattr(dataset, "SOPInstanceUID", "") or generate_uid()
    target = directory / f"{study}.{instance}.dcm"
    dataset.save_as(str(target), enforce_file_format=True)
    return str(target)