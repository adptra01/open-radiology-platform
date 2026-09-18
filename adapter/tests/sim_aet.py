"""Simulates a DICOM modality: C-ECHO, MWL query, and optional C-STORE against the ORP RIS adapter."""

import argparse
import sys
from pathlib import Path

from pydicom.dataset import Dataset
from pydicom.uid import generate_uid
from pynetdicom import AE
from pynetdicom.sop_class import CTImageStorage, ModalityWorklistInformationFind, Verification


def build_echo_ae() -> AE:
    ae = AE(ae_title="SIMAET")
    ae.add_requested_context(Verification)
    return ae


def build_mwl_ae() -> AE:
    ae = AE(ae_title="SIMAET")
    ae.add_requested_context(ModalityWorklistInformationFind)
    return ae


def build_store_ae() -> AE:
    ae = AE(ae_title="SIMAET")
    ae.add_requested_context(CTImageStorage)
    return ae


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(prog="sim_aet", description="Simulator DICOM modality for ORP RIS")
    parser.add_argument("peer_ae", nargs="?", default="ORP_RIS", help="AE title of the RIS adapter")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--mwl-port", type=int, default=4243)
    parser.add_argument("--store-port", type=int, default=4245)
    parser.add_argument("--store", type=Path, default=None, help="DICOM file to C-STORE")
    parser.add_argument("--accession", default="ACC0001", help="accession number to query MWL with")
    args = parser.parse_args(argv)

    echo_ae = build_echo_ae()
    assoc = echo_ae.associate(args.host, args.mwl_port, ae_title=args.peer_ae)
    if not assoc.is_established:
        print("C-ECHO: association FAILED")
        return 1
    print(f"C-ECHO: status={assoc.send_c_echo().Status} (0000=success)")
    assoc.release()
    echo_ae.shutdown()

    mwl_ae = build_mwl_ae()
    assoc = mwl_ae.associate(args.host, args.mwl_port, ae_title=args.peer_ae)
    if not assoc.is_established:
        print("MWL: association FAILED")
        return 1

    query = Dataset()
    query.QueryRetrieveLevel = "WORKLIST"
    query.AccessionNumber = args.accession
    matches = []
    from pynetdicom.sop_class import ModalityWorklistInformationFind
    for status, identifier in assoc.send_c_find(query, ModalityWorklistInformationFind):
        print(f"MWL: status={status.Status} matches={len(matches)}")
        matches.append(identifier)
        print(f"  -> {getattr(identifier, 'PatientName', '')} / {getattr(identifier, 'PatientID', '')}")
        break
    assoc.release()
    mwl_ae.shutdown()

    if args.store is not None:
        from pydicom import dcmread

        dataset = dcmread(str(args.store))
        store_ae = build_store_ae()
        store_ae.add_requested_context(dataset.SOPClassUID)
        assoc = store_ae.associate(args.host, args.store_port, ae_title=args.peer_ae)
        if not assoc.is_established:
            print("C-STORE: association FAILED")
            return 1
        status = assoc.send_c_store(dataset)
        print(f"C-STORE: status={status.Status if status else None} (0000=success)")
        assoc.release()
        store_ae.shutdown()

    return 0


if __name__ == "__main__":
    sys.exit(main())