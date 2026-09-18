import json
import logging

import requests

logger = logging.getLogger(__name__)


class RisClient:
    def __init__(self, base_url: str, api_key: str = "", timeout: int = 5):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout

    def _headers(self) -> dict:
        headers = {"Content-Type": "application/json", "Accept": "application/json"}
        if self.api_key:
            headers["X-API-Key"] = self.api_key
        return headers

    def url(self, path: str) -> str:
        return f"{self.base_url}/{path.lstrip('/')}"

    def fetch_worklist(self, query: dict) -> list:
        try:
            response = requests.get(
                self.url("dicom/worklists"),
                headers=self._headers(),
                params={k: v for k, v in query.items() if v},
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            logger.warning("dicom/worklists unreachable: %s", exc)
            return []
        if not response.ok:
            logger.warning("dicom/worklists HTTP %s: %s", response.status_code, response.text[:200])
            return []
        payload = response.json()
        return payload.get("items", payload) if isinstance(payload, dict) else payload

    def forward_mpps(self, payload: dict) -> requests.Response | None:
        return self._post("dicom/mpps", payload)

    def forward_study(self, payload: dict) -> requests.Response | None:
        return self._post("dicom/studies", payload)

    def announce_online(self, payload: dict) -> requests.Response | None:
        return self._post("dicom/adapters/online", payload)

    def _post(self, path: str, payload: dict) -> requests.Response | None:
        try:
            return requests.post(
                self.url(path),
                headers=self._headers(),
                data=json.dumps(payload),
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            logger.warning("%s unreachable: %s", path, exc)
            return None