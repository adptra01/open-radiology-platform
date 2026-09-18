"""MPPS SCU e2e helper: N-CREATE then N-SET COMPLETED against ORP_RIS adapter.

Pola sama seperti sim_aet.py — dipakai untuk uji E2E manual MPPS:
    uv run python tests/mpps_scu.py --accession ACC-... ncreate
    uv run python tests/mpps_scu.py --accession ACC-... nset
"""
import argparse
import sys

from pydicom.dataset import Dataset
from pynetdicom import AE
from pynetdicom.sop_class import ModalityPerformedProcedureStep

SOP_INSTANCE = "1.2.826.0.1.3680043.8.498.20260917.999"


def build_ncreate(accession: str) -> Dataset:
    ds = Dataset()
    ds.SOPClassUID = ModalityPerformedProcedureStep
    ds.SOPInstanceUID = SOP_INSTANCE
    ds.PatientName = "Joko Susilo"
    ds.PatientID = "MRN-20260917-9291"
    ds.ProcedureStepDescription = "CT Abdomen"
    step = Dataset()
    step.AccessionNumber = accession
    step.ReferencedSOPClassUID = ModalityPerformedProcedureStep
    step.ReferencedSOPInstanceUID = SOP_INSTANCE
    ds.ScheduledStepAttributesSequence = [step]
    ds.PerformedProcedureStepStartDate = "20260917"
    ds.PerformedProcedureStepStartTime = "130000"
    ds.Modality = "CT"
    ds.PerformedProcedureStepID = "PPS-001"
    ds.PerformedProcedureStepStatus = "IN PROGRESS"
    return ds


def build_nset_completed() -> Dataset:
    ds = Dataset()
    ds.PerformedProcedureStepStatus = "COMPLETED"
    ds.PerformedProcedureStepEndDate = "20260917"
    ds.PerformedProcedureStepEndTime = "130500"
    return ds


def run(host: str, port: int, accession: str, action: str) -> int:
    ae = AE(ae_title="SIM_MPPS")
    ae.add_requested_context(ModalityPerformedProcedureStep)
    assoc = ae.associate(host, int(port), ae_title="ORP_RIS")
    if not assoc.is_established:
        print("MPPS: association FAILED")
        return 1
    status = None
    if action == "ncreate":
        status, _ = assoc.send_n_create(build_ncreate(accession), ModalityPerformedProcedureStep, SOP_INSTANCE)
    else:
        status, _ = assoc.send_n_set(build_nset_completed(), ModalityPerformedProcedureStep, SOP_INSTANCE)
    assoc.release()
    print(f"MPPS {action}: status={status.Status if status else 'None'} ({'OK' if status and status.Status == 0 else 'NONZERO/FAILED'})")
    return 0 if status and status.Status == 0 else 1


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=4244)
    ap.add_argument("--accession", required=True)
    ap.add_argument("action", choices=["ncreate", "nset"])
    args = ap.parse_args()
    sys.exit(run(args.host, args.port, args.accession, args.action))