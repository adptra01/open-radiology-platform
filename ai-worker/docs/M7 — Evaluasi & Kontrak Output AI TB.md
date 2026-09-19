# M7 — Evaluasi Model & Integrasi AI TB (Draft Kerja)

> **Status: DRAFT** — menunggu artifact `tb_densenet121.pt` lolos load-test.
> Sesuai arahan: **jangan deklarasikan `tb.available=true` sebelum bobot
> terverifikasi (checksum + torch.load) + inferensi nyata terbukti.**
> Dokumen ini menyiapkan prosedur agar begitu bobot masuk, evaluasi langsung jalan.

---

## 1. Prinsip

- Model adalah **alat skrining/triase**, **bukan alat diagnostik**.
- Screening positif ≠ diagnosis TB. Butuh konfirmasi klinis/diagnostik lanjutan.
- Threshold CAD perlu **dikalibrasi** sesuai populasi & konteks (acuan WHO).
- Tidak ada klaim klinis tanpa validasi. Disclaimer eksplisit di output.
- Semua angka (sensitivity/specificity/AUC) WAJIB dari **test set terpisah**,
  bukan dari data training/validasi.

---

## 2. Kontrak Output Inference (M6 reference, disiapkan)

Endpoint produksi RIS → AI worker:

```
POST /api/ai/tb/analyze     (alias POST /infer/tb)
```

Request (multipart):

```json
{
  "study_id": "ORD-260919-0001",
  "image_id": 12345
}
```

Response sukses (200):

```json
{
  "study_id": "ORD-260919-0001",
  "model": "DenseNet121-TB",
  "model_version": "0.1.0",
  "tb_score": 0.87,
  "tb_class": "suspected" | "negative",
  "threshold": 0.5,
  "available": true,
  "disclaimer": "Skrining/triase, bukan diagnosis. Perlu evaluasi klinis.",
  "processed_ms": 833
}
```

Response saat bobot tidak ada (409/503):

```json
{
  "error": "tb_weights_missing",
  "message": "Bobot TB belum tersedia. Model umum 18-patologi tetap berjalan.",
  "tb_available": false
}
```

---

## 3. Prosedur Evaluasi (saat `.pt` lolos download + torch.load)

### 3.1 Lokasi artifact
- `ai-worker/weights/tb_densenet121.pt` (dari Colab `/content/tb_densenet121.pt`)
- Backup ganda: `.bak1` + `.bak2`
- Checksum: SHA-256 di changelog

### 3.2 Dataset evaluasi (WAJIB terpisah dari training)
- **Montgomery (138) + Shenzhen (662)** publik → split:
  - train/val (sudah dipakai fine-tune)
  - **test set terpisah**: idealnya subset yang sama distribusi tapi belum pernah dilihat model (mis. TBX11K subset, atau hold-out Montgomery).
- **PENTING**: data pasien/institusi TIDAK boleh masuk Colab/Kaggle (UU PDP). Evaluasi pakai dataset publik.

### 3.3 Metrik inti
| Metrik | Definisi | Target referensi |
|---|---|---|
| **Sensitivity** | TP/(TP+FN) | ≥ 0.90 (CAD TB WHO) |
| **Specificity** | TN/(TN+FP) | ≥ 0.70–0.80 |
| **ROC-AUC** | Area under ROC | ≥ 0.85 |
| **PR-AUC** | Precision-recall | Dilaporkan (imbalance) |
| **Threshold** | Titik kalibrasi | Dilaporkan eksplisit |
| **Confusion matrix** | TP/FP/TN/FN | Dilaporkan |
| **Calibration** | Brier/ECE | Dilaporkan (jika mungkin) |

### 3.4 Alur verifikasi
```python
# 1. checksum
sha256sum tb_densenet121.pt

# 2. load & smoke test
import torch
m = torch.load("tb_densenet121.pt", map_location="cpu")
m.eval()

# 3. inference pada test image publik
out = m(preprocess(img))
print(out)  # tb_score

# 4. kalkulasi metrik pada seluruh test set
```

### 3.5 Batas penggunaan (harus di depan output)
- Skrining suportif, bukan diagnosis.
- Butuh korelasi klinis & pemeriksaan konfirmasi.
- Belum tervalidasi klinis → label `internal/research`.
- Tidak cocok untuk populasi di luar domain training tanpa re-kalibrasi.

---

## 4. Template Hasil Evaluasi (diisi setelah artifact masuk)

```markdown
### [Hasil] Evaluasi DenseNet121-TB (v0.1.0) — 2026-09-19

- **Model**: DenseNet121 fine-tune (Montgomery+Shenzhen)
- **Test set**: ____ gambar (publik, terpisah)
- **Checksum (SHA-256)**: `____`
- **Load-test**: ✔ / ✘

| Metrik | Nilai |
|---|---|
| Sensitivity | ____ |
| Specificity | ____ |
| ROC-AUC | ____ |
| PR-AUC | ____ |
| Threshold | ____ |
| Brier/ECE | ____ |

Confusion matrix: ____

**Kesimpulan**: [screening OK / perlu re-tune threshold / belum layak]
```

---

## 5. Gate untuk `tb.available=true`

1. ✅ `tb_densenet121.pt` ada + backup ganda + SHA-256 diverifikasi.
2. ✅ `torch.load` OK (load-test).
3. ✅ Minimal 1 inferensi nyata berhasil → output struktural masuk RIS.
4. ⚠️ Evaluasi metrik dilakukan (bukan hanya "keputusan one-off").
5. ✅ Disclaimer "screening bukan diagnosis" tampil.

Sampai gate 1–3 selesai, `tb.available` TETAP `false`.
