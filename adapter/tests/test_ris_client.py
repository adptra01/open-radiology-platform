"""Unit tests untuk RisClient: URL building, header X-API-Key, dan parsing worklist."""

import json

import requests

from orp_adapter.ris_client import RisClient


class FakeResponse:
    def __init__(self, status_code=200, payload=None):
        self.status_code = status_code
        self._payload = payload if payload is not None else {"items": []}
        self.text = json.dumps(self._payload)
        self.ok = status_code < 400

    def json(self):
        return self._payload


def test_url_joins_base_and_path():
    client = RisClient("http://ris.test/api", api_key="k", timeout=2)
    assert client.url("dicom/worklists") == "http://ris.test/api/dicom/worklists"
    assert client.url("/dicom/mpps") == "http://ris.test/api/dicom/mpps"


def test_fetch_worklist_sends_api_key_header(monkeypatch):
    captured = {}

    def fake_get(url, **kwargs):
        captured["url"] = url
        captured["headers"] = kwargs.get("headers", {})
        captured["params"] = kwargs.get("params", {})
        return FakeResponse(payload={"items": [{"accession_number": "ACC1"}]})

    monkeypatch.setattr(requests, "get", fake_get)

    client = RisClient("http://ris.test/api", api_key="secret", timeout=2)
    items = client.fetch_worklist({"AccessionNumber": "ACC1", "Modality": ""})

    assert items == [{"accession_number": "ACC1"}]
    assert captured["url"] == "http://ris.test/api/dicom/worklists"
    assert captured["headers"]["X-API-Key"] == "secret"
    # params kosong dibuang
    assert captured["params"] == {"AccessionNumber": "ACC1"}


def test_fetch_worklist_unreachable_returns_empty(monkeypatch):
    def boom(url, **kwargs):
        raise requests.ConnectionError("down")

    monkeypatch.setattr(requests, "get", boom)
    client = RisClient("http://ris.test/api")
    assert client.fetch_worklist({}) == []


def test_forward_mpps_posts_json(monkeypatch):
    captured = {}

    def fake_post(url, **kwargs):
        captured["url"] = url
        captured["data"] = json.loads(kwargs["data"])
        captured["headers"] = kwargs.get("headers", {})
        return FakeResponse(status_code=200, payload={"matched": True})

    monkeypatch.setattr(requests, "post", fake_post)

    client = RisClient("http://ris.test/api", api_key="secret")
    response = client.forward_mpps({"type": "N-CREATE", "AccessionNumber": "ACC1"})

    assert response.ok
    assert captured["url"] == "http://ris.test/api/dicom/mpps"
    assert captured["data"] == {"type": "N-CREATE", "AccessionNumber": "ACC1"}
    assert captured["headers"]["X-API-Key"] == "secret"


def test_forward_study_unreachable_returns_none(monkeypatch):
    def boom(url, **kwargs):
        raise requests.ConnectionError("down")

    monkeypatch.setattr(requests, "post", boom)
    client = RisClient("http://ris.test/api")
    assert client.forward_study({}) is None