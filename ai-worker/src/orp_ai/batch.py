"""Batch inference over a directory of images/DICOMs -> JSON or CSV report."""

from __future__ import annotations

import csv
import json
import logging
from pathlib import Path

from .config import Settings
from .inference import findings_above_threshold, predict

log = logging.getLogger(__name__)

SUPPORTED_SUFFIXES = {".png", ".jpg", ".jpeg", ".dcm"}


def collect_images(root: str | Path) -> list[Path]:
    root = Path(root)
    if root.is_file():
        return [root] if root.suffix.lower() in SUPPORTED_SUFFIXES else []
    return sorted(
        p
        for p in root.rglob("*")
        if p.is_file() and p.suffix.lower() in SUPPORTED_SUFFIXES
    )


def run_batch(
    root: str | Path,
    settings: Settings | None = None,
    threshold: float | None = None,
) -> list[dict]:
    """Infer every image under `root` and return a list of compact reports."""
    settings = settings or Settings.from_env()
    images = collect_images(root)
    results: list[dict] = []
    for i, img in enumerate(images, 1):
        report = predict(img, settings)
        hits = findings_above_threshold(report, threshold)
        results.append(
            {
                "source": str(img),
                "model": report["model"],
                "findings": hits,
                "tuberculosis": report["focused"]["tuberculosis"],
                "tb": report["tb"],
            }
        )
        log.info("[%d/%d] %s — %d finding(s)", i, len(images), img, len(hits))
    return results


def write_report(results: list[dict], out: str | Path, fmt: str = "json") -> None:
    """Persist batch results as JSON (default) or CSV."""
    out = Path(out)
    out.parent.mkdir(parents=True, exist_ok=True)
    if fmt == "csv":
        # Flatten: one row per finding; pathologies without hits still listed once.
        rows: list[dict] = []
        for r in results:
            if not r["findings"]:
                rows.append({"source": r["source"], "finding": "", "probability": ""})
            for f in r["findings"]:
                rows.append(
                    {
                        "source": r["source"],
                        "finding": f["name"],
                        "probability": f["probability"],
                    }
                )
        with out.open("w", newline="", encoding="utf-8") as fh:
            writer = csv.DictWriter(fh, fieldnames=["source", "finding", "probability"])
            writer.writeheader()
            writer.writerows(rows)
    else:
        out.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
    log.info("Wrote %d result(s) to %s", len(results), out)