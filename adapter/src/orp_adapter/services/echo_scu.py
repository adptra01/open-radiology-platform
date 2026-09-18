import logging

from pynetdicom import AE
from pynetdicom.sop_class import Verification

logger = logging.getLogger(__name__)


def echo(peer_ae: str, peer_port: int, called_ae: str = "") -> bool:
    ae = AE()
    ae.add_requested_context(Verification)
    association = ae.associate(peer_ae, peer_port, ae_title=called_ae or None)
    if not association.is_established:
        logger.warning("C-ECHO association to %s:%s failed", peer_ae, peer_port)
        return False
    status = association.send_c_echo()
    association.release()
    return bool(status is not None and status.Status == 0x0000)