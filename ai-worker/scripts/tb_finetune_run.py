#!/usr/bin/env python3
"""ORP AI — Fine-tune deteksi Tuberkulosis via Google Colab CLI (headless).

Cermin 1:1 dari ``ai-worker/notebooks/tb_finetune_colab.ipynb`` (sel 1-9),
ditulis sebagai skrip .py polos agar bisa dijalankan dengan:

    colab new -s tb-train --gpu T4
    colab install -s tb-train torch torchvision huggingface_hub scikit-learn pillow pandas
    colab exec -s tb-train -f ai-worker/scripts/tb_finetune_run.py
    colab download -s tb-train tb_densenet121.pt ai-worker/weights/tb_densenet121.pt
    colab stop -s tb-train

atau satu perintah ephemeral (VM dibuat + dihancurkan otomatis):

    colab run --gpu T4 ai-worker/scripts/tb_finetune_run.py -- --epochs 20

Atau via shebang: ``./ai-worker/scripts/tb_finetune_run.py`` (lihat bawah).

HANYA dataset publik (Montgomery+Shenzhen via HuggingFace, ~100MB) yang
diunduh ke runtime Colab. Data pasien/institusi DILARANG (UU PDP 27/2022).
HANYA bobot akhir (~35MB) yang keluar dari Colab via `colab download`.

Checkpoint yang dihasilkan kompatibel dengan `ai-worker/src/orp_ai/tb.py`:
    {"state_dict": {...}, "pathologies": ["Normal","Tuberculosis"],
     "val_auc": float, "dataset": str, "note": str}
Pipeline inferensi identik: Grayscale(3) -> Resize 224 -> ToTensor ->
Normalize ImageNet, TANPA CenterCrop.
"""
# Jalankan langsung di GPU Colab bila executable (shebang untuk `colab run`).
# noqa: RUF100 - shebang berikut aktif saat file di-chmod +x:
# #!/usr/bin/env -S colab run --gpu T4 --keep

from __future__ import annotations

import argparse
import random
from pathlib import Path

SEED = 42
HF_DATASET = "Famatsu123/montgomery-shenzhen-tuberculosis-cxr"
CHECKPOINT_NOTE = "alat skrining/triase — bukan alat diagnostik"


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Fine-tune DenseNet121 untuk skrining TB")
    p.add_argument("--epochs", type=int, default=20)
    p.add_argument("--batch-size", type=int, default=16)
    p.add_argument("--lr", type=float, default=1e-4)
    p.add_argument("--data-dir", default="/content/data/monty_shenzhen")
    p.add_argument("--out", default="tb_densenet121.pt")
    return p.parse_args()


def main() -> None:
    args = parse_args()

    import numpy as np
    import pandas as pd
    import torch
    from huggingface_hub import snapshot_download
    from PIL import Image
    from sklearn.metrics import (
        accuracy_score,
        f1_score,
        precision_score,
        recall_score,
        roc_auc_score,
    )
    from sklearn.model_selection import train_test_split
    from torch import nn
    from torch.utils.data import DataLoader, Dataset
    from torchvision import models, transforms

    print(f"torch={torch.__version__} cuda={torch.cuda.is_available()}", flush=True)
    random.seed(SEED)
    np.random.seed(SEED)
    torch.manual_seed(SEED)

    # --- 1. Unduh dataset publik ke disk runtime Colab ---
    root = Path(
        snapshot_download(repo_id=HF_DATASET, repo_type="dataset", local_dir=args.data_dir)
    )
    print("Dataset siap di:", root, flush=True)

    # --- 2. Kumpulkan gambar + label heuristik (sel 3-4 notebook) ---
    exts = {".png", ".jpg", ".jpeg", ".bmp"}
    imgs = sorted(p for p in root.rglob("*") if p.suffix.lower() in exts)
    cr = root / "MontgomerySet" / "ClinicalReadings"

    def label_from_path(p: Path) -> str | None:
        name = p.name.lower()
        if "tb" in name and "normal" not in name:
            return "tb"
        if "normal" in name and "tb" not in name:
            return "normal"
        if "tuberculosis" in name:
            return "tb"
        if cr.exists():
            for txt in cr.glob(p.stem + ".txt"):
                lines = txt.read_text().splitlines()
                if len(lines) >= 3:
                    diag = lines[2].strip().lower()
                    return "tb" if diag in ("tb", "tuberculosis", "tb+") else "normal"
        return None

    rows = [(str(p), label_from_path(p)) for p in imgs]
    df = pd.DataFrame(rows, columns=["path", "label"])
    print(df["label"].value_counts(dropna=False).to_string(), flush=True)
    df = df.dropna(subset=["label"]).reset_index(drop=True)
    print("Dipakai:", len(df), "gambar", flush=True)
    if len(df) == 0:
        raise SystemExit("Tidak ada gambar ter-label — hentikan.")

    # --- 3. Dataset + split stratify (sel 5) ---
    tf = transforms.Compose(
        [
            transforms.Grayscale(num_output_channels=3),
            transforms.Resize((224, 224)),
            transforms.ToTensor(),
            transforms.Normalize(mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225]),
        ]
    )

    class CXR(Dataset):
        def __init__(self, frame: pd.DataFrame):
            self.df = frame.reset_index(drop=True)

        def __len__(self) -> int:
            return len(self.df)

        def __getitem__(self, i: int):
            img = Image.open(self.df.loc[i, "path"]).convert("L")
            y = 1.0 if self.df.loc[i, "label"] == "tb" else 0.0
            return tf(img), torch.tensor(y, dtype=torch.float32)

    tr, va = train_test_split(df, test_size=0.2, stratify=df["label"], random_state=SEED)
    train_dl = DataLoader(CXR(tr), batch_size=args.batch_size, shuffle=True, num_workers=2)
    val_dl = DataLoader(CXR(va), batch_size=args.batch_size, shuffle=False, num_workers=2)
    print(f"train={len(tr)} val={len(va)}", flush=True)

    # --- 4. Model DenseNet121 ImageNet -> 1 logit BCE (sel 6) ---
    device = "cuda" if torch.cuda.is_available() else "cpu"
    if torch.cuda.is_available():
        torch.cuda.manual_seed_all(SEED)
    model = models.densenet121(weights=models.DenseNet121_Weights.IMAGENET1K_V1)
    model.classifier = nn.Linear(model.classifier.in_features, 1)
    model.to(device)
    opt = torch.optim.Adam(model.parameters(), lr=args.lr)
    crit = nn.BCEWithLogitsLoss()
    print("Params:", sum(p.numel() for p in model.parameters()) // 1_000_000, "M", flush=True)

    # --- 5. Training loop + val AUC, simpan terbaik (sel 7) ---
    best_auc, best_state = 0.0, None
    for ep in range(1, args.epochs + 1):
        model.train()
        tot = 0.0
        for x, y in train_dl:
            x, y = x.to(device), y.to(device)
            opt.zero_grad()
            loss = crit(model(x).squeeze(1), y)
            loss.backward()
            opt.step()
            tot += loss.item() * len(x)
        model.eval()
        ys, ps = [], []
        with torch.no_grad():
            for x, y in val_dl:
                ys += y.tolist()
                ps += torch.sigmoid(model(x.to(device))).squeeze(1).cpu().tolist()
        auc = roc_auc_score(ys, ps)
        print(f"epoch {ep:2d} | loss {tot/len(tr):.4f} | val_auc {auc:.4f}", flush=True)
        if auc > best_auc:
            best_auc = auc
            best_state = {k: v.cpu().clone() for k, v in model.state_dict().items()}
    print(f"BEST val_auc = {best_auc:.4f}", flush=True)

    # --- 6. Evaluasi hold-out (sel 8) ---
    model.load_state_dict(best_state)
    model.eval()
    ys, ps = [], []
    with torch.no_grad():
        for x, y in val_dl:
            ys += y.tolist()
            ps += torch.sigmoid(model(x.to(device))).squeeze(1).cpu().tolist()
    pred = [1 if p >= 0.5 else 0 for p in ps]
    print(
        f"acc={accuracy_score(ys, pred):.4f} prec={precision_score(ys, pred):.4f} "
        f"rec={recall_score(ys, pred):.4f} f1={f1_score(ys, pred):.4f} "
        f"auc={roc_auc_score(ys, ps):.4f}",
        flush=True,
    )

    # --- 7. Simpan checkpoint format tb.py (sel 9, tanpa Google Drive) ---
    out = Path(args.out)
    torch.save(
        {
            "state_dict": best_state,
            "pathologies": ["Normal", "Tuberculosis"],
            "val_auc": float(best_auc),
            "dataset": HF_DATASET,
            "note": CHECKPOINT_NOTE,
        },
        out,
    )
    print(f"Bobot tersimpan: {out} ({out.stat().st_size/1e6:.1f} MB)", flush=True)
    print("Ambil dengan: colab download <session> tb_densenet121.pt ai-worker/weights/", flush=True)


if __name__ == "__main__":
    main()
