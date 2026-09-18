# ORP AI — Bobot Model TB

Folder ini menerima bobot model deteksi Tuberkulosis hasil fine-tune dari
**Google Colab** (lihat `../notebooks/tb_finetune_colab.ipynb`).

## Cara menempatkan bobot

1. Jalankan notebook `tb_finetune_colab.ipynb` di Colab (dataset publik
   Montgomery/Shenzhen — TANPA data pasien).
2. Setelah training, ambil `tb_densenet121.pt` dari
   `MyDrive/orp-ai/` (Google Drive) atau langsung dari runtime Colab.
3. Salin ke folder ini:

   ```bash
   # misal dari Drive (sudah terunduh) atau mount Drive di sistem
   cp /mnt/DiskD/Projects/DCM4CHE/ai-worker/weights/tb_densenet121.pt \
      /mnt/DiskD/Projects/DCM4CHE/ai-worker/weights/
   ```

   Atau dengan `gdown` (perlu ID file Drive):

   ```bash
   docker exec pydev uv --directory /projects/DCM4CHE/ai-worker run \
     python -c "import gdown; gdown.download('https://drive.google.com/uc?id=<FILE_ID>', 'weights/tb_densenet121.pt', quiet=False)"
   ```

4. Validasi integrasi: `orp-ai infer <gambar>` akan menampilkan blok `"tb"`
   dengan `"available": true` dan probabilitas TB di samping 18 patologi paru.

## Isi checkpoint (persis sesuai notebook)

```python
{
  "state_dict": {...},            # bobot DenseNet121 fine-tune (classifier -> 1 logit)
  "pathologies": ["Normal", "Tuberculosis"],
  "val_auc": float,               # AUC validasi (hold-out)
  "dataset": "Montgomery+Shenzhen (NLM/OpenI)",
  "note": "alat skrining/triase — bukan alat diagnostik; perlu validasi klinis",
}
```

## Catatan penting

- **Bukan alat diagnostik.** Output = probabilitas skrining/triase; keputusan
  klinis tetap di dokter. Validasi klinis wajib sebelum produksi.
- File bobot (~35 MB) **tidak dimasukkan ke git** — hanya referensi output
  Colab. Unduh ulang jika folder hilang (runtime Colab ephemeral, Drive = sumber).
- Untuk melacak dari mana bobot berasal, selalu simpan nilai `val_auc` yang
  tercantum di kolom `"tb"` pada laporan inferensi.