"""Command-line interface for the ORP AI worker.

Usage:
    orp-ai models                # list supported checkpoints
    orp-ai infer <image>         # single image report
    orp-ai batch <folder> [-o out.json|out.csv] [--format json|csv]
    orp-ai serve [--host 0.0.0.0] [--port 8000]
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
from typing import NoReturn

from .batch import run_batch, write_report
from .config import DEFAULT_SETTINGS, Settings
from .inference import findings_above_threshold, predict
from .model import default_model, supported_models


def _settings_from_args(args: argparse.Namespace) -> Settings:
    kwargs = {}
    if getattr(args, "model", None):
        kwargs["model_name"] = args.model
    if getattr(args, "threshold", None) is not None:
        kwargs["threshold"] = args.threshold
    if getattr(args, "top_k", None) is not None:
        kwargs["top_k"] = args.top_k
    return Settings(**kwargs)


def cmd_models(_: argparse.Namespace) -> None:
    for name in supported_models():
        print(name)


def cmd_infer(args: argparse.Namespace) -> None:
    settings = _settings_from_args(args)
    report = predict(args.image, settings)
    print(json.dumps(report, indent=2, ensure_ascii=False))


def cmd_batch(args: argparse.Namespace) -> None:
    settings = _settings_from_args(args)
    results = run_batch(args.folder, settings, threshold=args.threshold)
    if args.out:
        write_report(results, args.out, args.format)
    else:
        print(json.dumps(results, indent=2, ensure_ascii=False))


def cmd_serve(args: argparse.Namespace) -> None:
    import uvicorn

    # M11: serve selalu AI Gateway (server lama orp_ai.server sudah dihapus).
    uvicorn.run("ai_gateway.server:app", host=args.host, port=args.port, reload=args.reload)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="orp-ai",
        description="CPU-only chest X-ray inference (TorchXRayVision) for ORP RIS/PACS.",
    )
    parser.add_argument("--model", help="checkpoint name (default: from env or 'all')")
    parser.add_argument("--threshold", type=float, default=None, help="finding probability cutoff")
    parser.add_argument("--top-k", dest="top_k", type=int, default=None, help="findings to keep")
    sub = parser.add_subparsers(dest="command", required=True)

    p_models = sub.add_parser("models", help="list supported checkpoints")
    p_models.set_defaults(func=cmd_models)

    p_infer = sub.add_parser("infer", help="single image -> JSON report")
    p_infer.add_argument("image", type=str, help="path to image (PNG/JPG) or DICOM")
    p_infer.set_defaults(func=cmd_infer)

    p_batch = sub.add_parser("batch", help="folder -> per-image findings report")
    p_batch.add_argument("folder", type=str, help="file or directory of images/DICOMs")
    p_batch.add_argument("-o", "--out", type=str, default=None, help="output file (default: stdout)")
    p_batch.add_argument("--format", choices=["json", "csv"], default="json")
    p_batch.set_defaults(func=cmd_batch)

    p_serve = sub.add_parser("serve", help="run the AI Gateway HTTP server")
    p_serve.add_argument("--host", type=str, default="0.0.0.0")
    p_serve.add_argument("--port", type=int, default=8000)
    p_serve.add_argument("--reload", action="store_true")
    p_serve.set_defaults(func=cmd_serve)

    return parser


def main(argv: list[str] | None = None) -> NoReturn:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    args = build_parser().parse_args(argv)
    args.func(args)
    raise SystemExit(0)


if __name__ == "__main__":
    main()