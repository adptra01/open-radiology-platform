import logging
import signal
import threading

from pynetdicom import AE, events
from pynetdicom.sop_class import Verification

from .config import Settings
from .handlers import mpps, mwl, store
from .ris_client import RisClient

logger = logging.getLogger(__name__)


class Adapter:
    def __init__(self, settings: Settings, ris_client: RisClient):
        self.settings = settings
        self.ris_client = ris_client
        self._stop = threading.Event()

    def run_forever(self) -> None:
        servers = [
            threading.Thread(
                target=self._serve,
                args=(self.settings.mwl_port, mwl.SUPPORTED_CONTEXTS, [Verification], [(events.EVT_C_FIND, mwl.c_find_mwl, [self.ris_client])]),
                daemon=True,
            ),
            threading.Thread(
                target=self._serve,
                args=(self.settings.mpps_port, mpps.SUPPORTED_CONTEXTS, [Verification], mpps.handlers(self.ris_client)),
                daemon=True,
            ),
            threading.Thread(
                target=self._serve,
                args=(self.settings.store_port, store.SUPPORTED_CONTEXTS, [Verification], store.handlers(self.ris_client, self.settings)),
                daemon=True,
            ),
        ]

        for thread in servers:
            thread.start()

        logger.info(
            "ORP DICOM adapter online -> MWL:%s MPPS:%s STORE:%s AE:%s",
            self.settings.mwl_port,
            self.settings.mpps_port,
            self.settings.store_port,
            self.settings.ae_title,
        )

        self.ris_client.announce_online(
            {
                "ae_title": self.settings.ae_title,
                "host": self.settings.host,
                "mwl_port": self.settings.mwl_port,
                "mpps_port": self.settings.mpps_port,
                "store_port": self.settings.store_port,
            }
        )

        signal.signal(signal.SIGINT, lambda *_: self._stop.set())
        signal.signal(signal.SIGTERM, lambda *_: self._stop.set())
        self._stop.wait()

    def _serve(self, port: int, supported: list, also_supported: list, evt_handlers: list) -> None:
        ae = AE(ae_title=self.settings.ae_title)
        for sop_class in [*supported, *also_supported]:
            ae.add_supported_context(sop_class)
        server = ae.start_server((self.settings.host, port), block=False, evt_handlers=evt_handlers, ae_title=self.settings.ae_title)
        if server is not None:
            server.serve_forever()