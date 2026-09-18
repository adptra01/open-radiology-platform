import argparse
import logging
import sys

from .application import Adapter
from .config import settings
from .ris_client import RisClient
from .services import echo_scu


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(prog="orp-adapter", description="ORP RIS DICOM adapter")
    subparsers = parser.add_subparsers(dest="command", required=True)

    subparsers.add_parser("run", help="start MWL/MPPS/C-STORE SCP servers")

    ping = subparsers.add_parser("ping", help="send C-ECHO to a peer")
    ping.add_argument("peer_ae")
    ping.add_argument("--port", type=int, default=settings.mwl_port)
    ping.add_argument("--called-ae", default="")

    store = subparsers.add_parser("store", help="send C-STORE of a DICOM file to a peer")
    store.add_argument("file")
    store.add_argument("peer_ae")
    store.add_argument("--port", type=int, default=settings.store_port)
    store.add_argument("--called-ae", default="")

    args = parser.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")

    if args.command == "run":
        Adapter(settings, RisClient(settings.ris_api_url, settings.ris_api_key, settings.timeout)).run_forever()
        return 0

    if args.command == "ping":
        result = echo_scu.echo(args.peer_ae, args.port, args.called_ae)
        print(f"C-ECHO {'OK' if result else 'FAILED'}")
        return 0 if result else 1

    if args.command == "store":
        from .services import store_scu

        result = store_scu.store(args.file, args.peer_ae, args.port, args.called_ae)
        print(f"C-STORE success={result['success']} status={result.get('status')} sop_uid={result.get('instance_uid')}")
        return 0 if result["success"] else 1

    parser.print_help()
    return 2