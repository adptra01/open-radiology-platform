# ORP Adapter — DICOM gateway (MWL :4243, MPPS :4244, C-STORE :4245)
# Build (dari ROOT proyek):  docker build -t orp-adapter:latest -f adapter/Dockerfile .
# Atau via compose:          docker compose -f docker-compose.prod.yml build adapter
# Konfigurasi penuh via env (ORP_AE_TITLE, ORP_*_PORT, ORP_RIS_API_URL/KEY).

FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    UV_LINK_MODE=copy

# wget untuk healthcheck compose (python-slim tidak bawanya)
RUN apt-get update \
    && apt-get install -y --no-install-recommends wget \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /bin/

WORKDIR /app

# Deps dulu (layer cache), lalu source
COPY adapter/pyproject.toml adapter/uv.lock ./
RUN uv sync --frozen --no-dev --no-install-project \
    && uv cache clean

COPY adapter/src/ ./src/
RUN uv sync --frozen --no-dev \
    && uv cache clean

# Non-root (port DICOM >1024, aman tanpa root)
RUN useradd --create-home --shell /usr/sbin/nologin app \
    && chown -R app:app /app
USER app

EXPOSE 4243 4244 4245

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD uv run --no-sync --project /app python -c "import orp_adapter; print('ok')" || exit 1

CMD ["uv", "run", "--no-sync", "--project", "/app", "orp-adapter", "run"]
