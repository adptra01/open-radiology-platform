# ORP AI Worker — FastAPI inferensi CXR CPU-only (:8000)
# Build (dari ROOT proyek):  docker build -t orp-ai-worker:latest -f ai-worker/Dockerfile .
# Atau via compose:          docker compose -f docker-compose.prod.yml build ai-worker
# Torch CPU-only via [[tool.uv.index]] pytorch-cpu di pyproject (tanpa CUDA ~2GB).
# Konfigurasi via env ORP_AI_*; bobot TB opsional via /weights + ORP_AI_TB_WEIGHTS.

FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    UV_LINK_MODE=copy

# wget untuk healthcheck compose (python-slim tidak bawanya)
RUN apt-get update \
    && apt-get install -y --no-install-recommends wget \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /bin/

WORKDIR /app

# Deps dulu (layer cache; torch CPU ~200MB), lalu source
COPY ai-worker/pyproject.toml ai-worker/uv.lock ./
RUN uv sync --frozen --no-dev --no-install-project \
    && uv cache clean

COPY ai-worker/src/ ./src/
RUN uv sync --frozen --no-dev \
    && uv cache clean

# Non-root (port 8000 >1024, aman tanpa root)
RUN useradd --create-home --shell /usr/sbin/nologin app \
    && chown -R app:app /app
USER app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --retries=3 --start-period=30s \
    CMD wget -qO- http://localhost:8000/health || exit 1

CMD ["uv", "run", "--no-sync", "--project", "/app", "orp-ai", "serve", "--host", "0.0.0.0", "--port", "8000"]
