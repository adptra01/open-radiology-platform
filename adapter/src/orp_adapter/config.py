import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parents[2] / ".env")


@dataclass(frozen=True)
class Settings:
    ae_title: str = os.getenv("ORP_AE_TITLE", "ORP_RIS")
    host: str = os.getenv("ORP_BIND_HOST", "0.0.0.0")
    mwl_port: int = int(os.getenv("ORP_MWL_PORT", "4243"))
    mpps_port: int = int(os.getenv("ORP_MPPS_PORT", "4244"))
    store_port: int = int(os.getenv("ORP_STORE_PORT", "4245"))
    ris_api_url: str = os.getenv("ORP_RIS_API_URL", "http://127.0.0.1:8000/api")
    ris_api_key: str = os.getenv("ORP_RIS_API_KEY", "")
    timeout: int = int(os.getenv("ORP_HTTP_TIMEOUT", "5"))
    inbox_dir: str = os.getenv("ORP_INBOX_DIR", "")


settings = Settings()