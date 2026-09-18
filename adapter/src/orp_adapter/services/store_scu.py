import logging
from pathlib import Path

from pydicom import dcmread
from pynetdicom import AE

logger = logging.getLogger(__name__)


def store(file_path: str, peer_ae: str, peer_port: int, called_ae: str = "") -> dict:
    path = Path(file_path)
    if not path.is_file():
        logger.error("file not found: %s", file_path)
        raise FileNotFoundError(file_path)

    dataset = dcmread(str(path))
    ae = AE()
    ae.add_requested_context(dataset.SOPClassUID)
    association = ae.associate(peer_ae, peer_port, ae_title=called_ae or None)
    if not association.is_established:
        logger.warning("C-STORE association to %s:%s failed", peer_ae, peer_port)
        association.abort()
        return {"success": False, "reason": "association_failed"}

    status = association.send_c_store(dataset)
    ok = bool(status is not None and status.Status == 0x0000)
    association.release()

    return {
        "success": ok,
        "status": status.Status if status else None,
        "instance_uid": getattr(dataset, "SOPInstanceUID", ""),
    }