"""Unit tests untuk mapping baris worklist JSON (dari Laravel) → dataset DICOM."""

from orp_adapter.handlers.mwl import _worklist_dataset


def test_worklist_dataset_maps_all_fields():
    row = {
        "patient_id": "MRN-20260917-0001",
        "patient_name": "Ahmad Fauzi",
        "patient_birth_date": "19880312",
        "patient_sex": "M",
        "accession_number": "ACC-260917-0001",
        "requested_procedure_id": "ORD-260917-0001",
        "requested_procedure_description": "Chest X-Ray 1 View",
        "scheduled_station_aetitle": "ORPCR1",
        "scheduled_start_date": "20260918",
        "scheduled_start_time": "080000",
        "modality": "CR",
        "performing_physician_name": "Dr. Budi Santoso",
        "procedure_step_description": "Chest X-Ray 1 View",
    }

    ds = _worklist_dataset(row)

    assert ds.PatientID == "MRN-20260917-0001"
    assert str(ds.PatientName) == "Ahmad Fauzi"
    assert ds.PatientBirthDate == "19880312"
    assert ds.PatientSex == "M"
    assert ds.AccessionNumber == "ACC-260917-0001"
    assert ds.RequestedProcedureID == "ORD-260917-0001"
    assert ds.RequestedProcedureDescription == "Chest X-Ray 1 View"

    step = ds.ScheduledProcedureStepSequence[0]
    assert step.ScheduledStationAETitle == "ORPCR1"
    assert step.ScheduledProcedureStepStartDate == "20260918"
    assert step.ScheduledProcedureStepStartTime == "080000"
    assert step.Modality == "CR"
    assert str(step.ScheduledPerformingPhysicianName) == "Dr. Budi Santoso"
    assert step.ScheduledProcedureStepDescription == "Chest X-Ray 1 View"


def test_worklist_dataset_handles_missing_keys():
    ds = _worklist_dataset({})

    assert ds.PatientID == ""
    assert ds.PatientName == ""
    assert ds.AccessionNumber == ""
    assert ds.ScheduledProcedureStepSequence[0].Modality == ""