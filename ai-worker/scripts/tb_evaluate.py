"""TB evaluation harness — Phase 3 ready (keputusan arsitektur final M10+).

Scores a labeled image set with the versioned TB model package and writes a
calibration report: ROC-AUC, sensitivity/specificity sweep, Youden-optimal
threshold recommendation. The production threshold (0.50, uncalibrated) is
replaced by the recommended value ONLY after this runs on real held-out data.

Layouts accepted for --data:
  * <dir>/<label>/*.png|jpg  (label = directory name, e.g. Normal/Tuberculosis)
  * --csv labels.csv  (columns: path,label with label in {0,1})

--synthetic N proves the plumbing end-to-end without data (random pixels +
random labels; metrics MEANINGLESS, report stamped synthetic:true).

No new dependencies (ROC-AUC via Mann-Whitney on numpy).

Usage:
  uv run python scripts/tb_evaluate.py --weights weights/tb_densenet121.pt \\
      --data /path/to/labeled --out eval-report.json
  uv run python scripts/tb_evaluate.py --synthetic 40 --out /tmp/selftest.json
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path

import numpy as np
import torch
from PIL import Image

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))

from ai_gateway.core.models import get_model  # noqa: E402
from ai_gateway.tasks.tb import preprocessing as tb_pre  # noqa: E402
from ai_gateway.tasks.tb.model import MODEL_ID, MODEL_VERSION, load_model  # noqa: E402

LABEL_MAP = {"normal": 0, "tuberculosis": 1, "tb": 1, "0": 0, "1": 1}


def collect_items(data_dir: Path, csv_path: Path | None) -> list[tuple[Path, int]]:
    if csv_path is not None:
        items = []
        with open(csv_path, newline="") as f:
            for row in csv.DictReader(f):
                label = LABEL_MAP.get(str(row["label"]).strip().lower())
                if label is None:
                    raise ValueError(f"Unknown label: {row['label']}")
                items.append((Path(row["path"]), label))
        return items
    items = []
    for sub in sorted(p for p in data_dir.iterdir() if p.is_dir()):
        label = LABEL_MAP.get(sub.name.strip().lower())
        if label is None:
            continue
        for ext in ("*.png", "*.jpg", "*.jpeg"):
            items.extend((p, label) for p in sorted(sub.glob(ext)))
    if not items:
        raise ValueError(f"No labeled images under {data_dir} (need <label>/*.png)")
    return items


def roc_auc(scores: np.ndarray, labels: np.ndarray) -> float:
    """ROC-AUC via Mann-Whitney U (no sklearn needed)."""
    pos = scores[labels == 1]
    neg = scores[labels == 0]
    if len(pos) == 0 or len(neg) == 0:
        return float("nan")
    # P(score_pos > score_neg) + 0.5 * P(tie)
    wins = sum(float((pos > n).sum()) + 0.5 * float((pos == n).sum()) for n in neg)
    return float(wins / (len(pos) * len(neg)))


def sweep(scores: np.ndarray, labels: np.ndarray, grid: int = 21) -> list[dict]:
    out = []
    for t in np.linspace(0.05, 0.95, grid).round(4):
        pred = (scores >= t).astype(int)
        tp = int(((pred == 1) & (labels == 1)).sum())
        tn = int(((pred == 0) & (labels == 0)).sum())
        fp = int(((pred == 1) & (labels == 0)).sum())
        fn = int(((pred == 0) & (labels == 1)).sum())
        sens = tp / (tp + fn) if (tp + fn) else 0.0
        spec = tn / (tn + fp) if (tn + fp) else 0.0
        out.append({
            "threshold": float(t), "sensitivity": round(sens, 4),
            "specificity": round(spec, 4), "youden": round(sens + spec - 1, 4),
            "tp": tp, "tn": tn, "fp": fp, "fn": fn,
        })
    return out


def score_files(files: list[Path], weights: Path, device: str) -> np.ndarray:
    record = get_model(MODEL_ID, MODEL_VERSION, "tb-screening",
                       env_override=str(weights))
    if record is None:
        raise FileNotFoundError(f"Weights not found: {weights}")
    model, _meta = load_model(record)
    scores = []
    with torch.no_grad():
        for fp in files:
            pil = Image.open(fp).convert("L")
            tensor = tb_pre.pil_to_tensor(pil).to(device)
            logit = model(tensor).squeeze(1).item()
            scores.append(float(torch.sigmoid(torch.tensor(logit))))
    return np.array(scores)


def main() -> int:
    ap = argparse.ArgumentParser(description="TB Phase-3 evaluation harness")
    ap.add_argument("--weights", type=Path, default=Path("weights/tb_densenet121.pt"))
    ap.add_argument("--data", type=Path, default=None)
    ap.add_argument("--csv", type=Path, default=None)
    ap.add_argument("--out", type=Path, required=True)
    ap.add_argument("--synthetic", type=int, default=0,
                    help="self-test with N random images (metrics meaningless)")
    ap.add_argument("--device", default="cpu")
    args = ap.parse_args()

    rng = np.random.default_rng(42)
    if args.synthetic > 0:
        tmp = args.out.parent / "_synthetic_eval"
        tmp.mkdir(parents=True, exist_ok=True)
        items: list[tuple[Path, int]] = []
        for i in range(args.synthetic):
            label = int(rng.integers(0, 2))
            fp = tmp / f"img_{i:03d}.png"
            Image.fromarray(rng.integers(0, 256, (224, 224)).astype(np.uint8)).save(fp)
            items.append((fp, label))
        synthetic = True
    else:
        if args.data is None:
            print("Need --data <dir> (or --csv) unless --synthetic", file=sys.stderr)
            return 2
        items = collect_items(args.data, args.csv)
        synthetic = False

    files = [fp for fp, _ in items]
    labels = np.array([lb for _, lb in items])
    scores = score_files(files, args.weights, args.device)
    table = sweep(scores, labels)
    best = max(table, key=lambda r: (r["youden"], r["specificity"]))

    report = {
        "model": {"id": MODEL_ID, "version": MODEL_VERSION},
        "dataset": {"n": len(items), "n_pos": int(labels.sum()),
                    "n_neg": int(len(labels) - labels.sum()),
                    "source": "synthetic-selftest" if synthetic else str(args.data or args.csv)},
        "metrics": {"roc_auc": round(roc_auc(scores, labels), 4)},
        "threshold_sweep": table,
        "recommended": {"threshold": best["threshold"], "youden": best["youden"],
                        "sensitivity": best["sensitivity"], "specificity": best["specificity"]},
        "calibration": {"status": "synthetic-selftest" if synthetic else "evaluated-pending-review",
                        "note": "Production keeps 0.50/uncalibrated until a held-out clinical review adopts a recommended value."},
        "synthetic": synthetic,
    }
    args.out.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(f"n={len(items)} auc={report['metrics']['roc_auc']} "
          f"best_t={best['threshold']} (youden={best['youden']}) -> {args.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
