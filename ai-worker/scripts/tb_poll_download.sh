#!/usr/bin/env bash
# Poll + download bobot TB DenseNet121 dari session Colab tb-train8.
# Sintaks yang BENAR: `colab ls -s <session> <path>` dan
# `colab download -s <session> <remote_path> <local_path>` (path = POSITIONAL, bukan -p).
set -u
export PATH="$HOME/.local/bin:$PATH"
SESSION="tb-train8"
REMOTE="/content/tb_densenet121.pt"
DEST="/mnt/DiskD/Projects/DCM4CHE/ai-worker/weights/tb_densenet121.pt"
mkdir -p "$(dirname "$DEST")"

log() { echo "[$(date -u +%H:%M:%SZ)] $*"; }

log "poller start: $SESSION → $REMOTE"
for i in $(seq 1 60); do
  left=$((60 - i))
  # path = positional (argumen ke-2 setelah -s)
  if colab ls -s "$SESSION" "$REMOTE" >/tmp/opencode/tb_ls.txt 2>&1 &&
     grep -q "tb_densenet121.pt" /tmp/opencode/tb_ls.txt; then
    log "✅ FILE TERDETEKSI — mulai download (iterasi ke-$i)"
    for att in 1 2 3 4 5; do
      colab download -s "$SESSION" "$REMOTE" "$DEST" 2>&1 | tail -n 2
      if [ -s "$DEST" ]; then
        log "=== DOWNLOAD OK (attempt $att) ==="
        sha256sum "$DEST"; ls -la "$DEST"
        cp -f "$DEST" "${DEST}.bak1"
        cp -f "$DEST" "${DEST}.bak2"
        echo "--- SHA-256 ---"
        sha256sum "$DEST"
        echo "=== ARTIFACT AMAN (backup ganda) $(date -u) ==="
        exit 0
      fi
      log "  attempt $att belum; retry 8s"
      sleep 8
    done
    log "!!! 5x download gagal — perlu intervensi manual"
    exit 2
  else
    log "belum ada (sisa $left x 30s)"
  fi
  sleep 30
done
log "poller habis (30 menit) — belum ada; training mungkin masih jalan"
exit 3
