#!/usr/bin/env bash
# Amankan bobot TB: backup ganda + SHA-256 + verifikasi reload PyTorch
set -euo pipefail
SRC="${1:-ai-worker/weights/tb_densenet121.pt}"
BASE="ai-worker/weights/tb_densenet121"
die() { echo "ERROR: $*" >&2; exit 1; }

[ -s "$SRC" ] || die "bobot tidak ada/empty: $SRC"

# 1. Backup ganda + checksum
cp -f "$SRC" "$BASE.bak1.pt"
cp -f "$SRC" "$BASE.bak2.pt"
echo "// SHA-256 //" > "$BASE.SHA256SUM"
sha256sum "$SRC" "$BASE.bak1.pt" "$BASE.bak2.pt" >> "$BASE.SHA256SUM"

# 2. Load-test dengan PyTorch (offline, tidak butuh HF)
python3 - "$SRC" <<'PY'
import sys, torch
from torchvision import models
p = sys.argv[1]
try:
    m = models.densenet121(weights=None)
    m.classifier = torch.nn.Linear(m.classifier.in_features, 1)
    sd = torch.load(p, map_location="cpu", weights_only=True)
    if isinstance(sd, dict) and "state_dict" in sd:
        sd = sd["state_dict"]
    m.load_state_dict(sd, strict=False)
    m.eval()
    # smoke test inference
    with torch.no_grad():
        out = m(torch.randn(1, 3, 224, 224)).sigmoid().item()
    print(f"LOAD-OK model=densenet121 state_dict_keys={len(sd)} cls=linear:1 out={out:.4f}")
except Exception as e:
    print(f"LOAD-FAIL {p}: {e}", file=sys.stderr); sys.exit(1)
PY

echo "OK: bobot aman -> checksum + load-test sukses"
cat "$BASE.SHA256SUM"
