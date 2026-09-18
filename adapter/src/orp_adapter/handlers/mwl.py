from pydicom.dataset import Dataset
from pynetdicom.events import Event
from pynetdicom.sop_class import ModalityWorklistInformationFind

from ..ris_client import RisClient

import logging

logger = logging.getLogger(__name__)

SUPPORTED_CONTEXTS = [ModalityWorklistInformationFind]


def c_find_mwl(event: Event, ris_client: RisClient):
    identifier = event.identifier or Dataset()
    query = {
        "PatientID": _string(identifier, "PatientID"),
        "AccessionNumber": _string(identifier, "AccessionNumber"),
        "PatientsName": _string(identifier, "PatientsName"),
        "RequestedProcedureID": _string(identifier, "RequestedProcedureID"),
        "Modality": _string(identifier, "Modality"),
        "ScheduledStationAETitle": _string(identifier, "ScheduledStationAETitle"),
        "ScheduledProcedureStepStartDate": _string(identifier, "ScheduledProcedureStepStartDate"),
    }
    query = {k: v for k, v in query.items() if v}
    logger.debug("MWL C-FIND query: %s", query)

    for row in ris_client.fetch_worklist(query):
        yield 0xFF00, _worklist_dataset(row)


def _worklist_dataset(row: dict) -> Dataset:
    ds = Dataset()
    ds.PatientName = row.get("patient_name", "")
    ds.PatientID = row.get("patient_id", "")
    ds.PatientBirthDate = row.get("patient_birth_date", "")
    ds.PatientSex = row.get("patient_sex", "")
    ds.AccessionNumber = row.get("accession_number", "")
    ds.RequestedProcedureID = row.get("requested_procedure_id", "")
    ds.RequestedProcedureDescription = row.get("requested_procedure_description", "")
    scheduler = Dataset()
    scheduler.ScheduledStationAETitle = row.get("scheduled_station_aetitle", "")
    scheduler.ScheduledProcedureStepStartDate = row.get("scheduled_start_date", "")
    scheduler.ScheduledProcedureStepStartTime = row.get("scheduled_start_time", "")
    scheduler.Modality = row.get("modality", "")
    scheduler.ScheduledPerformingPhysicianName = row.get("performing_physician_name", "")
    scheduler.ScheduledProcedureStepDescription = row.get("procedure_step_description", "")
    ds.ScheduledProcedureStepSequence = [scheduler]
    return ds


def _string(identifier: Dataset, keyword: str) -> str:
    try:
        value = identifier.get(keyword)
    except Exception:
        return ""
    if value is None:
        return ""

    # pydicom >= 3: ds.get(keyword) mengembalikan value langsung (str/bytes),
    # bukan DataElement seperti di pydicom 2.x — dukung keduanya.
    if isinstance(value, bytes):
        return value.decode(errors="replace")
    if isinstance(value, str):
        return value
    try:
        value = value.value
    except AttributeError:
        pass
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode(errors="replace")
    return str(value)