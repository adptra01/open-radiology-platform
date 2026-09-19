"""Gateway-level errors (distinct from DICOM validation errors)."""

from __future__ import annotations


class GatewayError(Exception):
    """Base class for AI Gateway framework errors."""


class UnknownTaskError(GatewayError):
    """Raised by the dispatcher for an unregistered task_id (API -> 404)."""

    def __init__(self, task_id: str) -> None:
        super().__init__(f"Unknown AI task '{task_id}'. See GET /capabilities.")
        self.task_id = task_id
