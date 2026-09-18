# Pelatihan Bobot TB (TBX11K) — Notebook Colab Template

> Tujuan: Melatih model klasifikasi Tuberkulosis (TB) pada citra DICOM Chest X-Ray
> menggunakan dataset **TBX11K** (public), lalu mengekspor bobot `.pt` untuk
> `ai-worker` (FastAPI + TorchXRayVision).

> **Catatan etis**: Kode ini **hanya konsep/arsitektur** — **tidak menyalin**
> model/bobot/aset QURE. Dataset TBX11K bersifat publik (CC-BY-NC-SA).

---

## 1. Persiapan Environment (Colab GPU)

```python
# Colab: Runtime → Change runtime type → T4 GPU (gratis) atau A100 (pro)
!nvidia-smi

# Install deps
!pip install -q torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121
!pip install -q torchxrayvision==1.5.4 timm albumentations pandas scikit-learn tqdm pydicom
```

---

## 2. Unduh & Persiapkan Dataset TBX11K

TBX11K: 11.000+ citra CXR dengan label TB/Normal dari multiple sumber publik.
Unduh via script resmi atau mirror (ukuran ~15 GB).

```python
import os, zipfile, requests, hashlib
from pathlib import Path

DATA_DIR = Path('/content/tbx11k')
DATA_DIR.mkdir(parents=True, exist_ok=True)

# Opsi A: Kaggle (butuh kaggle.json)
# !kaggle datasets download -d kmader/tbx11k -p /content/tbx11k --unzip

# Opsi B: Mirror Zenodo / HuggingFace (contoh)
TBX11K_URL = "https://huggingface.co/datasets/.../resolve/main/tbx11k.zip"  # ganti URL resmi
ZIP_PATH = DATA_DIR / "tbx11k.zip"

if not ZIP_PATH.exists():
    print("Downloading TBX11K...")
    # requests stream download dengan progress bar
    import tqdm
    with requests.get(TBX11K_URL, stream=True) as r:
        r.raise_for_status()
        total = int(r.headers.get('content-length', 0))
        with open(ZIP_PATH, 'wb') as f, tqdm.tqdm(total=total, unit='B', unit_scale=True) as pbar:
            for chunk in r.iter_content(chunk_size=8192):
                f.write(chunk)
                pbar.update(len(chunk))

# Extract
with zipfile.ZipFile(ZIP_PATH, 'r') as zf:
    zf.extractall(DATA_DIR)

# Verifikasi struktur
# tbx11k/
#   images/        # .dcm atau .png
#   labels.csv     # columns: image_id, tb (0/1), dataset_source, ...
```

---

## 3. Preprocessing & DataLoader (TorchXRayVision + Albumentations)

```python
import torch
import torchxrayvision as xrv
import albumentations as A
from albumentations.pytorch import ToTensorV2
from torch.utils.data import DataLoader, Dataset
import pandas as pd
import pydicom
import numpy as np

class TBX11KDataset(Dataset):
    def __init__(self, csv_path, img_dir, transform=None):
        self.df = pd.read_csv(csv_path)
        self.img_dir = Path(img_dir)
        self.transform = transform

    def __len__(self):
        return len(self.df)

    def __getitem__(self, idx):
        row = self.df.iloc[idx]
        img_path = self.img_dir / row['image_id']  # sesuaikan kolom
        # Baca DICOM → pixel_array → normalize ke 0-1
        ds = pydicom.dcmread(img_path, force=True)
        img = ds.pixel_array.astype(np.float32)
        # Normalisasi DICOM (VOI LUT kalau ada)
        if hasattr(ds, 'VOILUTSequence'):
            pass  # skip untuk singkat
        img = (img - img.min()) / (img.max() - img.min() + 1e-6)
        # Resize ke 224x224 (DenseNet input)
        if self.transform:
            img = self.transform(image=img)['image']
        # TorchXRayVision expect (C,H,W) dengan C=1 (grayscale)
        if img.ndim == 2:
            img = img[None, ...]
        label = torch.tensor(row['tb'], dtype=torch.float32)  # 0/1
        return img, label

# Transform: resize + normalize ImageNet (TorchXRayVision pakai mean/std ImageNet)
transform = A.Compose([
    A.Resize(224, 224),
    A.Normalize(mean=[0.485], std=[0.229]),  # grayscale single channel
    ToTensorV2(),
])

train_ds = TBX11KDataset(
    csv_path=DATA_DIR/'labels_train.csv',
    img_dir=DATA_DIR/'images',
    transform=transform,
)
val_ds = TBX11KDataset(
    csv_path=DATA_DIR/'labels_val.csv',
    img_dir=DATA_DIR/'images',
    transform=transform,
)

train_loader = DataLoader(train_ds, batch_size=32, shuffle=True, num_workers=4, pin_memory=True)
val_loader   = DataLoader(val_ds, batch_size=32, shuffle=False, num_workers=4, pin_memory=True)
```

---

## 4. Model: DenseNet121 + Head Klasifikasi TB (Transfer Learning)

```python
import torch.nn as nn
import torchxrayvision as xrv

# Load backbone pretrained TorchXRayVision (DenseNet121 all pathologies)
backbone = xrv.models.DenseNet(weights="densenet121-res224-all")
# Backbone output: 18 pathology logits (bukan TB)
# Kita ambil feature sebelum final FC (global pool) → tambah head TB

class TBClassifier(nn.Module):
    def __init__(self, backbone, num_classes=1, freeze_backbone=True):
        super().__init__()
        self.features = backbone.features  # DenseNet feature extractor
        self.pool = nn.AdaptiveAvgPool2d((1,1))
        # DenseNet121 last conv channels = 1024
        self.classifier = nn.Linear(1024, num_classes)
        if freeze_backbone:
            for p in self.features.parameters():
                p.requires_grad = False

    def forward(self, x):
        x = self.features(x)
        x = torch.relu(x, inplace=True)
        x = self.pool(x).flatten(1)
        return self.classifier(x)  # logits (BCEWithLogitsLoss)

model = TBClassifier(backbone, freeze_backbone=True).cuda()
```

---

## 5. Training Loop (BCE + Class Weight untuk Imbalance)

```python
import torch.optim as optim
from sklearn.metrics import roc_auc_score, average_precision_score

# Class weight (TB biasanya minority)
pos_weight = torch.tensor([len(train_ds) / train_df['tb'].sum() - 1]).cuda()
criterion = nn.BCEWithLogitsLoss(pos_weight=pos_weight)
optimizer = optim.AdamW(model.parameters(), lr=1e-3, weight_decay=1e-4)
scheduler = optim.lr_scheduler.CosineAnnealingLR(optimizer, T_max=10)

best_auc = 0.0
for epoch in range(15):
    model.train()
    train_loss = 0.0
    for imgs, labels in train_loader:
        imgs, labels = imgs.cuda(), labels.cuda()
        optimizer.zero_grad()
        logits = model(imgs).squeeze(1)
        loss = criterion(logits, labels)
        loss.backward()
        optimizer.step()
        train_loss += loss.item() * imgs.size(0)
    train_loss /= len(train_ds)

    # Validasi
    model.eval()
    all_probs, all_labels = [], []
    with torch.no_grad():
        for imgs, labels in val_loader:
            imgs = imgs.cuda()
            probs = torch.sigmoid(model(imgs).squeeze(1)).cpu().numpy()
            all_probs.extend(probs)
            all_labels.extend(labels.numpy())

    auc = roc_auc_score(all_labels, all_probs)
    ap  = average_precision_score(all_labels, all_probs)
    print(f"Epoch {epoch:2d} | loss={train_loss:.4f} | AUC={auc:.4f} | AP={ap:.4f}")

    if auc > best_auc:
        best_auc = auc
        torch.save(model.state_dict(), '/content/tb_densenet121.pt')
        print(f"  ✓ Saved best (AUC={auc:.4f})")

    scheduler.step()

print(f"Best AUC: {best_auc:.4f}")
```

---

## 6. Ekspor Bobot untuk ai-worker

```python
# Bobot yang disimpan: state_dict head + backbone (jika unfreeze nanti)
# ai-worker expect: model dimuat via `torchxrayvision.models.DenseNet(weights=...)`
# tapi kita hanya butuh head TB — simpan full model state_dict

# Di ai-worker (server), load:
#   model = TBClassifier(xrv.models.DenseNet(weights="densenet121-res224-all"), freeze_backbone=True)
#   model.load_state_dict(torch.load('tb_densenet121.pt', map_location='cpu'))
#   model.eval()

# Verifikasi load
model_loaded = TBClassifier(xrv.models.DenseNet(weights="densenet121-res224-all"), freeze_backbone=True)
model_loaded.load_state_dict(torch.load('/content/tb_densenet121.pt', map_location='cpu'))
model_loaded.eval()
print("Load OK")

# Salin ke ai-worker/weights/ di host:
#   scp /content/tb_densenet121.pt user@server:/path/to/ai-worker/weights/
```

---

## 7. Integrasi ke ai-worker (FastAPI)

Di `ai-worker/orp_ai/tb_model.py` (contoh):

```python
import torch
import torchxrayvision as xrv
from pathlib import Path

class TBModel:
    def __init__(self, weights_path: str, device: str = "cpu"):
        self.device = torch.device(device)
        backbone = xrv.models.DenseNet(weights="densenet121-res224-all")
        self.model = TBClassifier(backbone, freeze_backbone=True).to(self.device)
        state = torch.load(weights_path, map_location=self.device)
        self.model.load_state_dict(state)
        self.model.eval()

    @torch.inference_mode()
    def predict(self, img_tensor: torch.Tensor) -> float:
        """img_tensor: (1,1,224,224) normalized ImageNet"""
        logits = self.model(img_tensor.to(self.device))
        return torch.sigmoid(logits).item()  # probabilitas TB [0,1]
```

Di `server.py` (`/health` endpoint):

```python
tb_model = None
try:
    tb_model = TBModel("/weights/tb_densenet121.pt", device="cpu")
    TB_AVAILABLE = True
except Exception as e:
    TB_AVAILABLE = False

@app.get("/health")
def health():
    return {
        "tb": {"available": TB_AVAILABLE, "model": "densenet121-tb"},
        # ... existing fields ...
    }
```

---

## 8. Checklist Deploy

- [ ] Dataset TBX11K diunduh & split train/val/test (stratified)
- [ ] Training 15 epoch, best AUC > 0.85 (target klinis minimal)
- [ ] Bobot `.pt` disimpan, di-copy ke `ai-worker/weights/tb_densenet121.pt`
- [ ] `docker compose -f docker-compose.prod.yml restart ai-worker`
- [ ] Verifikasi `GET /health` → `"tb": {"available": true, ...}`
- [ ] Uji inferensi: `POST /infer/tb` dengan DICOM CXR → `findings` include `Tuberculosis` dengan probabilitas

---

## 9. Referensi

- TBX11K Dataset: https://www.kaggle.com/datasets/kmader/tbx11k
- TorchXRayVision: https://github.com/mlmed/torchxrayvision
- DenseNet121 weights: `densenet121-res224-all` (18 pathology, ImageNet pretrained + CheXpert + MIMIC + NIH + RSNA + SIIM + COVID)
- Paper: "TorchXRayVision: A library for chest X-ray datasets and models" (2021)